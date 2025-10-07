<?php

/**
 * Bookings repository.
 *
 * @package Booking_Core
 */

namespace Booking_Core\Database\Repository;

class Bookings_Repository extends Base_Repository {

	/**
	 * Table name helper.
	 */
	private function table(): string {
		return $this->db->prefix . 'sbdp_bookings';
	}

	/**
	 * Retrieve a booking row.
	 */
	public function find( int $booking_id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row ?: null;
	}

	/**
	 * Fetch bookings between start/end datetimes.
	 *
	 * @param string $start ISO datetime.
	 * @param string $end   ISO datetime.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all_between( string $start, string $end ): array {
		$query = $this->db->prepare(
			' SELECT * FROM ' . $this->table() . ' WHERE start_datetime >= %s AND end_datetime <= %s ORDER BY start_datetime ASC ',
			$start,
			$end
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$results = $this->db->get_results( $query, ARRAY_A );

		return $results ?: array();
	}
}
