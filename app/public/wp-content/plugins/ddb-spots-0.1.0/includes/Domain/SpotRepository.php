<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Domain_Spot_Repository {
	private const CACHE_VERSION_OPTION = 'dbspots_api_cache_version';
	private const CACHE_TTL = 300;

	public static function invalidate_cache(): void {
		$version = (int) get_option(self::CACHE_VERSION_OPTION, 1);
		update_option(self::CACHE_VERSION_OPTION, max(1, $version + 1), false);
	}

	public function get_by_id(int $id): ?array {
		global $wpdb;
		$table = DDB_Spots_Core_Db_Tables::spots();
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if (! is_array($row)) {
			return null;
		}
		return $this->hydrate_row($row);
	}

	public function get_by_post_id(int $post_id): ?array {
		global $wpdb;
		$table = DDB_Spots_Core_Db_Tables::spots();
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE spot_post_id = %d LIMIT 1", $post_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if (! is_array($row)) {
			return null;
		}
		return $this->hydrate_row($row);
	}

	public function get_spots(array $filters, int $page, int $per_page): array {
		global $wpdb;
		$page = max(1, $page);
		$per_page = min(100, max(1, $per_page));
		$cache_key = $this->build_cache_key($filters, $page, $per_page);
		$cached = get_transient($cache_key);
		if (is_array($cached) && isset($cached['items'], $cached['pagination'])) {
			return $cached;
		}

		$table = DDB_Spots_Core_Db_Tables::spots();
		$where = array('1=1');
		$params = array();

		$status = isset($filters['status']) ? sanitize_key((string) $filters['status']) : 'publish';
		if ('' !== $status) {
			$where[] = 'status = %s';
			$params[] = $status;
		}
		if (! empty($filters['type'])) {
			$where[] = 'type = %s';
			$params[] = sanitize_key((string) $filters['type']);
		}
			if (! empty($filters['area'])) {
				$where[] = 'area = %s';
				$params[] = sanitize_key((string) $filters['area']);
			}
			if (! empty($filters['tag'])) {
				$where[] = "EXISTS (
					SELECT 1
					FROM {$wpdb->term_relationships} tr_tag
					INNER JOIN {$wpdb->term_taxonomy} tt_tag ON tt_tag.term_taxonomy_id = tr_tag.term_taxonomy_id
					INNER JOIN {$wpdb->terms} t_tag ON t_tag.term_id = tt_tag.term_id
					WHERE tr_tag.object_id = {$table}.spot_post_id
						AND tt_tag.taxonomy = %s
						AND t_tag.slug = %s
				)";
				$params[] = DDB_Spots_Core_Schema::TAX['tag'];
				$params[] = sanitize_title((string) $filters['tag']);
			}
			if (! empty($filters['category'])) {
				$where[] = "EXISTS (
					SELECT 1
					FROM {$wpdb->term_relationships} tr_category
					INNER JOIN {$wpdb->term_taxonomy} tt_category ON tt_category.term_taxonomy_id = tr_category.term_taxonomy_id
					INNER JOIN {$wpdb->terms} t_category ON t_category.term_id = tt_category.term_id
					WHERE tr_category.object_id = {$table}.spot_post_id
						AND tt_category.taxonomy = %s
						AND t_category.slug = %s
				)";
				$params[] = DDB_Spots_Core_Schema::TAX['category'];
				$params[] = sanitize_title((string) $filters['category']);
			}

			$where_sql = implode(' AND ', $where);
			$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
			$data_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC LIMIT %d OFFSET %d";

			$offset = ($page - 1) * $per_page;
			if (! empty($params)) {
				$total = (int) $wpdb->get_var($wpdb->prepare($total_sql, $params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			} else {
				$total = (int) $wpdb->get_var($total_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}

		$data_params = array_merge($params, array($per_page, $offset));
		$rows = $wpdb->get_results($wpdb->prepare($data_sql, $data_params), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$items = array_map(array($this, 'hydrate_row'), is_array($rows) ? $rows : array());

		$result = array(
			'items' => $items,
			'pagination' => array(
				'page' => $page,
				'per_page' => $per_page,
				'total_items' => $total,
				'total_pages' => max(1, (int) ceil($total / $per_page)),
			),
		);
		set_transient($cache_key, $result, self::CACHE_TTL);
		return $result;
	}

	public function get_candidates(?string $area = null): array {
		global $wpdb;
		$table = DDB_Spots_Core_Db_Tables::spots();
		if ($area) {
			$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = 'publish' AND area = %s", sanitize_key($area)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results("SELECT * FROM {$table} WHERE status = 'publish'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return array_map(array($this, 'hydrate_row'), is_array($rows) ? $rows : array());
	}

	public function upsert_from_post(int $post_id): ?array {
		$post = get_post($post_id);
		if (! $post instanceof WP_Post || 'ddb_spot' !== $post->post_type) {
			return null;
		}

		$type = sanitize_key((string) get_post_meta($post_id, '_ddb_spot_type_primary', true));
		if ('' === $type) {
			$type_terms = wp_get_post_terms($post_id, 'ddb_spot_type', array('fields' => 'slugs'));
			$type = (! is_wp_error($type_terms) && ! empty($type_terms)) ? sanitize_key((string) $type_terms[0]) : '';
		}
		$area_terms = wp_get_post_terms($post_id, 'ddb_area', array('fields' => 'slugs'));
		$area = (! is_wp_error($area_terms) && ! empty($area_terms)) ? sanitize_key((string) $area_terms[0]) : '';
		$tag_terms = wp_get_post_terms($post_id, 'ddb_tag', array('fields' => 'slugs'));
		$tags = (! is_wp_error($tag_terms) && is_array($tag_terms)) ? array_values(array_map('sanitize_key', $tag_terms)) : array();

		$gallery_csv = (string) get_post_meta($post_id, '_ddb_gallery_ids', true);
		$gallery_ids = array_values(array_filter(array_map('absint', array_map('trim', explode(',', $gallery_csv)))));
		$images = array(
			'featured_id' => (int) get_post_thumbnail_id($post_id),
			'gallery_ids' => $gallery_ids,
		);

		$cta = $this->resolve_primary_cta($post_id);
		$lat = $this->to_float((string) get_post_meta($post_id, '_ddb_lat', true));
		$lng = $this->to_float((string) get_post_meta($post_id, '_ddb_lng', true));
		$duration_hint = absint((int) get_post_meta($post_id, '_ddb_duration_hint', true));
		$priority_score = absint((int) get_post_meta($post_id, '_ddb_priority', true));

		$suitability_json = $this->sanitize_json_meta((string) get_post_meta($post_id, '_ddb_suitability_json', true));
		$near_spots_json = $this->sanitize_json_meta((string) get_post_meta($post_id, '_ddb_near_spots_json', true));
		$bundles_json = $this->sanitize_json_meta((string) get_post_meta($post_id, '_ddb_bundles_json', true));
		$is_informational = ('1' === (string) get_post_meta($post_id, '_ddb_informational_only', true)) ? 1 : 0;

		$row = array(
			'spot_post_id' => $post_id,
			'slug' => sanitize_title($post->post_name ?: $post->post_title),
			'name' => sanitize_text_field((string) $post->post_title),
			'type' => $type,
			'status' => sanitize_key((string) $post->post_status),
			'short_desc' => sanitize_textarea_field((string) $post->post_excerpt),
			'long_desc' => wp_kses_post((string) $post->post_content),
			'address' => sanitize_text_field((string) get_post_meta($post_id, '_ddb_address', true)),
			'lat' => $lat,
			'lng' => $lng,
			'area' => $area,
			'price_band' => sanitize_text_field((string) get_post_meta($post_id, '_ddb_price_level', true)),
			'duration_hint' => $duration_hint,
			'suitability_json' => $suitability_json,
			'images_json' => wp_json_encode($images),
			'tags_json' => wp_json_encode($tags),
			'primary_cta_type' => $cta['type'],
			'primary_cta_value' => $cta['value'],
			'primary_cta_label' => $cta['label'],
			'near_spots_json' => $near_spots_json,
			'bundles_json' => $bundles_json,
			'is_informational' => $is_informational,
			'source' => sanitize_key((string) get_post_meta($post_id, '_ddb_source', true)),
			'place_id' => sanitize_text_field((string) get_post_meta($post_id, '_ddb_google_place_id', true)),
			'priority_score' => $priority_score,
			'last_synced_at' => $this->to_mysql_datetime((string) get_post_meta($post_id, '_ddb_google_last_synced_at', true)),
			'updated_at' => gmdate('Y-m-d H:i:s'),
		);

		return $this->upsert_row($row);
	}

	public function set_status(int $id, string $status): ?array {
		global $wpdb;
		$table = DDB_Spots_Core_Db_Tables::spots();
		$ok = $wpdb->update(
			$table,
			array('status' => sanitize_key($status), 'updated_at' => gmdate('Y-m-d H:i:s')),
			array('id' => $id),
			array('%s', '%s'),
			array('%d')
		);
		if (false === $ok) {
			return null;
		}
		self::invalidate_cache();
		return $this->get_by_id($id);
	}

	public function upsert_from_api_payload(array $payload, ?int $canonical_id = null): ?array {
		$existing = null;
		if (is_int($canonical_id) && $canonical_id > 0) {
			$existing = $this->get_by_id($canonical_id);
		}

		$post_id = is_array($existing) ? (int) ($existing['spot_post_id'] ?? 0) : 0;
		if ($post_id <= 0) {
			$inserted = wp_insert_post(
				array(
					'post_type' => 'ddb_spot',
					'post_title' => (string) ($payload['name'] ?? ''),
					'post_excerpt' => (string) ($payload['short_desc'] ?? ''),
					'post_content' => (string) ($payload['long_desc'] ?? ''),
					'post_status' => 'draft',
				),
				true
			);
			if (is_wp_error($inserted) || (int) $inserted <= 0) {
				return null;
			}
			$post_id = (int) $inserted;
		}

		$this->apply_payload_to_post($post_id, $payload);
		return $this->upsert_from_post($post_id);
	}

	private function upsert_row(array $row): ?array {
		global $wpdb;
		$table = DDB_Spots_Core_Db_Tables::spots();
		$existing = $this->get_by_post_id((int) $row['spot_post_id']);
		if (null === $existing && ! empty($row['place_id'])) {
			$existing = $this->get_by_place_id((string) $row['place_id']);
		}

		if (null === $existing) {
			$row['created_at'] = gmdate('Y-m-d H:i:s');
			$inserted = $wpdb->insert($table, $row, $this->row_formats($row));
			if (! $inserted) {
				return null;
			}
			$id = (int) $wpdb->insert_id;
		} else {
			$id = (int) $existing['id'];
			$updated = $wpdb->update($table, $row, array('id' => $id), $this->row_formats($row), array('%d'));
			if (false === $updated) {
				return null;
			}
		}

		self::invalidate_cache();
		return $this->get_by_id($id);
	}

	private function get_by_place_id(string $place_id): ?array {
		$place_id = sanitize_text_field($place_id);
		if ('' === $place_id) {
			return null;
		}
		global $wpdb;
		$table = DDB_Spots_Core_Db_Tables::spots();
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE place_id = %s ORDER BY id ASC LIMIT 1", $place_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if (! is_array($row)) {
			return null;
		}
		return $this->hydrate_row($row);
	}

	private function row_formats(array $row): array {
		$formats = array();
		foreach ($row as $key => $value) {
			unset($value);
			if (in_array($key, array('spot_post_id', 'duration_hint', 'is_informational', 'priority_score'), true)) {
				$formats[] = '%d';
				continue;
			}
			if (in_array($key, array('lat', 'lng'), true)) {
				$formats[] = '%f';
				continue;
			}
			$formats[] = '%s';
		}
		return $formats;
	}

	private function resolve_primary_cta(int $post_id): array {
		$type = sanitize_key((string) get_post_meta($post_id, '_ddb_booking_provider', true));
		$candidates = array(
			(string) get_post_meta($post_id, '_ddb_cta_url', true),
			(string) get_post_meta($post_id, '_ddb_restaurant_booking_url', true),
			(string) get_post_meta($post_id, '_ddb_event_ticket_url', true),
			(string) get_post_meta($post_id, '_ddb_hotel_booking_url', true),
			(string) get_post_meta($post_id, '_ddb_spot_cta_url', true),
		);
		$value = '';
		foreach ($candidates as $candidate) {
			$clean = esc_url_raw($candidate);
			if ('' !== $clean) {
				$value = $clean;
				break;
			}
		}
		if ('' === $type) {
			$type = '' !== $value ? 'external' : 'none';
		}
		return array(
			'type' => $type,
			'value' => $value,
			'label' => '' !== $value ? __('Bekijk details', 'ddb-spots') : '',
		);
	}

	private function apply_payload_to_post(int $post_id, array $payload): void {
		wp_update_post(
			array(
				'ID' => $post_id,
				'post_title' => sanitize_text_field((string) ($payload['name'] ?? '')),
				'post_excerpt' => sanitize_textarea_field((string) ($payload['short_desc'] ?? '')),
				'post_content' => wp_kses_post((string) ($payload['long_desc'] ?? '')),
			)
		);

		$type = sanitize_key((string) ($payload['type'] ?? ''));
		if ('' !== $type) {
			$type_term_id = $this->ensure_term_id('ddb_spot_type', $type);
			if ($type_term_id > 0) {
				wp_set_post_terms($post_id, array($type_term_id), 'ddb_spot_type', false);
				update_post_meta($post_id, '_ddb_spot_type_primary', $type);
			}
		}

		$area = sanitize_key((string) ($payload['area'] ?? ''));
		if ('' !== $area) {
			$area_term_id = $this->ensure_term_id('ddb_area', $area);
			if ($area_term_id > 0) {
				wp_set_post_terms($post_id, array($area_term_id), 'ddb_area', false);
			}
		}

		update_post_meta($post_id, '_ddb_spot_cta_url', esc_url_raw((string) ($payload['primary_cta_value'] ?? '')));
		update_post_meta($post_id, '_ddb_booking_provider', sanitize_key((string) ($payload['primary_cta_type'] ?? 'external')));
		update_post_meta($post_id, '_ddb_price_level', sanitize_text_field((string) ($payload['price_band'] ?? '')));
		update_post_meta($post_id, '_ddb_duration_hint', (string) absint((int) ($payload['duration_hint'] ?? 0)));
		update_post_meta($post_id, '_ddb_lat', $this->sanitize_coordinate((string) ($payload['lat'] ?? '')));
		update_post_meta($post_id, '_ddb_lng', $this->sanitize_coordinate((string) ($payload['lng'] ?? '')));
		update_post_meta($post_id, '_ddb_informational_only', ! empty($payload['is_informational']) ? '1' : '0');
	}

	private function ensure_term_id(string $taxonomy, string $slug): int {
		$slug = sanitize_title($slug);
		if ('' === $slug) {
			return 0;
		}
		$term = get_term_by('slug', $slug, $taxonomy);
		if ($term instanceof WP_Term) {
			return (int) $term->term_id;
		}
		$created = wp_insert_term(ucwords(str_replace('-', ' ', $slug)), $taxonomy, array('slug' => $slug));
		if (is_wp_error($created)) {
			return 0;
		}
		return isset($created['term_id']) ? (int) $created['term_id'] : 0;
	}

	private function sanitize_coordinate(string $value): string {
		$value = trim(str_replace(',', '.', $value));
		if ('' === $value || ! is_numeric($value)) {
			return '';
		}
		return (string) ((float) $value);
	}

	private function sanitize_json_meta(string $value): string {
		$value = trim($value);
		if ('' === $value) {
			return wp_json_encode(array());
		}
		$decoded = json_decode($value, true);
		if (! is_array($decoded)) {
			return wp_json_encode(array());
		}
		return wp_json_encode($decoded);
	}

	private function to_float(string $value): ?float {
		$value = trim(str_replace(',', '.', $value));
		if ('' === $value || ! is_numeric($value)) {
			return null;
		}
		return (float) $value;
	}

	private function to_mysql_datetime(string $value): ?string {
		$value = trim($value);
		if ('' === $value) {
			return null;
		}
		$timestamp = strtotime($value);
		if (false === $timestamp) {
			return null;
		}
		return gmdate('Y-m-d H:i:s', $timestamp);
	}

	private function build_cache_key(array $filters, int $page, int $per_page): string {
		$version = (int) get_option(self::CACHE_VERSION_OPTION, 1);
		$payload = wp_json_encode(
			array(
				'v' => max(1, $version),
				'f' => $filters,
				'p' => $page,
				'pp' => $per_page,
			)
		);
		return 'dbspots_spots_' . md5((string) $payload);
	}

	private function hydrate_row(array $row): array {
		$json_fields = array('suitability_json', 'images_json', 'tags_json', 'near_spots_json', 'bundles_json');
		foreach ($json_fields as $field) {
			$decoded = isset($row[ $field ]) ? json_decode((string) $row[ $field ], true) : null;
			$row[ $field ] = is_array($decoded) ? $decoded : array();
		}
		$row['id'] = (int) ($row['id'] ?? 0);
		$row['spot_post_id'] = (int) ($row['spot_post_id'] ?? 0);
		$row['duration_hint'] = (int) ($row['duration_hint'] ?? 0);
		$row['priority_score'] = (int) ($row['priority_score'] ?? 0);
		$row['is_informational'] = (int) ($row['is_informational'] ?? 0);
		$row['lat'] = null !== $row['lat'] ? (float) $row['lat'] : null;
		$row['lng'] = null !== $row['lng'] ? (float) $row['lng'] : null;
		return $row;
	}
}
