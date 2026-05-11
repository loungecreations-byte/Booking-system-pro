<?php

declare(strict_types=1);

namespace BSP\Planner\Rest\Planboard;

use BSP\Planner\Services\Planboard\PlanboardBookingService;
use BSP\Planner\Services\Planboard\PlanboardPermissions;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

final class PlanboardBookingController extends WP_REST_Controller
{
    public function __construct(private PlanboardBookingService $service)
    {
        $this->namespace = 'bsp/v2';
        $this->rest_base = 'planboard/bookings';
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array($this, 'create_item'),
                    'permission_callback' => array($this, 'can_create'),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/move',
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array($this, 'move'),
                    'permission_callback' => array($this, 'can_move'),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/checkin',
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array($this, 'checkin'),
                    'permission_callback' => array($this, 'can_checkin'),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/payment',
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array($this, 'add_payment'),
                    'permission_callback' => array($this, 'can_add_payment'),
                ),
            )
        );
    }

    public function create_item($request)
    {
        if (! $request instanceof WP_REST_Request) {
            return new WP_Error('sbdp_planboard_invalid_request', __('Invalid request.', 'sbdp'), array('status' => 400));
        }

        return $this->wrap(function () use ($request) {
            $payload = $this->getJson($request);
            return $this->service->create($payload);
        });
    }

    public function move($request)
    {
        if (! $request instanceof WP_REST_Request) {
            return new WP_Error('sbdp_planboard_invalid_request', __('Invalid request.', 'sbdp'), array('status' => 400));
        }

        return $this->wrap(function () use ($request) {
            $payload = $this->getJson($request);
            return $this->service->move($payload);
        });
    }

    public function checkin($request)
    {
        if (! $request instanceof WP_REST_Request) {
            return new WP_Error('sbdp_planboard_invalid_request', __('Invalid request.', 'sbdp'), array('status' => 400));
        }

        return $this->wrap(function () use ($request) {
            $payload = $this->getJson($request);
            return $this->service->checkin($payload);
        });
    }

    public function add_payment($request)
    {
        if (! $request instanceof WP_REST_Request) {
            return new WP_Error('sbdp_planboard_invalid_request', __('Invalid request.', 'sbdp'), array('status' => 400));
        }

        return $this->wrap(function () use ($request) {
            $payload = $this->getJson($request);
            return $this->service->addPayment($payload);
        });
    }

    public function can_move(): bool
    {
        return PlanboardPermissions::canMove();
    }

    public function can_create(): bool
    {
        return PlanboardPermissions::canCreate();
    }

    public function can_checkin(): bool
    {
        return PlanboardPermissions::canCheckin();
    }

    public function can_add_payment(): bool
    {
        return PlanboardPermissions::canAddPayment();
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();
        return is_array($payload) ? $payload : array();
    }

    /**
     * @param callable(): (array<string, mixed>|WP_Error) $callback
     *
     * @return WP_REST_Response|WP_Error
     */
    private function wrap(callable $callback)
    {
        try {
            $result = $callback();
            if ($result instanceof WP_Error) {
                return $result;
            }

            return new WP_REST_Response($result);
        } catch (InvalidArgumentException $exception) {
            return new WP_Error('sbdp_planboard_invalid', $exception->getMessage(), array('status' => 400));
        } catch (\Throwable $exception) {
            return new WP_Error('sbdp_planboard_error', $exception->getMessage(), array('status' => 500));
        }
    }
}
