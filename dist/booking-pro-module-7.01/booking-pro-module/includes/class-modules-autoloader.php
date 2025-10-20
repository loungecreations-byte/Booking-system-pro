<?php
/**
 * Lightweight PSR-4 style autoloader for bundled Booking System Pro modules.
 *
 * @package SBDP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SBDP_Modules_Autoloader {

	/**
	 * Ensures the autoloader is only registered once.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Namespace prefixes mapped to their base directories.
	 *
	 * @var array<string,string>
	 */
	private static $prefixes = array(
		'BSP\\'       => 'modules/',
		'BSPModule\\' => 'modules/',
	);

	/**
	 * Register the autoloader with SPL.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		spl_autoload_register( array( __CLASS__, 'autoload' ), true, true );
		self::$registered = true;
	}

	/**
	 * Attempt to autoload a class from the modules directory.
	 *
	 * @param string $class Fully-qualified class name.
	 */
	private static function autoload( string $class ): void {
		foreach ( self::$prefixes as $prefix => $relative_dir ) {
			if ( 0 !== strpos( $class, $prefix ) ) {
				continue;
			}

			$relative = substr( $class, strlen( $prefix ) );
			if ( '' === $relative ) {
				return;
			}

			$segments = explode( '\\', $relative );
			if ( empty( $segments ) ) {
				return;
			}

			$segments[0] = self::normalise_top_level_directory( $segments[0] );
			$path        = SBDP_DIR . $relative_dir . implode( '/', $segments ) . '.php';

			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Convert a namespace segment into the directory format used inside /modules.
	 *
	 * Most top-level segment directories are kebab-case (e.g. vendor-portal) whereas namespaces
	 * use StudlyCaps (VendorPortal). This helper converts the namespace segment accordingly.
	 *
	 * @param string $segment Top-level namespace segment.
	 *
	 * @return string Normalised directory segment.
	 */
	private static function normalise_top_level_directory( string $segment ): string {
		$slug = preg_replace( '/([a-z])([A-Z])/', '$1-$2', $segment );
		if ( null === $slug ) {
			$slug = $segment;
		}

		return strtolower( $slug );
	}
}
