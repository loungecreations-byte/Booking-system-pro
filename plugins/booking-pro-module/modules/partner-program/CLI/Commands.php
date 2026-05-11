<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\CLI;

use BSP\PartnerProgram\Service\GooglePlacesService;
use BSP\PartnerProgram\Service\CanonicalMappingRepairService;
use BSP\PartnerProgram\Service\IdentityRepairService;
use BSP\PartnerProgram\Service\SettlementService;
use BSP\PartnerProgram\Service\ClaimService;
use BSP\PartnerProgram\Support\Installer;
use WP_CLI_Command;
use WP_CLI;

/**
 * WP-CLI commands for the Partner Program.
 *
 * Usage:
 *   wp bsp-partner seeds sync --query="Den Bosch" [--lat=51.69 --lng=5.30]
 *   wp bsp-partner seeds sync --nearby --lat=51.69 --lng=5.30 --radius=5000 --type=tourist_attraction
 *   wp bsp-partner seeds import-place --place-id=ChIJXXX
 *   wp bsp-partner seeds stats
 *   wp bsp-partner partners list [--status=active]
 *   wp bsp-partner settlements create --start=2025-01-01 --end=2025-01-31 [--label="Jan 2025"]
 *   wp bsp-partner settlements approve --batch-id=1
 *   wp bsp-partner db install
 *   wp bsp-partner db verify
 */
final class Commands
{
    public static function register(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        WP_CLI::add_command('bsp-partner', new class() extends WP_CLI_Command {

            // -----------------------------------------------------------------
            // SEEDS
            // -----------------------------------------------------------------

            /**
             * Sync place seeds from Google Places.
             *
             * ## OPTIONS
             *
             * [--query=<query>]
             * : Text query for Google Places textsearch.
             *
             * [--nearby]
             * : Use nearby search instead of text search.
             *
             * [--lat=<latitude>]
             * : Latitude (required for --nearby).
             *
             * [--lng=<longitude>]
             * : Longitude (required for --nearby).
             *
             * [--radius=<metres>]
             * : Search radius in metres. Default: 5000.
             *
             * [--type=<type>]
             * : Google place type. Default: tourist_attraction.
             *
             * ## EXAMPLES
             *
             *   wp bsp-partner seeds sync --query="Den Bosch bezienswaardigheden"
             *   wp bsp-partner seeds sync --nearby --lat=51.6978 --lng=5.3037 --radius=3000
             *
             * @subcommand seeds sync
             */
            public function seeds_sync(array $args, array $assocArgs): void
            {
                $query  = $assocArgs['query'] ?? null;
                $nearby = isset($assocArgs['nearby']);
                $lat    = isset($assocArgs['lat']) ? (float) $assocArgs['lat'] : null;
                $lng    = isset($assocArgs['lng']) ? (float) $assocArgs['lng'] : null;
                $radius = (int) ($assocArgs['radius'] ?? 5000);
                $type   = (string) ($assocArgs['type'] ?? 'tourist_attraction');

                if ($nearby) {
                    if ($lat === null || $lng === null) {
                        WP_CLI::error('--lat en --lng zijn verplicht voor --nearby.');
                        return;
                    }
                    WP_CLI::line("Syncing nearby ({$lat},{$lng}) radius={$radius}m type={$type}...");
                    $result = GooglePlacesService::syncNearby($lat, $lng, $radius, $type);
                } elseif ($query) {
                    WP_CLI::line("Syncing query: {$query}...");
                    $result = GooglePlacesService::syncByQuery($query, $lat, $lng);
                } else {
                    WP_CLI::error('Geef --query of --nearby op.');
                    return;
                }

                WP_CLI::success(sprintf(
                    'Sync klaar. Synced: %d | Errors: %d | Skipped: %d',
                    $result['synced'] ?? 0,
                    $result['errors'] ?? 0,
                    $result['skipped'] ?? 0
                ));

                if (! empty($result['error_message'])) {
                    WP_CLI::warning($result['error_message']);
                }
            }

            /**
             * Import a single place by Google Place ID.
             *
             * ## OPTIONS
             *
             * --place-id=<id>
             * : The Google Place ID.
             *
             * ## EXAMPLES
             *
             *   wp bsp-partner seeds import-place --place-id=ChIJXXXXX
             *
             * @subcommand seeds import-place
             */
            public function seeds_import_place(array $args, array $assocArgs): void
            {
                $placeId = $assocArgs['place-id'] ?? '';
                if (! $placeId) {
                    WP_CLI::error('--place-id is verplicht.');
                    return;
                }

                $result = GooglePlacesService::syncByPlaceId($placeId);
                if ($result['synced'] > 0) {
                    WP_CLI::success("Place {$placeId} geïmporteerd.");
                } else {
                    WP_CLI::error('Import mislukt: ' . ($result['error_message'] ?? 'onbekende fout'));
                }
            }

            /**
             * Show seed statistics.
             *
             * @subcommand seeds stats
             */
            public function seeds_stats(array $args, array $assocArgs): void
            {
                global $wpdb;

                $total   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bsp_place_seeds");
                $synced  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bsp_place_seeds WHERE sync_status='synced'");
                $claimed = (int) $wpdb->get_var("SELECT COUNT(DISTINCT place_seed_id) FROM {$wpdb->prefix}bsp_claim_requests");
                $cities  = $wpdb->get_results("SELECT city, COUNT(*) AS cnt FROM {$wpdb->prefix}bsp_place_seeds GROUP BY city ORDER BY cnt DESC LIMIT 10", ARRAY_A) ?: [];

                WP_CLI::line("Total seeds: {$total}");
                WP_CLI::line("Synced:      {$synced}");
                WP_CLI::line("Claimed:     {$claimed}");
                WP_CLI::line('');
                WP_CLI::line('Top steden:');
                foreach ($cities as $row) {
                    WP_CLI::line(sprintf('  %-30s %d', ($row['city'] ?: '(unknown)'), $row['cnt']));
                }
            }

            // -----------------------------------------------------------------
            // PARTNERS
            // -----------------------------------------------------------------

            /**
             * List partner accounts.
             *
             * ## OPTIONS
             *
             * [--status=<status>]
             * : Filter by account_status. Default: all.
             *
             * [--tier=<tier>]
             * : Filter by partner_tier.
             *
             * ## EXAMPLES
             *
             *   wp bsp-partner partners list --status=active --tier=gold
             *
             * @subcommand partners list
             */
            public function partners_list(array $args, array $assocArgs): void
            {
                global $wpdb;

                $where  = '1=1';
                $values = [];

                if (! empty($assocArgs['status'])) {
                    $where   .= ' AND pa.account_status = %s';
                    $values[] = $assocArgs['status'];
                }
                if (! empty($assocArgs['tier'])) {
                    $where   .= ' AND pa.partner_tier = %s';
                    $values[] = $assocArgs['tier'];
                }

                $sql = "SELECT pa.id, pa.partner_tier, pa.account_status, pa.commercial_mode, be.legal_name, v.vendor_name
                        FROM {$wpdb->prefix}bsp_partner_accounts pa
                        LEFT JOIN {$wpdb->prefix}bsp_business_entities be ON be.id = pa.business_entity_id
                        LEFT JOIN {$wpdb->prefix}bsp_vendors v ON v.id = pa.vendor_id
                        WHERE {$where}
                        ORDER BY pa.id DESC LIMIT 200";

                $rows = $values ? $wpdb->get_results($wpdb->prepare($sql, ...$values), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

                if (empty($rows)) {
                    WP_CLI::line('Geen partners gevonden.');
                    return;
                }

                $items = array_map(fn($r) => [
                    'ID'     => $r['id'],
                    'Naam'   => $r['vendor_name'] ?: $r['legal_name'] ?: '—',
                    'Tier'   => $r['partner_tier'],
                    'Status' => $r['account_status'],
                    'Mode'   => $r['commercial_mode'],
                ], $rows);

                WP_CLI\Utils\format_items('table', $items, ['ID', 'Naam', 'Tier', 'Status', 'Mode']);
            }

            /**
             * Audit canonical identity drift for partner accounts.
             *
             * @subcommand partners audit-identity
             */
            public function partners_audit_identity(array $args, array $assocArgs): void
            {
                $audit = IdentityRepairService::audit();

                WP_CLI::line('Repairable vendor links: ' . count($audit['repairable_vendor_links']));
                WP_CLI::line('Repairable user links:   ' . count($audit['repairable_user_links']));
                WP_CLI::line('Conflicts:               ' . count($audit['conflicts']));

                if (! empty($audit['repairable_vendor_links'])) {
                    WP_CLI::line('');
                    WP_CLI::line('Repairable vendor links:');
                    WP_CLI\Utils\format_items('table', array_map(static fn(array $row): array => [
                        'account_id' => $row['account_id'],
                        'wp_user_id' => $row['wp_user_id'],
                        'vendor_id' => $row['vendor_id'],
                    ], $audit['repairable_vendor_links']), ['account_id', 'wp_user_id', 'vendor_id']);
                }

                if (! empty($audit['repairable_user_links'])) {
                    WP_CLI::line('');
                    WP_CLI::line('Repairable user links:');
                    WP_CLI\Utils\format_items('table', array_map(static fn(array $row): array => [
                        'account_id' => $row['account_id'],
                        'vendor_id' => $row['vendor_id'],
                        'wp_user_id' => $row['wp_user_id'],
                    ], $audit['repairable_user_links']), ['account_id', 'vendor_id', 'wp_user_id']);
                }

                if (! empty($audit['conflicts'])) {
                    WP_CLI::line('');
                    WP_CLI::warning('Identity conflicts require manual review.');
                    WP_CLI\Utils\format_items('table', array_map(static function (array $row): array {
                        return [
                            'type' => $row['type'] ?? '',
                            'account_id' => $row['account_id'] ?? '',
                            'vendor_id' => $row['vendor_id'] ?? '',
                            'wp_user_id' => $row['wp_user_id'] ?? '',
                            'conflict_account_id' => $row['conflict_account_id'] ?? '',
                            'candidate_user_ids' => isset($row['candidate_user_ids']) && is_array($row['candidate_user_ids']) ? implode(',', $row['candidate_user_ids']) : '',
                        ];
                    }, $audit['conflicts']), ['type', 'account_id', 'vendor_id', 'wp_user_id', 'conflict_account_id', 'candidate_user_ids']);
                }
            }

            /**
             * Apply deterministic canonical identity repairs for partner accounts.
             *
             * ## OPTIONS
             *
             * [--dry-run]
             * : Show repairable rows without applying updates.
             *
             * @subcommand partners repair-identity
             */
            public function partners_repair_identity(array $args, array $assocArgs): void
            {
                $dryRun = isset($assocArgs['dry-run']);

                if ($dryRun) {
                    $this->partners_audit_identity($args, $assocArgs);
                    return;
                }

                $result = IdentityRepairService::apply();

                WP_CLI::success(sprintf(
                    'Updated vendor links: %d | Updated user links: %d | Remaining conflicts: %d',
                    (int) ($result['updated_vendor_links'] ?? 0),
                    (int) ($result['updated_user_links'] ?? 0),
                    count($result['conflicts'] ?? [])
                ));

                if (! empty($result['conflicts'])) {
                    WP_CLI::warning('Some identity conflicts still require manual review. Run `wp bsp-partner partners audit-identity`.');
                }
            }

            /**
             * Audit canonical product/vendor and booking/resource mappings.
             *
             * @subcommand mappings audit
             */
            public function mappings_audit(array $args, array $assocArgs): void
            {
                $audit = CanonicalMappingRepairService::audit();

                $productRows = $audit['repairable_product_vendor_links'] ?? [];
                $orderRows = $audit['repairable_order_item_resources'] ?? [];
                $conflicts = $audit['conflicts'] ?? [];

                WP_CLI::line('Repairable product/vendor links: ' . count($productRows));
                WP_CLI::line('Repairable order-item resources: ' . count($orderRows));
                WP_CLI::line('Conflicts:                      ' . count($conflicts));

                if (! empty($productRows)) {
                    WP_CLI::line('');
                    WP_CLI::line('Repairable product/vendor links:');
                    WP_CLI\Utils\format_items('table', array_map(static fn(array $row): array => [
                        'product_id' => $row['product_id'],
                        'vendor_id' => $row['vendor_id'],
                    ], $productRows), ['product_id', 'vendor_id']);
                }

                if (! empty($orderRows)) {
                    WP_CLI::line('');
                    WP_CLI::line('Repairable order-item resources:');
                    WP_CLI\Utils\format_items('table', array_map(static fn(array $row): array => [
                        'order_item_id' => $row['order_item_id'],
                        'product_id' => $row['product_id'],
                        'resource_id' => $row['resource_id'],
                    ], $orderRows), ['order_item_id', 'product_id', 'resource_id']);
                }

                if (! empty($conflicts)) {
                    WP_CLI::line('');
                    WP_CLI::warning('Mapping conflicts require manual review.');
                    WP_CLI\Utils\format_items('table', array_map(static function (array $row): array {
                        return [
                            'type' => $row['type'] ?? '',
                            'product_id' => $row['product_id'] ?? '',
                            'order_item_id' => $row['order_item_id'] ?? '',
                            'legacy_vendor_id' => $row['legacy_vendor_id'] ?? '',
                            'resource_vendor_ids' => isset($row['resource_vendor_ids']) && is_array($row['resource_vendor_ids']) ? implode(',', $row['resource_vendor_ids']) : '',
                            'candidate_resource_ids' => isset($row['candidate_resource_ids']) && is_array($row['candidate_resource_ids']) ? implode(',', $row['candidate_resource_ids']) : '',
                        ];
                    }, $conflicts), ['type', 'product_id', 'order_item_id', 'legacy_vendor_id', 'resource_vendor_ids', 'candidate_resource_ids']);
                }
            }

            /**
             * Apply deterministic canonical mapping repairs.
             *
             * ## OPTIONS
             *
             * [--dry-run]
             * : Show repairable rows without applying updates.
             *
             * @subcommand mappings repair
             */
            public function mappings_repair(array $args, array $assocArgs): void
            {
                $dryRun = isset($assocArgs['dry-run']);

                if ($dryRun) {
                    $this->mappings_audit($args, $assocArgs);
                    return;
                }

                $result = CanonicalMappingRepairService::apply();

                WP_CLI::success(sprintf(
                    'Updated product/vendor links: %d | Updated order-item resources: %d | Remaining conflicts: %d',
                    (int) ($result['updated_product_vendor_links'] ?? 0),
                    (int) ($result['updated_order_item_resources'] ?? 0),
                    count($result['conflicts'] ?? [])
                ));

                if (! empty($result['conflicts'])) {
                    WP_CLI::warning('Some mapping conflicts still require manual review. Run `wp bsp-partner mappings audit`.');
                }
            }

            // -----------------------------------------------------------------
            // SETTLEMENTS
            // -----------------------------------------------------------------

            /**
             * Create a new settlement batch.
             *
             * ## OPTIONS
             *
             * --start=<date>
             * : Period start (Y-m-d).
             *
             * --end=<date>
             * : Period end (Y-m-d).
             *
             * [--label=<label>]
             * : Human label, e.g. "Jan 2025".
             *
             * ## EXAMPLES
             *
             *   wp bsp-partner settlements create --start=2025-01-01 --end=2025-01-31
             *
             * @subcommand settlements create
             */
            public function settlements_create(array $args, array $assocArgs): void
            {
                $start = $assocArgs['start'] ?? '';
                $end   = $assocArgs['end'] ?? '';

                if (! $start || ! $end) {
                    WP_CLI::error('--start en --end zijn verplicht.');
                    return;
                }

                $label  = $assocArgs['label'] ?? date('Y-m', strtotime($start));
                $result = SettlementService::createBatch($label, $start, $end, get_current_user_id());

                if ($result['success']) {
                    WP_CLI::success(sprintf('Batch %d aangemaakt. Items: %d | Payout: €%.2f', $result['batch_id'], $result['item_count'], $result['total_payout']));
                } else {
                    WP_CLI::error($result['message']);
                }
            }

            /**
             * Approve a settlement batch.
             *
             * ## OPTIONS
             *
             * --batch-id=<id>
             * : The batch ID to approve.
             *
             * @subcommand settlements approve
             */
            public function settlements_approve(array $args, array $assocArgs): void
            {
                $batchId = (int) ($assocArgs['batch-id'] ?? 0);
                if (! $batchId) {
                    WP_CLI::error('--batch-id is verplicht.');
                    return;
                }

                $result = SettlementService::approveBatch($batchId, get_current_user_id());
                if ($result['success']) {
                    WP_CLI::success($result['message']);
                } else {
                    WP_CLI::error($result['message']);
                }
            }

            /**
             * Export payout data for a batch as CSV (includes IBAN per vendor).
             *
             * ## OPTIONS
             *
             * --batch-id=<id>
             * : The batch ID to export.
             *
             * [--output=<file>]
             * : Write CSV to this file path instead of stdout.
             *
             * ## EXAMPLES
             *
             *   wp bsp-partner settlements export --batch-id=5
             *   wp bsp-partner settlements export --batch-id=5 --output=/tmp/payout.csv
             *
             * @subcommand settlements export
             */
            public function settlements_export(array $args, array $assocArgs): void
            {
                $batchId    = (int) ($assocArgs['batch-id'] ?? 0);
                $outputFile = $assocArgs['output'] ?? '';

                if (! $batchId) {
                    WP_CLI::error('--batch-id is verplicht.');
                    return;
                }

                global $wpdb;
                $batch = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}bsp_settlement_batches WHERE id = %d LIMIT 1",
                    $batchId
                ), ARRAY_A);

                if (! $batch) {
                    WP_CLI::error("Batch {$batchId} niet gevonden.");
                    return;
                }

                $summary = SettlementService::getBatchVendorSummary($batchId);
                if (empty($summary)) {
                    WP_CLI::warning('Geen items gevonden in batch ' . $batchId);
                    return;
                }

                // Build CSV.
                $lines   = [];
                $lines[] = 'vendor_id,vendor_name,contact_email,account_holder_name,iban,item_count,total_gross_eur,total_commission_eur,total_payout_eur';

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

                $csv = implode("\n", $lines) . "\n";

                if ($outputFile) {
                    file_put_contents($outputFile, $csv);
                    WP_CLI::success("CSV opgeslagen in {$outputFile}");
                } else {
                    WP_CLI::line($csv);
                }
            }

            // -----------------------------------------------------------------
            // DB
            // -----------------------------------------------------------------

            /**
             * Install / upgrade Partner Program database tables.
             *
             * @subcommand db install
             */
            public function db_install(array $args, array $assocArgs): void
            {
                Installer::install();
                WP_CLI::success('Partner Program schema geïnstalleerd / bijgewerkt.');
            }

            /**
             * Verify all Partner Program tables exist.
             *
             * @subcommand db verify
             */
            public function db_verify(array $args, array $assocArgs): void
            {
                global $wpdb;

                $expected = [
                    'bsp_place_seeds', 'bsp_place_seed_sync_log', 'bsp_business_entities',
                    'bsp_claim_requests', 'bsp_partner_accounts', 'bsp_subscription_plans',
                    'bsp_subscription_contracts', 'bsp_partner_entitlements', 'bsp_commission_rules',
                    'bsp_settlement_batches', 'bsp_settlement_items', 'bsp_payout_profiles',
                ];

                $ok    = 0;
                $miss  = 0;

                foreach ($expected as $table) {
                    $exists = (bool) $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}{$table}'");
                    if ($exists) {
                        WP_CLI::line("  ✓ {$wpdb->prefix}{$table}");
                        $ok++;
                    } else {
                        WP_CLI::warning("  ✗ MISSING: {$wpdb->prefix}{$table}");
                        $miss++;
                    }
                }

                if ($miss === 0) {
                    WP_CLI::success("Alle {$ok} tabellen aanwezig.");
                } else {
                    WP_CLI::error("{$miss} tabellen ontbreken. Voer 'wp bsp-partner db install' uit.");
                }
            }

        });
    }
}
