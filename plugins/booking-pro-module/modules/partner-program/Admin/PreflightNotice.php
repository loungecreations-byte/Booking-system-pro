<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Admin;

use BSP\PartnerProgram\Service\PageAuditService;
use BSP\PartnerProgram\Support\Installer;

use function add_action;
use function add_query_arg;
use function admin_url;
use function class_exists;
use function current_user_can;
use function esc_html;
use function esc_url;
use function function_exists;
use function get_current_screen;
use function in_array;
use function sanitize_text_field;
use function wp_nonce_url;
use function wp_safe_redirect;
use function wp_verify_nonce;

final class PreflightNotice
{
    private const ACTION_RUN_PAGE_NORMALIZATION = 'bsp_partner_page_normalize';

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'handleNormalizationAction']);
        add_action('admin_notices', [self::class, 'render']);
    }

    public static function handleNormalizationAction(): void
    {
        if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_text_field($_GET['bsp_action'] ?? '');
        if ($action !== self::ACTION_RUN_PAGE_NORMALIZATION) {
            return;
        }

        $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');
        if (! wp_verify_nonce($nonce, self::ACTION_RUN_PAGE_NORMALIZATION)) {
            return;
        }

        Installer::runPageNormalization();

        $redirectUrl = remove_query_arg(['bsp_action', '_wpnonce']);
        $redirectUrl = add_query_arg('bsp_pages_normalized', '1', (string) $redirectUrl);
        wp_safe_redirect($redirectUrl);
        exit;
    }

    public static function render(): void
    {
        if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
            return;
        }

        if (! function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if (! $screen) {
            return;
        }

        $screenId = (string) $screen->id;
        $allowed  = [
            'bookings_page_sbdp_governance',
            'bookings_page_sbdp_partners',
            'bookings_page_sbdp_partner_claims',
            'bookings_page_sbdp_partner_settlements',
            'bookings_page_sbdp_partner_commissions',
            'edit-page',
            'page',
        ];

        if (! in_array($screenId, $allowed, true)) {
            return;
        }

        if (isset($_GET['bsp_pages_normalized']) && (string) $_GET['bsp_pages_normalized'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html('Paginanormalisatie is uitgevoerd.') . '</p></div>';
        }

        $normalizeUrl = wp_nonce_url(
            add_query_arg('bsp_action', self::ACTION_RUN_PAGE_NORMALIZATION, admin_url('admin.php?page=sbdp_governance&tab=partner')),
            self::ACTION_RUN_PAGE_NORMALIZATION
        );

        $missing = [];

        $hasSubscriptionExecutor = function_exists('wcs_get_subscription')
            || (function_exists('ddb_subscriptions_register_subscription_executor')
                && ddb_subscriptions_register_subscription_executor());

        if (! $hasSubscriptionExecutor) {
            $missing[] = 'WooCommerce Subscriptions ontbreekt of is niet actief (functie wcs_get_subscription niet beschikbaar).';
        }

        $hasMollie = (function_exists('ddb_subscriptions_register_mollie_executor')
                && ddb_subscriptions_register_mollie_executor())
            || function_exists('mollieWooCommerce')
            || function_exists('mollieWooCommerceSession')
            || class_exists('Mollie\\Inpsyde\\PaymentGateway\\PaymentGateway');

        if (! $hasMollie) {
            $missing[] = 'Mollie Payments executor ontbreekt of is niet actief.';
        }

        if ($missing !== []) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html('Partner Programma preflight blokkeert go-live:') . '</strong></p><ul style="margin:0 0 0 16px; list-style:disc;">';
            foreach ($missing as $line) {
                echo '<li>' . esc_html($line) . '</li>';
            }
            echo '</ul><p style="margin-top:10px"><a class="button button-secondary" href="' . esc_url($normalizeUrl) . '">' . esc_html('Voer paginanormalisatie nu uit') . '</a></p></div>';
        }

        $pageIssues = PageAuditService::getIssues();
        if ($pageIssues === []) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>' . esc_html('Custom pagina-audit heeft backend-afwijkingen gevonden:') . '</strong></p><ul style="margin:0 0 0 16px; list-style:disc;">';
        foreach ($pageIssues as $issue) {
            $summary = implode(' ', $issue['issues']);
            echo '<li><a href="' . esc_url((string) $issue['edit_url']) . '"><strong>' . esc_html((string) $issue['title']) . '</strong></a> <span style="color:#526173">(' . esc_html((string) $issue['slug']) . ')</span>: ' . esc_html($summary) . '</li>';
        }
        echo '</ul><p style="margin-top:10px"><a class="button button-secondary" href="' . esc_url($normalizeUrl) . '">' . esc_html('Voer paginanormalisatie nu uit') . '</a></p></div>';
    }
}
