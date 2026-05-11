<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use wpdb;
use function current_time;
use function wp_next_scheduled;
use function wp_schedule_event;
use function do_action;

/**
 * GracePeriodService — scheduled job that expires grace-period accounts.
 *
 * WooCommerce Subscriptions puts a subscription on-hold when payment fails.
 * PartnerBillingSync sets contract_status = 'past_due' and account_status = 'grace_period',
 * storing grace_period_end = NOW() + 7 days.
 *
 * This service runs once per day (via wp_cron) and:
 *   - Finds all contracts where grace_period_end < NOW() AND contract_status = 'past_due'
 *   - Suspends the partner account
 *   - Revokes all active entitlements
 *
 * Registered by Module.php via:
 *   add_action('bsp_partner_grace_period_check', [GracePeriodService::class, 'runExpiry']);
 */
final class GracePeriodService
{
    private const CRON_HOOK     = 'bsp_partner_grace_period_check';
    private const CRON_INTERVAL = 'daily';

    /**
     * Register the daily cron event if not already scheduled.
     * Called from Module::init().
     */
    public static function scheduleCron(): void
    {
        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_event')) {
            return;
        }

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    /**
     * Unschedule the cron event (call on plugin deactivation).
     */
    public static function unscheduleCron(): void
    {
        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_unschedule_event')) {
            return;
        }

        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Runs daily: find expired grace-period contracts and suspend partner accounts.
     *
     * Criteria:
     *   bsp_subscription_contracts.contract_status = 'past_due'
     *   AND grace_period_end < NOW()
     *
     * Effect on each expired contract:
     *   - contract_status          → 'cancelled'
     *   - partner_accounts.status  → 'suspended', suspended_at = NOW()
     *   - entitlements             → valid_until = NOW() (revoke immediately)
     */
    public static function runExpiry(): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $contractsTable    = $wpdb->prefix . 'bsp_subscription_contracts';
        $accountsTable     = $wpdb->prefix . 'bsp_partner_accounts';
        $entitlementsTable = $wpdb->prefix . 'bsp_partner_entitlements';

        // Find all contracts whose grace period has now ended.
        $expiredContracts = $wpdb->get_results(
            "SELECT id, partner_account_id
             FROM {$contractsTable}
             WHERE contract_status = 'past_due'
               AND grace_period_end IS NOT NULL
               AND grace_period_end < NOW()",
            ARRAY_A
        );

        if (empty($expiredContracts)) {
            return;
        }

        $now = current_time('mysql');

        foreach ($expiredContracts as $contract) {
            $contractId       = (int) $contract['id'];
            $partnerAccountId = (int) $contract['partner_account_id'];

            // Suspend the contract.
            $wpdb->update($contractsTable, [
                'contract_status' => 'cancelled',
                'cancelled_at'    => $now,
                'grace_period_end'=> null,
            ], ['id' => $contractId]);

            // Suspend the partner account.
            $wpdb->update($accountsTable, [
                'account_status' => 'suspended',
                'suspended_at'   => $now,
            ], ['id' => $partnerAccountId]);

            // Revoke all active entitlements.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$entitlementsTable}
                 SET valid_until = %s
                 WHERE partner_account_id = %d
                   AND (valid_until IS NULL OR valid_until > %s)",
                $now,
                $partnerAccountId,
                $now
            ));

            /**
             * Action hook fired after a partner account is suspended due to expired grace period.
             * External code (notifications, analytics) can hook here.
             *
             * @param int $partnerAccountId  The bsp_partner_accounts.id that was suspended.
             * @param int $contractId        The bsp_subscription_contracts.id that expired.
             */
            do_action('bsp_partner_grace_expired', $partnerAccountId, $contractId);
        }
    }
}
