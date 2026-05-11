<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Rest;

use BSP\PartnerProgram\Service\PartnerVendorIdentityService;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * CommissionController — REST endpoints for commission / settlement item data.
 *
 * Partners can view their own items.
 * Admins can view any vendor's items.
 *
 * GET /bsp/v1/partner/commissions/{vendor_id}         — list items for a vendor
 * GET /bsp/v1/partner/commissions/{vendor_id}/summary — totals per status
 */
final class CommissionController extends WP_REST_Controller
{
    protected $namespace = 'bsp/v1';
    protected $rest_base = 'partner/commissions';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<vendor_id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'listItems'],
                'permission_callback' => [$this, 'canAccessVendor'],
                'args'                => [
                    'vendor_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'status' => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_key',
                        'enum'              => ['', 'pending', 'in_review', 'approved', 'paid', 'cancelled'],
                    ],
                    'per_page' => [
                        'type'              => 'integer',
                        'default'           => 25,
                        'minimum'           => 1,
                        'maximum'           => 100,
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<vendor_id>\d+)/summary', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getSummary'],
                'permission_callback' => [$this, 'canAccessVendor'],
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
    }

    public function canAccessVendor(WP_REST_Request $request): bool|WP_Error
    {
        if (! is_user_logged_in()) {
            return new WP_Error('rest_forbidden', 'Niet ingelogd.', ['status' => 401]);
        }

        $vendorId = (int) $request->get_param('vendor_id');

        // Admins/WooCommerce managers can access any vendor.
        if (current_user_can('manage_options') || current_user_can('manage_woocommerce')) {
            return true;
        }

        // Partners can only access their own canonically resolved vendor scope.
        $myVendorId = PartnerVendorIdentityService::resolveVendorIdByUserId(get_current_user_id());
        if ($myVendorId && $myVendorId === $vendorId) {
            return true;
        }

        return new WP_Error('rest_forbidden', 'Toegang geweigerd.', ['status' => 403]);
    }

    public function listItems(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $vendorId = (int) $request->get_param('vendor_id');
        $status   = $request->get_param('status');
        $perPage  = (int) $request->get_param('per_page');
        $page     = (int) $request->get_param('page');
        $offset   = ($page - 1) * $perPage;

        $itemsTable   = $wpdb->prefix . 'bsp_settlement_items';
        $mastersTable = $wpdb->prefix . 'bsp_booking_masters';
        $batchesTable = $wpdb->prefix . 'bsp_settlement_batches';

        $where  = ['si.vendor_id = %d'];
        $params = [$vendorId];

        if ($status) {
            $where[]  = 'si.item_status = %s';
            $params[] = $status;
        }

        $whereClause = implode(' AND ', $where);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$itemsTable} si WHERE {$whereClause}",
            ...$params
        ));

        $params[] = $perPage;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT si.id,
                    si.batch_id,
                    si.vendor_id,
                    si.booking_master_id,
                    si.gross_eur,
                    si.commission_rate,
                    si.commission_eur,
                    si.payout_eur,
                    si.item_status,
                    DATE(bm.created_at) AS booking_date,
                    sb.period_label,
                    sb.batch_reference
             FROM {$itemsTable} si
             LEFT JOIN {$mastersTable} bm ON bm.id = si.booking_master_id
             LEFT JOIN {$batchesTable} sb ON sb.id = si.batch_id AND si.batch_id > 0
             WHERE {$whereClause}
             ORDER BY si.id DESC
             LIMIT %d OFFSET %d",
            ...$params
        ), ARRAY_A) ?: [];

        // Cast numeric fields.
        foreach ($rows as &$row) {
            $row['id']              = (int) $row['id'];
            $row['batch_id']        = (int) $row['batch_id'];
            $row['gross_eur']       = (float) $row['gross_eur'];
            $row['commission_rate'] = (float) $row['commission_rate'];
            $row['commission_eur']  = (float) $row['commission_eur'];
            $row['payout_eur']      = (float) $row['payout_eur'];
        }
        unset($row);

        $response = rest_ensure_response([
            'items'    => $rows,
            'total'    => $total,
            'pages'    => (int) ceil($total / $perPage),
            'page'     => $page,
            'per_page' => $perPage,
        ]);

        $response->header('X-BSP-Total', (string) $total);
        $response->header('X-BSP-Total-Pages', (string) ceil($total / $perPage));

        return $response;
    }

    public function getSummary(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $vendorId = (int) $request->get_param('vendor_id');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT item_status,
                    COUNT(*) AS item_count,
                    SUM(gross_eur) AS total_gross_eur,
                    SUM(commission_eur) AS total_commission_eur,
                    SUM(payout_eur) AS total_payout_eur
             FROM {$wpdb->prefix}bsp_settlement_items
             WHERE vendor_id = %d
             GROUP BY item_status",
            $vendorId
        ), ARRAY_A) ?: [];

        $summary = [];
        foreach ($rows as $row) {
            $summary[$row['item_status']] = [
                'item_count'           => (int) $row['item_count'],
                'total_gross_eur'      => (float) $row['total_gross_eur'],
                'total_commission_eur' => (float) $row['total_commission_eur'],
                'total_payout_eur'     => (float) $row['total_payout_eur'],
            ];
        }

        return rest_ensure_response([
            'vendor_id' => $vendorId,
            'by_status' => $summary,
        ]);
    }
}
