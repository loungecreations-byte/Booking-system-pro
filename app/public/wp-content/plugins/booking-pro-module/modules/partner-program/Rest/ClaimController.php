<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Rest;

use BSP\PartnerProgram\Service\ClaimService;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * ClaimController — REST endpoints for partner claim workflow.
 *
 * POST /bsp/v1/partner/claim         — submit a new claim
 * POST /bsp/v1/partner/verify        — verify claim via email token
 * POST /bsp/v1/partner/claim/{id}/approve — admin approves a claim (requires manage_options)
 */
final class ClaimController extends WP_REST_Controller
{
    protected $namespace = 'bsp/v1';
    protected $rest_base = 'partner';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/claim', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'submitClaim'],
                'permission_callback' => [$this, 'isLoggedIn'],
                'args'                => [
                    'place_seed_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/verify', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'verifyClaim'],
                'permission_callback' => '__return_true', // token-based, no auth required
                'args'                => [
                    'token' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/claim/(?P<id>\d+)/approve', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'adminApproveClaim'],
                'permission_callback' => [$this, 'isAdmin'],
                'args'                => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);
    }

    public function submitClaim(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $placeSeedId = (int) $request->get_param('place_seed_id');
        $userId      = get_current_user_id();

        $result = ClaimService::submitClaim($placeSeedId, $userId);

        if (! $result['success']) {
            return new WP_Error('claim_failed', $result['message'], ['status' => 422]);
        }

        return rest_ensure_response([
            'success'  => true,
            'message'  => $result['message'],
            'claim_id' => $result['claim_id'] ?? null,
        ]);
    }

    public function verifyClaim(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $token  = (string) $request->get_param('token');
        $result = ClaimService::verifyClaim($token);

        if (! $result['success']) {
            return new WP_Error('verify_failed', $result['message'], ['status' => 422]);
        }

        return rest_ensure_response([
            'success'  => true,
            'message'  => $result['message'],
            'claim_id' => $result['claim_id'] ?? null,
        ]);
    }

    public function adminApproveClaim(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $claimId = (int) $request->get_param('id');
        $adminId = get_current_user_id();

        $result = ClaimService::adminApproveClaim($claimId, $adminId);

        if (! $result['success']) {
            return new WP_Error('approve_failed', $result['message'], ['status' => 422]);
        }

        return rest_ensure_response([
            'success'            => true,
            'message'            => $result['message'],
            'partner_account_id' => $result['partner_account_id'] ?? null,
        ]);
    }

    // ---------------------------------------------------------------------------
    // Permission callbacks
    // ---------------------------------------------------------------------------

    public function isLoggedIn(): bool
    {
        return is_user_logged_in();
    }

    public function isAdmin(): bool
    {
        return current_user_can('manage_options');
    }
}
