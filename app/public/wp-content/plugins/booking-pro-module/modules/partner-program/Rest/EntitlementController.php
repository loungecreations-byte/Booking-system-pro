<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Rest;

use BSP\PartnerProgram\Service\EntitlementService;
use BSP\PartnerProgram\Service\PartnerVendorIdentityService;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * EntitlementController — REST endpoints for partner entitlement queries.
 *
 * GET /bsp/v1/partner/entitlements/{vendorId}         — full entitlement map for a vendor
 * GET /bsp/v1/partner/entitlements/{vendorId}/{key}   — single entitlement value
 *
 * Access: vendor can query their own; admin can query all.
 * Entitlements are the canonical gate — never read from WC subscription directly.
 */
final class EntitlementController extends WP_REST_Controller
{
    protected $namespace = 'bsp/v1';
    protected $rest_base = 'partner/entitlements';

    public function register_routes(): void
    {
        // Full entitlement map for a vendor.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<vendor_id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getAll'],
                'permission_callback' => [$this, 'canReadVendor'],
                'args'                => [
                    'vendor_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        // Single key lookup.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<vendor_id>\d+)/(?P<key>[a-z0-9_]+)',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$this, 'getSingle'],
                    'permission_callback' => [$this, 'canReadVendor'],
                    'args'                => [
                        'vendor_id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'minimum'           => 1,
                            'sanitize_callback' => 'absint',
                        ],
                        'key' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_key',
                        ],
                    ],
                ],
            ]
        );
    }

    public function getAll(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $vendorId    = (int) $request->get_param('vendor_id');
        $entitlements = EntitlementService::getAll($vendorId);
        $tier         = EntitlementService::getTier($vendorId);

        return rest_ensure_response([
            'vendor_id'    => $vendorId,
            'tier'         => $tier,
            'entitlements' => $entitlements,
        ]);
    }

    public function getSingle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $vendorId = (int) $request->get_param('vendor_id');
        $key      = (string) $request->get_param('key');
        $value    = EntitlementService::get($vendorId, $key, null);

        return rest_ensure_response([
            'vendor_id' => $vendorId,
            'key'       => $key,
            'value'     => $value,
        ]);
    }

    // ---------------------------------------------------------------------------
    // Permission callbacks
    // ---------------------------------------------------------------------------

    /**
     * Allow if admin OR if the current user is the vendor user.
     */
    public function canReadVendor(WP_REST_Request $request): bool
    {
        if (! is_user_logged_in()) {
            return false;
        }

        if (current_user_can('manage_options')) {
            return true;
        }

        $vendorId = (int) $request->get_param('vendor_id');
        $myVendorId = PartnerVendorIdentityService::resolveVendorIdByUserId(get_current_user_id());

        return $myVendorId > 0 && $myVendorId === $vendorId;
    }
}
