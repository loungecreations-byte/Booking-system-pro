<?php

declare(strict_types=1);

namespace BSPModule\Core\Services;

use BSPModule\Core\Product\AvailabilityRules;
use BSPModule\Core\Product\ProductMeta;
use BSPModule\Core\Rest\RestService;
use SBDP\Core\ProductSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function get_post_meta;
use function in_array;
use function is_array;
use function sanitize_text_field;
use function strtotime;

/**
 * Read-side availability projection only.
 *
 * This service may shape UI-friendly slot payloads, but it must not become
 * participant-aware execution truth.
 */
final class AvailabilityProjectionService
{
    public static function availabilitySlots(WP_REST_Request $request)
    {
        $productId  = (int) $request->get_param('product_id');
        $resourceId = (int) $request->get_param('resource_id');
        $date       = sanitize_text_field((string) $request->get_param('date'));
        $participants = max(1, (int) $request->get_param('participants'));

        if (! $productId || ! $date) {
            return new WP_Error('bad_request', 'product_id & date required', ['status' => 400]);
        }

        $resourceIds = ProductMeta::get_resource_ids($productId);
        if ($resourceIds === []) {
            $primary = (int) get_post_meta($productId, '_sbdp_resource_id', true);
            if ($primary > 0) {
                $resourceIds[] = $primary;
            }
        }

        if (! $resourceId && $resourceIds !== []) {
            $resourceId = (int) $resourceIds[0];
        }

        $resourceValid = $resourceIds === []
            ? true
            : ($resourceId > 0 && in_array($resourceId, $resourceIds, true));
        if (($resourceIds !== [] && $resourceId <= 0) || ! $resourceValid) {
            $fallbackSlots = ProductSettings::slotsForDate($productId, $date);

            return [
                'product_id'            => $productId,
                'resource_id'           => $resourceId,
                'date'                  => $date,
                'requested_participants'=> $participants,
                'resource_valid'        => false,
                'slots'                 => $fallbackSlots,
                'capacity'              => 0,
                'blocks'                => [],
            ];
        }

        $availability = self::buildAvailabilityPayload($productId, $resourceId, $date);
        $blocks = isset($availability['blocks']) && is_array($availability['blocks']) ? $availability['blocks'] : [];

        $overviewRequest = new WP_REST_Request('GET');
        $overviewRequest->set_param('view', 'day');
        $overviewRequest->set_param('date', $date);
        $overview = function_exists('apply_filters')
            ? apply_filters(
                'sbdp_availability_projection_schedule_overview',
                null,
                array(
                    'date'        => $date,
                    'resource_id' => $resourceId,
                    'product_id'  => $productId,
                )
            )
            : null;
        if ($overview === null) {
            $overview = RestService::get_schedule_overview($overviewRequest);
        }
        $overviewData = $overview instanceof WP_REST_Response ? $overview->get_data() : (is_array($overview) ? $overview : []);

        $timeline = isset($overviewData['timeline']) && is_array($overviewData['timeline']) ? $overviewData['timeline'] : [];
        $resourceEntry = null;
        foreach ($timeline as $entry) {
            if (isset($entry['resource']['id']) && (int) $entry['resource']['id'] === $resourceId) {
                $resourceEntry = $entry;
                break;
            }
        }

        $slots = [];
        if ($resourceEntry && isset($resourceEntry['available_slots']) && is_array($resourceEntry['available_slots'])) {
            $slots = $resourceEntry['available_slots'];
        }

        if ($slots === []) {
            $slots = ProductSettings::slotsForDate($productId, $date);
        }

        if ($blocks !== [] && $slots !== []) {
            $filtered = [];
            foreach ($slots as $slot) {
                $startTime = isset($slot['start']) ? (string) $slot['start'] : '';
                $endTime   = isset($slot['end']) ? (string) $slot['end'] : '';
                if ($startTime === '' || $endTime === '') {
                    continue;
                }

                $slotStart = strtotime($date . 'T' . $startTime . ':00');
                $slotEnd   = strtotime($date . 'T' . $endTime . ':00');
                if (false === $slotStart || false === $slotEnd) {
                    continue;
                }

                $blocked = false;
                foreach ($blocks as $block) {
                    $blockStart = isset($block['start']) ? strtotime((string) $block['start']) : false;
                    $blockEnd   = isset($block['end']) ? strtotime((string) $block['end']) : false;
                    if (false === $blockStart || false === $blockEnd) {
                        continue;
                    }
                    if ($slotStart < $blockEnd && $slotEnd > $blockStart) {
                        $blocked = true;
                        break;
                    }
                }

                if (! $blocked) {
                    $filtered[] = $slot;
                }
            }
            $slots = $filtered;
        }

        if ($slots !== [] && $participants > 0) {
            $participantAware = [];
            foreach ($slots as $slot) {
                $startTime = isset($slot['start']) ? (string) $slot['start'] : '';
                $endTime   = isset($slot['end']) ? (string) $slot['end'] : '';
                if ($startTime === '' || $endTime === '') {
                    $participantAware[] = $slot;
                    continue;
                }

                $startIso = $date . 'T' . $startTime . ':00';
                $endIso   = $date . 'T' . $endTime . ':00';
                $execution = function_exists('apply_filters')
                    ? apply_filters(
                        'sbdp_availability_projection_execution_check',
                        null,
                        array(
                            'product_id'  => $productId,
                            'resource_id' => $resourceId,
                            'start'       => $startIso,
                            'end'         => $endIso,
                            'participants'=> $participants,
                        )
                    )
                    : null;
                if ($execution === null) {
                    $execution = AvailabilityExecutionService::checkItemRules(
                        $productId,
                        $resourceId,
                        $startIso,
                        $endIso,
                        $participants
                    );
                }

                if (! ($execution instanceof WP_Error)) {
                    $participantAware[] = $slot;
                }
            }

            $slots = $participantAware;
        }

        return [
            'product_id'            => $productId,
            'resource_id'           => $resourceId,
            'date'                  => $date,
            'requested_participants'=> $participants,
            'resource_valid'        => true,
            'slots'                 => $slots,
            'capacity'              => (int) ($availability['capacity'] ?? 0),
            'blocks'                => $blocks,
        ];
    }

    public static function buildAvailabilityPayload(int $productId, int $resourceId, string $date): array
    {
        $key   = $resourceId ? "_sbdp_av_rules_res_{$resourceId}" : '_sbdp_av_rules';
        $rules = get_post_meta($productId, $key, true);
        if (! is_array($rules)) {
            $rules = AvailabilityRules::defaultRules();
        }

        $blocks = self::blocksForDate($date, $rules);

        $capKey   = $resourceId ? "_sbdp_capacity_res_{$resourceId}" : '_sbdp_capacity_default';
        $capacity = (int) get_post_meta($productId, $capKey, true);
        if ($capacity < 0) {
            $capacity = 0;
        }

        return [
            'blocks'   => $blocks,
            'capacity' => $capacity,
        ];
    }

    /**
     * @param mixed $rules
     * @return array<int, array<string, mixed>>
     */
    public static function blocksForDate(string $date, $rules): array
    {
        $blocks = [];
        $start  = $date . 'T10:00:00';
        $end    = $date . 'T24:00:00';

        $default = $rules['default'] ?? 'open';
        if ('closed' === $default) {
            $blocks[] = [
                'start'   => $start,
                'end'     => $end,
                'display' => 'background',
                'color'   => '#fee2e2',
            ];
        }

        if (! empty($rules['exclude_weekdays'])) {
            $dow = (int) date('w', strtotime($date));
            if (in_array($dow, array_map('intval', $rules['exclude_weekdays']), true)) {
                $blocks[] = [
                    'start'   => $start,
                    'end'     => $end,
                    'display' => 'background',
                    'color'   => '#fecaca',
                ];
            }
        }

        if (! empty($rules['exclude_months'])) {
            $month = (int) date('n', strtotime($date));
            if (in_array($month, array_map('intval', $rules['exclude_months']), true)) {
                $blocks[] = [
                    'start'   => $start,
                    'end'     => $end,
                    'display' => 'background',
                    'color'   => '#fecaca',
                ];
            }
        }

        if (! empty($rules['exclude_times']) && is_array($rules['exclude_times'])) {
            foreach ($rules['exclude_times'] as $time) {
                $blocks[] = [
                    'start'   => $date . 'T' . sanitize_text_field((string) ($time['start'] ?? '00:00')) . ':00',
                    'end'     => $date . 'T' . sanitize_text_field((string) ($time['end'] ?? '00:00')) . ':00',
                    'display' => 'background',
                    'color'   => '#fecaca',
                ];
            }
        }

        return $blocks;
    }
}
