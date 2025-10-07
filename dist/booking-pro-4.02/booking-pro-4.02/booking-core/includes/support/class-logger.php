<?php

/**
 * Minimal PSR-like logger wrapper.
 *
 * @package Booking_Core
 */

namespace Booking_Core\Support;

final class Logger {

	/**
	 * Register logger hooks (placeholder for future integrations).
	 */
	public static function register(): void {
	}

	/**
	 * Log a message.
	 *
	 * @param string $level   Log level (debug|info|warning|error).
	 * @param string $message Message.
	 * @param array  $context Optional context.
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		$allowed_levels = array( 'debug', 'info', 'warning', 'error' );
		if ( ! in_array( $level, $allowed_levels, true ) ) {
			$level = 'info';
		}

		$formatted = strtoupper( $level ) . ': ' . $message;
		if ( ! empty( $context ) ) {
			$formatted .= ' ' . wp_json_encode( $context );
		}

		error_log( $formatted ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
