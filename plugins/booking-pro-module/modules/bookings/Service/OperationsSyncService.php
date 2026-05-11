<?php

declare(strict_types=1);

namespace BSP\Bookings\Service;

use BSP\Bookings\Support\Installer;
use BSP\Core\CoreServiceProvider;
use SBDP\Modules\Arrangements\Domain\ArrangementAvailabilityService;
use SBDP\Modules\Arrangements\Domain\ArrangementRepository;
use Throwable;
use wpdb;

final class OperationsSyncService
{
    private ?ArrangementRepository $arrangementRepository = null;
    private ?ArrangementAvailabilityService $arrangementAvailability = null;
    private ?GuideAssignmentService $guideAssignments = null;
    private ?PartnerConfirmationService $partnerConfirmations = null;

    /**
     * @param array<string, mixed> $booking
     */
    public function sync(array $booking): void
    {
        try {
            Installer::maybeInstall();

            global $wpdb;
            if (! $wpdb instanceof wpdb) {
                return;
            }

            $master = $this->buildMasterRow($booking);
            $masterId = $this->upsertMaster($wpdb, $master);
            if ($masterId <= 0) {
                return;
            }

            $legs = $this->replaceLegs($wpdb, $masterId, $master, $booking);
            $this->getGuideAssignments()->syncForMaster($masterId, $master, $booking, $legs);
            $this->getPartnerConfirmations()->syncForMaster($masterId, $master, $booking, $legs);
            $this->recordEvent($wpdb, $masterId, $master, $booking);
        } catch (Throwable $exception) {
            CoreServiceProvider::logger()->log(
                sprintf('Booking operations sync failed for #%s: %s', (string) ($booking['id'] ?? 'unknown'), $exception->getMessage())
            );
        }
    }

    /**
     * @param array<string, mixed> $booking
     *
     * @return array<string, mixed>
     */
    private function buildMasterRow(array $booking): array
    {
        $bookingReference = $this->deriveBookingReference($booking);
        $legacyStatus = strtolower(trim((string) ($booking['status'] ?? 'draft')));
        $order = isset($booking['order']) && is_array($booking['order']) ? $booking['order'] : [];
        $customer = isset($booking['customer']) && is_array($booking['customer']) ? $booking['customer'] : [];
        $vendor = isset($booking['vendor']) && is_array($booking['vendor']) ? $booking['vendor'] : [];

        return [
            'booking_reference'   => $bookingReference,
            'woo_order_id'        => $this->deriveWooOrderId($booking, $order),
            'legacy_booking_id'   => isset($booking['id']) ? (int) $booking['id'] : null,
            'status'              => $this->mapMasterStatus($legacyStatus),
            'legacy_status'       => $legacyStatus,
            'booking_type'        => $this->deriveBookingType($booking),
            'commercial_status'   => isset($order['status']) ? (string) $order['status'] : '',
            'commercial_currency' => (string) ($booking['currency'] ?? 'EUR'),
            'commercial_total'    => round((float) ($booking['total'] ?? 0.0), 2),
            'participants'        => max(0, (int) ($booking['participants'] ?? 0)),
            'customer_name'       => isset($customer['name']) ? (string) $customer['name'] : '',
            'customer_email'      => isset($customer['email']) ? (string) $customer['email'] : '',
            'booking_date'        => $this->normalizeDate($booking['date'] ?? null),
            'booking_time'        => $this->normalizeTime($booking['time'] ?? null),
            'booking_end_date'    => $this->normalizeDate($booking['date_end'] ?? ($booking['date'] ?? null)),
            'booking_end_time'    => $this->normalizeTime($booking['time_end'] ?? ($booking['time'] ?? null)),
            'channel'             => $this->normalizeChannel($booking['channel'] ?? null),
            'vendor_id'           => isset($vendor['id']) ? (int) $vendor['id'] : null,
            'resource_ref'        => $this->normalizeResource($booking['resource'] ?? null),
            'payload'             => $this->encodeJson($booking),
        ];
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function deriveBookingReference(array $booking): string
    {
        $bookingId = isset($booking['id']) ? (int) $booking['id'] : 0;

        return $bookingId > 0 ? sprintf('booking:%d', $bookingId) : 'booking:0';
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $order
     */
    private function deriveWooOrderId(array $booking, array $order): ?int
    {
        $orderId = isset($order['id']) ? (int) $order['id'] : 0;
        if ($orderId > 0) {
            return $orderId;
        }

        $bookingId = isset($booking['id']) ? (int) $booking['id'] : 0;

        return $bookingId > 0 ? $bookingId : null;
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function deriveBookingType(array $booking): string
    {
        $items = isset($booking['items']) && is_array($booking['items']) ? $booking['items'] : [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach ($this->resolveLegDefinitions($item, $booking) as $leg) {
                $legType = isset($leg['leg_type']) ? (string) $leg['leg_type'] : '';
                if ($legType === 'restaurant_stop') {
                    return 'walking_dinner';
                }
            }
        }

        return count($items) > 1 ? 'combo' : 'standard';
    }

    private function mapMasterStatus(string $legacyStatus): string
    {
        return match ($legacyStatus) {
            'created', 'draft', 'conflict' => 'draft',
            'requested', 'pending' => 'pending',
            'captured', 'paid' => 'partially_confirmed',
            'completed' => 'completed',
            'cancelled', 'failed', 'refunded' => 'cancelled',
            default => 'draft',
        };
    }

    /**
     * @param mixed $value
     */
    private function normalizeDate($value): ?string
    {
        $date = is_string($value) ? trim($value) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }

    /**
     * @param mixed $value
     */
    private function normalizeTime($value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param mixed $value
     */
    private function normalizeChannel($value): string
    {
        $channel = is_string($value) ? strtolower(trim($value)) : '';

        return $channel !== '' ? $channel : 'web';
    }

    /**
     * @param mixed $value
     */
    private function normalizeResource($value): string
    {
        if (is_array($value)) {
            $id = isset($value['id']) ? (string) $value['id'] : '';
            $name = isset($value['name']) ? (string) $value['name'] : '';

            return trim($id !== '' ? $id : $name);
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $master
     */
    private function upsertMaster(wpdb $wpdb, array $master): int
    {
        $table = $wpdb->prefix . 'bsp_booking_masters';
        $existingId = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE booking_reference = %s LIMIT 1",
                (string) $master['booking_reference']
            )
        );

        $payload = $master;
        unset($payload['created_at'], $payload['updated_at']);

        if ($existingId > 0) {
            $wpdb->update($table, $payload, ['id' => $existingId]);

            return $existingId;
        }

        $wpdb->insert($table, $payload);

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $master
     * @param array<string, mixed> $booking
     */
    private function replaceLegs(wpdb $wpdb, int $masterId, array $master, array $booking): array
    {
        $table = $wpdb->prefix . 'bsp_booking_legs';
        $existingStatuses = $this->loadExistingLegStatuses($wpdb, $masterId);
        $wpdb->delete($table, ['master_id' => $masterId], ['%d']);

        $items = isset($booking['items']) && is_array($booking['items']) ? $booking['items'] : [];
        if ($items === []) {
            $items = [[]];
        }

        $legIndex = 1;
        $insertedLegs = [];
        foreach ($items as $index => $item) {
            $item = is_array($item) ? $item : [];
            foreach ($this->resolveLegDefinitions($item, $booking) as $resolvedIndex => $resolvedLeg) {
                unset($resolvedIndex);
                $payload = [
                    'master_id'          => $masterId,
                    'booking_reference'  => $master['booking_reference'],
                    'woo_order_id'       => $master['woo_order_id'],
                    'legacy_booking_id'  => $master['legacy_booking_id'],
                    'leg_key'            => (string) ($resolvedLeg['leg_key'] ?? $this->deriveLegKey($index, isset($resolvedLeg['product_id']) ? (int) $resolvedLeg['product_id'] : null)),
                    'leg_index'          => $legIndex,
                    'status'             => $this->resolveLegStatus(
                        $resolvedLeg,
                        (string) $master['legacy_status'],
                        $existingStatuses
                    ),
                    'legacy_status'      => (string) $master['legacy_status'],
                    'leg_type'           => (string) ($resolvedLeg['leg_type'] ?? 'product_item'),
                    'title'              => (string) ($resolvedLeg['title'] ?? sprintf('Leg %d', $legIndex)),
                    'product_id'         => isset($resolvedLeg['product_id']) ? (int) $resolvedLeg['product_id'] : null,
                    'supplier_id'        => isset($resolvedLeg['supplier_id']) ? (int) $resolvedLeg['supplier_id'] : (isset($master['vendor_id']) ? (int) $master['vendor_id'] : null),
                    'scheduled_date'     => $resolvedLeg['scheduled_date'] ?? $master['booking_date'],
                    'scheduled_time'     => $resolvedLeg['scheduled_time'] ?? $master['booking_time'],
                    'scheduled_end_date' => $resolvedLeg['scheduled_end_date'] ?? $master['booking_end_date'],
                    'scheduled_end_time' => $resolvedLeg['scheduled_end_time'] ?? $master['booking_end_time'],
                    'participants'       => isset($resolvedLeg['participants']) ? max(0, (int) $resolvedLeg['participants']) : max(0, (int) ($booking['participants'] ?? 0)),
                    'payload'            => $this->encodeJson([
                        'resolved_leg' => $resolvedLeg,
                        'booking_type' => $master['booking_type'],
                        'source_item'  => $item,
                    ]),
                ];

                $wpdb->insert($table, $payload);
                $payload['id'] = (int) $wpdb->insert_id;
                $insertedLegs[] = $payload;
                $legIndex++;
            }
        }

        return $insertedLegs;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $booking
     * @return array<int, array<string, mixed>>
     */
    private function resolveLegDefinitions(array $item, array $booking): array
    {
        $legs = $this->resolvePlannerLegs($item, $booking);
        if ($legs !== []) {
            return $legs;
        }

        $legs = $this->resolveArrangementTemplateLegs($item, $booking);
        if ($legs !== []) {
            return $legs;
        }

        return [$this->buildFallbackLeg($item, $booking, 0)];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $booking
     * @return array<int, array<string, mixed>>
     */
    private function resolvePlannerLegs(array $item, array $booking): array
    {
        $meta = isset($item['meta']) && is_array($item['meta']) ? $item['meta'] : [];
        $planItem = isset($meta['plan_item']) && is_array($meta['plan_item']) ? $meta['plan_item'] : [];
        $bookingResolution = isset($planItem['bookingResolution']) && is_array($planItem['bookingResolution'])
            ? $planItem['bookingResolution']
            : [];
        $segments = isset($bookingResolution['confirmedSegments']) && is_array($bookingResolution['confirmedSegments'])
            ? $bookingResolution['confirmedSegments']
            : (isset($bookingResolution['segments']) && is_array($bookingResolution['segments']) ? $bookingResolution['segments'] : []);

        if ($segments === []) {
            return [];
        }

        $legs = [];
        foreach (array_values($segments) as $index => $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $legs[] = $this->buildLegFromSegment(
                $segment,
                $item,
                $booking,
                $index,
                'planner_resolution'
            );
        }

        return $legs;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $booking
     * @return array<int, array<string, mixed>>
     */
    private function resolveArrangementTemplateLegs(array $item, array $booking): array
    {
        $arrangement = $this->findArrangementForItem($item);
        if ($arrangement === null) {
            return [];
        }

        $participants = $this->resolveCanonicalParticipants($booking, $item);
        if ($participants <= 0) {
            return [];
        }

        $resolution = $this->getArrangementAvailability()->resolve(
            $arrangement,
            [
                'date'         => (string) ($booking['date'] ?? ''),
                'start'        => (string) ($booking['time'] ?? ''),
                'participants' => $participants,
            ]
        );

        $segments = isset($resolution['segments']) && is_array($resolution['segments'])
            ? $resolution['segments']
            : [];
        if ($segments === []) {
            return [];
        }

        $legs = [];
        foreach (array_values($segments) as $index => $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $legs[] = $this->buildLegFromSegment(
                $segment,
                $item,
                $booking,
                $index,
                'arrangement_template'
            );
        }

        return $legs;
    }

    /**
     * @param array<string, mixed> $segment
     * @param array<string, mixed> $item
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    private function buildLegFromSegment(array $segment, array $item, array $booking, int $index, string $source): array
    {
        $segmentType = strtolower(trim((string) ($segment['segment_type'] ?? $segment['type'] ?? 'activity')));
        $role = strtolower(trim((string) ($segment['role'] ?? '')));
        $productId = (int) ($segment['linked_product_id'] ?? $segment['product_id'] ?? $item['product_id'] ?? 0);
        $supplierId = (int) ($segment['linked_vendor_id'] ?? $segment['vendor_id'] ?? 0);
        $title = trim((string) ($segment['title_override'] ?? $segment['ui_label'] ?? $segment['title'] ?? ''));
        if ($title === '') {
            $title = isset($item['label']) ? (string) $item['label'] : sprintf('Leg %d', $index + 1);
        }

        $startDateTime = $this->extractLegDateTime(
            $segment['resolved_start'] ?? ($segment['startTime'] ?? ($segment['start'] ?? '')),
            (string) ($booking['date'] ?? '')
        );
        $endDateTime = $this->extractLegDateTime(
            $segment['resolved_end'] ?? ($segment['endTime'] ?? ($segment['end'] ?? '')),
            (string) ($booking['date'] ?? '')
        );

        return [
            'leg_key'            => sprintf('%s-%d-%d', $source, $index + 1, max(0, $productId)),
            'status'             => $this->mapLegStatus((string) ($booking['status'] ?? 'draft')),
            'leg_type'           => $this->mapSegmentToLegType($segmentType, $role),
            'title'              => $title,
            'product_id'         => $productId > 0 ? $productId : null,
            'supplier_id'        => $supplierId > 0 ? $supplierId : null,
            'scheduled_date'     => $startDateTime['date'],
            'scheduled_time'     => $startDateTime['time'],
            'scheduled_end_date' => $endDateTime['date'],
            'scheduled_end_time' => $endDateTime['time'],
            'participants'       => max(0, $this->resolveCanonicalParticipants($booking, $item)),
            'source'             => $source,
            'segment_type'       => $segmentType,
            'segment_role'       => $role,
            'segment'            => $segment,
        ];
    }

    private function mapSegmentToLegType(string $segmentType, string $role): string
    {
        if ($segmentType === 'food_drink') {
            return 'restaurant_stop';
        }

        if ($segmentType === 'transport') {
            return 'transport_leg';
        }

        if ($role === 'anchor') {
            return 'anchor_activity';
        }

        return 'activity';
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    private function buildFallbackLeg(array $item, array $booking, int $index): array
    {
        $meta = isset($item['meta']) && is_array($item['meta']) ? $item['meta'] : [];
        $start = $this->extractLegDateTime($meta['start'] ?? ($booking['time'] ?? ''), (string) ($booking['date'] ?? ''));
        $end = $this->extractLegDateTime($meta['end'] ?? ($booking['time_end'] ?? ($booking['time'] ?? '')), (string) ($booking['date_end'] ?? ($booking['date'] ?? '')));

        return [
            'leg_key'            => $this->deriveLegKey($index, isset($item['product_id']) ? (int) $item['product_id'] : null),
            'status'             => $this->mapLegStatus((string) ($booking['status'] ?? 'draft')),
            'leg_type'           => 'product_item',
            'title'              => isset($item['label']) ? (string) $item['label'] : sprintf('Leg %d', $index + 1),
            'product_id'         => isset($item['product_id']) ? (int) $item['product_id'] : null,
            'supplier_id'        => null,
            'scheduled_date'     => $start['date'] ?: $this->normalizeDate($booking['date'] ?? null),
            'scheduled_time'     => $start['time'] ?: $this->normalizeTime($booking['time'] ?? null),
            'scheduled_end_date' => $end['date'] ?: $this->normalizeDate($booking['date_end'] ?? ($booking['date'] ?? null)),
            'scheduled_end_time' => $end['time'] ?: $this->normalizeTime($booking['time_end'] ?? ($booking['time'] ?? null)),
            'participants'       => max(0, $this->resolveCanonicalParticipants($booking, $item)),
            'source'             => 'fallback_item',
        ];
    }

    /**
     * Resolve participant truth from canonical booking/item meta only.
     *
     * Quantity is explicitly not participants truth.
     *
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $item
     */
    private function resolveCanonicalParticipants(array $booking, array $item): int
    {
        $meta = isset($item['meta']) && is_array($item['meta']) ? $item['meta'] : [];
        $sources = [
            $meta['sbdp_canonical_participants'] ?? null,
            $meta['participants'] ?? null,
            $item['participants'] ?? null,
            $booking['participants'] ?? null,
        ];

        foreach ($sources as $candidate) {
            $participants = (int) $candidate;
            if ($participants > 0) {
                return $participants;
            }
        }

        return 0;
    }

    /**
     * @return array{date:?string,time:string}
     * @param mixed $value
     */
    private function extractLegDateTime($value, string $fallbackDate): array
    {
        if (! is_string($value)) {
            return ['date' => $this->normalizeDate($fallbackDate), 'time' => ''];
        }

        $value = trim($value);
        if ($value === '') {
            return ['date' => $this->normalizeDate($fallbackDate), 'time' => ''];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T(\d{2}:\d{2})/', $value, $matches) === 1) {
            return [
                'date' => substr($value, 0, 10),
                'time' => $matches[1],
            ];
        }

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return [
                'date' => $this->normalizeDate($fallbackDate),
                'time' => $value,
            ];
        }

        return ['date' => $this->normalizeDate($fallbackDate), 'time' => ''];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function findArrangementForItem(array $item): ?array
    {
        $productId = isset($item['product_id']) ? (int) $item['product_id'] : 0;
        $meta = isset($item['meta']) && is_array($item['meta']) ? $item['meta'] : [];
        $planItem = isset($meta['plan_item']) && is_array($meta['plan_item']) ? $meta['plan_item'] : [];
        $aggregate = isset($meta['plan_aggregate']) && is_array($meta['plan_aggregate']) ? $meta['plan_aggregate'] : [];
        $label = isset($item['label']) ? trim((string) $item['label']) : '';

        $bySalesProduct = $this->findArrangementBySalesProductId($productId);
        if ($bySalesProduct !== null) {
            return $bySalesProduct;
        }

        $candidateIds = array(
            isset($planItem['arrangement_id']) ? (int) $planItem['arrangement_id'] : 0,
            isset($aggregate['arrangement_id']) ? (int) $aggregate['arrangement_id'] : 0,
            $this->extractArrangementIdFromGroupRef((string) ($planItem['aggregateId'] ?? '')),
            $this->extractArrangementIdFromGroupRef((string) ($planItem['groupId'] ?? '')),
            $this->extractArrangementIdFromGroupRef((string) ($aggregate['aggregateId'] ?? '')),
            $this->extractArrangementIdFromGroupRef((string) ($aggregate['groupId'] ?? '')),
        );

        foreach ($candidateIds as $candidateId) {
            if ($candidateId <= 0) {
                continue;
            }

            $arrangement = $this->getArrangementRepository()->find($candidateId);
            if (is_array($arrangement) && $arrangement !== []) {
                return $arrangement;
            }
        }

        if ($label !== '') {
            foreach ($this->getArrangementRepository()->query() as $arrangement) {
                if (! is_array($arrangement)) {
                    continue;
                }

                $title = isset($arrangement['title']) ? trim((string) $arrangement['title']) : '';
                if ($title !== '' && strcasecmp($title, $label) === 0) {
                    return $arrangement;
                }
            }
        }

        return null;
    }

    private function extractArrangementIdFromGroupRef(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        if (preg_match('/arrangement-(\d+)/', $value, $matches) === 1) {
            return (int) ($matches[1] ?? 0);
        }

        return 0;
    }

    private function findArrangementBySalesProductId(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        foreach ($this->getArrangementRepository()->query() as $arrangement) {
            if (! is_array($arrangement)) {
                continue;
            }

            if ((int) ($arrangement['sales_product_id'] ?? 0) === $productId) {
                return $arrangement;
            }
        }

        return null;
    }

    private function getArrangementRepository(): ArrangementRepository
    {
        if ($this->arrangementRepository === null) {
            $this->arrangementRepository = new ArrangementRepository();
        }

        return $this->arrangementRepository;
    }

    private function getArrangementAvailability(): ArrangementAvailabilityService
    {
        if ($this->arrangementAvailability === null) {
            $this->arrangementAvailability = new ArrangementAvailabilityService();
        }

        return $this->arrangementAvailability;
    }

    private function getGuideAssignments(): GuideAssignmentService
    {
        if ($this->guideAssignments === null) {
            $this->guideAssignments = new GuideAssignmentService();
        }

        return $this->guideAssignments;
    }

    private function getPartnerConfirmations(): PartnerConfirmationService
    {
        if ($this->partnerConfirmations === null) {
            $this->partnerConfirmations = new PartnerConfirmationService();
        }

        return $this->partnerConfirmations;
    }

    /**
     * @return array<string, string>
     */
    private function loadExistingLegStatuses(wpdb $wpdb, int $masterId): array
    {
        $table = $wpdb->prefix . 'bsp_booking_legs';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT leg_key, status FROM {$table} WHERE master_id = %d", $masterId),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        $statuses = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $legKey = isset($row['leg_key']) ? (string) $row['leg_key'] : '';
            if ($legKey === '') {
                continue;
            }

            $statuses[$legKey] = isset($row['status']) ? (string) $row['status'] : 'draft';
        }

        return $statuses;
    }

    /**
     * @param array<string, mixed> $resolvedLeg
     * @param array<string, string> $existingStatuses
     */
    private function resolveLegStatus(array $resolvedLeg, string $legacyStatus, array $existingStatuses): string
    {
        $legKey = isset($resolvedLeg['leg_key']) ? (string) $resolvedLeg['leg_key'] : '';
        if ($legKey !== '' && isset($existingStatuses[$legKey]) && $existingStatuses[$legKey] !== '') {
            return $existingStatuses[$legKey];
        }

        if (isset($resolvedLeg['status']) && is_string($resolvedLeg['status']) && trim($resolvedLeg['status']) !== '') {
            return (string) $resolvedLeg['status'];
        }

        return $this->mapLegStatus(
            $legacyStatus,
            isset($resolvedLeg['leg_type']) ? (string) $resolvedLeg['leg_type'] : ''
        );
    }

    private function deriveLegKey(int $index, ?int $productId): string
    {
        if ($productId !== null && $productId > 0) {
            return sprintf('item-%d-product-%d', $index + 1, $productId);
        }

        return sprintf('item-%d', $index + 1);
    }

    private function mapLegStatus(string $legacyStatus, string $legType = ''): string
    {
        if ($legType === 'restaurant_stop') {
            return match ($legacyStatus) {
                'completed' => 'completed',
                'cancelled', 'failed', 'refunded' => 'declined',
                default => 'awaiting_partner',
            };
        }

        return match ($legacyStatus) {
            'paid', 'captured' => 'paid',
            'completed' => 'completed',
            default => 'draft',
        };
    }

    /**
     * @param array<string, mixed> $master
     * @param array<string, mixed> $booking
     */
    private function recordEvent(wpdb $wpdb, int $masterId, array $master, array $booking): void
    {
        $table = $wpdb->prefix . 'bsp_booking_events';
        $wpdb->insert(
            $table,
            [
                'master_id'         => $masterId,
                'leg_id'            => null,
                'booking_reference' => $master['booking_reference'],
                'woo_order_id'      => $master['woo_order_id'],
                'event_type'        => 'projection_synced',
                'payload'           => $this->encodeJson([
                    'status' => $master['status'],
                    'legacy_status' => $master['legacy_status'],
                    'item_count' => isset($booking['items']) && is_array($booking['items']) ? count($booking['items']) : 0,
                ]),
            ]
        );
    }

    /**
     * @param mixed $value
     */
    private function encodeJson($value): string
    {
        if (function_exists('wp_json_encode')) {
            $encoded = wp_json_encode($value);
        } else {
            $encoded = json_encode($value);
        }

        return is_string($encoded) ? $encoded : '';
    }
}

if (! class_exists('BSPModule\\Bookings\\Service\\OperationsSyncService', false)) {
    class_alias(OperationsSyncService::class, 'BSPModule\\Bookings\\Service\\OperationsSyncService');
}
