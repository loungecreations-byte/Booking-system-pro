<?php

/**
 * Service registrar.
 *
 * @package Booking_Core
 */

namespace Booking_Core;

use Booking_Core\Support\Logger;
use Booking_Core\Product_Type;
use Booking_Core\CPT;

final class Services {

	/**
	 * Register service singletons or bootstrappers.
	 */
	public static function register(): void {
		Logger::register();
		Product_Type::register();
		CPT::register();
	}
}
