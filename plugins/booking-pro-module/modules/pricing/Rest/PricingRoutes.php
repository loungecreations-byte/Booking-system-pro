<?php

declare(strict_types=1);

namespace SBDP\Modules\Pricing\Rest;

use SBDP\Modules\Pricing\Services\PricingService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class PricingRoutes
{
    private const RATE_LIMIT_WINDOW = 60;
    private const RATE_LIMIT_MAX = 30;
    private const RATE_LIMIT_PREFIX = 'sbdp_pricing_quote_';

    public function __construct(private PricingService $service)
    {
    }

    public function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            'booking/v1',
            '/pricing/quote',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'quote'),
                    'permission_callback' => array($this, 'authorize_quote'),
                    'args'                => array(
                        'product_id' => array(
                            'required'          => true,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ),
                        'quantity'   => array(
                            'required'          => false,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                            'default'           => 1,
                        ),
                        'channel'    => array(
                            'required' => false,
                            'type'     => 'string',
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            'booking/v1',
            '/pricing/presets',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'list_presets'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'apply_preset'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'booking/v1',
            '/pricing/rules',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'list_rules'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'create_rule'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'booking/v1',
            '/pricing/log',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_log'),
                    'permission_callback' => array($this, 'can_manage'),
                    'args'                => array(
                        'product_id' => array(
                            'required'          => false,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ),
                    ),
                ),
            )
        );
    }

    public function quote(WP_REST_Request $request)
    {
        $product_id = (int) $request->get_param('product_id');
        $quantity   = (int) $request->get_param('quantity');
        $channel    = $request->get_param('channel');

        $result = $this->service->quote(
            $product_id,
            $quantity > 0 ? $quantity : 1,
            array(
                'channel' => is_string($channel) ? sanitize_text_field($channel) : 'web',
            )
        );

        if (! ($result['success'] ?? false)) {
            $error = $result['error'] ?? null;

            return $error instanceof WP_Error
                ? $error
                : new WP_Error('sbdp_pricing_quote_failed', __('Unable to produce quote.', 'sbdp'));
        }

        return new WP_REST_Response($result);
    }

    public function list_presets(): WP_REST_Response
    {
        return new WP_REST_Response(
            array(
                'presets' => $this->service->listPresets(),
            )
        );
    }

    public function apply_preset(WP_REST_Request $request)
    {
        $payload = json_decode((string) $request->get_body(), true);
        if (! is_array($payload)) {
            return new WP_Error('sbdp_pricing_invalid_payload', __('Invalid payload.', 'sbdp'), array('status' => 400));
        }

        $result = $this->service->applyPreset($payload);

        return $result instanceof WP_Error
            ? $result
            : new WP_REST_Response($result);
    }

    public function list_rules(): WP_REST_Response
    {
        return new WP_REST_Response(
            array(
                'rules' => $this->service->listRules(),
            )
        );
    }

    public function create_rule(WP_REST_Request $request)
    {
        $payload = json_decode((string) $request->get_body(), true);
        if (! is_array($payload)) {
            return new WP_Error('sbdp_pricing_invalid_payload', __('Invalid payload.', 'sbdp'), array('status' => 400));
        }

        $result = $this->service->createRule($payload);

        return $result instanceof WP_Error
            ? $result
            : new WP_REST_Response($result);
    }

    public function get_log(WP_REST_Request $request): WP_REST_Response
    {
        $product_id = (int) $request->get_param('product_id');

        return new WP_REST_Response(
            array(
                'log' => $this->service->getLog(
                    array(
                        'product_id' => $product_id,
                    )
                ),
            )
        );
    }

    public function can_manage(): bool
    {
        return $this->service->canManagePricing();
    }

    /**
     * @return true|WP_Error
     */
    public function authorize_quote(WP_REST_Request $request)
    {
        unset($request);

        $bucket = self::RATE_LIMIT_PREFIX . md5($this->request_ip());
        $state = get_transient($bucket);
        $state = is_array($state) ? $state : array();
        $attempts = (int) ($state['attempts'] ?? 0) + 1;

        if ($attempts > self::RATE_LIMIT_MAX) {
            return new WP_Error('sbdp_pricing_rate_limited', __('Too many quote requests.', 'sbdp'), array('status' => 429));
        }

        set_transient($bucket, array('attempts' => $attempts), self::RATE_LIMIT_WINDOW);

        return true;
    }

    private function request_ip(): string
    {
        $candidates = array(
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        );

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || '' === $candidate) {
                continue;
            }

            $parts = explode(',', $candidate);
            $ip = trim((string) ($parts[0] ?? ''));
            if ('' !== $ip) {
                return $ip;
            }
        }

        return 'unknown';
    }
}
