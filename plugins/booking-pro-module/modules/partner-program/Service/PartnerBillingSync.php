<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use wpdb;
use function absint;
use function current_time;
use function update_option;
use function get_option;
use BSP\PartnerProgram\Service\ContractService;
use BSP\PartnerProgram\Admin\Settings;

/**
 * PartnerBillingSync — keeps subscription contracts and entitlements in sync
 * with WooCommerce Subscriptions lifecycle events.
 *
 * WooCommerce Subscriptions is the BILLING EXECUTOR only.
 * The canonical state lives in bsp_subscription_contracts + bsp_partner_accounts.
 * Entitlements are always derived from the contract — never read from WC directly.
 *
 * Hooks registered by Module.php:
 *   woocommerce_subscription_status_updated → handleStatusChange (priority 10)
 *   woocommerce_subscription_payment_complete → handleRenewal (priority 10)
 */
final class PartnerBillingSync
{

    /**
     * Called when a WC Subscription changes status.
     *
     * @param mixed  $subscription WC_Subscription object
     * @param string $newStatus
     * @param string $oldStatus
     */
    public static function handleStatusChange($subscription, string $newStatus, string $oldStatus): void
    {
        if (! is_object($subscription) || ! method_exists($subscription, 'get_id')) {
            return;
        }

        $wcSubscriptionId = (int) $subscription->get_id();
        if ($wcSubscriptionId <= 0) {
            return;
        }

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        // Ensure a contract row exists before we try to sync it.
        // ContractService::ensureContract() is idempotent and creates the row
        // on first activation (handles the "new subscription → active" path).
        ContractService::ensureContract($subscription);

        $contractsTable = $wpdb->prefix . 'bsp_subscription_contracts';
        $accountsTable  = $wpdb->prefix . 'bsp_partner_accounts';

        $contract = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$contractsTable} WHERE woo_subscription_id = %d LIMIT 1",
            $wcSubscriptionId
        ), ARRAY_A);

        if (! $contract) {
            return;
        }

        $contractId      = (int) $contract['id'];
        $partnerAccountId = (int) $contract['partner_account_id'];

        switch ($newStatus) {
            case 'active':
                // Subscription became active — ensure contract + entitlements are live.
                $periodEnd = self::resolveSubscriptionPeriodEnd($subscription);

                $wpdb->update($contractsTable, [
                    'contract_status'       => 'active',
                    'current_period_end'    => $periodEnd,
                    'grace_period_end'      => null,
                    'cancelled_at'          => null,
                ], ['id' => $contractId]);

                $wpdb->update($accountsTable, [
                    'account_status' => 'active',
                    'suspended_at'   => null,
                ], ['id' => $partnerAccountId]);

                EntitlementService::issueFromPlan(
                    $partnerAccountId,
                    (int) $contract['plan_id'],
                    new \DateTime($periodEnd ?? 'now'),
                    null
                );
                break;

            case 'on-hold':
            case 'pending-cancel':
                // Subscription is in grace — keep access for configured grace period.
                $graceDays      = max(1, (int) Settings::get('grace_period_days', '7'));
                $gracePeriodEnd = date('Y-m-d H:i:s', strtotime(sprintf('+%d days', $graceDays)));

                $wpdb->update($contractsTable, [
                    'contract_status'  => 'past_due',
                    'grace_period_end' => $gracePeriodEnd,
                ], ['id' => $contractId]);

                $wpdb->update($accountsTable, [
                    'account_status' => 'grace_period',
                ], ['id' => $partnerAccountId]);
                break;

            case 'cancelled':
            case 'expired':
                // Hard termination — suspend account, revoke entitlements.
                $wpdb->update($contractsTable, [
                    'contract_status'   => 'cancelled',
                    'cancelled_at'      => current_time('mysql'),
                    'grace_period_end'  => null,
                ], ['id' => $contractId]);

                $wpdb->update($accountsTable, [
                    'account_status' => 'suspended',
                    'suspended_at'   => current_time('mysql'),
                ], ['id' => $partnerAccountId]);

                // Revoke all active entitlements (expire them immediately).
                $entitlementsTable = $wpdb->prefix . 'bsp_partner_entitlements';
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$entitlementsTable}
                     SET valid_until = NOW()
                     WHERE partner_account_id = %d AND (valid_until IS NULL OR valid_until > NOW())",
                    $partnerAccountId
                ));
                break;
        }
    }

    /**
     * Called when a WC Subscription renewal payment succeeds.
     *
     * @param mixed $subscription WC_Subscription object
     */
    public static function handleRenewal($subscription): void
    {
        if (! is_object($subscription) || ! method_exists($subscription, 'get_id')) {
            return;
        }

        $wcSubscriptionId = (int) $subscription->get_id();
        if ($wcSubscriptionId <= 0) {
            return;
        }

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $contractsTable = $wpdb->prefix . 'bsp_subscription_contracts';

        $contract = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$contractsTable} WHERE woo_subscription_id = %d LIMIT 1",
            $wcSubscriptionId
        ), ARRAY_A);

        if (! $contract) {
            return;
        }

        $periodEnd = self::resolveSubscriptionPeriodEnd($subscription);

        $wpdb->update($contractsTable, [
            'contract_status'    => 'active',
            'current_period_end' => $periodEnd,
            'grace_period_end'   => null,
        ], ['id' => (int) $contract['id']]);

        // Re-issue entitlements for the new period.
        EntitlementService::issueFromPlan(
            (int) $contract['partner_account_id'],
            (int) $contract['plan_id'],
            new \DateTime($periodEnd ?? 'now'),
            null
        );
    }

    // ---------------------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------------------

    private static function resolveSubscriptionPeriodEnd($subscription): ?string
    {
        if (! is_object($subscription)) {
            return null;
        }

        if (method_exists($subscription, 'get_date') && $subscription->get_date('next_payment')) {
            return date('Y-m-d H:i:s', strtotime($subscription->get_date('next_payment')));
        }

        return null;
    }
}
