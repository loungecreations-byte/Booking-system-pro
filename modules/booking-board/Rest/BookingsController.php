<?php

declare(strict_types=1);

namespace BSP\BookingBoard\Rest;

use BSP\BookingBoard\Service\BoardService;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class BookingsController
{
    private static ?BoardService $service = null;

    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            'bsp/v1',
            '/booking-board/bookings',
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'list'],
                'permission_callback' => [__CLASS__, 'canView'],
            ]
        );

        register_rest_route(
            'bsp/v1',
            '/booking-board/bookings/reschedule',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'reschedule'],
                'permission_callback' => [__CLASS__, 'canManage'],
            ]
        );

        register_rest_route(
            'bsp/v1',
            '/booking-board/bookings/update',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'update'],
                'permission_callback' => [__CLASS__, 'canManage'],
            ]
        );

        register_rest_route(
            'bsp/v1',
            '/booking-board/bookings/manual',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'createManual'],
                'permission_callback' => [__CLASS__, 'canManage'],
            ]
        );

        register_rest_route(
            'bsp/v1',
            '/booking-board/bookings/invoice',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'invoice'],
                'permission_callback' => [__CLASS__, 'canManage'],
            ]
        );

        register_rest_route(
            'bsp/v1',
            '/booking-board/bookings/invoice/pdf',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'invoicePdf'],
                'permission_callback' => [__CLASS__, 'canManage'],
            ]
        );

        register_rest_route(
            'bsp/v1',
            '/booking-board/stats',
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'stats'],
                'permission_callback' => [__CLASS__, 'canView'],
            ]
        );

        register_rest_route(
            'bsp/v1',
            '/booking-board/export',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'export'],
                'permission_callback' => [__CLASS__, 'canManage'],
            ]
        );

        register_rest_route(
            'bsp/v1',
            '/booking-board/customers',
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'customers'],
                'permission_callback' => [__CLASS__, 'canManage'],
            ]
        );
    }

    public static function list(WP_REST_Request $request)
    {
        $filters = [
            'status'    => $request->get_param('status'),
            'search'    => $request->get_param('search'),
            'date_from' => $request->get_param('date_from'),
            'date_to'   => $request->get_param('date_to'),
        ];

        return self::respond(static fn (BoardService $service) => $service->list(array_filter(
            $filters,
            static fn ($value) => $value !== null && $value !== ''
        )));
    }

    public static function reschedule(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = [];
        }

        return self::respond(static fn (BoardService $service) => [
            'booking' => $service->reschedule($payload),
        ]);
    }

    public static function update(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = [];
        }

        return self::respond(static fn (BoardService $service) => [
            'booking' => $service->updateDetails($payload),
        ]);
    }

    public static function createManual(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = [];
        }

        return self::respond(static fn (BoardService $service) => [
            'booking' => $service->createManual($payload),
        ]);
    }

    public static function invoice(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = [];
        }

        return self::respond(static fn (BoardService $service) => $service->issueInvoice($payload));
    }

    public static function invoicePdf(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = [];
        }

        return self::respond(static fn (BoardService $service) => $service->invoicePdf($payload));
    }

    public static function customers(WP_REST_Request $request)
    {
        $term = (string) $request->get_param('term');

        return self::respond(static fn (BoardService $service) => $service->searchCustomers($term));
    }

    public static function stats(WP_REST_Request $request)
    {
        $filters = [
            'status'    => $request->get_param('status'),
            'date_from' => $request->get_param('date_from'),
            'date_to'   => $request->get_param('date_to'),
        ];

        return self::respond(static fn (BoardService $service) => $service->stats(array_filter(
            $filters,
            static fn ($value) => $value !== null && $value !== ''
        )));
    }

    public static function export(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = [];
        }

        $filters = isset($payload['filters']) && is_array($payload['filters']) ? $payload['filters'] : [];
        $format  = isset($payload['format']) ? (string) $payload['format'] : 'csv';

        return self::respond(static fn (BoardService $service) => $service->export($filters, $format));
    }

    public static function canView(): bool
    {
        return function_exists('current_user_can') ? current_user_can('read') : true;
    }

    public static function canManage(): bool
    {
        return function_exists('current_user_can') ? current_user_can('manage_woocommerce') : true;
    }

    /**
     * @param callable(BoardService): (array<string, mixed>|WP_REST_Response) $callback
     */
    private static function respond(callable $callback)
    {
        try {
            $result = $callback(self::service());

            return function_exists('rest_ensure_response') ? rest_ensure_response($result) : $result;
        } catch (InvalidArgumentException $exception) {
            return new WP_Error('sbdp_booking_board_invalid', $exception->getMessage(), ['status' => 400]);
        } catch (\Throwable $exception) {
            return new WP_Error('sbdp_booking_board_error', $exception->getMessage(), ['status' => 500]);
        }
    }

    private static function service(): BoardService
    {
        if (self::$service === null) {
            self::$service = new BoardService();
        }

        return self::$service;
    }

    public static function resetService(): void
    {
        self::$service = null;
    }
}
