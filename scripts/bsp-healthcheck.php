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

require_once WP_PLUGIN_DIR . '/booking-system-pro/booking-system-pro.php';

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

$endpoints = [
    ['POST', 'bsp/v1/commerce/calc-price', [
        'base'  => 100,
        'rules' => [ ['type' => 'fixed', 'value' => 5], ['type' => 'percent', 'value' => 10] ],
    ]],
    ['POST', 'bsp/v1/planner/availability', [
        'all'    => ['09:00', '10:00'],
        'booked' => ['10:00'],
    ]],
    ['POST', 'bsp/v1/sales/revenue', [
        'orders' => [ ['amount' => 10], ['amount' => 5.5] ],
    ]],
    ['POST', 'bsp/v1/intel/trends', [
        'kv' => ['A' => 5, 'B' => 10],
        'k'  => 1,
    ]],
];

foreach ($endpoints as [$method, $route, $body]) {
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
    if ($code < 200 || $code >= 300) {
        echo "[FAIL] {$route}: HTTP {$code}\n";
        return;
    }

    echo "[OK] {$route} -> HTTP {$code}\n";
}

echo "Healthcheck completed successfully.\n";
