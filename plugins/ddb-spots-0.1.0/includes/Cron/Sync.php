<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Cron_Sync_Service {
	public const HOOK = 'ddb_spots_google_sync_event';
	private const CURSOR_OPTION = 'ddb_spots_google_sync_cursor';
	private const BATCH_SIZE = 12;
	private const TIME_BUDGET_SECONDS = 18;
	private DDB_Spots_Integrations_Google_Places $google_places;

	public function __construct(DDB_Spots_Integrations_Google_Places $google_places) {
		$this->google_places = $google_places;
	}

	public function init(): void {
		add_filter('cron_schedules', array($this, 'register_custom_schedule'));
		add_action(self::HOOK, array($this, 'run_scheduled_sync'));
		add_action('init', array($this, 'ensure_schedule'));
	}

	public static function activate(): void {
		update_option(self::CURSOR_OPTION, 0, false);
		self::reschedule();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook(self::HOOK);
		delete_option(self::CURSOR_OPTION);
	}

	public function register_custom_schedule(array $schedules): array {
		$schedules['every_3_days'] = array(
			'interval' => 3 * DAY_IN_SECONDS,
			'display' => __('Every 3 days', 'ddb-spots'),
		);
		return $schedules;
	}

	public function ensure_schedule(): void {
		$expected = self::get_frequency();
		$event = wp_get_scheduled_event(self::HOOK);
		if (! $event instanceof stdClass || ! isset($event->schedule)) {
			self::reschedule();
			return;
		}
		if ($event->schedule !== $expected) {
			self::reschedule();
		}
	}

	private static function reschedule(): void {
		wp_clear_scheduled_hook(self::HOOK);
		$frequency = self::get_frequency();
		wp_schedule_event(time() + HOUR_IN_SECONDS, $frequency, self::HOOK);
	}

	private static function get_frequency(): string {
		$frequency = (string) DDB_Spots_Admin_Settings_Page::get_value('data_sources.sync_frequency', 'daily');
		return in_array($frequency, array('daily', 'every_3_days'), true) ? $frequency : 'daily';
	}

	public function run_scheduled_sync(): void {
		$cursor = max(0, (int) get_option(self::CURSOR_OPTION, 0));
		$processed = 0;
		$errors = 0;
		$last_processed_id = $cursor;
		$deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
		$timed_out = false;

		$ids = $this->next_batch_ids($cursor, self::BATCH_SIZE);
		if (empty($ids) && $cursor > 0) {
			$cursor = 0;
			update_option(self::CURSOR_OPTION, 0, false);
			$ids = $this->next_batch_ids(0, self::BATCH_SIZE);
		}

		foreach ($ids as $post_id) {
			if (microtime(true) >= $deadline) {
				$timed_out = true;
				break;
			}

			$post_id = absint((int) $post_id);
			if ($post_id <= 0) {
				continue;
			}

			$result = $this->google_places->sync_post($post_id);
			$last_processed_id = $post_id;
			if (is_wp_error($result)) {
				$errors++;
				DDB_Spots_Admin_Sync_Dashboard::log_event(
					'cron_sync_item',
					'error',
					array(
						'post_id' => $post_id,
						'source' => 'cron',
						'message' => $result->get_error_message(),
					)
				);
				continue;
			}
			$processed++;
		}

		if ($last_processed_id > 0) {
			update_option(self::CURSOR_OPTION, $last_processed_id, false);
		}

		if (empty($ids)) {
			update_option(self::CURSOR_OPTION, 0, false);
		}

		DDB_Spots_Admin_Sync_Dashboard::log_event(
			'cron_sync_run',
			$errors > 0 ? 'warning' : 'success',
			array(
				'source' => 'cron',
				'message' => sprintf(
					'processed=%d errors=%d cursor=%d batch=%d timed_out=%d',
					$processed,
					$errors,
					(int) get_option(self::CURSOR_OPTION, 0),
					count($ids),
					$timed_out ? 1 : 0
				),
			)
		);
	}

	private function next_batch_ids(int $cursor, int $limit): array {
		global $wpdb;
		$cursor = max(0, $cursor);
		$limit = max(1, min(100, $limit));
		$sql = $wpdb->prepare(
			"SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} source_meta
				ON source_meta.post_id = p.ID
				AND source_meta.meta_key = %s
				AND source_meta.meta_value = %s
			INNER JOIN {$wpdb->postmeta} autosync_meta
				ON autosync_meta.post_id = p.ID
				AND autosync_meta.meta_key = %s
				AND autosync_meta.meta_value = %s
			WHERE p.post_type = %s
				AND p.post_status <> 'trash'
				AND p.ID > %d
			GROUP BY p.ID
			ORDER BY p.ID ASC
			LIMIT %d",
			'_ddb_source',
			'google_places',
			'_ddb_google_autosync',
			'1',
			'ddb_spot',
			$cursor,
			$limit
		);
		$ids = $wpdb->get_col($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if (! is_array($ids)) {
			return array();
		}
		return array_values(array_filter(array_map('absint', array_map('intval', $ids))));
	}

}
