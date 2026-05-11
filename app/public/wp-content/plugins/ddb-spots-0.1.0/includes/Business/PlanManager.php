<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Business_Plan_Manager {
	public static function bulk_set_plan(array $business_ids, string $plan_key, string $status = '', string $period_end = '', bool $dry_run = true): array {
		$plan_key = ddb_spots_normalize_plan_key($plan_key);
		$status = self::normalize_status($status, $plan_key);
		$period_end = self::sanitize_period_end($period_end);
		$ids = array_values(array_unique(array_filter(array_map('absint', $business_ids))));

		$result = array(
			'dry_run' => $dry_run,
			'updated' => 0,
			'skipped' => 0,
			'rows' => array(),
		);
		foreach ($ids as $business_id) {
			if (! DDB_Spots_Business_Registry::is_valid_business_id($business_id)) {
				$result['skipped']++;
				continue;
			}
			$current = DDB_Spots_Business_Registry::get_business_plan_info($business_id);
			$changed = ($plan_key !== (string) ($current['plan_key'] ?? '')) || ($status !== (string) ($current['status'] ?? '')) || ($period_end !== (string) ($current['period_end'] ?? ''));
			if (! $changed) {
				$result['skipped']++;
				continue;
			}

			$result['updated']++;
			$result['rows'][] = array(
				'business_id' => $business_id,
				'business_title' => (string) get_the_title($business_id),
				'from_plan' => (string) ($current['plan_key'] ?? 'free'),
				'from_status' => (string) ($current['status'] ?? 'inactive'),
				'to_plan' => $plan_key,
				'to_status' => $status,
				'to_period_end' => $period_end,
			);

			if ($dry_run) {
				continue;
			}
			update_post_meta($business_id, DDB_Spots_Business_Registry::META_PLAN_KEY, $plan_key);
			update_post_meta($business_id, DDB_Spots_Business_Registry::META_STATUS, $status);
			update_post_meta($business_id, DDB_Spots_Business_Registry::META_PERIOD_END, $period_end);
		}
		return $result;
	}

	public static function migrate_from_spot_overrides(array $options = array()): array {
		$options = array_merge(
			array(
				'dry_run' => true,
				'only_unset_businesses' => true,
				'respect_plan_source' => false,
				'limit' => 0,
			),
			$options
		);

		$business_ids = get_posts(
			array(
				'post_type' => DDB_Spots_Business_Registry::POST_TYPE,
				'post_status' => array('publish', 'draft', 'pending', 'private'),
				'posts_per_page' => max(0, (int) $options['limit']) > 0 ? max(1, (int) $options['limit']) : -1,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
				'no_found_rows' => true,
			)
		);

		$result = array(
			'dry_run' => ! empty($options['dry_run']),
			'scanned' => 0,
			'updated' => 0,
			'skipped' => 0,
			'rows' => array(),
		);

		foreach ((array) $business_ids as $business_id_raw) {
			$business_id = absint((int) $business_id_raw);
			if (! DDB_Spots_Business_Registry::is_valid_business_id($business_id)) {
				continue;
			}
			$result['scanned']++;
			$current = DDB_Spots_Business_Registry::get_business_plan_info($business_id);
			if (! empty($options['only_unset_businesses'])) {
				$is_default = ('free' === (string) ($current['plan_key'] ?? 'free')) && ('inactive' === (string) ($current['status'] ?? 'inactive'));
				if (! $is_default) {
					$result['skipped']++;
					continue;
				}
			}

			$aggregate = self::aggregate_spot_overrides_for_business($business_id, ! empty($options['respect_plan_source']));
			if (empty($aggregate['has_data'])) {
				$result['skipped']++;
				continue;
			}

			$new_plan = ddb_spots_normalize_plan_key((string) ($aggregate['plan_key'] ?? 'free'));
			$new_status = self::normalize_status((string) ($aggregate['status'] ?? ''), $new_plan);
			$new_period_end = self::sanitize_period_end((string) ($aggregate['period_end'] ?? ''));
			$changed = $new_plan !== (string) ($current['plan_key'] ?? 'free') || $new_status !== (string) ($current['status'] ?? 'inactive') || $new_period_end !== (string) ($current['period_end'] ?? '');
			if (! $changed) {
				$result['skipped']++;
				continue;
			}

			$result['updated']++;
			$result['rows'][] = array(
				'business_id' => $business_id,
				'business_title' => (string) get_the_title($business_id),
				'from_plan' => (string) ($current['plan_key'] ?? 'free'),
				'from_status' => (string) ($current['status'] ?? 'inactive'),
				'to_plan' => $new_plan,
				'to_status' => $new_status,
				'to_period_end' => $new_period_end,
				'source_spots' => (int) ($aggregate['source_spots'] ?? 0),
			);

			if (! empty($options['dry_run'])) {
				continue;
			}
			update_post_meta($business_id, DDB_Spots_Business_Registry::META_PLAN_KEY, $new_plan);
			update_post_meta($business_id, DDB_Spots_Business_Registry::META_STATUS, $new_status);
			update_post_meta($business_id, DDB_Spots_Business_Registry::META_PERIOD_END, $new_period_end);
		}

		if (! empty($result['rows']) && count($result['rows']) > 200) {
			$result['rows'] = array_slice($result['rows'], 0, 200);
		}
		return $result;
	}

	public static function collect_business_ids_with_linked_spots(): array {
		$spot_ids = get_posts(
			array(
				'post_type' => 'ddb_spot',
				'post_status' => array('publish', 'draft', 'pending', 'private'),
				'posts_per_page' => -1,
				'fields' => 'ids',
				'no_found_rows' => true,
			)
		);
		$out = array();
		foreach ((array) $spot_ids as $spot_id_raw) {
			$spot_id = absint((int) $spot_id_raw);
			$business_id = absint((int) get_post_meta($spot_id, '_ddb_business_id', true));
			if ($business_id > 0 && DDB_Spots_Business_Registry::is_valid_business_id($business_id)) {
				$out[] = $business_id;
			}
		}
		return array_values(array_unique($out));
	}

	private static function aggregate_spot_overrides_for_business(int $business_id, bool $respect_plan_source): array {
		$spot_ids = get_posts(
			array(
				'post_type' => 'ddb_spot',
				'post_status' => array('publish', 'draft', 'pending', 'private'),
				'posts_per_page' => -1,
				'fields' => 'ids',
				'meta_query' => array(
					array(
						'key' => '_ddb_business_id',
						'value' => (string) $business_id,
					),
				),
				'no_found_rows' => true,
			)
		);

		$rank_map = array('free' => 0, 'presence' => 1, 'conversion' => 2, 'partner' => 3);
		$status_rank = array('inactive' => 0, 'canceled' => 1, 'past_due' => 2, 'trial' => 3, 'active' => 4);
		$best_plan = 'free';
		$best_status = 'inactive';
		$best_period = '';
		$has_data = false;
		$source_spots = 0;

		foreach ((array) $spot_ids as $spot_id_raw) {
			$spot_id = absint((int) $spot_id_raw);
			if ($spot_id <= 0) {
				continue;
			}
			if ($respect_plan_source) {
				$source = sanitize_key((string) get_post_meta($spot_id, '_ddb_premium_plan_source', true));
				if ('spot' !== $source) {
					continue;
				}
			}
			$plan = ddb_spots_normalize_plan_key((string) get_post_meta($spot_id, '_ddb_premium_plan_key', true));
			$status = sanitize_key((string) get_post_meta($spot_id, '_ddb_premium_status', true));
			if (! array_key_exists($status, $status_rank)) {
				$status = 'inactive';
			}
			$period = self::sanitize_period_end((string) get_post_meta($spot_id, '_ddb_premium_period_end', true));
			$has_any_raw = '' !== trim((string) get_post_meta($spot_id, '_ddb_premium_plan_key', true)) || '' !== trim((string) get_post_meta($spot_id, '_ddb_premium_status', true)) || '' !== trim((string) get_post_meta($spot_id, '_ddb_premium_period_end', true));
			if (! $has_any_raw) {
				continue;
			}
			$has_data = true;
			$source_spots++;

			if (($rank_map[ $plan ] ?? 0) > ($rank_map[ $best_plan ] ?? 0)) {
				$best_plan = $plan;
				$best_status = $status;
				$best_period = $period;
				continue;
			}
			if ($plan === $best_plan) {
				if (($status_rank[ $status ] ?? 0) > ($status_rank[ $best_status ] ?? 0)) {
					$best_status = $status;
				}
				if (self::period_is_newer($period, $best_period)) {
					$best_period = $period;
				}
			}
		}

		return array(
			'has_data' => $has_data,
			'source_spots' => $source_spots,
			'plan_key' => $best_plan,
			'status' => $best_status,
			'period_end' => $best_period,
		);
	}

	private static function normalize_status(string $status, string $plan_key): string {
		$status = sanitize_key($status);
		$allowed = array('inactive', 'trial', 'active', 'past_due', 'canceled');
		if (in_array($status, $allowed, true)) {
			return $status;
		}
		return in_array($plan_key, array('presence', 'conversion', 'partner'), true) ? 'active' : 'inactive';
	}

	private static function sanitize_period_end(string $value): string {
		$value = trim(sanitize_text_field($value));
		if ('' === $value) {
			return '';
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return $value;
		}
		$ts = strtotime($value);
		if (false === $ts) {
			return '';
		}
		return gmdate('Y-m-d', $ts);
	}

	private static function period_is_newer(string $a, string $b): bool {
		if ('' === $a) {
			return false;
		}
		if ('' === $b) {
			return true;
		}
		$ta = strtotime($a . ' 00:00:00 UTC');
		$tb = strtotime($b . ' 00:00:00 UTC');
		if (false === $ta) {
			return false;
		}
		if (false === $tb) {
			return true;
		}
		return $ta > $tb;
	}
}
