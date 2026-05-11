<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Service_Quality_Policy {
	public function build_checks_for_post(WP_Post $post, ?array $config = null): array {
		$config = is_array($config) ? $config : DDB_Spots_Admin_Settings_Page::get_config();
		$post_id = (int) $post->ID;
		$type = $this->get_primary_type($post_id);
		$excerpt = trim((string) $post->post_excerpt);
		$content_text = trim(wp_strip_all_tags((string) $post->post_content));
		$lat = trim((string) get_post_meta($post_id, '_ddb_lat', true));
		$lng = trim((string) get_post_meta($post_id, '_ddb_lng', true));
		$gallery_items = $this->gallery_count($post_id);
		$opening_hours_google = (string) get_post_meta($post_id, '_ddb_google_opening_hours_json', true);
		$opening_hours_manual = (string) get_post_meta($post_id, '_ddb_opening_hours_json', true);
		$bundles_json = (string) get_post_meta($post_id, '_ddb_bundles_json', true);
		$best_time_slot = (string) get_post_meta($post_id, '_ddb_best_time_slot', true);
		$reviews_json = (string) get_post_meta($post_id, '_ddb_google_reviews_json', true);
		$source = (string) get_post_meta($post_id, '_ddb_source', true);
		$last_synced = (string) get_post_meta($post_id, '_ddb_google_last_synced_at', true);
		$tag_count = $this->count_terms($post_id, 'ddb_tag');
		$category_count = $this->count_terms($post_id, 'ddb_category');
		$area_count = $this->count_terms($post_id, 'ddb_area');
		$informational = '1' === (string) get_post_meta($post_id, '_ddb_informational_only', true);

		$ux = isset($config['ux_rules']) && is_array($config['ux_rules']) ? $config['ux_rules'] : array();
		$min_gallery = (int) ($ux['min_gallery_count'] ?? 3);
		$min_excerpt_len = max(140, (int) ($ux['min_excerpt_length'] ?? 140));
		$hero_required = ! empty($ux['hero_image_required']);
		$cta_required = ! array_key_exists('cta_required', $ux) || ! empty($ux['cta_required']);
		$allow_info_only = ! empty($ux['allow_informational_only']);
		$max_tags = (int) ($ux['max_tags'] ?? 8);
		$max_categories = (int) ($ux['max_categories'] ?? 3);
		$type_rules = isset($config['spot_types'][ $type ]['required_fields']) && is_array($config['spot_types'][ $type ]['required_fields']) ? $config['spot_types'][ $type ]['required_fields'] : array();
		$has_cta = $this->has_cta_for_type($post_id, $type);
		$cta_or_info_ok = ! $cta_required || $has_cta || ($allow_info_only && $informational);

		$checks = array(
			array('key' => 'type', 'label' => __('Type ingesteld', 'ddb-spots'), 'ok' => '' !== $type, 'fix_tab' => 'essentials', 'fix_focus' => 'taxonomy-ddb_spot_type'),
			array('key' => 'featured', 'label' => __('Featured image gezet', 'ddb-spots'), 'ok' => ! $hero_required || has_post_thumbnail($post), 'fix_tab' => 'media', 'fix_focus' => 'postimagediv'),
			array('key' => 'location', 'label' => __('Locatie (lat/lng) gezet', 'ddb-spots'), 'ok' => '' !== $lat && '' !== $lng, 'fix_tab' => 'daylogic', 'fix_focus' => 'ddb_lat'),
			array('key' => 'area', 'label' => __('Area ingesteld', 'ddb-spots'), 'ok' => $area_count > 0, 'fix_tab' => 'daylogic', 'fix_focus' => 'taxonomy-ddb_area'),
			array('key' => 'cta_or_info', 'label' => __('CTA of informational-only geconfigureerd', 'ddb-spots'), 'ok' => $cta_or_info_ok, 'fix_tab' => 'essentials', 'fix_focus' => 'ddb_cta_url'),
			array('key' => 'excerpt', 'label' => sprintf(__('Samenvatting aanwezig (%d+ chars)', 'ddb-spots'), $min_excerpt_len), 'ok' => (mb_strlen($excerpt) >= $min_excerpt_len || mb_strlen($content_text) >= $min_excerpt_len), 'fix_tab' => 'essentials', 'fix_focus' => 'excerpt'),
			array('key' => 'gallery', 'label' => sprintf(__('Galerij >= %d items', 'ddb-spots'), $min_gallery), 'ok' => $gallery_items >= $min_gallery, 'fix_tab' => 'media', 'fix_focus' => 'ddb_gallery_ids'),
			array('key' => 'hours', 'label' => __('Openingstijden aanwezig', 'ddb-spots'), 'ok' => '' !== trim($opening_hours_google) || '' !== trim($opening_hours_manual), 'fix_tab' => 'daylogic', 'fix_focus' => 'ddb_opening_hours_json'),
			array('key' => 'source', 'label' => __('Bron ingesteld', 'ddb-spots'), 'ok' => in_array($source, array('manual', 'google_places', 'partner'), true), 'fix_tab' => 'daylogic', 'fix_focus' => 'ddb_source'),
			array('key' => 'google_sync', 'label' => __('Google sync timestamp aanwezig', 'ddb-spots'), 'ok' => 'google_places' !== $source || '' !== $last_synced, 'fix_tab' => 'daylogic', 'fix_focus' => 'ddb_google_last_synced_at'),
			array('key' => 'bundle_links', 'label' => __('Bundle links aanwezig', 'ddb-spots'), 'ok' => '' !== trim($bundles_json), 'fix_tab' => 'bundles', 'fix_focus' => 'ddb_bundles_json'),
			array('key' => 'time_fit', 'label' => __('Day Logic tijdslot ingesteld', 'ddb-spots'), 'ok' => '' !== trim($best_time_slot), 'fix_tab' => 'daylogic', 'fix_focus' => 'ddb_best_time_slot'),
			array('key' => 'reviews', 'label' => __('Reviews aanwezig', 'ddb-spots'), 'ok' => '' !== trim($reviews_json), 'fix_tab' => 'health', 'fix_focus' => 'ddb_spot_health'),
			array('key' => 'max_tags', 'label' => sprintf(__('Max tags <= %d', 'ddb-spots'), $max_tags), 'ok' => $tag_count <= $max_tags, 'fix_tab' => 'health', 'fix_focus' => 'taxonomy-ddb_tag'),
			array('key' => 'max_categories', 'label' => sprintf(__('Max categories <= %d', 'ddb-spots'), $max_categories), 'ok' => $category_count <= $max_categories, 'fix_tab' => 'health', 'fix_focus' => 'taxonomy-ddb_category'),
		);

		foreach ($checks as &$check) {
			if (in_array((string) $check['key'], array_map('strval', $type_rules), true)) {
				$check['label'] = (string) $check['label'] . ' *';
			}
		}
		unset($check);

		return $checks;
	}

	public function get_critical_failures_for_post(WP_Post $post, ?array $config = null): array {
		$config = is_array($config) ? $config : DDB_Spots_Admin_Settings_Page::get_config();
		$checks = $this->build_checks_for_post($post, $config);
		$type = $this->get_primary_type((int) $post->ID);
		$type_rules = isset($config['spot_types'][ $type ]['required_fields']) && is_array($config['spot_types'][ $type ]['required_fields']) ? $config['spot_types'][ $type ]['required_fields'] : array();
		$normalized_rules = array_map(
			static function ($key): string {
				$key = (string) $key;
				return 'booking' === $key ? 'cta_or_info' : $key;
			},
			$type_rules
		);
		$required = array_values(array_unique(array_merge(array('type', 'featured', 'area', 'cta_or_info', 'excerpt'), array_map('strval', $normalized_rules))));
		return array_values(
			array_filter(
				$checks,
				static function (array $check) use ($required): bool {
					return in_array((string) ($check['key'] ?? ''), $required, true) && empty($check['ok']);
				}
			)
		);
	}

	public function get_publish_failures_for_row(array $row, ?array $config = null): array {
		$config = is_array($config) ? $config : DDB_Spots_Admin_Settings_Page::get_config();
		$ux = isset($config['ux_rules']) && is_array($config['ux_rules']) ? $config['ux_rules'] : array();
		$hero_required = ! empty($ux['hero_image_required']);
		$cta_required = ! array_key_exists('cta_required', $ux) || ! empty($ux['cta_required']);
		$allow_info_only = ! empty($ux['allow_informational_only']);
		$min_excerpt_length = max(140, (int) ($ux['min_excerpt_length'] ?? 140));

		$failures = array();
		if ('' === trim((string) ($row['type'] ?? ''))) {
			$failures[] = 'type';
		}
		if ('' === trim((string) ($row['area'] ?? ''))) {
			$failures[] = 'area';
		}
		$summary = trim((string) ($row['short_desc'] ?? ''));
		if ('' === $summary) {
			$summary = trim(wp_strip_all_tags((string) ($row['long_desc'] ?? '')));
		}
		if (mb_strlen($summary) < $min_excerpt_length) {
			$failures[] = 'short_desc';
		}
		$has_cta = '' !== trim((string) ($row['primary_cta_value'] ?? ''));
		$info_only = 1 === (int) ($row['is_informational'] ?? 0);
		if ($cta_required && ! $has_cta && ! ($allow_info_only && $info_only)) {
			$failures[] = 'cta_or_informational';
		}
		$post_id = (int) ($row['spot_post_id'] ?? 0);
		if ($hero_required && $post_id > 0 && ! has_post_thumbnail($post_id)) {
			$failures[] = 'image';
		}
		return $failures;
	}

	private function get_primary_type(int $post_id): string {
		$override = sanitize_title((string) get_post_meta($post_id, '_ddb_spot_type_primary', true));
		if ('' !== $override) {
			return $override;
		}
		$terms = wp_get_post_terms($post_id, 'ddb_spot_type', array('fields' => 'slugs'));
		if (is_wp_error($terms) || empty($terms)) {
			return '';
		}
		return (string) $terms[0];
	}

	private function gallery_count(int $post_id): int {
		$raw = (string) get_post_meta($post_id, '_ddb_gallery_ids', true);
		if ('' === trim($raw)) {
			return 0;
		}
		$ids = array_filter(array_map('absint', array_map('trim', explode(',', $raw))));
		return count($ids);
	}

	private function has_cta_for_type(int $post_id, string $type): bool {
		$provider = (string) get_post_meta($post_id, '_ddb_booking_provider', true);
		$cta = (string) get_post_meta($post_id, '_ddb_cta_url', true);
		$generic_cta = (string) get_post_meta($post_id, '_ddb_spot_cta_url', true);
		$event = (string) get_post_meta($post_id, '_ddb_event_ticket_url', true);
		$hotel = (string) get_post_meta($post_id, '_ddb_hotel_booking_url', true);
		$restaurant = (string) get_post_meta($post_id, '_ddb_restaurant_booking_url', true);
		$formitable_venue = (string) get_post_meta($post_id, '_ddb_formitable_venue_id', true);
		$formitable_widget = (string) get_post_meta($post_id, '_ddb_formitable_widget_id', true);
		$formitable_embed = (string) get_post_meta($post_id, '_ddb_formitable_embed', true);
		$formitable_embed_raw = (string) get_post_meta($post_id, '_ddb_formitable_embed_raw', true);
		if (in_array($type, array('event', 'events'), true)) {
			return (in_array($provider, array('external', 'ticket'), true) && '' !== trim($cta)) || '' !== trim($event);
		}
		if (in_array($type, array('hotel', 'hotels'), true)) {
			return (in_array($provider, array('external', 'ticket'), true) && '' !== trim($cta)) || '' !== trim($hotel);
		}
		if (in_array($type, array('restaurant', 'restaurants'), true)) {
			if ('formitable' === $provider) {
				return '' !== trim($formitable_venue) || '' !== trim($formitable_widget) || '' !== trim($formitable_embed) || '' !== trim($formitable_embed_raw);
			}
			return '' !== trim($cta) || '' !== trim($restaurant) || '' !== trim($generic_cta);
		}
		return '' !== trim($cta) || '' !== trim($generic_cta);
	}

	private function count_terms(int $post_id, string $taxonomy): int {
		$terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'ids'));
		if (is_wp_error($terms)) {
			return 0;
		}
		return count($terms);
	}
}
