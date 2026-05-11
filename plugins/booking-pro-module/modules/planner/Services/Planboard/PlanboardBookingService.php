<?php

declare(strict_types=1);

namespace BSP\Planner\Services\Planboard;

use BSP\Bookings\Service\BookingManager;
use BSPModule\Core\Audit\AuditLogger;
use BSPModule\Core\Rest\RestService;
use BSPModule\Core\Services\BookingTruthRuntimeService;
use DateTimeImmutable;
use WP_Error;

final class PlanboardBookingService
{
    private const META_RESOURCE = '_sbdp_booking_resource';
    private const META_CHECKIN = '_sbdp_booking_checkin_at';
    private const META_PAYMENTS = '_sbdp_booking_payment_history';

    private BookingTruthRuntimeService $truthRuntime;

    public function __construct(
        private BookingManager $manager,
        private PlanboardRulesService $rulesService,
        ?BookingTruthRuntimeService $truthRuntime = null
    ) {
        $this->truthRuntime = $truthRuntime ?? new BookingTruthRuntimeService();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|WP_Error
     */
    public function move(array $payload)
    {
        $validated = PlanboardValidator::validateMove($payload);
        if ($validated instanceof WP_Error) {
            return $validated;
        }

        $bookingId = (int) $validated['booking_id'];
        $lockKey = 'booking_move_' . $bookingId;

        if (! PlanboardLock::acquire($lockKey, 20)) {
            return new WP_Error('sbdp_planboard_locked', __('Booking is currently locked.', 'sbdp'), array('status' => 409));
        }

        try {
            $booking = $this->resolveBooking($bookingId);
            if ($booking instanceof WP_Error) {
                return $booking;
            }

            if (! $this->matchesVersion($booking, $validated['version'])) {
                return new WP_Error('sbdp_planboard_version_conflict', __('Booking was updated by someone else.', 'sbdp'), array('status' => 409));
            }

            if ($this->rulesService->isClosed($validated['start'], $validated['end'], $validated['resource_id'])) {
                return new WP_Error('sbdp_planboard_closed', __('Selected slot is closed.', 'sbdp'), array('status' => 409));
            }

            $this->validateMoveBusinessRules($booking, $validated);

            $resourceId = $this->resolveMoveResourceId($booking, $validated);
            $slotCheck = $this->assertCanonicalBookingTruth(
                $this->resolveBookingItems($booking, $bookingId),
                $validated['start'],
                $validated['end'],
                (int) ($booking['participants'] ?? 1),
                $resourceId,
                false
            );
            if ($slotCheck instanceof WP_Error) {
                return $slotCheck;
            }

            $date = substr($validated['start'], 0, 10);
            $time = substr($validated['start'], 11, 5);
            $dateEnd = substr($validated['end'], 0, 10);
            $timeEnd = substr($validated['end'], 11, 5);

            $truthContext = $this->buildBookingTruthContext(
                $this->resolveBookingItems($booking, $bookingId),
                $validated['start'],
                $validated['end'],
                (int) ($booking['participants'] ?? 1),
                $resourceId,
                'planboard_move'
            );
            $updated = $this->manager->rescheduleBooking($bookingId, $date, $time, $dateEnd, $timeEnd, $truthContext);

            $resourceId = $validated['resource_id'];
            if ($resourceId !== null && $resourceId > 0) {
                $this->persistResource($bookingId, (int) $resourceId);
            }

            $this->syncOrderItems(
                $bookingId,
                $validated['start'],
                $validated['end'],
                $resourceId !== null ? (int) $resourceId : null,
                (int) ($booking['participants'] ?? 1),
                $this->resolveBookingItems($booking, $bookingId)
            );

            $payload = array(
                'booking_id' => $bookingId,
                'start'      => $validated['start'],
                'end'        => $validated['end'],
                'resource_id'=> $validated['resource_id'],
            );

            $this->audit('planboard_move', $payload);

            if (function_exists('do_action')) {
                do_action('sbdp/planboard/booking/moved', $payload);
            }

            return $this->resolveBooking($bookingId) ?: $updated;
        } finally {
            PlanboardLock::release($lockKey);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|WP_Error
     */
    public function create(array $payload)
    {
        $validated = PlanboardValidator::validateCreate($payload);
        if ($validated instanceof WP_Error) {
            return $validated;
        }

        $validated['items'] = $this->applyPricing(
            $validated['items'],
            (string) $validated['date'],
            (string) $validated['time'],
            (int) $validated['participants'],
            isset($validated['resource_id']) ? (int) $validated['resource_id'] : null
        );

        $startIso = $this->buildIso((string) $validated['date'], (string) $validated['time']);
        $endIso   = $this->buildIso(
            (string) ($validated['date_end'] ?: $validated['date']),
            (string) ($validated['time_end'] ?: $validated['time'])
        );
        $slotAvailability = $this->assertCanonicalBookingTruth(
            $validated['items'],
            $startIso,
            $endIso,
            (int) $validated['participants'],
            isset($validated['resource_id']) ? (int) $validated['resource_id'] : null,
            true
        );
        if ($slotAvailability instanceof WP_Error) {
            return $slotAvailability;
        }

        $bookingPayload = array(
            'customer'     => $validated['customer'],
            'date'         => $validated['date'],
            'time'         => $validated['time'],
            'date_end'     => $validated['date_end'] ?: $validated['date'],
            'time_end'     => $validated['time_end'] ?: $validated['time'],
            'participants' => $validated['participants'],
            'items'        => $validated['items'],
            'notes'        => $validated['notes'],
            'currency'     => $validated['currency'],
            'channel'      => $validated['channel'],
            'pricing_rules'=> array(),
        );
        $truthContext = $this->buildBookingTruthContext(
            $validated['items'],
            $startIso,
            $endIso,
            (int) $validated['participants'],
            isset($validated['resource_id']) ? (int) $validated['resource_id'] : null,
            'planboard_create'
        );
        $booking = $this->manager->createBooking($bookingPayload, $truthContext);

        if (! empty($validated['resource_id'])) {
            $this->persistResource((int) ($booking['id'] ?? 0), (int) $validated['resource_id']);
        }

        $bookingId = (int) ($booking['id'] ?? 0);
        if ($bookingId > 0) {
            $startIso = $this->buildIso((string) $validated['date'], (string) $validated['time']);
            $endIso = $this->buildIso(
                (string) ($validated['date_end'] ?: $validated['date']),
                (string) ($validated['time_end'] ?: $validated['time'])
            );

            if ($startIso && $endIso) {
                $this->syncOrderItems(
                    $bookingId,
                    $startIso,
                    $endIso,
                    isset($validated['resource_id']) ? (int) $validated['resource_id'] : null,
                    (int) $validated['participants'],
                    $validated['items']
                );
            }
        }

        $payload = array(
            'booking_id' => $bookingId,
            'source'     => 'planboard',
        );

        $this->audit('planboard_create', $payload);

        if (function_exists('do_action')) {
            do_action('sbdp/planboard/booking/created', $payload);
        }

        return $this->resolveBooking((int) ($booking['id'] ?? 0)) ?: $booking;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyPricing(array $items, string $date, string $time, int $participants, ?int $resourceId): array
    {
        if (! function_exists('wc_get_product')) {
            return $items;
        }

        $startIso = $this->buildIso($date, $time);
        if ($startIso === '') {
            return $items;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0.0;
            if ($unitPrice > 0) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $product = wc_get_product($productId);
            if (! $product) {
                continue;
            }

            $itemResource = isset($item['resource_id']) ? (int) $item['resource_id'] : 0;
            if ($itemResource <= 0 && $resourceId !== null && $resourceId > 0) {
                $itemResource = $resourceId;
                $items[$index]['resource_id'] = $itemResource;
            }
            $itemParticipants = isset($item['participants']) ? (int) $item['participants'] : $participants;
            $pricing = RestService::calculate_pricing_for_item(
                $product,
                $itemResource,
                $startIso,
                max(1, $itemParticipants),
                array('channel' => 'planboard')
            );

            if (! is_array($pricing)) {
                continue;
            }

            $resolved = isset($pricing['unit_price']) ? (float) $pricing['unit_price'] : 0.0;
            if ($resolved <= 0 && isset($pricing['total'])) {
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $resolved = $quantity > 0 ? (float) $pricing['total'] / $quantity : 0.0;
            }

            if ($resolved > 0) {
                $items[$index]['unit_price'] = round($resolved, 2);
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|WP_Error
     */
    public function checkin(array $payload)
    {
        $validated = PlanboardValidator::validateCheckin($payload);
        if ($validated instanceof WP_Error) {
            return $validated;
        }

        $booking = $this->resolveBooking((int) $validated['booking_id']);
        if ($booking instanceof WP_Error) {
            return $booking;
        }

        if (! $this->matchesVersion($booking, $validated['version'])) {
            return new WP_Error('sbdp_planboard_version_conflict', __('Booking was updated by someone else.', 'sbdp'), array('status' => 409));
        }

        $this->persistCheckin((int) $validated['booking_id'], $validated['checked_in_at']);

        $payload = array(
            'booking_id'    => (int) $validated['booking_id'],
            'checked_in_at' => $validated['checked_in_at'],
            'notes'         => $validated['notes'],
        );

        $this->audit('planboard_checkin', $payload);

        if (function_exists('do_action')) {
            do_action('sbdp/planboard/booking/checkin', $payload);
        }

        return $this->resolveBooking((int) $validated['booking_id']) ?: array_merge($booking, array('checked_in_at' => $validated['checked_in_at']));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|WP_Error
     */
    public function addPayment(array $payload)
    {
        $validated = PlanboardValidator::validatePayment($payload);
        if ($validated instanceof WP_Error) {
            return $validated;
        }

        $booking = $this->resolveBooking((int) $validated['booking_id']);
        if ($booking instanceof WP_Error) {
            return $booking;
        }

        if (! $this->matchesVersion($booking, $validated['version'])) {
            return new WP_Error('sbdp_planboard_version_conflict', __('Booking was updated by someone else.', 'sbdp'), array('status' => 409));
        }

        $entry = array(
            'amount'      => $validated['amount'],
            'currency'    => $validated['currency'],
            'method'      => $validated['method'],
            'reference'   => $validated['reference'],
            'captured_at' => $validated['captured_at'] ?? gmdate(DateTimeImmutable::ATOM),
            'notes'       => $validated['notes'],
        );

        $this->persistPayment((int) $validated['booking_id'], $entry);

        $updated = $this->manager->updateBookingDetails(
            (int) $validated['booking_id'],
            array(
                'status' => 'paid',
            )
        );

        $this->audit('planboard_payment_add', array_merge(array('booking_id' => (int) $validated['booking_id']), $entry));

        if (function_exists('do_action')) {
            do_action('sbdp/planboard/payment/added', array_merge(array('booking_id' => (int) $validated['booking_id']), $entry));
        }

        return $updated;
    }

    private function resolveBooking(int $bookingId)
    {
        $records = $this->manager->getBookings(array('booking_id' => $bookingId));
        $booking = $records !== array() ? $records[0] : null;

        if (! is_array($booking)) {
            return new WP_Error('sbdp_planboard_booking_missing', __('Booking could not be found.', 'sbdp'), array('status' => 404));
        }

        return $booking;
    }

    private function resolveMoveResourceId(array $booking, array $validated): ?int
    {
        $resourceId = isset($validated['resource_id']) ? (int) $validated['resource_id'] : 0;
        if ($resourceId > 0) {
            return $resourceId;
        }

        $plannerResource = isset($booking['planner']['resource']) ? (int) $booking['planner']['resource'] : 0;
        if ($plannerResource > 0) {
            return $plannerResource;
        }

        $orderResource = isset($booking['resource']) ? (int) $booking['resource'] : 0;
        if ($orderResource > 0) {
            return $orderResource;
        }

        return null;
    }

    private function matchesVersion(array $booking, ?string $expected): bool
    {
        if ($expected === null || $expected === '') {
            return true;
        }

        $current = (string) ($booking['updated_at'] ?? ($booking['order']['updated_at'] ?? ''));

        return $current === '' || hash_equals($current, $expected);
    }

    private function validateMoveBusinessRules(array $booking, array $validated): void
    {
        if (! function_exists('apply_filters')) {
            return;
        }

        /**
         * Allow additional business rule validation for planboard moves.
         *
         * @param true|WP_Error $result
         * @param array<string, mixed> $payload
         * @param array<string, mixed> $booking
         */
        $result = apply_filters('sbdp/planboard/validate_move', true, $validated, $booking);

        if ($result instanceof WP_Error) {
            throw new \InvalidArgumentException($result->get_error_message());
        }
    }

    private function persistResource(int $bookingId, int $resourceId): void
    {
        if ($bookingId <= 0 || $resourceId <= 0 || ! function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($bookingId);
        if (! is_object($order) || ! method_exists($order, 'update_meta_data')) {
            return;
        }

        $order->update_meta_data(self::META_RESOURCE, $resourceId);
        if (method_exists($order, 'save')) {
            $order->save();
        }
    }

    private function syncOrderItems(
        int $bookingId,
        string $startIso,
        string $endIso,
        ?int $resourceId,
        int $participants,
        array $items = array()
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

        $itemsByProduct = array();
        foreach ($items as $bookingItem) {
            if (! is_array($bookingItem)) {
                continue;
            }

            $productId = (int) ($bookingItem['product_id'] ?? 0);
            if ($productId > 0 && ! isset($itemsByProduct[$productId])) {
                $itemsByProduct[$productId] = $bookingItem;
            }
        }

        foreach ($order->get_items() as $item) {
            if (! is_object($item) || ! method_exists($item, 'get_id')) {
                continue;
            }

            $itemId = $item->get_id();
            $productId = method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
            $bookingItem = $itemsByProduct[$productId] ?? array('product_id' => $productId);
            $profile = $this->truthRuntime->resolveBookingCapabilityProfile(
                array(
                    'product_id'   => $productId,
                    'resource_id'  => $resourceId ?? (int) ($bookingItem['resource_id'] ?? 0),
                    'participants' => $participants,
                    'date'         => substr($startIso, 0, 10),
                    'start'        => $startIso,
                    'end'          => $endIso,
                )
            );
            $meta = $this->truthRuntime->buildCanonicalMeta(
                array(
                    'product_id'   => $productId,
                    'resource_id'  => $resourceId ?? (int) ($bookingItem['resource_id'] ?? 0),
                    'participants' => $participants,
                    'start'        => $startIso,
                    'end'          => $endIso,
                ),
                $profile
            );

            if ($startIso !== '') {
                wc_update_order_item_meta($itemId, 'sbdp_start', $meta['sbdp_start']);
            }
            if ($endIso !== '') {
                wc_update_order_item_meta($itemId, 'sbdp_end', $meta['sbdp_end']);
            }
            if ($participants > 0) {
                wc_update_order_item_meta($itemId, 'sbdp_participants', $meta['sbdp_participants']);
                wc_update_order_item_meta($itemId, 'sbdp_canonical_participants', $meta['sbdp_canonical_participants']);
            }
            if ($resourceId !== null) {
                wc_update_order_item_meta($itemId, 'sbdp_resource_id', (int) $meta['sbdp_resource_id']);
            }
            wc_update_order_item_meta($itemId, 'sbdp_route_intent', $meta['sbdp_route_intent']);
            wc_update_order_item_meta($itemId, 'sbdp_booking_capability', $meta['sbdp_booking_capability']);
        }
    }

    private function buildIso(string $date, string $time): string
    {
        try {
            $value = trim($date . ' ' . $time);
            $dt = new DateTimeImmutable($value);
            return $dt->format(DateTimeImmutable::ATOM);
        } catch (\Throwable $exception) {
            return '';
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function assertCanonicalBookingTruth(
        array $items,
        string $startIso,
        string $endIso,
        int $participants,
        ?int $resourceId,
        bool $allowRequest
    ): ?WP_Error
    {
        if ($items === array()) {
            return new WP_Error('sbdp_planboard_missing_booking_items', __('Boeking mist koppelbare producten voor beschikbaarheidscontrole.', 'sbdp'), array('status' => 409));
        }

        $date = substr($startIso, 0, 10);
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemResourceId = isset($item['resource_id']) && (int) $item['resource_id'] > 0
                ? (int) $item['resource_id']
                : (int) ($resourceId ?? 0);
            $profile = $this->truthRuntime->resolveBookingCapabilityProfile(
                array(
                    'product_id'   => (int) ($item['product_id'] ?? 0),
                    'resource_id'  => $itemResourceId,
                    'participants' => max(1, $participants),
                    'date'         => $date,
                    'start'        => $startIso,
                    'end'          => $endIso,
                )
            );

            $status = (string) ($profile['status'] ?? BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE);
            if ($status === BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE) {
                return new WP_Error('sbdp_planboard_slot_unavailable', __('Gekozen tijdslot is niet beschikbaar.', 'sbdp'), array('status' => 409, 'reason_code' => $profile['reason_code'] ?? null));
            }

            if (! $allowRequest && ($profile['route_intent'] ?? BookingTruthRuntimeService::ROUTE_INTENT_BLOCKED) !== BookingTruthRuntimeService::ROUTE_INTENT_CHECKOUT) {
                return new WP_Error('sbdp_planboard_requires_request', __('Deze boeking kan niet als directe planning worden behouden.', 'sbdp'), array('status' => 409, 'reason_code' => $profile['reason_code'] ?? null));
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveBookingItems(array $booking, int $bookingId): array
    {
        $items = isset($booking['items']) && is_array($booking['items']) ? $booking['items'] : array();
        if ($items !== array()) {
            return $items;
        }

        if (! function_exists('wc_get_order')) {
            return array();
        }

        $order = wc_get_order($bookingId);
        if (! is_object($order) || ! method_exists($order, 'get_items')) {
            return array();
        }

        $resolved = array();
        foreach ($order->get_items() as $item) {
            if (! is_object($item) || ! method_exists($item, 'get_product_id')) {
                continue;
            }

            $productId = (int) $item->get_product_id();
            if ($productId <= 0) {
                continue;
            }

            $resolved[] = array(
                'product_id' => $productId,
                'resource_id' => method_exists($item, 'get_meta') ? (int) $item->get_meta('sbdp_resource_id', true) : 0,
            );
        }

        return $resolved;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function buildBookingTruthContext(
        array $items,
        string $startIso,
        string $endIso,
        int $participants,
        ?int $resourceId,
        string $source
    ): array {
        return $this->truthRuntime->resolveBookingWriteContext(
            array(
                'date'         => substr($startIso, 0, 10),
                'time'         => substr($startIso, 11, 5),
                'date_end'     => substr($endIso, 0, 10),
                'time_end'     => substr($endIso, 11, 5),
                'participants' => max(1, $participants),
                'resource_id'  => (int) ($resourceId ?? 0),
                'items'        => $items,
            ),
            array(
                'resource_id'       => (int) ($resourceId ?? 0),
                'start'             => $startIso,
                'end'               => $endIso,
                'validation_source' => $source,
            )
        );
    }

    private function persistCheckin(int $bookingId, string $timestamp): void
    {
        if ($bookingId <= 0 || ! function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($bookingId);
        if (! is_object($order) || ! method_exists($order, 'update_meta_data')) {
            return;
        }

        $order->update_meta_data(self::META_CHECKIN, $timestamp);
        if (method_exists($order, 'save')) {
            $order->save();
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function persistPayment(int $bookingId, array $entry): void
    {
        if ($bookingId <= 0 || ! function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($bookingId);
        if (! is_object($order) || ! method_exists($order, 'get_meta') || ! method_exists($order, 'update_meta_data')) {
            return;
        }

        $existing = $order->get_meta(self::META_PAYMENTS, true);
        if (! is_array($existing)) {
            $existing = array();
        }

        $existing[] = $entry;
        $order->update_meta_data(self::META_PAYMENTS, $existing);

        if (! empty($entry['method']) && method_exists($order, 'set_payment_method')) {
            $order->set_payment_method((string) $entry['method']);
        }

        if (! empty($entry['reference']) && method_exists($order, 'set_transaction_id')) {
            $order->set_transaction_id((string) $entry['reference']);
        }

        if (method_exists($order, 'add_order_note')) {
            $order->add_order_note(sprintf('Planboard manual payment of %s %0.2f recorded.', $entry['currency'], $entry['amount']));
        }

        if (method_exists($order, 'save')) {
            $order->save();
        }
    }

    private function audit(string $action, array $payload): void
    {
        if (class_exists(AuditLogger::class)) {
            AuditLogger::log(
                $action,
                array('scope' => 'planboard'),
                $payload,
                'info'
            );

            return;
        }

        if (function_exists('do_action')) {
            do_action('sbdp/audit/log', $action, array('scope' => 'planboard'), $payload, 'info');
        }
    }
}
