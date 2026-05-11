<?php
/**
 * Global unified design system stylesheet loader
 * Loads consolidated design tokens and component styles across all surfaces
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', function() {
    if (is_admin()) {
        return;
    }

    $is_elementor_context =
        isset($_GET['elementor-preview'])
        || isset($_GET['elementor_library'])
        || isset($_GET['elementor-library']);

    if (! $is_elementor_context && class_exists('\\Elementor\\Plugin')) {
        $plugin = \Elementor\Plugin::$instance;
        if ($plugin && method_exists($plugin, 'editor') && $plugin->editor && method_exists($plugin->editor, 'is_edit_mode') && $plugin->editor->is_edit_mode()) {
            $is_elementor_context = true;
        }
        if ($plugin && isset($plugin->preview) && method_exists($plugin->preview, 'is_preview_mode') && $plugin->preview->is_preview_mode()) {
            $is_elementor_context = true;
        }
    }

    // Global design system tokens - load on all public pages
    wp_enqueue_style(
        'sbdp-design-system',
        SBDP_URL . 'assets/css/design-system.css',
        [],
        SBDP_VER
    );

    // Unified flow system — commercial + discovery module classes. Load site-wide.
    wp_enqueue_style(
        'sbdp-flow-system',
        SBDP_URL . 'assets/css/ddb-flow-system.css',
        ['sbdp-design-system'],
        SBDP_VER
    );

    // Day planner surface styles - load when planner is present
    wp_enqueue_style(
        'sbdp-day-planner',
        SBDP_URL . 'assets/css/day-planner.css',
        ['sbdp-design-system'],
        SBDP_VER
    );

    // Homepage module CSS — load on front page only
    if (is_front_page() || is_home()) {
        wp_enqueue_style(
            'sbdp-homepage',
            SBDP_URL . 'assets/css/homepage.css',
            ['sbdp-design-system', 'sbdp-flow-system'],
            SBDP_VER
        );
    }

    // Commerce surfaces (cart, checkout, account, thank-you, order detail)
    $is_commerce_page = function_exists('is_cart') && is_cart()
        || function_exists('is_checkout') && is_checkout()
        || function_exists('is_account_page') && is_account_page();

    if (! $is_elementor_context && $is_commerce_page) {
        wp_enqueue_style(
            'sbdp-cart-checkout',
            SBDP_URL . 'assets/css/sbdp-cart-checkout.css',
            ['sbdp-design-system', 'sbdp-flow-system'],
            SBDP_VER
        );
    }

}, 9); // Priority 9 ensures it loads before other styles

// Offerte form styles are enqueued inline in OfferteForm.php shortcode
