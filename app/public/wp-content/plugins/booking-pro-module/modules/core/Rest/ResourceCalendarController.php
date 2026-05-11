<?php

declare(strict_types=1);

namespace BSPModule\Core\Rest;

use BSPModule\Core\Resource\ResourceCalendar;
use BSPModule\Core\Resource\ResourceCalendarSyncService;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;

if (class_exists(__NAMESPACE__ . '\\ResourceCalendarController', false)) {
    return;
}

final class ResourceCalendarController extends WP_REST_Controller
{
    public function register_routes(): void
    {
        register_rest_route(
            'sbdp/v1',
            '/resource-calendar/connect',
            array(
                array(
                    'methods'             => 'POST',
                    'permission_callback' => array($this, 'can_manage'),
                    'callback'            => array($this, 'connect'),
                    'args'                => array(
                        'resource_id' => array(
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ),
                        'calendar_id' => array(
                            'required'          => false,
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                        'timezone' => array(
                            'required'          => false,
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            'sbdp/v1',
            '/resource-calendar/sync',
            array(
                array(
                    'methods'             => 'POST',
                    'permission_callback' => array($this, 'can_manage'),
                    'callback'            => array($this, 'sync'),
                    'args'                => array(
                        'resource_id' => array(
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ),
                    ),
                ),
            )
        );
    }

    public function connect(WP_REST_Request $request)
    {
        $resource_id = absint($request->get_param('resource_id'));
        if ($resource_id <= 0) {
            return new WP_Error('invalid_resource', __('Invalid resource.', 'sbdp'), array('status' => 400));
        }

        $calendar_id = trim((string) $request->get_param('calendar_id'));
        $timezone = trim((string) $request->get_param('timezone'));
        $access_token = trim((string) $request->get_param('access_token'));
        $refresh_token = trim((string) $request->get_param('refresh_token'));

        ResourceCalendar::set_calendar_id($resource_id, $calendar_id !== '' ? $calendar_id : null);
        ResourceCalendar::set_timezone($resource_id, $timezone !== '' ? $timezone : null);

        $tokens = array();
        if ($access_token !== '') {
            $tokens['access_token'] = $access_token;
        }
        if ($refresh_token !== '') {
            $tokens['refresh_token'] = $refresh_token;
        }

        if ([] !== $tokens) {
            ResourceCalendar::set_tokens($resource_id, $tokens);
        }

        ResourceCalendar::mark_connected($resource_id);

        return rest_ensure_response(array('success' => true));
    }

    public function sync(WP_REST_Request $request)
    {
        $resource_id = absint($request->get_param('resource_id'));
        if ($resource_id <= 0) {
            return new WP_Error('invalid_resource', __('Invalid resource.', 'sbdp'), array('status' => 400));
        }

        $result = ResourceCalendarSyncService::sync_resource($resource_id);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array('success' => true, 'blocks' => $result));
    }

    public function can_manage(): bool
    {
        return current_user_can('manage_woocommerce');
    }
}
