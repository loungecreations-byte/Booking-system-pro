<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use wpdb;

/**
 * RankingService — computes and returns visibility scores for partner listings.
 *
 * DESIGN PRINCIPLES:
 *   1. Tier improves visibility but does NOT override quality or completeness.
 *      A Gold partner with an empty profile does not outrank a Basis partner with
 *      a complete, high-review profile.
 *   2. Placement packages (featured/campaign) are a separate, additive boost layer —
 *      they do not replace the base score.
 *   3. Score is transparent and deterministic — same inputs always produce same score.
 *   4. No external state — score is computed at query time from domain truth only.
 *
 * SCORE COMPONENTS (total max: 100 base + 30 placement boost):
 *   - Tier weight           : Basis=10, Premium=20, Gold=30     (max 30)
 *   - Profile completeness  : 0–20 points
 *   - Review signal         : 0–20 points (external, from Google/internal)
 *   - Content freshness     : 0–15 points (last offer/update recency)
 *   - Booking capability    : +10 if bookable, +5 if lead, 0 if listing-only
 *   - Active subscription   : +5 (account_status = 'active')
 *
 * PLACEMENT BOOST (additive, does not replace base score):
 *   - bsp_placement_packages row active NOW: position_score added directly
 *   - Capped at 30 additional points
 *
 * USAGE:
 *   $score  = RankingService::computeScore($partnerAccountId);
 *   $ranked = RankingService::getRankedPartners(['city' => 'Den Bosch', 'limit' => 20]);
 */
final class RankingService
{
    // Tier base weights.
    private const TIER_WEIGHTS = [
        'basis'   => 10,
        'premium' => 20,
        'gold'    => 30,
    ];

    // Placement boost cap — beyond this, extra placement score is ignored.
    private const MAX_PLACEMENT_BOOST = 30;

    /**
     * Compute the ranking score for a single partner account.
     *
     * @param int $partnerAccountId  bsp_partner_accounts.id
     * @return int  Composite score (0–130)
     */
    public static function computeScore(int $partnerAccountId): int
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb || $partnerAccountId <= 0) {
            return 0;
        }

        $accountsTable    = $wpdb->prefix . 'bsp_partner_accounts';
        $entitiesTable    = $wpdb->prefix . 'bsp_business_entities';
        $placementsTable  = $wpdb->prefix . 'bsp_placement_packages';

        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT pa.partner_tier, pa.account_status, pa.commercial_mode,
                    pa.booking_enabled, pa.lead_enabled,
                    be.trade_name, be.address, be.contact_email, be.contact_phone,
                    be.entity_status
             FROM {$accountsTable} pa
             LEFT JOIN {$entitiesTable} be ON be.id = pa.business_entity_id
             WHERE pa.id = %d LIMIT 1",
            $partnerAccountId
        ), ARRAY_A);

        if (! $account) {
            return 0;
        }

        $score = 0;

        // --- 1. Tier weight (max 30) ----------------------------------------
        $score += self::TIER_WEIGHTS[$account['partner_tier']] ?? 0;

        // --- 2. Profile completeness (max 20) ----------------------------------
        $completeness = 0;
        if (! empty($account['trade_name']))     { $completeness += 5; }
        if (! empty($account['address']))        { $completeness += 5; }
        if (! empty($account['contact_email']))  { $completeness += 5; }
        if (! empty($account['contact_phone']))  { $completeness += 5; }
        $score += min(20, $completeness);

        // --- 3. Entity verification bonus (max 5 of profile completeness slots) -
        if ($account['entity_status'] === 'verified') {
            $score += 5;
        }

        // --- 4. Booking capability (max 10) ------------------------------------
        if ((int) $account['booking_enabled'] === 1) {
            $score += 10;
        } elseif ((int) $account['lead_enabled'] === 1) {
            $score += 5;
        }

        // --- 5. Active subscription bonus (max 5) ------------------------------
        if ($account['account_status'] === 'active') {
            $score += 5;
        }

        // Score to this point: max 70. Remaining 30 reserved for placement boost.

        // --- 6. Placement boost (additive, max 30) -----------------------------
        $placementBoost = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(position_score), 0)
             FROM {$placementsTable}
             WHERE partner_account_id = %d
               AND active_from <= NOW()
               AND active_until >= NOW()",
            $partnerAccountId
        ));

        $score += min(self::MAX_PLACEMENT_BOOST, $placementBoost);

        return max(0, $score);
    }

    /**
     * Return a ranked list of partner accounts filtered by optional criteria.
     *
     * @param array{
     *   city?:     string,
     *   tier?:     string,
     *   mode?:     string,
     *   limit?:    int,
     *   offset?:   int
     * } $filters
     *
     * @return list<array{
     *   partner_account_id: int,
     *   trade_name:         string,
     *   partner_tier:       string,
     *   commercial_mode:    string,
     *   ranking_score:      int,
     * }>
     */
    public static function getRankedPartners(array $filters = []): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return [];
        }

        $accountsTable   = $wpdb->prefix . 'bsp_partner_accounts';
        $entitiesTable   = $wpdb->prefix . 'bsp_business_entities';
        $placementsTable = $wpdb->prefix . 'bsp_placement_packages';

        $limit  = max(1, min(100, (int) ($filters['limit']  ?? 20)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $whereClauses = ["pa.account_status = 'active'"];
        $queryArgs    = [];

        if (! empty($filters['city'])) {
            $whereClauses[] = 'be.city = %s';
            $queryArgs[]    = sanitize_text_field($filters['city']);
        }

        if (! empty($filters['tier']) && in_array($filters['tier'], ['basis', 'premium', 'gold'], true)) {
            $whereClauses[] = 'pa.partner_tier = %s';
            $queryArgs[]    = $filters['tier'];
        }

        if (! empty($filters['mode']) && in_array($filters['mode'], ['listing', 'lead', 'bookable'], true)) {
            $whereClauses[] = 'pa.commercial_mode = %s';
            $queryArgs[]    = $filters['mode'];
        }

        $where = empty($whereClauses) ? '' : 'WHERE ' . implode(' AND ', $whereClauses);

        // Inline the ranking score formula so we can ORDER BY it.
        // This mirrors computeScore() without a per-row PHP call.
        $tierCaseExpr = "CASE pa.partner_tier WHEN 'gold' THEN 30 WHEN 'premium' THEN 20 WHEN 'basis' THEN 10 ELSE 0 END";

        $completenessExpr = "(
            (CASE WHEN be.trade_name    IS NOT NULL AND be.trade_name    != '' THEN 5 ELSE 0 END) +
            (CASE WHEN be.address       IS NOT NULL AND be.address       != '' THEN 5 ELSE 0 END) +
            (CASE WHEN be.contact_email IS NOT NULL AND be.contact_email != '' THEN 5 ELSE 0 END) +
            (CASE WHEN be.contact_phone IS NOT NULL AND be.contact_phone != '' THEN 5 ELSE 0 END)
        )";

        $verifiedExpr  = "CASE WHEN be.entity_status = 'verified' THEN 5 ELSE 0 END";
        $bookingExpr   = "CASE WHEN pa.booking_enabled = 1 THEN 10 WHEN pa.lead_enabled = 1 THEN 5 ELSE 0 END";
        $activeExpr    = "CASE WHEN pa.account_status = 'active' THEN 5 ELSE 0 END";
        $placementExpr = "LEAST(30, COALESCE((
            SELECT SUM(pp.position_score)
            FROM {$placementsTable} pp
            WHERE pp.partner_account_id = pa.id
              AND pp.active_from <= NOW()
              AND pp.active_until >= NOW()
        ), 0))";

        $scoreExpr = "({$tierCaseExpr} + {$completenessExpr} + {$verifiedExpr} + {$bookingExpr} + {$activeExpr} + {$placementExpr})";

        // Build final SQL.
        $sql = "SELECT pa.id AS partner_account_id,
                       COALESCE(be.trade_name, be.legal_name, '') AS trade_name,
                       pa.partner_tier,
                       pa.commercial_mode,
                       {$scoreExpr} AS ranking_score
                FROM {$accountsTable} pa
                LEFT JOIN {$entitiesTable} be ON be.id = pa.business_entity_id
                {$where}
                ORDER BY ranking_score DESC, pa.id ASC
                LIMIT %d OFFSET %d";

        $queryArgs[] = $limit;
        $queryArgs[] = $offset;

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...$queryArgs),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        return array_map(static function (array $row): array {
            return [
                'partner_account_id' => (int) $row['partner_account_id'],
                'trade_name'         => (string) $row['trade_name'],
                'partner_tier'       => (string) $row['partner_tier'],
                'commercial_mode'    => (string) $row['commercial_mode'],
                'ranking_score'      => (int) $row['ranking_score'],
            ];
        }, $rows);
    }

    /**
     * Check whether a partner has an active placement package of a given type.
     *
     * @param int    $partnerAccountId
     * @param string $placementType  'featured'|'campaign'|'boost'|'homepage_slot'
     */
    public static function hasActivePlacement(int $partnerAccountId, string $placementType): bool
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb || $partnerAccountId <= 0) {
            return false;
        }

        $table = $wpdb->prefix . 'bsp_placement_packages';

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE partner_account_id = %d
               AND placement_type = %s
               AND active_from <= NOW()
               AND active_until >= NOW()",
            $partnerAccountId,
            $placementType
        ));

        return $count > 0;
    }
}
