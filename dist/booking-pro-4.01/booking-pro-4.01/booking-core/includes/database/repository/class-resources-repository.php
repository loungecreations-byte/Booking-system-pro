<?php

/**
 * Resources repository.
 *
 * @package Booking_Core
 */

namespace Booking_Core\Database\Repository;

class Resources_Repository extends Base_Repository {

	/**
	 * Table helper.
	 */
	private function table(): string {
		return $this->db->prefix . 'sbdp_resources';
	}

	/**
	 * Retrieve resources.
	 */
	public function all(): array {
		$results = $this->db->get_results( 'SELECT * FROM ' . $this->table() . ' ORDER BY title ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $results ?: array();
	}
}
