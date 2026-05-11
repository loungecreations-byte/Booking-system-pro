<?php

declare(strict_types=1);

namespace BSP\Planner\Rest\Planboard;

use BSP\Planner\Services\Planboard\PlanboardPermissions;
use BSP\Planner\Services\Planboard\PlanboardProductService;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

final class PlanboardProductsController extends WP_REST_Controller
{
    public function __construct(private PlanboardProductService $service)
    {
        $this->namespace = 'bsp/v2';
        $this->rest_base = 'planboard/products';
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array($this, 'list_items'),
                    'permission_callback' => array($this, 'can_view'),
                ),
            )
        );
    }

    public function list_items($request)
    {
        if (! $request instanceof WP_REST_Request) {
            return new WP_Error('sbdp_planboard_invalid_request', __('Invalid request.', 'sbdp'), array('status' => 400));
        }

        $filters = array();
        $resourceId = (int) $request->get_param('resource_id');
        if ($resourceId > 0) {
            $filters['resource'] = array($resourceId);
        }

        $search = $request->get_param('search');
        if (is_string($search) && $search !== '') {
            $filters['search'] = $search;
        }

        $filters['limit'] = (int) ($request->get_param('limit') ?: 50);

        return new WP_REST_Response(
            array(
                'products' => $this->service->list($filters),
            )
        );
    }

    public function can_view(): bool
    {
        return PlanboardPermissions::canView();
    }
}
