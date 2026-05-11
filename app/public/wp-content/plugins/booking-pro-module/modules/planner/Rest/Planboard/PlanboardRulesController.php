<?php

declare(strict_types=1);

namespace BSP\Planner\Rest\Planboard;

use BSP\Planner\Services\Planboard\PlanboardPermissions;
use BSP\Planner\Services\Planboard\PlanboardRulesService;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

final class PlanboardRulesController extends WP_REST_Controller
{
    public function __construct(private PlanboardRulesService $service)
    {
        $this->namespace = 'bsp/v2';
        $this->rest_base = 'planboard/closures';
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
                    'permission_callback' => array($this, 'can_manage'),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array($this, 'create_item'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9\\-_.:]+)',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array($this, 'get_item'),
                    'permission_callback' => array($this, 'can_manage'),
                    'args'                => array(
                        'id' => array(
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                    ),
                ),
                array(
                    'methods'             => array('PUT', 'PATCH'),
                    'callback'            => array($this, 'update_item'),
                    'permission_callback' => array($this, 'can_manage'),
                    'args'                => array(
                        'id' => array(
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                    ),
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array($this, 'delete_item'),
                    'permission_callback' => array($this, 'can_manage'),
                    'args'                => array(
                        'id' => array(
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                    ),
                ),
            )
        );
    }

    public function list_items($request)
    {
        if (! $request instanceof WP_REST_Request) {
            return new WP_Error('sbdp_planboard_invalid_request', __('Invalid request.', 'sbdp'), array('status' => 400));
        }

        return new WP_REST_Response(
            array(
                'rules' => $this->service->all(),
            )
        );
    }

    public function get_item($request)
    {
        if (! $request instanceof WP_REST_Request) {
            return new WP_Error('sbdp_planboard_invalid_request', __('Invalid request.', 'sbdp'), array('status' => 400));
        }

        $id = (string) $request->get_param('id');
        $rule = $this->service->get($id);

        if ($rule === null) {
            return new WP_Error('sbdp_planboard_rule_not_found', __('Rule not found.', 'sbdp'), array('status' => 404));
        }

        return new WP_REST_Response($rule);
    }

    public function create_item($request)
    {
        if (! $request instanceof WP_REST_Request) {
            return new WP_Error('sbdp_planboard_invalid_request', __('Invalid request.', 'sbdp'), array('status' => 400));
        }

        $payload = $this->getJson($request);
        $created = $this->service->create($payload);

        if ($created instanceof WP_Error) {
            return $created;
        }

        return new WP_REST_Response($created, 201);
    }

    public function update_item($request)
    {
        if (! $request instanceof WP_REST_Request) {
            return new WP_Error('sbdp_planboard_invalid_request', __('Invalid request.', 'sbdp'), array('status' => 400));
        }

        $id = (string) $request->get_param('id');
        $payload = $this->getJson($request);
        $updated = $this->service->update($id, $payload);

        if ($updated instanceof WP_Error) {
            return $updated;
        }

        return new WP_REST_Response($updated);
    }

    public function delete_item($request)
    {
        if (! $request instanceof WP_REST_Request) {
            return new WP_Error('sbdp_planboard_invalid_request', __('Invalid request.', 'sbdp'), array('status' => 400));
        }

        $id = (string) $request->get_param('id');
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return new WP_Error('sbdp_planboard_rule_not_found', __('Rule not found.', 'sbdp'), array('status' => 404));
        }

        return new WP_REST_Response(array('deleted' => true));
    }

    public function can_manage(): bool
    {
        return PlanboardPermissions::canManageRules();
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();
        return is_array($payload) ? $payload : array();
    }
}
