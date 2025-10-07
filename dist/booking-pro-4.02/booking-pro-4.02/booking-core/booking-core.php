<?php

/**
 * Plugin Name: Booking System Pro Core
 * Plugin URI:  https://owncreations.com
 * Description: Core services for Booking System Pro 2.0 (database, REST API, hooks, and shared assets).
 * Version:     2.0.0
 * Author:      Own Creations
 * Text Domain: booking-core
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * License:     GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'BOOKING_CORE_FILE' ) ) {
	return;
}

define( 'BOOKING_CORE_FILE', __FILE__ );

define( 'BOOKING_CORE_PATH', plugin_dir_path( BOOKING_CORE_FILE ) );

define( 'BOOKING_CORE_URL', plugin_dir_url( BOOKING_CORE_FILE ) );

define( 'BOOKING_CORE_VERSION', '2.0.0' );

define( 'BOOKING_CORE_MIN_PHP', '7.4' );

define( 'BOOKING_CORE_MIN_WP', '6.0' );

define( 'BOOKING_CORE_MIN_WC', '8.0' );

require_once BOOKING_CORE_PATH . 'includes/class-autoloader.php';

Booking_Core\Autoloader::register();

Booking_Core\Plugin::init();
