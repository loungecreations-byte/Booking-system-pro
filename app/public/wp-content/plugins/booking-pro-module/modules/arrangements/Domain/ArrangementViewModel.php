<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Domain;

use function array_values;
use function count;
use function is_array;
use function is_string;
use function sanitize_text_field;
use function sanitize_title;

final class ArrangementViewModel
{
    /**
     * @param array<string, mixed> $arrangement
     * @param array<string, mixed> $pricing
     * @param array<string, mixed> $availability
     * @return array<string, mixed>
     */
    public static function forPlanner(array $arrangement, array $pricing = array(), array $availability = array()): array
    {
        $segments = self::segmentsToCombiItems(is_array($arrangement['segments'] ?? null) ? $arrangement['segments'] : array());
        $salesProductId = (int) ($arrangement['sales_product_id'] ?? 0);
        $title = sanitize_text_field((string) ($arrangement['title'] ?? 'Arrangement'));
        $slug = sanitize_title((string) ($arrangement['slug'] ?? $title));

        return array(
            'id' => $salesProductId > 0 ? $salesProductId : (int) ($arrangement['id'] ?? 0),
            'arrangement_id' => (int) ($arrangement['id'] ?? 0),
            'productId' => $salesProductId,
            'product_id' => $salesProductId,
            'name' => $title,
            'title' => $title,
            'slug' => $slug,
            'kind' => 'arrangement',
            'type' => 'arrangement',
            'source' => 'product-combi',
            'isArrangement' => true,
            'groupId' => 'arrangement-' . (int) ($arrangement['id'] ?? 0),
            'aggregateId' => 'arrangement-' . (int) ($arrangement['id'] ?? 0),
            'description' => (string) ($arrangement['description'] ?? ''),
            'excerpt' => (string) ($arrangement['excerpt'] ?? ''),
            'arrangement_type' => (string) ($arrangement['arrangement_type'] ?? 'fixed'),
            'creation_mode' => (string) ($arrangement['creation_mode'] ?? 'fixed'),
            'visibility' => (string) ($arrangement['visibility'] ?? 'public'),
            'price_strategy' => (string) ($arrangement['price_strategy'] ?? 'sum_children'),
            'can_add_to_cart' => $salesProductId > 0,
            'sort_order' => (int) ($arrangement['sort_order'] ?? 0),
            'image' => (string) ($arrangement['image'] ?? ''),
            'gallery' => is_array($arrangement['gallery'] ?? null) ? array_values($arrangement['gallery']) : array(),
            'featured' => ! empty($arrangement['featured']),
            'category_slugs' => is_array($arrangement['categories'] ?? null) ? array_values($arrangement['categories']) : array(),
            'tags' => is_array($arrangement['tags'] ?? null) ? array_values($arrangement['tags']) : array(),
            'segment_count' => count($segments),
            'duration' => array('minutes' => (int) ($arrangement['duration_total'] ?? 0)),
            'daypart' => (string) ($arrangement['daypart'] ?? ''),
            'pricing' => $pricing,
            'price_pp' => isset($pricing['unit_price']) ? (float) $pricing['unit_price'] : (float) ($arrangement['base_price'] ?? 0.0),
            'totalCost' => isset($pricing['total']) ? (float) $pricing['total'] : (float) ($arrangement['base_price'] ?? 0.0),
            'participants' => 1,
            'resource_id' => 0,
            'resourceId' => 0,
            'combiItems' => $segments,
            'options' => array('combiItems' => $segments),
            'segments' => $segments,
            'bookingResolution' => is_array($availability['bookingResolution'] ?? null) ? $availability['bookingResolution'] : array(),
            'availability' => $availability,
            'status' => (string) ($availability['status'] ?? 'available'),
            'labels' => array(
                'parent' => 'Arrangement',
                'child' => 'Onderdeel',
                'fixed' => 'Vast',
                'flexible' => 'Flexibel',
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     * @return array<int, array<string, mixed>>
     */
    public static function segmentsToCombiItems(array $segments): array
    {
        $combiItems = array();
        foreach (array_values($segments) as $index => $segment) {
            if (! is_array($segment) || ! empty($segment['is_hidden'])) {
                continue;
            }

            $label = sanitize_text_field((string) ($segment['title_override'] ?? $segment['ui_label'] ?? ''));
            if ($label === '') {
                $label = 'Onderdeel ' . ($index + 1);
            }

            $role = self::resolveSegmentRole($segment, $index);
            $timing = self::mapRoleToTiming($role, (string) ($segment['timing_mode'] ?? 'after_previous'));

            $combiItems[] = array(
                'id' => (int) ($segment['linked_product_id'] ?? 0),
                'label' => $label,
                'timing' => $timing,
                'role' => $role,
                'order' => (int) ($segment['sequence'] ?? $index),
                'duration' => (int) ($segment['max_duration'] ?? $segment['min_duration'] ?? 0),
                'durationMinutes' => (int) ($segment['max_duration'] ?? $segment['min_duration'] ?? 0),
                'segment_type' => (string) ($segment['segment_type'] ?? 'activity'),
                'required' => ! empty($segment['required']),
                'fixed_start_time' => (string) ($segment['fixed_start_time'] ?? ''),
                'fixed_end_time' => (string) ($segment['fixed_end_time'] ?? ''),
                'earliest_start' => (string) ($segment['earliest_start'] ?? ''),
                'latest_start' => (string) ($segment['latest_start'] ?? ''),
                'buffer_before' => (int) ($segment['buffer_before'] ?? 0),
                'buffer_after' => (int) ($segment['buffer_after'] ?? 0),
                'travel_buffer' => (int) ($segment['travel_buffer'] ?? 0),
                'availability_source' => (string) ($segment['availability_source'] ?? 'derived'),
                'pricing_source' => (string) ($segment['pricing_source'] ?? 'derived'),
                'notes' => is_string($segment['notes'] ?? null) ? (string) $segment['notes'] : '',
                'is_optional' => ! empty($segment['is_optional']),
                'is_replaceable' => ! empty($segment['is_replaceable']),
            );
        }

        usort(
            $combiItems,
            static fn (array $left, array $right): int => ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0))
        );

        return array_values($combiItems);
    }

    private static function mapTimingMode(string $timingMode): string
    {
        return in_array($timingMode, array('fixed_start', 'before_next'), true) ? 'before' : 'after';
    }

    /**
     * @param array<string, mixed> $segment
     */
    private static function resolveSegmentRole(array $segment, int $index): string
    {
        $explicitRole = sanitize_text_field((string) ($segment['role'] ?? ''));
        if (in_array($explicitRole, array('anchor', 'pre', 'post'), true)) {
            return $explicitRole;
        }

        if ((string) ($segment['segment_type'] ?? '') === 'reception' || $index === 0) {
            return 'anchor';
        }

        return (string) ($segment['timing_mode'] ?? '') === 'before_next' ? 'pre' : 'post';
    }

    private static function mapRoleToTiming(string $role, string $timingMode): string
    {
        if ($role === 'anchor') {
            return 'anchor';
        }
        if ($role === 'pre') {
            return 'before';
        }

        return self::mapTimingMode($timingMode);
    }
}
