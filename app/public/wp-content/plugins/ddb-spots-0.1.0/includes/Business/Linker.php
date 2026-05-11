<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Business_Linker {
	private const SPOT_POST_TYPE = 'ddb_spot';
	private const SPOT_META_BUSINESS_ID = '_ddb_business_id';
	private const SPOT_META_PLAN_SOURCE = '_ddb_premium_plan_source';
	private const SPOT_META_GOOGLE_PLACE_ID = '_ddb_google_place_id';
	private const SPOT_META_GOOGLE_WEBSITE = '_ddb_google_website';
	private const SPOT_META_CTA_URL = '_ddb_spot_cta_url';

	public function init(): void {
		add_action('save_post_' . self::SPOT_POST_TYPE, array($this, 'handle_spot_save'), 20, 2);
	}

	public function handle_spot_save(int $post_id, WP_Post $post): void {
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if (self::SPOT_POST_TYPE !== $post->post_type) {
			return;
		}

		$current_biz = self::get_spot_business_id($post_id);
		if ($current_biz <= 0) {
			$this->auto_link_by_match($post_id);
		}
	}

	public function auto_link_by_match(int $spot_id): ?int {
		$place_id = get_post_meta($spot_id, DDB_Spots_Core_Schema::META['google_place_id'], true);
		$website = get_post_meta($spot_id, DDB_Spots_Core_Schema::META['google_website'], true);

		// 1. Try Place ID match
		if (! empty($place_id)) {
			$q = new WP_Query(array(
				'post_type' => DDB_Spots_Business_Registry::POST_TYPE,
				'meta_key' => DDB_Spots_Business_Registry::META_GOOGLE_PLACE_ID,
				'meta_value' => $place_id,
				'fields' => 'ids',
				'posts_per_page' => 1,
				));
				if (! empty($q->posts)) {
					$business_id = (int) $q->posts[0];
					self::sync_spot_business_meta($spot_id, $business_id);
					return $business_id;
				}
			}

		// 2. Try Domain match
		if (! empty($website)) {
			$host = parse_url($website, PHP_URL_HOST);
			if ($host) {
				$domain = str_replace('www.', '', strtolower($host));
				$businesses = get_posts(array(
					'post_type' => DDB_Spots_Business_Registry::POST_TYPE,
					'fields' => 'ids',
					'posts_per_page' => -1,
				));
				foreach ($businesses as $biz_id) {
					$biz_url = get_post_meta($biz_id, DDB_Spots_Business_Registry::META_WEBSITE, true);
						if ($biz_url) {
							$biz_host = parse_url($biz_url, PHP_URL_HOST);
							if ($biz_host && str_replace('www.', '', strtolower($biz_host)) === $domain) {
								self::sync_spot_business_meta($spot_id, (int) $biz_id);
								return (int) $biz_id;
							}
						}
					}
			}
		}

		return null;
	}

	public static function bootstrap_businesses_from_spots(array $options = array()): array {
		$options = array_merge(
			array(
				'dry_run' => true,
				'limit' => 0,
				'link_spots' => true,
				'set_plan_source' => true,
				'only_unlinked' => true,
				'business_status' => 'draft',
			),
			$options
		);
		$limit = max(0, (int) $options['limit']);
		$posts_per_page = $limit > 0 ? $limit : -1;
		$spot_ids = get_posts(
			array(
				'post_type' => self::SPOT_POST_TYPE,
				'post_status' => array('publish', 'draft', 'pending', 'private'),
				'posts_per_page' => $posts_per_page,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
				'no_found_rows' => true,
			)
		);

		$summary = array(
			'dry_run' => ! empty($options['dry_run']),
			'scanned' => 0,
			'created' => 0,
			'linked' => 0,
			'skipped_existing' => 0,
			'skipped_missing_identity' => 0,
			'rows' => array(),
		);

		$indexes = self::build_indexes();
		$new_business_keys = array();
		$preview_counter = 0;
		foreach ((array) $spot_ids as $spot_id_raw) {
			$spot_id = absint((int) $spot_id_raw);
			if ($spot_id <= 0) {
				continue;
			}
			$summary['scanned']++;

				$current_business_id = self::get_spot_business_id($spot_id);
				if (! empty($options['only_unlinked']) && $current_business_id > 0) {
					$summary['skipped_existing']++;
					continue;
				}

			$match = self::match_business_for_spot($spot_id, $indexes);
			if (! empty($match['business_id'])) {
				if (! empty($options['link_spots']) && $current_business_id <= 0) {
					$business_id = absint((int) $match['business_id']);
					$summary['linked']++;
					$summary['rows'][] = array(
						'spot_id' => $spot_id,
						'spot_title' => (string) get_the_title($spot_id),
						'business_id' => $business_id,
						'business_title' => (string) get_the_title($business_id),
						'action' => 'link_existing',
						'rule' => sanitize_key((string) ($match['rule'] ?? '')),
					);
					if (empty($options['dry_run'])) {
						self::link_spot_to_business($spot_id, $business_id, ! empty($options['set_plan_source']));
					}
				}
				continue;
			}

			$identity = self::spot_identity($spot_id);
			$key = (string) ($identity['key'] ?? '');
			if ('' === $key) {
				$summary['skipped_missing_identity']++;
				continue;
			}

			$business_id = isset($new_business_keys[ $key ]) ? absint((int) $new_business_keys[ $key ]) : 0;
			if ($business_id <= 0) {
				$summary['created']++;
				if (! empty($options['dry_run'])) {
					$preview_counter++;
					$business_id = -1 * $preview_counter;
				} else {
					$business_id = self::create_business_from_identity($identity, sanitize_key((string) $options['business_status']));
					if ($business_id <= 0) {
						continue;
					}
					$indexes = self::build_indexes();
				}
				$new_business_keys[ $key ] = $business_id;
			}

			if (! empty($options['link_spots']) && $current_business_id <= 0) {
				$summary['linked']++;
				if (! empty($options['dry_run'])) {
					$summary['rows'][] = array(
						'spot_id' => $spot_id,
						'spot_title' => (string) get_the_title($spot_id),
						'business_id' => $business_id,
						'business_title' => (string) ($identity['title'] ?? ''),
						'action' => 'create_and_link',
						'rule' => (string) ($identity['rule'] ?? ''),
					);
				} else {
					self::link_spot_to_business($spot_id, $business_id, ! empty($options['set_plan_source']));
					$summary['rows'][] = array(
						'spot_id' => $spot_id,
						'spot_title' => (string) get_the_title($spot_id),
						'business_id' => $business_id,
						'business_title' => (string) get_the_title($business_id),
						'action' => 'create_and_link',
						'rule' => (string) ($identity['rule'] ?? ''),
					);
				}
			}
		}

		if (! empty($summary['rows']) && count($summary['rows']) > 200) {
			$summary['rows'] = array_slice($summary['rows'], 0, 200);
		}
		return $summary;
	}

	public static function bulk_link_spots(array $options = array()): array {
		$options = array_merge(
			array(
				'dry_run' => true,
				'only_unlinked' => true,
				'set_plan_source' => true,
				'force_plan_source' => false,
				'limit' => 0,
			),
			$options
		);

		$indexes = self::build_indexes();
		$limit = max(0, (int) $options['limit']);
		$posts_per_page = $limit > 0 ? $limit : -1;
		$spot_ids = get_posts(
			array(
				'post_type' => self::SPOT_POST_TYPE,
				'post_status' => array('publish', 'draft', 'pending', 'private'),
				'posts_per_page' => $posts_per_page,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
				'no_found_rows' => true,
			)
		);

		$summary = array(
			'dry_run' => ! empty($options['dry_run']),
			'scanned' => 0,
			'linked' => 0,
			'skipped_existing' => 0,
			'unmatched' => 0,
			'rules' => array('place_id' => 0, 'domain' => 0, 'title_exact' => 0),
			'rows' => array(),
		);

		foreach ((array) $spot_ids as $spot_id_raw) {
			$spot_id = absint((int) $spot_id_raw);
			if ($spot_id <= 0) {
				continue;
			}
			$summary['scanned']++;

				$current_business_id = self::get_spot_business_id($spot_id);
				if (! empty($options['only_unlinked']) && $current_business_id > 0) {
					$summary['skipped_existing']++;
					continue;
				}

			$match = self::match_business_for_spot($spot_id, $indexes);
			if (empty($match['business_id'])) {
				$summary['unmatched']++;
				continue;
			}

			$business_id = absint((int) $match['business_id']);
			$rule = sanitize_key((string) ($match['rule'] ?? ''));
			if (isset($summary['rules'][ $rule ])) {
				$summary['rules'][ $rule ]++;
			}
			$summary['linked']++;

			$summary['rows'][] = array(
				'spot_id' => $spot_id,
				'spot_title' => (string) get_the_title($spot_id),
				'business_id' => $business_id,
				'business_title' => (string) get_the_title($business_id),
				'rule' => $rule,
				'confidence' => (float) ($match['confidence'] ?? 0.0),
			);

			if (! empty($options['dry_run'])) {
				continue;
			}

				self::sync_spot_business_meta($spot_id, $business_id);
				if (! empty($options['set_plan_source'])) {
					$current_source = sanitize_key((string) get_post_meta($spot_id, self::SPOT_META_PLAN_SOURCE, true));
					if (! empty($options['force_plan_source']) || $current_business_id <= 0 || '' === $current_source || 'business' === $current_source) {
						update_post_meta($spot_id, self::SPOT_META_PLAN_SOURCE, 'business');
				}
			}
		}

		if (! empty($summary['rows']) && count($summary['rows']) > 200) {
			$summary['rows'] = array_slice($summary['rows'], 0, 200);
		}
		return $summary;
	}

	public static function match_business_for_spot(int $spot_id, ?array $indexes = null): array {
		$spot_id = absint($spot_id);
		if ($spot_id <= 0) {
			return array();
		}
		if (null === $indexes) {
			$indexes = self::build_indexes();
		}

		$place_id = sanitize_text_field((string) get_post_meta($spot_id, self::SPOT_META_GOOGLE_PLACE_ID, true));
		$business_id = self::unique_match_id((array) ($indexes['by_place_id'] ?? array()), $place_id);
		if ($business_id > 0) {
			return array('business_id' => $business_id, 'rule' => 'place_id', 'confidence' => 1.0);
		}
		if ('' !== $place_id) {
			// If a spot has a place ID, do not fall back to domain/title matching.
			// This prevents false merges for portals that host many businesses.
			return array();
		}

		$domains = array();
		$google_website = (string) get_post_meta($spot_id, self::SPOT_META_GOOGLE_WEBSITE, true);
		$cta_url = (string) get_post_meta($spot_id, self::SPOT_META_CTA_URL, true);
		$domains[] = self::normalize_domain($google_website);
		$domains[] = self::normalize_domain($cta_url);
		$domains = array_values(array_unique(array_filter($domains)));
		foreach ($domains as $domain) {
			$business_id = self::unique_match_id((array) ($indexes['by_domain'] ?? array()), $domain);
			if ($business_id > 0) {
				return array('business_id' => $business_id, 'rule' => 'domain', 'confidence' => 0.9);
			}
		}

		$title_key = sanitize_title((string) get_the_title($spot_id));
		$business_id = self::unique_match_id((array) ($indexes['by_title'] ?? array()), $title_key);
		if ($business_id > 0) {
			return array('business_id' => $business_id, 'rule' => 'title_exact', 'confidence' => 0.7);
		}

		return array();
	}

	public static function reconcile_place_id_links(array $options = array()): array {
		$options = array_merge(
			array(
				'dry_run' => true,
				'limit' => 0,
				'set_plan_source' => true,
			),
			$options
		);
		$limit = max(0, (int) $options['limit']);
		$posts_per_page = $limit > 0 ? $limit : -1;
		$spot_ids = get_posts(
			array(
				'post_type' => self::SPOT_POST_TYPE,
				'post_status' => array('publish', 'draft', 'pending', 'private'),
				'posts_per_page' => $posts_per_page,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
				'no_found_rows' => true,
			)
		);

		$summary = array(
			'dry_run' => ! empty($options['dry_run']),
			'scanned' => 0,
			'mismatched' => 0,
			'fixed' => 0,
			'rows' => array(),
		);
		$indexes = self::build_indexes();

		foreach ((array) $spot_ids as $spot_id_raw) {
			$spot_id = absint((int) $spot_id_raw);
			if ($spot_id <= 0) {
				continue;
			}
			$summary['scanned']++;

				$current_business_id = self::get_spot_business_id($spot_id);
				if ($current_business_id <= 0) {
					continue;
				}
			$spot_place_id = sanitize_text_field((string) get_post_meta($spot_id, self::SPOT_META_GOOGLE_PLACE_ID, true));
			if ('' === $spot_place_id) {
				continue;
			}
			$current_business_place_id = sanitize_text_field((string) get_post_meta($current_business_id, DDB_Spots_Business_Registry::META_GOOGLE_PLACE_ID, true));
			if ('' === $current_business_place_id || $spot_place_id === $current_business_place_id) {
				continue;
			}

			$summary['mismatched']++;
			$new_business_id = self::unique_match_id((array) ($indexes['by_place_id'] ?? array()), $spot_place_id);
			$action = 'relink_existing';
			if ($new_business_id <= 0) {
				$identity = self::spot_identity($spot_id);
				if ('' === (string) ($identity['key'] ?? '')) {
					continue;
				}
				if (! empty($options['dry_run'])) {
					$new_business_id = -1;
					$action = 'create_and_relink';
				} else {
					$new_business_id = self::create_business_from_identity($identity, 'draft');
					if ($new_business_id <= 0) {
						continue;
					}
					$indexes = self::build_indexes();
					$action = 'create_and_relink';
				}
			}

			$summary['fixed']++;
			$summary['rows'][] = array(
				'spot_id' => $spot_id,
				'spot_title' => (string) get_the_title($spot_id),
				'from_business_id' => $current_business_id,
				'from_business_title' => (string) get_the_title($current_business_id),
				'to_business_id' => $new_business_id,
				'to_business_title' => $new_business_id > 0 ? (string) get_the_title($new_business_id) : (string) get_the_title($spot_id),
				'action' => $action,
			);

			if (empty($options['dry_run'])) {
				self::link_spot_to_business($spot_id, $new_business_id, ! empty($options['set_plan_source']));
			}
		}

		if (! empty($summary['rows']) && count($summary['rows']) > 200) {
			$summary['rows'] = array_slice($summary['rows'], 0, 200);
		}
		return $summary;
	}

	public static function build_indexes(): array {
		$ids = get_posts(
			array(
				'post_type' => DDB_Spots_Business_Registry::POST_TYPE,
				'post_status' => array('publish', 'draft', 'pending', 'private'),
				'posts_per_page' => -1,
				'fields' => 'ids',
				'orderby' => 'title',
				'order' => 'ASC',
				'no_found_rows' => true,
			)
		);

		$index = array(
			'by_place_id' => array(),
			'by_domain' => array(),
			'by_title' => array(),
		);

		foreach ((array) $ids as $business_id_raw) {
			$business_id = absint((int) $business_id_raw);
			if (! DDB_Spots_Business_Registry::is_valid_business_id($business_id)) {
				continue;
			}

			$place_id = sanitize_text_field((string) get_post_meta($business_id, DDB_Spots_Business_Registry::META_GOOGLE_PLACE_ID, true));
			if ('' !== $place_id) {
				self::append_index($index['by_place_id'], $place_id, $business_id);
			}

			$website = (string) get_post_meta($business_id, DDB_Spots_Business_Registry::META_WEBSITE, true);
			$domain = self::normalize_domain($website);
			if ('' !== $domain) {
				self::append_index($index['by_domain'], $domain, $business_id);
			}

			$title_key = sanitize_title((string) get_the_title($business_id));
			if ('' !== $title_key) {
				self::append_index($index['by_title'], $title_key, $business_id);
			}
		}

		return $index;
	}

	public static function normalize_domain(string $url_or_host): string {
		$url_or_host = trim((string) sanitize_text_field($url_or_host));
		if ('' === $url_or_host) {
			return '';
		}

		$candidate = $url_or_host;
		if (false === strpos($candidate, '://')) {
			$candidate = 'https://' . ltrim($candidate, '/');
		}
		$parts = wp_parse_url($candidate);
		$host = is_array($parts) && isset($parts['host']) ? strtolower((string) $parts['host']) : '';
		$host = trim($host, '.');
		if (str_starts_with($host, 'www.')) {
			$host = substr($host, 4);
		}
		return $host;
	}

	private static function append_index(array &$bucket, string $key, int $business_id): void {
		if ('' === $key || $business_id <= 0) {
			return;
		}
		if (! isset($bucket[ $key ]) || ! is_array($bucket[ $key ])) {
			$bucket[ $key ] = array();
		}
		if (! in_array($business_id, $bucket[ $key ], true)) {
			$bucket[ $key ][] = $business_id;
		}
	}

	private static function unique_match_id(array $index, string $key): int {
		$key = trim($key);
		if ('' === $key || ! isset($index[ $key ]) || ! is_array($index[ $key ])) {
			return 0;
		}
		$candidates = array_values(array_unique(array_filter(array_map('absint', $index[ $key ]))));
		return 1 === count($candidates) ? (int) $candidates[0] : 0;
	}

	private static function spot_identity(int $spot_id): array {
		$title = trim((string) get_the_title($spot_id));
		$title_key = sanitize_title($title);
		$place_id = sanitize_text_field((string) get_post_meta($spot_id, self::SPOT_META_GOOGLE_PLACE_ID, true));
		$google_website = (string) get_post_meta($spot_id, self::SPOT_META_GOOGLE_WEBSITE, true);
		$cta_url = (string) get_post_meta($spot_id, self::SPOT_META_CTA_URL, true);
		$website = '' !== trim($google_website) ? $google_website : $cta_url;
		$domain = self::normalize_domain($website);

		if ('' !== $place_id) {
			return array(
				'key' => 'place_id:' . $place_id,
				'rule' => 'place_id',
				'place_id' => $place_id,
				'website' => $website,
				'domain' => $domain,
				'title' => '' !== $title ? $title : ('Spot #' . $spot_id),
			);
		}
		if ('' !== $domain) {
			return array(
				'key' => 'domain:' . $domain,
				'rule' => 'domain',
				'place_id' => '',
				'website' => $website,
				'domain' => $domain,
				'title' => '' !== $title ? $title : ('Spot #' . $spot_id),
			);
		}
		if ('' !== $title_key) {
			return array(
				'key' => 'title:' . $title_key,
				'rule' => 'title_exact',
				'place_id' => '',
				'website' => '',
				'domain' => '',
				'title' => '' !== $title ? $title : ('Spot #' . $spot_id),
			);
		}

		return array();
	}

	private static function create_business_from_identity(array $identity, string $status): int {
		$status = in_array($status, array('publish', 'draft', 'pending', 'private'), true) ? $status : 'draft';
		$title = sanitize_text_field((string) ($identity['title'] ?? ''));
		if ('' === $title) {
			$title = __('Business', 'ddb-spots');
		}

		$business_id = wp_insert_post(
			array(
				'post_type' => DDB_Spots_Business_Registry::POST_TYPE,
				'post_title' => $title,
				'post_status' => $status,
			),
			true
		);
		if (is_wp_error($business_id)) {
			return 0;
		}
		$business_id = absint((int) $business_id);
		if ($business_id <= 0) {
			return 0;
		}

		update_post_meta($business_id, DDB_Spots_Business_Registry::META_PLAN_KEY, 'free');
		update_post_meta($business_id, DDB_Spots_Business_Registry::META_STATUS, 'inactive');
		update_post_meta($business_id, DDB_Spots_Business_Registry::META_PERIOD_END, '');

		$place_id = sanitize_text_field((string) ($identity['place_id'] ?? ''));
		if ('' !== $place_id) {
			update_post_meta($business_id, DDB_Spots_Business_Registry::META_GOOGLE_PLACE_ID, $place_id);
		}
		$website = esc_url_raw((string) ($identity['website'] ?? ''));
		if ('' !== $website) {
			update_post_meta($business_id, DDB_Spots_Business_Registry::META_WEBSITE, $website);
		}

		return $business_id;
	}

	private static function link_spot_to_business(int $spot_id, int $business_id, bool $set_plan_source): void {
		if ($spot_id <= 0 || $business_id <= 0) {
			return;
		}
		self::sync_spot_business_meta($spot_id, $business_id);
		if ($set_plan_source) {
			update_post_meta($spot_id, self::SPOT_META_PLAN_SOURCE, 'business');
		}
	}

	private static function get_spot_business_id(int $spot_id): int {
		$spot_id = absint($spot_id);
		if ($spot_id <= 0) {
			return 0;
		}

		$business_id = absint((int) get_post_meta($spot_id, self::SPOT_META_BUSINESS_ID, true));
		if ($business_id > 0) {
			return $business_id;
		}

		$legacy_meta_key = (string) (DDB_Spots_Core_Schema::META['parent_business_id'] ?? '');
		if ('' === $legacy_meta_key) {
			return 0;
		}

		$legacy_business_id = absint((int) get_post_meta($spot_id, $legacy_meta_key, true));
		if ($legacy_business_id > 0) {
			update_post_meta($spot_id, self::SPOT_META_BUSINESS_ID, $legacy_business_id);
		}
		return $legacy_business_id;
	}

	private static function sync_spot_business_meta(int $spot_id, int $business_id): void {
		$spot_id = absint($spot_id);
		if ($spot_id <= 0) {
			return;
		}
		$business_id = absint($business_id);
		$legacy_meta_key = (string) (DDB_Spots_Core_Schema::META['parent_business_id'] ?? '');

		if ($business_id > 0) {
			update_post_meta($spot_id, self::SPOT_META_BUSINESS_ID, $business_id);
			if ('' !== $legacy_meta_key) {
				update_post_meta($spot_id, $legacy_meta_key, $business_id);
			}
			return;
		}

		delete_post_meta($spot_id, self::SPOT_META_BUSINESS_ID);
		if ('' !== $legacy_meta_key) {
			delete_post_meta($spot_id, $legacy_meta_key);
		}
	}
}
