<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * SeedController — public REST endpoints for discovering place seeds.
 *
 * Public (no auth required) for the claim form frontend.
 * Returns only seeds that are not yet claimed.
 *
 * GET /bsp/v1/partner/seeds                 — search/list available seeds
 * GET /bsp/v1/partner/seeds/(?P<id>\d+)     — single seed detail
 */
final class SeedController extends WP_REST_Controller
{
    protected $namespace = 'bsp/v1';
    protected $rest_base = 'partner/seeds';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'listSeeds'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'search' => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'city' => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'unclaimed_only' => [
                        'type'    => 'boolean',
                        'default' => true,
                    ],
                    'per_page' => [
                        'type'              => 'integer',
                        'default'           => 50,
                        'minimum'           => 1,
                        'maximum'           => 200,
                        'sanitize_callback' => 'absint',
                    ],
                    'page' => [
                        'type'              => 'integer',
                        'default'           => 1,
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getSeed'],
                'permission_callback' => '__return_true',
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

    public function listSeeds(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $search       = $request->get_param('search');
        $city         = $request->get_param('city');
        $unclaimedOnly = (bool) $request->get_param('unclaimed_only');
        $perPage      = (int) $request->get_param('per_page');
        $page         = (int) $request->get_param('page');
        $offset       = ($page - 1) * $perPage;

        $seedsTable  = $wpdb->prefix . 'bsp_place_seeds';
        $claimsTable = $wpdb->prefix . 'bsp_claim_requests';

        $where  = ['s.sync_status != %s'];
        $params = ['stale'];

        if ($unclaimedOnly) {
            // Exclude seeds with any active claim.
            $where[] = "NOT EXISTS (
                SELECT 1 FROM {$claimsTable} cr
                WHERE cr.place_seed_id = s.id
                AND cr.claim_status NOT IN ('rejected', 'duplicate', 'expired')
            )";
        }

        if ($search) {
            $where[]  = '(s.name LIKE %s OR s.address LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($city) {
            $where[]  = 's.city LIKE %s';
            $params[] = '%' . $wpdb->esc_like($city) . '%';
        }

        $whereClause = implode(' AND ', $where);

        // Total count.
        $countSql = "SELECT COUNT(*) FROM {$seedsTable} s WHERE {$whereClause}";
        $total    = (int) $wpdb->get_var($wpdb->prepare($countSql, ...$params));

        // Results.
        $params[] = $perPage;
        $params[] = $offset;
        $sql      = "SELECT s.id, s.name, s.city, s.address, s.lat, s.lng, s.phone, s.website, s.categories, s.external_source
                     FROM {$seedsTable} s
                     WHERE {$whereClause}
                     ORDER BY s.name ASC
                     LIMIT %d OFFSET %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];

        // Decode categories JSON.
        foreach ($rows as &$row) {
            $row['categories'] = json_decode((string) $row['categories'], true) ?: [];
            $row['lat']        = $row['lat'] ? (float) $row['lat'] : null;
            $row['lng']        = $row['lng'] ? (float) $row['lng'] : null;
        }
        unset($row);

        $response = rest_ensure_response([
            'seeds'    => $rows,
            'total'    => $total,
            'pages'    => (int) ceil($total / $perPage),
            'page'     => $page,
            'per_page' => $perPage,
        ]);

        $response->header('X-BSP-Total', (string) $total);
        $response->header('X-BSP-Total-Pages', (string) ceil($total / $perPage));

        return $response;
    }

    public function getSeed(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $id   = (int) $request->get_param('id');
        $seed = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, city, address, lat, lng, phone, website, categories, external_source, sync_status, last_synced_at
             FROM {$wpdb->prefix}bsp_place_seeds WHERE id = %d LIMIT 1",
            $id
        ), ARRAY_A);

        if (! $seed) {
            return new WP_Error('seed_not_found', 'Locatie niet gevonden.', ['status' => 404]);
        }

        $seed['categories'] = json_decode((string) $seed['categories'], true) ?: [];
        $seed['lat']        = $seed['lat'] ? (float) $seed['lat'] : null;
        $seed['lng']        = $seed['lng'] ? (float) $seed['lng'] : null;

        return rest_ensure_response($seed);
    }
}
