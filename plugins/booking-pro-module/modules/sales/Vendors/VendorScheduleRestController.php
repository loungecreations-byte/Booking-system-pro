<?php

declare(strict_types=1);

namespace BSP\Sales\Vendors;

use BSPModule\Core\Services\ResourceScheduleService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

use function absint;
use function add_action;
use function register_rest_route;
use function rest_ensure_response;

final class VendorScheduleRestController
{
    public static function init(): void
    {
        add_action('rest_api_init', array(__CLASS__, 'registerRoutes'));
    }

    public static function registerRoutes(): void
    {
        register_rest_route(
            'bsp/v1',
            '/vendors/(?P<id>\d+)/schedule',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array(__CLASS__, 'getSchedule'),
                    'permission_callback' => array(VendorRestController::class, 'canManage'),
                    'args'                => array(
                        'id' => array(
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ),
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array(__CLASS__, 'updateSchedule'),
                    'permission_callback' => array(VendorRestController::class, 'canManage'),
                    'args'                => array(
                        'id'          => array(
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ),
                        'resource_id' => array(
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ),
                    ),
                ),
            )
        );
    }

    public static function getSchedule(WP_REST_Request $request)
    {
        $vendorId = absint($request['id']);
        $response = ResourceScheduleService::getVendorSchedule($vendorId);

        return $response instanceof WP_Error ? $response : rest_ensure_response($response);
    }

    public static function updateSchedule(WP_REST_Request $request)
    {
        $vendorId = absint($request['id']);
        $resourceId = absint($request->get_param('resource_id'));
        $body         = $request->get_json_params() ?: array();
        $response = ResourceScheduleService::updateVendorSchedule(
            $vendorId,
            $resourceId,
            array_key_exists('availability', $body) && is_array($body['availability']) ? $body['availability'] : array(),
            array_key_exists('tours', $body) && is_array($body['tours']) ? $body['tours'] : array()
        );

        return $response instanceof WP_Error ? $response : rest_ensure_response($response);
    }
}




