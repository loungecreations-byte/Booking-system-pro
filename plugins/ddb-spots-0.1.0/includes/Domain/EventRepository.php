<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Domain_Event_Repository {
	private const ALLOWED_EVENTS = array(
		'spot_view',
		'cta_click',
		'add_to_plan',
		'agent_recommended',
		'book_click',
	);

	public function log_event(string $event_type, int $spot_id, string $source, array $context = array()): bool {
		global $wpdb;
		$event_type = sanitize_key($event_type);
		if (! in_array($event_type, self::ALLOWED_EVENTS, true)) {
			return false;
		}

		$table = DDB_Spots_Core_Db_Tables::events();
		$clean_context = $this->sanitize_context($context);
		$inserted = $wpdb->insert(
			$table,
			array(
				'event_type' => $event_type,
				'spot_id' => max(0, $spot_id),
				'source' => sanitize_key($source),
				'context_json' => wp_json_encode($clean_context),
				'created_at' => gmdate('Y-m-d H:i:s'),
			),
			array('%s', '%d', '%s', '%s', '%s')
		);
		return (bool) $inserted;
	}

	public function counts_by_event_type(int $days = 7): array {
		global $wpdb;
		$table = DDB_Spots_Core_Db_Tables::events();
		$since = gmdate('Y-m-d H:i:s', time() - (max(1, $days) * DAY_IN_SECONDS));
		$sql = "SELECT event_type, COUNT(*) AS total FROM {$table} WHERE created_at >= %s GROUP BY event_type ORDER BY total DESC";
		$rows = $wpdb->get_results($wpdb->prepare($sql, $since), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = array();
		foreach ((array) $rows as $row) {
			$key = sanitize_key((string) ($row['event_type'] ?? ''));
			if ('' === $key) {
				continue;
			}
			$out[ $key ] = (int) ($row['total'] ?? 0);
		}
		return $out;
	}

	public function funnel_metrics(int $days = 7): array {
		$counts = $this->counts_by_event_type($days);
		$view = (int) ($counts['spot_view'] ?? 0);
		$click = (int) ($counts['cta_click'] ?? 0);
		$plan = (int) ($counts['add_to_plan'] ?? 0);
		$book = (int) ($counts['book_click'] ?? 0);

		return array(
			'view' => $view,
			'click' => $click,
			'plan' => $plan,
			'book' => $book,
			'ctr' => $view > 0 ? round(($click / $view) * 100, 2) : 0.0,
			'plan_rate' => $view > 0 ? round(($plan / $view) * 100, 2) : 0.0,
			'book_rate' => $view > 0 ? round(($book / $view) * 100, 2) : 0.0,
		);
	}

	public function popularity_scores(array $spot_ids, int $days = 30): array {
		$spot_ids = array_values(array_filter(array_map('absint', $spot_ids)));
		if (empty($spot_ids)) {
			return array();
		}

		global $wpdb;
		$table = DDB_Spots_Core_Db_Tables::events();
		$since = gmdate('Y-m-d H:i:s', time() - (max(1, $days) * DAY_IN_SECONDS));
		$ids_sql = implode(',', array_fill(0, count($spot_ids), '%d'));
		$sql = "SELECT spot_id, COUNT(*) AS total FROM {$table} WHERE created_at >= %s AND spot_id IN ({$ids_sql}) GROUP BY spot_id";
		$params = array_merge(array($since), $spot_ids);
		$rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$max = 1;
		$counts = array();
		foreach ((array) $rows as $row) {
			$spot_id = absint((int) ($row['spot_id'] ?? 0));
			$total = absint((int) ($row['total'] ?? 0));
			$counts[ $spot_id ] = $total;
			$max = max($max, $total);
		}

		$scores = array();
		foreach ($spot_ids as $spot_id) {
			$raw = (int) ($counts[ $spot_id ] ?? 0);
			$scores[ $spot_id ] = (int) round(($raw / $max) * 100);
		}
		return $scores;
	}

	private function sanitize_context(array $context): array {
		$blocked = array('email', 'phone', 'name', 'ip', 'address');
		$safe = array();
		foreach ($context as $key => $value) {
			$safe_key = sanitize_key((string) $key);
			if ('' === $safe_key || in_array($safe_key, $blocked, true)) {
				continue;
			}
			if (is_scalar($value)) {
				$safe[ $safe_key ] = sanitize_text_field((string) $value);
			}
		}
		return $safe;
	}
}
