<?php

declare(strict_types=1);

namespace BSP\Planner\Rest\Planboard;

use BSP\Planner\Services\Planboard\PlanboardPermissions;
use BSPModule\Core\Rest\RestService;
use WP_Error;
use WP_REST_Controller;

final class PlanboardOverviewController extends WP_REST_Controller
{
    public function __construct()
    {
        $this->namespace = 'bsp/v2';
        $this->rest_base = 'planboard/overview';
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array($this, 'get_item'),
                    'permission_callback' => array($this, 'can_view'),
                ),
            )
        );
    }

    public function get_item($request)
    {
        if (! $request instanceof \WP_REST_Request) {
            return new WP_Error('sbdp_planboard_invalid_request', __('Invalid request.', 'sbdp'), array('status' => 400));
        }

        return RestService::get_schedule_overview($request);
    }

    public function can_view(): bool
    {
        return PlanboardPermissions::canView();
    }
}
