<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Rest;

use BSP\DayPlanner\Service\PlanService;
use RuntimeException;
use WP_Error;
use WP_REST_Request;

final class PlansController
{
    private PlanService $service;

    public function __construct(PlanService $service)
    {
        $this->service = $service;
    }

    public static function register(PlanService $service): void
    {
        if (! \function_exists('register_rest_route')) {
            return;
        }

        $controller = new self($service);

        \register_rest_route(
            'planner/v1',
            '/activities',
            [
                'methods'             => 'GET',
                'callback'            => [$controller, 'listActivities'],
                'permission_callback' => '__return_true',
            ]
        );

        \register_rest_route(
            'planner/v1',
            '/plan',
            [
                'methods'             => 'POST',
                'callback'            => [$controller, 'createPlan'],
                'permission_callback' => [$controller, 'canEdit'],
            ]
        );

        \register_rest_route(
            'planner/v1',
            '/plan/(?P<id>\d+)',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$controller, 'getPlan'],
                    'permission_callback' => [$controller, 'canView'],
                ],
                [
                    'methods'             => 'PATCH',
                    'callback'            => [$controller, 'updatePlan'],
                    'permission_callback' => [$controller, 'canEdit'],
                ],
            ]
        );

        \register_rest_route(
            'planner/v1',
            '/plan/(?P<id>\d+)/share',
            [
                'methods'             => 'POST',
                'callback'            => [$controller, 'sharePlan'],
                'permission_callback' => [$controller, 'canView'],
            ]
        );

        \register_rest_route(
            'planner/v1',
            '/plan/(?P<id>\d+)/book',
            [
                'methods'             => 'POST',
                'callback'            => [$controller, 'bookPlan'],
                'permission_callback' => [$controller, 'canEdit'],
            ]
        );

        \register_rest_route(
            'planner/v1',
            '/plan/(?P<id>\d+)/export/pdf',
            [
                'methods'             => 'POST',
                'callback'            => [$controller, 'exportPdf'],
                'permission_callback' => [$controller, 'canView'],
            ]
        );

        \register_rest_route(
            'planner/v1',
            '/plan/(?P<id>\d+)/export/ics',
            [
                'methods'             => 'POST',
                'callback'            => [$controller, 'exportIcs'],
                'permission_callback' => [$controller, 'canView'],
            ]
        );

        \register_rest_route(
            'planner/v1',
            '/plan/ai/suggest',
            [
                'methods'             => 'POST',
                'callback'            => [$controller, 'suggestActivities'],
                'permission_callback' => [$controller, 'canEdit'],
            ]
        );

        \register_rest_route(
            'planner/v1',
            '/plan/conflicts',
            [
                'methods'             => 'POST',
                'callback'            => [$controller, 'detectConflicts'],
                'permission_callback' => [$controller, 'canEdit'],
            ]
        );
    }

    public function listActivities(WP_REST_Request $request)
    {
        return $this->respond(function () use ($request): array {
            $filters = $request->get_params();
            $items   = $this->service->listActivities(is_array($filters) ? $filters : []);

            return [
                'items' => $items,
                'meta'  => [
                    'cached' => false,
                    'total'  => count($items),
                ],
            ];
        });
    }

    public function createPlan(WP_REST_Request $request)
    {
        return $this->respond(function () use ($request): array {
            $payload = $this->sanitizeJson($request);

            $userId = \function_exists('get_current_user_id') ? (int) \get_current_user_id() : 0;

            return [
                'plan' => $this->service->createPlan($payload, $userId),
            ];
        });
    }

    public function getPlan(WP_REST_Request $request)
    {
        return $this->respond(function () use ($request): array {
            return [
                'plan' => $this->service->getPlan($this->planIdFromRequest($request)),
            ];
        });
    }

    public function updatePlan(WP_REST_Request $request)
    {
        return $this->respond(function () use ($request): array {
            $payload = $this->sanitizeJson($request);

            return [
                'plan' => $this->service->updatePlan(
                    $this->planIdFromRequest($request),
                    $payload
                ),
            ];
        });
    }

    public function sharePlan(WP_REST_Request $request)
    {
        return $this->respond(function () use ($request): array {
            $planId = $this->planIdFromRequest($request);

            return $this->service->sharePlan($planId);
        });
    }

    public function bookPlan(WP_REST_Request $request)
    {
        return $this->respond(function () use ($request): array {
            $planId = $this->planIdFromRequest($request);

            return $this->service->queueBooking($planId);
        });
    }

    public function exportPdf(WP_REST_Request $request)
    {
        return $this->respond(function () use ($request): array {
            $planId = $this->planIdFromRequest($request);

            return $this->service->scheduleExport($planId, 'pdf');
        });
    }

    public function exportIcs(WP_REST_Request $request)
    {
        return $this->respond(function () use ($request): array {
            $planId = $this->planIdFromRequest($request);

            return $this->service->scheduleExport($planId, 'ics');
        });
    }

    public function suggestActivities(WP_REST_Request $request)
    {
        return $this->respond(function () use ($request): array {
            return $this->service->suggestActivities(
                $this->sanitizeJson($request)
            );
        });
    }

    public function detectConflicts(WP_REST_Request $request)
    {
        return $this->respond(function () use ($request): array {
            $payload = $this->sanitizeJson($request);

            return [
                'conflicts' => $this->service->detectConflicts($payload),
            ];
        });
    }

    public function canEdit(): bool
    {
        return $this->currentUserCan(['manage_woocommerce', 'edit_posts']);
    }

    public function canView(): bool
    {
        return $this->currentUserCan(['read', 'manage_woocommerce']);
    }

    /**
     * @param callable():array<string,mixed> $callback
     */
    private function respond(callable $callback)
    {
        try {
            $result = $callback();

            return \rest_ensure_response($result);
        } catch (RuntimeException $exception) {
            return new WP_Error('sbdp_day_planner_error', $exception->getMessage(), ['status' => 400]);
        } catch (\Throwable $exception) {
            return new WP_Error('sbdp_day_planner_failure', $exception->getMessage(), ['status' => 500]);
        }
    }

    private function planIdFromRequest(WP_REST_Request $request): int
    {
        $planId = (int) $request->get_param('id');
        if ($planId <= 0) {
            throw new RuntimeException(__('Invalid plan identifier.', 'sbdp'));
        }

        return $planId;
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeJson(WP_REST_Request $request): array
    {
        $params = $request->get_json_params();

        return is_array($params) ? $params : [];
    }

    /**
     * @param string[] $capabilities
     */
    private function currentUserCan(array $capabilities): bool
    {
        if (! \function_exists('current_user_can')) {
            return true;
        }

        foreach ($capabilities as $capability) {
            if (\current_user_can($capability)) {
                return true;
            }
        }

        return false;
    }
}
