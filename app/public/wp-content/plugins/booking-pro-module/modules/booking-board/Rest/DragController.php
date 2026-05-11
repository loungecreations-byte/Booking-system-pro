<?php

declare(strict_types=1);

namespace BSP\BookingBoard\Rest;

use BSP\BookingBoard\Service\DragDropSessionService;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;

final class DragController
{
    private static ?DragDropSessionService $service = null;

    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            'sbdp/v1',
            '/drag/sessions',
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'startSession'),
                'permission_callback' => array(__CLASS__, 'canManage'),
            )
        );

        register_rest_route(
            'sbdp/v1',
            '/drag/apply',
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'apply'),
                'permission_callback' => array(__CLASS__, 'canManage'),
            )
        );

        register_rest_route(
            'sbdp/v1',
            '/drag/commit',
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'commit'),
                'permission_callback' => array(__CLASS__, 'canManage'),
            )
        );

        register_rest_route(
            'sbdp/v1',
            '/drag/rollback',
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'rollback'),
                'permission_callback' => array(__CLASS__, 'canManage'),
            )
        );
    }

    public static function startSession(WP_REST_Request $request)
    {
        $bookingId = (int) $request->get_param('booking_id');

        return self::respond(
            static fn (DragDropSessionService $service) => $service->start(
                $bookingId,
                function_exists('get_current_user_id') ? get_current_user_id() : 0
            )
        );
    }

    public static function apply(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = array();
        }

        $sessionId = isset($payload['session_id']) ? (string) $payload['session_id'] : '';

        return self::respond(
            static fn (DragDropSessionService $service) => $service->apply(
                $sessionId,
                function_exists('get_current_user_id') ? get_current_user_id() : 0,
                $payload
            )
        );
    }

    public static function commit(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = array();
        }

        $sessionId = isset($payload['session_id']) ? (string) $payload['session_id'] : '';

        return self::respond(
            static fn (DragDropSessionService $service) => $service->commit(
                $sessionId,
                function_exists('get_current_user_id') ? get_current_user_id() : 0
            )
        );
    }

    public static function rollback(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = array();
        }

        $sessionId = isset($payload['session_id']) ? (string) $payload['session_id'] : '';

        return self::respond(
            static fn (DragDropSessionService $service) => $service->rollback(
                $sessionId,
                function_exists('get_current_user_id') ? get_current_user_id() : 0
            )
        );
    }

    public static function canManage(): bool
    {
        return function_exists('current_user_can')
            ? current_user_can('manage_woocommerce')
            : true;
    }

    /**
     * @param callable(DragDropSessionService): (array<string, mixed>|object) $callback
     */
    private static function respond(callable $callback)
    {
        try {
            $result = $callback(self::service());
            return function_exists('rest_ensure_response') ? rest_ensure_response($result) : $result;
        } catch (InvalidArgumentException $exception) {
            return new WP_Error('sbdp_drag_invalid', $exception->getMessage(), array('status' => 400));
        } catch (\Throwable $exception) {
            return new WP_Error('sbdp_drag_error', $exception->getMessage(), array('status' => 500));
        }
    }

    private static function service(): DragDropSessionService
    {
        if (self::$service === null) {
            self::$service = new DragDropSessionService();
        }

        return self::$service;
    }

    public static function setService(DragDropSessionService $service): void
    {
        self::$service = $service;
    }
}
