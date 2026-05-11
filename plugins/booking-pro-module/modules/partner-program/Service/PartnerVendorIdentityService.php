<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use wpdb;

use function get_user_meta;

/**
 * Canonical identity resolver for Partner Program actor scope.
 *
 * Current-state constraint:
 * - Primary lookup is bsp_partner_accounts.wp_user_id
 * - Legacy fallback is user meta `_sbdp_vendor_id`
 *
 * This service exists to stop identity resolution from fragmenting across
 * frontend handlers, billing sync, and REST permissions.
 */
final class PartnerVendorIdentityService
{
    /**
     * Resolve the active partner identity context for a WP user.
     *
     * @return array{
     *   partner_account_id:int,
     *   vendor_id:int,
     *   wp_user_id:int,
     *   resolved_via:string
     * }|null
     */
    public static function resolveByUserId(int $userId): ?array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb || $userId <= 0) {
            return null;
        }

        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT id, vendor_id, wp_user_id
             FROM {$wpdb->prefix}bsp_partner_accounts
             WHERE wp_user_id = %d
               AND account_status != 'archived'
             LIMIT 1",
            $userId
        ), ARRAY_A);

        if (is_array($account) && (int) ($account['id'] ?? 0) > 0) {
            return [
                'partner_account_id' => (int) $account['id'],
                'vendor_id'          => (int) ($account['vendor_id'] ?? 0),
                'wp_user_id'         => $userId,
                'resolved_via'       => 'partner_account_wp_user',
            ];
        }

        $legacyVendorId = (int) get_user_meta($userId, '_sbdp_vendor_id', true);
        if ($legacyVendorId <= 0) {
            return null;
        }

        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT id, vendor_id, wp_user_id
             FROM {$wpdb->prefix}bsp_partner_accounts
             WHERE vendor_id = %d
               AND account_status != 'archived'
             LIMIT 1",
            $legacyVendorId
        ), ARRAY_A);

        if (! is_array($account) || (int) ($account['id'] ?? 0) <= 0) {
            return null;
        }

        return [
            'partner_account_id' => (int) $account['id'],
            'vendor_id'          => (int) ($account['vendor_id'] ?? 0),
            'wp_user_id'         => $userId,
            'resolved_via'       => 'legacy_user_vendor_meta',
        ];
    }

    public static function resolvePartnerAccountIdByUserId(int $userId): int
    {
        $context = self::resolveByUserId($userId);

        return $context['partner_account_id'] ?? 0;
    }

    public static function resolveVendorIdByUserId(int $userId): int
    {
        $context = self::resolveByUserId($userId);

        return $context['vendor_id'] ?? 0;
    }
}
