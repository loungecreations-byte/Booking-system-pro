<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Integrations_Google_Places {
	private const POST_TYPE = 'ddb_spot';
	private const PAGE_IMPORT = 'ddb-spots-google-import';
	private const REQUEST_TIME_BUDGET_SECONDS = 18;
	private const DEEP_IMPORT_STATE_OPTION = 'ddb_spots_deep_import_state';
	private const DEEP_IMPORT_LAST_COMPLETED_OPTION = 'ddb_spots_deep_import_last_completed';
	private const IMPORT_NOTICES_TRANSIENT_PREFIX = 'ddb_spots_import_notices_';
	private const PAGE_TOKEN_TRANSIENT_PREFIX = 'ddb_spots_page_token_';

	private array $notices = array();

	private function can_manage_import_screen(): bool {
		return current_user_can(DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE) || current_user_can('manage_options');
	}

	private function resolve_page_token_from_request(): string {
		$page_ref = isset($_GET['ddb_page_ref']) ? sanitize_key(wp_unslash((string) $_GET['ddb_page_ref'])) : '';
		if ('' !== $page_ref) {
			$stored = get_transient(self::PAGE_TOKEN_TRANSIENT_PREFIX . $page_ref);
			if (is_string($stored) && '' !== $stored) {
				return $stored;
			}
		}

		return isset($_GET['ddb_page_token']) ? sanitize_text_field(wp_unslash((string) $_GET['ddb_page_token'])) : '';
	}

	private function store_page_token(string $token): string {
		$token = sanitize_text_field($token);
		if ('' === $token) {
			return '';
		}

		$user_id = get_current_user_id();
		$key = substr(md5($user_id . '|' . $token), 0, 20);
		set_transient(self::PAGE_TOKEN_TRANSIENT_PREFIX . $key, $token, MINUTE_IN_SECONDS * 20);

		return $key;
	}

	public function init(): void {
		add_action('admin_menu', array($this, 'register_admin_pages'));
		add_action('admin_post_ddb_spots_sync_now', array($this, 'handle_sync_now_action'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
	}

	public function handle_sync_now_action(): void {
		$post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
		if ($post_id <= 0) {
			wp_die(esc_html__('No post ID', 'ddb-spots'));
		}
		if (! current_user_can('edit_post', $post_id)) {
			wp_die(esc_html__('Insufficient permissions', 'ddb-spots'));
		}
		check_admin_referer('ddb_spots_sync_now_' . $post_id);
		$result = $this->sync_post($post_id);
		$url = (string) get_edit_post_link($post_id, 'raw');
		if ('' === $url) {
			$url = add_query_arg(
				array(
					'post' => $post_id,
					'action' => 'edit',
				),
				admin_url('post.php')
			);
		}
		if (is_wp_error($result)) {
			DDB_Spots_Admin_Sync_Dashboard::log_event(
				'manual_sync',
				'error',
				array(
					'post_id' => $post_id,
					'source' => 'manual',
					'message' => $result->get_error_message(),
				)
			);
			$url = add_query_arg('ddb_error', rawurlencode($result->get_error_message()), $url);
		} else {
			DDB_Spots_Admin_Sync_Dashboard::log_event(
				'manual_sync',
				'success',
				array(
					'post_id' => $post_id,
					'source' => 'manual',
					'message' => __('Google sync succesvol.', 'ddb-spots'),
				)
			);
			$url = add_query_arg('ddb_success', '1', $url);
		}
		wp_safe_redirect($url);
		exit;
	}

	public function register_admin_pages(): void {
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			__('Import (Google Places)', 'ddb-spots'),
			__('Import (Google Places)', 'ddb-spots'),
			'manage_options',
			self::PAGE_IMPORT,
			array($this, 'render_import_page')
		);
	}

	public function enqueue_assets(string $hook): void {
		if ('ddb_spot_page_' . self::PAGE_IMPORT !== $hook) {
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

	public function render_import_page(): void {
		if (! $this->can_manage_import_screen()) {
			wp_die(esc_html__('Insufficient permissions', 'ddb-spots'));
		}

		$this->handle_import_actions();
		$this->consume_flash_notices();

		$default_city = (string) DDB_Spots_Admin_Settings_Page::get_value('data_sources.default_city', 'Den Bosch');
		$default_query = (string) DDB_Spots_Admin_Settings_Page::get_value('data_sources.default_query', "restaurants in 's-Hertogenbosch");
		$default_radius = (int) DDB_Spots_Admin_Settings_Page::get_value('data_sources.default_radius', 5000);
		$query = isset($_GET['ddb_query']) ? sanitize_text_field(wp_unslash((string) $_GET['ddb_query'])) : $default_query;
		$location = isset($_GET['ddb_location']) ? sanitize_text_field(wp_unslash((string) $_GET['ddb_location'])) : '';
		$radius = isset($_GET['ddb_radius']) ? absint($_GET['ddb_radius']) : $default_radius;
		$page_token = $this->resolve_page_token_from_request();
		$should_search = isset($_GET['ddb_search']) && '1' === (string) $_GET['ddb_search'];

		$results = array();
		$next_page_token = '';
		$error = '';
		$api_key = $this->get_api_key();
		if ('' === $api_key) {
			$error = __('Geen Google API key ingesteld in Settings (Engine) > Data Sources.', 'ddb-spots');
		} elseif ($should_search) {
			$search = $this->search_places($query, $location, $radius, $page_token);
			$results = $search['results'];
			$next_page_token = $search['next_page_token'];
			$error = $search['error'];
		}

		echo '<div class="wrap ddb-admin-ui ddb-admin-ui-wrap"><h1>' . esc_html__('DDB Spots Import (Google Places)', 'ddb-spots') . '</h1>';
		$this->render_notices();
		if ('' !== $error) {
			echo '<div class="notice notice-warning"><p>' . esc_html($error) . '</p></div>';
			if ('' !== $page_token) {
				$retry_args = array(
					'post_type' => self::POST_TYPE,
					'page' => self::PAGE_IMPORT,
					'ddb_search' => '1',
					'ddb_query' => $query,
					'ddb_location' => $location,
					'ddb_radius' => $radius,
					'ddb_page_ref' => $this->store_page_token($page_token),
				);
				$retry_url = add_query_arg($retry_args, admin_url('edit.php'));
				echo '<div class="notice notice-info ddb-google-import-retry" data-retry-url="' . esc_url($retry_url) . '" data-retry-delay="4"><p>' . esc_html__('Automatisch opnieuw proberen over een paar seconden…', 'ddb-spots') . '</p></div>';
				echo '<p><a class="button button-secondary" href="' . esc_url($retry_url) . '">' . esc_html__('Retry next page', 'ddb-spots') . '</a></p>';
			}
		}

		echo '<form method="get">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr(self::POST_TYPE) . '" />';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_IMPORT) . '" />';
		echo '<input type="hidden" name="ddb_search" value="1" />';
		echo '<table class="form-table"><tr><th><label for="ddb_query">' . esc_html__('Query', 'ddb-spots') . '</label></th><td><input class="regular-text" type="text" id="ddb_query" name="ddb_query" value="' . esc_attr($query) . '" /></td></tr>';
		echo '<tr><th><label for="ddb_location">' . esc_html__('Location (lat,lng)', 'ddb-spots') . '</label></th><td><input class="regular-text" type="text" id="ddb_location" name="ddb_location" value="' . esc_attr($location) . '" /></td></tr>';
		echo '<tr><th><label for="ddb_radius">' . esc_html__('Radius (m)', 'ddb-spots') . '</label></th><td><input class="small-text" type="number" min="0" id="ddb_radius" name="ddb_radius" value="' . esc_attr((string) $radius) . '" /></td></tr></table>';
		submit_button(__('Search', 'ddb-spots'), 'secondary', '', false);
		echo '</form>';

		$deep_max = (int) DDB_Spots_Admin_Settings_Page::get_value('data_sources.deep_import_max_places', 180);
		$deep_radius = (int) DDB_Spots_Admin_Settings_Page::get_value('data_sources.deep_import_radius', 5000);
		$deep_state = $this->get_deep_import_state();
		$queue_pending = isset($deep_state['pending_place_ids']) && is_array($deep_state['pending_place_ids']) ? count($deep_state['pending_place_ids']) : 0;
		$queue_total = isset($deep_state['total_found']) ? (int) $deep_state['total_found'] : 0;
		echo '<hr style="margin: 20px 0;" />';
		echo '<h2>' . esc_html__('Deep Import', 'ddb-spots') . '</h2>';
		echo '<p>' . esc_html(sprintf(__('Runs multiple queries and imports up to %1$d places (radius %2$dm).', 'ddb-spots'), $deep_max, $deep_radius)) . '</p>';
		if ($queue_pending > 0) {
			echo '<p><strong>' . esc_html(sprintf(__('Queue actief: %1$d van %2$d resterend.', 'ddb-spots'), $queue_pending, max($queue_total, $queue_pending))) . '</strong></p>';
		}
		$spots_url = add_query_arg(
			array(
				'post_type' => self::POST_TYPE,
			),
			admin_url('edit.php')
		);
		$restaurants_url = add_query_arg(
			array(
				'post_type' => self::POST_TYPE,
				DDB_Spots_Core_Schema::TAX['type'] => 'restaurant',
			),
			admin_url('edit.php')
		);
		$restaurant_term = get_term_by('slug', 'restaurant', DDB_Spots_Core_Schema::TAX['type']);
		$restaurant_count = ($restaurant_term instanceof WP_Term) ? (int) $restaurant_term->count : 0;
		echo '<p><a class="button" href="' . esc_url($spots_url) . '">' . esc_html__('Open All Imported Spots', 'ddb-spots') . '</a></p>';
		echo '<p><a class="button" href="' . esc_url($restaurants_url) . '">' . esc_html__('Open Imported Restaurants', 'ddb-spots') . '</a> <span style="margin-left:8px;">' . esc_html(sprintf(__('Huidig lokaal totaal: %d', 'ddb-spots'), $restaurant_count)) . '</span></p>';
		echo '<form method="post">';
		wp_nonce_field('ddb_spots_deep_import');
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="ddb_deep_city">' . esc_html__('City', 'ddb-spots') . '</label></th><td><input class="regular-text" type="text" id="ddb_deep_city" name="ddb_deep_city" value="' . esc_attr($default_city) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__('Auto-sync', 'ddb-spots') . '</th><td><label><input type="checkbox" name="ddb_deep_autosync" value="1" checked /> ' . esc_html__('Enable autosync on imported spots', 'ddb-spots') . '</label></td></tr>';
		echo '<tr><th>' . esc_html__('Restart', 'ddb-spots') . '</th><td><label><input type="checkbox" name="ddb_deep_restart" value="1" /> ' . esc_html__('Force a new scan when previous queue is finished', 'ddb-spots') . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button(__('Run Deep Import', 'ddb-spots'), 'primary', 'ddb_deep_import', false);
		echo ' ';
		submit_button(__('Run Restaurant Import', 'ddb-spots'), 'secondary', 'ddb_deep_import_restaurants', false);
		echo '</form>';

		if (! empty($results)) {
			$this->render_results_table($results, $query, $location, $radius, $page_token, $next_page_token);
		}

		echo '</div>';
	}

	private function render_results_table(array $results, string $query, string $location, int $radius, string $page_token, string $next_page_token): void {
		$place_ids = array_map(
			static function (array $item): string {
				return isset($item['place_id']) ? (string) $item['place_id'] : '';
			},
			$results
		);
		$existing_map = $this->find_existing_posts_by_place_ids($place_ids);

		echo '<form method="post" class="ddb-google-import-results-form">';
		wp_nonce_field('ddb_spots_import_selected');
		echo '<input type="hidden" name="ddb_query" value="' . esc_attr($query) . '" />';
		echo '<input type="hidden" name="ddb_location" value="' . esc_attr($location) . '" />';
		echo '<input type="hidden" name="ddb_radius" value="' . esc_attr((string) $radius) . '" />';
		echo '<input type="hidden" name="ddb_page_token" value="' . esc_attr($page_token) . '" />';

		echo '<div class="tablenav top"><div class="alignleft actions">';
		echo '<button type="button" class="button ddb-select-all-places">' . esc_html__('Select all', 'ddb-spots') . '</button>';
		echo ' ';
		echo '<button type="button" class="button ddb-clear-all-places">' . esc_html__('Clear', 'ddb-spots') . '</button>';
		echo '</div></div>';
		echo '<table class="widefat striped ddb-google-import-results"><thead><tr>';
		echo '<th><label><input type="checkbox" class="ddb-select-all-toggle" aria-label="' . esc_attr__('Select all places', 'ddb-spots') . '" /> ' . esc_html__('Select', 'ddb-spots') . '</label></th>';
		echo '<th>' . esc_html__('Name', 'ddb-spots') . '</th>';
		echo '<th>' . esc_html__('Address', 'ddb-spots') . '</th>';
		echo '<th>' . esc_html__('Rating', 'ddb-spots') . '</th>';
		echo '<th>' . esc_html__('Place ID', 'ddb-spots') . '</th>';
		echo '<th>' . esc_html__('Existing', 'ddb-spots') . '</th>';
		echo '<th>' . esc_html__('Auto-sync', 'ddb-spots') . '</th>';
		echo '</tr></thead><tbody>';

		foreach ($results as $item) {
			$place_id = isset($item['place_id']) ? (string) $item['place_id'] : '';
			if ('' === $place_id) {
				continue;
			}
			$name = isset($item['name']) ? (string) $item['name'] : '';
			$address = isset($item['formatted_address']) ? (string) $item['formatted_address'] : '';
			$rating = isset($item['rating']) ? (string) $item['rating'] : '';
			$existing = isset($existing_map[ $place_id ]) ? (int) $existing_map[ $place_id ] : 0;

			echo '<tr>';
			echo '<td><input type="checkbox" class="ddb-place-select" name="ddb_place_ids[]" value="' . esc_attr($place_id) . '" /></td>';
			echo '<td>' . esc_html($name) . '</td>';
			echo '<td>' . esc_html($address) . '</td>';
			echo '<td>' . esc_html($rating) . '</td>';
			echo '<td><code>' . esc_html($place_id) . '</code></td>';
			echo '<td>';
			if ($existing > 0) {
				echo '<a href="' . esc_url((string) get_edit_post_link($existing)) . '">' . esc_html__('Update existing', 'ddb-spots') . '</a>';
			} else {
				echo esc_html__('Create new', 'ddb-spots');
			}
			echo '</td>';
			echo '<td><input type="checkbox" name="ddb_autosync[' . esc_attr($place_id) . ']" value="1" checked /></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p>';
		submit_button(__('Import selected', 'ddb-spots'), 'primary', 'ddb_import_selected', false);
		echo ' ';
		submit_button(__('Sync selected existing', 'ddb-spots'), 'secondary', 'ddb_refresh_selected', false);
		echo '</p>';
		echo '</form>';

		if ('' !== $next_page_token) {
			$page_ref = $this->store_page_token($next_page_token);
			$url = add_query_arg(
				array(
					'post_type' => self::POST_TYPE,
					'page' => self::PAGE_IMPORT,
					'ddb_search' => '1',
					'ddb_query' => $query,
					'ddb_location' => $location,
					'ddb_radius' => $radius,
					'ddb_page_ref' => $page_ref,
				),
				admin_url('edit.php')
			);
			echo '<p><a class="button" href="' . esc_url($url) . '">' . esc_html__('Next page', 'ddb-spots') . '</a></p>';
		}
	}

	private function handle_import_actions(): void {
		if (! isset($_SERVER['REQUEST_METHOD']) || 'POST' !== $_SERVER['REQUEST_METHOD']) {
			return;
		}
		if (! $this->can_manage_import_screen()) {
			return;
		}

		if (isset($_POST['ddb_deep_import']) || isset($_POST['ddb_deep_import_restaurants'])) {
			if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_wpnonce'])), 'ddb_spots_deep_import')) {
				return;
			}
			$city = isset($_POST['ddb_deep_city']) ? sanitize_text_field(wp_unslash((string) $_POST['ddb_deep_city'])) : (string) DDB_Spots_Admin_Settings_Page::get_value('data_sources.default_city', 'Den Bosch');
			$autosync = isset($_POST['ddb_deep_autosync']) ? '1' : '0';
			$force_restart = isset($_POST['ddb_deep_restart']);
			$is_restaurant_run = isset($_POST['ddb_deep_import_restaurants']);
			$summary = $is_restaurant_run
				? $this->run_deep_import($city, $autosync, $force_restart, $this->get_restaurant_queries_for_city($city))
				: $this->run_deep_import($city, $autosync, $force_restart);
			$details = ! empty($summary['error_messages']) ? ' (' . implode(' | ', array_map('sanitize_text_field', (array) $summary['error_messages'])) . ')' : '';
			$timed_out = ! empty($summary['timed_out']);
			$remaining = max(0, (int) ($summary['pending_after'] ?? 0));
			$timeout_suffix = ($timed_out && $remaining > 0)
				? ' ' . sprintf(__('Tijdslimiet bereikt, run opnieuw voor resterende %d.', 'ddb-spots'), $remaining)
				: '';
			$reuse_queue = ! empty($summary['used_existing_queue']);
			$queue_suffix = $reuse_queue ? ' ' . __('(vervolgrun op bestaande queue)', 'ddb-spots') : '';
			$already_completed = ! empty($summary['already_completed']);

			if ($already_completed) {
				$this->notices[] = array(
					'type' => 'success',
					'message' => $is_restaurant_run
						? __('Restaurant import was al afgerond. Open "Imported Restaurants" voor het lokale overzicht, of vink "Restart" aan voor een nieuwe scan.', 'ddb-spots')
						: __('Deep import was al afgerond. Open "All Imported Spots" voor de lijst, of vink "Restart" aan voor een nieuwe scan.', 'ddb-spots'),
				);
				DDB_Spots_Admin_Sync_Dashboard::log_event(
					'deep_import',
					'info',
					array(
						'source' => 'import_screen',
						'message' => sprintf('city=%s already_completed=1 found=%d', $city, (int) ($summary['found'] ?? 0)),
					)
				);
				$this->redirect_with_notices($this->get_request_search_query_args());
				return; // @codeCoverageIgnore
			}

			$this->notices[] = array(
				'type' => (((int) ($summary['errors'] ?? 0)) > 0 || $timed_out) ? 'warning' : 'success',
				'message' => sprintf(
					$is_restaurant_run
						? __('Restaurant import klaar. Unique found: %1$d, imported/updated: %2$d, errors: %3$d', 'ddb-spots')
						: __('Deep import klaar. Unique found: %1$d, imported/updated: %2$d, errors: %3$d', 'ddb-spots'),
					(int) ($summary['found'] ?? 0),
					(int) ($summary['processed'] ?? 0),
					(int) ($summary['errors'] ?? 0)
				) . $details . $timeout_suffix . $queue_suffix,
			);

			DDB_Spots_Admin_Sync_Dashboard::log_event(
				'deep_import',
				(((int) ($summary['errors'] ?? 0)) > 0 || $timed_out) ? 'warning' : 'success',
				array(
					'source' => 'import_screen',
					'message' => sprintf(
						'city=%s found=%d processed=%d errors=%d timed_out=%d pending_before=%d pending_after=%d reused_queue=%d%s',
						$city,
						(int) ($summary['found'] ?? 0),
						(int) ($summary['processed'] ?? 0),
						(int) ($summary['errors'] ?? 0),
						$timed_out ? 1 : 0,
						(int) ($summary['pending_before'] ?? 0),
						$remaining,
						$reuse_queue ? 1 : 0,
						$details
					),
				)
			);
			$this->redirect_with_notices($this->get_request_search_query_args());
			return; // @codeCoverageIgnore
		}

		if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_wpnonce'])), 'ddb_spots_import_selected')) {
			return;
		}

		$place_ids = isset($_POST['ddb_place_ids']) && is_array($_POST['ddb_place_ids']) ? array_filter(array_map('sanitize_text_field', wp_unslash($_POST['ddb_place_ids']))) : array();
		$query_args = $this->get_posted_search_query_args();
		if (empty($place_ids)) {
			$this->notices[] = array('type' => 'warning', 'message' => __('Geen plekken geselecteerd.', 'ddb-spots'));
			$this->redirect_with_notices($query_args);
			return; // @codeCoverageIgnore
		}

		$do_import = isset($_POST['ddb_import_selected']);
		$do_refresh = isset($_POST['ddb_refresh_selected']);
		if (! $do_import && ! $do_refresh) {
			return;
		}

		$autosync = isset($_POST['ddb_autosync']) && is_array($_POST['ddb_autosync']) ? wp_unslash($_POST['ddb_autosync']) : array();
		$processed = 0;
		$skipped = 0;
		$errors = 0;
		$timed_out = false;
		$remaining = 0;
		$error_messages = array();
		$deadline = microtime(true) + self::REQUEST_TIME_BUDGET_SECONDS;
		if ($do_refresh) {
			$total = count($place_ids);
			foreach ($place_ids as $index => $place_id) {
				if (microtime(true) >= $deadline) {
					$timed_out = true;
					$remaining = max(0, $total - (int) $index);
					break;
				}
				$post_id = $this->find_post_by_place_id((string) $place_id);
				if ($post_id <= 0) {
					$skipped++;
					continue;
				}
				update_post_meta($post_id, '_ddb_google_autosync', isset($autosync[ $place_id ]) ? '1' : '0');
				$result = $this->sync_post($post_id);
				if (is_wp_error($result)) {
					$errors++;
					if (count($error_messages) < 3) {
						$error_messages[] = $result->get_error_message();
					}
					continue;
				}
				$processed++;
			}
			$details = ! empty($error_messages) ? ' (' . implode(' | ', array_map('sanitize_text_field', $error_messages)) . ')' : '';
			$timeout_suffix = ($timed_out && $remaining > 0)
				? ' ' . sprintf(__('Tijdslimiet bereikt, run opnieuw voor resterende %d.', 'ddb-spots'), $remaining)
				: '';
			$this->notices[] = array(
				'type' => ($errors > 0 || $timed_out) ? 'warning' : 'success',
				'message' => sprintf(__('Sync klaar. Geupdated: %1$d, overgeslagen (nieuw): %2$d, errors: %3$d', 'ddb-spots'), $processed, $skipped, $errors) . $details . $timeout_suffix,
			);
			DDB_Spots_Admin_Sync_Dashboard::log_event(
				'batch_refresh',
				($errors > 0 || $timed_out) ? 'warning' : 'success',
				array(
					'source' => 'import_screen',
					'message' => sprintf('updated=%d skipped=%d errors=%d timed_out=%d remaining=%d%s', $processed, $skipped, $errors, $timed_out ? 1 : 0, $remaining, $details),
				)
			);
			$this->redirect_with_notices($query_args);
			return; // @codeCoverageIgnore
		}

		$total = count($place_ids);
		foreach ($place_ids as $index => $place_id) {
			if (microtime(true) >= $deadline) {
				$timed_out = true;
				$remaining = max(0, $total - (int) $index);
				break;
			}
			$sync_enabled = isset($autosync[ $place_id ]) ? '1' : '0';
			$result = $this->import_place_by_id((string) $place_id, $sync_enabled);
			if (is_wp_error($result)) {
				$errors++;
				if (count($error_messages) < 3) {
					$error_messages[] = $result->get_error_message();
				}
				continue;
			}
			$processed++;
		}
		$details = ! empty($error_messages) ? ' (' . implode(' | ', array_map('sanitize_text_field', $error_messages)) . ')' : '';
		$timeout_suffix = ($timed_out && $remaining > 0)
			? ' ' . sprintf(__('Tijdslimiet bereikt, run opnieuw voor resterende %d.', 'ddb-spots'), $remaining)
			: '';
		$this->notices[] = array(
			'type' => ($errors > 0 || $timed_out) ? 'warning' : 'success',
			'message' => sprintf(__('Import klaar. Geupdated/geimporteerd: %1$d, errors: %2$d', 'ddb-spots'), $processed, $errors) . $details . $timeout_suffix,
		);
		DDB_Spots_Admin_Sync_Dashboard::log_event(
			'batch_import',
			($errors > 0 || $timed_out) ? 'warning' : 'success',
			array(
				'source' => 'import_screen',
				'message' => sprintf('processed=%d errors=%d timed_out=%d remaining=%d%s', $processed, $errors, $timed_out ? 1 : 0, $remaining, $details),
			)
		);
		$this->redirect_with_notices($query_args);
		return; // @codeCoverageIgnore
	}

	private function get_posted_search_query_args(): array {
		$query = isset($_POST['ddb_query']) ? sanitize_text_field(wp_unslash((string) $_POST['ddb_query'])) : '';
		$location = isset($_POST['ddb_location']) ? sanitize_text_field(wp_unslash((string) $_POST['ddb_location'])) : '';
		$radius = isset($_POST['ddb_radius']) ? absint((int) $_POST['ddb_radius']) : 0;
		$page_token = isset($_POST['ddb_page_token']) ? sanitize_text_field(wp_unslash((string) $_POST['ddb_page_token'])) : '';

		$args = array();
		if ('' !== $query) {
			$args['ddb_search'] = '1';
			$args['ddb_query'] = $query;
		}
		if ('' !== $location) {
			$args['ddb_location'] = $location;
		}
		if ($radius > 0) {
			$args['ddb_radius'] = $radius;
		}
		if ('' !== $page_token) {
			$args['ddb_page_token'] = $page_token;
		}

		return $args;
	}

	private function get_request_search_query_args(): array {
		$args = array();
		$search = isset($_GET['ddb_search']) ? sanitize_text_field(wp_unslash((string) $_GET['ddb_search'])) : '';
		$query = isset($_GET['ddb_query']) ? sanitize_text_field(wp_unslash((string) $_GET['ddb_query'])) : '';
		$location = isset($_GET['ddb_location']) ? sanitize_text_field(wp_unslash((string) $_GET['ddb_location'])) : '';
		$radius = isset($_GET['ddb_radius']) ? absint((int) $_GET['ddb_radius']) : 0;
		$page_token = $this->resolve_page_token_from_request();

		if ('1' === $search) {
			$args['ddb_search'] = '1';
		}
		if ('' !== $query) {
			$args['ddb_query'] = $query;
		}
		if ('' !== $location) {
			$args['ddb_location'] = $location;
		}
		if ($radius > 0) {
			$args['ddb_radius'] = $radius;
		}
		if ('' !== $page_token) {
			$args['ddb_page_ref'] = $this->store_page_token($page_token);
		}

		return $args;
	}

	private function redirect_with_notices(array $query_args = array()): void {
		$this->persist_flash_notices();
		$args = array_merge(
			array(
				'post_type' => self::POST_TYPE,
				'page' => self::PAGE_IMPORT,
			),
			$query_args
		);
		$url = add_query_arg($args, admin_url('edit.php'));
		wp_safe_redirect($url);
		exit;
	}

	private function persist_flash_notices(): void {
		$user_id = get_current_user_id();
		if ($user_id <= 0 || empty($this->notices)) {
			return;
		}
		set_transient(self::IMPORT_NOTICES_TRANSIENT_PREFIX . $user_id, $this->notices, 120);
	}

	private function consume_flash_notices(): void {
		$user_id = get_current_user_id();
		if ($user_id <= 0) {
			return;
		}
		$key = self::IMPORT_NOTICES_TRANSIENT_PREFIX . $user_id;
		$stored = get_transient($key);
		if (is_array($stored) && ! empty($stored)) {
			$this->notices = array_merge($this->notices, $stored);
		}
		delete_transient($key);
	}

	private function search_places(string $query, string $location, int $radius, string $page_token): array {
		$key = $this->get_api_key();
		if ('' === $key) {
			return array('results' => array(), 'next_page_token' => '', 'error' => __('Geen API key ingesteld.', 'ddb-spots'));
		}

		$params = array('key' => $key);
		if ('' !== $page_token) {
			$params['pagetoken'] = $page_token;
		} else {
			$params['query'] = $query;
			if ('' !== trim($location)) {
				$params['location'] = $location;
				if ($radius > 0) {
					$params['radius'] = $radius;
				}
			}
		}

		$url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
		$max_attempts = ('' !== $page_token) ? 6 : 1;
		$last_status = '';
		$last_message = '';
		for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
			$response = $this->request_json($url);
			if (is_wp_error($response)) {
				return array('results' => array(), 'next_page_token' => '', 'error' => $response->get_error_message());
			}
			$status = isset($response['status']) ? (string) $response['status'] : '';
			if ('OK' === $status || 'ZERO_RESULTS' === $status) {
				return array(
					'results' => isset($response['results']) && is_array($response['results']) ? $response['results'] : array(),
					'next_page_token' => isset($response['next_page_token']) ? (string) $response['next_page_token'] : '',
					'error' => '',
				);
			}

			$last_status = $status;
			$last_message = isset($response['error_message']) ? (string) $response['error_message'] : $status;
			$is_retryable_token_state = ('' !== $page_token && 'INVALID_REQUEST' === $status && $attempt < ($max_attempts - 1));
			if (! $is_retryable_token_state) {
				break;
			}
			sleep(2);
		}

		$msg = '' !== $last_message ? $last_message : $last_status;
		if ('' !== $page_token && 'INVALID_REQUEST' === $last_status) {
			$msg = __('Volgende resultatenpagina is nog niet klaar bij Google. Probeer opnieuw over een paar seconden.', 'ddb-spots');
		}
		return array('results' => array(), 'next_page_token' => '', 'error' => sprintf(__('Google API fout: %s', 'ddb-spots'), $msg));
	}

	private function run_deep_import(string $city, string $autosync, bool $force_restart = false, array $queries_override = array()): array {
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		$city = '' !== trim($city) ? $city : (string) DDB_Spots_Admin_Settings_Page::get_value('data_sources.default_city', 'Den Bosch');
		$queries = ! empty($queries_override) ? array_values(array_unique(array_filter(array_map('sanitize_text_field', $queries_override)))) : $this->get_deep_queries_for_city($city);
		$radius = max(500, (int) DDB_Spots_Admin_Settings_Page::get_value('data_sources.deep_import_radius', 5000));
		$max_places = max(20, min(500, (int) DDB_Spots_Admin_Settings_Page::get_value('data_sources.deep_import_max_places', 180)));
		$started_at = microtime(true);
		$deadline = $started_at + self::REQUEST_TIME_BUDGET_SECONDS;
		$collect_deadline = $started_at + min(8.0, self::REQUEST_TIME_BUDGET_SECONDS * 0.45);
		$timed_out = false;
		$error_messages = array();
		$signature = $this->get_deep_import_signature($city, $queries, $radius, $max_places);
		$state = $this->get_deep_import_state();
		$use_existing_queue = false;
		$last_completed = $this->get_deep_import_last_completed_state();

		if ($force_restart) {
			delete_option(self::DEEP_IMPORT_STATE_OPTION);
			delete_option(self::DEEP_IMPORT_LAST_COMPLETED_OPTION);
			$state = array();
			$last_completed = array();
		}

		if (
			$signature === (string) ($state['signature'] ?? '') &&
			isset($state['pending_place_ids']) &&
			is_array($state['pending_place_ids']) &&
			! empty($state['pending_place_ids'])
		) {
			$use_existing_queue = true;
		} elseif (
			! $force_restart &&
			$signature === (string) ($last_completed['signature'] ?? '') &&
			isset($last_completed['completed_at'])
		) {
			$completed_found = isset($last_completed['total_found']) ? (int) $last_completed['total_found'] : 0;
			return array(
				'found' => max(0, $completed_found),
				'processed' => 0,
				'errors' => 0,
				'error_messages' => array(),
				'timed_out' => false,
				'pending_before' => 0,
				'pending_after' => 0,
				'used_existing_queue' => true,
				'already_completed' => true,
			);
		} else {
			$unique_place_ids = array();
			foreach ($queries as $query) {
				if (microtime(true) >= $collect_deadline) {
					$timed_out = true;
					break;
				}
				if (count($unique_place_ids) >= $max_places) {
					break;
				}

				$remaining = max(1, $max_places - count($unique_place_ids));
				$collected = $this->collect_place_ids_for_query($query, '', $radius, $remaining, $collect_deadline);
				foreach ((array) ($collected['place_ids'] ?? array()) as $place_id) {
					$place_id = sanitize_text_field((string) $place_id);
					if ('' === $place_id || isset($unique_place_ids[ $place_id ])) {
						continue;
					}
					$unique_place_ids[ $place_id ] = true;
					if (count($unique_place_ids) >= $max_places) {
						break;
					}
				}
				foreach ((array) ($collected['errors'] ?? array()) as $error) {
					if (count($error_messages) >= 3) {
						break;
					}
					$error_messages[] = sanitize_text_field((string) $error);
				}
			}

			$state = array(
				'signature' => $signature,
				'city' => $city,
				'radius' => $radius,
				'max_places' => $max_places,
				'total_found' => count($unique_place_ids),
				'pending_place_ids' => array_values(array_keys($unique_place_ids)),
				'updated_at' => gmdate('c'),
			);
		}

		$pending_place_ids = isset($state['pending_place_ids']) && is_array($state['pending_place_ids'])
			? array_values(array_filter(array_map('sanitize_text_field', $state['pending_place_ids'])))
			: array();
		$pending_before = count($pending_place_ids);
		$total_found = (int) ($state['total_found'] ?? $pending_before);
		if ($total_found < $pending_before) {
			$total_found = $pending_before;
		}

		$processed = 0;
		$errors = 0;
		while (! empty($pending_place_ids)) {
			if (microtime(true) >= $deadline) {
				$timed_out = true;
				break;
			}
			$place_id = (string) array_shift($pending_place_ids);
			if ('' === $place_id) {
				continue;
			}
			// Deep import uses lightweight mode to avoid request timeouts.
			$result = $this->import_place_by_id((string) $place_id, $autosync, 0, false);
			if (is_wp_error($result)) {
				$errors++;
				if (count($error_messages) < 3) {
					$error_messages[] = $result->get_error_message();
				}
			} else {
				$processed++;
			}
		}

		$pending_after = count($pending_place_ids);
		if ($pending_after > 0) {
			$state['pending_place_ids'] = array_values($pending_place_ids);
			$state['total_found'] = $total_found;
			$state['updated_at'] = gmdate('c');
			update_option(self::DEEP_IMPORT_STATE_OPTION, $state, false);
		} else {
			delete_option(self::DEEP_IMPORT_STATE_OPTION);
			update_option(
				self::DEEP_IMPORT_LAST_COMPLETED_OPTION,
				array(
					'signature' => $signature,
					'total_found' => $total_found,
					'completed_at' => gmdate('c'),
				),
				false
			);
		}

		if ($pending_after > 0) {
			$timed_out = true;
		}

		return array(
			'found' => $total_found,
			'processed' => $processed,
			'errors' => $errors,
			'error_messages' => $error_messages,
			'timed_out' => $timed_out,
			'pending_before' => $pending_before,
			'pending_after' => $pending_after,
			'used_existing_queue' => $use_existing_queue,
			'already_completed' => false,
		);
	}

	private function get_deep_import_signature(string $city, array $queries, int $radius, int $max_places): string {
		$normalized_queries = array_values(array_map('sanitize_text_field', $queries));
		return md5(
			wp_json_encode(
				array(
					'city' => sanitize_text_field($city),
					'queries' => $normalized_queries,
					'radius' => $radius,
					'max_places' => $max_places,
				)
			) ?: ''
		);
	}

	private function get_deep_import_state(): array {
		$state = get_option(self::DEEP_IMPORT_STATE_OPTION, array());
		return is_array($state) ? $state : array();
	}

	private function get_deep_import_last_completed_state(): array {
		$state = get_option(self::DEEP_IMPORT_LAST_COMPLETED_OPTION, array());
		return is_array($state) ? $state : array();
	}

	private function collect_place_ids_for_query(string $query, string $location, int $radius, int $limit, ?float $deadline = null): array {
		$place_ids = array();
		$errors = array();
		$page_token = '';

		for ($page = 0; $page < 3; $page++) {
			if (null !== $deadline && microtime(true) >= $deadline) {
				break;
			}
			if (count($place_ids) >= $limit) {
				break;
			}

			$search = $this->search_places($query, $location, $radius, $page_token);
			$error = isset($search['error']) ? (string) $search['error'] : '';
			if ('' !== $error) {
				$errors[] = $error;
				break;
			}

			$results = isset($search['results']) && is_array($search['results']) ? $search['results'] : array();
			foreach ($results as $row) {
				$place_id = isset($row['place_id']) ? sanitize_text_field((string) $row['place_id']) : '';
				if ('' === $place_id || in_array($place_id, $place_ids, true)) {
					continue;
				}
				$place_ids[] = $place_id;
				if (count($place_ids) >= $limit) {
					break;
				}
			}

			$next_page_token = isset($search['next_page_token']) ? (string) $search['next_page_token'] : '';
			if ('' === $next_page_token) {
				break;
			}
			$page_token = $next_page_token;
			if (null !== $deadline && microtime(true) >= $deadline) {
				break;
			}
			sleep(1);
		}

		return array(
			'place_ids' => $place_ids,
			'errors' => $errors,
		);
	}

	private function get_deep_queries_for_city(string $city): array {
		$city = trim($city);
		$raw = (string) DDB_Spots_Admin_Settings_Page::get_value('data_sources.deep_import_queries', '');
		$lines = preg_split('/\r\n|\r|\n/', $raw) ?: array();
		$queries = array();

		foreach ($lines as $line) {
			$line = trim((string) $line);
			if ('' === $line) {
				continue;
			}
			$line = str_replace('{city}', $city, $line);
			$line = sanitize_text_field($line);
			if ('' !== $line) {
				$queries[] = $line;
			}
		}

		if (empty($queries)) {
			$queries = array(
				'restaurants in ' . $city,
				'things to do in ' . $city,
				'bars in ' . $city,
				'cafes in ' . $city,
				'museums in ' . $city,
				'hotels in ' . $city,
				'attractions in ' . $city,
			);
		}

		return array_values(array_unique($queries));
	}

	private function get_restaurant_queries_for_city(string $city): array {
		$city = trim($city);

		return array_values(array_unique(array(
			'restaurants in ' . $city,
			'restaurant ' . $city,
			'fine dining in ' . $city,
			'bistro in ' . $city,
			'brasserie in ' . $city,
			'eetcafe in ' . $city,
			'cafe in ' . $city,
			'lunch in ' . $city,
			'diner in ' . $city,
			'eten in ' . $city,
		)));
	}

	public function import_place_by_id(string $place_id, string $autosync = '1', int $preferred_post_id = 0, ?bool $import_media_override = null): int|WP_Error {
		$details = $this->get_place_details($place_id);
		if (is_wp_error($details)) {
			return $details;
		}

		$post_id = $preferred_post_id > 0 ? $preferred_post_id : $this->find_post_by_place_id($place_id);
		$name = isset($details['name']) ? sanitize_text_field((string) $details['name']) : '';
		$address = isset($details['formatted_address']) ? sanitize_text_field((string) $details['formatted_address']) : '';
		$title = '' !== $name ? $name : __('Google Place', 'ddb-spots') . ' ' . $place_id;
		$website = esc_url_raw((string) ($details['website'] ?? ''));
		$editorial_summary = sanitize_textarea_field((string) ($details['editorial_summary']['overview'] ?? ''));
		$import_editorial_summary = $this->should_import_editorial_summary();
		$excerpt_fallback = ($import_editorial_summary && '' !== trim($editorial_summary)) ? $editorial_summary : $address;

		if ($post_id > 0) {
			$existing = get_post($post_id);
			if (! $existing instanceof WP_Post || self::POST_TYPE !== $existing->post_type) {
				return new WP_Error('ddb_spots_import', __('Kon bestaande spot niet vinden.', 'ddb-spots'));
			}

			$lock_title = '1' === (string) get_post_meta($post_id, '_ddb_lock_title', true);
			$lock_excerpt = '1' === (string) get_post_meta($post_id, '_ddb_lock_excerpt', true);
			$update_data = array('ID' => $post_id);
			$should_update_post = false;

			if (! $lock_title && '' !== $title && trim((string) $existing->post_title) !== $title) {
				$update_data['post_title'] = $title;
				$should_update_post = true;
			}
			if (! $lock_excerpt && '' !== $excerpt_fallback && trim((string) $existing->post_excerpt) !== $excerpt_fallback) {
				$update_data['post_excerpt'] = $excerpt_fallback;
				$should_update_post = true;
			}

			if ($should_update_post) {
				$updated = wp_update_post($update_data, true);
				if (is_wp_error($updated)) {
					return $updated;
				}
				$post_id = (int) $updated;
			}
		} else {
			$inserted = wp_insert_post(
				array(
					'post_type' => self::POST_TYPE,
					'post_title' => $title,
					'post_excerpt' => $excerpt_fallback,
					'post_status' => 'draft',
				),
				true
			);
			if (is_wp_error($inserted)) {
				return $inserted;
			}
			$post_id = (int) $inserted;
		}
		if ($post_id <= 0) {
			return new WP_Error('ddb_spots_import', __('Kon spot niet opslaan.', 'ddb-spots'));
		}

		$lock_location = '1' === (string) get_post_meta($post_id, '_ddb_lock_location', true);
		$lock_contact = '1' === (string) get_post_meta($post_id, '_ddb_lock_contact', true);
		$lock_hours = '1' === (string) get_post_meta($post_id, '_ddb_lock_hours', true);
		$import_reviews = $this->should_import_reviews();
		$import_quality_signals = $this->should_import_quality_signals();
		$import_wp_media = null === $import_media_override ? $this->should_import_wp_media() : (bool) $import_media_override;
		$max_import_photos = $this->get_import_wp_media_max_photos();

		$components = $this->extract_address_components($details);
		$photos = isset($details['photos']) && is_array($details['photos']) ? $details['photos'] : array();
		$photo_refs = array();
		foreach ($photos as $photo) {
			if (isset($photo['photo_reference'])) {
				$photo_refs[] = sanitize_text_field((string) $photo['photo_reference']);
			}
		}
		$lat = isset($details['geometry']['location']['lat']) ? (string) $details['geometry']['location']['lat'] : '';
		$lng = isset($details['geometry']['location']['lng']) ? (string) $details['geometry']['location']['lng'] : '';

		update_post_meta($post_id, '_ddb_source', 'google_places');
		update_post_meta($post_id, '_ddb_google_place_id', $place_id);
		update_post_meta($post_id, '_ddb_google_last_synced_at', gmdate('c'));
		update_post_meta($post_id, '_ddb_google_autosync', '1' === $autosync ? '1' : '0');
		update_post_meta($post_id, '_ddb_google_maps_url', esc_url_raw((string) ($details['url'] ?? '')));
		update_post_meta($post_id, '_ddb_google_place_types_json', wp_json_encode($this->sanitize_string_list((array) ($details['types'] ?? array()), 20)));
		if (! $lock_hours) {
			update_post_meta($post_id, '_ddb_google_opening_hours_json', wp_json_encode($details['opening_hours'] ?? array()));
			update_post_meta($post_id, '_ddb_google_opening_periods_json', wp_json_encode($details['opening_hours']['periods'] ?? array()));
		}
		if (! $lock_contact) {
			$phone = (string) ($details['international_phone_number'] ?? $details['formatted_phone_number'] ?? '');
			update_post_meta($post_id, '_ddb_google_phone', sanitize_text_field($phone));
			update_post_meta($post_id, '_ddb_google_website', $website);
		}
		if (! $lock_location) {
			update_post_meta($post_id, '_ddb_address', sanitize_text_field($address));
			update_post_meta($post_id, '_ddb_city', $components['city']);
			update_post_meta($post_id, '_ddb_region', $components['region']);
			update_post_meta($post_id, '_ddb_country', $components['country']);
			update_post_meta($post_id, '_ddb_lat', sanitize_text_field($lat));
			update_post_meta($post_id, '_ddb_lng', sanitize_text_field($lng));
		}
		update_post_meta($post_id, '_ddb_google_photo_refs_json', wp_json_encode($photo_refs));
		update_post_meta($post_id, '_ddb_google_attribution_json', wp_json_encode($details['html_attributions'] ?? array()));
		if ($import_editorial_summary) {
			update_post_meta($post_id, '_ddb_google_editorial_summary', $editorial_summary);
		}
		if ($import_reviews) {
			update_post_meta($post_id, '_ddb_google_reviews_json', wp_json_encode($this->sanitize_reviews((array) ($details['reviews'] ?? array()), 5)));
		}
		if ($import_quality_signals) {
			$price_level = isset($details['price_level']) ? (int) $details['price_level'] : 0;
			if ($price_level >= 1 && $price_level <= 4) {
				update_post_meta($post_id, '_ddb_price_level', (string) $price_level);
			}
			update_post_meta($post_id, '_ddb_google_rating', isset($details['rating']) ? (string) (float) $details['rating'] : '');
			update_post_meta($post_id, '_ddb_google_user_ratings_total', isset($details['user_ratings_total']) ? (string) absint((int) $details['user_ratings_total']) : '0');
			update_post_meta($post_id, '_ddb_google_business_status', sanitize_key((string) ($details['business_status'] ?? '')));
		}
		if ($import_wp_media && ! empty($photo_refs)) {
			$this->import_google_photos_to_media($post_id, $photo_refs, $this->get_api_key(), $max_import_photos);
		}

		$lock_cta = '1' === (string) get_post_meta($post_id, '_ddb_lock_cta', true);
		if (! $lock_cta && ! $lock_contact && '' !== $website) {
			$current_generic_cta = (string) get_post_meta($post_id, '_ddb_spot_cta_url', true);
			$last_autofill = (string) get_post_meta($post_id, '_ddb_last_google_website_for_cta', true);
			if ('' === $current_generic_cta || $current_generic_cta === $last_autofill) {
				update_post_meta($post_id, '_ddb_spot_cta_url', $website);
				update_post_meta($post_id, '_ddb_last_google_website_for_cta', $website);
			}
		}

		$this->assign_restaurant_type($post_id);
		DDB_Spots::invalidate_cache();
		do_action('ddb_spots_canonical_sync_post', $post_id, 'google_places_import');
		return $post_id;
	}

	public function sync_post(int $post_id): int|WP_Error {
		$place_id = (string) get_post_meta($post_id, '_ddb_google_place_id', true);
		if ('' === $place_id) {
			return new WP_Error('ddb_spots_no_place_id', __('Geen Google Place ID op deze spot.', 'ddb-spots'));
		}
		$autosync = (string) get_post_meta($post_id, '_ddb_google_autosync', true);
		if ('' === $autosync) {
			$autosync = '1';
		}
		return $this->import_place_by_id($place_id, $autosync, $post_id);
	}

	private function get_place_details(string $place_id): array|WP_Error {
		$key = $this->get_api_key();
		if ('' === $key) {
			return new WP_Error('ddb_spots_key', __('Geen API key ingesteld.', 'ddb-spots'));
		}

		$response = $this->request_place_details_response($place_id, $this->get_place_details_fields());
		if (is_wp_error($response)) {
			return $response;
		}
		if ($this->has_unsupported_fields_error($response)) {
			$response = $this->request_place_details_response($place_id, $this->get_baseline_place_details_fields());
			if (is_wp_error($response)) {
				return $response;
			}
		}

		$status = isset($response['status']) ? (string) $response['status'] : '';
		if ('OK' !== $status || ! isset($response['result']) || ! is_array($response['result'])) {
			$msg = isset($response['error_message']) ? (string) $response['error_message'] : $status;
			return new WP_Error('ddb_spots_details', sprintf(__('Places details fout: %s', 'ddb-spots'), $msg));
		}
		return $response['result'];
	}

	private function request_json(string $url): array|WP_Error {
		$response = wp_remote_get($url, array('timeout' => 20, 'redirection' => 3));
		if (is_wp_error($response)) {
			return $response;
		}
		$status = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		if ($status < 200 || $status > 299) {
			return new WP_Error('ddb_spots_http', sprintf(__('HTTP fout %d', 'ddb-spots'), $status));
		}
		$data = json_decode((string) $body, true);
		if (! is_array($data)) {
			return new WP_Error('ddb_spots_json', __('Onleesbare JSON response van Google.', 'ddb-spots'));
		}
		return $data;
	}

	private function extract_address_components(array $details): array {
		$city = '';
		$region = '';
		$country = '';
		$components = isset($details['address_components']) && is_array($details['address_components']) ? $details['address_components'] : array();
		foreach ($components as $component) {
			$types = isset($component['types']) && is_array($component['types']) ? $component['types'] : array();
			$name = isset($component['long_name']) ? sanitize_text_field((string) $component['long_name']) : '';
			if (in_array('locality', $types, true) && '' === $city) {
				$city = $name;
			}
			if (in_array('administrative_area_level_1', $types, true) && '' === $region) {
				$region = $name;
			}
			if (in_array('country', $types, true) && '' === $country) {
				$country = $name;
			}
		}
		return array('city' => $city, 'region' => $region, 'country' => $country);
	}

	private function assign_restaurant_type(int $post_id): void {
		$term = term_exists('restaurant', 'ddb_spot_type');
		if (! $term) {
			$created = wp_insert_term('Restaurant', 'ddb_spot_type', array('slug' => 'restaurant'));
			if (is_wp_error($created)) {
				return;
			}
			$term = $created;
		}
		$term_id = is_array($term) && isset($term['term_id']) ? (int) $term['term_id'] : 0;
		if ($term_id > 0) {
			wp_set_post_terms($post_id, array($term_id), 'ddb_spot_type', false);
		}
		update_post_meta($post_id, '_ddb_spot_type_primary', 'restaurant');
	}

	private function find_post_by_place_id(string $place_id): int {
		$posts = get_posts(
			array(
				'post_type' => self::POST_TYPE,
				'post_status' => 'any',
				'meta_key' => '_ddb_google_place_id',
				'meta_value' => $place_id,
				'fields' => 'ids',
				'numberposts' => 1,
			)
		);
		return ! empty($posts) ? (int) $posts[0] : 0;
	}

	private function find_existing_posts_by_place_ids(array $place_ids): array {
		$map = array();
		$place_ids = array_values(array_filter(array_map('sanitize_text_field', $place_ids)));
		if (empty($place_ids)) {
			return $map;
		}
		$query = new WP_Query(
			array(
				'post_type' => self::POST_TYPE,
				'post_status' => 'any',
				'posts_per_page' => -1,
				'fields' => 'ids',
				'meta_query' => array(
					array(
						'key' => '_ddb_google_place_id',
						'value' => $place_ids,
						'compare' => 'IN',
					),
				),
			)
		);
		foreach ($query->posts as $post_id) {
			$pid = (int) $post_id;
			$place_id = (string) get_post_meta($pid, '_ddb_google_place_id', true);
			if ('' !== $place_id) {
				$map[ $place_id ] = $pid;
			}
		}
		return $map;
	}

	private function get_api_key(): string {
		return trim((string) DDB_Spots_Admin_Settings_Page::get_value('data_sources.google_api_key', ''));
	}

	private function get_place_details_fields(): string {
		$fields = array(
			'name',
			'formatted_address',
			'rating',
			'geometry',
			'website',
			'formatted_phone_number',
			'international_phone_number',
			'opening_hours',
			'photos',
			'address_components',
			'url',
			'types',
		);

		if ($this->should_import_editorial_summary()) {
			$fields[] = 'editorial_summary';
		}
		if ($this->should_import_reviews()) {
			$fields[] = 'reviews';
		}
		if ($this->should_import_quality_signals()) {
			$fields[] = 'user_ratings_total';
			$fields[] = 'price_level';
			$fields[] = 'business_status';
		}

		return implode(',', array_values(array_unique($fields)));
	}

	private function get_baseline_place_details_fields(): string {
		return 'name,formatted_address,rating,geometry,website,formatted_phone_number,opening_hours,photos,address_components,url,types';
	}

	private function request_place_details_response(string $place_id, string $fields): array|WP_Error {
		$key = $this->get_api_key();
		$params = array(
			'key' => $key,
			'place_id' => $place_id,
			'fields' => $fields,
		);
		$url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
		return $this->request_json($url);
	}

	private function has_unsupported_fields_error(array $response): bool {
		$message = isset($response['error_message']) ? strtolower((string) $response['error_message']) : '';
		return '' !== $message && false !== strpos($message, 'unsupported field name');
	}

	private function should_import_editorial_summary(): bool {
		return (bool) DDB_Spots_Admin_Settings_Page::get_value('data_sources.import_editorial_summary', true);
	}

	private function should_import_reviews(): bool {
		return (bool) DDB_Spots_Admin_Settings_Page::get_value('data_sources.import_reviews', true);
	}

	private function should_import_quality_signals(): bool {
		return (bool) DDB_Spots_Admin_Settings_Page::get_value('data_sources.import_quality_signals', true);
	}

	private function should_import_wp_media(): bool {
		return (bool) DDB_Spots_Admin_Settings_Page::get_value('data_sources.import_wp_media', false);
	}

	private function get_import_wp_media_max_photos(): int {
		return max(1, min(10, (int) DDB_Spots_Admin_Settings_Page::get_value('data_sources.import_wp_media_max_photos', 4)));
	}

	private function sanitize_string_list(array $items, int $max = 20): array {
		$out = array();
		foreach ($items as $item) {
			if (count($out) >= $max) {
				break;
			}
			$clean = sanitize_text_field((string) $item);
			if ('' === $clean) {
				continue;
			}
			$out[] = $clean;
		}
		return $out;
	}

	private function sanitize_reviews(array $reviews, int $max = 5): array {
		$clean = array();
		foreach ($reviews as $review) {
			if (count($clean) >= $max || ! is_array($review)) {
				continue;
			}
			$clean[] = array(
				'author_name' => sanitize_text_field((string) ($review['author_name'] ?? '')),
				'rating' => isset($review['rating']) ? (float) $review['rating'] : 0.0,
				'relative_time_description' => sanitize_text_field((string) ($review['relative_time_description'] ?? '')),
				'text' => sanitize_textarea_field((string) ($review['text'] ?? '')),
				'time' => isset($review['time']) ? (int) $review['time'] : 0,
			);
		}
		return $clean;
	}

	private function parse_gallery_ids(string $csv): array {
		if ('' === trim($csv)) {
			return array();
		}
		return array_values(array_filter(array_map('absint', array_map('trim', explode(',', $csv)))));
	}

	private function import_google_photos_to_media(int $post_id, array $photo_refs, string $api_key, int $max_photos): void {
		if ($post_id <= 0 || '' === $api_key || $max_photos <= 0) {
			return;
		}

		$photo_refs = array_values(array_unique(array_filter(array_map('sanitize_text_field', $photo_refs))));
		if (empty($photo_refs)) {
			return;
		}

		$upload = wp_upload_dir();
		$upload_path = isset($upload['path']) ? (string) $upload['path'] : '';
		if ('' === $upload_path || ! is_dir($upload_path) || ! is_writable($upload_path)) {
			$this->log_google_photo_import_event(
				$post_id,
				'warning',
				sprintf('Uploads directory not writable: %s', $upload_path)
			);
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$media_map = json_decode((string) get_post_meta($post_id, '_ddb_google_photo_media_map_json', true), true);
		if (! is_array($media_map)) {
			$media_map = array();
		}

		$imported_ids = array();
		$failed_count = 0;
		$failure_reasons = array();
		foreach (array_slice($photo_refs, 0, $max_photos) as $photo_ref) {
			$mapped_id = isset($media_map[ $photo_ref ]) ? absint((int) $media_map[ $photo_ref ]) : 0;
			if ($mapped_id > 0 && get_post($mapped_id) instanceof WP_Post) {
				$imported_ids[] = $mapped_id;
				continue;
			}

			$attachment_result = $this->download_google_photo_as_attachment($post_id, $photo_ref, $api_key);
			if (is_wp_error($attachment_result)) {
				$failed_count++;
				if (count($failure_reasons) < 2) {
					$failure_reasons[] = $attachment_result->get_error_message();
				}
			} else {
				$attachment_id = (int) $attachment_result;
				if ($attachment_id > 0) {
					$media_map[ $photo_ref ] = $attachment_id;
					$imported_ids[] = $attachment_id;
				}
			}
		}

		if (empty($imported_ids)) {
			if ($failed_count > 0) {
				$reason_suffix = ! empty($failure_reasons) ? ' Reasons: ' . implode(' | ', array_map('sanitize_text_field', $failure_reasons)) : '';
				$status = $this->is_forbidden_failure_reasons($failure_reasons) ? 'info' : 'warning';
				$this->log_google_photo_import_event(
					$post_id,
					$status,
					sprintf('No photos imported (%d failed).', $failed_count) . $reason_suffix
				);
			}
			return;
		}

		$existing_gallery = $this->parse_gallery_ids((string) get_post_meta($post_id, '_ddb_gallery_ids', true));
		$merged_gallery = array_values(array_unique(array_merge($existing_gallery, $imported_ids)));
		update_post_meta($post_id, '_ddb_gallery_ids', implode(',', $merged_gallery));

		$featured_id = (int) get_post_thumbnail_id($post_id);
		if ($featured_id <= 0 && ! empty($merged_gallery)) {
			set_post_thumbnail($post_id, (int) $merged_gallery[0]);
		}

		$hero_id = (int) get_post_meta($post_id, '_ddb_image_hero_id', true);
		if ($hero_id <= 0 && ! empty($merged_gallery)) {
			update_post_meta($post_id, '_ddb_image_hero_id', (string) ((int) $merged_gallery[0]));
		}

		update_post_meta($post_id, '_ddb_google_photo_media_map_json', wp_json_encode($media_map));

		if ($failed_count > 0) {
			$reason_suffix = ! empty($failure_reasons) ? ' Reasons: ' . implode(' | ', array_map('sanitize_text_field', $failure_reasons)) : '';
			$this->log_google_photo_import_event(
				$post_id,
				'warning',
				sprintf('Imported %d photos, %d failed.', count($imported_ids), $failed_count) . $reason_suffix
			);
		} else {
			$this->log_google_photo_import_event(
				$post_id,
				'success',
				sprintf('Imported %d photos.', count($imported_ids))
			);
		}
	}

	private function download_google_photo_as_attachment(int $post_id, string $photo_ref, string $api_key): int|WP_Error {
		$photo_url = 'https://maps.googleapis.com/maps/api/place/photo?' . http_build_query(
			array(
				'maxwidth' => 1600,
				'photo_reference' => $photo_ref,
				'key' => $api_key,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		$tmp_file = download_url($photo_url, 30);
		if (is_wp_error($tmp_file)) {
			return $tmp_file;
		}

		$extension = 'jpg';
		$image_type = function_exists('exif_imagetype') ? @exif_imagetype($tmp_file) : false;
		if (false !== $image_type) {
			$detected_ext = image_type_to_extension($image_type, false);
			if (is_string($detected_ext) && '' !== $detected_ext) {
				$extension = strtolower($detected_ext);
			}
		}

		if ('jpeg' === $extension) {
			$extension = 'jpg';
		}
		if (! in_array($extension, array('jpg', 'png', 'gif', 'webp'), true)) {
			$extension = 'jpg';
		}

		$file_array = array(
			'name' => 'google-place-' . substr(md5($photo_ref), 0, 12) . '.' . $extension,
			'tmp_name' => $tmp_file,
		);

		$attachment_id = media_handle_sideload($file_array, $post_id, __('Google Places photo', 'ddb-spots'));
		if (is_wp_error($attachment_id)) {
			@unlink($tmp_file);
			return $attachment_id;
		}

		return (int) $attachment_id;
	}

	private function is_forbidden_failure_reasons(array $failure_reasons): bool {
		if (empty($failure_reasons)) {
			return false;
		}

		foreach ($failure_reasons as $reason) {
			$text = strtolower(trim((string) $reason));
			if ('' === $text) {
				continue;
			}
			if (false === strpos($text, 'forbidden') && false === strpos($text, '403')) {
				return false;
			}
		}

		return true;
	}

	private function log_google_photo_import_event(int $post_id, string $status, string $message): void {
		$status = sanitize_key($status);
		if (! in_array($status, array('success', 'warning', 'error', 'info'), true)) {
			$status = 'info';
		}

		$message = sanitize_text_field($message);
		if ($post_id > 0) {
			$signature = md5($status . '|' . $message);
			$last_signature = (string) get_post_meta($post_id, '_ddb_google_photo_last_log_signature', true);
			$last_at = (int) get_post_meta($post_id, '_ddb_google_photo_last_log_at', true);
			$now = time();
			if ('' !== $last_signature && hash_equals($last_signature, $signature) && $last_at > 0 && ($now - $last_at) < HOUR_IN_SECONDS) {
				return;
			}
			update_post_meta($post_id, '_ddb_google_photo_last_log_signature', $signature);
			update_post_meta($post_id, '_ddb_google_photo_last_log_at', (string) $now);
		}

		DDB_Spots_Admin_Sync_Dashboard::log_event(
			'google_photo_import',
			$status,
			array(
				'post_id' => $post_id,
				'source' => 'google_places',
				'message' => $message,
			)
		);
	}

	private function render_notices(): void {
		foreach ($this->notices as $notice) {
			$type = isset($notice['type']) ? (string) $notice['type'] : 'info';
			$message = isset($notice['message']) ? (string) $notice['message'] : '';
			echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
		}
	}

}
