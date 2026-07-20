<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Frontend;

use BSP\PartnerProgram\Service\EntitlementService;
use BSP\PartnerProgram\Service\ClaimService;
use BSP\PartnerProgram\Service\PartnerVendorIdentityService;
use BSP\PartnerProgram\Service\SettlementService;
use function add_shortcode;
use function shortcode_atts;
use function ob_start;
use function ob_get_clean;
use function is_user_logged_in;
use function get_current_user_id;
use function wp_get_current_user;
use function esc_html;
use function esc_url;
use function home_url;
use function sanitize_text_field;
use function absint;
use function wp_nonce_field;
use function check_admin_referer;
use function wp_safe_redirect;
use function wp_login_url;
use function number_format;

/**
 * PartnerPortal — frontend shortcodes for the partner-facing portal.
 *
 * Shortcodes:
 *   [bsp_partner_dashboard]          — full dashboard (tier, entitlements, recent commissions)
 *   [bsp_partner_claim_form]         — form to submit a claim for a place seed
 *   [bsp_partner_verify]             — token verification landing page
 *
 * Register on page /partner-portal/ using the [bsp_partner_dashboard] shortcode.
 */
final class PartnerPortal
{
    public static function init(): void
    {
        add_shortcode('bsp_partner_dashboard', [self::class, 'renderDashboard']);
        add_shortcode('bsp_partner_claim_form', [self::class, 'renderClaimForm']);
        add_shortcode('bsp_partner_verify', [self::class, 'renderVerify']);
        add_shortcode('bsp_partner_pricing', [self::class, 'renderPricing']);

        // Handle claim form POST.
        add_action('init', [self::class, 'handleClaimPost']);
    }

    // -------------------------------------------------------------------------
    // Dashboard shortcode
    // -------------------------------------------------------------------------

    public static function renderDashboard(array $atts = []): string
    {
        \BSP\PartnerProgram\Module::enqueuePortalStyle();

        if (! is_user_logged_in()) {
            return '<div class="bsp-portal-login"><p>' .
                sprintf(
                    /* translators: %s login URL */
                    esc_html__('Je moet %s om je partner dashboard te bekijken.', 'sbdp'),
                    '<a href="' . esc_url(wp_login_url(home_url('/partner-portal/'))) . '">' . esc_html__('inloggen', 'sbdp') . '</a>'
                ) .
                '</p></div>';
        }

        $userId = get_current_user_id();
        $user   = wp_get_current_user();

        global $wpdb;

        $identity = PartnerVendorIdentityService::resolveByUserId($userId);
        $partnerAccountId = (int) ($identity['partner_account_id'] ?? 0);

        $account = null;
        if ($partnerAccountId > 0) {
            $account = $wpdb->get_row($wpdb->prepare(
                "SELECT pa.*, be.legal_name, be.trade_name
                 FROM {$wpdb->prefix}bsp_partner_accounts pa
                 LEFT JOIN {$wpdb->prefix}bsp_business_entities be ON be.id = pa.business_entity_id
                 WHERE pa.id = %d
                 LIMIT 1",
                $partnerAccountId
            ), ARRAY_A);
        }

        if (! $account) {
            return self::renderNoAccount($user->display_name);
        }

        $vendorId     = (int) ($account['vendor_id'] ?? 0);
        $tier         = (string) $account['partner_tier'];
        $status       = (string) $account['account_status'];
        $mode         = (string) $account['commercial_mode'];
        $businessName = $account['trade_name'] ?: $account['legal_name'] ?: $user->display_name;

        $entitlements = $vendorId ? EntitlementService::getAll($vendorId) : EntitlementService::fallbackEntitlements($tier);

        // Recent settlement items.
        $recentItems = [];
        if ($vendorId) {
            $recentItems = $wpdb->get_results($wpdb->prepare(
                "SELECT si.*, DATE(bm.created_at) AS booking_date
                 FROM {$wpdb->prefix}bsp_settlement_items si
                 LEFT JOIN {$wpdb->prefix}bsp_booking_masters bm ON bm.id = si.booking_master_id
                 WHERE si.vendor_id = %d
                 ORDER BY si.id DESC LIMIT 10",
                $vendorId
            ), ARRAY_A) ?: [];
        }

        // Pending total.
        $pendingTotal = (float) ($vendorId ? $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(payout_eur) FROM {$wpdb->prefix}bsp_settlement_items WHERE vendor_id = %d AND item_status = 'pending'",
            $vendorId
        )) : 0);

        // Resolve payout profile page URL from settings or a sensible default.
        $payoutProfileUrl = \BSP\PartnerProgram\Admin\Settings::get('payout_profile_page_url')
            ?: home_url('/partner-uitbetaling/');

        ob_start();
        require __DIR__ . '/../Templates/partner-dashboard.php';
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Claim form shortcode
    // -------------------------------------------------------------------------

    public static function renderClaimForm(array $atts = []): string
    {
        \BSP\PartnerProgram\Module::enqueuePortalStyle();

        if (! is_user_logged_in()) {
            return '<div class="bsp-claim-shell"><div class="bsp-claim-card bsp-claim-card--centered">' .
                '<p class="bsp-claim-kicker">' . esc_html__('Partnerportaal', 'sbdp') . '</p>' .
                '<h1>' . esc_html__('Log in om je locatie te claimen', 'sbdp') . '</h1>' .
                '<p>' . esc_html__('Na het inloggen kun je je bedrijf selecteren en een verificatielink aanvragen.', 'sbdp') . '</p>' .
                '<a class="bsp-btn bsp-btn--primary" href="' . esc_url(wp_login_url(home_url('/partner-claim/'))) . '">' .
                    esc_html__('Inloggen', 'sbdp') .
                '</a>' .
            '</div></div>';
        }

        $msg = sanitize_text_field($_GET['bsp_claim'] ?? '');

        global $wpdb;
        $seeds = $wpdb->get_results(
            "SELECT s.id, s.name, s.city, s.address
             FROM {$wpdb->prefix}bsp_place_seeds s
             LEFT JOIN {$wpdb->prefix}bsp_claim_requests cr ON cr.place_seed_id = s.id
               AND cr.claim_status NOT IN ('rejected', 'duplicate', 'expired')
             WHERE cr.id IS NULL
             ORDER BY s.name ASC LIMIT 500",
            ARRAY_A
        ) ?: [];

        ob_start();
        require __DIR__ . '/../Templates/claim-form.php';
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Verify shortcode — landing page for email token link
    // -------------------------------------------------------------------------

    public static function renderVerify(array $atts = []): string
    {
        \BSP\PartnerProgram\Module::enqueuePortalStyle();

        $token = sanitize_text_field($_GET['token'] ?? '');

        if (! $token) {
            return '<div class="bsp-portal-notice">' . esc_html__('Geen verificatietoken opgegeven.', 'sbdp') . '</div>';
        }

        $result = ClaimService::verifyClaim($token);

        ob_start();
        ?>
        <div class="bsp-portal-verify">
            <?php if ($result['success']) : ?>
                <div class="bsp-portal-notice bsp-portal-notice--success">
                    <strong><?php esc_html_e('Geverifieerd!', 'sbdp'); ?></strong>
                    <p><?php echo esc_html($result['message']); ?></p>
                    <p><?php esc_html_e('Uw aanvraag wordt beoordeeld door ons team. U ontvangt een bevestiging per e-mail.', 'sbdp'); ?></p>
                </div>
            <?php else : ?>
                <div class="bsp-portal-notice bsp-portal-notice--error">
                    <strong><?php esc_html_e('Verificatie mislukt', 'sbdp'); ?></strong>
                    <p><?php echo esc_html($result['message']); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Pricing shortcode — plan catalog mapped to WC subscription products
    // -------------------------------------------------------------------------

    public static function renderPricing(array $atts = []): string
    {
        \BSP\PartnerProgram\Module::enqueuePortalStyle();

        unset($atts);

        global $wpdb;

        $plans = $wpdb->get_results(
            "SELECT id, plan_slug, plan_name, billing_cycle, price_eur, setup_fee_eur
             FROM {$wpdb->prefix}bsp_subscription_plans
             ORDER BY FIELD(plan_slug, 'basis', 'premium', 'gold'), FIELD(billing_cycle, 'monthly', 'annual'), id ASC",
            ARRAY_A
        ) ?: [];

        if ($plans === []) {
            return '<div class="ui-card"><div class="ui-card__body"><p>' . esc_html__('Er zijn nog geen partnerplannen ingesteld.', 'sbdp') . '</p></div></div>';
        }

        $grouped = [];
        foreach ($plans as $plan) {
            $slug = (string) ($plan['plan_slug'] ?? 'onbekend');
            $grouped[$slug][] = $plan;
        }

        ob_start();
        ?>
        <section class="ui-section ui-section--tight">
            <div class="ui-container ui-container--lg ui-stack">
                <div class="ui-card ui-card--featured">
                    <div class="ui-card__body">
                        <p class="ddb-account-hub__eyebrow"><?php esc_html_e('Partner abonnementen', 'sbdp'); ?></p>
                        <h2 class="ui-card__title"><?php esc_html_e('Kies het plan dat bij je aanbod past', 'sbdp'); ?></h2>
                        <p class="ui-card__desc"><?php esc_html_e('Checkout en facturatie lopen via WooCommerce. Je rechten worden daarna gesynchroniseerd in het Partner Programma.', 'sbdp'); ?></p>
                    </div>
                </div>

                <div class="ui-grid ui-grid--3">
                    <?php foreach ($grouped as $slug => $variants) : ?>
                        <article class="ui-card">
                            <div class="ui-card__body">
                                <h3 class="ui-card__title"><?php echo esc_html(ucfirst((string) $slug)); ?></h3>
                                <div class="ui-stack" style="gap:10px;">
                                    <?php foreach ($variants as $variant) :
                                        $planId = (int) ($variant['id'] ?? 0);
                                        $productId = (int) $wpdb->get_var($wpdb->prepare(
                                            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bsp_partner_plan_id' AND meta_value = %d LIMIT 1",
                                            $planId
                                        ));
                                        $productUrl = $productId > 0 ? get_permalink($productId) : '';
                                        ?>
                                        <div class="ui-card" style="background:var(--ui-color-surface-2);">
                                            <div class="ui-card__body">
                                                <p><strong><?php echo esc_html((string) $variant['plan_name']); ?></strong></p>
                                                <p class="ui-card__desc"><?php echo esc_html(ucfirst((string) $variant['billing_cycle'])); ?> · €<?php echo esc_html(number_format((float) ($variant['price_eur'] ?? 0), 2, ',', '.')); ?></p>
                                                <?php if ((float) ($variant['setup_fee_eur'] ?? 0) > 0.0) : ?>
                                                    <p class="ui-card__desc"><?php esc_html_e('Setup', 'sbdp'); ?>: €<?php echo esc_html(number_format((float) $variant['setup_fee_eur'], 2, ',', '.')); ?></p>
                                                <?php endif; ?>

                                                <?php if ($productUrl !== '') : ?>
                                                    <a class="ui-btn ui-btn--primary" href="<?php echo esc_url($productUrl); ?>"><?php esc_html_e('Kies dit plan', 'sbdp'); ?></a>
                                                <?php else : ?>
                                                    <span class="ui-btn ui-btn--ghost" aria-disabled="true"><?php esc_html_e('Product wordt gekoppeld', 'sbdp'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Claim form POST handler
    // -------------------------------------------------------------------------

    public static function handleClaimPost(): void
    {
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        if (! is_admin() && $requestMethod === 'POST' && isset($_POST['bsp_submit_claim'])) {
            if (! is_user_logged_in()) {
                return;
            }

            if (! check_admin_referer('bsp_submit_claim', '_wpnonce')) {
                return;
            }

            $seedId = absint($_POST['place_seed_id'] ?? 0);
            $userId = get_current_user_id();

            $result = ClaimService::submitClaim($seedId, $userId);
            $status = $result['success'] ? 'sent' : 'error';

            wp_safe_redirect(add_query_arg(['bsp_claim' => $status], home_url('/partner-claim/')));
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function renderNoAccount(string $name): string
    {
        ob_start();
        ?>
        <div class="bsp-portal-no-account">
            <p><?php printf(esc_html__('Welkom, %s. Je hebt nog geen partneraccount.', 'sbdp'), esc_html($name)); ?></p>
            <p><a href="<?php echo esc_url(home_url('/partner-worden/')); ?>" class="bsp-btn bsp-btn--primary"><?php esc_html_e('Meld je aan als partner', 'sbdp'); ?></a></p>
        </div>
        <?php
        return ob_get_clean();
    }
}
