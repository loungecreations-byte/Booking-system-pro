<?php
declare(strict_types=1);

namespace BSP\Commerce\Rest;

use BSP\Commerce\Module;
use SBDP\Pricing\PricingService;
use Throwable;
use WP_Error;
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
                'permission_callback' => [__CLASS__, 'canProcessOrders'],
            ]
        );
    }

    /**
     * Calculate a price.
     *
     * Modes:
     * - CSOT mode: pass product_id (+ optional participants/context) to resolve via PricingService.
     * - Legacy mode: pass base + rules for generic arithmetic.
     *
     * @return WP_REST_Response|array<string, mixed>|WP_Error
     */
    public static function calcPrice(WP_REST_Request $request)
    {
        $productId = (int) ($request->get_param('product_id') ?? 0);
        if ($productId > 0) {
            $participants = max(1, (int) ($request->get_param('participants') ?? 1));
            $context = $request->get_param('context');
            $context = \is_array($context) ? $context : [];

            try {
                $quote = PricingService::instance()->quote($productId, $participants, $context);

                $data = [
                    'source' => 'pricing_service',
                    'price' => (float) ($quote['total'] ?? 0.0),
                    'total' => (float) ($quote['total'] ?? 0.0),
                    'unit_price' => (float) ($quote['unit_price'] ?? 0.0),
                    'currency' => (string) ($quote['currency'] ?? 'EUR'),
                    'product_id' => $productId,
                    'participants' => $participants,
                    'quote' => $quote,
                ];

                return \function_exists('rest_ensure_response') ? \rest_ensure_response($data) : $data;
            } catch (Throwable $exception) {
                return new WP_Error(
                    'bsp_commerce_calc_price_failed',
                    $exception->getMessage(),
                    ['status' => 400]
                );
            }
        }

        $base = (float)($request->get_param('base') ?? 0.0);
        $rules = $request->get_param('rules');
        $rules = \is_array($rules) ? $rules : [];

        $module = new Module();
        $price = $module->calculatePrice($base, $rules);
        $data = [
            'source' => 'legacy_rules',
            'price' => $price,
        ];

        return \function_exists('rest_ensure_response') ? \rest_ensure_response($data) : $data;
    }

    /**
     * Process an order through the module and return a status payload.
     *
     * @return WP_REST_Response|array<string, string>
     */
    public static function processOrder(WP_REST_Request $request)
    {
        $authorization = self::canProcessOrders($request);
        if ($authorization instanceof WP_Error) {
            return $authorization;
        }

        $orderId = (int)($request->get_param('orderId') ?? 0);

        $module = new Module();
        $message = $module->processOrder($orderId);
        $data = ['message' => $message];

        return \function_exists('rest_ensure_response') ? \rest_ensure_response($data) : $data;
    }

    /**
     * @return true|WP_Error
     */
    public static function canProcessOrders(WP_REST_Request $request)
    {
        unset($request);

        if (\function_exists('current_user_can') && (\current_user_can('manage_options') || \current_user_can('manage_woocommerce'))) {
            return true;
        }

        return new WP_Error(
            'bsp_commerce_process_order_forbidden',
            'Order processing can only be triggered by an authorised server-side flow.',
            ['status' => 403]
        );
    }
}
