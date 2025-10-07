<?php

/**
 * Core hooks bootstrap.
 *
 * @package Booking_Core
 */

namespace Booking_Core;

final class Hooks {

	/**
	 * Register hooks exposed by the core plugin.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
	}

	/**
	 * Provide backward compatibility alias for add-ons to register shortcodes.
	 */
	public static function register_shortcodes(): void {
		do_action( 'booking_core/register_shortcodes' );
	}
}
