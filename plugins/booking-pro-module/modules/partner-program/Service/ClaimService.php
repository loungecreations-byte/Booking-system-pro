<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use wpdb;
use function bin2hex;
use function random_bytes;
use function current_time;
use function wp_mail;
use function home_url;
use function sanitize_email;
use function sanitize_text_field;
use function is_user_logged_in;
use function get_userdata;
use BSP\PartnerProgram\Admin\Settings;

/**
 * ClaimService — manages the "claim a business listing" workflow.
 *
 * Workflow:
 *   1. Public user submits claim  → submitClaim() → token emailed
 *   2. User clicks email link     → verifyClaim() → status = 'email_verified'
 *   3. Admin reviews & approves   → adminApproveClaim() → creates business_entity + partner_account
 *
 * ClaimService never creates WooCommerce subscriptions — that is handled by the
 * subscription plan checkout flow. ClaimService creates the identity + account shell
 * so billing can link to it.
 */
final class ClaimService
{
    private const TOKEN_BYTES = 32;

    // ---------------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------------

    /**
     * Submit a new claim request from a WordPress user.
     *
     * @param int $placeSeedId  bsp_place_seeds.id the user wants to claim
     * @param int $wpUserId     WP user submitting the claim (must be logged in)
     *
     * @return array{success: bool, message: string, claim_id?: int}
     */
    public static function submitClaim(int $placeSeedId, int $wpUserId): array
    {
        if ($placeSeedId <= 0 || $wpUserId <= 0) {
            return ['success' => false, 'message' => 'Ongeldige aanvraag.'];
        }

        $user = get_userdata($wpUserId);
        if (! $user) {
            return ['success' => false, 'message' => 'Gebruiker niet gevonden.'];
        }

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return ['success' => false, 'message' => 'Database niet beschikbaar.'];
        }

        $seedsTable   = $wpdb->prefix . 'bsp_place_seeds';
        $claimsTable  = $wpdb->prefix . 'bsp_claim_requests';

        // Verify the seed exists.
        $seed = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name FROM {$seedsTable} WHERE id = %d LIMIT 1",
            $placeSeedId
        ), ARRAY_A);

        if (! $seed) {
            return ['success' => false, 'message' => 'Locatie niet gevonden.'];
        }

        // Block duplicate active claims for the same seed.
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$claimsTable}
             WHERE place_seed_id = %d AND claim_status NOT IN ('rejected', 'duplicate', 'expired')
             LIMIT 1",
            $placeSeedId
        ));

        if ($existing) {
            return ['success' => false, 'message' => 'Er loopt al een aanvraag voor deze locatie.'];
        }

        // Generate secure verification token.
        $token     = bin2hex(random_bytes(self::TOKEN_BYTES));
        $ttlHours  = max(1, (int) Settings::get('claim_token_ttl_hours', '48'));
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlHours * 3600);

        $inserted = $wpdb->insert($claimsTable, [
            'place_seed_id'         => $placeSeedId,
            'claimant_wp_user_id'   => $wpUserId,
            'business_entity_id'    => null,
            'claim_status'          => 'submitted',
            'verification_token'    => $token,
            'token_expires_at'      => $expiresAt,
            'verification_method'   => 'email',
        ]);

        if (! $inserted) {
            return ['success' => false, 'message' => 'Aanvraag kon niet worden opgeslagen.'];
        }

        $claimId = (int) $wpdb->insert_id;

        // Send verification email.
        self::sendVerificationEmail($user->user_email, $user->display_name, $token, $seed['name']);

        return [
            'success'  => true,
            'message'  => 'Verificatie-e-mail verstuurd. Controleer uw inbox.',
            'claim_id' => $claimId,
        ];
    }

    /**
     * Verify a claim via the token from the email link.
     *
     * @param string $token
     * @return array{success: bool, message: string, claim_id?: int}
     */
    public static function verifyClaim(string $token): array
    {
        if (strlen($token) !== self::TOKEN_BYTES * 2) {
            return ['success' => false, 'message' => 'Ongeldig verificatietoken.'];
        }

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return ['success' => false, 'message' => 'Database niet beschikbaar.'];
        }

        $claimsTable = $wpdb->prefix . 'bsp_claim_requests';

        $claim = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$claimsTable}
             WHERE verification_token = %s AND claim_status = 'submitted'
             LIMIT 1",
            $token
        ), ARRAY_A);

        if (! $claim) {
            return ['success' => false, 'message' => 'Token niet gevonden of al gebruikt.'];
        }

        if (strtotime($claim['token_expires_at']) < time()) {
            return ['success' => false, 'message' => 'Verificatielink is verlopen. Probeer opnieuw.'];
        }

        $wpdb->update($claimsTable, [
            'claim_status' => 'under_review',
            'reviewed_at'  => current_time('mysql'),
        ], ['id' => (int) $claim['id']]);

        return [
            'success'  => true,
            'message'  => 'E-mailadres geverifieerd. Uw aanvraag wordt beoordeeld.',
            'claim_id' => (int) $claim['id'],
        ];
    }

    /**
     * Admin approval: creates a business_entity and partner_account shell.
     * Does NOT create a WooCommerce subscription — admin (or billing flow) does that separately.
     *
     * @param int $claimRequestId
     * @param int $adminUserId     WP user performing the approval
     *
     * @return array{success: bool, message: string, partner_account_id?: int}
     */
    public static function adminApproveClaim(int $claimRequestId, int $adminUserId): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return ['success' => false, 'message' => 'Database niet beschikbaar.'];
        }

        $claimsTable    = $wpdb->prefix . 'bsp_claim_requests';
        $seedsTable     = $wpdb->prefix . 'bsp_place_seeds';
        $entitiesTable  = $wpdb->prefix . 'bsp_business_entities';
        $accountsTable  = $wpdb->prefix . 'bsp_partner_accounts';

        $claim = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$claimsTable} WHERE id = %d AND claim_status = 'under_review' LIMIT 1",
            $claimRequestId
        ), ARRAY_A);

        if (! $claim) {
            return ['success' => false, 'message' => 'Aanvraag niet gevonden of nog niet geverifieerd.'];
        }

        $seed = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$seedsTable} WHERE id = %d LIMIT 1",
            (int) $claim['place_seed_id']
        ), ARRAY_A);

        if (! $seed) {
            return ['success' => false, 'message' => 'Seed locatie niet gevonden.'];
        }

        // Create business entity from seed data.
        $wpdb->insert($entitiesTable, [
            'legal_name'    => $seed['name'],
            'trade_name'    => $seed['name'],
            'address'       => $seed['address'] ?? '',
            'city'          => $seed['city'] ?? '',
            'postal_code'   => $seed['postal_code'] ?? '',
            'place_seed_id' => $seed['id'],
            'entity_status' => 'unverified',
            'verified_by'   => $adminUserId,
        ]);

        $businessEntityId = (int) $wpdb->insert_id;
        if (! $businessEntityId) {
            return ['success' => false, 'message' => 'Kan bedrijfsentiteit niet aanmaken.'];
        }

        // Create partner account (shell — no subscription yet, vendor_id is NULL until admin links a vendor post-onboarding).
        $wpdb->insert($accountsTable, [
            'business_entity_id' => $businessEntityId,
            'vendor_id'          => null, // NULL until admin links a real bsp_vendors.id post-onboarding
            'wp_user_id'         => (int) $claim['claimant_wp_user_id'],
            'partner_tier'       => 'basis',
            'account_status'     => 'onboarding',
            'commercial_mode'    => 'listing',
        ]);

        $partnerAccountId = (int) $wpdb->insert_id;
        if (! $partnerAccountId) {
            return ['success' => false, 'message' => 'Kan partneraccount niet aanmaken.'];
        }

        // Link claim → business_entity, mark verified.
        $wpdb->update($claimsTable, [
            'claim_status'       => 'verified',
            'business_entity_id' => $businessEntityId,
            'reviewed_by'        => $adminUserId,
            'reviewed_at'        => current_time('mysql'),
        ], ['id' => $claimRequestId]);

        // Issue fallback basis entitlements immediately.
        // Resolve plan_id via slug so we are not fragile to seeding order.
        $basisPlanId = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}bsp_subscription_plans
             WHERE plan_slug = 'basis' AND billing_cycle = 'monthly' AND is_active = 1
             ORDER BY id ASC LIMIT 1"
        );
        if ($basisPlanId > 0) {
            EntitlementService::issueFromPlan($partnerAccountId, $basisPlanId, new \DateTime(), null);
        }

        // Notify the claimant.
        $user = get_userdata((int) $claim['claimant_wp_user_id']);
        if ($user) {
            self::sendApprovalEmail($user->user_email, $user->display_name, $seed['name']);
        }

        return [
            'success'            => true,
            'message'            => 'Partner aanvraag goedgekeurd.',
            'partner_account_id' => $partnerAccountId,
        ];
    }

    // ---------------------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------------------

    private static function sendVerificationEmail(
        string $email,
        string $name,
        string $token,
        string $placeName
    ): void {
        $verifyUrl = home_url('/partner-verificatie/?token=' . urlencode($token));
        $ttlHours  = max(1, (int) Settings::get('claim_token_ttl_hours', '48'));

        $subject = 'Bevestig uw partneraanvraag — DagjeDenBosch';
        $body    = sprintf(
            "Beste %s,\n\nBedankt voor uw aanvraag om \"%s\" te claimen op DagjeDenBosch.\n\n" .
            "Klik op onderstaande link om uw e-mailadres te bevestigen:\n%s\n\n" .
            "Deze link is %d uur geldig.\n\nMet vriendelijke groet,\nHet DagjeDenBosch team",
            sanitize_text_field($name),
            sanitize_text_field($placeName),
            esc_url($verifyUrl),
            $ttlHours
        );

        wp_mail(sanitize_email($email), $subject, $body);
    }

    private static function sendApprovalEmail(string $email, string $name, string $placeName): void
    {
        $portalUrl = home_url('/partner-portal/');

        $subject = 'Uw partneraanvraag is goedgekeurd — DagjeDenBosch';
        $body    = sprintf(
            "Beste %s,\n\nGefeliciteerd! Uw aanvraag voor \"%s\" op DagjeDenBosch is goedgekeurd.\n\n" .
            "U kunt nu inloggen op uw partnerportaal:\n%s\n\n" .
            "Met vriendelijke groet,\nHet DagjeDenBosch team",
            sanitize_text_field($name),
            sanitize_text_field($placeName),
            esc_url($portalUrl)
        );

        wp_mail(sanitize_email($email), $subject, $body);
    }
}
