<?php
/**
 * Legacy loader that boots classic Booking Pro components when modules are unavailable.
 *
 * @package Booking_Pro_Module
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides legacy fallbacks for the module bootstrap when core modules are missing.
 */
class SBDP_Legacy_Loader {

	/**
	 * Keeps track of whether the loader has already initialised legacy hooks.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Initialise legacy systems when the modular bootstrap is unavailable.
	 */
	public static function init(): void {
		if ( class_exists( \BSPModule\Core\Module::class ) ) {
			return;
		}

		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		require_once SBDP_DIR . 'includes/class-cpt.php';
		if ( class_exists( 'SBDP_CPT' ) ) {
			SBDP_CPT::init();
		}

		require_once SBDP_DIR . 'includes/class-product-type.php';
		if ( class_exists( 'SBDP_Product_Type' ) ) {
			SBDP_Product_Type::init();
		}

		require_once SBDP_DIR . 'includes/class-product-meta.php';
		require_once SBDP_DIR . 'includes/class-meta-display.php';

		require_once SBDP_DIR . 'includes/class-admin-menu.php';
		if ( class_exists( 'SBDP_Admin_Menu' ) ) {
			SBDP_Admin_Menu::init();
		}

		require_once SBDP_DIR . 'includes/class-admin-scheduler.php';
		if ( class_exists( 'SBDP_Admin_Scheduler' ) ) {
			SBDP_Admin_Scheduler::init();
		}

		$bookable_admin = SBDP_DIR . 'includes/admin/class-sbdp-admin-bookable-meta.php';
		if ( file_exists( $bookable_admin ) ) {
			require_once $bookable_admin;
			if ( class_exists( '\\SBDP\\Admin\\Bookable\\SBDP_Admin_Bookable_Meta' ) ) {
				\SBDP\Admin\Bookable\SBDP_Admin_Bookable_Meta::init();
			}
		}

		require_once SBDP_DIR . 'includes/class-rest.php';
		if ( class_exists( 'SBDP_REST' ) ) {
			SBDP_REST::init();
		}

		require_once SBDP_DIR . 'includes/class-shortcodes.php';
		if ( class_exists( 'SBDP_Shortcodes' ) ) {
			SBDP_Shortcodes::init();
		}

		require_once SBDP_DIR . 'includes/class-enqueue.php';
		if ( class_exists( 'SBDP_Enqueue' ) ) {
			SBDP_Enqueue::init();
		}

		require_once SBDP_DIR . 'includes/class-emails.php';
		if ( class_exists( 'SBDP_Emails' ) ) {
			SBDP_Emails::init();
		}

		require_once SBDP_DIR . 'includes/class-resource-meta.php';
		if ( class_exists( 'SBDP_Resource_Meta' ) ) {
			SBDP_Resource_Meta::init();
		}

		$elementor_file = SBDP_DIR . 'includes/class-elementor.php';
		if ( file_exists( $elementor_file ) ) {
			require_once $elementor_file;
			if ( class_exists( 'SBDP_Elementor_Integration' ) ) {
				SBDP_Elementor_Integration::init();
			}
		}
	}
}

