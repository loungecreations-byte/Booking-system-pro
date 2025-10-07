<?php
/**
 * Plugin Name: Booking Pro 4.02
 * Plugin URI: https://owncreations.com
 * Description: WooCommerce dagplanner en boekingsmodule met resources, capaciteiten, prijsregels en verbeterde e-mailflows.
 * Version: 4.02
 * Author: Own Creations
 * Text Domain: sbdp
 * License: GPLv2 or later
 *
 * @package Booking_Pro_Module
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'SBDP_FILE' ) && realpath( (string) SBDP_FILE ) !== __FILE__ ) {
	$sbdp_conflict_plugin  = defined( 'SBDP_FILE' ) ? plugin_basename( (string) SBDP_FILE ) : 'booking-pro-module/booking-pro-module.php';
	$sbdp_conflict_message = sprintf(
		/* translators: %s: conflicting plugin file path. */
		__( 'Booking Pro 4.02 cannot run because another Booking Pro variant (%s) is already active. Deactivate the existing module before activating this build.', 'sbdp' ),
		$sbdp_conflict_plugin
	);

	$sbdp_render_conflict_notice = static function () use ( $sbdp_conflict_message ): void {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $sbdp_conflict_message ) );
	};

	if ( function_exists( 'add_action' ) ) {
		add_action( 'admin_notices', $sbdp_render_conflict_notice );
		add_action( 'network_admin_notices', $sbdp_render_conflict_notice );
	}

	if ( function_exists( 'error_log' ) ) {
		error_log( '[SBDP] ' . $sbdp_conflict_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	return;
}

$sbdp_plugin_dir = plugin_dir_path( __FILE__ );

$sbdp_required_files = array(
	'includes/class-core-agent.php',
	'includes/class-sbdp-plugin.php',
	'includes/class-sbdp-legacy-loader.php',
	'includes/class-sbdp-activation.php',
);

$sbdp_missing_files = array();

foreach ( $sbdp_required_files as $sbdp_relative_file ) {
	$sbdp_path = $sbdp_plugin_dir . $sbdp_relative_file;

	if ( ! is_readable( $sbdp_path ) ) {
		$sbdp_missing_files[] = $sbdp_relative_file;
	}
}

if ( array() !== $sbdp_missing_files ) {
	$sbdp_missing_message = sprintf(
		/* translators: %s: comma-separated list of missing file paths. */
		__( 'Booking Pro 4.02 is missing required bootstrap files: %s. Reinstall the plugin package to continue.', 'sbdp' ),
		implode( ', ', $sbdp_missing_files )
	);

	$sbdp_render_missing_notice = static function () use ( $sbdp_missing_message ): void {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $sbdp_missing_message ) );
	};

	if ( function_exists( 'add_action' ) ) {
		add_action( 'admin_notices', $sbdp_render_missing_notice );
		add_action( 'network_admin_notices', $sbdp_render_missing_notice );
	}

	if ( function_exists( 'error_log' ) ) {
		error_log( '[SBDP] ' . $sbdp_missing_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	return;
}

define( 'SBDP_FILE', __FILE__ );
define( 'SBDP_DIR', rtrim( $sbdp_plugin_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
define( 'SBDP_URL', plugin_dir_url( __FILE__ ) );
define( 'SBDP_VER', '4.02' );

$autoload_file = SBDP_DIR . 'vendor/autoload.php';

if ( file_exists( $autoload_file ) ) {
	require_once $autoload_file;
} else {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Composer autoload file is missing. Run "composer install" to enable Booking System Pro modules.', 'sbdp' )
			);
		}
	);
}

require_once SBDP_DIR . 'includes/class-core-agent.php';
require_once SBDP_DIR . 'includes/class-sbdp-plugin.php';
require_once SBDP_DIR . 'includes/class-sbdp-activation.php';

SBDP_Activation::bootstrap();

register_activation_hook( SBDP_FILE, array( 'SBDP_Activation', 'activate' ) );
register_deactivation_hook( SBDP_FILE, array( 'SBDP_Activation', 'deactivate' ) );
register_uninstall_hook( SBDP_FILE, array( 'SBDP_Activation', 'uninstall' ) );

SBDP_Plugin::boot();

if ( ! function_exists( 'sbdp_bootstrap_modules' ) ) {
	/**
	 * Bootstraps the Booking System Pro module registry.
	 */
	function sbdp_bootstrap_modules(): void {
		static $booted = false;

		if ( $booted ) {
			return;
		}

		if ( ! class_exists( '\BSPModule\Shared\Modules\ModuleRegistry' ) ) {
			return;
		}

		$booted = true;

		$registry = new \BSPModule\Shared\Modules\ModuleRegistry();

		$module_classes = array(
			'\BSPModule\Core\Module',
			'\BSPModule\Sales\Module',
			'\BSPModule\Ops\Module',
			'\BSPModule\Finance\Module',
			'\BSPModule\Data\Module',
			'\BSPModule\Support\Module',
		);

		if ( function_exists( 'apply_filters' ) ) {
			$module_classes = (array) apply_filters( 'bsp/modules/default_classes', $module_classes ); // phpcs:ignore WordPress.NamingConventions.ValidHookName
		}

		foreach ( $module_classes as $module_class ) {
			if ( ! is_string( $module_class ) || '' === $module_class ) {
				continue;
			}

			if ( ! class_exists( $module_class ) ) {
				continue;
			}

			if ( ! is_subclass_of( $module_class, '\BSPModule\Shared\Modules\ModuleInterface' ) ) {
				continue;
			}

			$registry->add( new $module_class() );
		}

		do_action( 'bsp/modules/registry', $registry ); // phpcs:ignore WordPress.NamingConventions.ValidHookName

		$registry->boot();

		do_action( 'bsp/modules/booted', $registry ); // phpcs:ignore WordPress.NamingConventions.ValidHookName

		if ( class_exists( 'BSP_Core_Agent' ) ) {
			\BSP_Core_Agent::instance()->boot();
		}
	}
}
add_action( 'init', 'sbdp_bootstrap_modules', 20 );


