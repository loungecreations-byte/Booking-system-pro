<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Premium_Analytics {
	private const ALLOWED_EVENT_TYPES = array(
		'view',
		'spot_view',
		'cta_click',
		'module_event',
	);

	public function init(): void {
		// Hook container for future admin/cron integrations.
	}

	public static function table_name(): string {
		if (class_exists('DDB_Spots_Core_Db_Tables') && method_exists('DDB_Spots_Core_Db_Tables', 'spot_events')) {
			return DDB_Spots_Core_Db_Tables::spot_events();
		}
		global $wpdb;
		return $wpdb->prefix . 'ddb_spot_events';
	}

	public static function create_table_sql(string $charset = ''): string {
		$table = self::table_name();
		if ('' === $charset) {
			global $wpdb;
			$charset = $wpdb->get_charset_collate();
		}

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			spot_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			event_type VARCHAR(64) NOT NULL,
			ts DATETIME NOT NULL,
			meta_json LONGTEXT NULL,
			PRIMARY KEY (id),
			KEY idx_spot_ts (spot_id, ts),
			KEY idx_event_ts (event_type, ts)
		) {$charset};";
	}

	public static function log_event(int $spot_id, string $event_type, array $meta = array()): bool {
		$event_type = sanitize_key($event_type);
		if (! in_array($event_type, self::ALLOWED_EVENT_TYPES, true)) {
			return false;
		}

		global $wpdb;
		$table = self::table_name();
		$meta = self::sanitize_meta($meta);
		$ok = $wpdb->insert(
			$table,
			array(
				'spot_id' => max(0, $spot_id),
				'event_type' => $event_type,
				'ts' => gmdate('Y-m-d H:i:s'),
				'meta_json' => wp_json_encode($meta),
			),
			array('%d', '%s', '%s', '%s')
		);
		return (bool) $ok;
	}

	public static function spot_report(int $days = 30): array {
		$days = max(1, min(90, $days));
		$since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
		global $wpdb;
		$table = self::table_name();
		$sql = "SELECT spot_id, event_type, meta_json FROM {$table} WHERE ts >= %s AND spot_id > 0";
		$rows = $wpdb->get_results($wpdb->prepare($sql, $since), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if (! is_array($rows)) {
			return array();
		}

		$report = array();
		foreach ($rows as $row) {
			$spot_id = absint((int) ($row['spot_id'] ?? 0));
			if ($spot_id <= 0) {
				continue;
			}
			if (! isset($report[ $spot_id ])) {
				$report[ $spot_id ] = array(
					'spot_id' => $spot_id,
					'views' => 0,
					'cta_clicks' => 0,
					'cta_types' => array(),
					'module_events' => 0,
					'module_actions' => array(),
				);
			}
			$event_type = sanitize_key((string) ($row['event_type'] ?? ''));
			if (in_array($event_type, array('view', 'spot_view'), true)) {
				$report[ $spot_id ]['views']++;
			}
			if ('cta_click' === $event_type) {
				$report[ $spot_id ]['cta_clicks']++;
				$meta = json_decode((string) ($row['meta_json'] ?? ''), true);
				$cta_type = '';
				if (is_array($meta) && isset($meta['cta_type'])) {
					$cta_type = sanitize_key((string) $meta['cta_type']);
				}
				if ('' === $cta_type) {
					$cta_type = 'unknown';
				}
				if (! isset($report[ $spot_id ]['cta_types'][ $cta_type ])) {
					$report[ $spot_id ]['cta_types'][ $cta_type ] = 0;
				}
				$report[ $spot_id ]['cta_types'][ $cta_type ]++;
			}
			if ('module_event' === $event_type) {
				$report[ $spot_id ]['module_events']++;
				$meta = json_decode((string) ($row['meta_json'] ?? ''), true);
				$module = '';
				$action = '';
				if (is_array($meta)) {
					$module = sanitize_key((string) ($meta['module'] ?? ''));
					$action = sanitize_key((string) ($meta['action'] ?? ''));
				}
				if ('' === $module) {
					$module = 'unknown';
				}
				if ('' === $action) {
					$action = 'unknown';
				}
				if (! isset($report[ $spot_id ]['module_actions'][ $module ])) {
					$report[ $spot_id ]['module_actions'][ $module ] = array();
				}
				if (! isset($report[ $spot_id ]['module_actions'][ $module ][ $action ])) {
					$report[ $spot_id ]['module_actions'][ $module ][ $action ] = 0;
				}
				$report[ $spot_id ]['module_actions'][ $module ][ $action ]++;
			}
		}

		$rows = array_values($report);
		usort(
			$rows,
			static function (array $a, array $b): int {
				$views_a = (int) ($a['views'] ?? 0);
				$views_b = (int) ($b['views'] ?? 0);
				if ($views_a === $views_b) {
					$cta_cmp = ((int) ($b['cta_clicks'] ?? 0)) <=> ((int) ($a['cta_clicks'] ?? 0));
					if (0 !== $cta_cmp) {
						return $cta_cmp;
					}
					return ((int) ($b['module_events'] ?? 0)) <=> ((int) ($a['module_events'] ?? 0));
				}
				return $views_b <=> $views_a;
			}
		);
		return $rows;
	}

	public static function module_action_totals(int $days = 30): array {
		$totals = array();
		foreach (self::spot_report($days) as $row) {
			$module_actions = isset($row['module_actions']) && is_array($row['module_actions']) ? $row['module_actions'] : array();
			foreach ($module_actions as $module => $actions) {
				$module = sanitize_key((string) $module);
				if ('' === $module) {
					$module = 'unknown';
				}
				if (! isset($totals[ $module ])) {
					$totals[ $module ] = array();
				}
				foreach ((array) $actions as $action => $count) {
					$action = sanitize_key((string) $action);
					if ('' === $action) {
						$action = 'unknown';
					}
					if (! isset($totals[ $module ][ $action ])) {
						$totals[ $module ][ $action ] = 0;
					}
					$totals[ $module ][ $action ] += absint((int) $count);
				}
			}
		}

		foreach ($totals as $module => $actions) {
			arsort($actions);
			$totals[ $module ] = $actions;
		}
		uksort(
			$totals,
			static function (string $a, string $b) use ($totals): int {
				$sum_a = array_sum((array) ($totals[ $a ] ?? array()));
				$sum_b = array_sum((array) ($totals[ $b ] ?? array()));
				if ($sum_a === $sum_b) {
					return strcmp($a, $b);
				}
				return $sum_b <=> $sum_a;
			}
		);
		return $totals;
	}

	public static function export_csv(int $days = 30): void {
		$filename = 'ddb-premium-events-' . gmdate('Ymd-His') . '.csv';
		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=' . $filename);

		$fp = fopen('php://output', 'w');
		if (false === $fp) {
			return;
		}
		fputcsv($fp, array('spot_id', 'spot_title', 'views_30d', 'cta_clicks_30d', 'cta_types', 'module_events_30d', 'module_actions'));
		foreach (self::spot_report($days) as $row) {
			$spot_id = (int) ($row['spot_id'] ?? 0);
			$title = $spot_id > 0 ? get_the_title($spot_id) : '';
			$types = array();
			foreach ((array) ($row['cta_types'] ?? array()) as $type => $count) {
				$types[] = sanitize_key((string) $type) . ':' . absint((int) $count);
			}
			$module_pairs = array();
			foreach ((array) ($row['module_actions'] ?? array()) as $module => $actions) {
				foreach ((array) $actions as $action => $count) {
					$module_pairs[] = sanitize_key((string) $module) . '.' . sanitize_key((string) $action) . ':' . absint((int) $count);
				}
			}
			fputcsv(
				$fp,
				array(
					$spot_id,
					(string) $title,
					(int) ($row['views'] ?? 0),
					(int) ($row['cta_clicks'] ?? 0),
					implode('|', $types),
					(int) ($row['module_events'] ?? 0),
					implode('|', $module_pairs),
				)
			);
		}
		fclose($fp);
	}

	private static function sanitize_meta(array $meta): array {
		$blocked = array('email', 'name', 'phone', 'ip', 'address');
		$clean = array();
		foreach ($meta as $key => $value) {
			$key = sanitize_key((string) $key);
			if ('' === $key || in_array($key, $blocked, true)) {
				continue;
			}
			if (is_scalar($value)) {
				$clean[ $key ] = sanitize_text_field((string) $value);
			}
		}
		return $clean;
	}
}
