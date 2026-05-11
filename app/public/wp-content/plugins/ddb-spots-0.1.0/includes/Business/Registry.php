<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Business_Registry {
	public const POST_TYPE = 'ddb_business';
	public const META_PLAN_KEY = '_ddb_business_plan_key';
	public const META_STATUS = '_ddb_business_status';
	public const META_PERIOD_END = '_ddb_business_period_end';
	public const META_GOOGLE_PLACE_ID = '_ddb_business_google_place_id';
	public const META_WEBSITE = '_ddb_business_website';
	private const NONCE_ACTION = 'ddb_spots_business_meta';
	private const NONCE_FIELD = 'ddb_spots_business_meta_nonce';

	public function init(): void {
		add_action('init', array($this, 'register_post_type'));
		add_action('add_meta_boxes_' . self::POST_TYPE, array($this, 'register_meta_boxes'));
		add_action('save_post_' . self::POST_TYPE, array($this, 'save_business_meta'));
	}

	public function register_post_type(): void {
		$labels = array(
			'name' => __('Businesses', 'ddb-spots'),
			'singular_name' => __('Business', 'ddb-spots'),
			'add_new_item' => __('Add Business', 'ddb-spots'),
			'edit_item' => __('Edit Business', 'ddb-spots'),
			'new_item' => __('New Business', 'ddb-spots'),
			'view_item' => __('View Business', 'ddb-spots'),
			'search_items' => __('Search Businesses', 'ddb-spots'),
			'not_found' => __('No businesses found', 'ddb-spots'),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => $labels,
				'public' => false,
				'show_ui' => true,
				'show_in_menu' => 'edit.php?post_type=ddb_spot',
				'supports' => array('title'),
				'show_in_rest' => false,
				'capability_type' => 'post',
				'map_meta_cap' => true,
			)
		);
	}

	public function register_meta_boxes(): void {
		add_meta_box(
			'ddb_business_plan',
			__('Business Premium Plan', 'ddb-spots'),
			array($this, 'render_plan_meta_box'),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function render_plan_meta_box(WP_Post $post): void {
		$info = self::get_business_plan_info((int) $post->ID);
		$plans = ddb_spots_premium_plan_definitions();
		wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
		?>
		<table class="form-table">
			<tr>
				<th><label for="ddb_business_plan_key"><?php esc_html_e('Plan', 'ddb-spots'); ?></label></th>
				<td>
					<select id="ddb_business_plan_key" name="ddb_business_plan_key">
						<?php foreach ($plans as $key => $row) : ?>
							<option value="<?php echo esc_attr((string) $key); ?>" <?php selected((string) ($info['plan_key'] ?? 'free'), (string) $key); ?>><?php echo esc_html((string) ($row['label'] ?? $key)); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="ddb_business_status"><?php esc_html_e('Status', 'ddb-spots'); ?></label></th>
				<td>
					<select id="ddb_business_status" name="ddb_business_status">
						<?php foreach (array('inactive', 'trial', 'active', 'past_due', 'canceled') as $status) : ?>
							<option value="<?php echo esc_attr($status); ?>" <?php selected((string) ($info['status'] ?? 'inactive'), $status); ?>><?php echo esc_html($status); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="ddb_business_period_end"><?php esc_html_e('Period end', 'ddb-spots'); ?></label></th>
				<td><input type="date" id="ddb_business_period_end" name="ddb_business_period_end" value="<?php echo esc_attr((string) ($info['period_end'] ?? '')); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ddb_business_google_place_id"><?php esc_html_e('Google Place ID', 'ddb-spots'); ?></label></th>
				<td>
					<input class="regular-text" type="text" id="ddb_business_google_place_id" name="ddb_business_google_place_id" value="<?php echo esc_attr((string) ($info['google_place_id'] ?? '')); ?>" />
					<p class="description"><?php esc_html_e('Used for exact spot-to-business linking.', 'ddb-spots'); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="ddb_business_website"><?php esc_html_e('Website URL', 'ddb-spots'); ?></label></th>
				<td>
					<input class="regular-text" type="url" id="ddb_business_website" name="ddb_business_website" value="<?php echo esc_attr((string) ($info['website'] ?? '')); ?>" />
					<p class="description"><?php esc_html_e('Used for domain-based spot linking.', 'ddb-spots'); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_business_meta(int $post_id): void {
		if (! isset($_POST[ self::NONCE_FIELD ])) {
			return;
		}
		$nonce = sanitize_text_field(wp_unslash((string) $_POST[ self::NONCE_FIELD ]));
		if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			return;
		}
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$plan_key = isset($_POST['ddb_business_plan_key']) ? ddb_spots_normalize_plan_key(sanitize_key((string) wp_unslash($_POST['ddb_business_plan_key']))) : 'free';
		$status = isset($_POST['ddb_business_status']) ? sanitize_key((string) wp_unslash($_POST['ddb_business_status'])) : 'inactive';
		if (! in_array($status, array('inactive', 'trial', 'active', 'past_due', 'canceled'), true)) {
			$status = 'inactive';
		}
		$period_end = isset($_POST['ddb_business_period_end']) ? self::sanitize_period_end((string) wp_unslash($_POST['ddb_business_period_end'])) : '';
		$google_place_id = isset($_POST['ddb_business_google_place_id']) ? sanitize_text_field((string) wp_unslash($_POST['ddb_business_google_place_id'])) : '';
		$website = isset($_POST['ddb_business_website']) ? esc_url_raw((string) wp_unslash($_POST['ddb_business_website'])) : '';

		update_post_meta($post_id, self::META_PLAN_KEY, $plan_key);
		update_post_meta($post_id, self::META_STATUS, $status);
		update_post_meta($post_id, self::META_PERIOD_END, $period_end);
		if ('' !== $google_place_id) {
			update_post_meta($post_id, self::META_GOOGLE_PLACE_ID, $google_place_id);
		} else {
			delete_post_meta($post_id, self::META_GOOGLE_PLACE_ID);
		}
		if ('' !== $website) {
			update_post_meta($post_id, self::META_WEBSITE, $website);
		} else {
			delete_post_meta($post_id, self::META_WEBSITE);
		}
	}

	public static function get_business_plan_info(int $business_id): array {
		$post = $business_id > 0 ? get_post($business_id) : null;
		if (! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type) {
			return self::default_plan_info();
		}

		$plan_key = ddb_spots_normalize_plan_key((string) get_post_meta($business_id, self::META_PLAN_KEY, true));
		$status = sanitize_key((string) get_post_meta($business_id, self::META_STATUS, true));
		if ('' === $status) {
			$status = 'inactive';
		}
		$period_end = self::sanitize_period_end((string) get_post_meta($business_id, self::META_PERIOD_END, true));
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

		return array(
			'business_id' => $business_id,
			'business_name' => (string) get_the_title($business_id),
			'google_place_id' => sanitize_text_field((string) get_post_meta($business_id, self::META_GOOGLE_PLACE_ID, true)),
			'website' => esc_url_raw((string) get_post_meta($business_id, self::META_WEBSITE, true)),
			'plan_key' => $plan_key,
			'label' => $label,
			'status' => $status,
			'period_end' => $period_end,
			'is_paid_plan' => $is_paid_plan,
			'is_paid_active' => $is_paid_active,
			'entitlements' => ddb_spots_plan_entitlements($plan_key),
		);
	}

	public static function list_business_options(int $limit = 500): array {
		$limit = max(1, min(1000, $limit));
		$ids = get_posts(
			array(
				'post_type' => self::POST_TYPE,
				'post_status' => array('publish', 'draft', 'private', 'pending'),
				'posts_per_page' => $limit,
				'fields' => 'ids',
				'orderby' => 'title',
				'order' => 'ASC',
				'no_found_rows' => true,
			)
		);

		$out = array();
		foreach ((array) $ids as $business_id) {
			$business_id = absint((int) $business_id);
			if ($business_id <= 0) {
				continue;
			}
			$info = self::get_business_plan_info($business_id);
			$title = trim((string) get_the_title($business_id));
			if ('' === $title) {
				$title = sprintf(__('Business #%d', 'ddb-spots'), $business_id);
			}
			$out[] = array(
				'id' => $business_id,
				'title' => $title,
				'plan_key' => (string) ($info['plan_key'] ?? 'free'),
				'status' => (string) ($info['status'] ?? 'inactive'),
			);
		}
		return $out;
	}

	public static function is_valid_business_id(int $business_id): bool {
		$post = $business_id > 0 ? get_post($business_id) : null;
		return $post instanceof WP_Post && self::POST_TYPE === $post->post_type && ! in_array((string) $post->post_status, array('trash', 'auto-draft'), true);
	}

	private static function default_plan_info(): array {
		return array(
			'business_id' => 0,
			'business_name' => '',
			'google_place_id' => '',
			'website' => '',
			'plan_key' => 'free',
			'label' => __('Free', 'ddb-spots'),
			'status' => 'inactive',
			'period_end' => '',
			'is_paid_plan' => false,
			'is_paid_active' => false,
			'entitlements' => ddb_spots_plan_entitlements('free'),
		);
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
}

if (! function_exists('ddb_spots_get_business_plan_info')) {
	function ddb_spots_get_business_plan_info(int $business_id): array {
		return DDB_Spots_Business_Registry::get_business_plan_info($business_id);
	}
}
