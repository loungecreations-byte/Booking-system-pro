<?php

declare(strict_types=1);

namespace BSP\Bookings\Rest;

use BSP\Bookings\Service\BookingService;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;

final class Controller
{
    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route('bsp/v1', '/booking/create', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'create'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('bsp/v1', '/booking/request', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'request'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('bsp/v1', '/booking/pay', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'pay'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('bsp/v1', '/booking/list', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'list'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function create(WP_REST_Request $request)
    {
        return self::wrap(static fn () => BookingService::createBooking(self::parseJson($request)));
    }

    public static function request(WP_REST_Request $request)
    {
        return self::wrap(static fn () => BookingService::requestBooking(self::parseJson($request)));
    }

    public static function pay(WP_REST_Request $request)
    {
        return self::wrap(static fn () => BookingService::pay(self::parseJson($request)));
    }

    public static function list(WP_REST_Request $request)
    {
        unset($request); // parameter kept for signature compatibility.
        return self::respond(BookingService::getBookings());
    }

    private static function wrap(callable $callback)
    {
        try {
            $result = $callback();
            return self::respond($result);
        } catch (InvalidArgumentException $exception) {
            return self::respond(new WP_Error('bsp_booking_invalid', $exception->getMessage(), ['status' => 400]));
        }
    }

    private static function parseJson(WP_REST_Request $request): array
    {
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }

        return $params;
    }

    private static function respond($data)
    {
        if (function_exists('rest_ensure_response')) {
            return rest_ensure_response($data);
        }

        return $data;
    }
}

