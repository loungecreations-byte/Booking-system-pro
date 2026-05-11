<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Rest;

use BSP\DayPlanner\Service\PlanService;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class PlansController
{
    private const RATE_LIMIT_WINDOW = 60;
    private const RATE_LIMIT_READ_MAX = 90;
    private const RATE_LIMIT_WRITE_MAX = 30;
    private const RATE_LIMIT_PREFIX = 'sbdp_planner_rest_';

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
            '/config',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$controller, 'getConfig'],
                'permission_callback' => [$controller, 'is_ready'],
            ]
        );

        \register_rest_route(
            'planner/v1',
            '/activities',
            [
                'methods'             => 'GET',
                'callback'            => [$controller, 'listActivities'],
                'permission_callback' => [$controller, 'is_ready'],
            ]
        );

        \register_rest_route(
            'planner/v1',
            '/products',
            [
                'methods'             => 'GET',
                'callback'            => [$controller, 'listProducts'],
                'permission_callback' => [$controller, 'is_ready'],
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
                'permission_callback' => [$controller, 'canEdit'],
            ]
        );

        \register_rest_route(
            'planner/v1',
            '/plan/(?P<id>\d+)/quote',
            [
                'methods'             => 'POST',
                'callback'            => [$controller, 'requestQuote'],
                'permission_callback' => [$controller, 'canEdit'],
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
            $items   = array_values(
                array_map([$this, 'sanitizePublicCatalogItem'], $items)
            );

            return [
                'items' => $items,
                'products' => $items,
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

    public function listProducts(WP_REST_Request $request)
    {
        return $this->respond(function () use ($request): array {
            $filters = $request->get_params();
            $items   = $this->service->listProducts(is_array($filters) ? $filters : []);
            $items   = array_values(
                array_map([$this, 'sanitizePublicCatalogItem'], $items)
            );

            return [
                'products' => $items,
                'meta'     => [
                    'cached' => false,
                    'total'  => count($items),
                ],
            ];
        });
    }

    public function getConfig(WP_REST_Request $request)
    {
        unset($request);

        return $this->respond(function (): array {
            return [
                'config' => $this->service->getSettings(),
            ];
        });
    }

    public function getPlan(WP_REST_Request $request)
    {
        return $this->respond(function () use ($request): array {
            $planId = $this->planIdFromRequest($request);
            $plan   = $this->service->getPlan($planId);

            if (! $this->hasEditAccess($request)) {
                if (isset($plan['meta']) && is_array($plan['meta'])) {
                    unset($plan['meta']['edit_token']);
                }
                if (isset($plan['edit_token'])) {
                    unset($plan['edit_token']);
                }
            }

            return [
                'plan' => $plan,
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

    public function requestQuote(WP_REST_Request $request)
    {
        return $this->respond(function () use ($request): array {
            $planId = $this->planIdFromRequest($request);

            return $this->service->requestQuote($planId);
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

    public function is_ready(WP_REST_Request $request)
    {
        if (! $this->hasValidNonce($request)) {
            return false;
        }

        return $this->checkRateLimit($request, self::RATE_LIMIT_READ_MAX);
    }

    public function canEdit(WP_REST_Request $request)
    {
        $rateLimit = $this->checkRateLimit($request, self::RATE_LIMIT_WRITE_MAX);
        if ($rateLimit instanceof WP_Error) {
            return $rateLimit;
        }

        return $this->hasEditAccess($request);
    }

    private function hasEditAccess(WP_REST_Request $request): bool
    {
        $planId = $this->maybePlanId($request);
        $nonceIsValid = $this->hasValidNonce($request);

        if (\is_user_logged_in()) {
            if (! $nonceIsValid) {
                return false;
            }

            if (\current_user_can('manage_options') || \current_user_can('planner_manage')) {
                return true;
            }

            if ($planId === null) {
                return \current_user_can('edit_posts');
            }

            try {
                $plan = $this->service->getPlanMeta($planId);
            } catch (\Throwable $exception) {
                return false;
            }

            $ownerId = (int) ($plan['owner'] ?? 0);
            if ($ownerId === (int) \get_current_user_id()) {
                return true;
            }

            return \current_user_can('edit_others_posts');
        }

        if (! $nonceIsValid) {
            return false;
        }

        if ($planId === null) {
            return true;
        }

        try {
            $plan = $this->service->getPlanMeta($planId);
        } catch (\Throwable $exception) {
            return false;
        }

        $planToken    = $this->resolvePlanEditToken($plan);
        $requestToken = $this->extractEditToken($request);

        if ($planToken !== null && $requestToken !== null && hash_equals($planToken, $requestToken)) {
            return true;
        }

        return false;
    }

    public function canView(WP_REST_Request $request)
    {
        $rateLimit = $this->checkRateLimit($request, self::RATE_LIMIT_READ_MAX);
        if ($rateLimit instanceof WP_Error) {
            return $rateLimit;
        }

        return $this->hasViewAccess($request);
    }

    private function hasViewAccess(WP_REST_Request $request): bool
    {
        $planId = $this->maybePlanId($request);
        if ($planId === null) {
            return $this->hasValidNonce($request);
        }

        try {
            $plan = $this->service->getPlanMeta($planId);
        } catch (\Throwable $exception) {
            return false;
        }

        $planToken    = $this->resolvePlanEditToken($plan);
        $requestToken = $this->extractEditToken($request);
        if ($planToken !== null && $requestToken !== null && hash_equals($planToken, $requestToken)) {
            return true;
        }

        $shareKey = $this->extractShareKey($request);
        $planKey  = isset($plan['shared_key']) ? (string) $plan['shared_key'] : '';

        if ($shareKey !== null && $planKey !== '' && hash_equals($planKey, $shareKey)) {
            return true;
        }

        if (! \is_user_logged_in()) {
            return false;
        }

        if (! $this->hasValidNonce($request)) {
            return false;
        }

        $ownerId = (int) ($plan['owner'] ?? 0);
        if ($ownerId === (int) \get_current_user_id()) {
            return true;
        }

        return \current_user_can('edit_others_posts') || \current_user_can('planner_manage');
    }

    private function hasValidNonce(WP_REST_Request $request): bool
    {
        $nonce = $request->get_header('X-WP-Nonce');
        if (is_string($nonce) && $nonce !== '' && \function_exists('wp_verify_nonce') && wp_verify_nonce($nonce, 'wp_rest')) {
            return true;
        }

        $publicNonce = $request->get_header('x-sbdp-nonce');
        if (! is_string($publicNonce) || $publicNonce === '') {
            $publicNonce = $request->get_header('X-WP-Nonce');
        }

        return is_string($publicNonce)
            && $publicNonce !== ''
            && \function_exists('wp_verify_nonce')
            && wp_verify_nonce($publicNonce, \BSPModule\Core\Rest\RestService::PUBLIC_NONCE_ACTION);
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

        return is_array($params) ? $this->sanitizeRecursive($params) : [];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function sanitizePublicCatalogItem(array $item): array
    {
        unset($item['availability_windows']);

        if (isset($item['resources']) && is_array($item['resources'])) {
            $item['resources'] = array_values(
                array_filter(
                    array_map(
                        static function ($resource): ?array {
                            if (! is_array($resource)) {
                                return null;
                            }

                            $sanitized = array(
                                'id'    => isset($resource['id']) ? (int) $resource['id'] : 0,
                                'title' => isset($resource['title']) ? sanitize_text_field((string) $resource['title']) : '',
                            );

                            if (isset($resource['primary'])) {
                                $sanitized['primary'] = (bool) $resource['primary'];
                            }

                            return $sanitized;
                        },
                        $item['resources']
                    )
                )
            );
        }

        return $item;
    }

    /**
     * @return true|WP_Error
     */
    private function checkRateLimit(WP_REST_Request $request, int $maxAttempts)
    {
        $bucket = $this->rateLimitKey($request);
        $state = get_transient($bucket);
        $state = is_array($state) ? $state : [];
        $attempts = (int) ($state['attempts'] ?? 0) + 1;

        if ($attempts > $maxAttempts) {
            return new WP_Error(
                'sbdp_rate_limited',
                __('Te veel verzoeken. Probeer het zo opnieuw.', 'sbdp'),
                ['status' => 429]
            );
        }

        set_transient(
            $bucket,
            ['attempts' => $attempts],
            self::RATE_LIMIT_WINDOW
        );

        return true;
    }

    private function rateLimitKey(WP_REST_Request $request): string
    {
        $route = trim((string) $request->get_route(), '/');
        $method = strtoupper((string) $request->get_method());
        $identity = implode('|', [
            $this->requestIp(),
            (string) (\function_exists('get_current_user_id') ? \get_current_user_id() : 0),
            $this->resolveRateLimitNonce($request),
        ]);

        return self::RATE_LIMIT_PREFIX . md5($route . '|' . $method . '|' . $identity);
    }

    private function resolveRateLimitNonce(WP_REST_Request $request): string
    {
        $publicNonce = $request->get_header('x-sbdp-nonce');
        if (! is_string($publicNonce) || $publicNonce === '') {
            $publicNonce = $request->get_header('X-WP-Nonce');
        }

        return is_string($publicNonce) ? sanitize_text_field($publicNonce) : '';
    }

    private function requestIp(): string
    {
        $trustedProxy = (bool) apply_filters('sbdp/rest/trust_forwarded_ip', false);
        $headers = $trustedProxy
            ? ['HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR']
            : ['REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (empty($_SERVER[$header]) || ! is_string($_SERVER[$header])) {
                continue;
            }

            $candidate = (string) $_SERVER[$header];
            if ('HTTP_X_FORWARDED_FOR' === $header) {
                $parts = explode(',', $candidate);
                $candidate = trim((string) ($parts[0] ?? ''));
            }

            $candidate = sanitize_text_field($candidate);
            if ('' !== $candidate && filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return 'unknown';
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeRecursive($value, int $depth = 0)
    {
        if ($depth > 8) {
            return null;
        }

        if (is_array($value)) {
            $sanitised = [];
            foreach ($value as $key => $item) {
                $safeKey = is_string($key)
                    ? sanitize_key($key)
                    : (is_int($key) ? $key : null);
                if ($safeKey === null || $safeKey === '') {
                    continue;
                }

                $sanitised[$safeKey] = $this->sanitizeRecursive($item, $depth + 1);
            }

            return $sanitised;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            return sanitize_text_field($value);
        }

        return null;
    }

    private function maybePlanId(WP_REST_Request $request): ?int
    {
        $raw = $request->get_param('id');
        if ($raw === null) {
            return null;
        }

        $planId = (int) $raw;

        return $planId > 0 ? $planId : null;
    }

    private function resolvePlanEditToken(array $plan): ?string
    {
        $token = null;

        if (isset($plan['meta']) && is_array($plan['meta']) && isset($plan['meta']['edit_token'])) {
            $token = $plan['meta']['edit_token'];
        } elseif (isset($plan['edit_token'])) {
            $token = $plan['edit_token'];
        }

        if (! is_string($token)) {
            return null;
        }

        $token = trim($token);

        return $token !== '' ? $token : null;
    }

    private function extractEditToken(WP_REST_Request $request): ?string
    {
        $candidates = [
            $request->get_param('edit_token'),
            $request->get_param('token'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        $headers = $request->get_headers();
        if (isset($headers['x-sbdp-plan-token'][0])) {
            $headerToken = trim((string) $headers['x-sbdp-plan-token'][0]);
            if ($headerToken !== '') {
                return $headerToken;
            }
        }

        $payload = $request->get_json_params();
        if (is_array($payload)) {
            if (isset($payload['edit_token']) && is_string($payload['edit_token'])) {
                $fromBody = trim($payload['edit_token']);
                if ($fromBody !== '') {
                    return $fromBody;
                }
            }

            if (isset($payload['meta']) && is_array($payload['meta'])) {
                $metaToken = $payload['meta']['edit_token'] ?? null;
                if (is_string($metaToken)) {
                    $metaToken = trim($metaToken);
                    if ($metaToken !== '') {
                        return $metaToken;
                    }
                }
            }
        }

        return null;
    }

    private function extractShareKey(WP_REST_Request $request): ?string
    {
        $key = $request->get_param('shared_key');

        if ($key === null) {
            $key = $request->get_param('key');
        }

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key !== '' ? $key : null;
    }

}
