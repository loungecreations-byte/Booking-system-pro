<?php

declare(strict_types=1);

namespace BSP\Bookings\Rest;

use BSP\Bookings\Service\BookingService;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;

final class Controller
{
    private const RATE_LIMIT_WINDOW = 60;
    private const RATE_LIMIT_MAX = 20;
    private const RATE_LIMIT_PREFIX = 'bsp_booking_rest_';

    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route('bsp/v1', '/booking/create', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'create'],
            'permission_callback' => [__CLASS__, 'authorizePublicBooking'],
        ]);

        register_rest_route('bsp/v1', '/booking/request', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'request'],
            'permission_callback' => [__CLASS__, 'authorizePublicBooking'],
        ]);

        register_rest_route('bsp/v1', '/booking/pay', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'pay'],
            'permission_callback' => [__CLASS__, 'canMutatePaymentState'],
        ]);

        register_rest_route('bsp/v1', '/booking/list', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'list'],
            'permission_callback' => [__CLASS__, 'canListBookings'],
        ]);
    }

    public static function create(WP_REST_Request $request)
    {
        return self::wrap(static fn () => BookingService::createPublicBooking(self::parseJson($request)));
    }

    public static function request(WP_REST_Request $request)
    {
        return self::wrap(static fn () => BookingService::requestPublicBooking(self::parseJson($request)));
    }

    public static function pay(WP_REST_Request $request)
    {
        $authorization = self::canMutatePaymentState($request);
        if ($authorization instanceof WP_Error) {
            return self::respond($authorization);
        }

        return self::wrap(static fn () => BookingService::pay(self::parseJson($request)));
    }

    public static function list(WP_REST_Request $request)
    {
        unset($request); // parameter kept for signature compatibility.
        return self::respond(BookingService::getBookings());
    }

    public static function canListBookings(WP_REST_Request $request): bool
    {
        unset($request); // parameter kept for signature compatibility.

        return function_exists('current_user_can') && (current_user_can('manage_options') || current_user_can('manage_woocommerce'));
    }

    /**
     * @return true|WP_Error
     */
    public static function canMutatePaymentState(WP_REST_Request $request)
    {
        unset($request); // parameter kept for signature compatibility.

        if (function_exists('current_user_can') && (current_user_can('manage_options') || current_user_can('manage_woocommerce'))) {
            return true;
        }

        return new WP_Error(
            'bsp_booking_payment_forbidden',
            'Payment state can only be changed by an authorised server-side flow.',
            ['status' => 403]
        );
    }

    /**
     * @return true|WP_Error
     */
    public static function authorizePublicBooking(WP_REST_Request $request)
    {
        unset($request);

        $bucket = self::RATE_LIMIT_PREFIX . md5(self::requestIp());
        $state = get_transient($bucket);
        $state = is_array($state) ? $state : [];
        $attempts = (int) ($state['attempts'] ?? 0) + 1;

        if ($attempts > self::RATE_LIMIT_MAX) {
            return new WP_Error('bsp_booking_rate_limited', 'Too many booking requests.', ['status' => 429]);
        }

        set_transient($bucket, ['attempts' => $attempts], self::RATE_LIMIT_WINDOW);

        return true;
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

    private static function requestIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $parts = explode(',', $candidate);
            $ip = trim((string) ($parts[0] ?? ''));
            if ($ip !== '') {
                return $ip;
            }
        }

        return 'unknown';
    }
}

