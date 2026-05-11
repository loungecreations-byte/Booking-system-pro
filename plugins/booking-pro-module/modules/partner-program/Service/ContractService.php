<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use wpdb;
use function current_time;
use function get_post_meta;
use function function_exists;
use function is_object;
use function method_exists;

/**
 * ContractService — creates and syncs bsp_subscription_contracts from WC Subscriptions.
 *
 * This bridges WooCommerce Subscriptions (billing execution) to the domain contracts table.
 * Called by PartnerBillingSync on every status transition — uses upsert so it's safe
 * to call repeatedly.
 *
 * Contract-to-account linkage uses product meta `_bsp_partner_plan_id` set in WC product editor.
 * Partner account is resolved through PartnerVendorIdentityService so wp_user_id
 * stays primary and `_sbdp_vendor_id` remains temporary fallback only.
 *
 * WooCommerce is billing executor. bsp_subscription_contracts is canonical contract state.
 */
final class ContractService
{
    /**
     * Ensure a contract exists for a WC Subscription, creating it if not.
     * Called from PartnerBillingSync::handleStatusChange() before any status update.
     *
     * @param mixed $subscription WC_Subscription object
     * @return int|null  The bsp_subscription_contracts.id, or null on failure.
     */
    public static function ensureContract($subscription): ?int
    {
        if (! is_object($subscription) || ! method_exists($subscription, 'get_id')) {
            return null;
        }

        $wcSubId = (int) $subscription->get_id();
        if ($wcSubId <= 0) {
            return null;
        }

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return null;
        }

        $contractsTable = $wpdb->prefix . 'bsp_subscription_contracts';

        // Already exists?
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$contractsTable} WHERE woo_subscription_id = %d LIMIT 1",
            $wcSubId
        ), ARRAY_A);

        if ($existing) {
            return (int) $existing['id'];
        }

        // Resolve plan and partner account from the subscription.
        $planId          = self::resolvePlanId($subscription);
        $partnerAccountId = self::resolvePartnerAccountId($subscription);

        if (! $planId || ! $partnerAccountId) {
            // Not a Partner Program subscription — skip.
            return null;
        }

        // Resolve billing cycle from the plan.
        $billingCycle = (string) ($wpdb->get_var($wpdb->prepare(
            "SELECT billing_cycle FROM {$wpdb->prefix}bsp_subscription_plans WHERE id = %d",
            $planId
        )) ?: 'monthly');

        $periodStart = self::resolveStartDate($subscription);
        $periodEnd   = self::resolveEndDate($subscription);

        $wpdb->insert($contractsTable, [
            'partner_account_id'  => $partnerAccountId,
            'plan_id'             => $planId,
            'woo_subscription_id' => $wcSubId,
            'contract_status'     => 'active',
            'billing_cycle'       => $billingCycle,
            'current_period_start'=> $periodStart,
            'current_period_end'  => $periodEnd,
            'grace_period_end'    => null,
            'cancelled_at'        => null,
        ]);

        $contractId = (int) $wpdb->insert_id;

        if ($contractId) {
            // Issue entitlements immediately on contract creation.
            EntitlementService::issueFromPlan(
                $partnerAccountId,
                $planId,
                new \DateTime($periodStart),
                null
            );

            // Update account tier to match plan.
            self::syncAccountTierFromPlan($partnerAccountId, $planId);
        }

        return $contractId ?: null;
    }

    /**
     * Sync account tier and status from plan (called on contract creation/renewal).
     */
    public static function syncAccountTierFromPlan(int $partnerAccountId, int $planId): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT plan_slug, entitlements FROM {$wpdb->prefix}bsp_subscription_plans WHERE id = %d",
            $planId
        ), ARRAY_A);

        if (! $plan) {
            return;
        }

        $tier = $plan['plan_slug']; // 'basis' | 'premium' | 'gold'
        $entitlements = json_decode((string) $plan['entitlements'], true) ?: [];
        $bookingEnabled = (int) ($entitlements['booking_access'] ?? false);
        $leadEnabled    = (int) ($entitlements['lead_routing'] ?? false);

        $wpdb->update($wpdb->prefix . 'bsp_partner_accounts', [
            'partner_tier'    => $tier,
            'account_status'  => 'active',
            'commercial_mode' => $bookingEnabled ? 'bookable' : ($leadEnabled ? 'lead' : 'listing'),
            'booking_enabled' => $bookingEnabled,
            'lead_enabled'    => $leadEnabled,
        ], ['id' => $partnerAccountId]);
    }

    // -------------------------------------------------------------------------
    // Private resolvers
    // -------------------------------------------------------------------------

    /**
     * Resolve bsp_subscription_plans.id from the WC subscription's product meta.
     * Product must have `_bsp_partner_plan_id` set in WC product editor.
     */
    private static function resolvePlanId($subscription): ?int
    {
        if (! function_exists('get_post_meta')) {
            return null;
        }

        // Walk subscription items and find the first plan-linked product.
        foreach ($subscription->get_items() as $item) {
            $productId = (int) $item->get_product_id();
            if (! $productId) {
                continue;
            }

            $planId = (int) get_post_meta($productId, '_bsp_partner_plan_id', true);
            if ($planId > 0) {
                return $planId;
            }
        }

        return null;
    }

    /**
     * Resolve the bsp_partner_accounts.id linked to the subscription owner.
     */
    private static function resolvePartnerAccountId($subscription): ?int
    {
        $userId = (int) $subscription->get_user_id();
        if ($userId <= 0) {
            return null;
        }

        $accountId = PartnerVendorIdentityService::resolvePartnerAccountIdByUserId($userId);

        return $accountId > 0 ? $accountId : null;
    }

    private static function resolveStartDate($subscription): string
    {
        if (method_exists($subscription, 'get_date')) {
            $start = $subscription->get_date('start');
            if ($start) {
                return date('Y-m-d H:i:s', strtotime($start));
            }
        }
        return current_time('mysql');
    }

    private static function resolveEndDate($subscription): string
    {
        if (method_exists($subscription, 'get_date')) {
            $next = $subscription->get_date('next_payment');
            if ($next) {
                return date('Y-m-d H:i:s', strtotime($next));
            }
        }
        return date('Y-m-d H:i:s', strtotime('+1 month'));
    }
}
