<?php

declare(strict_types=1);

namespace BSP\Sales\Vendors;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

use function absint;
use function add_action;
use function array_map;
use function count;
use function get_post_meta;
use function register_rest_route;
use function rest_ensure_response;
use function sanitize_text_field;
use function update_post_meta;

final class VendorScheduleRestController
{
    public static function init(): void
    {
        add_action('rest_api_init', array(self::class, 'registerRoutes'));
    }

    public static function registerRoutes(): void
    {
        register_rest_route(
            'bsp/v1',
            '/vendors/(?P<id>\d+)/schedule',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array(self::class, 'getSchedule'),
                    'permission_callback' => array(VendorRestController::class, 'canManage'),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array(self::class, 'updateSchedule'),
                    'permission_callback' => array(VendorRestController::class, 'canManage'),
                ),
            )
        );
    }

    public static function getSchedule(WP_REST_Request $request)
    {
        $vendorId = absint($request['id']);
        $vendor   = VendorService::get($vendorId);
        if ($vendor === null) {
            return new WP_Error('bsp_sales_vendor_missing', __('Vendor not found.', 'sbdp'), array('status' => 404));
        }

        return rest_ensure_response(array(
            'vendor_id' => $vendorId,
            'resources' => VendorService::getResources($vendorId),
        ));
    }

    public static function updateSchedule(WP_REST_Request $request)
    {
        $vendorId = absint($request['id']);
        $vendor   = VendorService::get($vendorId);
        if ($vendor === null) {
            return new WP_Error('bsp_sales_vendor_missing', __('Vendor not found.', 'sbdp'), array('status' => 404));
        }

        $resourceId = absint($request->get_param('resource_id'));
        if ($resourceId <= 0) {
            return new WP_Error('bsp_sales_schedule_missing_resource', __('Resource is required.', 'sbdp'), array('status' => 400));
        }

        $linkedVendor = (int) get_post_meta($resourceId, '_sbdp_resource_vendor', true);
        if ($linkedVendor !== $vendorId) {
            return new WP_Error('bsp_sales_schedule_forbidden', __('You cannot manage this resource for the selected vendor.', 'sbdp'), array('status' => 403));
        }

        $body         = $request->get_json_params() ?: array();
        $availability = array();
        $tours        = array();

        if (array_key_exists('availability', $body) && is_array($body['availability'])) {
            $availability = self::sanitizeAvailability($body['availability']);
        }

        if (array_key_exists('tours', $body) && is_array($body['tours'])) {
            $tours = self::sanitizeTours($body['tours']);
        }

        if ($availability !== array()) {
            update_post_meta($resourceId, '_sbdp_resource_availability', $availability);
        }

        if ($tours !== array()) {
            update_post_meta($resourceId, '_sbdp_resource_tours', $tours);
        }

        return rest_ensure_response(array(
            'vendor_id' => $vendorId,
            'resources' => VendorService::getResources($vendorId),
        ));
    }

    private static function sanitizeAvailability(array $items): array
    {
        $sanitized = array();
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $start = isset($item['start']) ? sanitize_text_field((string) $item['start']) : '';
            $end   = isset($item['end']) ? sanitize_text_field((string) $item['end']) : '';
            if ($start === '' || $end === '') {
                continue;
            }

            $sanitized[] = array(
                'start' => $start,
                'end'   => $end,
                'notes' => isset($item['notes']) ? sanitize_text_field((string) $item['notes']) : '',
            );
        }

        return $sanitized;
    }

    private static function sanitizeTours(array $items): array
    {
        $sanitized = array();
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $date   = isset($item['date']) ? sanitize_text_field((string) $item['date']) : '';
            if ($date === '') {
                continue;
            }

            $status = isset($item['status']) ? sanitize_text_field((string) $item['status']) : VendorService::TOUR_STATUS_UPCOMING;
            if (! in_array($status, VendorService::TOUR_STATUSES, true)) {
                $status = VendorService::TOUR_STATUS_UPCOMING;
            }

            $sanitized[] = array(
                'date'   => $date,
                'status' => $status,
                'notes'  => isset($item['notes']) ? sanitize_text_field((string) $item['notes']) : '',
            );
        }

        return $sanitized;
    }
}



