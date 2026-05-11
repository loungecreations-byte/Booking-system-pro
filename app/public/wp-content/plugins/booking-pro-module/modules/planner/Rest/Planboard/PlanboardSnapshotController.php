<?php

declare(strict_types=1);

namespace BSP\Planner\Rest\Planboard;

use BSP\Planner\Services\Planboard\PlanboardPermissions;
use BSP\Planner\Services\Planboard\PlanboardSnapshotService;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

final class PlanboardSnapshotController extends WP_REST_Controller
{
    public function __construct(private PlanboardSnapshotService $service)
    {
        $this->namespace = 'bsp/v2';
        $this->rest_base = 'planboard/snapshot';
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
                    'args'                => array(
                        'start'    => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                        'end'      => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                        'compress' => array('required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'),
                    ),
                ),
            )
        );
    }

    public function get_item($request)
    {
        if (! $request instanceof WP_REST_Request) {
            return new WP_Error('sbdp_planboard_invalid_request', __('Invalid request.', 'sbdp'), array('status' => 400));
        }

        $filters = array(
            'start' => $request->get_param('start'),
            'end'   => $request->get_param('end'),
        );

        $data = $this->service->snapshot($filters, ! $request->get_param('no_cache'));
        if ($data instanceof WP_Error) {
            return $data;
        }

        $compress = (bool) $request->get_param('compress');
        $acceptsGzip = strpos((string) $request->get_header('accept-encoding'), 'gzip') !== false;

        if ($compress || $acceptsGzip) {
            $json = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
            if (is_string($json) && function_exists('gzencode')) {
                $encoded = gzencode($json, 6);
                if ($encoded !== false) {
                    $response = new WP_REST_Response($encoded);
                    $response->set_headers(
                        array(
                            'Content-Type'     => 'application/json',
                            'Content-Encoding' => 'gzip',
                            'Vary'             => 'Accept-Encoding',
                        )
                    );

                    return $response;
                }
            }
        }

        return new WP_REST_Response($data);
    }

    public function can_view(): bool
    {
        return PlanboardPermissions::canView();
    }
}
