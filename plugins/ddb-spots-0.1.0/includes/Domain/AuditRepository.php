<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Domain_Audit_Repository {
	public function log(string $entity_type, int $entity_id, string $action, int $user_id, array $diff): bool {
		global $wpdb;
		$table = DDB_Spots_Core_Db_Tables::audit();
		$inserted = $wpdb->insert(
			$table,
			array(
				'entity_type' => sanitize_key($entity_type),
				'entity_id' => max(0, $entity_id),
				'action' => sanitize_key($action),
				'user_id' => max(0, $user_id),
				'diff_json' => wp_json_encode($diff),
				'created_at' => gmdate('Y-m-d H:i:s'),
			),
			array('%s', '%d', '%s', '%d', '%s', '%s')
		);
		return (bool) $inserted;
	}

	public function recent(int $limit = 20): array {
		global $wpdb;
		$table = DDB_Spots_Core_Db_Tables::audit();
		$limit = min(200, max(1, $limit));
		$sql = "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d";
		$rows = $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array($rows) ? $rows : array();
	}
}

