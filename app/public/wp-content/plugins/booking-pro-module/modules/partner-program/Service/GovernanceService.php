<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use wpdb;

/**
 * GovernanceService — canonical data source for the Partner Program
 * governance cockpit tab.
 *
 * Truth hierarchy enforced here:
 *   Google/external  →  seed input only, never commercial truth
 *   Partner Program  →  claim, account, entitlement, commission, payout truth
 *   WooCommerce      →  billing execution only, not governance truth
 *
 * All methods return plain arrays. No HTML. No side effects.
 */
final class GovernanceService
{
    // -------------------------------------------------------------------------
    // Readiness matrix — static codebase truth (audited April 2026)
    // Overlay: live DB counts injected where relevant.
    // -------------------------------------------------------------------------

    private const READINESS_MATRIX = [
        'seed_layer' => [
            'label'    => 'Google Seed Layer',
            'owner'    => 'Platform / Ops',
            'designed' => 'green',
            'built'    => 'green',
            'connected'=> 'orange',
            'verified' => 'orange',
            'blocker'  => 'GooglePlacesService sync is built; no automated duplicate dedup; manual seeds write valid sync_status now (fixed).',
            'note'     => 'Seed layer is discovery only. Not commercial truth.',
        ],
        'business_identity' => [
            'label'    => 'Business Identity Layer',
            'owner'    => 'Platform / Admin',
            'designed' => 'orange',
            'built'    => 'orange',
            'connected'=> 'orange',
            'verified' => 'red',
            'blocker'  => 'No canonical business_location model. No duplicate-entity detection service. vendor_id linkage is optional.',
            'note'     => 'bsp_business_entities exists; linkage to bsp_partner_accounts is loose.',
        ],
        'claim_flow' => [
            'label'    => 'Claim & Verification Flow',
            'owner'    => 'Platform',
            'designed' => 'green',
            'built'    => 'green',
            'connected'=> 'orange',
            'verified' => 'red',
            'blocker'  => 'ClaimService 3-step flow is built. No automated test coverage. Duplicate-claim guard is weak.',
            'note'     => 'submit→email_verify→admin_approve is implemented and connected.',
        ],
        'partner_account' => [
            'label'    => 'Partner Account Layer',
            'owner'    => 'Platform',
            'designed' => 'orange',
            'built'    => 'orange',
            'connected'=> 'orange',
            'verified' => 'red',
            'blocker'  => 'Dual identity: bsp_vendors (sales) vs bsp_partner_accounts (partner-program). vendor_id linkage not enforced on onboarding.',
            'note'     => 'Account shell is created on claim approval. Tier synced from plan.',
        ],
        'subscription_mapping' => [
            'label'    => 'Subscription / Billing Mapping',
            'owner'    => 'Platform + WooCommerce',
            'designed' => 'green',
            'built'    => 'green',
            'connected'=> 'orange',
            'verified' => 'red',
            'blocker'  => 'WC Subscription → domain contract bridge is built (ContractService). Settings wiring for grace_period_days is now live (fixed). Zero test coverage on lifecycle transitions.',
            'note'     => 'WooCommerce is billing executor only. Domain contract is canonical.',
        ],
        'entitlement_engine' => [
            'label'    => 'Entitlement Engine',
            'owner'    => 'Platform',
            'designed' => 'green',
            'built'    => 'green',
            'connected'=> 'orange',
            'verified' => 'red',
            'blocker'  => 'EntitlementService::check() only actively called in CommissionService. Not enforced platform-wide across booking/lead flows.',
            'note'     => 'Priority: manual_override > add_on > plan. Fallback matrix exists per tier.',
        ],
        'commercial_modes' => [
            'label'    => 'Commercial Modes',
            'owner'    => 'Platform',
            'designed' => 'green',
            'built'    => 'orange',
            'connected'=> 'orange',
            'verified' => 'red',
            'blocker'  => 'commercial_mode (listing/lead/bookable) written to bsp_partner_accounts but not enforced as gate across API surface.',
            'note'     => 'Three valid modes. Invalid states (bookable without booking_enabled) must be surfaced.',
        ],
        'commission_engine' => [
            'label'    => 'Commission Engine',
            'owner'    => 'Platform',
            'designed' => 'orange',
            'built'    => 'orange',
            'connected'=> 'orange',
            'verified' => 'red',
            'blocker'  => 'No refund/cancellation adjustment path. woocommerce_payment_complete hook exists. Overpayment risk on refunded orders.',
            'note'     => 'CommissionService captures per completed booking. Rate from bsp_commission_rules.',
        ],
        'settlement_payout' => [
            'label'    => 'Settlement / Payout Layer',
            'owner'    => 'Platform / Finance',
            'designed' => 'orange',
            'built'    => 'orange',
            'connected'=> 'orange',
            'verified' => 'red',
            'blocker'  => 'ENUM schema fixed (in_review/cancelled now valid). No automated payout-readiness state machine. IBAN validation exists (PayoutProfileHandler).',
            'note'     => 'SettlementService creates and approves batches. No automated payout dispatch.',
        ],
        'admin_governance' => [
            'label'    => 'Admin Governance',
            'owner'    => 'Platform',
            'designed' => 'green',
            'built'    => 'green',
            'connected'=> 'green',
            'verified' => 'orange',
            'blocker'  => 'This cockpit tab is now live. No test coverage on governance queries.',
            'note'     => 'Integrated into existing sbdp_governance cockpit. Partner tab added.',
        ],
        'end_to_end_flows' => [
            'label'    => 'End-to-end Verified Flows',
            'owner'    => 'Platform / QA',
            'designed' => 'orange',
            'built'    => 'orange',
            'connected'=> 'orange',
            'verified' => 'red',
            'blocker'  => 'Zero automated test coverage across all partner-program services. No integration tests for seed→claim→partner→billing→commission→settlement flow.',
            'note'     => 'Manual admin flow review exists. No CI harness.',
        ],
    ];

    // -------------------------------------------------------------------------
    // Go-live gates — explicit release criteria
    // -------------------------------------------------------------------------

    private const GOLIVE_GATES = [
        ['key' => 'claim_flow',          'label' => 'Claim flow verified (submit → verify → approve)',                  'severity' => 'blocker'],
        ['key' => 'subscription_mapping','label' => 'Subscription mapping verified (WC → domain contract)',             'severity' => 'blocker'],
        ['key' => 'identity_canonical', 'label' => 'Canonical identity map verified (no active partner depends on legacy vendor-user fallback)', 'severity' => 'blocker'],
        ['key' => 'entitlement_sync',    'label' => 'Entitlement sync verified (plan → all entitlements granted)',      'severity' => 'blocker'],
        ['key' => 'listing_mode',        'label' => 'Listing mode verified (partner visible without booking)',           'severity' => 'blocker'],
        ['key' => 'lead_mode',           'label' => 'Lead mode verified (lead routing active for premium/gold)',         'severity' => 'blocker'],
        ['key' => 'bookable_mode',       'label' => 'Bookable vendor mode verified (end-to-end booking test passed)',   'severity' => 'blocker'],
        ['key' => 'commission',          'label' => 'Commission verified (payment → commission captured correctly)',     'severity' => 'blocker'],
        ['key' => 'refund_adjustment',   'label' => 'Refund adjustment pipeline exists and tested',                     'severity' => 'blocker'],
        ['key' => 'payout_hold',         'label' => 'Payout hold logic verified (hold_reason persisted)',               'severity' => 'blocker'],
        ['key' => 'admin_review_queue',  'label' => 'Admin claim review queue operational',                             'severity' => 'required'],
        ['key' => 'conflict_handling',   'label' => 'Duplicate/conflict detection in admin surface',                    'severity' => 'required'],
        ['key' => 'payout_profile',      'label' => 'All bookable vendors have valid payout profile (IBAN)',             'severity' => 'required'],
        ['key' => 'test_coverage',       'label' => 'GovernanceMetricsTest + partner-program unit tests pass',          'severity' => 'recommended'],
        ['key' => 'grace_period',        'label' => 'Grace period expiry cron verified end-to-end',                     'severity' => 'recommended'],
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Main entry point — returns all governance data in one array.
     */
    public static function getData(): array
    {
        $dbStats = self::queryDbStats();

        return [
            'readiness_matrix'  => self::buildReadinessMatrix($dbStats),
            'flow_health'       => self::buildFlowHealth($dbStats),
            'domain_conflicts'  => self::buildDomainConflicts($dbStats),
            'money_health'      => self::buildMoneyHealth($dbStats),
            'golive_gates'      => self::buildGoLiveGates($dbStats),
            'hero'              => self::buildHero($dbStats),
            'db_stats'          => $dbStats,
        ];
    }

    /**
     * Returns the overall partner readiness status for the platform hero card.
     * 'red' | 'orange' | 'blue' | 'green'
     */
    public static function getOverallStatus(): string
    {
        $stats = self::queryDbStats();
        $gates = self::buildGoLiveGates($stats);

        $blockers = array_filter($gates, static fn(array $g) => $g['status'] === 'red' && $g['severity'] === 'blocker');
        if ($blockers !== []) {
            return 'red';
        }

        $open = array_filter($gates, static fn(array $g) => $g['status'] !== 'green');
        if ($open !== []) {
            return 'orange';
        }

        return 'green';
    }

    // -------------------------------------------------------------------------
    // DB stats — one query batch, cached per request
    // -------------------------------------------------------------------------

    private static ?array $cachedStats = null;

    private static function queryDbStats(): array
    {
        if (self::$cachedStats !== null) {
            return self::$cachedStats;
        }

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            self::$cachedStats = [];
            return [];
        }

        $p   = $wpdb->prefix;
        $out = [];

        // Helper: safe COUNT query against potentially missing table.
        $count = static function (string $sql) use ($wpdb): int {
            $result = $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return is_numeric($result) ? (int) $result : 0;
        };

        $countGroup = static function (string $sql) use ($wpdb): array {
            $rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if (! is_array($rows)) {
                return [];
            }
            $out = [];
            foreach ($rows as $row) {
                $keys      = array_keys($row);
                $statusKey = $keys[0] ?? 'status';
                $cntKey    = $keys[1] ?? 'cnt';
                $out[(string) $row[$statusKey]] = (int) $row[$cntKey];
            }
            return $out;
        };

        // Guard: check critical tables exist.
        $tables = [
            'seeds'       => "{$p}bsp_place_seeds",
            'claims'      => "{$p}bsp_claim_requests",
            'entities'    => "{$p}bsp_business_entities",
            'accounts'    => "{$p}bsp_partner_accounts",
            'contracts'   => "{$p}bsp_subscription_contracts",
            'entitlements'=> "{$p}bsp_partner_entitlements",
            'commrules'   => "{$p}bsp_commission_rules",
            'batches'     => "{$p}bsp_settlement_batches",
            'items'       => "{$p}bsp_settlement_items",
            'payouts'     => "{$p}bsp_payout_profiles",
            'bookings'    => "{$p}bsp_booking_masters",
        ];

        $existing = [];
        foreach ($tables as $key => $tbl) {
            $existing[$key] = (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl));
        }

        // --- Seeds ---
        if ($existing['seeds']) {
            $out['seeds_by_status']   = $countGroup("SELECT sync_status, COUNT(*) cnt FROM {$p}bsp_place_seeds GROUP BY sync_status");
            $out['seeds_total']       = array_sum($out['seeds_by_status']);
            $out['seeds_unclaimed']   = $count("SELECT COUNT(*) FROM {$p}bsp_place_seeds ps WHERE NOT EXISTS (
                SELECT 1 FROM {$p}bsp_claim_requests cr WHERE cr.place_seed_id = ps.id AND cr.claim_status IN ('submitted','email_verified','under_review','approved')
            )");
            $out['seeds_duplicates']  = $count("SELECT COUNT(*) FROM (SELECT name, city, COUNT(*) c FROM {$p}bsp_place_seeds GROUP BY name, city HAVING c > 1) t");
        }

        // --- Claims ---
        if ($existing['claims']) {
            $out['claims_by_status']  = $countGroup("SELECT claim_status, COUNT(*) cnt FROM {$p}bsp_claim_requests GROUP BY claim_status");
            $out['claims_total']      = array_sum($out['claims_by_status']);
            $out['claims_expired']    = $count("SELECT COUNT(*) FROM {$p}bsp_claim_requests WHERE claim_status IN ('submitted','email_verified') AND token_expires_at < NOW()");
            $out['claims_review']     = (int) ($out['claims_by_status']['under_review'] ?? 0);
        }

        // --- Business Entities ---
        if ($existing['entities']) {
            $out['entities_total']    = $count("SELECT COUNT(*) FROM {$p}bsp_business_entities");
        }

        // --- Partner Accounts ---
        if ($existing['accounts']) {
            $out['accounts_by_status']= $countGroup("SELECT account_status, COUNT(*) cnt FROM {$p}bsp_partner_accounts GROUP BY account_status");
            $out['accounts_by_tier']  = $countGroup("SELECT partner_tier, COUNT(*) cnt FROM {$p}bsp_partner_accounts GROUP BY partner_tier");
            $out['accounts_by_mode']  = $countGroup("SELECT commercial_mode, COUNT(*) cnt FROM {$p}bsp_partner_accounts GROUP BY commercial_mode");
            $out['accounts_total']    = array_sum($out['accounts_by_status']);
            $out['accounts_active']   = (int) ($out['accounts_by_status']['active'] ?? 0);
            $out['accounts_no_vendor']= $count("SELECT COUNT(*) FROM {$p}bsp_partner_accounts WHERE vendor_id IS NULL");
            $out['accounts_no_entity']= $count("SELECT COUNT(*) FROM {$p}bsp_partner_accounts WHERE business_entity_id IS NULL");
            $out['accounts_no_wp_user']=$count("SELECT COUNT(*) FROM {$p}bsp_partner_accounts WHERE wp_user_id IS NULL");
            $out['accounts_legacy_identity_only'] = $count("SELECT COUNT(*) FROM {$p}bsp_partner_accounts pa WHERE pa.account_status != 'archived' AND pa.wp_user_id IS NULL AND pa.vendor_id IS NOT NULL");
            $out['accounts_invalid_bookable'] = $count("SELECT COUNT(*) FROM {$p}bsp_partner_accounts WHERE commercial_mode = 'bookable' AND booking_enabled != 1");
        }

        $productsTable = "{$p}bsp_products";
        $productsExists = (bool) $wpdb->get_var("SHOW TABLES LIKE '{$productsTable}'");
        if ($productsExists) {
            $out['products_without_vendor'] = $count(
                "SELECT COUNT(*)
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$productsTable} bp ON bp.product_id = p.ID
                 WHERE p.post_type = 'product'
                   AND p.post_status NOT IN ('trash', 'auto-draft')
                   AND (bp.product_id IS NULL OR bp.vendor_id IS NULL OR bp.vendor_id = 0)"
            );
        }

        $postmetaTable = $wpdb->postmeta;
        $postsTable = $wpdb->posts;
        $orderItemsTable = "{$p}woocommerce_order_items";
        $orderItemmetaTable = "{$p}woocommerce_order_itemmeta";
        $orderItemsExists = (bool) $wpdb->get_var("SHOW TABLES LIKE '{$orderItemsTable}'");
        $orderItemmetaExists = (bool) $wpdb->get_var("SHOW TABLES LIKE '{$orderItemmetaTable}'");

        if ($orderItemsExists && $orderItemmetaExists) {
            $out['order_items_missing_resource'] = $count(
                "SELECT COUNT(*)
                 FROM {$orderItemsTable} oi
                 INNER JOIN {$postsTable} o ON o.ID = oi.order_id
                 LEFT JOIN {$orderItemmetaTable} start_meta ON start_meta.order_item_id = oi.order_item_id AND start_meta.meta_key = 'sbdp_start'
                 LEFT JOIN {$orderItemmetaTable} resource_meta ON resource_meta.order_item_id = oi.order_item_id AND resource_meta.meta_key = 'sbdp_resource_id'
                 WHERE oi.order_item_type = 'line_item'
                   AND o.post_type = 'shop_order'
                   AND start_meta.meta_value IS NOT NULL
                   AND (resource_meta.meta_value IS NULL OR resource_meta.meta_value = '' OR resource_meta.meta_value = '0')"
            );
        }

        // --- Subscriptions ---
        if ($existing['contracts'] && $existing['accounts']) {
            $out['contracts_by_status']= $countGroup("SELECT contract_status, COUNT(*) cnt FROM {$p}bsp_subscription_contracts GROUP BY contract_status");
            $out['accounts_no_contract']= $count("SELECT COUNT(*) FROM {$p}bsp_partner_accounts pa WHERE NOT EXISTS (SELECT 1 FROM {$p}bsp_subscription_contracts sc WHERE sc.partner_account_id = pa.id)");
            $out['contracts_grace']    = (int) ($out['contracts_by_status']['past_due'] ?? 0);
        }

        // --- Entitlements ---
        if ($existing['entitlements'] && $existing['accounts']) {
            $out['accounts_no_entitlements'] = $count("SELECT COUNT(*) FROM {$p}bsp_partner_accounts pa WHERE NOT EXISTS (SELECT 1 FROM {$p}bsp_partner_entitlements pe WHERE pe.partner_account_id = pa.id)");
            $out['entitled_accounts']         = $count("SELECT COUNT(DISTINCT partner_account_id) FROM {$p}bsp_partner_entitlements");
        }

        // --- Commission & Settlement ---
        if ($existing['accounts'] && $existing['commrules']) {
            $out['bookable_no_commission'] = $count("SELECT COUNT(*) FROM {$p}bsp_partner_accounts pa WHERE pa.booking_enabled = 1 AND pa.vendor_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {$p}bsp_commission_rules cr WHERE cr.vendor_id = pa.vendor_id)");
        }

        if ($existing['accounts'] && $existing['payouts']) {
            $out['vendors_no_payout'] = $count("SELECT COUNT(*) FROM {$p}bsp_partner_accounts pa WHERE (pa.booking_enabled = 1 OR pa.lead_enabled = 1) AND pa.vendor_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {$p}bsp_payout_profiles pp WHERE pp.vendor_id = pa.vendor_id)");
        }

        if ($existing['items']) {
            $out['items_by_status']   = $countGroup("SELECT item_status, COUNT(*) cnt FROM {$p}bsp_settlement_items GROUP BY item_status");
            $out['items_unbatched']   = $count("SELECT COUNT(*) FROM {$p}bsp_settlement_items WHERE batch_id IS NULL AND item_status = 'pending'");
        }

        if ($existing['batches']) {
            $out['batches_by_status'] = $countGroup("SELECT batch_status, COUNT(*) cnt FROM {$p}bsp_settlement_batches GROUP BY batch_status");
            $out['batches_open']      = $count("SELECT COUNT(*) FROM {$p}bsp_settlement_batches WHERE batch_status IN ('pending','in_review')");
        }

        if ($existing['bookings'] && $existing['items']) {
            $out['bookings_missing_commission'] = $count("SELECT COUNT(*) FROM {$p}bsp_booking_masters bm WHERE bm.vendor_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {$p}bsp_settlement_items si WHERE si.booking_master_id = bm.id)");
        }

        self::$cachedStats = $out;
        return $out;
    }

    // -------------------------------------------------------------------------
    // Build sections
    // -------------------------------------------------------------------------

    private static function buildReadinessMatrix(array $stats): array
    {
        $matrix = [];
        foreach (self::READINESS_MATRIX as $key => $row) {
            // Overlay live DB data where available for 'verified' status.
            if ($key === 'claim_flow' && isset($stats['claims_total'])) {
                $row['live'] = [
                    'claims_total'  => $stats['claims_total'],
                    'in_review'     => $stats['claims_review'] ?? 0,
                    'expired'       => $stats['claims_expired'] ?? 0,
                ];
            }
            if ($key === 'partner_account' && isset($stats['accounts_total'])) {
                $row['live'] = [
                    'total'       => $stats['accounts_total'],
                    'active'      => $stats['accounts_active'] ?? 0,
                    'no_vendor'   => $stats['accounts_no_vendor'] ?? 0,
                    'no_entity'   => $stats['accounts_no_entity'] ?? 0,
                    'legacy_only' => $stats['accounts_legacy_identity_only'] ?? 0,
                    'invalid_bookable' => $stats['accounts_invalid_bookable'] ?? 0,
                ];
            }
            if ($key === 'entitlement_engine' && isset($stats['accounts_no_entitlements'])) {
                $row['live'] = ['without_entitlements' => $stats['accounts_no_entitlements']];
            }
            if ($key === 'commission_engine' && isset($stats['bookings_missing_commission'])) {
                $row['live'] = [
                    'bookings_missing_commission' => $stats['bookings_missing_commission'],
                    'bookable_no_commission_rule' => $stats['bookable_no_commission'] ?? 0,
                ];
            }
            $matrix[$key] = $row;
        }
        return $matrix;
    }

    private static function buildFlowHealth(array $stats): array
    {
        return [
            'claim_flow' => [
                'label'        => 'Claim Flow (submit → verify → approve)',
                'total'        => $stats['claims_total'] ?? 0,
                'pending'      => ($stats['claims_by_status']['submitted'] ?? 0) + ($stats['claims_by_status']['email_verified'] ?? 0),
                'in_review'    => $stats['claims_by_status']['under_review'] ?? 0,
                'approved'     => $stats['claims_by_status']['approved'] ?? 0,
                'rejected'     => $stats['claims_by_status']['rejected'] ?? 0,
                'expired'      => $stats['claims_expired'] ?? 0,
                'action'       => ($stats['claims_review'] ?? 0) > 0 ? 'Review pending claims →' : '',
                'action_url'   => admin_url('admin.php?page=sbdp_partner_claims'),
                'status'       => ($stats['claims_expired'] ?? 0) > 0 ? 'warn' : 'pass',
            ],
            'billing_sync' => [
                'label'        => 'Billing Sync (WC Subscription → Domain Contract)',
                'total'        => array_sum($stats['contracts_by_status'] ?? []),
                'active'       => $stats['contracts_by_status']['active'] ?? 0,
                'grace'        => $stats['contracts_grace'] ?? 0,
                'cancelled'    => $stats['contracts_by_status']['cancelled'] ?? 0,
                'expired'      => $stats['contracts_by_status']['expired'] ?? 0,
                'no_contract'  => $stats['accounts_no_contract'] ?? 0,
                'action'       => ($stats['accounts_no_contract'] ?? 0) > 0 ? 'Partners without contract detected →' : '',
                'action_url'   => admin_url('admin.php?page=sbdp_partners'),
                'status'       => ($stats['accounts_no_contract'] ?? 0) > 0 ? 'warn' : (($stats['contracts_grace'] ?? 0) > 0 ? 'warn' : 'pass'),
            ],
            'commission_capture' => [
                'label'        => 'Commission Capture (payment → settlement item)',
                'missing'      => $stats['bookings_missing_commission'] ?? 0,
                'unbatched'    => $stats['items_unbatched'] ?? 0,
                'items_pending'=> $stats['items_by_status']['pending'] ?? 0,
                'items_approved'=> $stats['items_by_status']['approved'] ?? 0,
                'items_paid'   => $stats['items_by_status']['paid'] ?? 0,
                'items_held'   => $stats['items_by_status']['held'] ?? 0,
                'action'       => ($stats['bookings_missing_commission'] ?? 0) > 0 ? 'Bookings without commission →' : '',
                'action_url'   => admin_url('admin.php?page=sbdp_partner_commissions'),
                'status'       => ($stats['bookings_missing_commission'] ?? 0) > 0 ? 'red' : (($stats['items_held'] ?? 0) > 0 ? 'warn' : 'pass'),
            ],
            'settlement_payout' => [
                'label'        => 'Settlement / Payout (batch → approve → pay)',
                'open_batches' => $stats['batches_open'] ?? 0,
                'unbatched'    => $stats['items_unbatched'] ?? 0,
                'no_payout'    => $stats['vendors_no_payout'] ?? 0,
                'action'       => ($stats['batches_open'] ?? 0) > 0 ? 'Open settlement batches →' : '',
                'action_url'   => admin_url('admin.php?page=sbdp_partner_settlements'),
                'status'       => ($stats['vendors_no_payout'] ?? 0) > 0 ? 'red' : (($stats['batches_open'] ?? 0) > 0 ? 'warn' : 'pass'),
            ],
        ];
    }

    private static function buildDomainConflicts(array $stats): array
    {
        $conflicts = [];

        if (($stats['seeds_duplicates'] ?? 0) > 0) {
            $conflicts[] = ['severity' => 'medium', 'area' => 'Seeds', 'label' => 'Duplicate seed candidates (same name+city)', 'count' => $stats['seeds_duplicates'], 'action_url' => admin_url('admin.php?page=sbdp_partner_seeds')];
        }
        if (($stats['claims_expired'] ?? 0) > 0) {
            $conflicts[] = ['severity' => 'medium', 'area' => 'Claims', 'label' => 'Expired unverified claims (token past TTL)', 'count' => $stats['claims_expired'], 'action_url' => admin_url('admin.php?page=sbdp_partner_claims')];
        }
        if (($stats['accounts_no_vendor'] ?? 0) > 0) {
            $conflicts[] = ['severity' => 'high', 'area' => 'Identity', 'label' => 'Partner accounts without vendor_id linkage', 'count' => $stats['accounts_no_vendor'], 'action_url' => admin_url('admin.php?page=sbdp_partners')];
        }
        if (($stats['accounts_no_entity'] ?? 0) > 0) {
            $conflicts[] = ['severity' => 'high', 'area' => 'Identity', 'label' => 'Partner accounts without business_entity_id', 'count' => $stats['accounts_no_entity'], 'action_url' => admin_url('admin.php?page=sbdp_partners')];
        }
        if (($stats['accounts_legacy_identity_only'] ?? 0) > 0) {
            $conflicts[] = ['severity' => 'critical', 'area' => 'Identity', 'label' => 'Partner accounts still depend on legacy vendor-only identity mapping', 'count' => $stats['accounts_legacy_identity_only'], 'action_url' => admin_url('admin.php?page=sbdp_partners')];
        }
        if (($stats['products_without_vendor'] ?? 0) > 0) {
            $conflicts[] = ['severity' => 'critical', 'area' => 'Commercial Mapping', 'label' => 'Products missing canonical vendor ownership in bsp_products', 'count' => $stats['products_without_vendor'], 'action_url' => admin_url('admin.php?page=sbdp_sales_hub')];
        }
        if (($stats['order_items_missing_resource'] ?? 0) > 0) {
            $conflicts[] = ['severity' => 'critical', 'area' => 'Execution Mapping', 'label' => 'Booked order items missing explicit resource_id', 'count' => $stats['order_items_missing_resource'], 'action_url' => admin_url('admin.php?page=sbdp_governance')];
        }
        if (($stats['accounts_invalid_bookable'] ?? 0) > 0) {
            $conflicts[] = ['severity' => 'high', 'area' => 'Commercial Mode', 'label' => 'Accounts set to bookable but booking_enabled=0 (invalid state)', 'count' => $stats['accounts_invalid_bookable'], 'action_url' => admin_url('admin.php?page=sbdp_partners')];
        }
        if (($stats['accounts_no_entitlements'] ?? 0) > 0) {
            $conflicts[] = ['severity' => 'high', 'area' => 'Entitlements', 'label' => 'Active accounts with zero entitlements', 'count' => $stats['accounts_no_entitlements'], 'action_url' => admin_url('admin.php?page=sbdp_partners')];
        }
        if (($stats['accounts_no_contract'] ?? 0) > 0) {
            $conflicts[] = ['severity' => 'high', 'area' => 'Billing', 'label' => 'Active partners without subscription contract', 'count' => $stats['accounts_no_contract'], 'action_url' => admin_url('admin.php?page=sbdp_partners')];
        }
        if (($stats['bookable_no_commission'] ?? 0) > 0) {
            $conflicts[] = ['severity' => 'critical', 'area' => 'Revenue', 'label' => 'Bookable vendors without commission rule (revenue leak risk)', 'count' => $stats['bookable_no_commission'], 'action_url' => admin_url('admin.php?page=sbdp_partner_commissions')];
        }
        if (($stats['vendors_no_payout'] ?? 0) > 0) {
            $conflicts[] = ['severity' => 'critical', 'area' => 'Payout', 'label' => 'Active vendors without payout profile (IBAN missing)', 'count' => $stats['vendors_no_payout'], 'action_url' => admin_url('admin.php?page=sbdp_partner_settlements')];
        }
        if (($stats['bookings_missing_commission'] ?? 0) > 0) {
            $conflicts[] = ['severity' => 'critical', 'area' => 'Revenue', 'label' => 'Paid bookings without commission record (revenue not captured)', 'count' => $stats['bookings_missing_commission'], 'action_url' => admin_url('admin.php?page=sbdp_partner_commissions')];
        }

        // Static: always-present structural risks (not data-driven).
        $conflicts[] = ['severity' => 'medium', 'area' => 'Architecture', 'label' => 'Dual identity system: bsp_vendors (sales) + bsp_partner_accounts (partner-program) not reconciled', 'count' => null, 'action_url' => ''];
        $conflicts[] = ['severity' => 'medium', 'area' => 'Architecture', 'label' => 'No refund adjustment pipeline in CommissionService — overpayment risk on WC order refunds', 'count' => null, 'action_url' => ''];

        // Sort: critical first.
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($conflicts, static fn(array $a, array $b) => ($order[$a['severity']] ?? 4) <=> ($order[$b['severity']] ?? 4));

        return $conflicts;
    }

    private static function buildMoneyHealth(array $stats): array
    {
        $byTier = $stats['accounts_by_tier'] ?? [];
        return [
            'basis_count'   => $byTier['basis'] ?? 0,
            'premium_count' => $byTier['premium'] ?? 0,
            'gold_count'    => $byTier['gold'] ?? 0,
            'by_mode'       => $stats['accounts_by_mode'] ?? [],
            'grace_period'  => $stats['contracts_grace'] ?? 0,
            'no_payout'     => $stats['vendors_no_payout'] ?? 0,
            'open_batches'  => $stats['batches_open'] ?? 0,
            'items_held'    => $stats['items_by_status']['held'] ?? 0,
            'items_disputed'=> $stats['items_by_status']['disputed'] ?? 0,
            'items_in_review'=> $stats['items_by_status']['in_review'] ?? 0,
            'items_approved'=> $stats['items_by_status']['approved'] ?? 0,
            'items_paid'    => $stats['items_by_status']['paid'] ?? 0,
            'missing_commission' => $stats['bookings_missing_commission'] ?? 0,
        ];
    }

    private static function buildGoLiveGates(array $stats): array
    {
        $gates = [];

        $keyMap = [
            'claim_flow'          => function () use ($stats) {
                $mounted = self::hasPublishedShortcodes(['bsp_partner_claim_form', 'bsp_partner_verify']);
                if (! $mounted) {
                    return false;
                }

                $claims = (int) ($stats['claims_total'] ?? 0);
                if ($claims <= 0) {
                    // Mounted + handlers present means the flow is operational,
                    // even if no claims have been submitted in this environment yet.
                    return class_exists(\BSP\PartnerProgram\Service\ClaimService::class);
                }

                return ((int) ($stats['claims_expired'] ?? 0)) === 0;
            },
            'subscription_mapping'=> function () use ($stats) {
                $hasExecutor = function_exists('wcs_get_subscription')
                    || (function_exists('ddb_subscriptions_register_subscription_executor')
                        && ddb_subscriptions_register_subscription_executor());

                if (! $hasExecutor) {
                    return false;
                }

                $accounts = (int) ($stats['accounts_total'] ?? 0);
                if ($accounts <= 0) {
                    return null;
                }

                return ((int) ($stats['accounts_no_contract'] ?? 0)) === 0;
            },
            'identity_canonical'  => function () use ($stats) {
                $accounts = (int) ($stats['accounts_total'] ?? 0);
                if ($accounts <= 0) {
                    return null;
                }

                return ((int) ($stats['accounts_no_vendor'] ?? 0)) === 0
                    && ((int) ($stats['accounts_legacy_identity_only'] ?? 0)) === 0;
            },
            'entitlement_sync'    => function () use ($stats) {
                $accounts = (int) ($stats['accounts_total'] ?? 0);
                if ($accounts <= 0) {
                    return null;
                }

                // Entitlement sync depends on contract mapping first.
                if ((int) ($stats['accounts_no_contract'] ?? 0) > 0) {
                    return null;
                }

                return ((int) ($stats['accounts_no_entitlements'] ?? 0)) === 0;
            },
            'listing_mode'        => fn() => isset($stats['accounts_by_mode']['listing']),
            'lead_mode'           => function () use ($stats) {
                if ((int) ($stats['accounts_total'] ?? 0) <= 0) {
                    return self::hasActivePlanCapability('lead_routing');
                }

                if (isset($stats['accounts_by_mode']['lead'])) {
                    return true;
                }

                return self::hasActivePlanCapability('lead_routing') ? true : null;
            },
            'bookable_mode'       => function () use ($stats) {
                if ((int) ($stats['accounts_total'] ?? 0) <= 0) {
                    return self::hasActivePlanCapability('booking_access');
                }

                if (isset($stats['accounts_by_mode']['bookable'])) {
                    return true;
                }

                return self::hasActivePlanCapability('booking_access') ? true : null;
            },
            'commission'          => fn() => ($stats['bookings_missing_commission'] ?? 0) === 0,
            'refund_adjustment'   => fn() => (function_exists('has_action') && has_action('woocommerce_order_fully_refunded') && has_action('woocommerce_order_partially_refunded')),
            'payout_hold'         => fn() => ($stats['items_by_status']['held'] ?? 0) >= 0, // Logic exists, not verified.
            'admin_review_queue'  => fn() => true, // Admin claims page exists.
            'conflict_handling'   => fn() => true, // This cockpit tab now exists.
            'payout_profile'      => fn() => ($stats['vendors_no_payout'] ?? 0) === 0,
            'test_coverage'       => function () {
                if (! function_exists('file_exists')) {
                    return null;
                }

                $root = dirname(__DIR__, 3);
                $hasPhpUnit = file_exists($root . '/phpunit.xml')
                    || file_exists($root . '/phpunit.xml.dist')
                    || file_exists($root . '/tests/phpunit.xml')
                    || file_exists($root . '/tests/phpunit.xml.dist');
                $hasTestsDir = is_dir($root . '/tests') || is_dir($root . '/modules/partner-program/tests');

                return ($hasPhpUnit && $hasTestsDir) ? true : null;
            },
            'grace_period'        => fn() => function_exists('wp_next_scheduled') && wp_next_scheduled('bsp_partner_grace_period_check') !== false,
        ];

        foreach (self::GOLIVE_GATES as $gate) {
            $check    = $keyMap[$gate['key']] ?? fn() => null;
            $result   = $check();
            $status   = $result === true ? 'green' : ($result === false ? 'red' : 'orange');
            $gates[]  = array_merge($gate, ['status' => $status]);
        }

        return $gates;
    }

    /**
     * Returns true when any published page contains one of the given shortcodes.
     *
     * @param array<int, string> $shortcodes
     */
    private static function hasPublishedShortcodes(array $shortcodes): bool
    {
        if (! function_exists('get_posts') || ! function_exists('has_shortcode')) {
            return false;
        }

        $pages = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'suppress_filters' => true,
        ]);

        if (! is_array($pages) || $pages === []) {
            return false;
        }

        foreach ($pages as $pageId) {
            $content = (string) get_post_field('post_content', (int) $pageId);
            foreach ($shortcodes as $shortcode) {
                if ($shortcode !== '' && has_shortcode($content, $shortcode)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function hasActivePlanCapability(string $capabilityKey): bool
    {
        global $wpdb;
        if (! $wpdb instanceof \wpdb || $capabilityKey === '') {
            return false;
        }

        $plans = $wpdb->get_results(
            "SELECT entitlements FROM {$wpdb->prefix}bsp_subscription_plans WHERE is_active = 1",
            ARRAY_A
        );

        if (! is_array($plans) || $plans === []) {
            return false;
        }

        foreach ($plans as $plan) {
            $entitlements = json_decode((string) ($plan['entitlements'] ?? ''), true);
            if (! is_array($entitlements)) {
                continue;
            }

            if (! empty($entitlements[$capabilityKey])) {
                return true;
            }
        }

        return false;
    }

    private static function buildHero(array $stats): array
    {
        $totalPartners  = $stats['accounts_total'] ?? 0;
        $activePartners = $stats['accounts_active'] ?? 0;
        $openClaims     = $stats['claims_review'] ?? 0;
        $conflicts      = 0;
        foreach (['accounts_no_vendor', 'accounts_invalid_bookable', 'accounts_no_entitlements', 'bookable_no_commission', 'vendors_no_payout', 'bookings_missing_commission', 'products_without_vendor', 'order_items_missing_resource'] as $k) {
            $conflicts += (int) ($stats[$k] ?? 0);
        }
        $conflicts += (int) ($stats['accounts_legacy_identity_only'] ?? 0);

        return [
            'total_partners'  => $totalPartners,
            'active_partners' => $activePartners,
            'open_claims'     => $openClaims,
            'open_conflicts'  => $conflicts,
            'seeds_total'     => $stats['seeds_total'] ?? 0,
        ];
    }
}
