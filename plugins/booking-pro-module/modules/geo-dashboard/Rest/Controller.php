<?php
declare(strict_types=1);

namespace BSP\GeoDashboard\Rest;

use BSP\GeoDashboard\Service\GeoDataProvider;
use WP_REST_Request;

final class Controller
{
    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route('bsp/v1', '/geodashboard', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'index'],
            'permission_callback' => [__CLASS__, 'permissions'],
        ]);
    }

    public static function permissions(): bool
    {
        return current_user_can('manage_options');
    }

    public static function index(WP_REST_Request $request)
    {
        $filters = [
            'vendor_status'  => (string) $request->get_param('vendor_status'),
            'booking_status' => (string) $request->get_param('booking_status'),
            'radius'         => (float) $request->get_param('radius'),
            'start_date'     => (string) $request->get_param('start_date'),
            'end_date'       => (string) $request->get_param('end_date'),
        ];

        $provider = new GeoDataProvider();
        $data     = $provider->getGeoData($filters);

        if (function_exists('rest_ensure_response')) {
            return rest_ensure_response($data);
        }

        return $data;
    }
}

