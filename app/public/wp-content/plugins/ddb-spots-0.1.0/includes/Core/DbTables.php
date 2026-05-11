<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Core_Db_Tables {
	public static function spots(): string {
		global $wpdb;
		return $wpdb->prefix . 'dbspots_spots';
	}

	public static function events(): string {
		global $wpdb;
		return $wpdb->prefix . 'dbspots_events';
	}

	public static function audit(): string {
		global $wpdb;
		return $wpdb->prefix . 'dbspots_audit';
	}

	public static function spot_events(): string {
		global $wpdb;
		return $wpdb->prefix . 'ddb_spot_events';
	}
}
