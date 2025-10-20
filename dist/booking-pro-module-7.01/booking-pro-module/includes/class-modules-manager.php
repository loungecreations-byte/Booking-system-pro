<?php
/**
 * Module bootstrapper that wires modern BSP modules into the classic plugin lifecycle.
 *
 * @package SBDP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SBDP_Modules_Manager {

	/**
	 * Track whether the bootstrap already executed.
	 *
	 * @var bool
	 */
	private static $bootstrapped = false;

	/**
	 * Initialise all bundled modules (both legacy BSPModule and modern BSP namespaces).
	 */
	public static function bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}

		self::$bootstrapped = true;

		self::bootstrap_legacy_modules();
		self::bootstrap_core_modules();

		if ( class_exists( 'BSP_Core_Agent' ) ) {
			\BSP_Core_Agent::instance()->boot();
		}
	}

	/**
	 * Register and load modules that implement \BSP\Core\Interfaces\ModuleInterface.
	 */
	private static function bootstrap_core_modules(): void {
		if ( ! class_exists( '\BSP\Core\Modules' ) ) {
			return;
		}

		$core_modules = array(
			'planner'       => '\BSP\Planner\Module',
			'bookings'      => '\BSP\Bookings\Module',
			'sales'         => '\BSP\Sales\Module',
			'vendor_portal' => '\BSP\VendorPortal\Module',
			'geo_dashboard' => '\BSP\GeoDashboard\Module',
			'notifications' => '\BSP\Notifications\Module',
		);

		if ( function_exists( 'apply_filters' ) ) {
			$core_modules = (array) apply_filters( 'sbdp/core_module_classes', $core_modules );
		}

		foreach ( $core_modules as $key => $class ) {
			if ( ! is_string( $class ) || '' === $class ) {
				continue;
			}

			if ( ! class_exists( $class ) ) {
				continue;
			}

			if ( ! is_subclass_of( $class, '\BSP\Core\Interfaces\ModuleInterface' ) ) {
				continue;
			}

			$slug = is_string( $key ) && '' !== $key
				? (string) $key
				: strtolower( basename( str_replace( '\\', '/', $class ) ) );

			if ( \BSP\Core\Modules::isRegistered( $slug ) ) {
				continue;
			}

			\BSP\Core\Modules::register( $slug, $class );
		}

		\BSP\Core\Modules::loadAll();

		if ( function_exists( 'do_action' ) ) {
			do_action( 'sbdp/core_modules/booted' );
		}
	}

	/**
	 * Register and boot legacy modules that rely on the BSPModule namespace.
	 */
	private static function bootstrap_legacy_modules(): void {
		if ( ! class_exists( '\BSPModule\Shared\Modules\ModuleRegistry' ) ) {
			return;
		}

		$registry = new \BSPModule\Shared\Modules\ModuleRegistry();

		$legacy_modules = array(
			'\BSPModule\Core\Module',
			'\BSPModule\Sales\Module',
			'\BSPModule\Support\Module',
		);

		$fallback_classes = array(
			'\BSPModule\Sales\Module' => '\BSP\Sales\LegacyModule',
		);

		if ( function_exists( 'apply_filters' ) ) {
			$legacy_modules = (array) apply_filters( 'sbdp/legacy_module_classes', $legacy_modules );
		}

		foreach ( $legacy_modules as $class ) {
			if ( ! is_string( $class ) || '' === $class ) {
				continue;
			}

			$target = $class;

			if ( ! class_exists( $target ) && isset( $fallback_classes[ $target ] ) && class_exists( $fallback_classes[ $target ] ) ) {
				$target = $fallback_classes[ $target ];
			}

			if ( ! class_exists( $target ) ) {
				continue;
			}

			if ( ! is_subclass_of( $target, '\BSPModule\Shared\Modules\ModuleInterface' ) ) {
				continue;
			}

			$module = new $target();
			$registry->add( $module );
		}

		if ( function_exists( 'do_action' ) ) {
			do_action( 'sbdp/legacy_modules/registry', $registry );
		}

		$registry->boot();

		if ( function_exists( 'do_action' ) ) {
			do_action( 'sbdp/legacy_modules/booted', $registry );
		}
	}
}
