<?php
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

/**
 * Quick healthcheck script for Booking System Pro.
 *
 * Usage via WP-CLI: wp eval-file scripts/bsp-healthcheck.php
 */

if (! defined('ABSPATH')) {
    exit("Must be run within WordPress\n");
}

define('BSP_HEALTHCHECK', true);

$pluginMainCandidates = [
    WP_PLUGIN_DIR . '/booking-pro-module/booking-pro-module.php',
    WP_PLUGIN_DIR . '/booking-system-pro/booking-system-pro.php',
];

$pluginLoaded = false;
foreach ($pluginMainCandidates as $pluginMain) {
    if (file_exists($pluginMain)) {
        require_once $pluginMain;
        $pluginLoaded = true;
        break;
    }
}

if (! $pluginLoaded) {
    exit("[FAIL] Unable to load Booking Pro plugin main file\n");
}

$coreModules = [
    'commerce'     => '\\BSP\\Commerce\\Module',
    'planner'      => '\\BSP\\Planner\\Module',
    'sales'        => '\\BSP\\Sales\\Module',
    'intelligence' => '\\BSP\\Intelligence\\Module',
];

foreach ($coreModules as $key => $class) {
    if (! class_exists($class)) {
        echo "[FAIL] Module class missing: {$class}\n";
        return;
    }
}

echo "[OK] All module classes available.\n";

$sampleProductId = 0;
if (function_exists('wc_get_products')) {
    $products = wc_get_products([
        'status' => 'publish',
        'limit'  => 1,
        'return' => 'ids',
    ]);
    if (is_array($products) && ! empty($products)) {
        $sampleProductId = (int) reset($products);
    }
}

if ($sampleProductId <= 0) {
    $fallback = get_posts([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ]);
    if (is_array($fallback) && ! empty($fallback)) {
        $sampleProductId = (int) $fallback[0];
    }
}

$endpoints = [
    ['POST', 'bsp/v1/commerce/calc-price', [
        'base'  => 100,
        'rules' => [ ['type' => 'fixed', 'value' => 5], ['type' => 'percent', 'value' => 10] ],
    ], [200]],
    ['POST', 'bsp/v1/planner/availability', [
        'all'    => ['09:00', '10:00'],
        'booked' => ['10:00'],
    ], [200, 401, 403]],
    ['POST', 'bsp/v1/sales/revenue', [
        'orders' => [ ['amount' => 10], ['amount' => 5.5] ],
    ], [200]],
    ['POST', 'bsp/v1/intel/trends', [
        'kv' => ['A' => 5, 'B' => 10],
        'k'  => 1,
    ], [200, 401, 403]],
];

if ($sampleProductId > 0) {
    $endpoints[] = ['POST', 'bsp/v1/commerce/calc-price', [
        'product_id'   => $sampleProductId,
        'participants' => 2,
        'context'      => ['channel' => 'healthcheck'],
    ], [200]];
}

foreach ($endpoints as [$method, $route, $body, $allowedCodes]) {
    $response = wp_remote_post(rest_url($route), [
        'method'  => $method,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode($body),
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        echo "[FAIL] {$route}: " . $response->get_error_message() . "\n";
        return;
    }

    $code = wp_remote_retrieve_response_code($response);
    if (! in_array($code, $allowedCodes, true)) {
        echo "[FAIL] {$route}: HTTP {$code} (allowed: " . implode(',', $allowedCodes) . ")\n";
        return;
    }

    if ($code >= 200 && $code < 300) {
        echo "[OK] {$route} -> HTTP {$code}\n";
    } else {
        echo "[OK] {$route} -> HTTP {$code} (protected route status accepted)\n";
    }
}

echo "Healthcheck completed successfully.\n";
