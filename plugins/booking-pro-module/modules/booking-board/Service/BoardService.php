<?php

declare(strict_types=1);

namespace BSP\BookingBoard\Service;

use BSP\Bookings\Service\BookingManager;
use BSP\Bookings\Service\DietaryProfileService;
use BSP\Bookings\Service\PartnerConfirmationService;
use BSP\Core\CoreServiceProvider;
use BSP\Planner\Vendor\CityGuideProfile;
use BSP\Planner\Vendor\CityGuideProfileStore;
use BSPModule\Core\Services\BookingTruthRuntimeService;
use DateTimeImmutable;
use InvalidArgumentException;

final class BoardService
{
    private BookingManager $manager;

    private AccessControl $access;

    private NotificationBridge $notifications;

    private AiInsightsService $insights;

    private CustomerDirectory $customers;

    /**
     * Default labels for booking statuses displayed in the board UI.
     *
     * @var array<string, string>
     */
    private const DEFAULT_STATUS_LABELS = array(
        'created'   => 'Created',
        'requested' => 'Requested',
        'captured'  => 'Captured',
        'pending'   => 'Pending',
        'paid'      => 'Paid',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    );

    /**
     * Cached planner resource metadata indexed by resource identifier.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $resourceLookup = null;
    private array $operationsSummaryCache = [];
    private BookingTruthRuntimeService $truthRuntime;

    public function __construct(
        ?BookingManager $manager = null,
        ?AccessControl $access = null,
        ?NotificationBridge $notifications = null,
        ?AiInsightsService $insights = null,
        ?CustomerDirectory $customers = null,
        ?BookingTruthRuntimeService $truthRuntime = null
    ) {
        $this->manager       = $manager ?? BookingManager::createDefault();
        $this->access        = $access ?? new AccessControl();
        $this->notifications = $notifications ?? new NotificationBridge();
        $this->insights      = $insights ?? new AiInsightsService();
        $this->customers     = $customers ?? new CustomerDirectory();
        $this->truthRuntime  = $truthRuntime ?? new BookingTruthRuntimeService();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        $allBookings = $this->access->filter($this->manager->getBookings($filters));
        $bookings    = $this->applyFilters($allBookings, $filters);
        $items       = array_map([$this, 'transformBooking'], $bookings);

        return [
            'items' => $items,
            'meta'  => [
                'total'            => count($items),
                'filters_applied'  => $filters,
                'available_filters'=> $this->buildFilterCatalogue($allBookings),
            ],
            'stats' => $this->computeStats($bookings),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function reschedule(array $payload): array
    {
        $this->access->enforceManage();

        $bookingId = (int) ($payload['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new InvalidArgumentException('Booking identifier is required.');
        }

        [$dateStart, $timeStart] = $this->extractDateTime($payload, 'date_start', 'time_start', 'start');
        [$dateEnd, $timeEnd]     = $this->extractDateTime($payload, 'date_end', 'time_end', 'end');

        $booking = $this->manager->getBookings(['booking_id' => $bookingId])[0] ?? null;
        if (! is_array($booking)) {
            throw new InvalidArgumentException('Booking could not be found.');
        }

        $this->assertCanonicalBookingTruth(
            $this->resolveBookingItems($booking, $bookingId),
            $dateStart,
            $timeStart,
            $dateEnd,
            $timeEnd,
            (int) ($booking['participants'] ?? 1),
            $this->resolveBookingResourceId($booking, $bookingId)
        );

        $truthContext = $this->buildBookingTruthContext(
            $this->resolveBookingItems($booking, $bookingId),
            $dateStart,
            $timeStart,
            $dateEnd,
            $timeEnd,
            (int) ($booking['participants'] ?? 1),
            $this->resolveBookingResourceId($booking, $bookingId),
            'booking_board_reschedule'
        );
        $updated = $this->manager->rescheduleBooking($bookingId, $dateStart, $timeStart, $dateEnd, $timeEnd, $truthContext);
        $this->syncWooOrderItemTruth($bookingId, $this->resolveBookingItems($booking, $bookingId), $dateStart, $timeStart, $dateEnd, $timeEnd, (int) ($booking['participants'] ?? 1), $this->resolveBookingResourceId($booking, $bookingId));
        $this->notifications->bookingRescheduled($updated);

        return $this->transformBooking($updated);
    }

    /**
     * Retrieve a single booking record with access filtering applied.
     *
     * @throws InvalidArgumentException When the booking does not exist or is not accessible.
     */
    public function get(int $bookingId): array
    {
        if ($bookingId <= 0) {
            throw new InvalidArgumentException('Booking identifier is required.');
        }

        $bookings = $this->access->filter($this->manager->getBookings(['booking_id' => $bookingId]));

        foreach ($bookings as $booking) {
            if ((int) ($booking['id'] ?? 0) === $bookingId) {
                return $this->transformBooking($booking);
            }
        }

        throw new InvalidArgumentException('Booking could not be found or you do not have access.');
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateDetails(array $payload): array
    {
        $this->access->enforceManage();

        $bookingId = (int) ($payload['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new InvalidArgumentException('Booking identifier is required.');
        }

        $mutations = array_intersect_key(
            $payload,
            array_flip([
                'notes',
                'status',
                'participants',
                'currency',
                'total',
                'date',
                'time',
                'date_end',
                'time_end',
            ])
        );

        if ($mutations === []) {
            throw new InvalidArgumentException('No changes supplied.');
        }

        if (isset($mutations['status']) && $mutations['status'] === 'fully_confirmed') {
            $this->guardDietaryUnresolved($bookingId);
        }

        $booking = $this->manager->getBookings(['booking_id' => $bookingId])[0] ?? null;
        if (! is_array($booking)) {
            throw new InvalidArgumentException('Booking could not be found.');
        }

        if (
            array_key_exists('participants', $mutations)
            || array_key_exists('date', $mutations)
            || array_key_exists('time', $mutations)
            || array_key_exists('date_end', $mutations)
            || array_key_exists('time_end', $mutations)
        ) {
            $nextDateStart = (string) ($mutations['date'] ?? $booking['date'] ?? '');
            $nextTimeStart = (string) ($mutations['time'] ?? $booking['time'] ?? '');
            $nextDateEnd = (string) ($mutations['date_end'] ?? $booking['date_end'] ?? $nextDateStart);
            $nextTimeEnd = (string) ($mutations['time_end'] ?? $booking['time_end'] ?? $nextTimeStart);
            $nextParticipants = (int) ($mutations['participants'] ?? $booking['participants'] ?? 1);

            $this->assertCanonicalBookingTruth(
                $this->resolveBookingItems($booking, $bookingId),
                $nextDateStart,
                $nextTimeStart,
                $nextDateEnd,
                $nextTimeEnd,
                max(1, $nextParticipants),
                $this->resolveBookingResourceId($booking, $bookingId)
            );
        }

        $truthContext = null;
        if (
            array_key_exists('participants', $mutations)
            || array_key_exists('date', $mutations)
            || array_key_exists('time', $mutations)
            || array_key_exists('date_end', $mutations)
            || array_key_exists('time_end', $mutations)
        ) {
            $truthContext = $this->buildBookingTruthContext(
                $this->resolveBookingItems($booking, $bookingId),
                $nextDateStart,
                $nextTimeStart,
                $nextDateEnd,
                $nextTimeEnd,
                max(1, $nextParticipants),
                $this->resolveBookingResourceId($booking, $bookingId),
                'booking_board_update'
            );
        }

        $booking = $this->manager->updateBookingDetails($bookingId, $mutations, $truthContext);
        if (
            array_key_exists('participants', $mutations)
            || array_key_exists('date', $mutations)
            || array_key_exists('time', $mutations)
            || array_key_exists('date_end', $mutations)
            || array_key_exists('time_end', $mutations)
        ) {
            $this->syncWooOrderItemTruth(
                $bookingId,
                $this->resolveBookingItems($booking, $bookingId),
                (string) ($booking['date'] ?? ''),
                (string) ($booking['time'] ?? ''),
                (string) ($booking['date_end'] ?? $booking['date'] ?? ''),
                (string) ($booking['time_end'] ?? $booking['time'] ?? ''),
                (int) ($booking['participants'] ?? 1),
                $this->resolveBookingResourceId($booking, $bookingId)
            );
        }
        $this->notifications->bookingUpdated($booking);

        return $this->transformBooking($booking);
    }

    /**
     * Throw when transitioning to fully_confirmed while dietary profiles are unresolved.
     */
    private function guardDietaryUnresolved(int $bookingId): void
    {
        global $wpdb;
        if (! $wpdb instanceof \wpdb) {
            return;
        }

        $masterTable = $wpdb->prefix . 'bsp_booking_masters';
        $reference   = sprintf('booking:%d', $bookingId);
        $master      = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM {$masterTable} WHERE booking_reference = %s LIMIT 1", $reference),
            ARRAY_A
        );

        if (! is_array($master) || empty($master['id'])) {
            return;
        }

        $summary = (new DietaryProfileService())->buildMasterSummary((int) $master['id']);
        if (! empty($summary['unresolved'])) {
            throw new InvalidArgumentException(
                'Boeking kan niet worden bevestigd: allergieën zijn nog niet afgehandeld door de partner. (dietary_confirmation_pending)'
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createManual(array $payload): array
    {
        $this->access->enforceManage();

        [$dateStart, $timeStart] = $this->extractDateTime($payload, 'date_start', 'time_start', 'start');
        [$dateEnd, $timeEnd]     = $this->extractDateTime($payload, 'date_end', 'time_end', 'end');

        $productId = (int) ($payload['product'] ?? 0);
        if ($productId <= 0) {
            throw new InvalidArgumentException('A bookable product is required.');
        }

        $participants = (int) ($payload['persons'] ?? 1);
        if ($participants <= 0) {
            $participants = 1;
        }

        $price = isset($payload['price']) ? (float) $payload['price'] : 0.0;
        if ($price < 0) {
            $price = 0.0;
        }

        $customer = $this->resolveCustomerProfile($payload);

        $bookingPayload = [
            'customer'     => $customer,
            'date'         => $dateStart,
            'time'         => $timeStart,
            'date_end'     => $dateEnd,
            'time_end'     => $timeEnd,
            'participants' => $participants,
            'items'        => [
                [
                    'product_id' => $productId,
                    'quantity'   => 1,
                    'unit_price' => $price,
                    'label'      => (string) ($payload['product_label'] ?? sprintf(
                        __('Product #%d', 'sbdp'),
                        $productId
                    )),
                ],
            ],
            'pricing_rules' => [],
            'notes'         => isset($payload['notes']) ? (string) $payload['notes'] : null,
            'currency'      => (string) ($payload['currency'] ?? 'EUR'),
            'channel'       => 'manual',
            'vendor_id'     => isset($payload['vendor_id']) ? (int) $payload['vendor_id'] : null,
            'duration'      => isset($payload['duration']) ? (int) $payload['duration'] : null,
        ];

        if (isset($payload['status'])) {
            $bookingPayload['status'] = $payload['status'];
        }

        $resourceId = isset($payload['resource_id']) ? (int) $payload['resource_id'] : 0;
        $this->assertCanonicalBookingTruth(
            $bookingPayload['items'],
            $dateStart,
            $timeStart,
            $dateEnd,
            $timeEnd,
            $participants,
            $resourceId > 0 ? $resourceId : null
        );

        $truthContext = $this->buildBookingTruthContext(
            $bookingPayload['items'],
            $dateStart,
            $timeStart,
            $dateEnd,
            $timeEnd,
            $participants,
            $resourceId > 0 ? $resourceId : null,
            'booking_board_manual_create'
        );
        $booking = $this->manager->createBooking($bookingPayload, $truthContext);
        $this->syncWooOrderItemTruth(
            (int) ($booking['id'] ?? 0),
            $bookingPayload['items'],
            $dateStart,
            $timeStart,
            $dateEnd,
            $timeEnd,
            $participants,
            $resourceId > 0 ? $resourceId : null
        );
        $this->notifications->bookingCreated($booking);

        if (! empty($payload['send_invoice'])) {
            $booking = $this->manager->dispatchInvoice((int) ($booking['id'] ?? 0), ! empty($payload['force_invoice']));
        }

        return $this->transformBooking($booking);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function assertCanonicalBookingTruth(
        array $items,
        string $dateStart,
        string $timeStart,
        string $dateEnd,
        string $timeEnd,
        int $participants,
        ?int $resourceId = null
    ): void {
        if ($items === []) {
            throw new InvalidArgumentException('Boeking mist producten voor canonical beschikbaarheidscontrole.');
        }

        $startIso = $this->buildIso($dateStart, $timeStart);
        $endIso = $this->buildIso($dateEnd, $timeEnd);
        if ($startIso === '' || $endIso === '') {
            throw new InvalidArgumentException('Boeking heeft een ongeldig tijdvenster.');
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $profile = $this->truthRuntime->resolveBookingCapabilityProfile(
                array(
                    'product_id'   => (int) ($item['product_id'] ?? 0),
                    'resource_id'  => isset($item['resource_id']) && (int) $item['resource_id'] > 0
                        ? (int) $item['resource_id']
                        : (int) ($resourceId ?? 0),
                    'participants' => max(1, $participants),
                    'date'         => $dateStart,
                    'start'        => $startIso,
                    'end'          => $endIso,
                )
            );

            if (($profile['status'] ?? BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE) === BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE) {
                throw new InvalidArgumentException('Boeking kan niet worden opgeslagen: canonical booking truth verwerpt deze selectie.');
            }
        }
    }

    /**
     * @param array<string, mixed> $booking
     * @return array<int, array<string, mixed>>
     */
    private function resolveBookingItems(array $booking, int $bookingId): array
    {
        $items = isset($booking['items']) && is_array($booking['items']) ? $booking['items'] : [];
        if ($items !== []) {
            return $items;
        }

        if (! function_exists('wc_get_order')) {
            return [];
        }

        $order = wc_get_order($bookingId);
        if (! is_object($order) || ! method_exists($order, 'get_items')) {
            return [];
        }

        $resolved = [];
        foreach ($order->get_items() as $item) {
            if (! is_object($item) || ! method_exists($item, 'get_product_id')) {
                continue;
            }

            $productId = (int) $item->get_product_id();
            if ($productId <= 0) {
                continue;
            }

            $resolved[] = [
                'product_id' => $productId,
                'resource_id' => method_exists($item, 'get_meta') ? (int) $item->get_meta('sbdp_resource_id', true) : 0,
            ];
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function resolveBookingResourceId(array $booking, int $bookingId): ?int
    {
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($bookingId);
            if (is_object($order) && method_exists($order, 'get_meta')) {
                $resourceId = (int) $order->get_meta('_sbdp_booking_resource', true);
                if ($resourceId > 0) {
                    return $resourceId;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function buildBookingTruthContext(
        array $items,
        string $dateStart,
        string $timeStart,
        string $dateEnd,
        string $timeEnd,
        int $participants,
        ?int $resourceId,
        string $source
    ): array {
        return $this->truthRuntime->resolveBookingWriteContext(
            array(
                'date'         => $dateStart,
                'time'         => $timeStart,
                'date_end'     => $dateEnd,
                'time_end'     => $timeEnd,
                'participants' => max(1, $participants),
                'resource_id'  => (int) ($resourceId ?? 0),
                'items'        => $items,
            ),
            array(
                'resource_id'       => (int) ($resourceId ?? 0),
                'validation_source' => $source,
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function syncWooOrderItemTruth(
        int $bookingId,
        array $items,
        string $dateStart,
        string $timeStart,
        string $dateEnd,
        string $timeEnd,
        int $participants,
        ?int $resourceId = null
    ): void {
        if (
            $bookingId <= 0
            || ! function_exists('wc_get_order')
            || ! function_exists('wc_update_order_item_meta')
        ) {
            return;
        }

        $order = wc_get_order($bookingId);
        if (! is_object($order) || ! method_exists($order, 'get_items')) {
            return;
        }

        $startIso = $this->buildIso($dateStart, $timeStart);
        $endIso = $this->buildIso($dateEnd, $timeEnd);
        if ($startIso === '' || $endIso === '') {
            return;
        }

        $itemsByProduct = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId > 0 && ! isset($itemsByProduct[$productId])) {
                $itemsByProduct[$productId] = $item;
            }
        }

        foreach ($order->get_items() as $item) {
            if (! is_object($item) || ! method_exists($item, 'get_id') || ! method_exists($item, 'get_product_id')) {
                continue;
            }

            $productId = (int) $item->get_product_id();
            $resolved = $itemsByProduct[$productId] ?? ['product_id' => $productId];
            $profile = $this->truthRuntime->resolveBookingCapabilityProfile(
                [
                    'product_id'   => $productId,
                    'resource_id'  => (int) ($resolved['resource_id'] ?? $resourceId ?? 0),
                    'participants' => max(1, $participants),
                    'date'         => $dateStart,
                    'start'        => $startIso,
                    'end'          => $endIso,
                ]
            );
            $meta = $this->truthRuntime->buildCanonicalMeta(
                [
                    'product_id'   => $productId,
                    'resource_id'  => (int) ($resolved['resource_id'] ?? $resourceId ?? 0),
                    'participants' => max(1, $participants),
                    'start'        => $startIso,
                    'end'          => $endIso,
                ],
                $profile
            );

            wc_update_order_item_meta($item->get_id(), 'sbdp_start', $meta['sbdp_start']);
            wc_update_order_item_meta($item->get_id(), 'sbdp_end', $meta['sbdp_end']);
            wc_update_order_item_meta($item->get_id(), 'sbdp_participants', $meta['sbdp_participants']);
            wc_update_order_item_meta($item->get_id(), 'sbdp_canonical_participants', $meta['sbdp_canonical_participants']);
            wc_update_order_item_meta($item->get_id(), 'sbdp_resource_id', $meta['sbdp_resource_id']);
            wc_update_order_item_meta($item->get_id(), 'sbdp_route_intent', $meta['sbdp_route_intent']);
            wc_update_order_item_meta($item->get_id(), 'sbdp_booking_capability', $meta['sbdp_booking_capability']);
        }
    }

    private function buildIso(string $date, string $time): string
    {
        $date = trim($date);
        $time = trim($time);
        if ($date === '' || $time === '') {
            return '';
        }

        return $date . 'T' . $time . ':00';
    }

    /**
     * @return array<string, mixed>
     */
    public function issueInvoice(array $payload): array
    {
        $this->access->enforceManage();

        $bookingId = (int) ($payload['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new InvalidArgumentException('Booking identifier is required.');
        }

        $force = ! empty($payload['force']);
        $booking = $this->manager->dispatchInvoice($bookingId, $force);

        return [
            'booking' => $this->transformBooking($booking),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function replacePartnerFallback(array $payload): array
    {
        $this->access->enforceManage();

        $bookingId = (int) ($payload['booking_id'] ?? 0);
        $legKey = isset($payload['leg_key']) ? (string) $payload['leg_key'] : '';
        $supplierId = isset($payload['supplier_id']) ? (int) $payload['supplier_id'] : null;

        if ($bookingId <= 0 || trim($legKey) === '') {
            throw new InvalidArgumentException('Booking en leg zijn verplicht voor fallback-vervanging.');
        }

        $result = (new PartnerConfirmationService())->replaceWithFallback(
            sprintf('booking:%d', $bookingId),
            $legKey,
            $supplierId
        );

        unset($this->operationsSummaryCache['booking:' . $bookingId]);

        return [
            'booking' => $this->get($bookingId),
            'replacement' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateDietaryProfiles(array $payload): array
    {
        $this->access->enforceManage();

        $bookingId = (int) ($payload['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new InvalidArgumentException('Booking identifier is required.');
        }

        $profiles = isset($payload['profiles']) && is_array($payload['profiles']) ? $payload['profiles'] : [];
        $intakeMode = isset($payload['intake_mode']) ? (string) $payload['intake_mode'] : 'per_guest';
        $bookingReference = sprintf('booking:%d', $bookingId);

        $result = (new DietaryProfileService())->replaceForBookingReference($bookingReference, $profiles, $intakeMode);
        unset($this->operationsSummaryCache[$bookingReference]);

        return [
            'booking' => $this->get($bookingId),
            'dietary' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function invoicePdf(array $payload): array
    {
        $this->access->enforceManage();

        $bookingId = (int) ($payload['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new InvalidArgumentException('Booking identifier is required.');
        }

        $this->ensurePdfInvoicesPlugin();

        if (! function_exists('wc_get_order')) {
            throw new InvalidArgumentException('WooCommerce is required to generate invoices.');
        }

        $booking = $this->manager->prepareInvoiceDocument($bookingId);
        $orderId = isset($booking['order']['id']) ? (int) $booking['order']['id'] : 0;
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Unable to locate the WooCommerce order for this booking.');
        }

        $order = wc_get_order($orderId);
        if (! is_object($order)) {
            throw new InvalidArgumentException('WooCommerce order not found.');
        }

        $document = $this->resolveInvoiceDocument($order);
        $artifacts = $this->extractInvoiceArtifacts($document);

        if ($artifacts['url'] === null && $artifacts['base64'] === null && $artifacts['path'] === null) {
            throw new InvalidArgumentException('Unable to generate the invoice PDF.');
        }

        return [
            'booking'    => $this->transformBooking($booking),
            'pdf_url'    => $artifacts['url'],
            'pdf_base64' => $artifacts['base64'],
            'pdf_path'   => $artifacts['path'],
            'file_name'  => $artifacts['file_name'],
            'order_id'   => $orderId,
            'generated'  => gmdate('c'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function searchCustomers(string $term): array
    {
        $this->access->enforceManage();

        $results = $this->customers->search($term, 15);
        $normalized = array_map(function (array $customer): array {
            $customer['billing']  = $this->normalizeAddress(isset($customer['billing']) && is_array($customer['billing']) ? $customer['billing'] : null);
            $customer['shipping'] = $this->normalizeAddress(isset($customer['shipping']) && is_array($customer['shipping']) ? $customer['shipping'] : null);
            if (! isset($customer['company']) || $customer['company'] === '') {
                $customer['company'] = $customer['billing']['company'];
            }

            return $customer;
        }, $results);

        if (function_exists('apply_filters')) {
            /** @var array<int, array<string, mixed>> $normalized */
            $normalized = (array) apply_filters(
                'sbdp/booking_board/customers/results',
                $normalized,
                $term,
                $this
            );
        }

        return [
            'items' => $normalized,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function stats(array $filters = []): array
    {
        $bookings = $this->applyFilters($this->access->filter($this->manager->getBookings($filters)), $filters);

        return $this->computeStats($bookings);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function export(array $filters, string $format): array
    {
        $result = $this->list($filters);

        return [
            'format'       => strtolower($format),
            'generated_at' => gmdate('c'),
            'rows'         => $result['items'],
            'file_name'    => 'booking_board_export_' . gmdate('Ymd_His') . '.' . strtolower($format),
        ];
    }

    /**
     * Compile available filter metadata based on all accessible bookings.
     *
     * @param array<int, array<string, mixed>> $bookings
     *
     * @return array<string, mixed>
     */
    private function buildFilterCatalogue(array $bookings): array
    {
        $statusCounts = array();
        foreach (array_keys(self::DEFAULT_STATUS_LABELS) as $statusKey) {
            $statusCounts[$statusKey] = 0;
        }

        $channels = array();
        $vendors  = array();
        $products = array();
        $agents   = array();
        $dateMin  = null;
        $dateMax  = null;

        foreach ($bookings as $booking) {
            if (! is_array($booking)) {
                continue;
            }

            $normalized = $this->transformBooking($booking);

            $status = strtolower((string) ($normalized['status'] ?? $booking['status'] ?? ''));
            if ($status !== '') {
                if (! array_key_exists($status, $statusCounts)) {
                    $statusCounts[$status] = 0;
                }

                $statusCounts[$status]++;
            }

            $dateCandidate = '';
            if (isset($normalized['from']) && is_string($normalized['from']) && $normalized['from'] !== '') {
                $dateCandidate = substr($normalized['from'], 0, 10);
            } elseif (isset($booking['date']) && is_string($booking['date'])) {
                $dateCandidate = $booking['date'];
            }

            if ($dateCandidate !== '') {
                if ($dateMin === null || $dateCandidate < $dateMin) {
                    $dateMin = $dateCandidate;
                }

                if ($dateMax === null || $dateCandidate > $dateMax) {
                    $dateMax = $dateCandidate;
                }
            }

            $channelOption = $this->normalizeFilterOption(
                $normalized['channel'] ?? ($booking['channel'] ?? null),
                'channel'
            );
            if ($channelOption !== null) {
                $channelId = $channelOption['id'];
                if (! isset($channels[$channelId])) {
                    $channels[$channelId] = $channelOption + array( 'count' => 0 );
                }

                $channels[$channelId]['count']++;
            }

            $vendorOption = $this->normalizeFilterOption(
                $normalized['vendor'] ?? ($booking['vendor'] ?? null),
                'vendor'
            );
            if ($vendorOption !== null) {
                $vendorId = $vendorOption['id'];
                if (! isset($vendors[$vendorId])) {
                    $vendors[$vendorId] = $vendorOption + array( 'count' => 0 );
                }

                $vendors[$vendorId]['count']++;
            }

            $resourceSource = $normalized['resource'] ?? null;
            if (! is_array($resourceSource) || empty($resourceSource['id'])) {
                $resourceSource = $this->normalizeFilterOption(
                    $booking['planner']['resource'] ?? ($booking['resource'] ?? 'unassigned'),
                    'agent'
                );
            }

            if (\is_array($resourceSource) && isset($resourceSource['id'])) {
                $agentId = (string) $resourceSource['id'];
                $agentLabel = isset($resourceSource['label']) ? (string) $resourceSource['label'] : $agentId;
                if ($agentId === '') {
                    $agentId = 'unassigned';
                }

                if (! isset($agents[$agentId])) {
                    $agents[$agentId] = [
                        'id'    => $agentId,
                        'label' => $agentLabel !== '' ? $agentLabel : ($agentId === 'unassigned'
                            ? (function_exists('__') ? __('Unassigned', 'sbdp') : 'Unassigned')
                            : $this->humanizeValue($agentId)),
                        'count' => 0,
                    ];
                }

                $agents[$agentId]['count']++;
            }

            $items = isset($booking['items']) && is_array($booking['items']) ? $booking['items'] : array();
            if ($items !== array()) {
                $seenProducts = array();
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $productId = isset($item['product_id']) ? (int) $item['product_id'] : 0;
                    if ($productId <= 0 || isset($seenProducts[$productId])) {
                        continue;
                    }

                    $seenProducts[$productId] = true;
                    $productKey = (string) $productId;

                    $productLabel = isset($item['label']) && $item['label'] !== ''
                        ? (string) $item['label']
                        : $this->defaultProductLabel($productId);

                    if (! isset($products[$productKey])) {
                        $products[$productKey] = array(
                            'id'    => $productKey,
                            'label' => $productLabel,
                            'count' => 0,
                        );
                    }

                    $products[$productKey]['count']++;
                }
            }
        }

        $statusOptions = array();
        foreach ($statusCounts as $status => $count) {
            $statusOptions[] = array(
                'id'    => $status,
                'label' => $this->formatStatusLabel($status),
                'count' => (int) $count,
            );
        }

        $statusOptions = $this->sortStatusOptions($statusOptions);

        $catalogue = array(
            'status'     => $statusOptions,
            'channels'   => $this->sortFilterOptions($channels),
            'vendors'    => $this->sortFilterOptions($vendors),
            'products'   => $this->sortFilterOptions($products),
            'agents'     => $this->sortFilterOptions($agents),
            'date_range' => array(
                'min' => $dateMin,
                'max' => $dateMax,
            ),
        );

        if (function_exists('apply_filters')) {
            $catalogue = (array) apply_filters(
                'sbdp_booking_board_filter_catalogue',
                $catalogue,
                $bookings
            );
        }

        return $catalogue;
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $bookings, array $filters): array
    {
        if (isset($filters['status'])) {
            $statuses = (array) $filters['status'];
            $statuses = array_map(static fn ($status) => strtolower((string) $status), $statuses);

            $bookings = array_filter(
                $bookings,
                static function (array $booking) use ($statuses): bool {
                    $status = strtolower((string) ($booking['status'] ?? ''));

                    return $status !== '' && in_array($status, $statuses, true);
                }
            );
        }

        $channelsFilter = $this->extractFilterList($filters, ['channels', 'channel'], 'channel');
        if ($channelsFilter !== []) {
            $bookings = array_filter(
                $bookings,
                function (array $booking) use ($channelsFilter): bool {
                    $option = $this->normalizeFilterOption($booking['channel'] ?? null, 'channel');
                    if ($option === null && isset($booking['meta']['channel'])) {
                        $option = $this->normalizeFilterOption($booking['meta']['channel'], 'channel');
                    }

                    if ($option === null) {
                        return false;
                    }

                    return \in_array($option['id'], $channelsFilter, true);
                }
            );
        }

        $vendorsFilter = $this->extractFilterList($filters, ['vendors', 'vendor', 'vendor_id', 'vendor_ids'], 'vendor');
        if ($vendorsFilter !== []) {
            $bookings = array_filter(
                $bookings,
                function (array $booking) use ($vendorsFilter): bool {
                    $option = $this->normalizeFilterOption($booking['vendor'] ?? null, 'vendor');

                    if ($option === null) {
                        return false;
                    }

                    return \in_array($option['id'], $vendorsFilter, true);
                }
            );
        }

        $outletsFilter = $this->extractFilterList($filters, ['outlets', 'outlet', 'outlet_id', 'outlet_ids'], 'vendor');
        if ($outletsFilter !== []) {
            $bookings = array_filter(
                $bookings,
                function (array $booking) use ($outletsFilter): bool {
                    $option = $this->normalizeFilterOption($booking['vendor'] ?? null, 'vendor');

                    if ($option === null) {
                        return false;
                    }

                    return \in_array($option['id'], $outletsFilter, true);
                }
            );
        }

        $productsFilter = $this->extractFilterList($filters, ['products', 'product', 'product_id', 'product_ids'], 'product');
        if ($productsFilter !== []) {
            $bookings = array_filter(
                $bookings,
                function (array $booking) use ($productsFilter): bool {
                    $items = isset($booking['items']) && \is_array($booking['items']) ? $booking['items'] : [];
                    if ($items === []) {
                        return false;
                    }

                    foreach ($items as $item) {
                        if (! \is_array($item)) {
                            continue;
                        }

                        $productId = $item['product_id'] ?? ($item['id'] ?? null);
                        if ($productId === null || $productId === '') {
                            continue;
                        }

                        $option = $this->normalizeFilterOption($productId, 'product');
                        if ($option !== null && \in_array($option['id'], $productsFilter, true)) {
                            return true;
                        }
                    }

                    return false;
                }
            );
        }

        $agentsFilter = $this->extractFilterList($filters, ['assigned_agents', 'assigned_agent', 'resource', 'resources'], 'agent');
        if ($agentsFilter !== []) {
            $bookings = array_filter(
                $bookings,
                function (array $booking) use ($agentsFilter): bool {
                    $resource = '';

                    if (isset($booking['planner']) && \is_array($booking['planner']) && ! empty($booking['planner']['resource'])) {
                        $resource = (string) $booking['planner']['resource'];
                    } elseif (! empty($booking['resource'])) {
                        $resource = (string) $booking['resource'];
                    }

                    if ($resource === '' && \in_array('unassigned', $agentsFilter, true)) {
                        return true;
                    }

                    $option = $resource !== '' ? $this->normalizeFilterOption($resource, 'agent') : null;
                    if ($option === null && isset($booking['assignee']) && \is_array($booking['assignee'])) {
                        $option = $this->normalizeFilterOption($booking['assignee'], 'agent');
                    }

                    if ($option === null) {
                        return false;
                    }

                    return \in_array($option['id'], $agentsFilter, true);
                }
            );
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $needle   = strtolower((string) $filters['search']);
            $bookings = array_filter(
                $bookings,
                static function (array $booking) use ($needle): bool {
                    $haystacks = [
                        $booking['customer']['name'] ?? '',
                        $booking['customer']['email'] ?? '',
                        $booking['notes'] ?? '',
                    ];

                    if (isset($booking['items']) && is_array($booking['items'])) {
                        foreach ($booking['items'] as $item) {
                            if (isset($item['label'])) {
                                $haystacks[] = (string) $item['label'];
                            }
                        }
                    }

                    foreach ($haystacks as $haystack) {
                        if ($haystack !== '' && strpos(strtolower((string) $haystack), $needle) !== false) {
                            return true;
                        }
                    }

                    return false;
                }
            );
        }

        if (isset($filters['date_from']) || isset($filters['date_to'])) {
            $from = isset($filters['date_from']) && $filters['date_from'] !== ''
                ? new DateTimeImmutable((string) $filters['date_from'])
                : null;
            $to   = isset($filters['date_to']) && $filters['date_to'] !== ''
                ? new DateTimeImmutable((string) $filters['date_to'])
                : null;

            $bookings = array_filter(
                $bookings,
                static function (array $booking) use ($from, $to): bool {
                    $date = isset($booking['date']) ? new DateTimeImmutable((string) $booking['date']) : null;
                    if ($date === null) {
                        return false;
                    }

                    if ($from !== null && $date < $from) {
                        return false;
                    }

                    if ($to !== null && $date > $to) {
                        return false;
                    }

                    return true;
                }
            );
        }

        if (! empty($filters['location'])) {
            $locationNeedle = strtolower((string) $filters['location']);
            $bookings = array_filter(
                $bookings,
                static function (array $booking) use ($locationNeedle): bool {
                    $candidates = [];

                    if (isset($booking['location']) && \is_string($booking['location'])) {
                        $candidates[] = $booking['location'];
                    }

                    if (isset($booking['planner']) && \is_array($booking['planner'])) {
                        if (isset($booking['planner']['location']) && \is_string($booking['planner']['location'])) {
                            $candidates[] = $booking['planner']['location'];
                        }

                        if (isset($booking['planner']['venue']) && \is_string($booking['planner']['venue'])) {
                            $candidates[] = $booking['planner']['venue'];
                        }
                    }

                    foreach ($candidates as $candidate) {
                        if ($candidate === '') {
                            continue;
                        }

                        if (\strpos(strtolower($candidate), $locationNeedle) !== false) {
                            return true;
                        }
                    }

                    return false;
                }
            );
        }

        return array_values($bookings);
    }

    /**
     * @param array<string, array<string, mixed>> $options
     *
     * @return array<int, array<string, mixed>>
     */
    private function sortFilterOptions(array $options): array
    {
        $list = array_values($options);

        usort(
            $list,
            static function (array $left, array $right): int {
                $leftLabel  = isset($left['label']) ? (string) $left['label'] : '';
                $rightLabel = isset($right['label']) ? (string) $right['label'] : '';

                return strcasecmp($leftLabel, $rightLabel);
            }
        );

        return $list;
    }

    /**
     * @param array<int, array<string, mixed>> $options
     *
     * @return array<int, array<string, mixed>>
     */
    private function sortStatusOptions(array $options): array
    {
        $order = array_keys(self::DEFAULT_STATUS_LABELS);

        usort(
            $options,
            static function (array $left, array $right) use ($order): int {
                $leftId  = isset($left['id']) ? (string) $left['id'] : '';
                $rightId = isset($right['id']) ? (string) $right['id'] : '';

                $leftPos  = array_search($leftId, $order, true);
                $rightPos = array_search($rightId, $order, true);

                $leftPos  = $leftPos === false ? PHP_INT_MAX : $leftPos;
                $rightPos = $rightPos === false ? PHP_INT_MAX : $rightPos;

                if ($leftPos === $rightPos) {
                    $leftLabel  = isset($left['label']) ? (string) $left['label'] : '';
                    $rightLabel = isset($right['label']) ? (string) $right['label'] : '';

                    return strcasecmp($leftLabel, $rightLabel);
                }

                return $leftPos <=> $rightPos;
            }
        );

        return $options;
    }

    /**
     * @param mixed $value
     *
     * @return array<string, string>|null
     */
    private function normalizeFilterOption($value, string $type): ?array
    {
        if ($value === null) {
            return null;
        }

        $id    = '';
        $label = '';

        if (is_array($value)) {
            $idCandidates = array( 'id', 'slug', 'key', 'code' );
            foreach ($idCandidates as $candidate) {
                if (isset($value[$candidate]) && (string) $value[$candidate] !== '') {
                    $id = (string) $value[$candidate];
                    break;
                }
            }

            $labelCandidates = array( 'label', 'name', 'title' );
            foreach ($labelCandidates as $candidate) {
                if (isset($value[$candidate]) && (string) $value[$candidate] !== '') {
                    $label = trim((string) $value[$candidate]);
                    break;
                }
            }
        } elseif (is_string($value)) {
            $id    = trim($value);
            $label = $id;
        }

        if ($id === '') {
            $id = $label;
        }

        $id = $this->normalizeFilterKey((string) $id);
        if ($id === '') {
            return null;
        }

        if ($label === '') {
            $label = $this->defaultFilterLabel($type, $id);
        } elseif ($label === $id || strtolower($label) === $id) {
            $label = $this->humanizeValue($label);
        }

        $option = array(
            'id'    => $id,
            'label' => $label,
        );

        if (is_array($value)) {
            $option['data'] = $value;
        }

        return $option;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, string>   $keys
     *
     * @return array<int, string>
     */
    private function extractFilterList(array $filters, array $keys, string $type): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $filters)) {
                return $this->normaliseFilterListEntries($filters[$key], $type);
            }
        }

        return [];
    }

    /**
     * @param mixed $value
     *
     * @return array<int, string>
     */
    private function normaliseFilterListEntries($value, string $type): array
    {
        $entries = $this->prepareFilterEntries($value);
        if ($entries === []) {
            return [];
        }

        $ids = [];
        foreach ($entries as $entry) {
            $option = $this->normalizeFilterOption($entry, $type);
            if ($option !== null) {
                $ids[] = $option['id'];
            }
        }

        if ($ids === []) {
            return [];
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param mixed $value
     *
     * @return array<int, mixed>
     */
    private function prepareFilterEntries($value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return $this->isSequentialArray($value) ? $value : [$value];
        }

        $string = trim((string) $value);
        if ($string === '') {
            return [];
        }

        if (strpos($string, ',') !== false) {
            return array_filter(
                array_map(
                    static fn (string $part): string => trim($part),
                    explode(',', $string)
                ),
                static fn (string $part): bool => $part !== ''
            );
        }

        return [$string];
    }

    /**
     * @param array<int, mixed> $value
     */
    private function isSequentialArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private function defaultProductLabel(int $productId): string
    {
        if (function_exists('__')) {
            return sprintf(__('Product #%d', 'sbdp'), $productId);
        }

        return 'Product #' . $productId;
    }

    private function formatStatusLabel(string $status): string
    {
        $status = strtolower($status);
        if (isset(self::DEFAULT_STATUS_LABELS[$status])) {
            $label = self::DEFAULT_STATUS_LABELS[$status];

            return function_exists('__') ? __( $label, 'sbdp') : $label;
        }

        return $this->humanizeValue($status);
    }

    private function defaultFilterLabel(string $type, string $id): string
    {
        $label = $this->humanizeValue($id);

        if ($label === '') {
            return $id;
        }

        if ($type === 'vendor' && strtolower($id) === 'unassigned') {
            return function_exists('__') ? __('Unassigned', 'sbdp') : 'Unassigned';
        }

        if ($type === 'channel' && strtolower($id) === 'direct') {
            return function_exists('__') ? __('Direct', 'sbdp') : 'Direct';
        }

        if ($type === 'agent' && strtolower($id) === 'unassigned') {
            return function_exists('__') ? __('Unassigned', 'sbdp') : 'Unassigned';
        }

        if ($type === 'product') {
            $numeric = (int) preg_replace('/[^0-9]/', '', $id);
            if ($numeric > 0) {
                return $this->defaultProductLabel($numeric);
            }
        }

        return $label;
    }

    private function normalizeFilterKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('sanitize_key')) {
            $sanitized = sanitize_key($value);
            if ($sanitized !== '') {
                return $sanitized;
            }
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);

        return $value !== null ? trim($value, '-') : '';
    }

    private function humanizeValue(string $value): string
    {
        $value = strtr($value, array( '_' => ' ', '-' => ' ' ));
        $value = preg_replace('/\s+/', ' ', $value);
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return '';
        }

        return ucwords(strtolower($value));
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function transformBooking(array $booking): array
    {
        $start = $this->combineDateTime(
            (string) ($booking['date'] ?? ''),
            (string) ($booking['time'] ?? '')
        );
        $end   = $this->combineDateTime(
            (string) ($booking['date_end'] ?? ($booking['date'] ?? '')),
            (string) ($booking['time_end'] ?? ($booking['time'] ?? ''))
        );

        $customerDetails = $this->buildCustomerDetails(isset($booking['customer']) && is_array($booking['customer']) ? $booking['customer'] : []);
        $resourceDetails = $this->resolveResourceAssignment($booking);

        $location = '';
        if (isset($booking['planner']) && is_array($booking['planner']) && ! empty($booking['planner']['location'])) {
            $location = (string) $booking['planner']['location'];
        } elseif (! empty($booking['location']) && is_string($booking['location'])) {
            $location = (string) $booking['location'];
        }

        return [
            'booking_id'     => $booking['id'] ?? null,
            'product'        => $this->resolveProductLabel($booking),
            'customer'       => $customerDetails['name'],
            'customer_email' => $customerDetails['email'],
            'customer_phone' => $customerDetails['phone'],
            'customer_company' => $customerDetails['company'],
            'from'           => $start,
            'to'             => $end,
            'duration'       => $this->calculateDuration($booking, $start, $end),
            'people'         => $booking['participants'] ?? 0,
            'status'         => $booking['status'] ?? '',
            'price'          => [
                'amount'   => (float) ($booking['total'] ?? 0.0),
                'currency' => $booking['currency'] ?? 'EUR',
            ],
            'channel'       => $booking['channel'] ?? null,
            'vendor'        => $booking['vendor'] ?? null,
            'notes'         => $booking['notes'] ?? '',
            'location'      => $location,
            'resource'      => $resourceDetails,
            'assignee'      => $resourceDetails,
            'customer_details' => $customerDetails,
            'order'         => isset($booking['order']) && is_array($booking['order']) ? $booking['order'] : null,
            'payment_request'=> isset($booking['payment_request']) && is_array($booking['payment_request']) ? $booking['payment_request'] : null,
            'operations'    => $this->resolveOperationsSummary($booking),
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    private function resolveOperationsSummary(array $booking): array
    {
        global $wpdb;
        if (! $wpdb instanceof \wpdb) {
            return [];
        }

        $bookingId = isset($booking['id']) ? (int) $booking['id'] : 0;
        $bookingReference = $bookingId > 0 ? sprintf('booking:%d', $bookingId) : '';
        if ($bookingReference === '') {
            return [];
        }

        if (isset($this->operationsSummaryCache[$bookingReference])) {
            return $this->operationsSummaryCache[$bookingReference];
        }

        $masterTable = $wpdb->prefix . 'bsp_booking_masters';
        $legTable = $wpdb->prefix . 'bsp_booking_legs';
        $guideTable = $wpdb->prefix . 'bsp_guide_assignments';
        $confirmationTable = $wpdb->prefix . 'bsp_partner_confirmations';
        $dietaryService = new DietaryProfileService();

        $master = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$masterTable} WHERE booking_reference = %s LIMIT 1", $bookingReference),
            ARRAY_A
        );

        if (! is_array($master)) {
            $this->operationsSummaryCache[$bookingReference] = [];
            return [];
        }

        $masterId = (int) ($master['id'] ?? 0);
        $legs = $wpdb->get_results(
            $wpdb->prepare("SELECT leg_key, status, leg_type, supplier_id FROM {$legTable} WHERE master_id = %d ORDER BY leg_index ASC", $masterId),
            ARRAY_A
        );
        $guide = $wpdb->get_row(
            $wpdb->prepare("SELECT status, requested_language, primary_guide_id, backup_guide_id, scarcity_score FROM {$guideTable} WHERE master_id = %d LIMIT 1", $masterId),
            ARRAY_A
        );
        $confirmations = $wpdb->get_results(
            $wpdb->prepare("SELECT leg_key, supplier_id, status, payload FROM {$confirmationTable} WHERE master_id = %d ORDER BY scheduled_date ASC, scheduled_time ASC", $masterId),
            ARRAY_A
        );

        $summary = [
            'master_status' => (string) ($master['status'] ?? ''),
            'booking_type' => (string) ($master['booking_type'] ?? ''),
            'leg_counts' => [
                'total' => is_array($legs) ? count($legs) : 0,
                'restaurant_stops' => 0,
                'awaiting_partner' => 0,
                'declined' => 0,
                'alternative_proposed' => 0,
            ],
            'guide' => is_array($guide) ? [
                'status' => (string) ($guide['status'] ?? ''),
                'requested_language' => (string) ($guide['requested_language'] ?? ''),
                'primary_guide_id' => isset($guide['primary_guide_id']) ? (int) $guide['primary_guide_id'] : 0,
                'backup_guide_id' => isset($guide['backup_guide_id']) ? (int) $guide['backup_guide_id'] : 0,
                'scarcity_score' => isset($guide['scarcity_score']) ? (int) $guide['scarcity_score'] : 0,
            ] : null,
            'confirmations' => [],
            'dietary' => $dietaryService->buildMasterSummary($masterId),
            'blockers' => [],
            'risk_level' => 'low',
            'next_action' => '',
        ];

        if (is_array($legs)) {
            foreach ($legs as $leg) {
                if (! is_array($leg)) {
                    continue;
                }

                $legType = (string) ($leg['leg_type'] ?? '');
                $status = (string) ($leg['status'] ?? '');
                if ($legType === 'restaurant_stop') {
                    $summary['leg_counts']['restaurant_stops']++;
                }
                if ($status === 'awaiting_partner') {
                    $summary['leg_counts']['awaiting_partner']++;
                } elseif ($status === 'declined') {
                    $summary['leg_counts']['declined']++;
                } elseif ($status === 'alternative_proposed') {
                    $summary['leg_counts']['alternative_proposed']++;
                }
            }
        }

        if (is_array($confirmations)) {
            foreach ($confirmations as $confirmation) {
                if (! is_array($confirmation)) {
                    continue;
                }

                $payload = json_decode((string) ($confirmation['payload'] ?? ''), true);
                $fallbackSupplierIds = [];
                if (is_array($payload)) {
                    $fallbackSupplierIds = isset($payload['fallback_supplier_ids']) && is_array($payload['fallback_supplier_ids'])
                        ? array_values(array_filter(array_map('intval', $payload['fallback_supplier_ids'])))
                        : [];
                }

                $summary['confirmations'][] = [
                    'leg_key' => (string) ($confirmation['leg_key'] ?? ''),
                    'supplier_id' => isset($confirmation['supplier_id']) ? (int) $confirmation['supplier_id'] : 0,
                    'status' => (string) ($confirmation['status'] ?? ''),
                    'fallback_supplier_ids' => $fallbackSupplierIds,
                    'fallback_available' => $fallbackSupplierIds !== [],
                ];
            }
        }

        if ($summary['leg_counts']['declined'] > 0) {
            $summary['risk_level'] = 'high';
            $summary['blockers'][] = 'partner_declined';
            $summary['next_action'] = 'replace_partner_fallback';
        } elseif ($summary['leg_counts']['alternative_proposed'] > 0) {
            $summary['risk_level'] = 'high';
            $summary['blockers'][] = 'partner_alternative_pending';
            $summary['next_action'] = 'review_partner_alternative';
        } elseif ($summary['leg_counts']['awaiting_partner'] > 0) {
            $summary['risk_level'] = 'medium';
            $summary['blockers'][] = 'partner_confirmation_pending';
            $summary['next_action'] = 'follow_up_partner_confirmation';
        }

        if (is_array($guide) && (string) ($guide['status'] ?? '') === 'needed') {
            $summary['risk_level'] = $summary['risk_level'] === 'high' ? 'high' : 'medium';
            $summary['blockers'][] = 'guide_assignment_missing';
            if ($summary['next_action'] === '') {
                $summary['next_action'] = 'assign_guide';
            }
        }

        if (! empty($summary['dietary']['unresolved'])) {
            $summary['risk_level'] = 'high';
            $summary['blockers'][] = 'dietary_confirmation_pending';
            $summary['master_status'] = 'partially_confirmed';
            $summary['next_action'] = 'resolve_allergy_workflow';
        } elseif (($summary['dietary']['guest_count'] ?? 0) > 0 && $summary['master_status'] === 'partially_confirmed') {
            $summary['dietary']['gate_ready'] = true;
        } else {
            $summary['dietary']['gate_ready'] = false;
        }

        if ($summary['risk_level'] === 'low' && $summary['next_action'] === '') {
            $summary['next_action'] = 'monitor';
        }

        $summary['blockers'] = array_values(array_unique($summary['blockers']));
        $this->operationsSummaryCache[$bookingReference] = $summary;

        return $summary;
    }

    /**
     * @param array<string, mixed> $booking
     *
     * @return array<string, string>
     */
    private function resolveResourceAssignment(array $booking): array
    {
        $resource = '';

        if (isset($booking['planner']) && \is_array($booking['planner']) && ! empty($booking['planner']['resource'])) {
            $resource = (string) $booking['planner']['resource'];
        } elseif (! empty($booking['resource'])) {
            $resource = (string) $booking['resource'];
        }

        if ($resource === '') {
            return [
                'id'    => 'unassigned',
                'label' => function_exists('__') ? __('Unassigned', 'sbdp') : 'Unassigned',
                'code'  => '',
            ];
        }

        $metadata = $this->resolveResourceMetadata($resource);

        return [
            'id'    => $metadata['id'],
            'label' => $metadata['label'],
            'code'  => $metadata['code'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function resolveResourceMetadata(string $resourceId): array
    {
        $resourceId = \trim($resourceId);
        if ($resourceId === '') {
            return [
                'id'    => 'unassigned',
                'label' => function_exists('__') ? __('Unassigned', 'sbdp') : 'Unassigned',
                'code'  => '',
            ];
        }

        if ($this->resourceLookup === null) {
            $this->resourceLookup = $this->buildResourceLookup();
        }

        $lookupKey = $this->normalizeFilterKey($resourceId);
        if ($lookupKey !== '' && isset($this->resourceLookup[$lookupKey])) {
            return $this->resourceLookup[$lookupKey];
        }

        $fallbackLabel = $this->humanizeValue($resourceId);
        if ($fallbackLabel === '') {
            $fallbackLabel = $resourceId;
        }

        return [
            'id'    => $lookupKey !== '' ? $lookupKey : $resourceId,
            'label' => $fallbackLabel,
            'code'  => $resourceId,
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function buildResourceLookup(): array
    {
        $lookup = [];

        if (! class_exists(CityGuideProfileStore::class)) {
            return $lookup;
        }

        try {
            $store = new CityGuideProfileStore();
            $profiles = $store->all();
        } catch (\Throwable $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            $profiles = [];
        }

        foreach ($profiles as $profile) {
            if (! $profile instanceof CityGuideProfile) {
                continue;
            }

            $id       = (string) $profile->id;
            $slugKey  = $this->normalizeFilterKey($id);
            $nameKey  = $this->normalizeFilterKey($profile->name);
            $label    = $profile->name !== '' ? $profile->name : $this->defaultGuideLabel($id);

            $entry = [
                'id'    => $slugKey !== '' ? $slugKey : $id,
                'label' => $label,
                'code'  => $id,
            ];

            if ($slugKey !== '') {
                $lookup[$slugKey] = $entry;
            }

            if ($nameKey !== '' && ! isset($lookup[$nameKey])) {
                $lookup[$nameKey] = $entry;
            }
        }

        return $lookup;
    }

    private function defaultGuideLabel(string $id): string
    {
        if (function_exists('__')) {
            return sprintf(__('Guide #%s', 'sbdp'), $id);
        }

        return 'Guide #' . $id;
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     */
    private function computeStats(array $bookings): array
    {
        $today  = (new DateTimeImmutable('today'))->format('Y-m-d');
        $totals = [
            'total'          => 0,
            'paid'           => 0,
            'pending'        => 0,
            'cancelled'      => 0,
            'completed'      => 0,
            'at_risk'        => 0,
            'revenue_today'  => 0.0,
            'revenue_total'  => 0.0,
            'by_status'      => array(),
            'by_outlet'      => array(),
        ];

        foreach ($bookings as $booking) {
            $normalized = $this->transformBooking($booking);
            $totals['total']++;

            $operations = isset($normalized['operations']) && is_array($normalized['operations']) ? $normalized['operations'] : [];
            if (isset($operations['risk_level']) && in_array((string) $operations['risk_level'], ['medium', 'high'], true)) {
                $totals['at_risk']++;
            }

            $status = strtolower((string) ($normalized['status'] ?? ''));
            if ($status !== '') {
                $totals['by_status'][$status] = ($totals['by_status'][$status] ?? 0) + 1;
            }

            if (isset($totals[$status])) {
                $totals[$status]++;
            }

            $amount = isset($normalized['price']['amount'])
                ? (float) $normalized['price']['amount']
                : (float) ($booking['total'] ?? 0.0);

            $totals['revenue_total'] += $amount;

            $start = isset($normalized['from']) ? (string) $normalized['from'] : '';
            if ($start !== '' && substr($start, 0, 10) === $today) {
                $totals['revenue_today'] += $amount;
            }

            $vendor      = $normalized['vendor'];
            $vendorId    = 'unassigned';
            $vendorLabel = function_exists('__') ? __('Unassigned', 'sbdp') : 'Unassigned';

            if (is_array($vendor)) {
                if (isset($vendor['id']) && $vendor['id'] !== '') {
                    $vendorId = (string) $vendor['id'];
                }

                if (! empty($vendor['name'])) {
                    $vendorLabel = (string) $vendor['name'];
                } elseif (! empty($vendor['title'])) {
                    $vendorLabel = (string) $vendor['title'];
                } elseif (! empty($vendor['label'])) {
                    $vendorLabel = (string) $vendor['label'];
                } elseif ($vendorId !== 'unassigned' && $vendorId !== '') {
                    $vendorLabel = $vendorId;
                }
            } elseif (is_string($vendor) && $vendor !== '') {
                $vendorId    = $vendor;
                $vendorLabel = $vendor;
            }

            if (! isset($totals['by_outlet'][$vendorId])) {
                $totals['by_outlet'][$vendorId] = [
                    'id'             => $vendorId,
                    'label'          => $vendorLabel,
                    'count'          => 0,
                    'revenue_total'  => 0.0,
                    'revenue_today'  => 0.0,
                ];
            }

            $totals['by_outlet'][$vendorId]['count']++;
            $totals['by_outlet'][$vendorId]['revenue_total'] += $amount;

            if ($start !== '' && substr($start, 0, 10) === $today) {
                $totals['by_outlet'][$vendorId]['revenue_today'] += $amount;
            }
        }

        $totals['revenue_today'] = round($totals['revenue_today'], 2);
        $totals['revenue_total'] = round($totals['revenue_total'], 2);
        $totals['by_outlet']     = array_values(
            array_map(
                static function (array $outlet): array {
                    $outlet['revenue_total'] = round($outlet['revenue_total'], 2);
                    $outlet['revenue_today'] = round($outlet['revenue_today'], 2);

                    return $outlet;
                },
                $totals['by_outlet']
            )
        );

        return array_merge(
            $totals,
            [
                'ai' => $this->insights->summarize($bookings),
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0: string, 1: string}
     */
    private function extractDateTime(array $payload, string $dateKey, string $timeKey, string $fallbackKey): array
    {
        if (isset($payload[$dateKey]) || isset($payload[$timeKey])) {
            $date = isset($payload[$dateKey]) ? (string) $payload[$dateKey] : '';
            $time = isset($payload[$timeKey]) ? (string) $payload[$timeKey] : '';
            if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new InvalidArgumentException(sprintf('Field %s must be a date string (YYYY-MM-DD).', $dateKey));
            }

            if ($time === '') {
                $time = '09:00';
            }

            return [$date, $time];
        }

        if (isset($payload[$fallbackKey])) {
            $raw = (string) $payload[$fallbackKey];
            $dateTime = new DateTimeImmutable($raw);

            return [
                $dateTime->format('Y-m-d'),
                $dateTime->format('H:i'),
            ];
        }

        throw new InvalidArgumentException(sprintf('Unable to determine %s datetime.', $fallbackKey));
    }

    private function combineDateTime(string $date, string $time): string
    {
        if ($date === '') {
            return '';
        }

        $value = $date . ' ' . ($time !== '' ? $time : '00:00');

        try {
            return (new DateTimeImmutable($value))->format(DateTimeImmutable::ATOM);
        } catch (\Exception $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $customer
     *
     * @return array<string, mixed>
     */
    private function buildCustomerDetails(array $customer): array
    {
        $billing  = $this->normalizeAddress(isset($customer['billing']) && is_array($customer['billing']) ? $customer['billing'] : null);
        $shipping = $this->normalizeAddress(isset($customer['shipping']) && is_array($customer['shipping']) ? $customer['shipping'] : null);

        $company = (string) ($customer['company'] ?? '');
        if ($company === '') {
            $company = $billing['company'];
        }

        return [
            'id'       => isset($customer['id']) ? (int) $customer['id'] : null,
            'name'     => (string) ($customer['name'] ?? ''),
            'email'    => (string) ($customer['email'] ?? ''),
            'phone'    => (string) ($customer['phone'] ?? ''),
            'company'  => $company,
            'billing'  => $billing,
            'shipping' => $shipping,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function resolveCustomerProfile(array $payload): array
    {
        $profile = [
            'id'       => isset($payload['customer_id']) ? (int) $payload['customer_id'] : null,
            'name'     => trim((string) ($payload['customer_name'] ?? '')),
            'email'    => trim((string) ($payload['customer_email'] ?? '')),
            'phone'    => trim((string) ($payload['customer_phone'] ?? '')),
            'company'  => trim((string) ($payload['customer_company'] ?? '')),
            'billing'  => $this->normalizeAddress(isset($payload['customer_billing']) && is_array($payload['customer_billing']) ? $payload['customer_billing'] : null),
            'shipping' => $this->normalizeAddress(isset($payload['customer_shipping']) && is_array($payload['customer_shipping']) ? $payload['customer_shipping'] : null),
        ];

        $resolved = null;

        if ($profile['id'] !== null && $profile['id'] > 0) {
            $resolved = $this->customers->findById($profile['id']);
        } elseif ($profile['email'] !== '') {
            $resolved = $this->customers->findByEmail($profile['email']);
        }

        $profile = $this->mergeCustomerProfile($profile, is_array($resolved) ? $resolved : null);

        if ($profile['name'] === '') {
            $profile['name'] = 'Manual booking';
        }

        if ($profile['email'] === '') {
            $profile['email'] = 'manual@example.com';
        }

        return $profile;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed>|null $profile
     *
     * @return array<string, mixed>
     */
    private function mergeCustomerProfile(array $base, ?array $profile): array
    {
        if (! is_array($profile)) {
            return $base;
        }

        if (isset($profile['id']) && (int) $profile['id'] > 0) {
            $base['id'] = (int) $profile['id'];
        }

        foreach (['name', 'email', 'phone', 'company'] as $field) {
            if (($base[$field] ?? '') === '' && isset($profile[$field])) {
                $base[$field] = trim((string) $profile[$field]);
            }
        }

        $base['billing']  = $this->mergeAddress($base['billing'], isset($profile['billing']) && is_array($profile['billing']) ? $profile['billing'] : []);
        $base['shipping'] = $this->mergeAddress($base['shipping'], isset($profile['shipping']) && is_array($profile['shipping']) ? $profile['shipping'] : []);

        return $base;
    }

    /**
     * @param array<string, mixed> $subject
     * @param array<string, mixed> $candidate
     *
     * @return array<string, mixed>
     */
    private function mergeAddress(array $subject, array $candidate): array
    {
        $candidate = $this->normalizeAddress($candidate);

        foreach ($candidate as $key => $value) {
            if ($key === 'formatted') {
                continue;
            }

            if ($subject[$key] === '' && $value !== '') {
                $subject[$key] = $value;
            }
        }

        $subject['formatted'] = $this->formatAddress($subject);

        return $subject;
    }

    private function ensurePdfInvoicesPlugin(): void
    {
        if (
            ! function_exists('wpo_wcpdf_get_document')
            && ! class_exists('\WPO\WC\PDF_Invoices\Documents\Invoice')
        ) {
            throw new InvalidArgumentException('PDF Invoices & Packing Slips for WooCommerce is not active.');
        }
    }

    /**
     * @param mixed $order
     *
     * @return object
     */
    private function resolveInvoiceDocument($order)
    {
        if (function_exists('wpo_wcpdf_get_document')) {
            $document = wpo_wcpdf_get_document('invoice', $order);
            if (is_object($document)) {
                return $document;
            }
        }

        if (class_exists('\WPO\WC\PDF_Invoices\Documents\Invoice')) {
            $document = new \WPO\WC\PDF_Invoices\Documents\Invoice();
            if (method_exists($document, 'set_order')) {
                $document->set_order($order);
            }

            return $document;
        }

        throw new InvalidArgumentException('Unable to initialize invoice document.');
    }

    /**
     * @param object $document
     *
     * @return array{url:?string,path:?string,base64:?string,file_name:?string}
     */
    private function extractInvoiceArtifacts($document): array
    {
        $url      = null;
        $path     = null;
        $fileName = null;

        if (method_exists($document, 'get_pdf_filename')) {
            $fileName = (string) $document->get_pdf_filename();
        } elseif (method_exists($document, 'get_pdf_file_name')) {
            $fileName = (string) $document->get_pdf_file_name();
        }

        if (method_exists($document, 'get_pdf_url')) {
            $candidate = (string) $document->get_pdf_url();
            if ($candidate !== '') {
                $url = $candidate;
            }
        }

        if (($url === null || $url === '') && method_exists($document, 'get_pdf')) {
            try {
                $document->get_pdf();
                if (method_exists($document, 'get_pdf_url')) {
                    $candidate = (string) $document->get_pdf_url();
                    if ($candidate !== '') {
                        $url = $candidate;
                    }
                }
            } catch (\Throwable $exception) {
                CoreServiceProvider::logger()->log(
                    sprintf('Invoice PDF render failed: %s', $exception->getMessage())
                );
            }
        }

        if (method_exists($document, 'get_pdf_file_path')) {
            $candidatePath = $document->get_pdf_file_path();
            if (is_string($candidatePath) && $candidatePath !== '') {
                $path = $candidatePath;
                if ($fileName === null) {
                    $fileName = basename($candidatePath);
                }

                if ($url === null) {
                    $url = $this->convertPathToUrl($candidatePath);
                }
            }
        }

        if ($path === null && method_exists($document, 'get_pdf_path')) {
            $candidatePath = $document->get_pdf_path();
            if (is_string($candidatePath) && $candidatePath !== '') {
                $path = $candidatePath;
                if ($fileName === null) {
                    $fileName = basename($candidatePath);
                }
                if ($url === null) {
                    $url = $this->convertPathToUrl($candidatePath);
                }
            }
        }

        $base64 = null;
        if ($path !== null && is_readable($path)) {
            $contents = file_get_contents($path);
            if ($contents !== false) {
                $base64 = base64_encode($contents);
            }
        }

        return [
            'url'       => $url,
            'path'      => $path,
            'base64'    => $base64,
            'file_name' => $fileName,
        ];
    }

    private function convertPathToUrl(string $path): ?string
    {
        if (! function_exists('wp_upload_dir')) {
            return null;
        }

        $uploads = wp_upload_dir();
        if (
            ! is_array($uploads)
            || empty($uploads['basedir'])
            || empty($uploads['baseurl'])
        ) {
            return null;
        }

        $basedir = rtrim((string) $uploads['basedir'], DIRECTORY_SEPARATOR);
        $baseurl = rtrim((string) $uploads['baseurl'], '/');

        if (strpos($path, $basedir) !== 0) {
            return null;
        }

        $relative = ltrim(substr($path, strlen($basedir)), DIRECTORY_SEPARATOR);
        if ($relative === '') {
            return null;
        }

        return $baseurl . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    /**
     * @param array<string, mixed>|null $source
     *
     * @return array<string, string>
     */
    private function normalizeAddress(?array $source): array
    {
        $fields = [
            'company'   => '',
            'address_1' => '',
            'address_2' => '',
            'postcode'  => '',
            'city'      => '',
            'state'     => '',
            'country'   => '',
        ];

        if (is_array($source)) {
            foreach ($fields as $key => $default) {
                if (isset($source[$key])) {
                    $fields[$key] = trim((string) $source[$key]);
                } else {
                    $fields[$key] = $default;
                }
            }
        }

        $fields['formatted'] = $this->formatAddress($fields);

        return $fields;
    }

    /**
     * @param array<string, string> $address
     */
    private function formatAddress(array $address): string
    {
        $parts = [];
        if (($address['company'] ?? '') !== '') {
            $parts[] = $address['company'];
        }

        if (($address['address_1'] ?? '') !== '') {
            $parts[] = $address['address_1'];
        }

        if (($address['address_2'] ?? '') !== '') {
            $parts[] = $address['address_2'];
        }

        $cityLine = trim(sprintf(
            '%s %s',
            $address['postcode'] ?? '',
            $address['city'] ?? ''
        ));

        if ($cityLine !== '') {
            $parts[] = $cityLine;
        }

        if (($address['state'] ?? '') !== '') {
            $parts[] = $address['state'];
        }

        if (($address['country'] ?? '') !== '') {
            $parts[] = $address['country'];
        }

        return implode(', ', array_map('trim', array_filter($parts, static fn (string $part): bool => $part !== '')));
    }

    private function calculateDuration(array $booking, string $start, string $end): ?int
    {
        if (isset($booking['duration_minutes']) && is_int($booking['duration_minutes'])) {
            return $booking['duration_minutes'];
        }

        try {
            $startDate = new DateTimeImmutable($start);
            $endDate   = new DateTimeImmutable($end);
            $diff      = $endDate->getTimestamp() - $startDate->getTimestamp();
            if ($diff <= 0) {
                return null;
            }

            return (int) round($diff / 60);
        } catch (\Exception $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        }

        return null;
    }

    private function resolveProductLabel(array $booking): string
    {
        if (! isset($booking['items']) || ! is_array($booking['items'])) {
            return '';
        }

        $first = reset($booking['items']);

        return isset($first['label']) ? (string) $first['label'] : '';
    }
}
