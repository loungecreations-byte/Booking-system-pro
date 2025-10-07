<?php
/**
 * Core plugin bootstrap utilities for Booking Pro Module.
 *
 * @package Booking_Pro_Module
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SBDP_DIR . 'includes/class-sbdp-legacy-loader.php';

/**
 * Main plugin bootstrapper responsible for environment checks and notices.
 */
class SBDP_Plugin {

	/**
	 * Minimum supported WordPress version.
	 */
	public const MIN_WP_VERSION = '5.8';

	/**
	 * Minimum supported PHP version.
	 */
	public const MIN_PHP_VERSION = '7.4';

	/**
	 * Minimum supported WooCommerce version.
	 */
	public const MIN_WC_VERSION = '7.0';

	/**
	 * Tracks whether the plugin has been booted.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Stores the current admin notice message.
	 *
	 * @var string
	 */
	private static $notice = '';

	/**
	 * Register the primary hooks used by the plugin.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'init', array( __CLASS__, 'init' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SBDP_FILE ), array( __CLASS__, 'register_plugin_links' ) );
	}

	/**
	 * Initialise localisation and legacy loaders when environment allows it.
	 */
	public static function init(): void {
		self::load_textdomain();

		if ( ! self::is_environment_compatible() ) {
			return;
		}

		if ( class_exists( 'BSP_Core_Agent' ) ) {
			\BSP_Core_Agent::instance();
		}

		SBDP_Legacy_Loader::init();

		add_filter( 'rest_authentication_errors', array( __CLASS__, 'maybe_allow_public_rest' ), 999 );
	}

	/**
	 * Load translation files.
	 */
	private static function load_textdomain(): void {
		load_plugin_textdomain( 'sbdp', false, dirname( plugin_basename( SBDP_FILE ) ) . '/languages' );
	}

	/**
	 * Determine whether the hosting environment meets plugin requirements.
	 *
	 * @return bool True when the environment is compatible, false otherwise.
	 */
	private static function is_environment_compatible(): bool {
	global $wp_version;

	if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
		self::$notice = sprintf(
			/* translators: 1: Minimum PHP version, 2: Current PHP version. */
			__( 'Booking Pro Module vereist minimaal PHP %1$s. Huidige versie: %2$s.', 'sbdp' ),
			self::MIN_PHP_VERSION,
			PHP_VERSION
		);

		return false;
	}

	if ( version_compare( $wp_version, self::MIN_WP_VERSION, '<' ) ) {
		self::$notice = sprintf(
			/* translators: 1: Minimum WordPress version, 2: Current WordPress version. */
			__( 'Booking Pro Module vereist minimaal WordPress %1$s. Huidige versie: %2$s.', 'sbdp' ),
			self::MIN_WP_VERSION,
			$wp_version
		);

		return false;
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		self::$notice = __( 'Booking Pro Module vereist dat WooCommerce actief is. Activeer WooCommerce om verder te gaan.', 'sbdp' );

		return false;
	}

	if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, self::MIN_WC_VERSION, '<' ) ) {
		self::$notice = sprintf(
			/* translators: 1: Minimum WooCommerce version, 2: Current WooCommerce version. */
			__( 'Booking Pro Module vereist minimaal WooCommerce %1$s. Huidige versie: %2$s.', 'sbdp' ),
			self::MIN_WC_VERSION,
			defined( 'WC_VERSION' ) ? WC_VERSION : __( 'onbekend', 'sbdp' )
		);

		return false;
	}

	self::$notice = '';

	return true;
}

	/**
	 * Render the stored admin notice when needed.
	 */
	public static function maybe_render_notice(): void {
		if ( '' === self::$notice ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( self::$notice )
		);
	}

	/**
	 * Add quick links to the plugin action row.
	 *
	 * @param array<int|string, string> $links Existing action links.
	 *
	 * @return array<int|string, string> Filtered action links.
	 */
	public static function register_plugin_links( array $links ): array {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=sbdp_bookings' ) ),
			esc_html__( 'Planner', 'sbdp' )
		);

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( 'https://owncreations.com' ),
			esc_html__( 'Ondersteuning', 'sbdp' )
		);

		return $links;
	}

	/**
	 * Allow public REST access to specific plugin endpoints when necessary.
	 *
	 * @param mixed $result Current authentication result.
	 *
	 * @return WP_Error|mixed|null Original result, null to grant access, or WP_Error.
	 */
	public static function maybe_allow_public_rest( $result ) {
		if ( empty( $result ) || ! ( $result instanceof WP_Error ) ) {
			return $result;
		}

		$route = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false !== strpos( $route, '/wp-json/sbdp/v1/' ) ) {
			return null;
		}

		return $result;
	}
}


