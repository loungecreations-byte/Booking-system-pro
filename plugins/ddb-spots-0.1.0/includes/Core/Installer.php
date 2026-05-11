<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Core_Installer {
	private const BACKFILL_OPTION = 'dbspots_backfill_done';

	public function init(): void {
		add_action('admin_init', array($this, 'ensure_schema'));
	}

	public static function activate(): void {
		$migrator = new DDB_Spots_Core_Migrator();
		$migrator->migrate();
		delete_option(self::BACKFILL_OPTION);
	}

	public function ensure_schema(): void {
		$current = (int) get_option(DDB_Spots_Core_Migrator::OPTION_KEY, 0);
		if ($current < 5) {
			$migrator = new DDB_Spots_Core_Migrator();
			$migrator->migrate();
		}
		$this->run_backfill_batch();
	}

	private function run_backfill_batch(): void {
		if ((bool) get_option(self::BACKFILL_OPTION, false)) {
			return;
		}

		global $wpdb;
		$posts_table = $wpdb->posts;
		$spots_table = DDB_Spots_Core_Db_Tables::spots();
		$sql = "SELECT p.ID
			FROM {$posts_table} p
			LEFT JOIN {$spots_table} s ON s.spot_post_id = p.ID
			WHERE p.post_type = %s
			AND p.post_status IN ('publish','draft','pending','private')
			AND s.id IS NULL
			ORDER BY p.ID ASC
			LIMIT 20";
		$ids = $wpdb->get_col($wpdb->prepare($sql, 'ddb_spot')); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_values(array_filter(array_map('absint', is_array($ids) ? $ids : array())));
		if (empty($ids)) {
			update_option(self::BACKFILL_OPTION, true, false);
			return;
		}

		foreach ($ids as $post_id) {
			do_action('ddb_spots_canonical_sync_post', $post_id, 'backfill');
		}
	}
}
