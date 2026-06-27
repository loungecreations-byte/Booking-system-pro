<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots {
	private bool $frontend_assets_ensured = false;

	public function init(): void {
		add_action('init', array($this, 'register_content_model'));
		add_action('init', array($this, 'register_meta_fields'));
		add_action('init', array($this, 'register_shortcodes'));
		add_filter('request', array($this, 'maybe_route_spots_root_to_page'), 1);
		add_filter('use_block_editor_for_post_type', array($this, 'disable_block_editor_for_spot_type'), 10, 2);
		add_filter('use_block_editor_for_post', array($this, 'disable_block_editor_for_spot_post'), 10, 2);
		add_action('save_post_' . DDB_Spots_Core_Schema::POST_TYPE, array($this, 'save_meta_boxes'));
		add_action('save_post_' . DDB_Spots_Core_Schema::POST_TYPE, array($this, 'invalidate_listing_cache'), 20);
		add_action('before_delete_post', array($this, 'invalidate_cache_on_delete'));
		add_action('set_object_terms', array($this, 'invalidate_cache_on_term_set'), 20, 6);
		add_action('rest_api_init', array($this, 'register_rest_routes'));
		add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
		add_filter('the_content', array($this, 'append_single_spot_content'));
		add_filter('template_include', array($this, 'maybe_override_archive_template'), 99);
		add_action('template_redirect', array($this, 'maybe_render_archive_fallback'), 1);
	}

	public function maybe_route_spots_root_to_page(array $query_vars): array {
		if (is_admin()) {
			return $query_vars;
		}

		$request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
		$path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
		$path = '/' . trim(strtolower($path), '/') . '/';
		if ('/spots/' !== $path) {
			return $query_vars;
		}

		$is_spot_archive_request = isset($query_vars['post_type']) && DDB_Spots_Core_Schema::POST_TYPE === (string) $query_vars['post_type'];
		if (! $is_spot_archive_request) {
			return $query_vars;
		}

		$page = get_page_by_path('spots', OBJECT, 'page');
		if (! $page instanceof WP_Post || 'publish' !== (string) $page->post_status) {
			return $query_vars;
		}

		return array(
			'page_id'  => (int) $page->ID,
			'pagename' => (string) $page->post_name,
		);
	}

	public function disable_block_editor_for_spot_type(bool $use_block_editor, string $post_type): bool {
		if (DDB_Spots_Core_Schema::POST_TYPE === $post_type) {
			return false;
		}
		return $use_block_editor;
	}

	public function disable_block_editor_for_spot_post(bool $use_block_editor, WP_Post $post): bool {
		if (DDB_Spots_Core_Schema::POST_TYPE === (string) $post->post_type) {
			return false;
		}
		return $use_block_editor;
	}

	public function register_content_model(): void {
		register_post_type(
			DDB_Spots_Core_Schema::POST_TYPE,
			array(
				'label'        => __('Spots', 'ddb-spots'),
				'labels'       => array('singular_name' => __('Spot', 'ddb-spots')),
				'public'       => true,
				'show_in_rest' => true,
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-location-alt',
				'supports'     => array('title', 'editor', 'excerpt', 'thumbnail'),
				'rewrite'      => array('slug' => 'spots'),
			)
		);

		register_taxonomy(
			DDB_Spots_Core_Schema::TAX['type'],
			DDB_Spots_Core_Schema::POST_TYPE,
			array('label' => __('Spot Types', 'ddb-spots'), 'public' => true, 'show_in_rest' => true, 'hierarchical' => false, 'show_admin_column' => true)
		);
		register_taxonomy(
			DDB_Spots_Core_Schema::TAX['area'],
			DDB_Spots_Core_Schema::POST_TYPE,
			array('label' => __('Areas', 'ddb-spots'), 'public' => true, 'show_in_rest' => true, 'hierarchical' => true, 'show_admin_column' => true)
		);
		register_taxonomy(
			DDB_Spots_Core_Schema::TAX['tag'],
			DDB_Spots_Core_Schema::POST_TYPE,
			array('label' => __('Spot Tags', 'ddb-spots'), 'public' => true, 'show_in_rest' => true, 'hierarchical' => false, 'show_admin_column' => true)
		);
		register_taxonomy(
			DDB_Spots_Core_Schema::TAX['category'],
			DDB_Spots_Core_Schema::POST_TYPE,
			array('label' => __('Spot Categories', 'ddb-spots'), 'public' => true, 'show_in_rest' => true, 'hierarchical' => true, 'show_admin_column' => true)
		);
	}

	public function register_meta_fields(): void {
		foreach (DDB_Spots_Core_Schema::META as $key => $meta_key) {
			$sanitize = array($this, 'sanitize_meta_text');
			if (str_contains($meta_key, '_url')) {
				$sanitize = array($this, 'sanitize_meta_url');
			}
			if ('google_website' === $key) {
				$sanitize = array($this, 'sanitize_meta_url');
			}
			if (str_contains($meta_key, '_json') || 'google_photo_refs_json' === $key) {
				$sanitize = array($this, 'sanitize_meta_textarea');
			}
			if ('event_date' === $key) {
				$sanitize = array($this, 'sanitize_event_date');
			}
			if ('booking_provider' === $key) {
				$sanitize = array($this, 'sanitize_booking_provider');
			}
			if ('priority' === $key) {
				$sanitize = array($this, 'sanitize_meta_int');
			}
			if ('price_level' === $key) {
				$sanitize = array($this, 'sanitize_price_level');
			}
			if ('group_max' === $key) {
				$sanitize = array($this, 'sanitize_meta_absint');
			}
			if ('duration_hint' === $key) {
				$sanitize = array($this, 'sanitize_meta_absint');
			}
			if ('parent_business_id' === $key) {
				$sanitize = array($this, 'sanitize_meta_absint');
			}
			if (in_array($key, array('group_fit_score', 'walk_distance_to_core'), true)) {
				$sanitize = array($this, 'sanitize_meta_absint');
			}
			if ('source' === $key) {
				$sanitize = array($this, 'sanitize_source');
			}
			if ('best_time_slot' === $key) {
				$sanitize = array($this, 'sanitize_best_time_slot');
			}
			if ('weather_compatibility' === $key) {
				$sanitize = array($this, 'sanitize_weather_compatibility');
			}
			if (in_array($key, array('lat', 'lng'), true)) {
				$sanitize = array($this, 'sanitize_coordinate');
			}
			if ('google_autosync' === $key || in_array($key, array('lock_title', 'lock_excerpt', 'lock_cta', 'lock_location', 'lock_contact', 'lock_hours', 'informational_only'), true)) {
				$sanitize = array($this, 'sanitize_checkbox');
			}
			if ('gallery_ids' === $key) {
				$sanitize = array($this, 'sanitize_csv_ids');
			}
			if (in_array($key, array('logo_id', 'image_hero_id', 'image_sfeer_id', 'image_eten_id'), true)) {
				$sanitize = array($this, 'sanitize_meta_absint');
			}
			if ('formitable_embed_raw' === $key) {
				$sanitize = array($this, 'sanitize_formitable_embed');
			}
			if ('formitable_embed' === $key) {
				$sanitize = array($this, 'sanitize_formitable_embed');
			}
			$auth = (in_array($key, array('formitable_embed_raw', 'formitable_embed'), true)) ? array($this, 'can_edit_formitable_embed') : array($this, 'can_edit_spot_meta');

			register_post_meta(
				DDB_Spots_Core_Schema::POST_TYPE,
				$meta_key,
				array(
					'single'            => true,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => $sanitize,
					'auth_callback'     => $auth,
					'show_in_rest'      => false,
				)
			);
		}
	}

	public function sanitize_meta_text($value): string {
		return sanitize_text_field((string) $value);
	}

	public function sanitize_meta_url($value): string {
		return esc_url_raw((string) $value);
	}

	public function sanitize_meta_textarea($value): string {
		return sanitize_textarea_field((string) $value);
	}

	public function sanitize_meta_int($value): string {
		return (string) intval($value);
	}

	public function sanitize_meta_absint($value): string {
		return (string) absint($value);
	}

	public function can_edit_spot_meta(): bool {
		return current_user_can('edit_posts');
	}

	public function can_edit_formitable_embed(): bool {
		return current_user_can('manage_options');
	}

	public function sanitize_event_date(string $value): string {
		$value = sanitize_text_field($value);
		if ('' === $value) {
			return '';
		}
		$site_timezone = wp_timezone();
		$date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $site_timezone);
		if (! $date instanceof DateTimeImmutable) {
			$timestamp = strtotime($value);
			if (false === $timestamp) {
				return '';
			}
			$date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($site_timezone);
		}
		return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}

	public function sanitize_booking_provider(string $value): string {
		$value = sanitize_text_field($value);
		return in_array($value, array('none', 'formitable', 'external', 'ticket'), true) ? $value : 'none';
	}

	public function sanitize_price_level(string $value): string {
		$level = (int) $value;
		if ($level < 1 || $level > 4) {
			return '';
		}
		return (string) $level;
	}

	public function sanitize_source(string $value): string {
		$value = sanitize_text_field($value);
		return in_array($value, array('manual', 'google_places', 'partner'), true) ? $value : 'manual';
	}

	public function sanitize_best_time_slot(string $value): string {
		$value = sanitize_key($value);
		return in_array($value, array('morning', 'lunch', 'afternoon', 'evening'), true) ? $value : '';
	}

	public function sanitize_weather_compatibility(string $value): string {
		$value = sanitize_key($value);
		return in_array($value, array('rainproof', 'outdoor'), true) ? $value : '';
	}

	public function sanitize_coordinate(string $value): string {
		$value = str_replace(',', '.', sanitize_text_field($value));
		if ('' === $value || ! is_numeric($value)) {
			return '';
		}
		return (string) $value;
	}

	public function sanitize_checkbox($value): string {
		if (is_string($value) || is_numeric($value)) {
			return ((int) $value) > 0 ? '1' : '0';
		}
		return '0';
	}

	public function sanitize_csv_ids(string $value): string {
		$parts = array_filter(array_map('absint', array_map('trim', explode(',', $value))));
		return implode(',', $parts);
	}

	private function format_event_date_for_input(string $stored_value): string {
		if ('' === $stored_value) {
			return '';
		}
		$date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stored_value, new DateTimeZone('UTC'));
		if (! $date instanceof DateTimeImmutable) {
			$timestamp = strtotime($stored_value);
			if (false === $timestamp) {
				return '';
			}
			$date = new DateTimeImmutable('@' . $timestamp);
		}
		return $date->setTimezone(wp_timezone())->format('Y-m-d\TH:i');
	}

	public function sanitize_formitable_embed(string $value): string {
		$value = trim($value);
		if ('' === $value) {
			return '';
		}
		$value = preg_replace('#<script(?![^>]*\bsrc=)[^>]*>.*?</script>#is', '', $value);
		if (null === $value) {
			return '';
		}
		$value = preg_replace_callback(
			'#<script[^>]*\bsrc=([\'"])([^\'"]+)\1[^>]*>\s*</script>#i',
			function (array $matches): string {
				return $this->is_trusted_formitable_src($matches[2]) ? $matches[0] : '';
			},
			$value
		);
		$value = preg_replace_callback(
			'#<iframe[^>]*\bsrc=([\'"])([^\'"]+)\1[^>]*>.*?</iframe>#is',
			function (array $matches): string {
				return $this->is_trusted_formitable_src($matches[2]) ? $matches[0] : '';
			},
			$value
		);
		if (null === $value) {
			return '';
		}
		return wp_kses($value, $this->get_formitable_allowed_tags());
	}

	private function is_trusted_formitable_src(string $url): bool {
		$host = (string) wp_parse_url($url, PHP_URL_HOST);
		if ('' === $host) {
			return false;
		}

		$host = strtolower($host);
		$trusted = array('formitable.com', 'widget.formitable.com', 'reservations.formitable.com');
		foreach ($trusted as $domain) {
			$domain = strtolower($domain);
			if ($host === $domain || str_ends_with($host, '.' . $domain)) {
				return true;
			}
		}

		return false;
	}

	private function get_formitable_allowed_tags(): array {
		return array(
			'iframe' => array('src' => true, 'width' => true, 'height' => true, 'frameborder' => true, 'allow' => true, 'allowfullscreen' => true, 'class' => true, 'id' => true, 'style' => true, 'loading' => true, 'title' => true),
			'div'    => array('class' => true, 'id' => true, 'data-venue-id' => true, 'data-widget-id' => true, 'data-formitable-id' => true),
			'script' => array('src' => true, 'async' => true, 'defer' => true, 'type' => true, 'id' => true),
			'a'      => array('href' => true, 'target' => true, 'rel' => true, 'class' => true),
			'p'      => array('class' => true),
			'span'   => array('class' => true),
		);
	}

	public function register_meta_boxes(): void {
		add_meta_box('ddb_spot_meta', __('Spot Details', 'ddb-spots'), array($this, 'render_meta_box'), DDB_Spots_Core_Schema::POST_TYPE, 'normal', 'high');
	}

	public function render_meta_box(WP_Post $post): void {
		wp_nonce_field('ddb_spots_save_meta', 'ddb_spots_meta_nonce');
			$v = array();
			foreach (DDB_Spots_Core_Schema::META as $k => $m) {
				$v[ $k ] = (string) get_post_meta($post->ID, $m, true);
			}
			$event_value = $this->format_event_date_for_input($v['event_date']);
			?>
		<p><?php esc_html_e('Type-specifieke velden voor restaurant/event/hotel.', 'ddb-spots'); ?></p>
		<table class="form-table">
			<tr data-ddb-types="restaurant,restaurants"><th><label for="ddb_formitable_venue_id">Formitable Venue ID</label></th><td><input class="regular-text" type="text" id="ddb_formitable_venue_id" name="ddb_formitable_venue_id" value="<?php echo esc_attr($v['formitable_venue_id']); ?>" /></td></tr>
			<tr data-ddb-types="restaurant,restaurants"><th><label for="ddb_formitable_widget_id">Formitable Widget ID</label></th><td><input class="regular-text" type="text" id="ddb_formitable_widget_id" name="ddb_formitable_widget_id" value="<?php echo esc_attr($v['formitable_widget_id']); ?>" /></td></tr>
			<tr data-ddb-types="restaurant,restaurants"><th><label for="ddb_restaurant_booking_url">Restaurant Booking URL</label></th><td><input class="regular-text" type="url" id="ddb_restaurant_booking_url" name="ddb_restaurant_booking_url" value="<?php echo esc_url($v['restaurant_booking_url']); ?>" /></td></tr>
			<tr data-ddb-types="restaurant,restaurants"><th><label for="ddb_formitable_embed_raw">Formitable Raw Embed (admins)</label></th><td><?php if (current_user_can('manage_options')) : ?><textarea class="large-text code" rows="6" id="ddb_formitable_embed_raw" name="ddb_formitable_embed_raw"><?php echo esc_textarea($v['formitable_embed_raw']); ?></textarea><?php else : ?><em><?php esc_html_e('Alleen trusted admins kunnen embed bewerken.', 'ddb-spots'); ?></em><?php endif; ?></td></tr>
			<tr data-ddb-types="event,events"><th><label for="ddb_event_date">Event Date</label></th><td><input type="datetime-local" id="ddb_event_date" name="ddb_event_date" value="<?php echo esc_attr($event_value); ?>" /></td></tr>
			<tr data-ddb-types="event,events"><th><label for="ddb_event_ticket_url">Event Ticket URL</label></th><td><input class="regular-text" type="url" id="ddb_event_ticket_url" name="ddb_event_ticket_url" value="<?php echo esc_url($v['event_ticket_url']); ?>" /></td></tr>
			<tr data-ddb-types="hotel,hotels"><th><label for="ddb_hotel_booking_url">Hotel Booking URL</label></th><td><input class="regular-text" type="url" id="ddb_hotel_booking_url" name="ddb_hotel_booking_url" value="<?php echo esc_url($v['hotel_booking_url']); ?>" /></td></tr>
			<tr data-ddb-types="all"><th><label for="ddb_spot_cta_url">Generic CTA URL</label></th><td><input class="regular-text" type="url" id="ddb_spot_cta_url" name="ddb_spot_cta_url" value="<?php echo esc_url($v['generic_cta_url']); ?>" /></td></tr>
		</table>
		<?php
	}

	public function save_meta_boxes(int $post_id): void {
		if (! isset($_POST['ddb_spots_meta_nonce'])) {
			return;
		}
		$nonce = sanitize_text_field(wp_unslash((string) $_POST['ddb_spots_meta_nonce']));
		if (! wp_verify_nonce($nonce, 'ddb_spots_save_meta')) {
			return;
		}
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$map = array(
			'ddb_booking_provider'       => DDB_Spots_Core_Schema::META['booking_provider'],
			'ddb_spot_type_primary'      => DDB_Spots_Core_Schema::META['spot_type_primary'],
			'ddb_priority'               => DDB_Spots_Core_Schema::META['priority'],
			'ddb_price_level'            => DDB_Spots_Core_Schema::META['price_level'],
			'ddb_formitable_embed'       => DDB_Spots_Core_Schema::META['formitable_embed'],
			'ddb_cta_url'                => DDB_Spots_Core_Schema::META['cta_url'],
				'ddb_group_max'              => DDB_Spots_Core_Schema::META['group_max'],
				'ddb_duration_hint'          => DDB_Spots_Core_Schema::META['duration_hint'],
				'ddb_best_time_slot'         => DDB_Spots_Core_Schema::META['best_time_slot'],
				'ddb_weather_compatibility'  => DDB_Spots_Core_Schema::META['weather_compatibility'],
				'ddb_group_fit_score'        => DDB_Spots_Core_Schema::META['group_fit_score'],
				'ddb_walk_distance_to_core'  => DDB_Spots_Core_Schema::META['walk_distance_to_core'],
				'ddb_parent_business_id'     => DDB_Spots_Core_Schema::META['parent_business_id'],
				'ddb_source'                 => DDB_Spots_Core_Schema::META['source'],
			'ddb_google_place_id'        => DDB_Spots_Core_Schema::META['google_place_id'],
			'ddb_google_last_synced_at'  => DDB_Spots_Core_Schema::META['google_last_synced_at'],
			'ddb_google_opening_hours_json' => DDB_Spots_Core_Schema::META['google_opening_hours'],
			'ddb_google_phone'           => DDB_Spots_Core_Schema::META['google_phone'],
			'ddb_google_website'         => DDB_Spots_Core_Schema::META['google_website'],
			'ddb_address'                => DDB_Spots_Core_Schema::META['address'],
			'ddb_city'                   => DDB_Spots_Core_Schema::META['city'],
			'ddb_region'                 => DDB_Spots_Core_Schema::META['region'],
			'ddb_country'                => DDB_Spots_Core_Schema::META['country'],
			'ddb_lat'                    => DDB_Spots_Core_Schema::META['lat'],
			'ddb_lng'                    => DDB_Spots_Core_Schema::META['lng'],
			'ddb_google_photo_refs_json' => DDB_Spots_Core_Schema::META['google_photo_refs_json'],
			'ddb_google_attribution_json' => DDB_Spots_Core_Schema::META['google_attribution_json'],
			'ddb_google_autosync'        => DDB_Spots_Core_Schema::META['google_autosync'],
			'ddb_opening_hours_json'     => DDB_Spots_Core_Schema::META['opening_hours_json'],
			'ddb_suitability_json'       => DDB_Spots_Core_Schema::META['suitability_json'],
			'ddb_near_spots_json'        => DDB_Spots_Core_Schema::META['near_spots_json'],
			'ddb_bundles_json'           => DDB_Spots_Core_Schema::META['bundles_json'],
			'ddb_spot_highlights'        => DDB_Spots_Core_Schema::META['highlights_json'],
			'ddb_gallery_ids'            => DDB_Spots_Core_Schema::META['gallery_ids'],
			'ddb_logo_id'                => DDB_Spots_Core_Schema::META['logo_id'],
			'ddb_image_hero_id'          => DDB_Spots_Core_Schema::META['image_hero_id'],
			'ddb_image_sfeer_id'         => DDB_Spots_Core_Schema::META['image_sfeer_id'],
			'ddb_image_eten_id'          => DDB_Spots_Core_Schema::META['image_eten_id'],
			'ddb_formitable_venue_id'    => DDB_Spots_Core_Schema::META['formitable_venue_id'],
			'ddb_formitable_widget_id'   => DDB_Spots_Core_Schema::META['formitable_widget_id'],
			'ddb_restaurant_booking_url' => DDB_Spots_Core_Schema::META['restaurant_booking_url'],
			'ddb_event_date'             => DDB_Spots_Core_Schema::META['event_date'],
			'ddb_event_ticket_url'       => DDB_Spots_Core_Schema::META['event_ticket_url'],
			'ddb_hotel_booking_url'      => DDB_Spots_Core_Schema::META['hotel_booking_url'],
			'ddb_spot_cta_url'           => DDB_Spots_Core_Schema::META['generic_cta_url'],
			'ddb_informational_only'     => DDB_Spots_Core_Schema::META['informational_only'],
			'ddb_lock_title'             => DDB_Spots_Core_Schema::META['lock_title'],
			'ddb_lock_excerpt'           => DDB_Spots_Core_Schema::META['lock_excerpt'],
			'ddb_lock_cta'               => DDB_Spots_Core_Schema::META['lock_cta'],
			'ddb_lock_location'          => DDB_Spots_Core_Schema::META['lock_location'],
			'ddb_lock_contact'           => DDB_Spots_Core_Schema::META['lock_contact'],
			'ddb_lock_hours'             => DDB_Spots_Core_Schema::META['lock_hours'],
		);

		foreach ($map as $field => $meta_key) {
			if (! array_key_exists($field, $_POST) && ! str_contains($field, 'lock_') && $field !== 'ddb_informational_only') {
				continue;
			}
			$raw = array_key_exists($field, $_POST) ? wp_unslash((string) $_POST[ $field ]) : '0';
			$value = sanitize_text_field($raw);
				if (str_contains($meta_key, '_url')) {
					$value = esc_url_raw($raw);
				}
				if (DDB_Spots_Core_Schema::META['google_website'] === $meta_key) {
					$value = esc_url_raw($raw);
				}
			if (DDB_Spots_Core_Schema::META['event_date'] === $meta_key) {
				$value = $this->sanitize_event_date($raw);
			}
			if (DDB_Spots_Core_Schema::META['booking_provider'] === $meta_key) {
				$value = $this->sanitize_booking_provider($raw);
			}
			if (DDB_Spots_Core_Schema::META['price_level'] === $meta_key) {
				$value = $this->sanitize_price_level($raw);
			}
			if (DDB_Spots_Core_Schema::META['priority'] === $meta_key) {
				$value = (string) intval($raw);
			}
			if (DDB_Spots_Core_Schema::META['group_max'] === $meta_key) {
				$value = (string) absint($raw);
			}
				if (DDB_Spots_Core_Schema::META['duration_hint'] === $meta_key) {
					$value = (string) absint($raw);
				}
				if (in_array($meta_key, array(DDB_Spots_Core_Schema::META['group_fit_score'], DDB_Spots_Core_Schema::META['walk_distance_to_core']), true)) {
					$value = (string) absint($raw);
				}
				if (DDB_Spots_Core_Schema::META['parent_business_id'] === $meta_key) {
					$linked_business_id = absint((int) $raw);
					$value = $linked_business_id > 0 ? (string) $linked_business_id : '';
				}
				if (DDB_Spots_Core_Schema::META['source'] === $meta_key) {
					$value = $this->sanitize_source($raw);
				}
			if (DDB_Spots_Core_Schema::META['best_time_slot'] === $meta_key) {
				$value = $this->sanitize_best_time_slot($raw);
			}
			if (DDB_Spots_Core_Schema::META['weather_compatibility'] === $meta_key) {
				$value = $this->sanitize_weather_compatibility($raw);
			}
			if (DDB_Spots_Core_Schema::META['formitable_embed'] === $meta_key) {
				$value = $this->sanitize_formitable_embed($raw);
			}
			if (DDB_Spots_Core_Schema::META['lat'] === $meta_key || DDB_Spots_Core_Schema::META['lng'] === $meta_key) {
				$value = $this->sanitize_coordinate($raw);
			}
			if (in_array($meta_key, array(DDB_Spots_Core_Schema::META['google_autosync'], DDB_Spots_Core_Schema::META['lock_title'], DDB_Spots_Core_Schema::META['lock_excerpt'], DDB_Spots_Core_Schema::META['lock_cta'], DDB_Spots_Core_Schema::META['lock_location'], DDB_Spots_Core_Schema::META['lock_contact'], DDB_Spots_Core_Schema::META['lock_hours'], DDB_Spots_Core_Schema::META['informational_only']), true)) {
				$value = $this->sanitize_checkbox($raw);
			}
			if (DDB_Spots_Core_Schema::META['gallery_ids'] === $meta_key) {
				$value = $this->sanitize_csv_ids($raw);
			}
			if (in_array($meta_key, array(DDB_Spots_Core_Schema::META['logo_id'], DDB_Spots_Core_Schema::META['image_hero_id'], DDB_Spots_Core_Schema::META['image_sfeer_id'], DDB_Spots_Core_Schema::META['image_eten_id']), true)) {
				$value = (string) absint($raw);
			}
			if (DDB_Spots_Core_Schema::META['opening_hours_json'] === $meta_key && isset($_POST['ddb_opening_hours'])) {
				$hours = array_map('sanitize_text_field', (array) $_POST['ddb_opening_hours']);
				$value = wp_json_encode($hours);
			}
				if (DDB_Spots_Core_Schema::META['highlights_json'] === $meta_key) {
					$lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: array()));
					$value = ! empty($lines) ? wp_json_encode(array_values($lines)) : '';
				}
				$this->save_meta_value($post_id, $meta_key, $value);
				if (DDB_Spots_Core_Schema::META['parent_business_id'] === $meta_key) {
					if ('' === $value) {
						delete_post_meta($post_id, '_ddb_business_id');
					} else {
						update_post_meta($post_id, '_ddb_business_id', absint((int) $value));
					}
				}
			}

		if (isset($_POST['ddb_formitable_embed_raw']) && current_user_can('manage_options')) {
			$this->save_meta_value($post_id, DDB_Spots_Core_Schema::META['formitable_embed_raw'], $this->sanitize_formitable_embed(wp_unslash((string) $_POST['ddb_formitable_embed_raw'])));
		}

		if (array_key_exists('ddb_area_term_id', $_POST)) {
			$area_term_id = absint((int) wp_unslash((string) $_POST['ddb_area_term_id']));
			if ($area_term_id > 0) {
				$term = get_term($area_term_id, DDB_Spots_Core_Schema::TAX['area']);
				if ($term instanceof WP_Term && ! is_wp_error($term)) {
					wp_set_post_terms($post_id, array($area_term_id), DDB_Spots_Core_Schema::TAX['area'], false);
				}
			} else {
				wp_set_post_terms($post_id, array(), DDB_Spots_Core_Schema::TAX['area'], false);
			}
		}

		$current_area_ids = wp_get_post_terms($post_id, DDB_Spots_Core_Schema::TAX['area'], array('fields' => 'ids'));
		if (is_wp_error($current_area_ids) || ! is_array($current_area_ids)) {
			$current_area_ids = array();
		}
		if (empty($current_area_ids)) {
			$default_area_id = $this->get_default_area_term_id();
			if ($default_area_id > 0) {
				wp_set_post_terms($post_id, array($default_area_id), DDB_Spots_Core_Schema::TAX['area'], false);
			}
		}
	}

	private function get_default_area_term_id(): int {
		$centrum = get_term_by('slug', 'centrum', DDB_Spots_Core_Schema::TAX['area']);
		if ($centrum instanceof WP_Term && ! is_wp_error($centrum)) {
			return absint((int) $centrum->term_id);
		}

		$terms = get_terms(
			array(
				'taxonomy' => DDB_Spots_Core_Schema::TAX['area'],
				'hide_empty' => false,
				'name' => 'Centrum',
				'number' => 1,
			)
		);
		if (! is_wp_error($terms) && is_array($terms) && ! empty($terms[0]) && $terms[0] instanceof WP_Term) {
			return absint((int) $terms[0]->term_id);
		}
		return 0;
	}

	private function save_meta_value(int $post_id, string $meta_key, string $value): void {
		if ('' === $value) {
			delete_post_meta($post_id, $meta_key);
			return;
		}
		update_post_meta($post_id, $meta_key, $value);
	}

	public function invalidate_listing_cache(int $post_id): void {
		$post = get_post($post_id);
		if (! $post instanceof WP_Post || DDB_Spots_Core_Schema::POST_TYPE !== $post->post_type) {
			return;
		}
		self::invalidate_cache();
	}

	public function invalidate_cache_on_delete(int $post_id): void {
		$post = get_post($post_id);
		if (! $post instanceof WP_Post || DDB_Spots_Core_Schema::POST_TYPE !== $post->post_type) {
			return;
		}
		self::invalidate_cache();
	}

	public function invalidate_cache_on_term_set(int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids): void {
		unset($terms, $tt_ids, $append, $old_tt_ids);
		if (! in_array($taxonomy, DDB_Spots_Core_Schema::TAX, true)) {
			return;
		}
		$post = get_post($object_id);
		if (! $post instanceof WP_Post || DDB_Spots_Core_Schema::POST_TYPE !== $post->post_type) {
			return;
		}
		self::invalidate_cache();
	}

	public static function invalidate_cache(): void {
		$version = (int) get_option(DDB_Spots_Core_Schema::CACHE_VERSION_OPTION, 1);
		update_option(DDB_Spots_Core_Schema::CACHE_VERSION_OPTION, max(1, $version + 1), false);
	}

	public function register_shortcodes(): void {
		add_shortcode('ddb_spots', array($this, 'render_listing_shortcode'));
		add_shortcode('ddb_spot_cta', array($this, 'render_cta_shortcode'));
		add_shortcode('ddb_restaurant_widget', array($this, 'render_restaurant_widget_shortcode'));
		add_shortcode('ddb_spots_featured', array($this, 'render_featured_spots_shortcode'));
	}

	public function render_listing_shortcode(array $atts = array()): string {
		$this->ensure_frontend_assets();
		$atts = shortcode_atts(array('type' => '', 'area' => '', 'tag' => '', 'category' => '', 'per_page' => 12, 'page' => 0, 'lat' => '', 'lng' => '', 'variant' => 'overview'), $atts, 'ddb_spots');
		$variant = sanitize_key((string) $atts['variant']);
		if (in_array($variant, array('map', 'map-first', 'plattegrond'), true)) {
			$variant = 'map-first';
		} else {
			$variant = 'overview';
		}
		$show_map = 'map-first' === $variant;
		$selected_type = isset($_GET['ddb_type']) ? sanitize_title(wp_unslash((string) $_GET['ddb_type'])) : sanitize_title((string) $atts['type']);
		$selected_area = isset($_GET['ddb_area']) ? sanitize_title(wp_unslash((string) $_GET['ddb_area'])) : sanitize_title((string) $atts['area']);
		$selected_category = isset($_GET['ddb_category']) ? sanitize_title(wp_unslash((string) $_GET['ddb_category'])) : sanitize_title((string) $atts['category']);
		$search_query = isset($_GET['ddb_q']) ? sanitize_text_field(wp_unslash((string) $_GET['ddb_q'])) : '';
		$page = max(1, absint($atts['page']), isset($_GET['ddb_page']) ? absint((int) $_GET['ddb_page']) : 0);
		$per_page = min(50, max(1, absint($atts['per_page'])));
		$origin_lat = $this->to_float_or_null((string) $atts['lat']);
		$origin_lng = $this->to_float_or_null((string) $atts['lng']);

		$sort_type = (string) ($selected_type ?: 'restaurant');
		$config = DDB_Spots_Admin_Settings_Page::get_config();
		$sort_by = (string) ($config['ranking_visibility']['sorting_defaults'][ $sort_type ] ?? 'relevance');

		$tax_query = $this->build_tax_query_from_filters(
			array(
				'type' => $selected_type,
				'area' => $selected_area,
				'tag' => (string) $atts['tag'],
				'category' => $selected_category,
			)
		);
		$result = $this->query_spots_for_output($tax_query, $sort_by, $page, $per_page, $origin_lat, $origin_lng);
		$posts = $result['posts'];
		$total_pages = max(1, (int) $result['total_pages']);
		$type_options = get_terms(array('taxonomy' => DDB_Spots_Core_Schema::TAX['type'], 'hide_empty' => true));
		$area_options = get_terms(array('taxonomy' => DDB_Spots_Core_Schema::TAX['area'], 'hide_empty' => true));
		$category_options = get_terms(array('taxonomy' => DDB_Spots_Core_Schema::TAX['category'], 'hide_empty' => true));
		if (! is_array($type_options) || is_wp_error($type_options)) {
			$type_options = array();
		}
		if (! is_array($area_options) || is_wp_error($area_options)) {
			$area_options = array();
		}
		if (! is_array($category_options) || is_wp_error($category_options)) {
			$category_options = array();
		}

		if ('' !== trim($search_query)) {
			$needle = strtolower($search_query);
			$posts = array_values(
				array_filter(
					$posts,
					static function (WP_Post $post) use ($needle): bool {
						$title = strtolower((string) get_the_title($post->ID));
						$excerpt = strtolower((string) get_the_excerpt($post->ID));
						return str_contains($title, $needle) || str_contains($excerpt, $needle);
					}
				)
			);
		}

		$top_pick_posts = array();
		$regular_posts = array();
		foreach ($posts as $post) {
			$id = (int) $post->ID;
			$is_top_pick = function_exists('ddb_spots_is_top_pick_active') ? ddb_spots_is_top_pick_active($id) : false;
			if ($is_top_pick) {
				$top_pick_posts[] = $post;
			}
		}
		$featured_top_picks = array_slice($top_pick_posts, 0, 3);
		$featured_ids = array_map(
			static function (WP_Post $post): int {
				return (int) $post->ID;
			},
			$featured_top_picks
		);
		foreach ($posts as $post) {
			$id = (int) $post->ID;
			if (in_array($id, $featured_ids, true)) {
				continue;
			}
			$regular_posts[] = $post;
		}
		$visible_posts = array_merge($featured_top_picks, $regular_posts);
		$map_points = array();
		foreach ($visible_posts as $post) {
			$id = (int) $post->ID;
			$lat = $this->to_float_or_null((string) get_post_meta($id, DDB_Spots_Core_Schema::META['lat'], true));
			$lng = $this->to_float_or_null((string) get_post_meta($id, DDB_Spots_Core_Schema::META['lng'], true));
			if (null === $lat || null === $lng) {
				continue;
			}
			$maps_url = (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_maps_url'], true);
			if ('' === trim($maps_url)) {
				$maps_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($lat . ',' . $lng);
			}
			$address = trim((string) get_post_meta($id, DDB_Spots_Core_Schema::META['address'], true));
			$city = trim((string) get_post_meta($id, DDB_Spots_Core_Schema::META['city'], true));
			$map_points[] = array(
				'id' => $id,
				'title' => (string) get_the_title($id),
				'lat' => $lat,
				'lng' => $lng,
				'address' => trim(implode(', ', array_filter(array($address, $city)))),
				'maps_url' => $maps_url,
				'embed_url' => $this->build_openstreetmap_embed_url($lat, $lng),
			);
		}
		$active_map = ! empty($map_points) ? $map_points[0] : null;
		$total_visible = count($visible_posts);
		$query_args = array(
			'ddb_q' => $search_query,
			'ddb_type' => $selected_type,
			'ddb_area' => $selected_area,
			'ddb_category' => $selected_category,
		);
		$reset_url = remove_query_arg(array('ddb_q', 'ddb_type', 'ddb_area', 'ddb_category', 'ddb_page'));

		ob_start();
		?>
		<div class="ddb-spots-listing ddb-spots-listing--<?php echo esc_attr($variant); ?> ddb-listing-shell" data-ddb-component="listing-shell">
			<form method="get" class="ddb-listing-toolbar ui-summary ui-summary--compact" aria-label="<?php esc_attr_e('Spot filters', 'ddb-spots'); ?>">
				<input type="hidden" name="ddb_page" value="1" />
				<div class="ddb-listing-toolbar__row">
					<label class="ddb-listing-field ui-field">
						<span><?php esc_html_e('Zoek', 'ddb-spots'); ?></span>
						<input class="ui-input" type="search" name="ddb_q" value="<?php echo esc_attr($search_query); ?>" placeholder="<?php esc_attr_e('Naam of omschrijving', 'ddb-spots'); ?>" />
					</label>
					<label class="ddb-listing-field ui-field">
						<span><?php esc_html_e('Type', 'ddb-spots'); ?></span>
						<select class="ui-select" name="ddb_type">
							<option value=""><?php esc_html_e('Alle types', 'ddb-spots'); ?></option>
							<?php foreach ($type_options as $term) : ?>
								<option value="<?php echo esc_attr((string) $term->slug); ?>" <?php selected($selected_type, (string) $term->slug); ?>><?php echo esc_html((string) $term->name); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="ddb-listing-field ui-field">
						<span><?php esc_html_e('Buurt', 'ddb-spots'); ?></span>
						<select class="ui-select" name="ddb_area">
							<option value=""><?php esc_html_e('Alle buurten', 'ddb-spots'); ?></option>
							<?php foreach ($area_options as $term) : ?>
								<option value="<?php echo esc_attr((string) $term->slug); ?>" <?php selected($selected_area, (string) $term->slug); ?>><?php echo esc_html((string) $term->name); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="ddb-listing-field ui-field">
						<span><?php esc_html_e('Categorie', 'ddb-spots'); ?></span>
						<select class="ui-select" name="ddb_category">
							<option value=""><?php esc_html_e('Alle categorieën', 'ddb-spots'); ?></option>
							<?php foreach ($category_options as $term) : ?>
								<option value="<?php echo esc_attr((string) $term->slug); ?>" <?php selected($selected_category, (string) $term->slug); ?>><?php echo esc_html((string) $term->name); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
				<div class="ddb-listing-toolbar__actions">
					<button type="submit" class="ui-btn ui-btn--primary ddb-listing-btn ddb-listing-btn--primary"><?php esc_html_e('Toon resultaten', 'ddb-spots'); ?></button>
					<a class="ui-btn ui-btn--ghost ddb-listing-btn ddb-listing-btn--ghost" href="<?php echo esc_url($reset_url); ?>"><?php esc_html_e('Reset', 'ddb-spots'); ?></a>
					<?php if ((bool) apply_filters('ddb_spots_show_legacy_theme_button', false, 0, 'listing')) : ?>
						<button type="button" class="ui-btn ui-btn--ghost ddb-listing-btn ddb-listing-btn--theme" data-ddb-theme-toggle data-light-label="<?php echo esc_attr__('Lichte modus', 'ddb-spots'); ?>" data-dark-label="<?php echo esc_attr__('Donkere modus', 'ddb-spots'); ?>"><?php esc_html_e('Thema', 'ddb-spots'); ?></button>
					<?php endif; ?>
					<?php if ($show_map) : ?>
						<button type="button" class="ui-btn ui-btn--secondary ddb-listing-btn ddb-listing-btn--map" data-ddb-map-toggle><?php esc_html_e('Kaart tonen', 'ddb-spots'); ?></button>
					<?php endif; ?>
				</div>
			</form>

			<div class="ddb-listing-main">
				<section class="ddb-listing-results" aria-live="polite">
					<header class="ddb-listing-results__head">
						<h2><?php echo esc_html(sprintf(_n('%d spot gevonden', '%d spots gevonden', $total_visible, 'ddb-spots'), $total_visible)); ?></h2>
					</header>

					<?php if (! empty($featured_top_picks)) : ?>
						<section class="ddb-listing-top-picks">
							<h3><?php esc_html_e('Top Picks', 'ddb-spots'); ?></h3>
							<div class="ddb-spots-grid ddb-spots-grid--top-picks">
								<?php foreach ($featured_top_picks as $post) : ?>
									<?php echo $this->render_listing_spot_card((int) $post->ID, true, $origin_lat, $origin_lng); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endif; ?>

					<?php if (! empty($regular_posts)) : ?>
						<div class="ddb-spots-grid">
							<?php foreach ($regular_posts as $post) : ?>
								<?php echo $this->render_listing_spot_card((int) $post->ID, false, $origin_lat, $origin_lng); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php endforeach; ?>
						</div>
					<?php elseif (! empty($featured_top_picks)) : ?>
						<p class="ddb-listing-empty"><?php esc_html_e('Alle resultaten vallen momenteel in Top Picks.', 'ddb-spots'); ?></p>
					<?php else : ?>
						<p class="ddb-listing-empty"><?php esc_html_e('Geen spots gevonden voor deze filters.', 'ddb-spots'); ?></p>
					<?php endif; ?>

					<?php
					$base_query = array_filter(
						$query_args,
						static function (string $value): bool {
							return '' !== trim($value);
						}
					);
					$pagination = '';
					if ('' === trim($search_query)) {
						$pagination = paginate_links(
							array(
								'base' => esc_url_raw(add_query_arg(array_merge($base_query, array('ddb_page' => '%#%')))),
								'format' => '',
								'current' => $page,
								'total' => $total_pages,
								'type' => 'list',
							)
						);
					}
					if ($pagination) :
						?>
						<nav class="ddb-spots-pagination" aria-label="<?php esc_attr_e('Spots pagina navigatie', 'ddb-spots'); ?>"><?php echo wp_kses_post($pagination); ?></nav>
					<?php endif; ?>
				</section>

				<?php if ($show_map) : ?>
					<aside class="ddb-listing-map<?php echo ! empty($map_points) ? ' is-ready' : ''; ?>" data-ddb-map-pane>
						<div class="ddb-listing-map__sticky ui-summary ui-summary--compact">
							<h3><?php esc_html_e('Plattegrond', 'ddb-spots'); ?></h3>
							<?php if (! empty($map_points) && null !== $active_map) : ?>
								<iframe
									class="ddb-listing-map__frame"
									data-ddb-map-frame
									title="<?php esc_attr_e('Kaart van spots', 'ddb-spots'); ?>"
									src="<?php echo esc_url((string) $active_map['embed_url']); ?>"
									loading="lazy"
									referrerpolicy="no-referrer-when-downgrade"></iframe>
								<p class="ddb-listing-map__focus" data-ddb-map-focus>
									<strong data-ddb-map-title><?php echo esc_html((string) $active_map['title']); ?></strong>
									<span data-ddb-map-address><?php echo esc_html((string) $active_map['address']); ?></span>
									<a data-ddb-map-link href="<?php echo esc_url((string) $active_map['maps_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open route', 'ddb-spots'); ?></a>
								</p>
								<div class="ddb-listing-map__points" role="group" aria-label="<?php esc_attr_e('Kies een spot op de kaart', 'ddb-spots'); ?>">
									<?php foreach ($map_points as $index => $point) : ?>
										<button
											type="button"
											class="ddb-listing-map__item<?php echo 0 === $index ? ' is-active' : ''; ?>"
											data-ddb-map-item
											data-spot-id="<?php echo esc_attr((string) $point['id']); ?>"
											data-embed-url="<?php echo esc_attr((string) $point['embed_url']); ?>"
											data-map-url="<?php echo esc_attr((string) $point['maps_url']); ?>"
											data-title="<?php echo esc_attr((string) $point['title']); ?>"
											data-address="<?php echo esc_attr((string) $point['address']); ?>">
											<span><?php echo esc_html((string) $point['title']); ?></span>
										</button>
									<?php endforeach; ?>
								</div>
							<?php else : ?>
								<p class="ddb-listing-map__empty"><?php esc_html_e('Voor deze selectie zijn nog geen kaartcoördinaten beschikbaar.', 'ddb-spots'); ?></p>
							<?php endif; ?>
						</div>
					</aside>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function render_listing_spot_card(int $id, bool $is_top_pick = false, ?float $origin_lat = null, ?float $origin_lng = null): string {
		$title = (string) get_the_title($id);
		$permalink = (string) get_permalink($id);
		if ('' === $title || '' === $permalink) {
			return '';
		}

		$image_html = $this->get_spot_primary_image_html($id);
		$excerpt = (string) get_the_excerpt($id);
		$type_label = $this->get_primary_term_label($id, DDB_Spots_Core_Schema::TAX['type']);
		$area_label = $this->get_primary_term_label($id, DDB_Spots_Core_Schema::TAX['area']);
		$distance_km = $this->distance_km_from_origin($id, $origin_lat, $origin_lng);
		$rating = (float) get_post_meta($id, DDB_Spots_Core_Schema::META['google_rating'], true);
		$price_level = absint((int) get_post_meta($id, DDB_Spots_Core_Schema::META['price_level'], true));
		$price_label = ($price_level >= 1 && $price_level <= 4) ? str_repeat('€', $price_level) : '';
		$meta_bits = array();
		if ('' !== $type_label) {
			$meta_bits[] = esc_html($type_label);
		}
		if ('' !== $area_label) {
			$meta_bits[] = esc_html($area_label);
		}
		if ($rating > 0) {
			$meta_bits[] = esc_html(number_format($rating, 1) . ' ★');
		}
		if (null !== $distance_km) {
			$meta_bits[] = esc_html(number_format($distance_km, 1) . ' km');
		}

		$type_slug = $this->get_primary_type_slug($id);
		$is_horeca = in_array($type_slug, array('restaurant', 'restaurants'), true);
		$featured_badge = $is_top_pick ? '<span class="ui-listing-card__state ddb-spot-card__featured">' . esc_html__('Top Pick', 'ddb-spots') . '</span>' : '';
		$premium_labels = $this->render_premium_labels_html($id, 'card');
		$meta_line = '';
		if (! empty($meta_bits)) {
			$items = array_map(
				static function (string $bit): string {
					return '<li class="ui-listing-card__meta-item">' . $bit . '</li>';
				},
				$meta_bits
			);
			$meta_line = '<ul class="ui-listing-card__meta ddb-spot-card__meta">' . implode('', $items) . '</ul>';
		}
		$price_badge = '' !== $price_label ? '<span class="ui-listing-card__price ddb-spot-card__price">' . esc_html($price_label) . '</span>' : '';
		$safe_excerpt = '' !== trim($excerpt) ? '<p class="ddb-spot-card__excerpt">' . esc_html(wp_trim_words($excerpt, 12, '...')) . '</p>' : '';
		$cta = $this->get_cta_markup($id, 'card', 'secondary');
		$detail_variant = 'primary';
		$detail_button = sprintf(
			'<a class="ui-listing-card__cta ui-listing-card__cta--%5$s ddb-spot-cta-button ddb-spot-cta-button--detail" href="%1$s" data-ddb-track="card_click" data-ddb-context="card_detail" data-ddb-spot-id="%2$s" data-ddb-spot-type="%3$s">%4$s</a>',
			esc_url($permalink),
			esc_attr((string) $id),
			esc_attr($type_slug),
			esc_html__('Bekijk plek', 'ddb-spots'),
			esc_attr($detail_variant)
		);
		$actions = $detail_button . $cta;

		return '<article class="ui-listing-card ui-listing-card--compact ddb-spot-card' . ($is_top_pick ? ' ddb-spot-card--featured' : '') . '" data-ddb-spot-id="' . esc_attr((string) $id) . '" data-ddb-spot-type="' . esc_attr($this->get_primary_type_slug($id)) . '">' .
			'<a class="ddb-spot-card__link" href="' . esc_url($permalink) . '" data-ddb-track="card_click" data-ddb-context="card_surface" data-ddb-spot-id="' . esc_attr((string) $id) . '" data-ddb-spot-type="' . esc_attr($this->get_primary_type_slug($id)) . '" data-ddb-map-focus="' . esc_attr((string) $id) . '">' .
				$image_html .
				'<div class="ddb-spot-card__body">' .
					'<div class="ddb-spot-card__labels">' . $featured_badge . $premium_labels . '</div>' .
					'<header class="ui-listing-card__header ddb-spot-card__header">' .
						'<div class="ui-listing-card__header-main">' .
							'<p class="ui-listing-card__eyebrow">' . esc_html($type_label ?: $area_label) . '</p>' .
							'<h3 class="ui-listing-card__title ddb-spot-card__title">' . esc_html($title) . '</h3>' .
						'</div>' .
						$price_badge .
					'</header>' .
					$meta_line .
					$safe_excerpt .
				'</div>' .
			'</a>' .
			'<div class="ui-listing-card__actions ddb-spot-card__cta">' . $actions . '</div>' .
		'</article>';
	}

	private function get_spot_primary_image_html(int $id): string {
		$thumb_id = get_post_thumbnail_id($id);
		if ($thumb_id > 0) {
			$html = wp_get_attachment_image($thumb_id, 'medium_large', false, array('class' => 'ddb-spot-card__image', 'loading' => 'lazy'));
			if ('' !== (string) $html) {
				return '<figure class="ui-listing-card__media ddb-spot-card__media">' . str_replace('class="ddb-spot-card__image"', 'class="ui-listing-card__image ddb-spot-card__image"', $html) . '</figure>';
			}
		}
		return '<figure class="ui-listing-card__media ddb-spot-card__media"><div class="ui-listing-card__placeholder ddb-spot-card__media--empty"></div></figure>';
	}

	private function get_primary_term_label(int $id, string $taxonomy): string {
		$terms = wp_get_post_terms($id, $taxonomy);
		if (is_wp_error($terms) || empty($terms) || ! isset($terms[0]) || ! $terms[0] instanceof WP_Term) {
			return '';
		}
		return (string) $terms[0]->name;
	}

	private function build_openstreetmap_embed_url(float $lat, float $lng, int $zoom = 15): string {
		$delta = 0.01;
		$left = $lng - $delta;
		$right = $lng + $delta;
		$bottom = $lat - $delta;
		$top = $lat + $delta;
		return 'https://www.openstreetmap.org/export/embed.html?bbox=' .
			rawurlencode($left . ',' . $bottom . ',' . $right . ',' . $top) .
			'&layer=mapnik&marker=' . rawurlencode($lat . ',' . $lng) .
			'&zoom=' . rawurlencode((string) max(8, min(18, $zoom)));
	}

	public function render_cta_shortcode(array $atts = array()): string {
		$this->ensure_frontend_assets();
		$atts = shortcode_atts(array('id' => 0), $atts, 'ddb_spot_cta');
		$id = absint($atts['id']);
		if (! $id) {
			$id = get_the_ID() ? absint(get_the_ID()) : 0;
		}
		if (! $id || DDB_Spots_Core_Schema::POST_TYPE !== get_post_type($id)) {
			return '';
		}
		return $this->get_cta_markup($id, 'shortcode');
	}

	public function render_restaurant_widget_shortcode(array $atts = array()): string {
		$this->ensure_frontend_assets();
		$atts = shortcode_atts(array('id' => 0), $atts, 'ddb_restaurant_widget');
		$id = absint($atts['id']);
		if (! $id) {
			$id = get_the_ID() ? absint(get_the_ID()) : 0;
		}
		if (! $id || DDB_Spots_Core_Schema::POST_TYPE !== get_post_type($id)) {
			return '';
		}
		if (! is_singular(DDB_Spots_Core_Schema::POST_TYPE) || (int) get_queried_object_id() !== $id || ! $this->spot_is_restaurant($id)) {
			return '';
		}
		return $this->get_restaurant_widget_markup($id);
	}

	private function build_tax_query_from_filters(array $filters): array {
		$tax_query = array();
		foreach ($filters as $filter => $value) {
			$value = trim($value);
			if ('' === $value || ! isset(DDB_Spots_Core_Schema::TAX[ $filter ])) {
				continue;
			}
			$terms = array_filter(array_map('sanitize_title', array_map('trim', explode(',', $value))));
			if (empty($terms)) {
				continue;
			}
			$tax_query[] = array('taxonomy' => DDB_Spots_Core_Schema::TAX[ $filter ], 'field' => 'slug', 'terms' => $terms);
		}
		if (count($tax_query) > 1) {
			$tax_query['relation'] = 'AND';
		}
		return $tax_query;
	}

	public function sanitize_rest_tax_filter($value): string {
		if (is_array($value)) {
			$value = implode(',', array_map('strval', $value));
		}
		if (! is_scalar($value)) {
			return '';
		}
		$raw = sanitize_text_field((string) $value);
		$terms = array_filter(array_map('sanitize_title', array_map('trim', explode(',', $raw))));
		return implode(',', $terms);
	}

	public function register_rest_routes(): void {
		if (function_exists('ddb_spots_premium_rest_active') && ddb_spots_premium_rest_active()) {
			return;
		}

		$legacy_enabled = (bool) DDB_Spots_Admin_Settings_Page::get_value('integrations.legacy_rest_enabled', true);
		if (! $legacy_enabled) {
			return;
		}

		register_rest_route(
			'ddb/v1',
			'/spots',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array($this, 'get_spots_rest'),
				'args'                => array(
					'type'     => array('sanitize_callback' => array($this, 'sanitize_rest_tax_filter')),
					'area'     => array('sanitize_callback' => array($this, 'sanitize_rest_tax_filter')),
					'tag'      => array('sanitize_callback' => array($this, 'sanitize_rest_tax_filter')),
					'category' => array('sanitize_callback' => array($this, 'sanitize_rest_tax_filter')),
					'per_page' => array('default' => 12, 'sanitize_callback' => 'absint'),
					'page'     => array('default' => 1, 'sanitize_callback' => 'absint'),
					'lat'      => array('sanitize_callback' => array($this, 'sanitize_rest_coordinate')),
					'lng'      => array('sanitize_callback' => array($this, 'sanitize_rest_coordinate')),
				),
			)
		);

		register_rest_route(
			'ddb/v1',
			'/spots/(?P<id>\d+)/ranking-debug',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array($this, 'can_debug_ranking_rest'),
				'callback'            => array($this, 'get_spot_ranking_debug_rest'),
				'args'                => array(
					'id'  => array('required' => true, 'sanitize_callback' => 'absint'),
					'lat' => array('sanitize_callback' => array($this, 'sanitize_rest_coordinate')),
					'lng' => array('sanitize_callback' => array($this, 'sanitize_rest_coordinate')),
				),
			)
		);
	}

	public function can_debug_ranking_rest(): bool {
		return current_user_can('edit_posts') || current_user_can(DDB_Spots_Core_Roles::CAP_VIEW_INSIGHTS);
	}

	public function sanitize_rest_coordinate($value): string {
		if (! is_scalar($value)) {
			return '';
		}
		return $this->sanitize_coordinate((string) $value);
	}

	public function get_spots_rest(WP_REST_Request $request): WP_REST_Response {
		$per_page = min(50, max(1, absint((int) $request->get_param('per_page'))));
		$page = max(1, absint((int) $request->get_param('page')));
		$origin_lat = $this->to_float_or_null((string) $request->get_param('lat'));
		$origin_lng = $this->to_float_or_null((string) $request->get_param('lng'));
		$sort_type = (string) $request->get_param('type');
		if ('' === $sort_type) {
			$sort_type = 'restaurant';
		}
		$config = DDB_Spots_Admin_Settings_Page::get_config();
		$sort_by = (string) ($config['ranking_visibility']['sorting_defaults'][ $sort_type ] ?? 'relevance');

		$tax_query = $this->build_tax_query_from_filters(
			array(
				'type'     => (string) $request->get_param('type'),
				'area'     => (string) $request->get_param('area'),
				'tag'      => (string) $request->get_param('tag'),
				'category' => (string) $request->get_param('category'),
			)
		);
		$result = $this->query_spots_for_output($tax_query, $sort_by, $page, $per_page, $origin_lat, $origin_lng);
		$items = array();
		foreach ($result['posts'] as $post) {
			$items[] = $this->build_spot_payload((int) $post->ID);
		}
		$response = rest_ensure_response(
			array(
				'items'        => $items,
				'pagination'   => array('page' => $page, 'per_page' => $per_page, 'total_items' => (int) $result['total_items'], 'total_pages' => (int) $result['total_pages']),
				'filters'      => array('type' => (string) $request->get_param('type'), 'area' => (string) $request->get_param('area'), 'tag' => (string) $request->get_param('tag'), 'category' => (string) $request->get_param('category'), 'lat' => (string) $request->get_param('lat'), 'lng' => (string) $request->get_param('lng')),
				'generated_at' => gmdate('c'),
			)
		);
		$response->header('X-DDB-Deprecated', 'true');
		$response->header('X-DDB-Replacement', '/wp-json/dbspots/v1/spots');
		$response->header('Deprecation', 'true');
		$response->header('Link', '</wp-json/dbspots/v1/spots>; rel="successor-version"');
		$sunset_date = (string) DDB_Spots_Admin_Settings_Page::get_value('integrations.legacy_rest_sunset_date', '');
		$sunset_ts = '' !== $sunset_date ? strtotime($sunset_date . ' 23:59:59 UTC') : false;
		if (false !== $sunset_ts) {
			$response->header('Sunset', gmdate('D, d M Y H:i:s', (int) $sunset_ts) . ' GMT');
			$response->header('X-DDB-Legacy-Sunset-Date', $sunset_date);
		}
		return $response;
	}

	public function get_spot_ranking_debug_rest(WP_REST_Request $request): WP_REST_Response {
		$post_id = absint((int) $request->get_param('id'));
		$post = $post_id > 0 ? get_post($post_id) : null;
		if (! $post instanceof WP_Post || DDB_Spots_Core_Schema::POST_TYPE !== $post->post_type) {
			return new WP_REST_Response(array('message' => __('Spot niet gevonden.', 'ddb-spots')), 404);
		}

		$origin_lat = $this->to_float_or_null((string) $request->get_param('lat'));
		$origin_lng = $this->to_float_or_null((string) $request->get_param('lng'));
		$config = DDB_Spots_Admin_Settings_Page::get_config();
		$weights = $this->get_ranking_weights($config);
		$components = $this->get_ranking_components($post_id, $origin_lat, $origin_lng);
		$score = $this->calculate_weighted_average($components, $weights);

		$debug_components = array();
		foreach ($components as $key => $raw_score) {
			$weight = (float) ($weights[ $key ] ?? 0.0);
			$debug_components[ $key ] = array(
				'raw_score' => round((float) $raw_score, 4),
				'weight' => $weight,
				'weighted_score' => round(((float) $raw_score) * ($weight / 100.0), 4),
			);
		}

		$distance_km = $this->distance_km_from_origin($post_id, $origin_lat, $origin_lng);

		return rest_ensure_response(
			array(
				'id' => $post_id,
				'title' => (string) get_the_title($post_id),
				'origin' => array(
					'lat' => null !== $origin_lat ? $origin_lat : null,
					'lng' => null !== $origin_lng ? $origin_lng : null,
				),
				'distance_km' => null !== $distance_km ? round($distance_km, 4) : null,
				'components' => $debug_components,
				'final_score' => round($score, 4),
				'generated_at' => gmdate('c'),
			)
		);
	}

	private function query_spots_for_output(array $tax_query, string $sort_by, int $page, int $per_page, ?float $origin_lat, ?float $origin_lng): array {
		$cache_key = $this->build_query_cache_key(
			array(
				'tax_query' => $tax_query,
				'sort_by' => $sort_by,
				'page' => $page,
				'per_page' => $per_page,
				'lat' => $origin_lat,
				'lng' => $origin_lng,
			)
		);
		$cached = get_transient($cache_key);
		if (is_array($cached) && isset($cached['post_ids']) && is_array($cached['post_ids'])) {
			return $this->hydrate_cached_query_result($cached);
		}

		if ('relevance' === $sort_by) {
			$result = $this->query_weighted_relevance_spots($tax_query, $page, $per_page, $origin_lat, $origin_lng);
		} else {
			$args = array(
				'post_type'      => DDB_Spots_Core_Schema::POST_TYPE,
				'post_status'    => 'publish',
				'paged'          => $page,
				'posts_per_page' => $per_page,
			);
			if (! empty($tax_query)) {
				$args['tax_query'] = $tax_query;
			}

			if ('date' === $sort_by) {
				$args['meta_key'] = DDB_Spots_Core_Schema::META['event_date'];
				$args['meta_type'] = 'DATETIME';
				$args['orderby'] = 'meta_value';
				$args['order'] = 'ASC';
			}

			$q = new WP_Query($args);
			$result = array(
				'posts' => $q->posts,
				'total_items' => (int) $q->found_posts,
				'total_pages' => max(1, (int) $q->max_num_pages),
			);
		}

		$payload = array(
			'post_ids' => array_values(
				array_map(
					static function (WP_Post $post): int {
						return (int) $post->ID;
					},
					$result['posts']
				)
			),
			'total_items' => (int) $result['total_items'],
			'total_pages' => (int) $result['total_pages'],
		);
		set_transient($cache_key, $payload, DDB_Spots_Core_Schema::CACHE_TTL);
		return $result;
	}

	private function build_query_cache_key(array $params): string {
		$version = (int) get_option(DDB_Spots_Core_Schema::CACHE_VERSION_OPTION, 1);
		$payload = wp_json_encode(
			array(
				'version' => max(1, $version),
				'params' => $params,
			)
		);
		return 'ddb_spots_q_' . md5((string) $payload);
	}

	private function hydrate_cached_query_result(array $cached): array {
		$post_ids = array_values(array_filter(array_map('absint', $cached['post_ids'] ?? array())));
		if (empty($post_ids)) {
			return array(
				'posts' => array(),
				'total_items' => (int) ($cached['total_items'] ?? 0),
				'total_pages' => max(1, (int) ($cached['total_pages'] ?? 1)),
			);
		}

		$posts = get_posts(
			array(
				'post_type' => DDB_Spots_Core_Schema::POST_TYPE,
				'post_status' => 'publish',
				'post__in' => $post_ids,
				'posts_per_page' => count($post_ids),
				'orderby' => 'post__in',
			)
		);

		return array(
			'posts' => $posts,
			'total_items' => (int) ($cached['total_items'] ?? count($posts)),
			'total_pages' => max(1, (int) ($cached['total_pages'] ?? 1)),
		);
	}

	private function query_weighted_relevance_spots(array $tax_query, int $page, int $per_page, ?float $origin_lat, ?float $origin_lng): array {
		$all_ids = get_posts(
			array(
				'post_type' => DDB_Spots_Core_Schema::POST_TYPE,
				'post_status' => 'publish',
				'posts_per_page' => -1,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'DESC',
				'tax_query' => empty($tax_query) ? array() : $tax_query,
			)
		);
		$sorted_ids = $this->sort_ids_by_weighted_relevance(array_map('absint', $all_ids), $origin_lat, $origin_lng);
		$total_items = count($sorted_ids);
		$total_pages = max(1, (int) ceil($total_items / max(1, $per_page)));
		$paged_ids = array_slice($sorted_ids, max(0, ($page - 1) * $per_page), $per_page);
		if (empty($paged_ids)) {
			return array('posts' => array(), 'total_items' => $total_items, 'total_pages' => $total_pages);
		}

		$posts = get_posts(
			array(
				'post_type' => DDB_Spots_Core_Schema::POST_TYPE,
				'post_status' => 'publish',
				'posts_per_page' => count($paged_ids),
				'post__in' => $paged_ids,
				'orderby' => 'post__in',
			)
		);

		return array(
			'posts' => $posts,
			'total_items' => $total_items,
			'total_pages' => $total_pages,
		);
	}

	private function sort_ids_by_weighted_relevance(array $post_ids, ?float $origin_lat, ?float $origin_lng): array {
		if (count($post_ids) < 2) {
			return $post_ids;
		}

		$config = DDB_Spots_Admin_Settings_Page::get_config();
		$weights = $this->get_ranking_weights($config);
		$scores = array();
		foreach ($post_ids as $post_id) {
			$scores[ $post_id ] = $this->calculate_weighted_score((int) $post_id, $weights, $origin_lat, $origin_lng);
		}

		usort(
			$post_ids,
			function (int $a, int $b) use ($scores): int {
				$score_a = $scores[ $a ] ?? 0.0;
				$score_b = $scores[ $b ] ?? 0.0;
				if ($score_a === $score_b) {
					$title_a = (string) get_the_title($a);
					$title_b = (string) get_the_title($b);
					return strcasecmp($title_a, $title_b);
				}
				return ($score_b <=> $score_a);
			}
		);

		return $post_ids;
	}

	private function get_ranking_weights(array $config): array {
		$defaults = array(
			'distance' => 30.0,
			'availability' => 25.0,
			'premium' => 25.0,
			'manual_priority' => 20.0,
		);
		$saved = isset($config['ranking_visibility']['weights']) && is_array($config['ranking_visibility']['weights']) ? $config['ranking_visibility']['weights'] : array();
		foreach ($defaults as $key => $value) {
			if (isset($saved[ $key ])) {
				$defaults[ $key ] = max(0.0, min(100.0, (float) $saved[ $key ]));
			}
		}
		return $defaults;
	}

	private function calculate_weighted_score(int $post_id, array $weights, ?float $origin_lat, ?float $origin_lng): float {
		$components = $this->get_ranking_components($post_id, $origin_lat, $origin_lng);
		return $this->calculate_weighted_average($components, $weights);
	}

	private function get_ranking_components(int $post_id, ?float $origin_lat, ?float $origin_lng): array {
		return array(
			'distance' => $this->distance_component_score($post_id, $origin_lat, $origin_lng),
			'availability' => $this->availability_component_score($post_id),
			'premium' => $this->premium_component_score($post_id),
			'manual_priority' => $this->manual_priority_component_score($post_id),
		);
	}

	private function calculate_weighted_average(array $components, array $weights): float {
		$total_weight = 0.0;
		$total_score = 0.0;
		foreach ($components as $key => $value) {
			$weight = (float) ($weights[ $key ] ?? 0.0);
			if ($weight <= 0.0) {
				continue;
			}
			$total_weight += $weight;
			$total_score += ($value * $weight);
		}

		if ($total_weight <= 0.0) {
			return 0.0;
		}
		return $total_score / $total_weight;
	}

	private function distance_km_from_origin(int $post_id, ?float $origin_lat, ?float $origin_lng): ?float {
		if (null === $origin_lat || null === $origin_lng) {
			return null;
		}

		$lat = $this->to_float_or_null((string) get_post_meta($post_id, DDB_Spots_Core_Schema::META['lat'], true));
		$lng = $this->to_float_or_null((string) get_post_meta($post_id, DDB_Spots_Core_Schema::META['lng'], true));
		if (null === $lat || null === $lng) {
			return null;
		}

		return $this->haversine_km($origin_lat, $origin_lng, $lat, $lng);
	}

	private function distance_component_score(int $post_id, ?float $origin_lat, ?float $origin_lng): float {
		if (null === $origin_lat || null === $origin_lng) {
			return 50.0;
		}
		$distance_km = $this->distance_km_from_origin($post_id, $origin_lat, $origin_lng);
		if (null === $distance_km) {
			return 0.0;
		}
		$score = 100.0 - (2.0 * $distance_km);
		return max(0.0, min(100.0, $score));
	}

	private function availability_component_score(int $post_id): float {
		$url = $this->get_cta_url_by_type($post_id, 'api');
		return '' !== trim($url) ? 100.0 : 0.0;
	}

	private function premium_component_score(int $post_id): float {
		$premium_meta = (string) get_post_meta($post_id, '_ddb_is_premium', true);
		if ('1' === $premium_meta) {
			return 100.0;
		}
		$tags = wp_get_post_terms($post_id, DDB_Spots_Core_Schema::TAX['tag'], array('fields' => 'slugs'));
		if (! is_wp_error($tags) && in_array('premium', array_map('strval', $tags), true)) {
			return 100.0;
		}
		return 0.0;
	}

	private function manual_priority_component_score(int $post_id): float {
		$priority = (float) get_post_meta($post_id, DDB_Spots_Core_Schema::META['priority'], true);
		return max(0.0, min(100.0, $priority));
	}

	private function to_float_or_null(string $value): ?float {
		$value = trim(str_replace(',', '.', $value));
		if ('' === $value || ! is_numeric($value)) {
			return null;
		}
		return (float) $value;
	}

	private function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
		$earth_radius = 6371.0;
		$d_lat = deg2rad($lat2 - $lat1);
		$d_lng = deg2rad($lng2 - $lng1);
		$a = sin($d_lat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($d_lng / 2) ** 2;
		$c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
		return $earth_radius * $c;
	}

	private function build_spot_payload(int $id): array {
		return array(
			'id'         => $id,
			'title'      => get_the_title($id),
			'excerpt'    => get_the_excerpt($id),
			'permalink'  => get_permalink($id),
			'types'      => wp_get_post_terms($id, DDB_Spots_Core_Schema::TAX['type'], array('fields' => 'slugs')),
			'areas'      => wp_get_post_terms($id, DDB_Spots_Core_Schema::TAX['area'], array('fields' => 'slugs')),
			'tags'       => wp_get_post_terms($id, DDB_Spots_Core_Schema::TAX['tag'], array('fields' => 'slugs')),
			'categories' => wp_get_post_terms($id, DDB_Spots_Core_Schema::TAX['category'], array('fields' => 'slugs')),
				'meta'       => array(
					'source'                 => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['source'], true),
					'google_place_id'        => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_place_id'], true),
					'google_last_synced_at'  => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_last_synced_at'], true),
					'google_opening_hours'   => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_opening_hours'], true),
					'google_opening_periods_json' => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_opening_periods_json'], true),
					'google_phone'           => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_phone'], true),
					'google_website'         => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_website'], true),
					'google_maps_url'        => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_maps_url'], true),
					'google_rating'          => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_rating'], true),
					'google_user_ratings_total' => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_user_ratings_total'], true),
					'google_business_status' => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_business_status'], true),
					'google_editorial_summary' => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_editorial_summary'], true),
					'google_reviews_json'    => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_reviews_json'], true),
					'google_place_types_json' => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_place_types_json'], true),
					'address'                => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['address'], true),
					'city'                   => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['city'], true),
					'region'                 => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['region'], true),
					'country'                => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['country'], true),
					'lat'                    => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['lat'], true),
					'lng'                    => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['lng'], true),
					'google_photo_refs_json' => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_photo_refs_json'], true),
					'google_photo_media_map_json' => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_photo_media_map_json'], true),
					'google_attribution_json' => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['google_attribution_json'], true),
					'event_date'             => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['event_date'], true),
					'event_ticket_url'       => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['event_ticket_url'], true),
					'hotel_booking_url'      => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['hotel_booking_url'], true),
					'restaurant_booking_url' => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['restaurant_booking_url'], true),
					'formitable_venue_id'    => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['formitable_venue_id'], true),
					'formitable_widget_id'   => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['formitable_widget_id'], true),
					'booking_provider'       => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['booking_provider'], true),
					'cta_url'                => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['cta_url'], true),
					'duration_hint'          => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['duration_hint'], true),
					'best_time_slot'         => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['best_time_slot'], true),
					'weather_compatibility'  => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['weather_compatibility'], true),
					'group_fit_score'        => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['group_fit_score'], true),
					'walk_distance_to_core'  => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['walk_distance_to_core'], true),
					'suitability_json'       => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['suitability_json'], true),
					'near_spots_json'        => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['near_spots_json'], true),
					'bundles_json'           => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['bundles_json'], true),
					'informational_only'     => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['informational_only'], true),
					'lock_title'             => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['lock_title'], true),
					'lock_excerpt'           => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['lock_excerpt'], true),
					'lock_cta'               => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['lock_cta'], true),
					'lock_location'          => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['lock_location'], true),
					'lock_contact'           => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['lock_contact'], true),
					'lock_hours'             => (string) get_post_meta($id, DDB_Spots_Core_Schema::META['lock_hours'], true),
				),
			'cta'        => array('label' => $this->get_cta_label_by_type($id), 'url' => $this->get_cta_url_by_type($id, 'api')),
		);
	}

	private function ensure_frontend_assets(): void {
		if ($this->frontend_assets_ensured) {
			return;
		}

		$this->frontend_assets_ensured = true;
		$this->enqueue_frontend_assets();
	}

	private function rest_route_exists(string $route): bool {
		if (! function_exists('rest_get_server')) {
			return false;
		}

		$route = '/' . ltrim($route, '/');
		$server = rest_get_server();
		if (! $server) {
			return false;
		}

		$routes = $server->get_routes();
		return isset($routes[ $route ]);
	}

	public function enqueue_frontend_assets(): void {
		$post = get_post();
		$is_archive = is_post_type_archive(DDB_Spots_Core_Schema::POST_TYPE);
		$has_shortcode_spots = false;
		$has_shortcode_cta = false;
		$has_shortcode_widget = false;

		if ($post instanceof WP_Post) {
			$has_shortcode_spots = has_shortcode($post->post_content, 'ddb_spots') || has_shortcode($post->post_content, 'ddb_spots_featured');
			$has_shortcode_cta = has_shortcode($post->post_content, 'ddb_spot_cta');
			$has_shortcode_widget = has_shortcode($post->post_content, 'ddb_restaurant_widget');
			if (! $has_shortcode_spots) {
				$has_shortcode_spots = $this->elementor_document_contains_shortcode((int) $post->ID, 'ddb_spots')
					|| $this->elementor_document_contains_shortcode((int) $post->ID, 'ddb_spots_featured');
			}
			if (! $has_shortcode_cta) {
				$has_shortcode_cta = $this->elementor_document_contains_shortcode((int) $post->ID, 'ddb_spot_cta');
			}
			if (! $has_shortcode_widget) {
				$has_shortcode_widget = $this->elementor_document_contains_shortcode((int) $post->ID, 'ddb_restaurant_widget');
			}
		}

		if ($is_archive || is_singular(DDB_Spots_Core_Schema::POST_TYPE) || $has_shortcode_spots || $has_shortcode_cta || $has_shortcode_widget) {
			$spots_css_path = DDB_SPOTS_PATH . 'assets/css/ddb-spots.css';
			$spots_css_version = file_exists($spots_css_path) ? (string) filemtime($spots_css_path) : DDB_SPOTS_VERSION;

			// Ensure design-system listing card CSS loads (oled-card.php uses ui-listing-card__* BEM classes)
			if ($has_shortcode_spots || $is_archive) {
				wp_enqueue_style('ddb-core-ui-listing-cards');
			}
			// Base core styles
			wp_enqueue_style('ddb-spots-core', DDB_SPOTS_URL . 'assets/css/ddb-spots.css', array('ddb-core-ui', 'ddb-core-ui-listing-cards'), $spots_css_version);
			wp_enqueue_script('ddb-spots-core', DDB_SPOTS_URL . 'assets/js/ddb-spots.js', array(), DDB_SPOTS_VERSION, true);
			
			// Component specific styles (if files existed or were split)
			if (is_singular(DDB_Spots_Core_Schema::POST_TYPE)) {
				$single_css_path = DDB_SPOTS_PATH . 'assets/css/ddb-spots-single.css';
				if (file_exists($single_css_path)) {
					wp_enqueue_style('ddb-spots-single', DDB_SPOTS_URL . 'assets/css/ddb-spots-single.css', array('ddb-spots-core'), (string) filemtime($single_css_path));
				}
			}

			if ($has_shortcode_widget) {
				wp_enqueue_style('ddb-spots-widget', DDB_SPOTS_URL . 'assets/css/ddb-spots-widget.css', array('ddb-spots-core'), DDB_SPOTS_VERSION);
			}

			$events_endpoint = $this->rest_route_exists('/dbspots/v1/events') ? rest_url('dbspots/v1/events') : '';
			$legacy_events_endpoint = $this->rest_route_exists('/ddb/v1/events') ? rest_url('ddb/v1/events') : '';
			$can_track_events = current_user_can('edit_posts') || current_user_can(DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE);
			
			wp_localize_script(
				'ddb-spots-core',
				'ddbSpotsFrontend',
				array(
					'apiRoot' => esc_url_raw(rest_url('dbspots/v1/')),
					'premiumApiRoot' => esc_url_raw(rest_url('ddb/v1/')),
					'eventsEndpoint' => esc_url_raw($events_endpoint),
					'legacyEventsEndpoint' => esc_url_raw($legacy_events_endpoint),
					'canTrackEvents' => $can_track_events,
					'restNonce' => wp_create_nonce('wp_rest'),
				)
			);
		}
	}

	private function elementor_document_contains_shortcode(int $post_id, string $shortcode): bool {
		if ($post_id <= 0 || '' === $shortcode) {
			return false;
		}

		$raw_data = get_post_meta($post_id, '_elementor_data', true);
		if (! is_string($raw_data) || '' === $raw_data) {
			return false;
		}

		return false !== strpos($raw_data, '[' . $shortcode);
	}

	public function enqueue_admin_assets(string $hook): void {
		if (! in_array($hook, array('post.php', 'post-new.php'), true)) {
			return;
		}
		$screen = get_current_screen();
		if (! $screen || DDB_Spots_Core_Schema::POST_TYPE !== $screen->post_type) {
			return;
		}
		wp_enqueue_script('ddb-spots-admin', DDB_SPOTS_URL . 'assets/js/ddb-spots-admin.js', array(), DDB_SPOTS_VERSION, true);
	}

	public function append_single_spot_content(string $content): string {
		if (! is_singular(DDB_Spots_Core_Schema::POST_TYPE) || ! in_the_loop() || ! is_main_query()) {
			return $content;
		}
		$id = get_the_ID();
		if (! $id) {
			return $content;
		}
		$blocks = array();
		if ($this->spot_is_restaurant($id)) {
			$widget = $this->get_restaurant_widget_markup($id);
			if ('' !== $widget) {
				$blocks[] = '<section class="ddb-spot-detail__widget" id="restaurant-widget">' . $widget . '</section>';
			}
		}
		$cta = $this->get_cta_markup($id, 'single');
		if ('' !== $cta) {
			$blocks[] = '<section class="ddb-spot-detail__cta">' . $cta . '</section>';
		}
		$blocks[] = '<section class="ddb-spot-detail__bundles"><h3>' . esc_html__('Bundels', 'ddb-spots') . '</h3><div class="ddb-bundles-placeholder">' . esc_html__('Bundel rendering placeholder (v2).', 'ddb-spots') . '</div></section>';
		return $content . '<div class="ddb-spot-detail">' . implode('', $blocks) . '</div>';
	}

	public function maybe_override_archive_template(string $template): string {
		if (! is_post_type_archive(DDB_Spots_Core_Schema::POST_TYPE)) {
			return $template;
		}

		$override = DDB_SPOTS_PATH . 'templates/archive-ddb-spot.php';
		if (file_exists($override)) {
			return $override;
		}

		return $template;
	}

	public function maybe_render_archive_fallback(): void {
		if (! is_post_type_archive(DDB_Spots_Core_Schema::POST_TYPE) || is_admin() || is_feed() || is_embed()) {
			return;
		}

		$override = DDB_SPOTS_PATH . 'templates/archive-ddb-spot.php';
		if (! file_exists($override)) {
			return;
		}

		status_header(200);
		include $override;
		exit;
	}

	private function get_primary_type_slug(int $id): string {
		$override = sanitize_title((string) get_post_meta($id, DDB_Spots_Core_Schema::META['spot_type_primary'], true));
		if ('' !== $override) {
			return $override;
		}
		$terms = wp_get_post_terms($id, DDB_Spots_Core_Schema::TAX['type'], array('fields' => 'slugs'));
		if (is_wp_error($terms) || empty($terms)) {
			return '';
		}
		return (string) $terms[0];
	}

	private function spot_is_restaurant(int $id): bool {
		return in_array($this->get_primary_type_slug($id), array('restaurant', 'restaurants'), true);
	}

	private function get_cta_url_by_type(int $id, string $context = 'generic'): string {
		$type = $this->get_primary_type_slug($id);
		$provider = (string) get_post_meta($id, DDB_Spots_Core_Schema::META['booking_provider'], true);
		$direct_cta = (string) get_post_meta($id, DDB_Spots_Core_Schema::META['cta_url'], true);
		if (in_array($type, array('event', 'events'), true)) {
			if (in_array($provider, array('external', 'ticket'), true) && '' !== $direct_cta) {
				return $direct_cta;
			}
			return (string) get_post_meta($id, DDB_Spots_Core_Schema::META['event_ticket_url'], true);
		}
		if (in_array($type, array('hotel', 'hotels'), true)) {
			if (in_array($provider, array('external', 'ticket'), true) && '' !== $direct_cta) {
				return $direct_cta;
			}
			return (string) get_post_meta($id, DDB_Spots_Core_Schema::META['hotel_booking_url'], true);
		}
		if (in_array($type, array('restaurant', 'restaurants'), true)) {
			if (in_array($provider, array('external', 'ticket'), true) && '' !== $direct_cta) {
				return $direct_cta;
			}
			if ('formitable' === $provider) {
				$has_embed = '' !== (string) get_post_meta($id, DDB_Spots_Core_Schema::META['formitable_embed'], true) || '' !== (string) get_post_meta($id, DDB_Spots_Core_Schema::META['formitable_embed_raw'], true);
				$has_ids = '' !== (string) get_post_meta($id, DDB_Spots_Core_Schema::META['formitable_venue_id'], true) || '' !== (string) get_post_meta($id, DDB_Spots_Core_Schema::META['formitable_widget_id'], true);
				if ($has_embed || $has_ids) {
					if ('single' === $context && is_singular(DDB_Spots_Core_Schema::POST_TYPE) && (int) get_queried_object_id() === $id) {
						return '#restaurant-widget';
					}
					$permalink = (string) get_permalink($id);
					return '' !== $permalink ? $permalink . '#restaurant-widget' : '#restaurant-widget';
				}
				return '';
			}
			$url = (string) get_post_meta($id, DDB_Spots_Core_Schema::META['restaurant_booking_url'], true);
			if ('' !== $url) {
				return $url;
			}
			$has_raw = '' !== (string) get_post_meta($id, DDB_Spots_Core_Schema::META['formitable_embed_raw'], true);
			$has_ids = '' !== (string) get_post_meta($id, DDB_Spots_Core_Schema::META['formitable_venue_id'], true) || '' !== (string) get_post_meta($id, DDB_Spots_Core_Schema::META['formitable_widget_id'], true);
			if ($has_raw || $has_ids) {
				if ('single' === $context && is_singular(DDB_Spots_Core_Schema::POST_TYPE) && (int) get_queried_object_id() === $id) {
					return '#restaurant-widget';
				}
				$permalink = (string) get_permalink($id);
				return '' !== $permalink ? $permalink . '#restaurant-widget' : '#restaurant-widget';
			}
		}
		if ('' !== $direct_cta) {
			return $direct_cta;
		}
		return (string) get_post_meta($id, DDB_Spots_Core_Schema::META['generic_cta_url'], true);
	}

	private function get_cta_label_by_type(int $id): string {
		$type = $this->get_primary_type_slug($id);
		if (in_array($type, array('event', 'events'), true)) {
			return __('Koop tickets', 'ddb-spots');
		}
		if (in_array($type, array('hotel', 'hotels'), true)) {
			return __('Boek hotel', 'ddb-spots');
		}
		if (in_array($type, array('restaurant', 'restaurants'), true)) {
			return __('Reserveer tafel', 'ddb-spots');
		}
		return __('Bekijk details', 'ddb-spots');
	}

	private function get_cta_markup(int $id, string $context, string $button_variant = 'secondary'): string {
		$url = $this->get_cta_url_by_type($id, $context);
		if ('' === $url) {
			return '';
		}
		$type = $this->get_primary_type_slug($id);
		$cta_type = $this->get_cta_tracking_type($id, $type, $url);
		$is_widget_anchor = '#restaurant-widget' === $url || str_ends_with($url, '#restaurant-widget');
		$href = '#restaurant-widget' === $url ? esc_attr($url) : esc_url($url);
		$target = $is_widget_anchor ? '' : ' target="_blank" rel="noopener noreferrer"';
		return sprintf(
			'<a class="ui-listing-card__cta ui-listing-card__cta--%9$s ddb-spot-cta-button ddb-spot-cta-button--%1$s" href="%2$s"%3$s data-ddb-track="cta_click" data-ddb-context="%4$s" data-ddb-spot-id="%5$s" data-ddb-spot-type="%6$s" data-ddb-cta-type="%7$s">%8$s</a>',
			esc_attr($type ?: 'generic'),
			$href,
			$target,
			esc_attr(sanitize_html_class($context)),
			esc_attr((string) $id),
			esc_attr($type),
			esc_attr($cta_type),
			esc_html($this->get_cta_label_by_type($id)),
			esc_attr(in_array($button_variant, array('primary', 'secondary'), true) ? $button_variant : 'secondary')
		);
	}

	private function get_cta_tracking_type(int $id, string $type, string $url): string {
		if ('#restaurant-widget' === $url || str_ends_with($url, '#restaurant-widget')) {
			return 'widget';
		}
		$provider = sanitize_key((string) get_post_meta($id, DDB_Spots_Core_Schema::META['booking_provider'], true));
		if (in_array($type, array('event', 'events'), true)) {
			return 'ticket';
		}
		if (in_array($type, array('hotel', 'hotels'), true)) {
			return 'hotel_booking';
		}
		if (in_array($type, array('restaurant', 'restaurants'), true)) {
			return '' !== $provider ? $provider : 'restaurant';
		}
		return 'generic';
	}

	private function render_premium_labels_html(int $spot_id, string $context): string {
		if (! function_exists('ddb_spots_get_spot_plan_info')) {
			return '';
		}
		$plan = ddb_spots_get_spot_plan_info($spot_id);
		$entitlements = isset($plan['entitlements']) && is_array($plan['entitlements']) ? $plan['entitlements'] : array();
		$is_top_pick = function_exists('ddb_spots_is_top_pick_active') ? ddb_spots_is_top_pick_active($spot_id) : false;
		$is_sponsored = ! empty($plan['is_paid_active']) && ! empty($entitlements['sponsored']);
		if (! $is_top_pick && ! $is_sponsored) {
			return '';
		}

		$items = array();
		if ($is_top_pick) {
			$items[] = '<li class="ddb-spot-label ddb-spot-label--top-pick">' . esc_html__('Top Pick', 'ddb-spots') . '</li>';
		}
		if ($is_sponsored) {
			$items[] = '<li class="ddb-spot-label ddb-spot-label--sponsored">' . esc_html__('Gesponsord', 'ddb-spots') . '</li>';
		}

		return '<ul class="ddb-spot-labels ddb-spot-labels--' . esc_attr(sanitize_html_class($context)) . '">' . implode('', $items) . '</ul>';
	}

	private function get_restaurant_widget_markup(int $id): string {
		if (! $this->spot_is_restaurant($id)) {
			return '';
		}
		$provider = (string) get_post_meta($id, DDB_Spots_Core_Schema::META['booking_provider'], true);
		if ('formitable' !== $provider) {
			return '';
		}
		$raw = (string) get_post_meta($id, DDB_Spots_Core_Schema::META['formitable_embed'], true);
		if ('' === $raw) {
			$raw = (string) get_post_meta($id, DDB_Spots_Core_Schema::META['formitable_embed_raw'], true);
		}
		if ('' !== $raw) {
			return '<div class="ddb-formitable-embed">' . wp_kses($raw, $this->get_formitable_allowed_tags()) . '</div>';
		}
		$venue = (string) get_post_meta($id, DDB_Spots_Core_Schema::META['formitable_venue_id'], true);
		$widget = (string) get_post_meta($id, DDB_Spots_Core_Schema::META['formitable_widget_id'], true);
		if ('' === $venue && '' === $widget) {
			return '';
		}
		return sprintf(
			'<div class="ddb-formitable-placeholder" data-venue-id="%1$s" data-widget-id="%2$s">%3$s</div>',
			esc_attr($venue),
			esc_attr($widget),
			esc_html__('Formitable widget placeholder (ID-based renderer v2).', 'ddb-spots')
		);
	}

	/**
	 * Render a compact featured spots section for the homepage.
	 *
	 * Usage: [ddb_spots_featured ids="1,2,3" limit="3" style="compact"]
	 *
	 * @param array<string,string> $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function render_featured_spots_shortcode(array $atts = array()): string {
		$atts = shortcode_atts(
			array(
				'ids'   => '',
				'limit' => '3',
				'style' => 'compact',
			),
			$atts,
			'ddb_spots_featured'
		);

		$limit   = min(12, max(1, absint($atts['limit'])));
		$raw_ids = array_filter(array_map('absint', explode(',', (string) $atts['ids'])));

		if (! empty($raw_ids)) {
			$args = array(
				'post_type'           => DDB_Spots_Core_Schema::POST_TYPE,
				'post__in'            => $raw_ids,
				'posts_per_page'      => count($raw_ids),
				'orderby'             => 'post__in',
				'post_status'         => 'publish',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			);
		} else {
			$args = array(
				'post_type'           => DDB_Spots_Core_Schema::POST_TYPE,
				'posts_per_page'      => $limit,
				'post_status'         => 'publish',
				'orderby'             => 'meta_value_num',
				'order'               => 'DESC',
				'meta_key'            => '_ddb_ranking_score',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			);
		}

		$query = new WP_Query($args);
		$posts = $query->posts;

		if (empty($posts)) {
			return '';
		}

		$style = sanitize_html_class($atts['style']);
		$this->ensure_frontend_assets();

		ob_start();
		?>
		<div class="ddb-hp-spots ddb-hp-spots--<?php echo esc_attr($style); ?>" aria-label="<?php echo esc_attr__('Uitgelichte plekken', 'ddb-spots'); ?>">
			<?php foreach ($posts as $post) :
				if (! $post instanceof WP_Post) {
					continue;
				}
				$id        = (int) $post->ID;
				$title     = get_the_title($id);
				$permalink = get_permalink($id);
				$type_label = $this->get_primary_term_label($id, DDB_Spots_Core_Schema::TAX['type']);
				$area_label = $this->get_primary_term_label($id, DDB_Spots_Core_Schema::TAX['area']);
				$eyebrow    = '' !== $type_label ? $type_label : $area_label;
				$image_html = $this->get_spot_primary_image_html($id);
				?>
			<article class="ui-listing-card ui-listing-card--compact ddb-card ddb-card--compact ddb-hp-spot-card" data-ddb-spot-id="<?php echo esc_attr((string) $id); ?>">
				<a class="ddb-card__link" href="<?php echo esc_url($permalink); ?>">
					<?php echo $image_html; ?>
					<div class="ddb-spot-card__body">
						<header class="ui-listing-card__header ddb-spot-card__header">
							<div class="ui-listing-card__header-main">
								<?php if ('' !== $eyebrow) : ?>
									<p class="ui-listing-card__eyebrow"><?php echo esc_html($eyebrow); ?></p>
								<?php endif; ?>
								<h3 class="ui-listing-card__title ddb-card__title"><?php echo esc_html($title); ?></h3>
							</div>
						</header>
					</div>
				</a>
				<div class="ui-listing-card__actions ddb-spot-card__cta">
					<a class="ui-listing-card__cta ui-listing-card__cta--primary ddb-spot-cta-button ddb-spot-cta-button--detail" href="<?php echo esc_url($permalink); ?>" data-ddb-track="card_click" data-ddb-context="hp_featured" data-ddb-spot-id="<?php echo esc_attr((string) $id); ?>">
						<?php esc_html_e('Bekijk plek', 'ddb-spots'); ?>
					</a>
				</div>
			</article>
			<?php endforeach; ?>
		</div>
		<?php
		wp_reset_postdata();

		return trim(ob_get_clean());
	}
}
