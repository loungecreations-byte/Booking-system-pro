<?php

declare(strict_types=1);

namespace BSPModule\Core\Services;

use BSP\Sales\Vendors\VendorService;
use WP_Error;

use function __;
use function get_post_meta;
use function in_array;
use function is_array;
use function sanitize_text_field;
use function update_post_meta;

/**
 * Canonical write path for resource-managed schedule inputs.
 *
 * This stores manual blocks and tour states for a resource, but it is not
 * execution truth for occupancy.
 */
final class ResourceScheduleService
{
    public static function getVendorSchedule(int $vendorId)
    {
        $vendor = VendorService::get($vendorId);
        if ($vendor === null) {
            return new WP_Error('bsp_sales_vendor_missing', __('Vendor not found.', 'sbdp'), ['status' => 404]);
        }

        return [
            'vendor_id' => $vendorId,
            'resources' => VendorService::getResources($vendorId),
        ];
    }

    /**
     * @param array<int, mixed> $availability
     * @param array<int, mixed> $tours
     */
    public static function updateVendorSchedule(int $vendorId, int $resourceId, array $availability = [], array $tours = [])
    {
        $vendor = VendorService::get($vendorId);
        if ($vendor === null) {
            return new WP_Error('bsp_sales_vendor_missing', __('Vendor not found.', 'sbdp'), ['status' => 404]);
        }

        if ($resourceId <= 0) {
            return new WP_Error('bsp_sales_schedule_missing_resource', __('Resource is required.', 'sbdp'), ['status' => 400]);
        }

        $linkedVendor = (int) get_post_meta($resourceId, '_sbdp_resource_vendor', true);
        if ($linkedVendor !== $vendorId) {
            return new WP_Error('bsp_sales_schedule_forbidden', __('You cannot manage this resource for the selected vendor.', 'sbdp'), ['status' => 403]);
        }

        $cleanAvailability = self::sanitizeAvailability($availability);
        $cleanTours        = self::sanitizeTours($tours);

        if ($cleanAvailability !== []) {
            update_post_meta($resourceId, '_sbdp_resource_availability', $cleanAvailability);
        }

        if ($cleanTours !== []) {
            update_post_meta($resourceId, '_sbdp_resource_tours', $cleanTours);
        }

        return [
            'vendor_id' => $vendorId,
            'resources' => VendorService::getResources($vendorId),
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, string>>
     */
    public static function sanitizeAvailability(array $items): array
    {
        $sanitized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $start = isset($item['start']) ? sanitize_text_field((string) $item['start']) : '';
            $end   = isset($item['end']) ? sanitize_text_field((string) $item['end']) : '';
            if ($start === '' || $end === '') {
                continue;
            }

            $sanitized[] = [
                'start' => $start,
                'end'   => $end,
                'notes' => isset($item['notes']) ? sanitize_text_field((string) $item['notes']) : '',
            ];
        }

        return $sanitized;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, string>>
     */
    public static function sanitizeTours(array $items): array
    {
        $sanitized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $date = isset($item['date']) ? sanitize_text_field((string) $item['date']) : '';
            if ($date === '') {
                continue;
            }

            $status = isset($item['status']) ? sanitize_text_field((string) $item['status']) : VendorService::TOUR_STATUS_UPCOMING;
            if (! in_array($status, VendorService::TOUR_STATUSES, true)) {
                $status = VendorService::TOUR_STATUS_UPCOMING;
            }

            $sanitized[] = [
                'date'   => $date,
                'status' => $status,
                'notes'  => isset($item['notes']) ? sanitize_text_field((string) $item['notes']) : '',
            ];
        }

        return $sanitized;
    }
}
