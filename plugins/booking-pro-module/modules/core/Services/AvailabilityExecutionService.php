<?php

declare(strict_types=1);

namespace BSPModule\Core\Services;

use DateTimeImmutable;
use Exception;
use WP_Error;

use function __;
use function apply_filters;
use function get_post_meta;
use function max;
use function strtotime;
use function wp_list_pluck;

final class AvailabilityExecutionService
{
    public static function checkItemRules(int $productId, int $resourceId, string $start, string $end, int $participants)
    {
        $startDt = self::getLocalDatetime($start);
        $endDt   = self::getLocalDatetime($end);

        if (! $startDt || ! $endDt) {
            return new WP_Error('sbdp_bad_time', __('Ongeldige datum of tijd ontvangen.', 'sbdp'), ['status' => 400]);
        }

        $date = $startDt->format('Y-m-d');

        $rulesKey = $resourceId ? "_sbdp_av_rules_res_{$resourceId}" : '_sbdp_av_rules';
        $rules    = get_post_meta($productId, $rulesKey, true);

        $blocks = AvailabilityProjectionService::blocksForDate($date, $rules);
        foreach ($blocks as $block) {
            $blockStart = (string) ($block['start'] ?? '');
            $blockEnd   = (string) ($block['end'] ?? '');
            if (self::rangesOverlap($start, $end, $blockStart, $blockEnd)) {
                return new WP_Error('sbdp_conflict', __('De geselecteerde tijd is niet beschikbaar.', 'sbdp'), ['status' => 400]);
            }
        }

        $capKey   = $resourceId ? "_sbdp_capacity_res_{$resourceId}" : '_sbdp_capacity_default';
        $capacity = (int) get_post_meta($productId, $capKey, true);
        if ($capacity < 0) {
            $capacity = 0;
        }

        $conflicts = self::findOverlappingBookings($productId, $resourceId, $start, $end);
        $occupied  = 0;
        foreach ($conflicts as $existing) {
            $occupied += max(1, (int) ($existing['participants'] ?? 0));
        }

        if ($capacity > 0 && ($occupied + $participants) > $capacity) {
            return new WP_Error(
                'sbdp_capacity',
                __('Er zijn onvoldoende plaatsen beschikbaar voor dit tijdslot.', 'sbdp'),
                [
                    'status'    => 400,
                    'available' => max(0, $capacity - $occupied),
                    'conflicts' => wp_list_pluck($conflicts, 'order_id'),
                ]
            );
        }

        $allowParallel = apply_filters('sbdp_allow_parallel_bookings', false, $productId, $resourceId);
        if (! $allowParallel && ! empty($conflicts)) {
            return new WP_Error(
                'sbdp_conflict',
                __('De geselecteerde tijd is niet beschikbaar.', 'sbdp'),
                [
                    'status'    => 400,
                    'conflicts' => wp_list_pluck($conflicts, 'order_id'),
                ]
            );
        }

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findOverlappingBookings(int $productId, int $resourceId, string $start, string $end): array
    {
        global $wpdb;

        if (! isset($wpdb) || ! $wpdb) {
            return [];
        }

        $statusFilter = apply_filters('sbdp_booking_conflict_statuses', ['wc-processing', 'wc-on-hold', 'wc-completed', 'wc-pending']);
        if (empty($statusFilter)) {
            return [];
        }

        $day  = substr($start, 0, 10);
        $like = $day ? $wpdb->esc_like($day) . '%' : '%';

        $orderItemsTable    = $wpdb->prefix . 'woocommerce_order_items';
        $orderItemmetaTable = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $postsTable         = $wpdb->posts;
        $statusPlaceholders = implode(',', array_fill(0, count($statusFilter), '%s'));

        $sql = "SELECT o.ID AS order_id,
                       o.post_status,
                       start_meta.meta_value AS start_time,
                       end_meta.meta_value AS end_time,
                       participants_meta.meta_value AS participants,
                       COALESCE(resource_meta.meta_value, '') AS resource_id
                FROM {$orderItemsTable} AS oi
                INNER JOIN {$postsTable} AS o ON o.ID = oi.order_id
                LEFT JOIN {$orderItemmetaTable} AS product_meta ON product_meta.order_item_id = oi.order_item_id AND product_meta.meta_key = '_product_id'
                LEFT JOIN {$orderItemmetaTable} AS start_meta ON start_meta.order_item_id = oi.order_item_id AND start_meta.meta_key = 'sbdp_start'
                LEFT JOIN {$orderItemmetaTable} AS end_meta ON end_meta.order_item_id = oi.order_item_id AND end_meta.meta_key = 'sbdp_end'
                LEFT JOIN {$orderItemmetaTable} AS participants_meta ON participants_meta.order_item_id = oi.order_item_id AND participants_meta.meta_key = 'sbdp_participants'
                LEFT JOIN {$orderItemmetaTable} AS resource_meta ON resource_meta.order_item_id = oi.order_item_id AND resource_meta.meta_key = 'sbdp_resource_id'
                WHERE oi.order_item_type = 'line_item'
                  AND o.post_type = 'shop_order'
                  AND product_meta.meta_value = %d
                  AND o.post_status IN ( {$statusPlaceholders} )
                  AND start_meta.meta_value IS NOT NULL
                  AND end_meta.meta_value IS NOT NULL
                  AND start_meta.meta_value LIKE %s";

        $params = array_merge([$productId], $statusFilter, [$like]);
        if ($resourceId > 0) {
            $sql      .= ' AND ( resource_meta.meta_value = %s )';
            $params[] = (string) $resourceId;
        }

        $prepared = $wpdb->prepare($sql, $params);
        $rows     = $wpdb->get_results($prepared, ARRAY_A);

        if (empty($rows)) {
            return [];
        }

        $conflicts = [];

        foreach ($rows as $row) {
            $rowStart = $row['start_time'] ?? '';
            $rowEnd   = $row['end_time'] ?? '';
            if (! $rowStart || ! $rowEnd) {
                continue;
            }
            if (! self::rangesOverlap($start, $end, (string) $rowStart, (string) $rowEnd)) {
                continue;
            }

            $conflicts[] = [
                'order_id'     => (int) $row['order_id'],
                'status'       => (string) $row['post_status'],
                'start'        => $rowStart,
                'end'          => $rowEnd,
                'participants' => max(1, (int) $row['participants']),
                'resource_id'  => (int) (($row['resource_id'] ?? '') !== '' ? $row['resource_id'] : 0),
            ];
        }

        return $conflicts;
    }

    public static function rangesOverlap(string $start, string $end, string $blockStart, string $blockEnd): bool
    {
        $startTs      = strtotime($start);
        $endTs        = strtotime($end);
        $blockStartTs = strtotime($blockStart);
        $blockEndTs   = strtotime($blockEnd);

        if (! $startTs || ! $endTs || ! $blockStartTs || ! $blockEndTs) {
            return false;
        }

        return ($blockEndTs > $startTs) && ($blockStartTs < $endTs);
    }

    private static function getLocalDatetime(string $iso): ?DateTimeImmutable
    {
        try {
            $dt = new DateTimeImmutable($iso);
        } catch (Exception $e) {
            return null;
        }

        try {
            $timezone = wp_timezone();
            return $dt->setTimezone($timezone);
        } catch (Exception $e) {
            return $dt;
        }
    }
}
