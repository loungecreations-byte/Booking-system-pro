<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Rest;

use SBDP\Modules\Arrangements\Domain\ArrangementPlannerService;
use SBDP\Modules\Arrangements\Domain\ArrangementRepository;
use SBDP\Modules\Arrangements\Domain\ArrangementSchema;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Controller
{
    public function __construct(
        private ArrangementRepository $repository = new ArrangementRepository(),
        private ArrangementPlannerService $planner = new ArrangementPlannerService()
    ) {
    }

    public function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            'sbdp/v1',
            '/arrangements',
            array(
                array(
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => array($this, 'index'),
                    'permission_callback' => '__return_true',
                ),
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array($this, 'create'),
                    'permission_callback' => array($this, 'canManage'),
                ),
            )
        );

        register_rest_route(
            'sbdp/v1',
            '/arrangements/(?P<id>\d+)',
            array(
                array(
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => array($this, 'show'),
                    'permission_callback' => '__return_true',
                ),
                array(
                    'methods' => array('POST', 'PATCH', 'PUT'),
                    'callback' => array($this, 'update'),
                    'permission_callback' => array($this, 'canManage'),
                ),
            )
        );

        register_rest_route(
            'sbdp/v1',
            '/arrangements/(?P<id>\d+)/preview',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'preview'),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $filters = $request->get_params();
        $items = array();
        foreach ($this->repository->query(is_array($filters) ? $filters : array()) as $arrangement) {
            $item = $this->planner->toPlannerProduct($arrangement, $filters);
            $items[] = $item ?: $arrangement;
        }

        return new WP_REST_Response(array('arrangements' => $items));
    }

    public function show(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $arrangement = $this->repository->find($id);
        if (! is_array($arrangement)) {
            return new WP_Error('sbdp_arrangement_not_found', __('Arrangement not found.', 'sbdp'), array('status' => 404));
        }

        return new WP_REST_Response(array('arrangement' => $arrangement));
    }

    public function create(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        $payload = is_array($payload) ? $payload : array();
        $id = $this->repository->save($payload);
        if ($id <= 0) {
            return new WP_Error('sbdp_arrangement_save_failed', __('Arrangement could not be saved.', 'sbdp'), array('status' => 500));
        }

        return new WP_REST_Response(array('id' => $id));
    }

    public function update(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $payload = $request->get_json_params();
        $payload = is_array($payload) ? $payload : array();
        $payload['id'] = $id;
        $savedId = $this->repository->save($payload);
        if ($savedId <= 0) {
            return new WP_Error('sbdp_arrangement_save_failed', __('Arrangement could not be saved.', 'sbdp'), array('status' => 500));
        }

        return new WP_REST_Response(array('id' => $savedId));
    }

    public function preview(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $arrangement = $this->repository->find($id);
        if (! is_array($arrangement)) {
            return new WP_REST_Response(array('available' => false, 'pricing' => array(), 'status' => 'missing'), 404);
        }

        $context = array(
            'date' => (string) $request->get_param('date'),
            'start' => (string) $request->get_param('start'),
            'participants' => (int) ($request->get_param('participants') ?: 1),
            'resource_id' => (int) ($request->get_param('resource_id') ?: 0),
        );

        return new WP_REST_Response(array(
            'arrangement' => $arrangement,
            'pricing' => $this->planner->toPlannerProduct($arrangement, $context)['pricing'] ?? array(),
        ));
    }

    public function canManage(): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }
}
