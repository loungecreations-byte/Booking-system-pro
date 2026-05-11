<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Rest;

use BSP\PartnerProgram\Service\SettlementService;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * SettlementController — admin REST endpoints for payout batch management.
 *
 * POST /bsp/v1/partner/settlements/batch        — create a new batch
 * POST /bsp/v1/partner/settlements/batch/{id}/approve  — approve a batch
 * GET  /bsp/v1/partner/settlements/batches      — list all batches
 * GET  /bsp/v1/partner/settlements/batch/{id}/items    — items in a batch
 * GET  /bsp/v1/partner/settlements/batch/{id}/summary  — per-vendor summary
 *
 * All endpoints require manage_options (admin only).
 */
final class SettlementController extends WP_REST_Controller
{
    protected $namespace = 'bsp/v1';
    protected $rest_base = 'partner/settlements';

    public function register_routes(): void
    {
        // Create batch.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/batch', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'createBatch'],
                'permission_callback' => [$this, 'isAdmin'],
                'args'                => [
                    'period_label' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'period_start' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'period_end' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        // Approve batch.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/batch/(?P<id>\d+)/approve', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'approveBatch'],
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

        // List batches.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/batches', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'listBatches'],
                'permission_callback' => [$this, 'isAdmin'],
                'args'                => [
                    'limit' => [
                        'type'              => 'integer',
                        'default'           => 50,
                        'minimum'           => 1,
                        'maximum'           => 200,
                        'sanitize_callback' => 'absint',
                    ],
                    'offset' => [
                        'type'              => 'integer',
                        'default'           => 0,
                        'minimum'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        // Items in a batch.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/batch/(?P<id>\d+)/items', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getBatchItems'],
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

        // Per-vendor summary for a batch.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/batch/(?P<id>\d+)/summary', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getBatchSummary'],
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

        // Payout export for a batch (includes IBAN per vendor).
        register_rest_route($this->namespace, '/' . $this->rest_base . '/batch/(?P<id>\d+)/export', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getPayoutExport'],
                'permission_callback' => [$this, 'isAdmin'],
                'args'                => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'format' => [
                        'type'              => 'string',
                        'default'           => 'json',
                        'enum'              => ['json', 'csv'],
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ],
        ]);
    }

    public function createBatch(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = SettlementService::createBatch(
            (string) $request->get_param('period_label'),
            (string) $request->get_param('period_start'),
            (string) $request->get_param('period_end'),
            get_current_user_id()
        );

        if (! $result['success']) {
            return new WP_Error('batch_failed', $result['message'], ['status' => 422]);
        }

        return rest_ensure_response($result);
    }

    public function approveBatch(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $batchId = (int) $request->get_param('id');
        $result  = SettlementService::approveBatch($batchId, get_current_user_id());

        if (! $result['success']) {
            return new WP_Error('approve_failed', $result['message'], ['status' => 422]);
        }

        return rest_ensure_response($result);
    }

    public function listBatches(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $batches = SettlementService::listBatches(
            (int) $request->get_param('limit'),
            (int) $request->get_param('offset')
        );

        return rest_ensure_response(['batches' => $batches]);
    }

    public function getBatchItems(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $batchId = (int) $request->get_param('id');
        $items   = SettlementService::getBatchItems($batchId);

        return rest_ensure_response([
            'batch_id' => $batchId,
            'items'    => $items,
        ]);
    }

    public function getBatchSummary(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $batchId = (int) $request->get_param('id');
        $summary = SettlementService::getBatchVendorSummary($batchId);

        return rest_ensure_response([
            'batch_id' => $batchId,
            'summary'  => $summary,
        ]);
    }

    public function getPayoutExport(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $batchId = (int) $request->get_param('id');
        $format  = (string) $request->get_param('format');

        global $wpdb;
        $batch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bsp_settlement_batches WHERE id = %d LIMIT 1",
            $batchId
        ), ARRAY_A);

        if (! $batch) {
            return new WP_Error('batch_not_found', 'Batch niet gevonden.', ['status' => 404]);
        }

        $summary = SettlementService::getBatchVendorSummary($batchId);

        if ($format === 'csv') {
            return $this->payoutExportAsCsv($batch, $summary);
        }

        return rest_ensure_response([
            'batch_id'        => $batchId,
            'batch_reference' => $batch['batch_reference'],
            'period_label'    => $batch['period_label'],
            'period_start'    => $batch['period_start'],
            'period_end'      => $batch['period_end'],
            'batch_status'    => $batch['batch_status'],
            'total_payout_eur' => (float) $batch['total_payout_eur'],
            'vendors'         => array_map(static function (array $row): array {
                return [
                    'vendor_id'           => (int) $row['vendor_id'],
                    'vendor_name'         => $row['vendor_name'] ?? '',
                    'contact_email'       => $row['contact_email'] ?? '',
                    'account_holder_name' => $row['account_holder_name'] ?? '',
                    'iban'                => $row['iban'] ?? '',
                    'item_count'          => (int) $row['item_count'],
                    'total_gross_eur'     => (float) $row['total_gross_eur'],
                    'total_commission_eur' => (float) $row['total_commission_eur'],
                    'total_payout_eur'    => (float) $row['total_payout_eur'],
                ];
            }, $summary),
        ]);
    }

    private function payoutExportAsCsv(array $batch, array $summary): WP_REST_Response
    {
        $lines   = [];
        $lines[] = implode(',', [
            'vendor_id', 'vendor_name', 'contact_email',
            'account_holder_name', 'iban',
            'item_count', 'total_gross_eur', 'total_commission_eur', 'total_payout_eur',
        ]);

        foreach ($summary as $row) {
            $lines[] = implode(',', [
                (int) $row['vendor_id'],
                '"' . str_replace('"', '""', $row['vendor_name'] ?? '') . '"',
                '"' . str_replace('"', '""', $row['contact_email'] ?? '') . '"',
                '"' . str_replace('"', '""', $row['account_holder_name'] ?? '') . '"',
                '"' . str_replace('"', '""', $row['iban'] ?? '') . '"',
                (int) $row['item_count'],
                number_format((float) $row['total_gross_eur'], 2, '.', ''),
                number_format((float) $row['total_commission_eur'], 2, '.', ''),
                number_format((float) $row['total_payout_eur'], 2, '.', ''),
            ]);
        }

        $csv      = implode("\n", $lines);
        $ref      = preg_replace('/[^A-Za-z0-9_-]/', '_', $batch['batch_reference'] ?? (string) $batch['id']);
        $filename = 'payout-export-' . $ref . '.csv';

        $response = new WP_REST_Response($csv, 200);
        $response->header('Content-Type', 'text/csv; charset=utf-8');
        $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    // ---------------------------------------------------------------------------
    // Permission callbacks
    // ---------------------------------------------------------------------------

    public function isAdmin(): bool
    {
        return is_user_logged_in() && current_user_can('manage_options');
    }
}
