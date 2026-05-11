<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Admin_Spot_Health {
	private const POST_TYPE = 'ddb_spot';
	private const TYPE_TAX = 'ddb_spot_type';
	private const NOTICE_KEY = 'ddb_spots_publish_gate_notice_';
	private const PREPUBLISH_NONCE = 'ddb_spots_prepublish_validate';
	private DDB_Spots_Service_Quality_Policy $quality_policy;

	public function __construct(?DDB_Spots_Service_Quality_Policy $quality_policy = null) {
		$this->quality_policy = $quality_policy instanceof DDB_Spots_Service_Quality_Policy ? $quality_policy : new DDB_Spots_Service_Quality_Policy();
	}

	public function init(): void {
		add_action('add_meta_boxes_' . self::POST_TYPE, array($this, 'register_metabox'));
		add_action('transition_post_status', array($this, 'enforce_publish_gate'), 20, 3);
		add_action('admin_notices', array($this, 'render_publish_gate_notice'));
		add_action('wp_ajax_ddb_spots_prepublish_validate', array($this, 'ajax_validate_prepublish'));
	}

	public function register_metabox(): void {
		add_meta_box(
			'ddb_spot_health',
			__('Spot Health', 'ddb-spots'),
			array($this, 'render_metabox'),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	public function render_metabox(WP_Post $post): void {
		$config = DDB_Spots_Admin_Settings_Page::get_config();
		$checks = $this->build_checks($post, $config);
		$total = count($checks);
		$passed = count(array_filter($checks, static fn(array $check): bool => (bool) $check['ok']));
		$score = $total > 0 ? (int) round(($passed / $total) * 100) : 0;
		$source = (string) get_post_meta($post->ID, '_ddb_source', true);
		$place_id = (string) get_post_meta($post->ID, '_ddb_google_place_id', true);
		?>
		<div class="ddb-health">
			<p><strong><?php esc_html_e('Quality Score', 'ddb-spots'); ?>:</strong> <?php echo esc_html((string) $score); ?>/100</p>
			<progress max="100" value="<?php echo esc_attr((string) $score); ?>" class="ddb-health__progress"></progress>
			<ul class="ddb-health__list">
				<?php foreach ($checks as $check) : ?>
					<li class="ddb-health__item <?php echo $check['ok'] ? 'is-pass' : 'is-fail'; ?>">
						<span aria-hidden="true"><?php echo $check['ok'] ? '&#10003;' : '&#10007;'; ?></span>
						<span><?php echo esc_html($check['label']); ?></span>
						<?php if (! $check['ok']) : ?>
							<a href="#" data-ddb-tab-link="<?php echo esc_attr($check['fix_tab']); ?>" data-ddb-focus="<?php echo esc_attr($check['fix_focus']); ?>"><?php esc_html_e('Fix', 'ddb-spots'); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ('google_places' === $source && '' !== $place_id && current_user_can('edit_post', $post->ID)) : ?>
				<p>
					<a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'ddb_spots_sync_now', 'post_id' => (int) $post->ID), admin_url('admin-post.php')), 'ddb_spots_sync_now_' . (int) $post->ID)); ?>"><?php esc_html_e('Sync now', 'ddb-spots'); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function enforce_publish_gate(string $new_status, string $old_status, WP_Post $post): void {
		if (self::POST_TYPE !== $post->post_type || 'publish' !== $new_status || 'publish' === $old_status) {
			return;
		}

		$post_id = (int) $post->ID;
		if ($post_id <= 0 || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}

		$config = DDB_Spots_Admin_Settings_Page::get_config();
		if (empty($config['ux_rules']['block_publish_on_critical'])) {
			return;
		}

		$critical_failures = $this->get_critical_failures($post, $config);
		if (empty($critical_failures)) {
			return;
		}

		remove_action('transition_post_status', array($this, 'enforce_publish_gate'), 20);
		wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));
		add_action('transition_post_status', array($this, 'enforce_publish_gate'), 20, 3);

		$labels = array_map(
			static fn(array $check): string => (string) $check['label'],
			$critical_failures
		);
		$this->store_publish_gate_notice($labels);
	}

	public function render_publish_gate_notice(): void {
		$screen = get_current_screen();
		if (! $screen || self::POST_TYPE !== $screen->post_type || 'post' !== $screen->base) {
			return;
		}

		$user_id = get_current_user_id();
		if ($user_id <= 0) {
			return;
		}

		$key = self::NOTICE_KEY . $user_id;
		$labels = get_transient($key);
		if (! is_array($labels) || empty($labels)) {
			return;
		}
		delete_transient($key);

		echo '<div class="notice notice-error"><p>';
		echo esc_html__('Publicatie geblokkeerd: kritieke Spot Health checks zijn niet gehaald.', 'ddb-spots');
		echo '</p><ul>';
		foreach ($labels as $label) {
			echo '<li>' . esc_html((string) $label) . '</li>';
		}
		echo '</ul></div>';
	}

	public function ajax_validate_prepublish(): void {
		check_ajax_referer(self::PREPUBLISH_NONCE, 'nonce');
		$post_id = isset($_POST['post_id']) ? absint((int) $_POST['post_id']) : 0;
		if ($post_id <= 0 || ! current_user_can('edit_post', $post_id)) {
			wp_send_json_error(array('message' => __('Geen toegang.', 'ddb-spots')), 403);
		}

		$post = get_post($post_id);
		if (! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type) {
			wp_send_json_error(array('message' => __('Spot niet gevonden.', 'ddb-spots')), 404);
		}

		$config = DDB_Spots_Admin_Settings_Page::get_config();
		$critical_failures = $this->get_critical_failures($post, $config);
		$area_term_count = isset($_POST['area_term_count']) ? absint((int) $_POST['area_term_count']) : 0;
		$excerpt_length = isset($_POST['excerpt_length']) ? absint((int) $_POST['excerpt_length']) : 0;
		$content_length = isset($_POST['content_length']) ? absint((int) $_POST['content_length']) : 0;
		$min_excerpt_length = max(140, (int) ($config['ux_rules']['min_excerpt_length'] ?? 140));
		if ($area_term_count > 0 && ! empty($critical_failures)) {
			$critical_failures = array_values(
				array_filter(
					$critical_failures,
					static function (array $check): bool {
						return 'area' !== (string) ($check['key'] ?? '');
					}
				)
			);
		}
		if (($excerpt_length >= $min_excerpt_length || $content_length >= $min_excerpt_length) && ! empty($critical_failures)) {
			$critical_failures = array_values(
				array_filter(
					$critical_failures,
					static function (array $check): bool {
						return 'excerpt' !== (string) ($check['key'] ?? '');
					}
				)
			);
		}
		$hard_block = ! empty($config['ux_rules']['block_publish_on_critical']);

		wp_send_json_success(
			array(
				'ok' => empty($critical_failures),
				'hard_block' => $hard_block,
				'failures' => array_values(
					array_map(
						static function (array $check): array {
							return array(
								'label' => (string) ($check['label'] ?? ''),
								'fix_tab' => (string) ($check['fix_tab'] ?? 'basis'),
								'fix_focus' => (string) ($check['fix_focus'] ?? ''),
							);
						},
						$critical_failures
					)
				),
			)
		);
	}

	private function build_checks(WP_Post $post, array $config): array {
		return $this->quality_policy->build_checks_for_post($post, $config);
	}

	private function get_critical_failures(WP_Post $post, array $config): array {
		return $this->quality_policy->get_critical_failures_for_post($post, $config);
	}

	private function store_publish_gate_notice(array $labels): void {
		$user_id = get_current_user_id();
		if ($user_id <= 0 || empty($labels)) {
			return;
		}
		set_transient(self::NOTICE_KEY . $user_id, array_values($labels), 2 * MINUTE_IN_SECONDS);
	}

	private function get_primary_type(int $post_id): string {
		$override = sanitize_title((string) get_post_meta($post_id, '_ddb_spot_type_primary', true));
		if ('' !== $override) {
			return $override;
		}
		$terms = wp_get_post_terms($post_id, self::TYPE_TAX, array('fields' => 'slugs'));
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
