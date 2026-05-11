<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use BSP\PartnerProgram\Admin\Settings;
use wpdb;

use function add_action;
use function class_exists;
use function current_time;
use function function_exists;
use function get_option;
use function get_post_meta;
use function is_array;
use function is_object;
use function method_exists;
use function strtotime;
use function update_option;
use function wp_next_scheduled;
use function wp_schedule_event;

/**
 * LocalSubscriptionExecutor
 *
 * Lightweight fallback for partner billing lifecycle when Woo Subscriptions
 * is unavailable. It does not replace full subscription product behavior.
 */
final class LocalSubscriptionExecutor
{
    private const CRON_HOOK = 'bsp_partner_local_subscription_renewal_check';
    private const BACKFILL_OPTION = 'bsp_partner_local_executor_backfilled_v1';

    public static function init(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('woocommerce_order_status_processing', [self::class, 'handlePaidOrder'], 20, 1);
        add_action('woocommerce_order_status_completed', [self::class, 'handlePaidOrder'], 20, 1);

        add_action(self::CRON_HOOK, [self::class, 'processDueContracts']);
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }

        self::maybeBackfillContracts();
    }

    public static function isRuntimeAvailable(): bool
    {
        return function_exists('wc_get_order') && class_exists('WooCommerce');
    }

    public static function handlePaidOrder(int $orderId): void
    {
        if (! self::isRuntimeAvailable() || ! function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($orderId);
        if (! is_object($order) || ! method_exists($order, 'get_items')) {
            return;
        }

        $userId = (int) ($order->get_user_id() ?? 0);
        if ($userId <= 0) {
            return;
        }

        $partnerAccountId = self::resolvePartnerAccountIdByUser($userId);
        if ($partnerAccountId <= 0) {
            return;
        }

        foreach ($order->get_items() as $item) {
            $productId = (int) $item->get_product_id();
            if ($productId <= 0) {
                continue;
            }

            $planId = (int) get_post_meta($productId, '_bsp_partner_plan_id', true);
            if ($planId <= 0) {
                continue;
            }

            self::upsertLocalContract($partnerAccountId, $planId);
        }
    }

    public static function processDueContracts(): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $contractsTable = $wpdb->prefix . 'bsp_subscription_contracts';
        $accountsTable = $wpdb->prefix . 'bsp_partner_accounts';
        $entitlementsTable = $wpdb->prefix . 'bsp_partner_entitlements';
        $graceDays = max(1, (int) Settings::get('grace_period_days', '7'));

        $dueActive = $wpdb->get_results(
            "SELECT id, partner_account_id, current_period_end
             FROM {$contractsTable}
             WHERE woo_subscription_id IS NULL
               AND contract_status = 'active'
               AND current_period_end <= NOW()",
            ARRAY_A
        );

        if (is_array($dueActive)) {
            foreach ($dueActive as $row) {
                $graceEnd = date('Y-m-d H:i:s', strtotime((string) $row['current_period_end'] . " +{$graceDays} days"));
                $contractId = (int) ($row['id'] ?? 0);
                $accountId = (int) ($row['partner_account_id'] ?? 0);
                if ($contractId <= 0 || $accountId <= 0) {
                    continue;
                }

                $wpdb->update($contractsTable, [
                    'contract_status' => 'past_due',
                    'grace_period_end' => $graceEnd,
                ], ['id' => $contractId]);

                $wpdb->update($accountsTable, [
                    'account_status' => 'grace_period',
                ], ['id' => $accountId]);
            }
        }

        $expired = $wpdb->get_results(
            "SELECT id, partner_account_id
             FROM {$contractsTable}
             WHERE woo_subscription_id IS NULL
               AND contract_status = 'past_due'
               AND grace_period_end IS NOT NULL
               AND grace_period_end <= NOW()",
            ARRAY_A
        );

        if (! is_array($expired)) {
            return;
        }

        foreach ($expired as $row) {
            $contractId = (int) ($row['id'] ?? 0);
            $accountId = (int) ($row['partner_account_id'] ?? 0);
            if ($contractId <= 0 || $accountId <= 0) {
                continue;
            }

            $wpdb->update($contractsTable, [
                'contract_status' => 'expired',
                'cancelled_at' => current_time('mysql'),
            ], ['id' => $contractId]);

            $wpdb->update($accountsTable, [
                'account_status' => 'suspended',
                'suspended_at' => current_time('mysql'),
            ], ['id' => $accountId]);

            $wpdb->query($wpdb->prepare(
                "UPDATE {$entitlementsTable}
                 SET valid_until = NOW()
                 WHERE partner_account_id = %d
                   AND (valid_until IS NULL OR valid_until > NOW())",
                $accountId
            ));
        }
    }

    private static function maybeBackfillContracts(): void
    {
        if (get_option(self::BACKFILL_OPTION, '0') === '1') {
            return;
        }

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $accounts = $wpdb->get_results(
            "SELECT pa.id, pa.partner_tier
             FROM {$wpdb->prefix}bsp_partner_accounts pa
             WHERE pa.account_status IN ('active','onboarding','grace_period')
               AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}bsp_subscription_contracts sc
                    WHERE sc.partner_account_id = pa.id
               )",
            ARRAY_A
        );

        if (! is_array($accounts) || $accounts === []) {
            update_option(self::BACKFILL_OPTION, '1', false);
            return;
        }

        foreach ($accounts as $account) {
            $accountId = (int) ($account['id'] ?? 0);
            $tier = (string) ($account['partner_tier'] ?? 'basis');
            if ($accountId <= 0) {
                continue;
            }

            $planId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id
                 FROM {$wpdb->prefix}bsp_subscription_plans
                 WHERE plan_slug = %s AND billing_cycle = 'monthly' AND is_active = 1
                 ORDER BY id ASC
                 LIMIT 1",
                $tier
            ));

            if ($planId <= 0) {
                $planId = (int) $wpdb->get_var(
                    "SELECT id
                     FROM {$wpdb->prefix}bsp_subscription_plans
                     WHERE plan_slug = 'basis' AND billing_cycle = 'monthly' AND is_active = 1
                     ORDER BY id ASC
                     LIMIT 1"
                );
            }

            if ($planId <= 0) {
                continue;
            }

            self::upsertLocalContract($accountId, $planId);
        }

        update_option(self::BACKFILL_OPTION, '1', false);
    }

    private static function upsertLocalContract(int $partnerAccountId, int $planId): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT billing_cycle FROM {$wpdb->prefix}bsp_subscription_plans WHERE id = %d LIMIT 1",
            $planId
        ), ARRAY_A);

        if (! $plan) {
            return;
        }

        $billingCycle = (string) ($plan['billing_cycle'] ?? 'monthly');
        $periodStart = current_time('mysql');
        $periodEnd = date(
            'Y-m-d H:i:s',
            strtotime($billingCycle === 'annual' ? '+1 year' : '+1 month', strtotime($periodStart))
        );

        $contractsTable = $wpdb->prefix . 'bsp_subscription_contracts';

        $existingId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id
             FROM {$contractsTable}
             WHERE partner_account_id = %d
               AND plan_id = %d
               AND woo_subscription_id IS NULL
               AND contract_status IN ('active','past_due','paused')
             ORDER BY id DESC
             LIMIT 1",
            $partnerAccountId,
            $planId
        ));

        if ($existingId > 0) {
            $wpdb->update($contractsTable, [
                'contract_status' => 'active',
                'billing_cycle' => $billingCycle,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'grace_period_end' => null,
                'cancelled_at' => null,
            ], ['id' => $existingId]);
        } else {
            $wpdb->insert($contractsTable, [
                'partner_account_id' => $partnerAccountId,
                'plan_id' => $planId,
                'woo_subscription_id' => null,
                'contract_status' => 'active',
                'billing_cycle' => $billingCycle,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'grace_period_end' => null,
                'cancelled_at' => null,
            ]);
        }

        EntitlementService::issueFromPlan(
            $partnerAccountId,
            $planId,
            new \DateTime($periodStart),
            new \DateTime($periodEnd)
        );

        ContractService::syncAccountTierFromPlan($partnerAccountId, $planId);
    }

    private static function resolvePartnerAccountIdByUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return PartnerVendorIdentityService::resolvePartnerAccountIdByUserId($userId);
    }
}
