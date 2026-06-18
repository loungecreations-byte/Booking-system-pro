<?php
/**
 * Dutch public aliases for WooCommerce account/cart/checkout routes.
 *
 * WooCommerce is configured with English page slugs in this install. Keep that
 * configuration intact and redirect Dutch public routes to the canonical Woo
 * permalinks so menus, pasted URLs, and Dutch CTA copy do not 404.
 */

declare(strict_types=1);

add_action('template_redirect', static function (): void {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }

    $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    $path = trim((string) parse_url($path, PHP_URL_PATH), '/');
    $path = strtolower(rawurldecode($path));

    $aliases = array(
        'winkelwagen' => function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/'),
        'afrekenen' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout/'),
        'mijn-account' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/'),
    );

    if (! isset($aliases[$path])) {
        return;
    }

    wp_safe_redirect($aliases[$path], 301);
    exit;
}, 1);
