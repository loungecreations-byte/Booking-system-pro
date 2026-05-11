<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Premium_Engine {
	public const OPTION_KEY = 'ddb_spots_premium_settings';
	public const PAGE_SLUG = 'ddb-spots-premium';
	public const POST_TYPE = 'ddb_spot';
	public const META_PLAN_KEY = '_ddb_premium_plan_key';
	public const META_STATUS = '_ddb_premium_status';
	public const META_PERIOD_END = '_ddb_premium_period_end';
	public const META_BUSINESS_ID = '_ddb_business_id';
	public const META_PLAN_SOURCE = '_ddb_premium_plan_source';
	public const META_TOP_PICK = '_ddb_premium_top_pick';
	public const META_MODULE_CTA_VARIANT = '_ddb_premium_module_cta_variant';
	public const META_MODULE_HIGHLIGHT_BADGE = '_ddb_premium_module_highlight_badge';
	public const META_MODULE_LEAD_FORM = '_ddb_premium_module_lead_form';
	public const PLAN_SOURCE_SPOT = 'spot';
	public const PLAN_SOURCE_BUSINESS = 'business';
	private const NOTICE_KEY = 'ddb_spots_premium_notice_';

	public function init(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('save_post_' . self::POST_TYPE, array($this, 'save_spot_premium_fields'), 50);
		add_action('ddb_spots_editor_render_premium_tab', array($this, 'render_editor_premium_tab'), 10, 1);
		add_action('admin_notices', array($this, 'render_admin_notices'));
		add_action('admin_init', array($this, 'handle_export_request'));
	}

	public static function defaults(): array {
		return array(
			'relevance_threshold' => 60,
			'boost_cap' => 1.20,
			'health_gate' => 75,
			'top_picks_caps' => array(),
			'billing_provider' => 'manual',
		);
	}

	public static function get_settings(): array {
		$saved = get_option(self::OPTION_KEY, array());
		if (! is_array($saved)) {
			$saved = array();
		}

		$settings = array_replace_recursive(self::defaults(), $saved);
		$settings['relevance_threshold'] = max(0, min(100, (int) ($settings['relevance_threshold'] ?? 60)));
		$settings['boost_cap'] = max(1.0, min(2.0, (float) ($settings['boost_cap'] ?? 1.2)));
		$settings['health_gate'] = max(0, min(100, (int) ($settings['health_gate'] ?? 75)));
		$settings['billing_provider'] = 'manual';
		$settings['top_picks_caps'] = self::normalize_top_pick_caps($settings['top_picks_caps'] ?? array());
		return $settings;
	}

	public static function get_setting(string $path, $default = null) {
		$current = self::get_settings();
		foreach (explode('.', $path) as $part) {
			if (! is_array($current) || ! array_key_exists($part, $current)) {
				return $default;
			}
			$current = $current[ $part ];
		}
		return $current;
	}

	public static function get_spot_plan_info(int $spot_id): array {
		$business_id = self::sanitize_business_id((int) get_post_meta($spot_id, self::META_BUSINESS_ID, true));
		$plan_source = self::normalize_plan_source((string) get_post_meta($spot_id, self::META_PLAN_SOURCE, true), $business_id > 0);
		$business_info = array();
		$use_business = false;

		if (self::PLAN_SOURCE_BUSINESS === $plan_source && $business_id > 0 && class_exists('DDB_Spots_Business_Registry')) {
			$business_info = DDB_Spots_Business_Registry::get_business_plan_info($business_id);
			$use_business = $business_id === (int) ($business_info['business_id'] ?? 0);
		}

		if ($use_business) {
			$plan = self::build_plan_info(
				(string) ($business_info['plan_key'] ?? 'free'),
				(string) ($business_info['status'] ?? 'inactive'),
				(string) ($business_info['period_end'] ?? ''),
				array(
					'plan_source' => self::PLAN_SOURCE_BUSINESS,
					'business_id' => $business_id,
					'business_name' => (string) ($business_info['business_name'] ?? ''),
				)
			);
			$plan['override_plan_key'] = ddb_spots_normalize_plan_key((string) get_post_meta($spot_id, self::META_PLAN_KEY, true));
			$plan['override_status'] = sanitize_key((string) get_post_meta($spot_id, self::META_STATUS, true));
			$plan['override_period_end'] = self::sanitize_period_end((string) get_post_meta($spot_id, self::META_PERIOD_END, true));
			return $plan;
		}

		$spot_plan = self::build_plan_info(
			(string) get_post_meta($spot_id, self::META_PLAN_KEY, true),
			(string) get_post_meta($spot_id, self::META_STATUS, true),
			(string) get_post_meta($spot_id, self::META_PERIOD_END, true),
			array(
				'plan_source' => self::PLAN_SOURCE_SPOT,
				'business_id' => $business_id,
				'business_name' => $business_id > 0 ? (string) get_the_title($business_id) : '',
			)
		);
		$spot_plan['override_plan_key'] = (string) $spot_plan['plan_key'];
		$spot_plan['override_status'] = (string) $spot_plan['status'];
		$spot_plan['override_period_end'] = (string) $spot_plan['period_end'];
		return $spot_plan;
	}

	public static function get_spot_modules(int $spot_id): array {
		return array(
			'cta_variant' => '1' === (string) get_post_meta($spot_id, self::META_MODULE_CTA_VARIANT, true),
			'highlight_badge' => '1' === (string) get_post_meta($spot_id, self::META_MODULE_HIGHLIGHT_BADGE, true),
			'lead_form' => '1' === (string) get_post_meta($spot_id, self::META_MODULE_LEAD_FORM, true),
		);
	}

	public static function spot_eligibility(int $spot_id, ?int $health_score = null): array {
		$health = null === $health_score ? ddb_spot_health_score($spot_id) : max(0, min(100, $health_score));
		$gate = (int) self::get_setting('health_gate', 75);
		$plan = self::get_spot_plan_info($spot_id);
		$reasons = array();
		if ($health < $gate) {
			$reasons[] = 'health_gate';
		}
		if (empty($plan['is_paid_active'])) {
			$reasons[] = 'plan_inactive';
		}

		return array(
			'eligible' => empty($reasons),
			'health_score' => $health,
			'health_gate' => $gate,
			'reasons' => $reasons,
			'plan_key' => (string) ($plan['plan_key'] ?? 'free'),
		);
	}

	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			__('Premium', 'ddb-spots'),
			__('Premium', 'ddb-spots'),
			DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE,
			self::PAGE_SLUG,
			array($this, 'render_settings_page')
		);
	}

	public function render_editor_premium_tab(WP_Post $post): void {
		$spot_id = (int) $post->ID;
		$plan = self::get_spot_plan_info($spot_id);
		$entitlements = isset($plan['entitlements']) && is_array($plan['entitlements']) ? $plan['entitlements'] : array();
		$business_id = self::sanitize_business_id((int) ($plan['business_id'] ?? 0));
		$plan_source = self::normalize_plan_source((string) ($plan['plan_source'] ?? ''), $business_id > 0);
		$business_options = class_exists('DDB_Spots_Business_Registry') ? DDB_Spots_Business_Registry::list_business_options() : array();
		$override_plan_key = ddb_spots_normalize_plan_key((string) ($plan['override_plan_key'] ?? 'free'));
		$override_status = sanitize_key((string) ($plan['override_status'] ?? 'inactive'));
		if (! in_array($override_status, array('inactive', 'trial', 'active', 'past_due', 'canceled'), true)) {
			$override_status = 'inactive';
		}
		$override_period_end = self::sanitize_period_end((string) ($plan['override_period_end'] ?? ''));
		$health = ddb_spot_health_details($spot_id);
		$modules = self::get_spot_modules($spot_id);
		$plans = ddb_spots_premium_plan_definitions();
		$module_labels = $this->module_labels();
		$allowed_modules = array_values(array_filter(array_map('sanitize_key', (array) ($entitlements['modules'] ?? array()))));
		$can_use_top_picks = ! empty($entitlements['top_picks']);
		$current_top_pick = '1' === (string) get_post_meta($spot_id, self::META_TOP_PICK, true);
		$slot = array(
			'cap' => 0,
			'selected_count' => 0,
			'slot_available' => false,
		);
		if ($can_use_top_picks) {
			$slot = ddb_spots_top_pick_context_for_spot($spot_id);
		}
		$can_toggle_top_pick = $can_use_top_picks && (! empty($slot['slot_available']) || $current_top_pick);
		?>
		<div class="ddb-premium-grid">
			<div class="ddb-premium-card">
				<h4><?php esc_html_e('Plan status', 'ddb-spots'); ?></h4>
				<table class="form-table ddb-premium-form-table">
					<tr>
						<th><label for="ddb_premium_business_id"><?php esc_html_e('Business', 'ddb-spots'); ?></label></th>
						<td>
							<select id="ddb_premium_business_id" name="ddb_premium_business_id">
								<option value="0"><?php esc_html_e('No linked business', 'ddb-spots'); ?></option>
								<?php foreach ($business_options as $business_row) : ?>
									<?php
									$business_option_id = absint((int) ($business_row['id'] ?? 0));
									if ($business_option_id <= 0) {
										continue;
									}
									$business_label = sprintf(
										'%s (%s/%s)',
										(string) ($business_row['title'] ?? ('#' . $business_option_id)),
										sanitize_key((string) ($business_row['plan_key'] ?? 'free')),
										sanitize_key((string) ($business_row['status'] ?? 'inactive'))
									);
									?>
									<option value="<?php echo esc_attr((string) $business_option_id); ?>" <?php selected($business_id, $business_option_id); ?>><?php echo esc_html($business_label); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ddb_premium_plan_source"><?php esc_html_e('Plan source', 'ddb-spots'); ?></label></th>
						<td>
							<select id="ddb_premium_plan_source" name="ddb_premium_plan_source">
								<option value="<?php echo esc_attr(self::PLAN_SOURCE_BUSINESS); ?>" <?php selected($plan_source, self::PLAN_SOURCE_BUSINESS); ?>><?php esc_html_e('Business inheritance', 'ddb-spots'); ?></option>
								<option value="<?php echo esc_attr(self::PLAN_SOURCE_SPOT); ?>" <?php selected($plan_source, self::PLAN_SOURCE_SPOT); ?>><?php esc_html_e('Spot override', 'ddb-spots'); ?></option>
							</select>
							<p class="description">
								<?php
								echo esc_html(self::PLAN_SOURCE_BUSINESS === $plan_source ? __('Effective plan comes from linked business.', 'ddb-spots') : __('Effective plan comes from spot override values below.', 'ddb-spots'));
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Effective plan', 'ddb-spots'); ?></th>
						<td>
							<strong><?php echo esc_html((string) ($plan['label'] ?? $plan['plan_key'])); ?></strong>
							<br />
							<span class="description"><?php echo esc_html(sprintf(__('Status: %1$s, period end: %2$s', 'ddb-spots'), (string) ($plan['status'] ?? 'inactive'), '' !== (string) ($plan['period_end'] ?? '') ? (string) $plan['period_end'] : '—')); ?></span>
						</td>
					</tr>
					<tr>
						<th><label for="ddb_premium_plan_key"><?php esc_html_e('Override plan', 'ddb-spots'); ?></label></th>
						<td>
							<select id="ddb_premium_plan_key" name="ddb_premium_plan_key">
								<?php foreach ($plans as $key => $row) : ?>
									<option value="<?php echo esc_attr((string) $key); ?>" <?php selected($override_plan_key, $key); ?>><?php echo esc_html((string) ($row['label'] ?? $key)); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ddb_premium_status"><?php esc_html_e('Override status', 'ddb-spots'); ?></label></th>
						<td>
							<select id="ddb_premium_status" name="ddb_premium_status">
								<?php foreach (array('inactive', 'trial', 'active', 'past_due', 'canceled') as $status) : ?>
									<option value="<?php echo esc_attr($status); ?>" <?php selected($override_status, $status); ?>><?php echo esc_html($status); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ddb_premium_period_end"><?php esc_html_e('Override period end', 'ddb-spots'); ?></label></th>
						<td><input type="date" id="ddb_premium_period_end" name="ddb_premium_period_end" value="<?php echo esc_attr($override_period_end); ?>" /></td>
					</tr>
				</table>
			</div>

			<div class="ddb-premium-card">
				<h4><?php esc_html_e('Health score', 'ddb-spots'); ?></h4>
				<p class="ddb-premium-score"><strong><?php echo esc_html((string) ($health['score'] ?? 0)); ?></strong>/100</p>
				<?php if (! empty($health['missing'])) : ?>
					<p class="description"><?php esc_html_e('Openstaande punten:', 'ddb-spots'); ?></p>
					<ul class="ddb-premium-list">
						<?php foreach ((array) $health['missing'] as $reason) : ?>
							<li><?php echo esc_html((string) $reason); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="description"><?php esc_html_e('Geen ontbrekende kwaliteitschecks.', 'ddb-spots'); ?></p>
				<?php endif; ?>
			</div>

			<div class="ddb-premium-card">
				<h4><?php esc_html_e('Entitlements', 'ddb-spots'); ?></h4>
				<ul class="ddb-premium-list">
					<li><?php echo esc_html(sprintf(__('Media limiet: %d', 'ddb-spots'), (int) ($entitlements['media_limit'] ?? 0))); ?></li>
					<li><?php echo esc_html(! empty($entitlements['analytics']) ? __('Analytics: enabled', 'ddb-spots') : __('Analytics: disabled', 'ddb-spots')); ?></li>
					<li><?php echo esc_html(! empty($entitlements['top_picks']) ? __('Top Picks: enabled', 'ddb-spots') : __('Top Picks: disabled', 'ddb-spots')); ?></li>
					<li><?php echo esc_html(sprintf(__('Ranking boost: +%s%%', 'ddb-spots'), (string) round(((float) ($entitlements['ranking_boost'] ?? 0.0)) * 100))); ?></li>
				</ul>
			</div>
		</div>

		<div class="ddb-premium-card ddb-premium-card--full">
			<h4><?php esc_html_e('Module settings', 'ddb-spots'); ?></h4>
			<?php if (empty($allowed_modules)) : ?>
				<p class="description"><?php esc_html_e('Geen premium modules beschikbaar voor dit plan.', 'ddb-spots'); ?></p>
			<?php else : ?>
				<?php foreach ($module_labels as $key => $label) : ?>
					<?php $is_allowed = in_array($key, $allowed_modules, true); ?>
					<?php if (! $is_allowed) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<input type="hidden" name="ddb_premium_module_<?php echo esc_attr($key); ?>" value="0" />
					<p>
						<label>
							<input type="checkbox" name="ddb_premium_module_<?php echo esc_attr($key); ?>" value="1" <?php checked(! empty($modules[ $key ])); ?> />
							<?php echo esc_html($label); ?>
						</label>
					</p>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<div class="ddb-premium-card ddb-premium-card--full">
			<h4><?php esc_html_e('Top Picks', 'ddb-spots'); ?></h4>
			<?php if (! $can_use_top_picks) : ?>
				<p class="description"><?php esc_html_e('Alleen beschikbaar op Partner plan.', 'ddb-spots'); ?></p>
			<?php elseif ((int) ($slot['cap'] ?? 0) <= 0) : ?>
				<p class="description"><?php esc_html_e('Geen slotcap geconfigureerd voor deze category/area.', 'ddb-spots'); ?></p>
			<?php else : ?>
				<p class="description"><?php echo esc_html(sprintf(__('Combinatie cap: %d, bezet: %d', 'ddb-spots'), (int) ($slot['cap'] ?? 0), (int) ($slot['selected_count'] ?? 0))); ?></p>
				<?php if ($can_toggle_top_pick) : ?>
					<input type="hidden" name="ddb_premium_top_pick" value="0" />
					<label>
						<input type="checkbox" name="ddb_premium_top_pick" value="1" <?php checked($current_top_pick); ?> />
						<?php esc_html_e('Activeren als Top Pick kandidaat', 'ddb-spots'); ?>
					</label>
				<?php else : ?>
					<p class="description"><?php esc_html_e('Geen vrij slot in deze combinatie.', 'ddb-spots'); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function save_spot_premium_fields(int $post_id): void {
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

		$business_id = isset($_POST['ddb_premium_business_id']) ? self::sanitize_business_id((int) wp_unslash((string) $_POST['ddb_premium_business_id'])) : self::sanitize_business_id((int) get_post_meta($post_id, self::META_BUSINESS_ID, true));
		$requested_source = isset($_POST['ddb_premium_plan_source']) ? sanitize_key((string) wp_unslash($_POST['ddb_premium_plan_source'])) : sanitize_key((string) get_post_meta($post_id, self::META_PLAN_SOURCE, true));
		$plan_source = self::normalize_plan_source($requested_source, $business_id > 0);
		if (self::PLAN_SOURCE_BUSINESS === $requested_source && $business_id <= 0) {
			$this->push_notice(__('Business inheritance niet opgeslagen: koppel eerst een geldig business-profiel.', 'ddb-spots'));
		}

		$plan_key = isset($_POST['ddb_premium_plan_key']) ? ddb_spots_normalize_plan_key(sanitize_key((string) wp_unslash($_POST['ddb_premium_plan_key']))) : ddb_spots_normalize_plan_key((string) get_post_meta($post_id, self::META_PLAN_KEY, true));
		$status = isset($_POST['ddb_premium_status']) ? sanitize_key((string) wp_unslash($_POST['ddb_premium_status'])) : sanitize_key((string) get_post_meta($post_id, self::META_STATUS, true));
		if (! in_array($status, array('inactive', 'trial', 'active', 'past_due', 'canceled'), true)) {
			$status = 'inactive';
		}
		$period_end = isset($_POST['ddb_premium_period_end']) ? self::sanitize_period_end((string) wp_unslash($_POST['ddb_premium_period_end'])) : self::sanitize_period_end((string) get_post_meta($post_id, self::META_PERIOD_END, true));

		update_post_meta($post_id, self::META_PLAN_KEY, $plan_key);
		update_post_meta($post_id, self::META_STATUS, $status);
		update_post_meta($post_id, self::META_PERIOD_END, $period_end);
		if ($business_id > 0) {
			update_post_meta($post_id, self::META_BUSINESS_ID, $business_id);
		} else {
			delete_post_meta($post_id, self::META_BUSINESS_ID);
		}
		update_post_meta($post_id, self::META_PLAN_SOURCE, $plan_source);

		$effective_plan = self::get_spot_plan_info($post_id);
		$effective_plan_key = ddb_spots_normalize_plan_key((string) ($effective_plan['plan_key'] ?? 'free'));
		$entitlements = isset($effective_plan['entitlements']) && is_array($effective_plan['entitlements']) ? $effective_plan['entitlements'] : ddb_spots_plan_entitlements($effective_plan_key);
		$this->enforce_gallery_limit($post_id, $entitlements);
		$this->save_modules_with_entitlement_gates($post_id, $entitlements);
		$this->save_top_pick_with_entitlement_gates($post_id, $effective_plan_key, $entitlements);
	}

	private function enforce_gallery_limit(int $post_id, array $entitlements): void {
		$limit = max(0, (int) ($entitlements['media_limit'] ?? 0));
		$raw = (string) get_post_meta($post_id, '_ddb_gallery_ids', true);
		$ids = array_values(array_filter(array_map('absint', array_map('trim', explode(',', $raw)))));
		if ($limit <= 0) {
			if (! empty($ids)) {
				update_post_meta($post_id, '_ddb_gallery_ids', '');
				$this->push_notice(__('Gallery gewist door planlimiet.', 'ddb-spots'));
			}
			return;
		}
		if (count($ids) <= $limit) {
			return;
		}
		$trimmed = array_slice($ids, 0, $limit);
		update_post_meta($post_id, '_ddb_gallery_ids', implode(',', $trimmed));
		$this->push_notice(sprintf(__('Gallery beperkt tot %d items voor dit plan.', 'ddb-spots'), $limit));
	}

	private function save_modules_with_entitlement_gates(int $post_id, array $entitlements): void {
		$allowed = array_values(array_filter(array_map('sanitize_key', (array) ($entitlements['modules'] ?? array()))));
		$meta_map = array(
			'cta_variant' => self::META_MODULE_CTA_VARIANT,
			'highlight_badge' => self::META_MODULE_HIGHLIGHT_BADGE,
			'lead_form' => self::META_MODULE_LEAD_FORM,
		);

		foreach ($meta_map as $module => $meta_key) {
			if (! in_array($module, $allowed, true)) {
				update_post_meta($post_id, $meta_key, '0');
				continue;
			}
			$field = 'ddb_premium_module_' . $module;
			$enabled = isset($_POST[ $field ]) && '1' === (string) wp_unslash((string) $_POST[ $field ]);
			update_post_meta($post_id, $meta_key, $enabled ? '1' : '0');
		}
	}

	private function save_top_pick_with_entitlement_gates(int $post_id, string $plan_key, array $entitlements): void {
		if ('partner' !== $plan_key || empty($entitlements['top_picks'])) {
			update_post_meta($post_id, self::META_TOP_PICK, '0');
			return;
		}

		$request = isset($_POST['ddb_premium_top_pick']) && '1' === (string) wp_unslash((string) $_POST['ddb_premium_top_pick']);
		if (! $request) {
			update_post_meta($post_id, self::META_TOP_PICK, '0');
			return;
		}

		if (! ddb_spots_top_pick_slot_available($post_id)) {
			update_post_meta($post_id, self::META_TOP_PICK, '0');
			$this->push_notice(__('Top Pick niet opgeslagen: geen vrij slot voor category/area.', 'ddb-spots'));
			return;
		}

		update_post_meta($post_id, self::META_TOP_PICK, '1');
	}

	public function render_settings_page(): void {
		if (! current_user_can(DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE)) {
			wp_die(esc_html__('Insufficient permissions.', 'ddb-spots'));
		}

		$notice = '';
		if (isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD']) {
			check_admin_referer('ddb_spots_premium_settings');
			$settings = $this->sanitize_settings($_POST);
			update_option(self::OPTION_KEY, $settings, false);
			$notice = __('Premium settings opgeslagen.', 'ddb-spots');
		}

		$settings = self::get_settings();
		$plans = ddb_spots_premium_plan_definitions();
		$days = isset($_GET['days']) ? max(7, min(90, absint((int) $_GET['days']))) : 30;
		$report = DDB_Spots_Premium_Analytics::spot_report($days);
		$module_totals = DDB_Spots_Premium_Analytics::module_action_totals($days);
		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'post_type' => self::POST_TYPE,
					'page' => self::PAGE_SLUG,
					'ddb_export' => 'premium_events_csv',
					'days' => $days,
				),
				admin_url('edit.php')
			),
			'ddb_spots_premium_export'
		);

		echo '<div class="wrap ddb-admin-ui ddb-admin-ui-wrap">';
		echo '<h1>' . esc_html__('DDB Spots Premium', 'ddb-spots') . '</h1>';
		if ('' !== $notice) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
		}

		echo '<h2>' . esc_html__('Plan Summary', 'ddb-spots') . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Plan', 'ddb-spots') . '</th><th>' . esc_html__('Media', 'ddb-spots') . '</th><th>' . esc_html__('Modules', 'ddb-spots') . '</th><th>' . esc_html__('Boost', 'ddb-spots') . '</th><th>' . esc_html__('Top Picks', 'ddb-spots') . '</th></tr></thead><tbody>';
		foreach ($plans as $key => $row) {
			$entitlements = ddb_spots_plan_entitlements((string) $key);
			echo '<tr>';
			echo '<td><strong>' . esc_html((string) ($row['label'] ?? $key)) . '</strong><br /><span class="description">' . esc_html((string) ($row['description'] ?? '')) . '</span></td>';
			echo '<td>' . esc_html((string) ($entitlements['media_limit'] ?? 0)) . '</td>';
			echo '<td>' . esc_html(empty($entitlements['modules']) ? '—' : implode(', ', array_map('strval', (array) $entitlements['modules']))) . '</td>';
			echo '<td>+' . esc_html((string) round(((float) ($entitlements['ranking_boost'] ?? 0.0)) * 100)) . '%</td>';
			echo '<td>' . esc_html(! empty($entitlements['top_picks']) ? __('Yes', 'ddb-spots') : __('No', 'ddb-spots')) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" style="margin-top:24px;">';
		wp_nonce_field('ddb_spots_premium_settings');
		echo '<h2>' . esc_html__('Premium Settings', 'ddb-spots') . '</h2>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="ddb_premium_relevance_threshold">' . esc_html__('Relevance threshold', 'ddb-spots') . '</label></th><td><input class="small-text" type="number" min="0" max="100" id="ddb_premium_relevance_threshold" name="relevance_threshold" value="' . esc_attr((string) $settings['relevance_threshold']) . '" /><p class="description">' . esc_html__('Boost alleen boven deze base relevance score.', 'ddb-spots') . '</p></td></tr>';
		echo '<tr><th><label for="ddb_premium_boost_cap">' . esc_html__('Boost cap', 'ddb-spots') . '</label></th><td><input class="small-text" type="number" min="1" max="2" step="0.01" id="ddb_premium_boost_cap" name="boost_cap" value="' . esc_attr((string) $settings['boost_cap']) . '" /><p class="description">' . esc_html__('Maximale multiplier op base score (default 1.20).', 'ddb-spots') . '</p></td></tr>';
		echo '<tr><th><label for="ddb_premium_health_gate">' . esc_html__('Health gate', 'ddb-spots') . '</label></th><td><input class="small-text" type="number" min="0" max="100" id="ddb_premium_health_gate" name="health_gate" value="' . esc_attr((string) $settings['health_gate']) . '" /><p class="description">' . esc_html__('Plan-je-Dag eligibility minimaal deze health score.', 'ddb-spots') . '</p></td></tr>';
		echo '<tr><th><label for="ddb_premium_billing_provider">' . esc_html__('Billing provider', 'ddb-spots') . '</label></th><td><select id="ddb_premium_billing_provider" name="billing_provider"><option value="manual" selected="selected">manual</option></select></td></tr>';
		echo '<tr><th><label for="ddb_premium_top_caps">' . esc_html__('Top Picks caps', 'ddb-spots') . '</label></th><td><textarea class="large-text code" rows="7" id="ddb_premium_top_caps" name="top_picks_caps_text" placeholder="12|34=2">' . esc_textarea(self::caps_to_textarea((array) $settings['top_picks_caps'])) . '</textarea><p class="description">' . esc_html__('Per regel: category_id|area_id=cap', 'ddb-spots') . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button(__('Save Premium Settings', 'ddb-spots'));
		echo '</form>';

		echo '<h2 style="margin-top:32px;">' . esc_html(sprintf(__('Premium Analytics (last %d days)', 'ddb-spots'), $days)) . '</h2>';
		echo '<p><a class="button button-secondary" href="' . esc_url($export_url) . '">' . esc_html__('Export CSV', 'ddb-spots') . '</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Spot', 'ddb-spots') . '</th><th>' . esc_html__('Views', 'ddb-spots') . '</th><th>' . esc_html__('CTA Clicks', 'ddb-spots') . '</th><th>' . esc_html__('CTA Types', 'ddb-spots') . '</th><th>' . esc_html__('Module Events', 'ddb-spots') . '</th><th>' . esc_html__('Module Actions', 'ddb-spots') . '</th></tr></thead><tbody>';
		if (empty($report)) {
			echo '<tr><td colspan="6">' . esc_html__('Nog geen premium event data.', 'ddb-spots') . '</td></tr>';
		} else {
			foreach ($report as $row) {
				$spot_id = (int) ($row['spot_id'] ?? 0);
				$title = $spot_id > 0 ? get_the_title($spot_id) : '';
				if ('' === trim((string) $title)) {
					$title = sprintf(__('Spot #%d', 'ddb-spots'), $spot_id);
				}
				$edit_url = $spot_id > 0 ? get_edit_post_link($spot_id, 'raw') : '';
				$types = array();
				foreach ((array) ($row['cta_types'] ?? array()) as $type => $count) {
					$types[] = sanitize_key((string) $type) . ':' . absint((int) $count);
				}
				$module_actions = array();
				foreach ((array) ($row['module_actions'] ?? array()) as $module => $actions) {
					foreach ((array) $actions as $action => $count) {
						$module_actions[] = sanitize_key((string) $module) . '.' . sanitize_key((string) $action) . ':' . absint((int) $count);
					}
				}
				echo '<tr>';
				echo '<td>' . ('' !== $edit_url ? '<a href="' . esc_url($edit_url) . '">' . esc_html((string) $title) . '</a>' : esc_html((string) $title)) . '</td>';
				echo '<td>' . esc_html((string) absint((int) ($row['views'] ?? 0))) . '</td>';
				echo '<td>' . esc_html((string) absint((int) ($row['cta_clicks'] ?? 0))) . '</td>';
				echo '<td>' . esc_html(empty($types) ? '—' : implode(', ', $types)) . '</td>';
				echo '<td>' . esc_html((string) absint((int) ($row['module_events'] ?? 0))) . '</td>';
				echo '<td>' . esc_html(empty($module_actions) ? '—' : implode(', ', $module_actions)) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		echo '<h2 style="margin-top:24px;">' . esc_html(sprintf(__('Module Usage (last %d days)', 'ddb-spots'), $days)) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Module', 'ddb-spots') . '</th><th>' . esc_html__('Actions', 'ddb-spots') . '</th><th>' . esc_html__('Total', 'ddb-spots') . '</th></tr></thead><tbody>';
		if (empty($module_totals)) {
			echo '<tr><td colspan="3">' . esc_html__('Nog geen module event data.', 'ddb-spots') . '</td></tr>';
		} else {
			foreach ($module_totals as $module => $actions) {
				$pairs = array();
				$total = 0;
				foreach ((array) $actions as $action => $count) {
					$count = absint((int) $count);
					$total += $count;
					$pairs[] = sanitize_key((string) $action) . ':' . $count;
				}
				echo '<tr>';
				echo '<td><code>' . esc_html(sanitize_key((string) $module)) . '</code></td>';
				echo '<td>' . esc_html(empty($pairs) ? '—' : implode(', ', $pairs)) . '</td>';
				echo '<td>' . esc_html((string) $total) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	public function handle_export_request(): void {
		if (! is_admin()) {
			return;
		}
		if (! isset($_GET['ddb_export']) || 'premium_events_csv' !== (string) wp_unslash($_GET['ddb_export'])) {
			return;
		}
		if (! isset($_GET['page']) || self::PAGE_SLUG !== sanitize_key((string) wp_unslash($_GET['page']))) {
			return;
		}
		if (! current_user_can(DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE)) {
			return;
		}
		if (! isset($_GET['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])), 'ddb_spots_premium_export')) {
			return;
		}

		$days = isset($_GET['days']) ? max(7, min(90, absint((int) $_GET['days']))) : 30;
		DDB_Spots_Premium_Analytics::export_csv($days);
		exit;
	}

	public function render_admin_notices(): void {
		$screen = get_current_screen();
		if (! $screen || self::POST_TYPE !== $screen->post_type || 'post' !== $screen->base) {
			return;
		}
		$user_id = get_current_user_id();
		if ($user_id <= 0) {
			return;
		}
		$key = self::NOTICE_KEY . $user_id;
		$messages = get_transient($key);
		if (! is_array($messages) || empty($messages)) {
			return;
		}
		delete_transient($key);

		echo '<div class="notice notice-warning is-dismissible"><ul>';
		foreach ($messages as $msg) {
			echo '<li>' . esc_html((string) $msg) . '</li>';
		}
		echo '</ul></div>';
	}

	private function sanitize_settings(array $input): array {
		return array(
			'relevance_threshold' => isset($input['relevance_threshold']) ? max(0, min(100, (int) $input['relevance_threshold'])) : 60,
			'boost_cap' => isset($input['boost_cap']) ? max(1.0, min(2.0, round((float) $input['boost_cap'], 2))) : 1.20,
			'health_gate' => isset($input['health_gate']) ? max(0, min(100, (int) $input['health_gate'])) : 75,
			'billing_provider' => 'manual',
			'top_picks_caps' => isset($input['top_picks_caps_text']) ? self::parse_caps_textarea((string) wp_unslash($input['top_picks_caps_text'])) : array(),
		);
	}

	private function push_notice(string $message): void {
		$user_id = get_current_user_id();
		if ($user_id <= 0 || '' === trim($message)) {
			return;
		}
		$key = self::NOTICE_KEY . $user_id;
		$messages = get_transient($key);
		if (! is_array($messages)) {
			$messages = array();
		}
		$messages[] = $message;
		set_transient($key, array_values(array_unique(array_map('strval', $messages))), 2 * MINUTE_IN_SECONDS);
	}

	private function module_labels(): array {
		return array(
			'cta_variant' => __('CTA variant', 'ddb-spots'),
			'highlight_badge' => __('Highlight badge', 'ddb-spots'),
			'lead_form' => __('Lead form', 'ddb-spots'),
		);
	}

	private static function build_plan_info(string $plan_key_raw, string $status_raw, string $period_end_raw, array $extra = array()): array {
		$plan_key = ddb_spots_normalize_plan_key($plan_key_raw);
		$status = sanitize_key($status_raw);
		if (! in_array($status, array('inactive', 'trial', 'active', 'past_due', 'canceled'), true)) {
			$status = 'inactive';
		}
		$period_end = self::sanitize_period_end($period_end_raw);
		$active_statuses = array('active', 'trial');
		$has_active_status = in_array($status, $active_statuses, true);
		$not_expired = true;
		if ('' !== $period_end) {
			$expiry = strtotime($period_end . ' 23:59:59 UTC');
			$not_expired = false !== $expiry ? $expiry >= time() : false;
		}
		$is_paid_plan = in_array($plan_key, array('presence', 'conversion', 'partner'), true);
		$is_paid_active = $is_paid_plan && $has_active_status && $not_expired;
		$plans = ddb_spots_premium_plan_definitions();
		$label = isset($plans[ $plan_key ]['label']) ? (string) $plans[ $plan_key ]['label'] : ucfirst($plan_key);

		return array_merge(
			array(
				'plan_key' => $plan_key,
				'label' => $label,
				'status' => $status,
				'period_end' => $period_end,
				'is_paid_plan' => $is_paid_plan,
				'is_paid_active' => $is_paid_active,
				'entitlements' => ddb_spots_plan_entitlements($plan_key),
				'plan_source' => self::PLAN_SOURCE_SPOT,
				'business_id' => 0,
				'business_name' => '',
			),
			$extra
		);
	}

	private static function normalize_plan_source(string $value, bool $has_business): string {
		$value = sanitize_key($value);
		if (self::PLAN_SOURCE_BUSINESS === $value && $has_business) {
			return self::PLAN_SOURCE_BUSINESS;
		}
		if (self::PLAN_SOURCE_SPOT === $value) {
			return self::PLAN_SOURCE_SPOT;
		}
		return $has_business ? self::PLAN_SOURCE_BUSINESS : self::PLAN_SOURCE_SPOT;
	}

	private static function sanitize_business_id(int $business_id): int {
		$business_id = absint($business_id);
		if ($business_id <= 0) {
			return 0;
		}
		if (! class_exists('DDB_Spots_Business_Registry')) {
			return 0;
		}
		return DDB_Spots_Business_Registry::is_valid_business_id($business_id) ? $business_id : 0;
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

	private static function parse_caps_textarea(string $raw): array {
		$lines = preg_split('/\r\n|\r|\n/', sanitize_textarea_field($raw));
		if (! is_array($lines)) {
			return array();
		}
		$caps = array();
		foreach ($lines as $line) {
			$line = trim((string) $line);
			if ('' === $line) {
				continue;
			}
			if (! preg_match('/^(\d+)\s*\|\s*(\d+)\s*=\s*(\d+)$/', $line, $matches)) {
				continue;
			}
			$cat = absint((int) $matches[1]);
			$area = absint((int) $matches[2]);
			$cap = max(0, absint((int) $matches[3]));
			if ($cat <= 0 || $area <= 0 || $cap <= 0) {
				continue;
			}
			$caps[ $cat . '|' . $area ] = $cap;
		}
		return $caps;
	}

	private static function normalize_top_pick_caps($value): array {
		if (! is_array($value)) {
			return array();
		}
		$clean = array();
		foreach ($value as $key => $cap) {
			$key = sanitize_text_field((string) $key);
			if (! preg_match('/^(\d+)\|(\d+)$/', $key, $matches)) {
				continue;
			}
			$cat = absint((int) $matches[1]);
			$area = absint((int) $matches[2]);
			$cap = max(0, absint((int) $cap));
			if ($cat <= 0 || $area <= 0 || $cap <= 0) {
				continue;
			}
			$clean[ $cat . '|' . $area ] = $cap;
		}
		return $clean;
	}

	private static function caps_to_textarea(array $caps): string {
		$lines = array();
		foreach ($caps as $key => $cap) {
			$lines[] = sanitize_text_field((string) $key) . '=' . absint((int) $cap);
		}
		return implode("\n", $lines);
	}
}

if (! function_exists('ddb_spots_premium_setting')) {
	function ddb_spots_premium_setting(string $path, $default = null) {
		return DDB_Spots_Premium_Engine::get_setting($path, $default);
	}
}

if (! function_exists('ddb_spots_get_spot_plan_info')) {
	function ddb_spots_get_spot_plan_info(int $spot_id): array {
		return DDB_Spots_Premium_Engine::get_spot_plan_info($spot_id);
	}
}

if (! function_exists('ddb_spots_get_spot_entitlements')) {
	function ddb_spots_get_spot_entitlements(int $spot_id): array {
		$plan = DDB_Spots_Premium_Engine::get_spot_plan_info($spot_id);
		return isset($plan['entitlements']) && is_array($plan['entitlements']) ? $plan['entitlements'] : array();
	}
}

if (! function_exists('ddb_spots_spot_eligibility')) {
	function ddb_spots_spot_eligibility(int $spot_id, ?int $health_score = null): array {
		return DDB_Spots_Premium_Engine::spot_eligibility($spot_id, $health_score);
	}
}

if (! function_exists('ddb_spots_is_module_allowed')) {
	function ddb_spots_is_module_allowed(int $spot_id, string $module_key): bool {
		$entitlements = ddb_spots_get_spot_entitlements($spot_id);
		$modules = array_values(array_filter(array_map('sanitize_key', (array) ($entitlements['modules'] ?? array()))));
		return in_array(sanitize_key($module_key), $modules, true);
	}
}
