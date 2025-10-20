<?php
declare(strict_types=1);

namespace BSP\Commerce\Rest;

use BSP\Commerce\Module;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller exposing commerce functionality.
 */
final class Controller
{
    /**
     * Register the module REST endpoints with WordPress.
     */
    public static function register(): void
    {
        if (!\function_exists('register_rest_route')) {
            return;
        }

        \register_rest_route(
            'bsp/v1',
            '/commerce/calc-price',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'calcPrice'],
                'permission_callback' => '__return_true',
            ]
        );

        \register_rest_route(
            'bsp/v1',
            '/commerce/process-order',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'processOrder'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Calculate a price for the supplied base amount and pricing rules.
     *
     * @return WP_REST_Response|array<string, float>
     */
    public static function calcPrice(WP_REST_Request $request)
    {
        $base = (float)($request->get_param('base') ?? 0.0);
        $rules = $request->get_param('rules');
        $rules = \is_array($rules) ? $rules : [];

        $module = new Module();
        $price = $module->calculatePrice($base, $rules);
        $data = ['price' => $price];

        return \function_exists('rest_ensure_response') ? \rest_ensure_response($data) : $data;
    }

    /**
     * Process an order through the module and return a status payload.
     *
     * @return WP_REST_Response|array<string, string>
     */
    public static function processOrder(WP_REST_Request $request)
    {
        $orderId = (int)($request->get_param('orderId') ?? 0);

        $module = new Module();
        $message = $module->processOrder($orderId);
        $data = ['message' => $message];

        return \function_exists('rest_ensure_response') ? \rest_ensure_response($data) : $data;
    }
}

