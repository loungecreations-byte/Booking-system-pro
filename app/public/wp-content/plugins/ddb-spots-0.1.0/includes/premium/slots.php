<?php
if (! defined('ABSPATH')) {
	exit;
}

if (! function_exists('ddb_spots_top_pick_context_for_spot')) {
	function ddb_spots_top_pick_context_for_spot(int $spot_id): array {
		$category_id = ddb_spots_primary_term_id($spot_id, 'ddb_category');
		$area_id = ddb_spots_primary_term_id($spot_id, 'ddb_area');
		$key = ddb_spots_top_pick_slot_key($category_id, $area_id);
		$caps = (array) ddb_spots_premium_setting('top_picks_caps', array());
		$cap = (int) ($caps[ $key ] ?? 0);
		$current_selected = '1' === (string) get_post_meta($spot_id, DDB_Spots_Premium_Engine::META_TOP_PICK, true);
		$selected_count = 0;
		$slot_available = false;
		if ($cap > 0) {
			$selected_ids = ddb_spots_top_pick_selected_ids($category_id, $area_id);
			$selected_count = count($selected_ids);
			$slot_available = ($selected_count < $cap || $current_selected);
		}

		return array(
			'category_id' => $category_id,
			'area_id' => $area_id,
			'key' => $key,
			'cap' => $cap,
			'selected_count' => $selected_count,
			'slot_available' => $slot_available,
			'current_selected' => $current_selected,
		);
	}
}

if (! function_exists('ddb_spots_top_pick_slot_available')) {
	function ddb_spots_top_pick_slot_available(int $spot_id): bool {
		$context = ddb_spots_top_pick_context_for_spot($spot_id);
		return ! empty($context['slot_available']);
	}
}

if (! function_exists('ddb_spots_top_pick_slot_key')) {
	function ddb_spots_top_pick_slot_key(int $category_id, int $area_id): string {
		$category_id = max(0, $category_id);
		$area_id = max(0, $area_id);
		return $category_id . '|' . $area_id;
	}
}

if (! function_exists('ddb_spots_primary_term_id')) {
	function ddb_spots_primary_term_id(int $spot_id, string $taxonomy): int {
		$terms = wp_get_post_terms($spot_id, $taxonomy, array('fields' => 'ids'));
		if (is_wp_error($terms) || empty($terms)) {
			return 0;
		}
		return absint((int) $terms[0]);
	}
}

if (! function_exists('ddb_spots_top_pick_selected_ids')) {
	function ddb_spots_top_pick_selected_ids(int $category_id, int $area_id): array {
		$cache_key = ddb_spots_top_pick_slot_key($category_id, $area_id);
		static $cache = array();
		if (isset($cache[ $cache_key ])) {
			return $cache[ $cache_key ];
		}
		if ($category_id <= 0 || $area_id <= 0) {
			$cache[ $cache_key ] = array();
			return $cache[ $cache_key ];
		}

		$ids = get_posts(
			array(
				'post_type' => 'ddb_spot',
				'post_status' => 'publish',
				'fields' => 'ids',
				'posts_per_page' => -1,
				'tax_query' => array(
					'relation' => 'AND',
					array(
						'taxonomy' => 'ddb_category',
						'field' => 'term_id',
						'terms' => array($category_id),
					),
					array(
						'taxonomy' => 'ddb_area',
						'field' => 'term_id',
						'terms' => array($area_id),
					),
				),
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key' => DDB_Spots_Premium_Engine::META_TOP_PICK,
						'value' => '1',
					),
					array(
						'key' => DDB_Spots_Premium_Engine::META_PLAN_KEY,
						'value' => 'partner',
					),
					array(
						'key' => DDB_Spots_Premium_Engine::META_STATUS,
						'value' => array('active', 'trial'),
						'compare' => 'IN',
					),
				),
			)
		);

		$cache[ $cache_key ] = array_values(array_filter(array_map('absint', (array) $ids)));
		return $cache[ $cache_key ];
	}
}

if (! function_exists('ddb_spots_top_pick_active_ids')) {
	function ddb_spots_top_pick_active_ids(int $category_id, int $area_id): array {
		$cache_key = ddb_spots_top_pick_slot_key($category_id, $area_id);
		static $cache = array();
		if (isset($cache[ $cache_key ])) {
			return $cache[ $cache_key ];
		}

		$selected = ddb_spots_top_pick_selected_ids($category_id, $area_id);
		if (empty($selected)) {
			$cache[ $cache_key ] = array();
			return $cache[ $cache_key ];
		}

		$caps = (array) ddb_spots_premium_setting('top_picks_caps', array());
		$cap = (int) ($caps[ $cache_key ] ?? 0);
		if ($cap <= 0) {
			$cache[ $cache_key ] = array();
			return $cache[ $cache_key ];
		}

		if (count($selected) <= $cap) {
			$cache[ $cache_key ] = $selected;
			return $cache[ $cache_key ];
		}

		$seed = (int) gmdate('z');
		$count = count($selected);
		$offset = $count > 0 ? ($seed % $count) : 0;
		$rotated = array_merge(array_slice($selected, $offset), array_slice($selected, 0, $offset));
		$cache[ $cache_key ] = array_slice($rotated, 0, $cap);
		return $cache[ $cache_key ];
	}
}

if (! function_exists('ddb_spots_is_top_pick_active')) {
	function ddb_spots_is_top_pick_active(int $spot_id): bool {
		if ('1' !== (string) get_post_meta($spot_id, DDB_Spots_Premium_Engine::META_TOP_PICK, true)) {
			return false;
		}
		$plan = ddb_spots_get_spot_plan_info($spot_id);
		if (empty($plan['is_paid_active']) || 'partner' !== (string) ($plan['plan_key'] ?? '')) {
			return false;
		}

		$context = ddb_spots_top_pick_context_for_spot($spot_id);
		$active_ids = ddb_spots_top_pick_active_ids((int) ($context['category_id'] ?? 0), (int) ($context['area_id'] ?? 0));
		return in_array($spot_id, $active_ids, true);
	}
}
