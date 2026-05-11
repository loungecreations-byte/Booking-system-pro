<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use wpdb;

/**
 * EntitlementService — single source of truth for partner rights.
 *
 * All feature gating in the codebase must go through this service.
 * Never read partner tier or entitlements from WooCommerce, user meta,
 * or template/CSS. This service is the canonical gate.
 *
 * Usage:
 *   EntitlementService::check($vendorId, 'booking_access');  // bool
 *   EntitlementService::get($vendorId, 'max_offers');        // mixed
 *   EntitlementService::getAll($vendorId);                   // array
 */
final class EntitlementService
{
    /**
     * Returns true if the vendor has the given entitlement and it evaluates as truthy.
     */
    public static function check(int $vendorId, string $key): bool
    {
        $value = self::get($vendorId, $key);
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float) $value > 0;
        }
        return ! empty($value);
    }

    /**
     * Returns the raw entitlement value, or $default if not set.
     */
    public static function get(int $vendorId, string $key, mixed $default = null): mixed
    {
        $all = self::getAll($vendorId);
        return $all[$key] ?? $default;
    }

    /**
     * Returns all active entitlements for a vendor as a flat key→value map.
     * Prioritises: manual_override > add_on > plan.
     * Falls back to plan entitlement matrix from bsp_subscription_plans.
     */
    public static function getAll(int $vendorId): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return self::fallbackEntitlements('basis');
        }

        $accountsTable     = $wpdb->prefix . 'bsp_partner_accounts';
        $entitlementsTable = $wpdb->prefix . 'bsp_partner_entitlements';

        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT id, partner_tier FROM {$accountsTable} WHERE vendor_id = %d AND account_status IN ('active','onboarding') LIMIT 1",
            $vendorId
        ), ARRAY_A);

        if (! $account) {
            return self::fallbackEntitlements('basis');
        }

        $accountId = (int) $account['id'];
        $tier      = (string) ($account['partner_tier'] ?? 'basis');

        // Load explicit entitlement rows (overrides + add-ons).
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT entitlement_key, entitlement_value, source
             FROM {$entitlementsTable}
             WHERE partner_account_id = %d
               AND (valid_until IS NULL OR valid_until > NOW())
             ORDER BY FIELD(source, 'plan', 'add_on', 'manual_override') ASC",
            $accountId
        ), ARRAY_A);

        if (! $rows) {
            return self::fallbackEntitlements($tier);
        }

        $entitlements = self::fallbackEntitlements($tier);
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['entitlement_value'], true);
            $entitlements[(string) $row['entitlement_key']] = $decoded ?? $row['entitlement_value'];
        }

        return $entitlements;
    }

    /**
     * Returns the partner tier for a vendor ('basis' | 'premium' | 'gold').
     */
    public static function getTier(int $vendorId): string
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return 'basis';
        }

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT partner_tier FROM {$wpdb->prefix}bsp_partner_accounts WHERE vendor_id = %d AND account_status IN ('active','onboarding') LIMIT 1",
            $vendorId
        ));

        return in_array((string) $result, ['basis', 'premium', 'gold'], true) ? (string) $result : 'basis';
    }

    /**
     * Issues a full set of entitlements to a partner account derived from the plan.
     * Called when a subscription contract is activated or renewed.
     */
    public static function issueFromPlan(int $partnerAccountId, int $planId, \DateTime $validFrom, ?\DateTime $validUntil = null): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT entitlements FROM {$wpdb->prefix}bsp_subscription_plans WHERE id = %d LIMIT 1",
            $planId
        ), ARRAY_A);

        if (! $plan) {
            return;
        }

        $matrix = json_decode((string) $plan['entitlements'], true);
        if (! is_array($matrix)) {
            return;
        }

        $table = $wpdb->prefix . 'bsp_partner_entitlements';

        foreach ($matrix as $key => $value) {
            // Expire existing plan-sourced entitlements for this key.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET valid_until = %s WHERE partner_account_id = %d AND entitlement_key = %s AND source = 'plan' AND (valid_until IS NULL OR valid_until > NOW())",
                $validFrom->format('Y-m-d H:i:s'),
                $partnerAccountId,
                $key
            ));

            $wpdb->insert($table, [
                'partner_account_id' => $partnerAccountId,
                'entitlement_key'    => $key,
                'entitlement_value'  => (string) wp_json_encode($value),
                'source'             => 'plan',
                'valid_from'         => $validFrom->format('Y-m-d H:i:s'),
                'valid_until'        => $validUntil ? $validUntil->format('Y-m-d H:i:s') : null,
            ]);
        }
    }

    /**
     * Hard-coded fallback matrix used when no DB rows exist.
     * Prevents NULL errors during onboarding / migration.
     * Public so other services can safely access the tier defaults.
     */
    public static function fallbackEntitlements(string $tier): array
    {
        $matrix = [
            'basis' => [
                'max_offers'           => 1,
                'max_users'            => 1,
                'max_locations'        => 1,
                'listing_visibility'   => 'standard',
                'featured_eligible'    => false,
                'ai_host_priority'     => 'low',
                'lead_routing'         => false,
                'booking_access'       => false,
                'reporting_depth'      => 'basic',
                'support_priority'     => 'email',
                'campaign_eligible'    => false,
                'settlement_frequency' => 'monthly',
                'commission_rate_pct'  => 18.00,
            ],
            'premium' => [
                'max_offers'           => 5,
                'max_users'            => 2,
                'max_locations'        => 2,
                'listing_visibility'   => 'elevated',
                'featured_eligible'    => true,
                'ai_host_priority'     => 'medium',
                'lead_routing'         => true,
                'booking_access'       => false,
                'reporting_depth'      => 'advanced',
                'support_priority'     => 'priority_email',
                'campaign_eligible'    => true,
                'settlement_frequency' => 'monthly',
                'commission_rate_pct'  => 14.00,
            ],
            'gold' => [
                'max_offers'           => -1,
                'max_users'            => 5,
                'max_locations'        => -1,
                'listing_visibility'   => 'priority',
                'featured_eligible'    => true,
                'featured_included'    => 1,
                'ai_host_priority'     => 'high',
                'lead_routing'         => true,
                'lead_routing_priority'=> true,
                'booking_access'       => true,
                'reporting_depth'      => 'full',
                'support_priority'     => 'dedicated',
                'campaign_eligible'    => true,
                'settlement_frequency' => 'bi-monthly',
                'commission_rate_pct'  => 10.00,
            ],
        ];

        return $matrix[$tier] ?? $matrix['basis'];
    }
}
