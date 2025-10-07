<?php

/**
 * Core shortcode registration.
 *
 * @package Booking_Core
 */

namespace Booking_Core;

use Booking_Core\Shortcodes\Dayplanner;
use Booking_Core\Shortcodes\Cart;

final class Shortcodes {

	/**
	 * Register core shortcodes.
	 */
	public static function register(): void {
		Dayplanner::register();
		Cart::register();
	}
}
