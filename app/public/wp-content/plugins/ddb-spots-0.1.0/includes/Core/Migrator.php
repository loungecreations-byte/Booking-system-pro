<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Core_Migrator {
	public const OPTION_KEY = 'dbspots_schema_version';
	private const TARGET_VERSION = 5;

	public function migrate(): void {
		$this->create_or_update_tables();
		$current = (int) get_option(self::OPTION_KEY, 0);
		while ($current < self::TARGET_VERSION) {
			$current++;
			$this->apply_migration($current);
			update_option(self::OPTION_KEY, $current, false);
		}
	}

	private function create_or_update_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$spots = DDB_Spots_Core_Db_Tables::spots();
		$events = DDB_Spots_Core_Db_Tables::events();
		$audit = DDB_Spots_Core_Db_Tables::audit();

		$spots_sql = "CREATE TABLE {$spots} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			spot_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			slug VARCHAR(191) NOT NULL DEFAULT '',
			name VARCHAR(255) NOT NULL DEFAULT '',
			type VARCHAR(100) NOT NULL DEFAULT '',
			status VARCHAR(50) NOT NULL DEFAULT 'draft',
			short_desc TEXT NULL,
			long_desc LONGTEXT NULL,
			address VARCHAR(255) NOT NULL DEFAULT '',
			lat DECIMAL(10,7) NULL,
			lng DECIMAL(10,7) NULL,
			area VARCHAR(120) NOT NULL DEFAULT '',
			price_band VARCHAR(20) NOT NULL DEFAULT '',
			duration_hint INT UNSIGNED NOT NULL DEFAULT 0,
			suitability_json LONGTEXT NULL,
			images_json LONGTEXT NULL,
			tags_json LONGTEXT NULL,
			primary_cta_type VARCHAR(80) NOT NULL DEFAULT '',
			primary_cta_value TEXT NULL,
			primary_cta_label VARCHAR(120) NOT NULL DEFAULT '',
			near_spots_json LONGTEXT NULL,
			bundles_json LONGTEXT NULL,
			is_informational TINYINT(1) NOT NULL DEFAULT 0,
			source VARCHAR(80) NOT NULL DEFAULT 'manual',
			place_id VARCHAR(191) NOT NULL DEFAULT '',
			priority_score INT UNSIGNED NOT NULL DEFAULT 0,
			last_synced_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uq_spot_post_id (spot_post_id),
			KEY idx_place_id (place_id),
			KEY idx_status_type_area (status,type,area),
			KEY idx_spot_time (updated_at)
		) {$charset};";

		$events_sql = "CREATE TABLE {$events} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(80) NOT NULL,
			spot_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source VARCHAR(80) NOT NULL DEFAULT '',
			context_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_event_type_time (event_type,created_at),
			KEY idx_event_spot_time (spot_id,created_at)
		) {$charset};";

		$audit_sql = "CREATE TABLE {$audit} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type VARCHAR(80) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(80) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			diff_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_audit_entity_time (entity_type,entity_id,created_at)
		) {$charset};";

		$spot_events_sql = class_exists('DDB_Spots_Premium_Analytics')
			? DDB_Spots_Premium_Analytics::create_table_sql($charset)
			: "CREATE TABLE " . DDB_Spots_Core_Db_Tables::spot_events() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			spot_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			event_type VARCHAR(64) NOT NULL,
			ts DATETIME NOT NULL,
			meta_json LONGTEXT NULL,
			PRIMARY KEY (id),
			KEY idx_spot_ts (spot_id, ts),
			KEY idx_event_ts (event_type, ts)
		) {$charset};";

		dbDelta($spots_sql);
		dbDelta($events_sql);
		dbDelta($audit_sql);
		dbDelta($spot_events_sql);
	}

	private function apply_migration(int $version): void {
		switch ($version) {
			case 1:
				// Initial schema is applied through dbDelta in create_or_update_tables.
				return;
			case 2:
				$this->add_column_if_missing(DDB_Spots_Core_Db_Tables::spots(), 'priority_score', 'INT UNSIGNED NOT NULL DEFAULT 0');
				return;
			case 3:
				$this->add_column_if_missing(DDB_Spots_Core_Db_Tables::spots(), 'is_informational', 'TINYINT(1) NOT NULL DEFAULT 0');
				return;
			case 4:
				$this->drop_index_if_exists(DDB_Spots_Core_Db_Tables::spots(), 'uq_place_id');
				$this->add_index_if_missing(DDB_Spots_Core_Db_Tables::spots(), 'idx_place_id', '(place_id)');
				return;
			case 5:
				// Premium events table is maintained via dbDelta in create_or_update_tables.
				return;
		}
	}

	private function add_column_if_missing(string $table, string $column, string $definition): void {
		global $wpdb;
		$exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ($exists) {
			return;
		}
		$wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function drop_index_if_exists(string $table, string $index): void {
		global $wpdb;
		$exists = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if (! $exists) {
			return;
		}
		$wpdb->query("ALTER TABLE {$table} DROP INDEX {$index}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function add_index_if_missing(string $table, string $index, string $columns): void {
		global $wpdb;
		$exists = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ($exists) {
			return;
		}
		$wpdb->query("ALTER TABLE {$table} ADD INDEX {$index} {$columns}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
