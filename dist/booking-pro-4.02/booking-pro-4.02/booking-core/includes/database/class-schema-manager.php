<?php

/**
 * Database schema manager.
 *
 * @package Booking_Core
 */

namespace Booking_Core\Database;

use WP_Error;

final class Schema_Manager {

	/**
	 * Schema version option name.
	 */
	private const OPTION_KEY = 'booking_core_schema_version';

	/**
	 * Current schema version.
	 */
	private const VERSION = '2025.03';

	/**
	 * Create/update database tables.
	 */
	public function register_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$bookings_table    = $wpdb->prefix . 'sbdp_bookings';
		$resources_table   = $wpdb->prefix . 'sbdp_resources';
		$assignments_table = $wpdb->prefix . 'sbdp_assignments';

		$sql = array();

		$sql[] = "CREATE TABLE {$bookings_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED DEFAULT NULL,
			customer_id BIGINT(20) UNSIGNED DEFAULT NULL,
			status VARCHAR(40) NOT NULL DEFAULT 'draft',
			start_datetime DATETIME NOT NULL,
			end_datetime DATETIME NOT NULL,
			total DECIMAL(14,4) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY start_datetime (start_datetime),
			KEY customer_id (customer_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$resources_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(40) NOT NULL DEFAULT 'guide',
			title VARCHAR(200) NOT NULL,
			vendor_id BIGINT(20) UNSIGNED DEFAULT NULL,
			capacity SMALLINT UNSIGNED DEFAULT 0,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY type (type),
			KEY vendor_id (vendor_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$assignments_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id BIGINT(20) UNSIGNED NOT NULL,
			resource_id BIGINT(20) UNSIGNED NOT NULL,
			start_datetime DATETIME NOT NULL,
			end_datetime DATETIME NOT NULL,
			role VARCHAR(80) NOT NULL DEFAULT 'primary',
			notes TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY booking_id (booking_id),
			KEY resource_id (resource_id),
			KEY start_datetime (start_datetime)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement ); // phpcs:ignore WordPress.DB.RestrictedFunctions.dbDelta_dbDelta
		}

		update_option( self::OPTION_KEY, self::VERSION );
	}

	/**
	 * Return the current schema version.
	 */
	public static function get_version(): string {
		return (string) get_option( self::OPTION_KEY, '0' );
	}
}
