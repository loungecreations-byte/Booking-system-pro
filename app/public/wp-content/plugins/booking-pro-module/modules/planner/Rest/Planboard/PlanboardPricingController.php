<?php

declare(strict_types=1);

namespace BSP\Planner\Rest\Planboard;

use BSP\Planner\Services\Planboard\PlanboardPermissions;
use BSPModule\Core\Rest\RestService;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

final class PlanboardPricingController extends WP_REST_Controller
{
    public function __construct()
    {
        $this->namespace = 'bsp/v2';
        $this->rest_base = 'planboard/pricing/preview';
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array($this, 'preview'),
                    'permission_callback' => array($this, 'can_view'),
                ),
            )
        );
    }

    public function preview($request)
    {
        if (! $request instanceof WP_REST_Request) {
            return new WP_Error('sbdp_planboard_invalid_request', __('Invalid request.', 'sbdp'), array('status' => 400));
        }

        if (! function_exists('wc_get_product')) {
            return new WP_Error('sbdp_planboard_pricing_unavailable', __('Pricing service unavailable.', 'sbdp'), array('status' => 500));
        }

        $payload = $request->get_json_params();
        $payload = is_array($payload) ? $payload : array();

        $start = isset($payload['start']) ? (string) $payload['start'] : '';
        $resourceId = isset($payload['resource_id']) ? (int) $payload['resource_id'] : 0;
        $participants = isset($payload['participants']) ? (int) $payload['participants'] : 1;
        $participants = max(1, $participants);

        $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : array();
        if ($items === array()) {
            return new WP_Error('sbdp_planboard_invalid_items', __('At least one item is required.', 'sbdp'), array('status' => 400));
        }

        $results = array();

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) {
                $results[] = array(
                    'product_id' => 0,
                    'error'      => __('Invalid product.', 'sbdp'),
                );
                continue;
            }

            $product = wc_get_product($productId);
            if (! $product) {
                $results[] = array(
                    'product_id' => $productId,
                    'error'      => __('Product not found.', 'sbdp'),
                );
                continue;
            }

            $itemParticipants = isset($item['participants']) ? (int) $item['participants'] : $participants;
            $itemParticipants = max(1, $itemParticipants);

            $itemResource = isset($item['resource_id']) ? (int) $item['resource_id'] : $resourceId;

            $pricing = RestService::calculate_pricing_for_item(
                $product,
                $itemResource,
                $start,
                $itemParticipants,
                array('channel' => 'planboard')
            );

            $unit = is_array($pricing) && isset($pricing['unit_price']) ? (float) $pricing['unit_price'] : 0.0;
            if ($unit <= 0 && is_array($pricing) && isset($pricing['total'])) {
                $qty = max(1, (int) ($item['quantity'] ?? 1));
                $unit = $qty > 0 ? (float) $pricing['total'] / $qty : 0.0;
            }

            $results[] = array(
                'product_id' => $productId,
                'resource_id'=> $itemResource,
                'participants' => $itemParticipants,
                'pricing'    => $pricing,
                'unit_price' => $unit > 0 ? round($unit, 2) : 0.0,
            );
        }

        return new WP_REST_Response(
            array(
                'items' => $results,
            )
        );
    }

    public function can_view(): bool
    {
        return PlanboardPermissions::canView();
    }
}
