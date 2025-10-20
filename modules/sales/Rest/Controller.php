<?php
declare(strict_types=1);

namespace BSP\Sales\Rest;

use BSP\Sales\Module;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller exposing sales helper endpoints.
 */
final class Controller
{
    /**
     * Register sales REST endpoints.
     */
    public static function register(): void
    {
        if (!\function_exists('register_rest_route')) {
            return;
        }

        \register_rest_route(
            'bsp/v1',
            '/sales/revenue',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'revenue'],
                'permission_callback' => '__return_true',
            ]
        );

        \register_rest_route(
            'bsp/v1',
            '/sales/top-products',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'topProducts'],
                'permission_callback' => '__return_true',
            ]
        );

        \register_rest_route(
            'bsp/v1',
            '/sales/dynamic-pricing',
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'dynamicPricingConfig'],
                'permission_callback' => [__CLASS__, 'canManageSales'],
            ]
        );

        \register_rest_route(
            'bsp/v1',
            '/sales/dynamic-pricing',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'configureDynamicPricing'],
                'permission_callback' => [__CLASS__, 'canManageSales'],
            ]
        );

        \register_rest_route(
            'bsp/v1',
            '/sales/dynamic-pricing/preview',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'previewDynamicPrice'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Calculate overall revenue for provided orders.
     *
     * @return WP_REST_Response|array<string, float>
     */
    public static function revenue(WP_REST_Request $request)
    {
        $orders = $request->get_param('orders');
        $orders = \is_array($orders) ? $orders : [];

        $module = new Module();
        $revenue = $module->calculateRevenue($orders);
        $data = ['revenue' => $revenue];

        return \function_exists('rest_ensure_response') ? \rest_ensure_response($data) : $data;
    }

    /**
     * Return the most popular products ranked by quantity.
     *
     * @return WP_REST_Response|array<string, int>
     */
    public static function topProducts(WP_REST_Request $request)
    {
        $lines = $request->get_param('lines');
        $limit = (int)($request->get_param('limit') ?? 3);
        $lines = \is_array($lines) ? $lines : [];

        $module = new Module();
        $top = $module->topProducts($lines, $limit);

        return \function_exists('rest_ensure_response') ? \rest_ensure_response($top) : $top;
    }

    public static function dynamicPricingConfig()
    {
        $module = new Module();
        $data   = ['config' => $module->getDynamicPricingConfiguration()];

        return \function_exists('rest_ensure_response') ? \rest_ensure_response($data) : $data;
    }

    public static function configureDynamicPricing(WP_REST_Request $request)
    {
        $payload = (array) $request->get_json_params();

        $module = new Module();
        $config = $module->configureDynamicPricing($payload);
        $data   = ['success' => true, 'config' => $config];

        return \function_exists('rest_ensure_response') ? \rest_ensure_response($data) : $data;
    }

    public static function previewDynamicPrice(WP_REST_Request $request)
    {
        $price   = (float) ($request->get_param('price') ?? 0.0);
        $signals = $request->get_param('signals');
        $signals = \is_array($signals) ? $signals : [];

        $module = new Module();
        $result = $module->generateDynamicPrice($price, $signals);

        return \function_exists('rest_ensure_response') ? \rest_ensure_response($result) : $result;
    }

    public static function canManageSales(): bool
    {
        if (! \function_exists('current_user_can')) {
            return true;
        }

        return \current_user_can('manage_woocommerce') || \current_user_can('manage_options');
    }
}

