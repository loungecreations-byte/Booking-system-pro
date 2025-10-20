<?php
declare(strict_types=1);

namespace BSP\Intelligence\Rest;

use BSP\Intelligence\Module;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for intelligence utilities.
 */
final class Controller
{
    /**
     * Register intelligence REST endpoints.
     */
    public static function register(): void
    {
        if (!\function_exists('register_rest_route')) {
            return;
        }

        \register_rest_route(
            'bsp/v1',
            '/intel/trends',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'trends'],
                'permission_callback' => '__return_true',
            ]
        );

        \register_rest_route(
            'bsp/v1',
            '/intel/forecast',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'forecast'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Return the strongest trends for the supplied dataset.
     *
     * @return WP_REST_Response|array<string, float|int>
     */
    public static function trends(WP_REST_Request $request)
    {
        $kv = $request->get_param('kv');
        $kv = \is_array($kv) ? $kv : [];
        $k = (int)($request->get_param('k') ?? 3);

        $module = new Module();
        $trends = $module->analyzeTrends($kv, $k);

        return \function_exists('rest_ensure_response') ? \rest_ensure_response($trends) : $trends;
    }

    /**
     * Produce forecasts for the provided series.
     *
     * @return WP_REST_Response|array<string, float>
     */
    public static function forecast(WP_REST_Request $request)
    {
        $series = $request->get_param('series');
        $series = \is_array($series) ? $series : [];
        $window = (int)($request->get_param('window') ?? 3);

        $module = new Module();
        $forecast = $module->forecastDemand($series, $window);

        return \function_exists('rest_ensure_response') ? \rest_ensure_response($forecast) : $forecast;
    }
}

