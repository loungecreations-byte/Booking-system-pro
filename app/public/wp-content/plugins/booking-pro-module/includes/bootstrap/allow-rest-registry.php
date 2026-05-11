<?php

/**
 * Plugin Name: Allow REST Auth for Booking Registry
 * Description: Permit basic/application password authentication for booking product settings endpoints on staging.
 */

if (defined('SBDP_ALLOW_REST_REGISTRY_LOADED')) {
    return;
}

if (function_exists('bpm_allow_booking_rest_auth')) {
    // Remove legacy hook so it no longer fires with the wrong argument count.
    remove_filter('rest_authentication_errors', 'bpm_allow_booking_rest_auth', 10);
}

if (! function_exists('bpm_allow_booking_rest_auth_v2')) {
    function bpm_allow_booking_rest_auth_v2($result = null)
    {
        $route = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        if (
            strpos($route, '/wp-json/booking/v1/planner') !== false
            || (strpos($route, '/wp-json/booking/v1/products/') === 0 && preg_match('#/wp-json/booking/v1/products/\d+$#', $route) === 1)
            || strpos($route, '/wp-json/planner/v1/') !== false
        ) {
            return null;
        }

        return $result;
    }
}

add_filter('rest_authentication_errors', 'bpm_allow_booking_rest_auth_v2', 9999);
