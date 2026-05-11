<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use wpdb;
use function wc_get_order;
use function get_post_meta;
use function function_exists;
use function current_time;

/**
 * CommissionService — captures commission events per completed booking.
 *
 * Called on woocommerce_payment_complete (priority 20, after PaymentSync).
 * Inserts a bsp_settlement_items row (status='pending') for every bookable vendor.
 * Does NOT calculate payouts — that happens in SettlementService.
 *
 * Source of commission rate: bsp_commission_rules (partner-specific, then platform default).
 * Never reads commission rate from vendor metadata or WooCommerce product.
 */
final class CommissionService
{
    public static function captureFromOrder(int $orderId): void
    {
    // nothing changed in captureFromOrder — marker only
        if ($orderId <= 0 || ! function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($orderId);
        if (! $order) {
            return;
        }

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $mastersTable = $wpdb->prefix . 'bsp_booking_masters';
        $itemsTable   = $wpdb->prefix . 'bsp_settlement_items';

        // Find the booking_master linked to this WooCommerce order.
        $bookingMaster = $wpdb->get_row($wpdb->prepare(
            "SELECT id, vendor_id, commercial_total FROM {$mastersTable} WHERE woo_order_id = %d LIMIT 1",
            $orderId
        ), ARRAY_A);

        if (! $bookingMaster || ! $bookingMaster['vendor_id']) {
            return;
        }

        $vendorId        = (int) $bookingMaster['vendor_id'];
        $bookingMasterId = (int) $bookingMaster['id'];
        $grossEur        = (float) $bookingMaster['commercial_total'];

        // Only capture if the vendor is in bookable mode.
        if (! EntitlementService::check($vendorId, 'booking_access')) {
            return;
        }

        // Prevent duplicate capture.
        $already = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$itemsTable} WHERE booking_master_id = %d LIMIT 1",
            $bookingMasterId
        ));
        if ($already) {
            return;
        }

        $commissionRate = self::resolveCommissionRate($vendorId);
        $commissionEur  = round($grossEur * ($commissionRate / 100), 2);
        $payoutEur      = round($grossEur - $commissionEur, 2);

        // Insert pending settlement item (not yet in a batch).
        $wpdb->insert($itemsTable, [
            'batch_id'          => 0, // assigned when batch is created
            'vendor_id'         => $vendorId,
            'booking_master_id' => $bookingMasterId,
            'gross_eur'         => $grossEur,
            'commission_rate'   => $commissionRate / 100,
            'commission_eur'    => $commissionEur,
            'payout_eur'        => $payoutEur,
            'item_status'       => 'pending',
        ]);
    }

    /**
     * Adjusts the settlement item when a WooCommerce order is fully refunded.
     *
     * RULES:
     *   pending   → cancelled  (safe: not yet in a batch, no payout risk)
     *   in_review → held       (manual review required: batch not yet approved)
     *   approved  → held       (manual review required: batch was approved but not paid)
     *   paid      → disputed   (manual review required: payout may already have gone out)
     *   held      → no change  (already flagged)
     *   disputed  → no change  (already flagged)
     *   cancelled → no change  (already resolved)
     *
     * Called by: woocommerce_order_fully_refunded (hook registered in Module.php).
     *
     * @param int $refundId  WC_Order_Refund ID
     * @param int $orderId   WC_Order ID
     */
    public static function adjustFromFullRefund(int $refundId, int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        self::adjustFromRefund($orderId, null, $refundId, false);
    }

    /**
     * Adjusts the settlement item when a WooCommerce order is partially refunded.
     *
     * Partial refunds move the item to 'held' so finance can manually
     * decide whether to recalculate the payout or write off the difference.
     * The hold_reason records the refunded amount context.
     *
     * Called by: woocommerce_order_partially_refunded (hook registered in Module.php).
     *
     * @param int $orderId  WC_Order ID
     * @param int $refundId WC_Order_Refund ID
     */
    public static function adjustFromPartialRefund(int $orderId, int $refundId): void
    {
        if ($orderId <= 0) {
            return;
        }

        self::adjustFromRefund($orderId, null, $refundId, true);
    }

    /**
     * Core refund adjustment logic.
     *
     * @param int        $orderId   WC_Order ID
     * @param float|null $refundAmount  Reserved for future partial-amount prorating.
     * @param int        $refundId  WC_Order_Refund ID used in hold_reason text.
     * @param bool       $isPartial When true, pending items go to 'held' not 'cancelled'.
     */
    private static function adjustFromRefund(int $orderId, ?float $refundAmount, int $refundId = 0, bool $isPartial = false): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $mastersTable = $wpdb->prefix . 'bsp_booking_masters';
        $itemsTable   = $wpdb->prefix . 'bsp_settlement_items';

        $bookingMasterId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$mastersTable} WHERE woo_order_id = %d LIMIT 1",
            $orderId
        ));

        if (! $bookingMasterId) {
            return;
        }

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT id, item_status, gross_eur FROM {$itemsTable} WHERE booking_master_id = %d LIMIT 1",
            $bookingMasterId
        ), ARRAY_A);

        if (! $item) {
            return;
        }

        $itemId     = (int) $item['id'];
        $itemStatus = (string) $item['item_status'];
        $grossEur   = (float) $item['gross_eur'];

        $refundContext = $refundId > 0
            ? sprintf('WC refund #%d voor order #%d', $refundId, $orderId)
            : sprintf('Terugbetaling voor order #%d', $orderId);

        $holdReason = $isPartial
            ? sprintf('Gedeeltelijke terugbetaling — %s. Origineel: €%.2f. Handmatige review vereist.', $refundContext, $grossEur)
            : sprintf('Volledige terugbetaling — %s. Origineel: €%.2f.', $refundContext, $grossEur);

        $newStatus = match (true) {
            $itemStatus === 'pending' && ! $isPartial                                       => 'cancelled',
            in_array($itemStatus, ['in_review', 'approved'], true)                          => 'held',
            $itemStatus === 'paid'                                                           => 'disputed',
            $isPartial && in_array($itemStatus, ['pending', 'in_review', 'approved'], true) => 'held',
            default                                                                          => null,
        };

        if ($newStatus === null) {
            return;
        }

        $wpdb->update(
            $itemsTable,
            ['item_status' => $newStatus, 'hold_reason' => $holdReason],
            ['id' => $itemId],
            ['%s', '%s'],
            ['%d']
        );

        do_action('bsp_commission_item_adjusted', $itemId, $newStatus, $orderId, $refundId);
    }

    /**
     * Resolves the commission rate for a vendor.
     * Priority: partner-specific rule > platform default for tier.
     */
    public static function resolveCommissionRate(int $vendorId): float
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return 18.00;
        }

        $rulesTable    = $wpdb->prefix . 'bsp_commission_rules';
        $accountsTable = $wpdb->prefix . 'bsp_partner_accounts';

        $tier = EntitlementService::getTier($vendorId);

        // 1. Partner-specific override.
        $accountId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$accountsTable} WHERE vendor_id = %d LIMIT 1",
            $vendorId
        ));

        if ($accountId) {
            $partnerRate = $wpdb->get_var($wpdb->prepare(
                "SELECT commission_value FROM {$rulesTable}
                 WHERE partner_account_id = %d AND commercial_mode = 'bookable'
                   AND applies_from <= NOW() AND (applies_until IS NULL OR applies_until > NOW())
                 ORDER BY applies_from DESC LIMIT 1",
                (int) $accountId
            ));

            if ($partnerRate !== null) {
                return (float) $partnerRate;
            }
        }

        // 2. Platform default for tier.
        $platformRate = $wpdb->get_var($wpdb->prepare(
            "SELECT commission_value FROM {$rulesTable}
             WHERE partner_account_id IS NULL AND partner_tier = %s AND commercial_mode = 'bookable'
               AND applies_from <= NOW() AND (applies_until IS NULL OR applies_until > NOW())
             ORDER BY applies_from DESC LIMIT 1",
            $tier
        ));

        if ($platformRate !== null) {
            return (float) $platformRate;
        }

        // 3. Hard fallback by tier.
        return match ($tier) {
            'gold'    => 10.00,
            'premium' => 14.00,
            default   => 18.00,
        };
    }
}
