<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Bookings\Service\BookingManager;
use BSP\Bookings\Service\OperationsSyncService;
use BSP\Quotes\Repository\QuoteRepositoryInterface;
use BSPModule\Core\Services\BookingTruthRuntimeService;
use InvalidArgumentException;
use wpdb;

final class QuoteBookingBridgeService
{
    private BookingManager $bookingManager;
    private BookingTruthRuntimeService $bookingTruthRuntime;

    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events,
        BookingManager $bookingManager,
        ?BookingTruthRuntimeService $bookingTruthRuntime = null
    ) {
        $this->bookingTruthRuntime = $bookingTruthRuntime ?? new BookingTruthRuntimeService();
        $this->bookingManager = $bookingManager;
    }

    /**
     * @return array<string, mixed>
     */
    public function createOperationsBooking(int $quoteId, ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        $existingMasterId = (int) ($quote['booking_master_id'] ?? 0);
        if ($existingMasterId > 0) {
            return array(
                'quote_id' => $quoteId,
                'booking_master_id' => $existingMasterId,
                'created' => false,
                'idempotent' => true,
                'status' => (string) ($quote['handoff_status'] ?? ''),
            );
        }

        if ((string) ($quote['status'] ?? '') !== 'confirmed') {
            throw new InvalidArgumentException('Booking bridge vereist een confirmed quote.');
        }

        if ((string) ($quote['handoff_status'] ?? '') !== 'woo_cart_hydrated') {
            throw new InvalidArgumentException('Booking bridge vereist eerst een gecontroleerde Woo cart hydration.');
        }

        $versionId = (int) ($quote['approved_version_id'] ?? 0);
        if ($versionId <= 0) {
            throw new InvalidArgumentException('Booking bridge vereist approved_version_id.');
        }

        $version = $this->repository->findQuoteVersion($versionId);
        if ($version === null) {
            throw new InvalidArgumentException('Approved quote version not found.');
        }

        $orderId = (int) ($quote['woo_order_id'] ?? 0);
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Booking bridge vereist een gekoppelde Woo order.');
        }
        $this->assertWooOrderMatchesQuote($orderId, $quoteId, $versionId);
        $this->assertRequiredEvents($quoteId);

        $handoffPayload = is_array($version['handoff_payload_json'] ?? null)
            ? $version['handoff_payload_json']
            : array();
        $executionPayload = isset($handoffPayload['execution_adapter']) && is_array($handoffPayload['execution_adapter'])
            ? $handoffPayload['execution_adapter']
            : array();
        if (($executionPayload['adapter_type'] ?? '') !== 'cart_order_prep') {
            throw new InvalidArgumentException('Booking bridge vereist een cart_order_prep execution payload.');
        }

        $this->assertNoUnconfirmedSupplierLines($versionId, $executionPayload);

        $bookingPayload = $this->buildBookingPayload($quote, $versionId, $orderId, $executionPayload);
        $truthContext = $this->bookingTruthRuntime->resolveBookingWriteContext(
            $bookingPayload,
            array(
                'validation_source' => 'quote_booking_bridge',
                'resource_id' => (int) ($bookingPayload['items'][0]['resource_id'] ?? 0),
            )
        );

        $booking = $this->bookingManager->createBooking($bookingPayload, $truthContext);
        $projectionBooking = $booking;
        $projectionBooking['order'] = array(
            'id' => $orderId,
            'status' => $this->resolveOrderStatus($orderId),
        );
        $projectionBooking['status'] = 'paid';
        (new OperationsSyncService())->sync($projectionBooking);

        $masterId = $this->findBookingMasterId((int) ($booking['id'] ?? 0));
        if ($masterId <= 0) {
            throw new InvalidArgumentException('Booking bridge kon geen operations booking master vinden.');
        }

        $updatedQuote = $this->repository->updateQuote($quoteId, array(
            'booking_master_id' => $masterId,
            'handoff_status' => 'operations_ready',
            'handoff_completed_at' => $this->now(),
            'updated_at' => $this->now(),
        ));

        $this->events->log(
            'quote_booking_bridge_created',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            $versionId,
            $actorId,
            'Quote naar operations booking master/legs overgezet.',
            array(
                'booking_master_id' => $masterId,
                'booking_id' => (int) ($booking['id'] ?? 0),
                'woo_order_id' => $orderId,
            )
        );

        return array(
            'quote_id' => $quoteId,
            'quote_version_id' => $versionId,
            'woo_order_id' => $orderId,
            'booking_id' => (int) ($booking['id'] ?? 0),
            'booking_master_id' => $masterId,
            'handoff_status' => (string) ($updatedQuote['handoff_status'] ?? 'operations_ready'),
            'created' => true,
            'idempotent' => false,
        );
    }

    private function assertWooOrderMatchesQuote(int $orderId, int $quoteId, int $versionId): void
    {
        if (! function_exists('wc_get_order')) {
            throw new InvalidArgumentException('WooCommerce order lookup is not available.');
        }

        $order = \wc_get_order($orderId);
        if (! $order || ! method_exists($order, 'get_meta')) {
            throw new InvalidArgumentException('Gekoppelde Woo order niet gevonden.');
        }

        if ((int) $order->get_meta('_sbdp_quote_id') !== $quoteId) {
            throw new InvalidArgumentException('Woo order quote_id matcht niet met quote.');
        }

        if ((int) $order->get_meta('_sbdp_quote_version_id') !== $versionId) {
            throw new InvalidArgumentException('Woo order quote_version_id matcht niet met approved_version_id.');
        }
    }

    private function assertRequiredEvents(int $quoteId): void
    {
        $eventTypes = array_map(
            static fn (array $event): string => (string) ($event['event_type'] ?? ''),
            $this->repository->listQuoteEvents($quoteId)
        );

        foreach (array('quote_confirmed', 'quote_woo_payment_completed', 'quote_woo_cart_hydrated') as $required) {
            if (! in_array($required, $eventTypes, true)) {
                throw new InvalidArgumentException(sprintf('Booking bridge vereist event %s.', $required));
            }
        }
    }

    /**
     * @param array<string, mixed> $executionPayload
     */
    private function assertNoUnconfirmedSupplierLines(int $versionId, array $executionPayload): void
    {
        foreach ((array) ($executionPayload['items'] ?? array()) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if ((int) ($item['product_id'] ?? 0) === 115) {
                throw new InvalidArgumentException('Booking bridge blokkeert product 115 zonder supplier_booking_confirmed.');
            }
            $meta = is_array($item['sbdp_meta'] ?? null) ? $item['sbdp_meta'] : array();
            if (strtolower((string) ($meta['supplier_provider'] ?? $meta['provider'] ?? '')) === 'eliio') {
                throw new InvalidArgumentException('Booking bridge blokkeert Eliio regels zonder supplier_booking_confirmed.');
            }
        }

        foreach ($this->repository->listQuoteLines($versionId) as $line) {
            $snapshot = is_array($line['availability_snapshot_json'] ?? null)
                ? $line['availability_snapshot_json']
                : array();
            $productId = (int) ($line['product_id'] ?? 0);
            $bookingMode = strtolower((string) ($snapshot['bookingMode'] ?? ''));
            $provider = strtolower((string) ($snapshot['supplierProvider'] ?? $snapshot['provider'] ?? ''));
            $supplierStatus = strtolower((string) ($snapshot['supplierStatus'] ?? ''));

            if (
                ($productId === 115 || $bookingMode === 'supplier_confirmation' || $provider === 'eliio')
                && $supplierStatus !== 'supplier_booking_confirmed'
            ) {
                throw new InvalidArgumentException('Booking bridge blokkeert supplier/Eliio regels zonder supplier_booking_confirmed.');
            }
        }
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $executionPayload
     * @return array<string, mixed>
     */
    private function buildBookingPayload(array $quote, int $versionId, int $orderId, array $executionPayload): array
    {
        $items = array();
        $firstDate = '';
        $firstStart = '';
        $firstEnd = '';
        $participants = 0;

        foreach ((array) ($executionPayload['items'] ?? array()) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $itemParticipants = max(1, (int) ($item['participants'] ?? $item['quantity'] ?? 1));
            $participants = max($participants, $itemParticipants);
            $date = (string) ($item['date'] ?? '');
            $start = $this->timeOnly((string) ($item['start'] ?? ''));
            $end = $this->timeOnly((string) ($item['end'] ?? ''));
            if ($firstDate === '' && $date !== '') {
                $firstDate = $date;
            }
            if ($firstStart === '' && $start !== '') {
                $firstStart = $start;
            }
            if ($firstEnd === '' && $end !== '') {
                $firstEnd = $end;
            }

            $pricing = is_array($item['sbdp_pricing'] ?? null) ? $item['sbdp_pricing'] : array();
            $unitPrice = $this->resolveUnitPrice($pricing, $itemParticipants);
            $items[] = array(
                'product_id' => $productId,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'unit_price' => $unitPrice,
                'resource_id' => (int) ($item['resource_id'] ?? 0),
                'participants' => $itemParticipants,
                'label' => $this->resolveItemLabel($item, $index),
                'meta' => array_merge(
                    is_array($item['sbdp_meta'] ?? null) ? $item['sbdp_meta'] : array(),
                    array(
                        'quote_id' => (int) ($quote['id'] ?? 0),
                        'quote_version_id' => $versionId,
                        'woo_order_id' => $orderId,
                        'sbdp_start' => $start,
                        'sbdp_end' => $end,
                        'sbdp_participants' => $itemParticipants,
                        'sbdp_canonical_participants' => $itemParticipants,
                        'sbdp_route_intent' => 'checkout',
                        'sbdp_booking_capability' => 'DIRECT',
                    )
                ),
            );
        }

        if ($items === array()) {
            throw new InvalidArgumentException('Booking bridge vond geen booking items.');
        }

        $customer = is_array($executionPayload['customer'] ?? null) ? $executionPayload['customer'] : array();
        $participants = $participants > 0 ? $participants : 1;

        return array(
            'customer' => array(
                'name' => trim((string) ($customer['name'] ?? 'Quote klant')),
                'email' => trim((string) ($customer['email'] ?? 'quote@example.test')),
            ),
            'date' => $this->dateOnly($firstDate),
            'time' => $firstStart !== '' ? $firstStart : '09:00',
            'date_end' => $this->dateOnly($firstDate),
            'time_end' => $firstEnd !== '' ? $firstEnd : ($firstStart !== '' ? $firstStart : '09:00'),
            'participants' => $participants,
            'items' => $items,
            'notes' => sprintf('Quote bridge voor %s', (string) ($quote['quote_reference'] ?? '')),
            'currency' => (string) (($executionPayload['totals']['currency'] ?? '') ?: 'EUR'),
            'channel' => 'quote_booking_bridge',
            'pricing_rules' => array(),
            'vendor_id' => null,
            'status' => 'paid',
        );
    }

    /**
     * @param array<string, mixed> $pricing
     */
    private function resolveUnitPrice(array $pricing, int $participants): float
    {
        foreach (array('display_unit_price', 'display_per_person', 'unit_price') as $key) {
            if (isset($pricing[$key]) && is_numeric($pricing[$key]) && (float) $pricing[$key] > 0) {
                return round((float) $pricing[$key], 2);
            }
        }

        if (isset($pricing['display_total']) && is_numeric($pricing['display_total']) && $participants > 0) {
            return round(((float) $pricing['display_total']) / max(1, $participants), 2);
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveItemLabel(array $item, int $index): string
    {
        $summary = is_array($item['sbdp_summary'] ?? null) ? $item['sbdp_summary'] : array();
        $title = trim((string) ($summary['title'] ?? $item['title'] ?? ''));

        return $title !== '' ? $title : sprintf('Quote item %d', $index + 1);
    }

    private function findBookingMasterId(int $bookingId): int
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb || $bookingId <= 0) {
            return 0;
        }

        $table = $wpdb->prefix . 'bsp_booking_masters';
        $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ((string) $existing !== $table) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE booking_reference = %s LIMIT 1",
                sprintf('booking:%d', $bookingId)
            )
        );
    }

    private function resolveOrderStatus(int $orderId): string
    {
        if (! function_exists('wc_get_order')) {
            return '';
        }

        $order = \wc_get_order($orderId);
        return $order && method_exists($order, 'get_status') ? (string) $order->get_status() : '';
    }

    private function dateOnly(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches) === 1) {
            return $matches[0];
        }

        return gmdate('Y-m-d');
    }

    private function timeOnly(string $value): string
    {
        $value = trim($value);
        if (preg_match('/T(\d{2}:\d{2})/', $value, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match('/^\d{2}:\d{2}/', $value, $matches) === 1) {
            return $matches[0];
        }

        return '';
    }

    private function now(): string
    {
        return function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
