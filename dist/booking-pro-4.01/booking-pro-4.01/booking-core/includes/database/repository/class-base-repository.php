<?php

/**
 * Base repository helper.
 *
 * @package Booking_Core
 */

namespace Booking_Core\Database\Repository;

use wpdb;

abstract class Base_Repository {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	protected wpdb $db;

	/**
	 * Constructor.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->db = $wpdb;
	}
}
