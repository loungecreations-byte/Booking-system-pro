<?php

declare(strict_types=1);

namespace BSP\PartnerProgram;

use BSP\Core\Interfaces\ModuleInterface;
use BSP\PartnerProgram\Support\Installer;

if (class_exists(__NAMESPACE__ . '\\Module', false)) {
    return;
}

/**
 * Partner Program module — governs claim flow, subscription contracts,
 * partner entitlements, commission rules, and settlement batches.
 *
 * Truth hierarchy:
 *   Google/external seed  →  bsp_place_seeds       (discovery only)
 *   Business identity     →  bsp_business_entities  (OMDB / admin)
 *   Partner account layer →  bsp_partner_accounts   (portal/account state only)
 *   Commercial owner      →  bsp_vendors            (sales domain)
 *   Billing execution     →  WooCommerce            (execution only)
 */
final class Module implements ModuleInterface
{
    private static bool $booted = false;

    public function init(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        require_once __DIR__ . '/bootstrap-executors.php';

        Installer::maybeInstall();

        if (! function_exists('add_action')) {
            return;
        }

        // REST API.
        add_action('rest_api_init', static function () {
            (new \BSP\PartnerProgram\Rest\ClaimController())->register_routes();
            (new \BSP\PartnerProgram\Rest\EntitlementController())->register_routes();
            (new \BSP\PartnerProgram\Rest\SettlementController())->register_routes();
            (new \BSP\PartnerProgram\Rest\SeedController())->register_routes();
            (new \BSP\PartnerProgram\Rest\CommissionController())->register_routes();
        });

        // WP Admin pages.
        \BSP\PartnerProgram\Admin\PartnerAdmin::init();
        \BSP\PartnerProgram\Admin\ProductMeta::init();
        \BSP\PartnerProgram\Admin\Settings::init();
        \BSP\PartnerProgram\Admin\PreflightNotice::init();

        // Governance cockpit — registers Partner Programma tab in sbdp_governance.
        add_filter('bsp_governance_extra_tabs', [\BSP\PartnerProgram\Admin\GovernanceDashboard::class, 'registerTab']);
        add_filter('bsp_governance_hero_cards', [\BSP\PartnerProgram\Admin\GovernanceDashboard::class, 'heroCard']);
        add_action('bsp_governance_render_tab_partner', [\BSP\PartnerProgram\Admin\GovernanceDashboard::class, 'render']);

        // Frontend shortcodes + claim form handler.
        \BSP\PartnerProgram\Frontend\PartnerPortal::init();
        \BSP\PartnerProgram\Frontend\PayoutProfileHandler::init();

        // Frontend CSS.
        add_action('wp_enqueue_scripts', [self::class, 'enqueueFrontendAssets']);

        // Local billing executor fallback when Woo Subscriptions is unavailable.
        \BSP\PartnerProgram\Service\LocalSubscriptionExecutor::init();

        // WP-CLI commands.
        \BSP\PartnerProgram\CLI\Commands::register();

        // WooCommerce Subscriptions billing sync hooks (safe-guarded).
        add_action('woocommerce_subscription_status_updated', [\BSP\PartnerProgram\Service\PartnerBillingSync::class, 'handleStatusChange'], 10, 3);
        add_action('woocommerce_subscription_payment_complete', [\BSP\PartnerProgram\Service\PartnerBillingSync::class, 'handleRenewal'], 10, 1);

        // Booking commission capture.
        add_action('woocommerce_payment_complete', [\BSP\PartnerProgram\Service\CommissionService::class, 'captureFromOrder'], 20, 1);

        // Refund adjustment — prevent overpayment on WC order refunds.
        add_action('woocommerce_order_fully_refunded', [\BSP\PartnerProgram\Service\CommissionService::class, 'adjustFromFullRefund'], 10, 2);
        add_action('woocommerce_order_partially_refunded', [\BSP\PartnerProgram\Service\CommissionService::class, 'adjustFromPartialRefund'], 10, 2);

        // Grace period expiry — runs daily via wp_cron.
        \BSP\PartnerProgram\Service\GracePeriodService::scheduleCron();
        add_action('bsp_partner_grace_period_check', [\BSP\PartnerProgram\Service\GracePeriodService::class, 'runExpiry']);
    }

    public static function enqueueFrontendAssets(): void
    {
        wp_register_style(
            'bsp-partner-portal',
            plugins_url('assets/partner-portal.css', __FILE__),
            [],
            '1.0.0'
        );

        if (self::isPartnerProgramPage()) {
            wp_enqueue_style('bsp-partner-portal');
        }
    }

    public static function enqueuePortalStyle(): void
    {
        if (! function_exists('wp_enqueue_style')) {
            return;
        }

        wp_enqueue_style('bsp-partner-portal');
    }

    private static function isPartnerProgramPage(): bool
    {
        if (! function_exists('is_page')) {
            return false;
        }

        if (is_page(array(
            'partner-profile',
            'premium-members',
            'partner-portal',
            'partner-claim',
            'partner-verify',
            'partner-uitbetaling',
        ))) {
            return true;
        }

        if (! function_exists('is_singular') || ! is_singular() || ! function_exists('has_shortcode')) {
            return false;
        }

        $post = get_post();
        if (! $post instanceof \WP_Post) {
            return false;
        }

        foreach (array('bsp_partner_dashboard', 'bsp_partner_claim_form', 'bsp_partner_verify', 'bsp_partner_pricing', 'bsp_payout_profile') as $shortcode) {
            if (has_shortcode((string) $post->post_content, $shortcode)) {
                return true;
            }
        }

        return false;
    }
}

if (! class_exists('BSPModule\\PartnerProgram\\Module', false)) {
    class_alias(Module::class, 'BSPModule\\PartnerProgram\\Module');
}
