<?php

/**
 * Assignments repository.
 *
 * @package Booking_Core
 */

namespace Booking_Core\Database\Repository;

use wpdb;

class Assignments_Repository extends Base_Repository {

	private function table(): string {
		return $this->db->prefix . 'sbdp_assignments';
	}

	public function find( int $assignment_id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $assignment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row ?: null;
	}

	public function for_booking( int $booking_id ): array {
		$results = $this->db->get_results( $this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE booking_id = %d ORDER BY start_datetime ASC', $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $results ?: array();
	}

	public function between( string $start, string $end ): array {
		$query = $this->db->prepare(
			' SELECT * FROM ' . $this->table() . ' WHERE start_datetime >= %s AND end_datetime <= %s ORDER BY start_datetime ASC ',
			$start,
			$end
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$results = $this->db->get_results( $query, ARRAY_A );

		return $results ?: array();
	}

	public function save( array $data ): ?array {
		$prepared = $this->prepare_data( $data );
		$table    = $this->table();

		if ( isset( $prepared['id'] ) && $prepared['id'] ) {
			$id = (int) $prepared['id'];
			unset( $prepared['id'] );

			$this->db->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table,
				$prepared,
				array( 'id' => $id ),
				$this->formats( $prepared ),
				array( '%d' )
			);

			return $this->find( $id );
		}

		$this->db->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			$prepared,
			$this->formats( $prepared )
		);

		$id = (int) $this->db->insert_id;

		return $id ? $this->find( $id ) : null;
	}

	private function prepare_data( array $data ): array {
		$clean = array();

		if ( isset( $data['id'] ) ) {
			$clean['id'] = (int) $data['id'];
		}

		$clean['booking_id']     = isset( $data['booking_id'] ) ? (int) $data['booking_id'] : 0;
		$clean['resource_id']    = isset( $data['resource_id'] ) ? (int) $data['resource_id'] : 0;
		$clean['start_datetime'] = isset( $data['start_datetime'] ) ? $data['start_datetime'] : ( $data['start'] ?? '' );
		$clean['end_datetime']   = isset( $data['end_datetime'] ) ? $data['end_datetime'] : ( $data['end'] ?? '' );
		$clean['role']           = isset( $data['role'] ) ? sanitize_text_field( $data['role'] ) : 'primary';
		$clean['notes']          = isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : '';

		return $clean;
	}

	private function formats( array $data ): array {
		$formats = array();
		foreach ( $data as $key => $value ) {
			switch ( $key ) {
				case 'booking_id':
				case 'resource_id':
				case 'id':
					$formats[] = '%d';
					break;
				default:
					$formats[] = '%s';
			}
		}

		return $formats;
	}
}
