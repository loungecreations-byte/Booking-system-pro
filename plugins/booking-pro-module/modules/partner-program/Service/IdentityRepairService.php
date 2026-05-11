<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use wpdb;

/**
 * Safe repair tooling for canonical partner identity linkage.
 *
 * Only deterministic repairs are applied automatically:
 * - fill missing vendor_id from a user's legacy _sbdp_vendor_id when unambiguous
 * - fill missing wp_user_id from legacy usermeta by vendor_id when unambiguous
 *
 * Ambiguous cases are reported, never auto-mutated.
 */
final class IdentityRepairService
{
    public static function audit(): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return [
                'repairable_vendor_links' => [],
                'repairable_user_links' => [],
                'conflicts' => [],
            ];
        }

        $accountsTable = $wpdb->prefix . 'bsp_partner_accounts';
        $conflicts = [];
        $repairableVendorLinks = [];
        $repairableUserLinks = [];

        $accountsMissingVendor = $wpdb->get_results(
            "SELECT id, wp_user_id
             FROM {$accountsTable}
             WHERE account_status != 'archived'
               AND vendor_id IS NULL
               AND wp_user_id IS NOT NULL",
            ARRAY_A
        ) ?: [];

        foreach ($accountsMissingVendor as $account) {
            $accountId = (int) ($account['id'] ?? 0);
            $userId = (int) ($account['wp_user_id'] ?? 0);
            if ($accountId <= 0 || $userId <= 0) {
                continue;
            }

            $legacyVendorId = (int) get_user_meta($userId, '_sbdp_vendor_id', true);
            if ($legacyVendorId <= 0) {
                continue;
            }

            $existing = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id
                 FROM {$accountsTable}
                 WHERE vendor_id = %d
                   AND id != %d
                   AND account_status != 'archived'
                 LIMIT 1",
                $legacyVendorId,
                $accountId
            ));

            if ($existing > 0) {
                $conflicts[] = [
                    'type' => 'vendor_conflict',
                    'account_id' => $accountId,
                    'wp_user_id' => $userId,
                    'vendor_id' => $legacyVendorId,
                    'conflict_account_id' => $existing,
                ];
                continue;
            }

            $repairableVendorLinks[] = [
                'account_id' => $accountId,
                'wp_user_id' => $userId,
                'vendor_id' => $legacyVendorId,
            ];
        }

        $accountsMissingUser = $wpdb->get_results(
            "SELECT id, vendor_id
             FROM {$accountsTable}
             WHERE account_status != 'archived'
               AND wp_user_id IS NULL
               AND vendor_id IS NOT NULL",
            ARRAY_A
        ) ?: [];

        foreach ($accountsMissingUser as $account) {
            $accountId = (int) ($account['id'] ?? 0);
            $vendorId = (int) ($account['vendor_id'] ?? 0);
            if ($accountId <= 0 || $vendorId <= 0) {
                continue;
            }

            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id
                 FROM {$wpdb->usermeta}
                 WHERE meta_key = '_sbdp_vendor_id'
                   AND meta_value = %s",
                (string) $vendorId
            )) ?: [];

            $rows = array_values(array_unique(array_map('intval', $rows)));
            $rows = array_values(array_filter($rows));

            if (count($rows) !== 1) {
                if ($rows !== []) {
                    $conflicts[] = [
                        'type' => 'user_conflict',
                        'account_id' => $accountId,
                        'vendor_id' => $vendorId,
                        'candidate_user_ids' => $rows,
                    ];
                }
                continue;
            }

            $userId = (int) $rows[0];
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id
                 FROM {$accountsTable}
                 WHERE wp_user_id = %d
                   AND id != %d
                   AND account_status != 'archived'
                 LIMIT 1",
                $userId,
                $accountId
            ));

            if ($existing > 0) {
                $conflicts[] = [
                    'type' => 'user_conflict',
                    'account_id' => $accountId,
                    'vendor_id' => $vendorId,
                    'candidate_user_ids' => [$userId],
                    'conflict_account_id' => $existing,
                ];
                continue;
            }

            $repairableUserLinks[] = [
                'account_id' => $accountId,
                'vendor_id' => $vendorId,
                'wp_user_id' => $userId,
            ];
        }

        return [
            'repairable_vendor_links' => $repairableVendorLinks,
            'repairable_user_links' => $repairableUserLinks,
            'conflicts' => $conflicts,
        ];
    }

    public static function apply(): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return [
                'updated_vendor_links' => 0,
                'updated_user_links' => 0,
                'conflicts' => [],
            ];
        }

        $audit = self::audit();
        $accountsTable = $wpdb->prefix . 'bsp_partner_accounts';
        $updatedVendorLinks = 0;
        $updatedUserLinks = 0;

        foreach ($audit['repairable_vendor_links'] as $row) {
            $updated = $wpdb->update(
                $accountsTable,
                ['vendor_id' => (int) $row['vendor_id']],
                ['id' => (int) $row['account_id'], 'vendor_id' => null],
                ['%d'],
                ['%d', '%s']
            );
            if ($updated !== false && $updated > 0) {
                $updatedVendorLinks++;
            }
        }

        foreach ($audit['repairable_user_links'] as $row) {
            $updated = $wpdb->update(
                $accountsTable,
                ['wp_user_id' => (int) $row['wp_user_id']],
                ['id' => (int) $row['account_id'], 'wp_user_id' => null],
                ['%d'],
                ['%d', '%s']
            );
            if ($updated !== false && $updated > 0) {
                $updatedUserLinks++;
            }
        }

        return [
            'updated_vendor_links' => $updatedVendorLinks,
            'updated_user_links' => $updatedUserLinks,
            'conflicts' => $audit['conflicts'],
        ];
    }
}
