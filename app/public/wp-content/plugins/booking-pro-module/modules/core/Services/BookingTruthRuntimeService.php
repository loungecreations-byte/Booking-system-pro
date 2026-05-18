<?php

declare(strict_types=1);

namespace BSPModule\Core\Services;

use BSPModule\Core\Rest\RestService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function apply_filters;
use function gmdate;
use function get_post_meta;
use function count;
use function in_array;
use function is_array;
use function preg_match;
use function sprintf;
use function substr;
use function trim;

final class BookingTruthRuntimeService
{
    public const BOOKING_CAPABILITY_DIRECT = 'DIRECT_ELIGIBLE';
    public const BOOKING_CAPABILITY_REQUEST = 'REQUEST_ONLY';
    public const CAPABILITY_STATUS_DIRECT = 'DIRECT';
    public const CAPABILITY_STATUS_DIRECT_LIMITED = 'DIRECT_LIMITED';
    public const CAPABILITY_STATUS_REQUEST = 'REQUEST';
    public const CAPABILITY_STATUS_UNAVAILABLE = 'UNAVAILABLE';
    public const ROUTE_INTENT_CHECKOUT = 'checkout';
    public const ROUTE_INTENT_QUOTE = 'quote';
    public const ROUTE_INTENT_BLOCKED = 'blocked';

    /**
     * @return array{
     *   product_id:int,
     *   resource_id:int,
     *   date:string,
     *   participants:int,
     *   slots:array<int, array<string, mixed>>,
     *   capacity:int,
     *   resource_valid:bool,
     *   selected_time_valid:bool,
     *   execution_ok:bool,
     *   lookup_error:bool,
     *   reason_code:?string,
     *   execution_error_code:?string
     * }
     */
    public function resolveSlotAvailability(
        int $productId,
        string $date,
        int $participants,
        int $resourceId,
        string $startIso = '',
        string $endIso = ''
    ): array {
        $result = array(
            'product_id'           => $productId,
            'resource_id'          => $resourceId,
            'date'                 => $date,
            'participants'         => max(1, $participants),
            'slots'                => array(),
            'capacity'             => 0,
            'resource_valid'       => true,
            'selected_time_valid'  => false,
            'execution_ok'         => false,
            'lookup_error'         => false,
            'reason_code'          => null,
            'execution_error_code' => null,
        );

        if ($productId <= 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $result['lookup_error'] = true;
            $result['reason_code'] = 'invalid_request';

            return $result;
        }

        $request = new WP_REST_Request('GET');
        $request->set_param('product_id', $productId);
        $request->set_param('resource_id', $resourceId);
        $request->set_param('date', $date);
        $request->set_param('participants', max(1, $participants));

        $payload = function_exists('apply_filters')
            ? apply_filters(
                'sbdp_planservice_availability_slots_payload',
                null,
                array(
                    'product_id'   => $productId,
                    'resource_id'  => $resourceId,
                    'date'         => $date,
                    'participants' => max(1, $participants),
                    'start'        => $startIso,
                    'end'          => $endIso,
                )
            )
            : null;
        if ($payload === null) {
            $payload = RestService::availability_slots($request);
        }

        if ($payload instanceof WP_Error) {
            $result['lookup_error'] = true;
            $result['reason_code'] = 'availability_lookup_failed';

            return $result;
        }
        if ($payload instanceof WP_REST_Response) {
            $payload = $payload->get_data();
        }
        if (! is_array($payload)) {
            $result['lookup_error'] = true;
            $result['reason_code'] = 'availability_lookup_failed';

            return $result;
        }

        $result['slots'] = isset($payload['slots']) && is_array($payload['slots']) ? array_values($payload['slots']) : array();
        $result['capacity'] = (int) ($payload['capacity'] ?? 0);
        if (array_key_exists('resource_valid', $payload)) {
            $result['resource_valid'] = (bool) $payload['resource_valid'];
        }

        $startTime = $this->extractTimePart($startIso);
        $endTime = $this->extractTimePart($endIso);
        if ($startTime === '' || $endTime === '') {
            $result['reason_code'] = 'missing_time_window';

            return $result;
        }

        $startMinutes = $this->timeToMinutes($startTime);
        $endMinutes = $this->timeToMinutes($endTime);
        if ($startMinutes === null || $endMinutes === null || $endMinutes <= $startMinutes) {
            $result['reason_code'] = 'missing_time_window';

            return $result;
        }

        $slots = $result['slots'];
        if ($slots !== array()) {
            if ($this->isSelectionCoveredByExplicitSlot($slots, $startMinutes, $endMinutes)) {
                $result['selected_time_valid'] = true;
            } else {
                $slotLength = $this->resolveSlotLengthMinutes($slots);
                if ($slotLength <= 0) {
                    foreach ($slots as $slot) {
                        $slotStart = isset($slot['start']) ? $this->timeToMinutes((string) $slot['start']) : null;
                        if ($slotStart !== null && $slotStart === $startMinutes) {
                            $result['selected_time_valid'] = true;
                            break;
                        }
                    }
                } else {
                    $required = max(1, (int) ceil(($endMinutes - $startMinutes) / $slotLength));
                    $startSet = array();
                    foreach ($slots as $slot) {
                        if (! isset($slot['start'])) {
                            continue;
                        }
                        $slotStart = $this->timeToMinutes((string) $slot['start']);
                        if ($slotStart !== null) {
                            $startSet[$slotStart] = true;
                        }
                    }

                    $result['selected_time_valid'] = true;
                    for ($i = 0; $i < $required; $i++) {
                        $candidate = $startMinutes + ($i * $slotLength);
                        if (! isset($startSet[$candidate])) {
                            $result['selected_time_valid'] = false;
                            break;
                        }
                    }
                }
            }
        }

        $execution = function_exists('apply_filters')
            ? apply_filters(
                'sbdp_planservice_execution_check',
                null,
                array(
                    'product_id'   => $productId,
                    'resource_id'  => $resourceId,
                    'start'        => $startIso,
                    'end'          => $endIso,
                    'participants' => max(1, $participants),
                )
            )
            : null;
        if ($execution === null) {
            $execution = AvailabilityExecutionService::checkItemRules(
                $productId,
                $resourceId,
                $startIso,
                $endIso,
                max(1, $participants)
            );
        }

        if ($execution instanceof WP_Error) {
            $executionCode = $execution->get_error_code();
            $result['execution_ok'] = false;
            $result['execution_error_code'] = is_string($executionCode) && $executionCode !== '' ? $executionCode : null;
            $result['reason_code'] = match ($executionCode) {
                'sbdp_capacity' => 'capacity_exceeded',
                'sbdp_conflict' => 'time_unavailable',
                default => 'availability_rejected',
            };

            return $result;
        }

        $result['execution_ok'] = true;
        if (! $result['selected_time_valid']) {
            $result['reason_code'] = 'selected_time_invalid';

            return $result;
        }

        $result['reason_code'] = null;

        return $result;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $context
     * @return array{status:string,route_intent:string,reason_code:?string,legacy_status:string}
     */
    public function resolveBookingCapabilityProfile(array $item, array $context = array()): array
    {
        $explicit = $this->normalizeBookingCapability(
            $context['explicit_capability']
                ?? $item['bookingCapability']
                ?? $item['booking_capability']
                ?? null
        );
        $bookingResolution = isset($item['bookingResolution']) && is_array($item['bookingResolution'])
            ? $item['bookingResolution']
            : array();
        $requiresConfirmation = false;
        foreach (
            array(
                $context['requires_confirmation'] ?? null,
                $item['requires_confirmation'] ?? null,
                $item['requiresConfirmation'] ?? null,
                $bookingResolution['requires_confirmation'] ?? null,
                $bookingResolution['requiresConfirmation'] ?? null,
            ) as $candidate
        ) {
            if ($candidate === true) {
                $requiresConfirmation = true;
                break;
            }
            if (is_string($candidate) || is_int($candidate) || is_float($candidate)) {
                $parsed = filter_var($candidate, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed === true) {
                    $requiresConfirmation = true;
                    break;
                }
            }
        }

        $participants = max(1, (int) ($item['participants'] ?? 1));
        $start = trim((string) ($item['start'] ?? ''));
        $end = trim((string) ($item['end'] ?? ''));
        if ($start === '' || $end === '') {
            return $this->buildCapabilityProfile(self::CAPABILITY_STATUS_UNAVAILABLE, 'missing_time_window');
        }

        $date = trim((string) ($item['date'] ?? ''));
        if ($date === '' && strlen($start) >= 10) {
            $date = substr($start, 0, 10);
        }

        $slotAvailability = $this->resolveSlotAvailability(
            (int) ($item['product_id'] ?? 0),
            $date,
            $participants,
            (int) ($item['resource_id'] ?? 0),
            $start,
            $end
        );

        if (! empty($slotAvailability['lookup_error'])) {
            return $this->buildCapabilityProfile(self::CAPABILITY_STATUS_UNAVAILABLE, 'availability_lookup_failed');
        }

        if (isset($slotAvailability['resource_valid']) && $slotAvailability['resource_valid'] === false) {
            return $this->buildCapabilityProfile(self::CAPABILITY_STATUS_UNAVAILABLE, 'invalid_resource');
        }

        if (empty($slotAvailability['selected_time_valid'])) {
            return $this->buildCapabilityProfile(
                self::CAPABILITY_STATUS_REQUEST,
                (string) ($slotAvailability['reason_code'] ?? 'selected_time_invalid')
            );
        }

        if (empty($slotAvailability['execution_ok'])) {
            return $this->buildCapabilityProfile(
                self::CAPABILITY_STATUS_UNAVAILABLE,
                (string) ($slotAvailability['reason_code'] ?? 'availability_rejected')
            );
        }

        $bookingResolutionStatus = isset($context['booking_resolution_status']) && is_string($context['booking_resolution_status'])
            ? strtolower(trim($context['booking_resolution_status']))
            : '';
        if (in_array($bookingResolutionStatus, array('invalid', 'error', 'needs_choice'), true)) {
            return $this->buildCapabilityProfile(self::CAPABILITY_STATUS_REQUEST, 'booking_resolution_incomplete');
        }

        if ($bookingResolutionStatus === 'partial' && $requiresConfirmation) {
            return $this->buildCapabilityProfile(self::CAPABILITY_STATUS_REQUEST, 'booking_resolution_incomplete');
        }

        if ($explicit !== null) {
            return $this->buildCapabilityProfile($explicit, 'explicit_capability');
        }

        $productId = (int) ($item['product_id'] ?? 0);
        if ($productId > 0 && $this->productRequiresConfirmation($productId)) {
            return $this->buildCapabilityProfile(self::CAPABILITY_STATUS_REQUEST, 'requires_confirmation');
        }

        return $this->buildCapabilityProfile(self::CAPABILITY_STATUS_DIRECT);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function buildCanonicalMeta(array $item, array $profile): array
    {
        $participants = max(1, (int) ($item['participants'] ?? 1));

        return array(
            'sbdp_canonical_participants' => $participants,
            'sbdp_participants'           => $participants,
            'sbdp_start'                  => (string) ($item['start'] ?? ''),
            'sbdp_end'                    => (string) ($item['end'] ?? ''),
            'sbdp_resource_id'            => (int) ($item['resource_id'] ?? 0),
            'sbdp_route_intent'           => (string) ($profile['route_intent'] ?? self::ROUTE_INTENT_BLOCKED),
            'sbdp_booking_capability'     => (string) ($profile['status'] ?? self::CAPABILITY_STATUS_UNAVAILABLE),
        );
    }

    /**
     * @return array{status:string,route_intent:string,reason_code:?string,legacy_status:string}
     */
    public function buildCapabilityProfile(string $status, ?string $reasonCode = null): array
    {
        return array(
            'status'         => $status,
            'route_intent'   => $this->mapCapabilityStatusToRouteIntent($status),
            'reason_code'    => $reasonCode,
            'legacy_status'  => $this->mapCapabilityStatusToLegacyStatus($status),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function resolveBookingWriteContext(array $payload, array $context = array()): array
    {
        $participants = max(1, (int) ($payload['participants'] ?? 1));
        $date = trim((string) ($payload['date'] ?? ''));
        $time = trim((string) ($payload['time'] ?? ''));
        $dateEnd = trim((string) ($payload['date_end'] ?? $date));
        $timeEnd = trim((string) ($payload['time_end'] ?? $time));
        $start = trim((string) ($context['start'] ?? $this->composeIso($date, $time)));
        $end = trim((string) ($context['end'] ?? $this->composeIso($dateEnd, $timeEnd)));
        $defaultResourceId = max(0, (int) ($context['resource_id'] ?? $payload['resource_id'] ?? 0));
        $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : array();
        $resolvedItems = array();
        $aggregateStatus = self::CAPABILITY_STATUS_DIRECT;
        $aggregateReason = null;
        $aggregateResourceId = $defaultResourceId;

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemResourceId = isset($item['resource_id']) && (int) $item['resource_id'] > 0
                ? (int) $item['resource_id']
                : $defaultResourceId;
            if ($aggregateResourceId <= 0 && $itemResourceId > 0) {
                $aggregateResourceId = $itemResourceId;
            }

            $canonicalItem = array(
                'product_id'   => (int) ($item['product_id'] ?? 0),
                'resource_id'  => $itemResourceId,
                'participants' => $participants,
                'date'         => $date,
                'start'        => $start,
                'end'          => $end,
            );
            $profile = $this->resolveBookingCapabilityProfile(
                $canonicalItem,
                array(
                    'explicit_capability' => $item['booking_capability'] ?? null,
                )
            );
            $status = (string) ($profile['status'] ?? self::CAPABILITY_STATUS_UNAVAILABLE);
            if ($status === self::CAPABILITY_STATUS_UNAVAILABLE) {
                $aggregateStatus = self::CAPABILITY_STATUS_UNAVAILABLE;
                $aggregateReason = (string) ($profile['reason_code'] ?? 'availability_rejected');
            } elseif ($aggregateStatus !== self::CAPABILITY_STATUS_UNAVAILABLE && $status === self::CAPABILITY_STATUS_REQUEST) {
                $aggregateStatus = self::CAPABILITY_STATUS_REQUEST;
                $aggregateReason = (string) ($profile['reason_code'] ?? 'request_only_item_present');
            } elseif (
                $aggregateStatus === self::CAPABILITY_STATUS_DIRECT
                && $status === self::CAPABILITY_STATUS_DIRECT_LIMITED
            ) {
                $aggregateStatus = self::CAPABILITY_STATUS_DIRECT_LIMITED;
                $aggregateReason = (string) ($profile['reason_code'] ?? 'direct_with_limits');
            }

            $resolvedItems[] = array(
                'index'                 => $index,
                'product_id'            => $canonicalItem['product_id'],
                'resource_id'           => $canonicalItem['resource_id'],
                'participants'          => $participants,
                'start'                 => $start,
                'end'                   => $end,
                'route_intent'          => (string) ($profile['route_intent'] ?? self::ROUTE_INTENT_BLOCKED),
                'booking_capability'    => (string) ($profile['status'] ?? self::CAPABILITY_STATUS_UNAVAILABLE),
                'legacy_status'         => (string) ($profile['legacy_status'] ?? self::BOOKING_CAPABILITY_REQUEST),
                'reason_code'           => $profile['reason_code'] ?? null,
                'canonical_meta'        => $this->buildCanonicalMeta($canonicalItem, $profile),
            );
        }

        $aggregateProfile = $this->buildCapabilityProfile($aggregateStatus, $aggregateReason);

        return array(
            'validated_by'         => 'booking_truth_runtime',
            'validation_source'    => (string) ($context['validation_source'] ?? 'booking_manager'),
            'validated_at'         => gmdate('c'),
            'participants'         => $participants,
            'start'                => $start,
            'end'                  => $end,
            'resource_id'          => $aggregateResourceId,
            'route_intent'         => (string) ($aggregateProfile['route_intent'] ?? self::ROUTE_INTENT_BLOCKED),
            'booking_capability'   => (string) ($aggregateProfile['status'] ?? self::CAPABILITY_STATUS_UNAVAILABLE),
            'legacy_status'        => (string) ($aggregateProfile['legacy_status'] ?? self::BOOKING_CAPABILITY_REQUEST),
            'reason_code'          => $aggregateProfile['reason_code'] ?? null,
            'items'                => array_values($resolvedItems),
            'item_count'           => count($resolvedItems),
        );
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $provided
     */
    public function writeContextMatches(array $expected, array $provided): bool
    {
        $fields = array('participants', 'start', 'end', 'resource_id', 'route_intent', 'booking_capability');
        foreach ($fields as $field) {
            if ((string) ($expected[$field] ?? '') !== (string) ($provided[$field] ?? '')) {
                return false;
            }
        }

        $expectedItems = isset($expected['items']) && is_array($expected['items']) ? array_values($expected['items']) : array();
        $providedItems = isset($provided['items']) && is_array($provided['items']) ? array_values($provided['items']) : array();
        if (count($expectedItems) !== count($providedItems)) {
            return false;
        }

        foreach ($expectedItems as $index => $expectedItem) {
            $providedItem = $providedItems[$index] ?? array();
            foreach (array('product_id', 'resource_id', 'participants', 'start', 'end', 'route_intent', 'booking_capability') as $field) {
                if ((string) ($expectedItem[$field] ?? '') !== (string) ($providedItem[$field] ?? '')) {
                    return false;
                }
            }
        }

        return true;
    }

    private function composeIso(string $date, string $time): string
    {
        $date = trim($date);
        $time = trim($time);
        if ($date === '' || $time === '') {
            return '';
        }

        if (substr($time, -3) === ':00' && preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) === 1) {
            return sprintf('%sT%s', $date, $time);
        }

        return sprintf('%sT%s:00', $date, $time);
    }

    private function normalizeBookingCapability($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, array('direct', 'direct_eligible', 'direct-eligible', 'book', 'checkout'), true)) {
            return self::CAPABILITY_STATUS_DIRECT;
        }

        if (in_array($normalized, array('direct_limited', 'direct-limited', 'limited_direct', 'limited-direct'), true)) {
            return self::CAPABILITY_STATUS_DIRECT_LIMITED;
        }

        if (in_array($normalized, array('request', 'request_only', 'request-only', 'quote', 'quote_only', 'quote-only'), true)) {
            return self::CAPABILITY_STATUS_REQUEST;
        }

        if (in_array($normalized, array('unavailable', 'blocked', 'closed', 'none'), true)) {
            return self::CAPABILITY_STATUS_UNAVAILABLE;
        }

        return null;
    }

    private function mapCapabilityStatusToRouteIntent(string $status): string
    {
        if ($status === self::CAPABILITY_STATUS_DIRECT || $status === self::CAPABILITY_STATUS_DIRECT_LIMITED) {
            return self::ROUTE_INTENT_CHECKOUT;
        }

        if ($status === self::CAPABILITY_STATUS_REQUEST) {
            return self::ROUTE_INTENT_QUOTE;
        }

        return self::ROUTE_INTENT_BLOCKED;
    }

    private function mapCapabilityStatusToLegacyStatus(string $status): string
    {
        if ($status === self::CAPABILITY_STATUS_DIRECT || $status === self::CAPABILITY_STATUS_DIRECT_LIMITED) {
            return self::BOOKING_CAPABILITY_DIRECT;
        }

        return self::BOOKING_CAPABILITY_REQUEST;
    }

    private function productRequiresConfirmation(int $productId): bool
    {
        $wcFlag = get_post_meta($productId, '_wc_booking_requires_confirmation', true);
        if ($wcFlag === 'yes' || $wcFlag === '1' || $wcFlag === 1 || $wcFlag === true) {
            return true;
        }

        $bookable = get_post_meta($productId, '_sbdp_bookable', true);
        if (is_array($bookable)) {
            $flag = $bookable['booking_requires_confirmation'] ?? null;
            if ($flag === 'yes' || $flag === '1' || $flag === 1 || $flag === true) {
                return true;
            }
        }

        return false;
    }

    private function extractTimePart(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/\d{2}:\d{2}/', $value, $matches)) {
            return $matches[0];
        }

        return '';
    }

    private function resolveSlotLengthMinutes(array $slots): int
    {
        $first = $slots[0] ?? array();
        $start = isset($first['start']) ? $this->timeToMinutes((string) $first['start']) : null;
        $end = isset($first['end']) ? $this->timeToMinutes((string) $first['end']) : null;
        if ($start === null || $end === null) {
            return 0;
        }

        return max(0, $end - $start);
    }

    private function timeToMinutes(string $time): ?int
    {
        if (! preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }

    private function isSelectionCoveredByExplicitSlot(array $slots, int $startMinutes, int $endMinutes): bool
    {
        foreach ($slots as $slot) {
            if (! isset($slot['start'], $slot['end'])) {
                continue;
            }

            $slotStart = $this->timeToMinutes((string) $slot['start']);
            $slotEnd = $this->timeToMinutes((string) $slot['end']);
            if ($slotStart === null || $slotEnd === null || $slotEnd <= $slotStart) {
                continue;
            }

            if ($startMinutes >= $slotStart && $endMinutes <= $slotEnd) {
                return true;
            }
        }

        return false;
    }
}
