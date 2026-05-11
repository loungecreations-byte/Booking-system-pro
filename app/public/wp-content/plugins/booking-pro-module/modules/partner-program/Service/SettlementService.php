<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use wpdb;
use function current_time;
use function get_userdata;
use function wp_mail;
use function sanitize_text_field;
use function sanitize_email;
use function home_url;

/**
 * SettlementService — creates and manages monthly payout batches.
 *
 * Flow:
 *   1. Admin runs createBatch() — collects all pending settlement items into a batch
 *   2. Admin reviews the batch — per-item data visible via getBatchItems()
 *   3. Admin calls approveBatch() — batch_status → 'approved', items → 'approved'
 *   4. Finance exports or triggers payouts externally
 *
 * SettlementService is read-only with respect to commission calculations.
 * It does not re-calculate commission — it uses the values recorded by CommissionService.
 *
 * Domain boundary: pricing truth comes from bsp_settlement_items (written by CommissionService).
 * WooCommerce order totals are used only at capture time in CommissionService.
 */
final class SettlementService
{
    // ---------------------------------------------------------------------------
    // Batch creation
    // ---------------------------------------------------------------------------

    /**
     * Create a new settlement batch for a given period.
     * Collects all unbatched pending items (batch_id = 0) for the period.
     *
     * @param string $periodLabel   Human label e.g. "2025-06" or "Juni 2025"
     * @param string $periodStart   Y-m-d
     * @param string $periodEnd     Y-m-d
     * @param int    $createdByUid  WP user creating the batch
     *
     * @return array{success: bool, message: string, batch_id?: int, item_count?: int, total_payout?: float}
     */
    public static function createBatch(
        string $periodLabel,
        string $periodStart,
        string $periodEnd,
        int $createdByUid
    ): array {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return ['success' => false, 'message' => 'Database niet beschikbaar.'];
        }

        $batchesTable = $wpdb->prefix . 'bsp_settlement_batches';
        $itemsTable   = $wpdb->prefix . 'bsp_settlement_items';

        // Collect eligible pending items — must have a booking_master in this period.
        $mastersTable = $wpdb->prefix . 'bsp_booking_masters';

        $itemIds = $wpdb->get_col($wpdb->prepare(
            "SELECT si.id
             FROM {$itemsTable} si
             INNER JOIN {$mastersTable} bm ON bm.id = si.booking_master_id
             WHERE si.batch_id = 0
               AND si.item_status = 'pending'
               AND DATE(bm.created_at) BETWEEN %s AND %s",
            $periodStart,
            $periodEnd
        ));

        if (empty($itemIds)) {
            return ['success' => false, 'message' => 'Geen openstaande items gevonden voor deze periode.'];
        }

        $totalPayout   = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(si.payout_eur)
             FROM {$itemsTable} si
             INNER JOIN {$mastersTable} bm ON bm.id = si.booking_master_id
             WHERE si.batch_id = 0
               AND si.item_status = 'pending'
               AND DATE(bm.created_at) BETWEEN %s AND %s",
            $periodStart,
            $periodEnd
        ));

        // Collect gross + commission totals.
        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(si.gross_eur) AS gross, SUM(si.commission_eur) AS commission
             FROM {$itemsTable} si
             INNER JOIN {$mastersTable} bm ON bm.id = si.booking_master_id
             WHERE si.batch_id = 0
               AND si.item_status = 'pending'
               AND DATE(bm.created_at) BETWEEN %s AND %s",
            $periodStart,
            $periodEnd
        ), ARRAY_A);

        // Create batch header.
        $batchReference = strtoupper(substr(md5($periodStart . $periodEnd . $createdByUid), 0, 8));
        $wpdb->insert($batchesTable, [
            'batch_reference'     => $batchReference . '-' . date('YmdHis'),
            'period_label'        => $periodLabel ?: null,
            'period_start'        => $periodStart,
            'period_end'          => $periodEnd,
            'batch_status'        => 'draft',
            'total_gross_eur'     => (float) ($totals['gross'] ?? 0),
            'total_commission_eur' => (float) ($totals['commission'] ?? 0),
            'total_payout_eur'    => $totalPayout,
        ]);

        $batchId = (int) $wpdb->insert_id;
        if (! $batchId) {
            return ['success' => false, 'message' => 'Batch kon niet worden aangemaakt.'];
        }

        // Assign items to this batch.
        $idList = implode(',', array_map('intval', $itemIds));
        $wpdb->query(
            "UPDATE {$itemsTable} SET batch_id = {$batchId}, item_status = 'in_review'
             WHERE id IN ({$idList})"
        );

        return [
            'success'      => true,
            'message'      => sprintf('Batch aangemaakt met %d items.', count($itemIds)),
            'batch_id'     => $batchId,
            'item_count'   => count($itemIds),
            'total_payout' => $totalPayout,
        ];
    }

    // ---------------------------------------------------------------------------
    // Batch approval
    // ---------------------------------------------------------------------------

    /**
     * Approve a settlement batch. Moves status and items to 'approved'.
     *
     * @param int $batchId
     * @param int $approvedByUid WP admin user
     *
     * @return array{success: bool, message: string}
     */
    public static function approveBatch(int $batchId, int $approvedByUid): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return ['success' => false, 'message' => 'Database niet beschikbaar.'];
        }

        $batchesTable = $wpdb->prefix . 'bsp_settlement_batches';
        $itemsTable   = $wpdb->prefix . 'bsp_settlement_items';

        $batch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$batchesTable} WHERE id = %d AND batch_status = 'draft' LIMIT 1",
            $batchId
        ), ARRAY_A);

        if (! $batch) {
            return ['success' => false, 'message' => 'Batch niet gevonden of al goedgekeurd.'];
        }

        $wpdb->update($batchesTable, [
            'batch_status' => 'approved',
            'approved_by'  => $approvedByUid,
            'approved_at'  => current_time('mysql'),
        ], ['id' => $batchId]);

        $wpdb->update($itemsTable, [
            'item_status' => 'approved',
        ], ['batch_id' => $batchId, 'item_status' => 'in_review']);

        // Notify each vendor in this batch.
        self::sendBatchApprovalNotifications($batchId, $batch);

        return ['success' => true, 'message' => 'Batch goedgekeurd en klaar voor uitbetaling.'];
    }

    // ---------------------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------------------

    private static function sendBatchApprovalNotifications(int $batchId, array $batch): void
    {
        global $wpdb;

        $summary = self::getBatchVendorSummaryWithContact($batchId);

        foreach ($summary as $row) {
            $email = sanitize_email($row['contact_email'] ?? '');
            if (! $email || ! is_email($email)) {
                continue;
            }

            $label    = $batch['period_label'] ?? ($batch['period_start'] . ' / ' . $batch['period_end']);
            $amount   = number_format((float) $row['total_payout_eur'], 2, ',', '.');
            $subject  = 'Uw uitbetaling is goedgekeurd — DagjeDenBosch';
            $body     = sprintf(
                "Beste partner,\n\nUw uitbetaling voor de periode %s is goedgekeurd.\n" .
                "Totaalbedrag: €%s\n\n" .
                "Het bedrag wordt overgemaakt naar het bij ons bekende rekeningnummer.\n\n" .
                "Met vriendelijke groet,\nHet DagjeDenBosch team",
                sanitize_text_field($label),
                $amount
            );

            wp_mail($email, $subject, $body);
        }
    }

    private static function getBatchVendorSummaryWithContact(int $batchId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT si.vendor_id,
                    v.vendor_name,
                    v.contact_email,
                    pp.iban,
                    pp.account_holder_name,
                    COUNT(*) AS item_count,
                    SUM(si.payout_eur) AS total_payout_eur
             FROM {$wpdb->prefix}bsp_settlement_items si
             LEFT JOIN {$wpdb->prefix}bsp_vendors v ON v.id = si.vendor_id
             LEFT JOIN {$wpdb->prefix}bsp_payout_profiles pp ON pp.vendor_id = si.vendor_id
             WHERE si.batch_id = %d
             GROUP BY si.vendor_id",
            $batchId
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    // ---------------------------------------------------------------------------
    // Read helpers
    // ---------------------------------------------------------------------------

    /**
     * Get all items in a batch with vendor info.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getBatchItems(int $batchId): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return [];
        }

        $itemsTable   = $wpdb->prefix . 'bsp_settlement_items';
        $vendorsTable = $wpdb->prefix . 'bsp_vendors';

        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT si.*, v.vendor_name, v.contact_email
             FROM {$itemsTable} si
             LEFT JOIN {$vendorsTable} v ON v.id = si.vendor_id
             WHERE si.batch_id = %d
             ORDER BY si.vendor_id ASC, si.id ASC",
            $batchId
        ), ARRAY_A);
    }

    /**
     * Get summary per vendor for a batch (for payout export).
     *
     * @return array<int, array{vendor_id: int, vendor_name: string, item_count: int, total_payout_eur: float}>
     */
    public static function getBatchVendorSummary(int $batchId): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT si.vendor_id,
                    v.vendor_name,
                    v.contact_email,
                    pp.iban,
                    pp.account_holder_name,
                    COUNT(*) AS item_count,
                    SUM(si.gross_eur) AS total_gross_eur,
                    SUM(si.commission_eur) AS total_commission_eur,
                    SUM(si.payout_eur) AS total_payout_eur
             FROM {$wpdb->prefix}bsp_settlement_items si
             LEFT JOIN {$wpdb->prefix}bsp_vendors v ON v.id = si.vendor_id
             LEFT JOIN {$wpdb->prefix}bsp_payout_profiles pp ON pp.vendor_id = si.vendor_id
             WHERE si.batch_id = %d
             GROUP BY si.vendor_id",
            $batchId
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * List all batches with summary info (newest first).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listBatches(int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return [];
        }

        $batchesTable = $wpdb->prefix . 'bsp_settlement_batches';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$batchesTable} ORDER BY id DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }
}
