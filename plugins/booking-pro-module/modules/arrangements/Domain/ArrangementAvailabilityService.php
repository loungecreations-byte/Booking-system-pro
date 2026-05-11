<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Domain;

use BSPModule\Core\Services\AvailabilityProjectionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function absint;
use function array_map;
use function array_values;
use function class_exists;
use function count;
use function function_exists;
use function is_array;
use function is_string;
use function max;
use function preg_match;
use function sanitize_text_field;
use function sprintf;

final class ArrangementAvailabilityService
{
    /**
     * @param array<string, mixed> $arrangement
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function resolve(array $arrangement, array $context = array()): array
    {
        $segments = is_array($arrangement['segments'] ?? null) ? array_values($arrangement['segments']) : array();
        $start = is_string($context['start'] ?? null) ? trim((string) $context['start']) : '';
        $date = is_string($context['date'] ?? null) ? trim((string) $context['date']) : '';
        $participants = max(1, (int) ($context['participants'] ?? 1));

        $resolvedSegments = array();
        $conflicts = array();
        $cursorMinutes = $start !== '' ? $this->timeToMinutes($start) : null;
        $previousEnd = null;

        foreach ($segments as $index => $segment) {
            if (! is_array($segment) || ! empty($segment['is_hidden'])) {
                continue;
            }

            $duration = max(0, (int) ($segment['max_duration'] ?? $segment['min_duration'] ?? 0));
            $fixedStart = $this->sanitizeTime((string) ($segment['fixed_start_time'] ?? ''));
            $segmentStart = $fixedStart !== '' ? $fixedStart : ($cursorMinutes !== null ? $this->minutesToTime($cursorMinutes) : '');
            $segmentEnd = $segmentStart !== '' && $duration > 0 ? $this->minutesToTime($this->timeToMinutes($segmentStart) + $duration) : '';
            $availability = $this->resolveSegmentAvailability($segment, $date, $participants, $segmentStart);

            if (($availability['available'] ?? false) !== true) {
                $conflicts[] = array(
                    'segment_id' => (string) ($segment['id'] ?? $index),
                    'reason' => (string) ($availability['reason'] ?? 'unavailable'),
                );
            }

            $resolvedSegments[] = array_merge(
                $segment,
                array(
                    'resolved_start' => $segmentStart,
                    'resolved_end' => $segmentEnd,
                    'availability' => $availability,
                )
            );

            if ($segmentEnd !== '') {
                $cursorMinutes = $this->timeToMinutes($segmentEnd);
                $previousEnd = $segmentEnd;
            }
        }

        $status = 'available';
        if ($conflicts !== array()) {
            $status = count($conflicts) === count($resolvedSegments) ? 'unavailable' : 'partial';
        }

        return array(
            'status' => $status,
            'available' => $status === 'available',
            'date' => $date,
            'start' => $start,
            'participants' => $participants,
            'segments' => $resolvedSegments,
            'conflicts' => $conflicts,
            'timeline' => array(
                'startTime' => $start,
                'endTime' => $previousEnd,
            ),
            'bookingResolution' => array(
                'status' => $status === 'available' ? 'valid' : ($status === 'partial' ? 'partial' : 'invalid'),
                'warnings' => array(),
                'errors' => array_map(static fn (array $row): string => (string) ($row['reason'] ?? 'unavailable'), $conflicts),
                'segments' => $resolvedSegments,
            ),
        );
    }

    /**
     * @param array<string, mixed> $segment
     * @param string $date
     * @param int $participants
     * @param string $segmentStart
     * @return array<string, mixed>
     */
    private function resolveSegmentAvailability(array $segment, string $date, int $participants, string $segmentStart): array
    {
        $filtered = apply_filters('sbdp_arrangement_segment_availability', null, $segment, $date, $participants, $segmentStart);
        if (is_array($filtered)) {
            return $filtered;
        }

        $productId = (int) ($segment['linked_product_id'] ?? 0);
        if (
            $productId <= 0
            || ! class_exists(AvailabilityProjectionService::class)
            || ! class_exists('WP_REST_Request')
            || ! class_exists('WP_REST_Response')
            || ! class_exists('WP_Error')
            || ! function_exists('wc_get_product')
        ) {
            return array(
                'available' => true,
                'reason' => 'derived',
            );
        }

        $request = new WP_REST_Request('GET');
        $request->set_param('product_id', $productId);
        $request->set_param('date', $date);
        $resourceId = absint((int) ($segment['linked_resource_id'] ?? 0));
        if ($resourceId > 0) {
            $request->set_param('resource_id', $resourceId);
        }

        $response = AvailabilityProjectionService::availabilitySlots($request);
        if ($response instanceof WP_Error) {
            return array('available' => false, 'reason' => $response->get_error_code());
        }
        if ($response instanceof WP_REST_Response) {
            $response = $response->get_data();
        }

        if (! is_array($response)) {
            return array('available' => false, 'reason' => 'availability_unavailable');
        }

        $slots = is_array($response['slots'] ?? null) ? $response['slots'] : array();
        if ($slots === array()) {
            return array('available' => true, 'reason' => 'no_slot_constraints');
        }

        foreach ($slots as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $slotStart = sanitize_text_field((string) ($slot['start'] ?? ''));
            if ($segmentStart !== '' && $slotStart === $segmentStart) {
                return array('available' => true, 'reason' => 'matched_slot');
            }
        }

        return array('available' => false, 'reason' => 'slot_not_available');
    }

    private function sanitizeTime(string $value): string
    {
        return preg_match('/^\d{2}:\d{2}$/', $value) === 1 ? $value : '';
    }

    private function timeToMinutes(string $value): ?int
    {
        if ($this->sanitizeTime($value) === '') {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $value));
        return ($hours * 60) + $minutes;
    }

    private function minutesToTime(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return sprintf('%02d:%02d', $hours, $mins);
    }
}
