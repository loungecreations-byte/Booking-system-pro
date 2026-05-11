<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Admin_Settings_Page {
	public const OPTION_KEY = 'ddb_spots_engine_config';
	private const PAGE_SLUG = 'ddb-spots-engine-settings';

	public function init(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
	}

	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=ddb_spot',
			__('Settings (Engine)', 'ddb-spots'),
			__('Settings (Engine)', 'ddb-spots'),
			DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE,
			self::PAGE_SLUG,
			array($this, 'render_page')
		);
	}

	public function enqueue_assets(string $hook): void {
		if ('ddb_spot_page_' . self::PAGE_SLUG !== $hook) {
			return;
		}
		wp_enqueue_style(
			'ddb-spots-admin',
			DDB_SPOTS_URL . 'assets/css/ddb-spots-admin.css',
			array(),
			DDB_SPOTS_VERSION
		);
		wp_enqueue_script(
			'ddb-spots-admin',
			DDB_SPOTS_URL . 'assets/js/ddb-spots-admin.js',
			array(),
			DDB_SPOTS_VERSION,
			true
		);
	}

	public static function defaults(): array {
		return array(
			'spot_types' => array(
				'restaurant' => array('enabled' => true, 'required_fields' => array('excerpt', 'location', 'booking')),
				'hotel' => array('enabled' => true, 'required_fields' => array('excerpt', 'location', 'booking')),
				'museum' => array('enabled' => true, 'required_fields' => array('excerpt', 'location')),
				'event' => array('enabled' => true, 'required_fields' => array('excerpt', 'location', 'booking')),
				'attraction' => array('enabled' => true, 'required_fields' => array('excerpt', 'location')),
				'activity' => array('enabled' => true, 'required_fields' => array('excerpt', 'location')),
			),
			'booking_monetization' => array(
				'allowed_providers' => array('none', 'formitable', 'external', 'ticket'),
				'default_commission_model' => 'internal',
				'featured_boost' => 15,
				'premium_boost' => 25,
			),
			'ranking_visibility' => array(
				'weights' => array(
					'distance' => 30,
					'availability' => 25,
					'premium' => 25,
					'manual_priority' => 20,
					'popularity' => 25,
					'margin' => 20,
					'priority' => 25,
				),
				'sorting_defaults' => array(
					'restaurant' => 'relevance',
					'hotel' => 'relevance',
					'museum' => 'relevance',
					'event' => 'date',
					'attraction' => 'relevance',
					'activity' => 'relevance',
				),
			),
			'data_sources' => array(
				'google_api_key' => '',
				'default_city' => 'Den Bosch',
				'default_query' => "restaurants in 's-Hertogenbosch",
				'default_radius' => 5000,
				'deep_import_queries' => "restaurants in {city}\nthings to do in {city}\nbars in {city}\ncafes in {city}\nmuseums in {city}\nhotels in {city}\nattractions in {city}",
				'deep_import_radius' => 5000,
				'deep_import_max_places' => 180,
				'sync_frequency' => 'daily',
				'dedup_strategy' => 'place_id',
				'import_editorial_summary' => true,
				'import_reviews' => true,
				'import_quality_signals' => true,
				'import_wp_media' => false,
				'import_wp_media_max_photos' => 4,
			),
			'ux_rules' => array(
				'min_gallery_count' => 3,
				'min_excerpt_length' => 140,
				'hero_image_required' => true,
				'max_tags' => 8,
				'max_categories' => 3,
				'cta_required' => true,
				'allow_informational_only' => true,
				'block_publish_on_critical' => true,
			),
			'integrations' => array(
				'plan_je_dag_enabled' => false,
				'ai_agent_enabled' => false,
				'legacy_rest_enabled' => false,
				'legacy_rest_sunset_date' => '2026-06-30',
				'public_ingest_enabled' => false,
				'ingestion_shared_key' => '',
				'public_events_per_minute' => 90,
				'public_suggest_per_minute' => 45,
			),
		);
	}

	public static function get_config(): array {
		$saved = get_option(self::OPTION_KEY, array());
		if (! is_array($saved)) {
			$saved = array();
		}
		$config = array_replace_recursive(self::defaults(), $saved);
		$config['ux_rules']['min_excerpt_length'] = max(140, (int) ($config['ux_rules']['min_excerpt_length'] ?? 140));
		return $config;
	}

	public static function get_value(string $path, $default = null) {
		$config = self::get_config();
		$parts = explode('.', $path);
		$current = $config;
		foreach ($parts as $part) {
			if (! is_array($current) || ! array_key_exists($part, $current)) {
				return $default;
			}
			$current = $current[ $part ];
		}
		return $current;
	}

	public function render_page(): void {
		if (! current_user_can(DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE)) {
			wp_die(esc_html__('Insufficient permissions.', 'ddb-spots'));
		}

		$notice = '';
		$notice_type = 'success';
		if (isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD']) {
			check_admin_referer('ddb_spots_engine_settings');
			$config = $this->sanitize_config($_POST);
			update_option(self::OPTION_KEY, $config, false);
			$notice = __('Engine settings opgeslagen.', 'ddb-spots');
		}

		$config = self::get_config();
		$tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'spot-types';
		$tabs = array(
			'spot-types' => __('Spot Types', 'ddb-spots'),
			'booking-monetization' => __('Booking & Monetization', 'ddb-spots'),
			'ranking-visibility' => __('Ranking & Visibility', 'ddb-spots'),
			'data-sources' => __('Data Sources', 'ddb-spots'),
			'ux-rules' => __('UX Rules', 'ddb-spots'),
			'integrations' => __('Integrations', 'ddb-spots'),
		);
		if (! isset($tabs[ $tab ])) {
			$tab = 'spot-types';
		}

		echo '<div class="wrap ddb-admin-ui ddb-admin-ui-wrap">';
		echo '<h1>' . esc_html__('DDB Spots Settings (Engine)', 'ddb-spots') . '</h1>';
		if ('' !== $notice) {
			echo '<div class="notice notice-' . esc_attr($notice_type) . ' is-dismissible"><p>' . esc_html($notice) . '</p></div>';
		}
		echo '<h2 class="nav-tab-wrapper">';
		foreach ($tabs as $slug => $label) {
			$url = add_query_arg(
				array(
					'post_type' => 'ddb_spot',
					'page' => self::PAGE_SLUG,
					'tab' => $slug,
				),
				admin_url('edit.php')
			);
			$class = 'nav-tab' . ($slug === $tab ? ' nav-tab-active' : '');
			echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
		}
		echo '</h2>';

		echo '<form method="post">';
		wp_nonce_field('ddb_spots_engine_settings');
		echo '<input type="hidden" name="ddb_active_tab" value="' . esc_attr($tab) . '" />';
		switch ($tab) {
			case 'spot-types':
				$this->render_tab_spot_types($config);
				break;
			case 'booking-monetization':
				$this->render_tab_booking($config);
				break;
			case 'ranking-visibility':
				$this->render_tab_ranking($config);
				break;
			case 'data-sources':
				$this->render_tab_data_sources($config);
				break;
			case 'ux-rules':
				$this->render_tab_ux($config);
				break;
			case 'integrations':
				$this->render_tab_integrations($config);
				break;
		}
		submit_button(__('Save Engine Settings', 'ddb-spots'));
		echo '</form></div>';
	}

	private function render_tab_spot_types(array $config): void {
		$types = array('restaurant', 'hotel', 'museum', 'event', 'attraction', 'activity');
		echo '<table class="form-table"><tbody>';
		foreach ($types as $type) {
			$enabled = ! empty($config['spot_types'][ $type ]['enabled']);
			$required = $config['spot_types'][ $type ]['required_fields'] ?? array();
			echo '<tr><th>' . esc_html(ucfirst($type)) . '</th><td>';
			echo '<label><input type="checkbox" name="spot_types[' . esc_attr($type) . '][enabled]" value="1" ' . checked($enabled, true, false) . ' /> ' . esc_html__('Enabled', 'ddb-spots') . '</label>';
			echo '<p><label>' . esc_html__('Required fields (comma separated)', 'ddb-spots') . '<br /><input class="regular-text" type="text" name="spot_types[' . esc_attr($type) . '][required_fields]" value="' . esc_attr(is_array($required) ? implode(',', $required) : '') . '" /></label></p>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_tab_booking(array $config): void {
		$providers = $config['booking_monetization']['allowed_providers'] ?? array();
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__('Allowed providers', 'ddb-spots') . '</th><td><input class="regular-text" type="text" name="booking_monetization[allowed_providers]" value="' . esc_attr(implode(',', (array) $providers)) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Default commission model', 'ddb-spots') . '</th><td><input class="regular-text" type="text" name="booking_monetization[default_commission_model]" value="' . esc_attr((string) $config['booking_monetization']['default_commission_model']) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Featured boost', 'ddb-spots') . '</th><td><input class="small-text" type="number" min="0" max="100" name="booking_monetization[featured_boost]" value="' . esc_attr((string) $config['booking_monetization']['featured_boost']) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Premium boost', 'ddb-spots') . '</th><td><input class="small-text" type="number" min="0" max="100" name="booking_monetization[premium_boost]" value="' . esc_attr((string) $config['booking_monetization']['premium_boost']) . '" /></td></tr>';
		echo '</tbody></table>';
	}

	private function render_tab_ranking(array $config): void {
		$weights = $config['ranking_visibility']['weights'] ?? array();
		echo '<table class="form-table"><tbody>';
		foreach (array('distance', 'availability', 'premium', 'manual_priority', 'popularity', 'margin', 'priority') as $weight) {
			echo '<tr><th>' . esc_html(ucwords(str_replace('_', ' ', $weight))) . '</th><td><input class="small-text" type="number" min="0" max="100" name="ranking_visibility[weights][' . esc_attr($weight) . ']" value="' . esc_attr((string) ($weights[ $weight ] ?? 0)) . '" /></td></tr>';
		}
		$sorting = $config['ranking_visibility']['sorting_defaults'] ?? array();
		foreach (array('restaurant', 'hotel', 'museum', 'event', 'attraction', 'activity') as $type) {
			echo '<tr><th>' . esc_html(sprintf(__('Default sorting (%s)', 'ddb-spots'), $type)) . '</th><td><input class="regular-text" type="text" name="ranking_visibility[sorting_defaults][' . esc_attr($type) . ']" value="' . esc_attr((string) ($sorting[ $type ] ?? 'relevance')) . '" /></td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_tab_data_sources(array $config): void {
		$data = $config['data_sources'];
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__('Google Places API key', 'ddb-spots') . '</th><td><input class="regular-text" type="text" id="ddb_google_api_key" name="data_sources[google_api_key]" value="' . esc_attr((string) $data['google_api_key']) . '" autocomplete="off" spellcheck="false" placeholder="AIza..." /><p class="description">' . esc_html__('Gebruik je Google API key (meestal start met AIza).', 'ddb-spots') . '</p></td></tr>';
		echo '<tr><th>' . esc_html__('Default city', 'ddb-spots') . '</th><td><input class="regular-text" type="text" name="data_sources[default_city]" value="' . esc_attr((string) $data['default_city']) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Default query', 'ddb-spots') . '</th><td><input class="regular-text" type="text" name="data_sources[default_query]" value="' . esc_attr((string) $data['default_query']) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Default radius (m)', 'ddb-spots') . '</th><td><input class="small-text" type="number" min="100" step="100" name="data_sources[default_radius]" value="' . esc_attr((string) $data['default_radius']) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Deep import queries', 'ddb-spots') . '</th><td><textarea class="large-text code" rows="6" name="data_sources[deep_import_queries]" placeholder="restaurants in {city}">' . esc_textarea((string) ($data['deep_import_queries'] ?? '')) . '</textarea><p class="description">' . esc_html__('One query per line. Use {city} placeholder.', 'ddb-spots') . '</p></td></tr>';
		echo '<tr><th>' . esc_html__('Deep import radius (m)', 'ddb-spots') . '</th><td><input class="small-text" type="number" min="500" step="100" name="data_sources[deep_import_radius]" value="' . esc_attr((string) ($data['deep_import_radius'] ?? 5000)) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Deep import max places', 'ddb-spots') . '</th><td><input class="small-text" type="number" min="20" max="500" name="data_sources[deep_import_max_places]" value="' . esc_attr((string) ($data['deep_import_max_places'] ?? 180)) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Sync frequency', 'ddb-spots') . '</th><td><select name="data_sources[sync_frequency]"><option value="daily" ' . selected('daily', (string) $data['sync_frequency'], false) . '>daily</option><option value="every_3_days" ' . selected('every_3_days', (string) $data['sync_frequency'], false) . '>every 3 days</option></select></td></tr>';
		echo '<tr><th>' . esc_html__('Import editorial summary', 'ddb-spots') . '</th><td><label><input type="checkbox" name="data_sources[import_editorial_summary]" value="1" ' . checked(! empty($data['import_editorial_summary']), true, false) . ' /> ' . esc_html__('Use Google editorial text as excerpt fallback', 'ddb-spots') . '</label></td></tr>';
		echo '<tr><th>' . esc_html__('Import reviews', 'ddb-spots') . '</th><td><label><input type="checkbox" name="data_sources[import_reviews]" value="1" ' . checked(! empty($data['import_reviews']), true, false) . ' /> ' . esc_html__('Store sanitized top reviews JSON', 'ddb-spots') . '</label></td></tr>';
		echo '<tr><th>' . esc_html__('Import rating/price/status', 'ddb-spots') . '</th><td><label><input type="checkbox" name="data_sources[import_quality_signals]" value="1" ' . checked(! empty($data['import_quality_signals']), true, false) . ' /> ' . esc_html__('Store rating, user_ratings_total, price_level and business_status', 'ddb-spots') . '</label></td></tr>';
		echo '<tr><th>' . esc_html__('Import photos into WP Media', 'ddb-spots') . '</th><td><label><input type="checkbox" name="data_sources[import_wp_media]" value="1" ' . checked(! empty($data['import_wp_media']), true, false) . ' /> ' . esc_html__('Download Place photos and attach as featured/gallery', 'ddb-spots') . '</label></td></tr>';
		echo '<tr><th>' . esc_html__('Max imported photos', 'ddb-spots') . '</th><td><input class="small-text" type="number" min="1" max="10" name="data_sources[import_wp_media_max_photos]" value="' . esc_attr((string) ($data['import_wp_media_max_photos'] ?? 4)) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Dedup strategy', 'ddb-spots') . '</th><td><input class="regular-text" type="text" name="data_sources[dedup_strategy]" value="' . esc_attr((string) $data['dedup_strategy']) . '" /></td></tr>';
		echo '</tbody></table>';
	}

	private function render_tab_ux(array $config): void {
		$ux = $config['ux_rules'];
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__('Minimum gallery count', 'ddb-spots') . '</th><td><input class="small-text" type="number" min="0" name="ux_rules[min_gallery_count]" value="' . esc_attr((string) $ux['min_gallery_count']) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Minimum excerpt length (chars)', 'ddb-spots') . '</th><td><input class="small-text" type="number" min="140" name="ux_rules[min_excerpt_length]" value="' . esc_attr((string) $ux['min_excerpt_length']) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Hero image required', 'ddb-spots') . '</th><td><label><input type="checkbox" name="ux_rules[hero_image_required]" value="1" ' . checked(! empty($ux['hero_image_required']), true, false) . ' /> ' . esc_html__('Required', 'ddb-spots') . '</label></td></tr>';
		echo '<tr><th>' . esc_html__('CTA required', 'ddb-spots') . '</th><td><label><input type="checkbox" name="ux_rules[cta_required]" value="1" ' . checked(! empty($ux['cta_required']), true, false) . ' /> ' . esc_html__('Required for publish', 'ddb-spots') . '</label></td></tr>';
		echo '<tr><th>' . esc_html__('Allow informational only', 'ddb-spots') . '</th><td><label><input type="checkbox" name="ux_rules[allow_informational_only]" value="1" ' . checked(! empty($ux['allow_informational_only']), true, false) . ' /> ' . esc_html__('Allow spots without CTA', 'ddb-spots') . '</label></td></tr>';
		echo '<tr><th>' . esc_html__('Max tags', 'ddb-spots') . '</th><td><input class="small-text" type="number" min="0" name="ux_rules[max_tags]" value="' . esc_attr((string) $ux['max_tags']) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Max categories', 'ddb-spots') . '</th><td><input class="small-text" type="number" min="0" name="ux_rules[max_categories]" value="' . esc_attr((string) $ux['max_categories']) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Block publish on critical', 'ddb-spots') . '</th><td><label><input type="checkbox" name="ux_rules[block_publish_on_critical]" value="1" ' . checked(! empty($ux['block_publish_on_critical']), true, false) . ' /> ' . esc_html__('Enabled', 'ddb-spots') . '</label></td></tr>';
		echo '</tbody></table>';
	}

	private function render_tab_integrations(array $config): void {
		$int = $config['integrations'];
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__('Plan-je-Dag endpoint enabled', 'ddb-spots') . '</th><td><label><input type="checkbox" name="integrations[plan_je_dag_enabled]" value="1" ' . checked(! empty($int['plan_je_dag_enabled']), true, false) . ' /> ' . esc_html__('Enabled', 'ddb-spots') . '</label></td></tr>';
		echo '<tr><th>' . esc_html__('AI agent endpoint enabled', 'ddb-spots') . '</th><td><label><input type="checkbox" name="integrations[ai_agent_enabled]" value="1" ' . checked(! empty($int['ai_agent_enabled']), true, false) . ' /> ' . esc_html__('Enabled', 'ddb-spots') . '</label></td></tr>';
		echo '<tr><th>' . esc_html__('Legacy ddb/v1 endpoints', 'ddb-spots') . '</th><td><label><input type="checkbox" name="integrations[legacy_rest_enabled]" value="1" ' . checked(! empty($int['legacy_rest_enabled']), true, false) . ' /> ' . esc_html__('Enabled (deprecated)', 'ddb-spots') . '</label></td></tr>';
		echo '<tr><th>' . esc_html__('Legacy REST sunset date (UTC)', 'ddb-spots') . '</th><td><input class="regular-text" type="date" name="integrations[legacy_rest_sunset_date]" value="' . esc_attr((string) ($int['legacy_rest_sunset_date'] ?? '')) . '" /><p class="description">' . esc_html__('Communicated via deprecation headers on /ddb/v1.', 'ddb-spots') . '</p></td></tr>';
		echo '<tr><th>' . esc_html__('Public ingestion endpoints', 'ddb-spots') . '</th><td><label><input type="checkbox" name="integrations[public_ingest_enabled]" value="1" ' . checked(! empty($int['public_ingest_enabled']), true, false) . ' /> ' . esc_html__('Enabled (/events and /suggest)', 'ddb-spots') . '</label></td></tr>';
		echo '<tr><th>' . esc_html__('Public ingestion shared key', 'ddb-spots') . '</th><td><input class="regular-text" type="password" name="integrations[ingestion_shared_key]" value="' . esc_attr((string) ($int['ingestion_shared_key'] ?? '')) . '" autocomplete="off" /><p class="description">' . esc_html__('Required when public ingestion is enabled. Send via X-DDB-Ingest-Key header.', 'ddb-spots') . '</p></td></tr>';
		echo '<tr><th>' . esc_html__('Event rate limit / minute', 'ddb-spots') . '</th><td><input class="small-text" type="number" min="10" max="1000" name="integrations[public_events_per_minute]" value="' . esc_attr((string) ($int['public_events_per_minute'] ?? 90)) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Suggest rate limit / minute', 'ddb-spots') . '</th><td><input class="small-text" type="number" min="5" max="1000" name="integrations[public_suggest_per_minute]" value="' . esc_attr((string) ($int['public_suggest_per_minute'] ?? 45)) . '" /></td></tr>';
		echo '</tbody></table>';
	}

	private function sanitize_config(array $input): array {
		$defaults = self::defaults();
		$config = $defaults;

		$types = isset($input['spot_types']) && is_array($input['spot_types']) ? $input['spot_types'] : array();
		foreach ($defaults['spot_types'] as $type => $type_defaults) {
			$row = isset($types[ $type ]) && is_array($types[ $type ]) ? $types[ $type ] : array();
			$config['spot_types'][ $type ]['enabled'] = isset($row['enabled']);
			$required_raw = isset($row['required_fields']) ? sanitize_text_field(wp_unslash((string) $row['required_fields'])) : '';
			$config['spot_types'][ $type ]['required_fields'] = array_values(array_filter(array_map('sanitize_key', array_map('trim', explode(',', $required_raw)))));
		}

		$booking = isset($input['booking_monetization']) && is_array($input['booking_monetization']) ? $input['booking_monetization'] : array();
		$providers_raw = isset($booking['allowed_providers']) ? sanitize_text_field(wp_unslash((string) $booking['allowed_providers'])) : '';
		$config['booking_monetization']['allowed_providers'] = array_values(array_filter(array_map('sanitize_key', array_map('trim', explode(',', $providers_raw)))));
		$config['booking_monetization']['default_commission_model'] = isset($booking['default_commission_model']) ? sanitize_text_field(wp_unslash((string) $booking['default_commission_model'])) : $defaults['booking_monetization']['default_commission_model'];
		$config['booking_monetization']['featured_boost'] = isset($booking['featured_boost']) ? max(0, min(100, (int) $booking['featured_boost'])) : $defaults['booking_monetization']['featured_boost'];
		$config['booking_monetization']['premium_boost'] = isset($booking['premium_boost']) ? max(0, min(100, (int) $booking['premium_boost'])) : $defaults['booking_monetization']['premium_boost'];

		$ranking = isset($input['ranking_visibility']) && is_array($input['ranking_visibility']) ? $input['ranking_visibility'] : array();
		$weights = isset($ranking['weights']) && is_array($ranking['weights']) ? $ranking['weights'] : array();
		foreach ($defaults['ranking_visibility']['weights'] as $k => $v) {
			$config['ranking_visibility']['weights'][ $k ] = isset($weights[ $k ]) ? max(0, min(100, (int) $weights[ $k ])) : $v;
		}
		$sorting = isset($ranking['sorting_defaults']) && is_array($ranking['sorting_defaults']) ? $ranking['sorting_defaults'] : array();
		foreach ($defaults['ranking_visibility']['sorting_defaults'] as $k => $v) {
			$config['ranking_visibility']['sorting_defaults'][ $k ] = isset($sorting[ $k ]) ? sanitize_key((string) $sorting[ $k ]) : $v;
		}

		$data = isset($input['data_sources']) && is_array($input['data_sources']) ? $input['data_sources'] : array();
		$config['data_sources']['google_api_key'] = isset($data['google_api_key']) ? sanitize_text_field(wp_unslash((string) $data['google_api_key'])) : '';
		$config['data_sources']['default_city'] = isset($data['default_city']) ? sanitize_text_field(wp_unslash((string) $data['default_city'])) : $defaults['data_sources']['default_city'];
		$config['data_sources']['default_query'] = isset($data['default_query']) ? sanitize_text_field(wp_unslash((string) $data['default_query'])) : $defaults['data_sources']['default_query'];
		$config['data_sources']['default_radius'] = isset($data['default_radius']) ? max(100, (int) $data['default_radius']) : $defaults['data_sources']['default_radius'];
		$config['data_sources']['deep_import_queries'] = isset($data['deep_import_queries']) ? sanitize_textarea_field(wp_unslash((string) $data['deep_import_queries'])) : (string) $defaults['data_sources']['deep_import_queries'];
		$config['data_sources']['deep_import_radius'] = isset($data['deep_import_radius']) ? max(500, (int) $data['deep_import_radius']) : (int) $defaults['data_sources']['deep_import_radius'];
		$config['data_sources']['deep_import_max_places'] = isset($data['deep_import_max_places']) ? max(20, min(500, (int) $data['deep_import_max_places'])) : (int) $defaults['data_sources']['deep_import_max_places'];
		$freq = isset($data['sync_frequency']) ? sanitize_key((string) $data['sync_frequency']) : $defaults['data_sources']['sync_frequency'];
		$config['data_sources']['sync_frequency'] = in_array($freq, array('daily', 'every_3_days'), true) ? $freq : 'daily';
		$config['data_sources']['import_editorial_summary'] = isset($data['import_editorial_summary']);
		$config['data_sources']['import_reviews'] = isset($data['import_reviews']);
		$config['data_sources']['import_quality_signals'] = isset($data['import_quality_signals']);
		$config['data_sources']['import_wp_media'] = isset($data['import_wp_media']);
		$config['data_sources']['import_wp_media_max_photos'] = isset($data['import_wp_media_max_photos']) ? max(1, min(10, (int) $data['import_wp_media_max_photos'])) : (int) $defaults['data_sources']['import_wp_media_max_photos'];
		$config['data_sources']['dedup_strategy'] = 'place_id';

		$ux = isset($input['ux_rules']) && is_array($input['ux_rules']) ? $input['ux_rules'] : array();
		$config['ux_rules']['min_gallery_count'] = isset($ux['min_gallery_count']) ? max(0, (int) $ux['min_gallery_count']) : $defaults['ux_rules']['min_gallery_count'];
		$config['ux_rules']['min_excerpt_length'] = isset($ux['min_excerpt_length']) ? max(140, (int) $ux['min_excerpt_length']) : $defaults['ux_rules']['min_excerpt_length'];
		$config['ux_rules']['hero_image_required'] = isset($ux['hero_image_required']);
		$config['ux_rules']['cta_required'] = isset($ux['cta_required']);
		$config['ux_rules']['allow_informational_only'] = isset($ux['allow_informational_only']);
		$config['ux_rules']['max_tags'] = isset($ux['max_tags']) ? max(0, (int) $ux['max_tags']) : $defaults['ux_rules']['max_tags'];
		$config['ux_rules']['max_categories'] = isset($ux['max_categories']) ? max(0, (int) $ux['max_categories']) : $defaults['ux_rules']['max_categories'];
		$config['ux_rules']['block_publish_on_critical'] = isset($ux['block_publish_on_critical']);

		$integrations = isset($input['integrations']) && is_array($input['integrations']) ? $input['integrations'] : array();
		$config['integrations']['plan_je_dag_enabled'] = isset($integrations['plan_je_dag_enabled']);
		$config['integrations']['ai_agent_enabled'] = isset($integrations['ai_agent_enabled']);
		$config['integrations']['legacy_rest_enabled'] = isset($integrations['legacy_rest_enabled']);
		$sunset_date = isset($integrations['legacy_rest_sunset_date']) ? sanitize_text_field(wp_unslash((string) $integrations['legacy_rest_sunset_date'])) : (string) $defaults['integrations']['legacy_rest_sunset_date'];
		$config['integrations']['legacy_rest_sunset_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $sunset_date) ? $sunset_date : (string) $defaults['integrations']['legacy_rest_sunset_date'];
		$config['integrations']['public_ingest_enabled'] = isset($integrations['public_ingest_enabled']);
		$config['integrations']['ingestion_shared_key'] = isset($integrations['ingestion_shared_key']) ? sanitize_text_field(wp_unslash((string) $integrations['ingestion_shared_key'])) : '';
		$config['integrations']['public_events_per_minute'] = isset($integrations['public_events_per_minute']) ? max(10, min(1000, (int) $integrations['public_events_per_minute'])) : (int) $defaults['integrations']['public_events_per_minute'];
		$config['integrations']['public_suggest_per_minute'] = isset($integrations['public_suggest_per_minute']) ? max(5, min(1000, (int) $integrations['public_suggest_per_minute'])) : (int) $defaults['integrations']['public_suggest_per_minute'];

		return $config;
	}
}
