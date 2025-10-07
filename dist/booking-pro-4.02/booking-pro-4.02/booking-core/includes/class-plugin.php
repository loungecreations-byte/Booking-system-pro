<?php

/**
 * Core plugin bootstrap.
 *
 * @package Booking_Core
 */

namespace Booking_Core;

use Booking_Core\Database\Schema_Manager;

final class Plugin {

	/**
	 * Flag to ensure bootstrap only runs once.
	 *
	 * @var bool
	 */
	private static bool $bootstrapped = false;

	/**
	 * Stores an error message when environment requirements fail.
	 *
	 * @var string
	 */
	private static string $compat_error = '';

	/**
	 * Initialise the plugin.
	 */
	public static function init(): void {
		if ( self::$bootstrapped ) {
			return;
		}

		self::$bootstrapped = true;

		add_action( 'plugins_loaded', array( __CLASS__, 'on_plugins_loaded' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );

		register_activation_hook( BOOKING_CORE_FILE, array( __CLASS__, 'on_activation' ) );
		register_deactivation_hook( BOOKING_CORE_FILE, array( __CLASS__, 'on_deactivation' ) );
	}

	/**
	 * Plugins loaded hook.
	 */
	public static function on_plugins_loaded(): void {
		if ( ! self::check_compatibility() ) {
			return;
		}

		Hooks::register();
		Services::register();
		Shortcodes::register();
		Assets::register();
		Rest_Api::register();
	}

	/**
	 * Perform environment checks.
	 */
	private static function check_compatibility(): bool {
		global $wp_version;

		if ( version_compare( PHP_VERSION, BOOKING_CORE_MIN_PHP, '<' ) ) {
			self::$compat_error = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version */
				__( 'Booking System Pro Core requires PHP %1$s or higher. You are running %2$s.', 'booking-core' ),
				BOOKING_CORE_MIN_PHP,
				PHP_VERSION
			);

			return false;
		}

		if ( version_compare( $wp_version, BOOKING_CORE_MIN_WP, '<' ) ) {
			self::$compat_error = sprintf(
				/* translators: 1: required WordPress version, 2: current version */
				__( 'Booking System Pro Core requires WordPress %1$s or higher. You are running %2$s.', 'booking-core' ),
				BOOKING_CORE_MIN_WP,
				$wp_version
			);

			return false;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			self::$compat_error = __( 'Booking System Pro Core requires WooCommerce to be active.', 'booking-core' );

			return false;
		}
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, BOOKING_CORE_MIN_WC, '<' ) ) {
			self::$compat_error = sprintf(
				/* translators: 1: required WooCommerce version, 2: current version */
				__( 'Booking System Pro Core requires WooCommerce %1$s or higher. You are running %2$s.', 'booking-core' ),
				BOOKING_CORE_MIN_WC,
				WC_VERSION
			);

					return false;
		}

		return true;
	}

	/**
	 * Activation hook.
	 */
	public static function on_activation(): void {
		if ( ! self::check_compatibility() ) {
			trigger_error( self::$compat_error, E_USER_ERROR ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
		}

		( new Schema_Manager() )->register_tables();
	}

	/**
	 * Deactivation hook.
	 */
	public static function on_deactivation(): void {
		// Placeholder for scheduled event cleanup.
	}

	/**
	 * Display admin notice when compatibility fails.
	 */
	public static function render_admin_notice(): void {
		if ( empty( self::$compat_error ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( self::$compat_error )
		);
	}
}
