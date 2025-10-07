<?php

/**
 * Simple PSR-4 style autoloader for Booking System Pro Core.
 *
 * @package Booking_Core
 */

namespace Booking_Core;

final class Autoloader {

	/**
	 * Namespace prefix handled by this autoloader.
	 *
	 * @var string
	 */
	private static string $prefix = 'Booking_Core\\';

	/**
	 * Base directory for the namespace prefix.
	 *
	 * @var string
	 */
	private static string $base_dir = '';

	/**
	 * Register the autoloader with SPL.
	 */
	public static function register(): void {
		self::$base_dir = rtrim( BOOKING_CORE_PATH . 'includes/', '/' ) . '/';

		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload callback.
	 *
	 * @param string $class Fully-qualified class name.
	 */
	private static function autoload( string $class ): void {
		if ( 0 !== strpos( $class, self::$prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( self::$prefix ) );
		$relative_class = ltrim( $relative_class, '\\' );

		$parts    = explode( '\\', $relative_class );
		$filename = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
		$path     = '';

		if ( ! empty( $parts ) ) {
			$directories = array_map(
				static function ( $segment ) {
					return strtolower( str_replace( '_', '-', $segment ) );
				},
				$parts
			);

			$path = implode( '/', $directories ) . '/';
		}

		$file = self::$base_dir . $path . $filename;

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
