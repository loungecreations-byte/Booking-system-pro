<?php

/**
 * Compatibility loader for legacy "booking-pro-suite" installations.
 *
 * This file intentionally has no plugin header so WordPress exposes only the primary
 * Booking Pro entry (`booking-pro-module.php`) in the plugins list.
 */

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/booking-pro-module.php';
