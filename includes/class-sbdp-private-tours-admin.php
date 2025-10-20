<?php
/**
 * Admin UI for private tours.
 *
 * @package Booking_Pro_Module
 */

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Handles meta boxes, admin columns, and preview flows for private tours.
 */
class SBDP_Private_Tours_Admin {

	/**
	 * Track bootstrap state.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Wire admin hooks.
	 */
	public static function init(): void {
		if (self::$booted) {
			return;
		}

		self::$booted = true;

		add_action('add_meta_boxes', array(__CLASS__, 'register_meta_boxes'));
		add_action('save_post_sbdp_private_tour', array(__CLASS__, 'save_tour_meta'), 10, 2);
		add_action('save_post_sbdp_private_tour_step', array(__CLASS__, 'save_step_meta'), 10, 2);

		add_filter('manage_edit-sbdp_private_tour_columns', array(__CLASS__, 'register_tour_columns'));
		add_action('manage_sbdp_private_tour_posts_custom_column', array(__CLASS__, 'render_tour_columns'), 10, 2);
		add_filter('manage_edit-sbdp_private_tour_sortable_columns', array(__CLASS__, 'register_sortable_columns'));
		add_action('restrict_manage_posts', array(__CLASS__, 'render_tour_filters'));
		add_action('pre_get_posts', array(__CLASS__, 'handle_admin_query'));

		add_filter('post_row_actions', array(__CLASS__, 'register_preview_row_action'), 10, 2);
		add_action('admin_post_sbdp_preview_tour', array(__CLASS__, 'handle_preview_request'));
	}

	/**
	 * Register admin meta boxes.
	 */
	public static function register_meta_boxes(): void {
		add_meta_box(
			'sbdp_tour_details',
			__('Tour Details', 'sbdp'),
			array(__CLASS__, 'render_tour_meta_box'),
			'sbdp_private_tour',
			'normal',
			'high'
		);

		add_meta_box(
			'sbdp_tour_step_details',
			__('Step Details', 'sbdp'),
			array(__CLASS__, 'render_step_meta_box'),
			'sbdp_private_tour_step',
			'normal',
			'high'
		);
	}

	/**
	 * Render the tour detail fields.
	 *
	 * @param WP_Post $post Tour post.
	 */
	public static function render_tour_meta_box(WP_Post $post): void {
		wp_nonce_field('sbdp_save_tour_meta', 'sbdp_tour_meta_nonce');

		$summary       = (string) get_post_meta($post->ID, '_sbdp_tour_summary', true);
		$duration      = (int) get_post_meta($post->ID, '_sbdp_tour_duration', true);
		$product_id    = (int) get_post_meta($post->ID, '_sbdp_tour_product_id', true);
		$support_mail  = (string) get_post_meta($post->ID, '_sbdp_tour_support_email', true);
		$chapter_count = (int) get_post_meta($post->ID, '_sbdp_tour_chapter_count', true);

		if ($chapter_count <= 0) {
			$chapter_count = SBDP_Private_Tours_Tickets::get_step_count($post->ID);
		}

		?>
		<p>
			<label for="sbdp_tour_summary"><strong><?php esc_html_e('Short summary', 'sbdp'); ?></strong></label>
			<textarea name="sbdp_tour_summary" id="sbdp_tour_summary" class="widefat" rows="4"><?php echo esc_textarea($summary); ?></textarea>
		</p>
		<p>
			<label for="sbdp_tour_duration"><strong><?php esc_html_e('Duration (minutes)', 'sbdp'); ?></strong></label>
			<input type="number" min="0" name="sbdp_tour_duration" id="sbdp_tour_duration" value="<?php echo esc_attr($duration); ?>" class="small-text" />
		</p>
		<p>
			<label for="sbdp_tour_product_id"><strong><?php esc_html_e('WooCommerce product ID', 'sbdp'); ?></strong></label>
			<input type="number" min="0" name="sbdp_tour_product_id" id="sbdp_tour_product_id" value="<?php echo esc_attr($product_id); ?>" class="small-text" />
			<span class="description"><?php esc_html_e('Link this tour to a product to auto-issue tickets after purchase.', 'sbdp'); ?></span>
		</p>
		<p>
			<label for="sbdp_tour_support_email"><strong><?php esc_html_e('Support email', 'sbdp'); ?></strong></label>
			<input type="email" name="sbdp_tour_support_email" id="sbdp_tour_support_email" value="<?php echo esc_attr($support_mail); ?>" class="regular-text" />
		</p>
		<p>
			<label for="sbdp_tour_chapter_count"><strong><?php esc_html_e('Aantal hoofdstukken', 'sbdp'); ?></strong></label>
			<input type="number" min="0" name="sbdp_tour_chapter_count" id="sbdp_tour_chapter_count" value="<?php echo esc_attr($chapter_count); ?>" class="small-text" />
			<span class="description"><?php esc_html_e('Bepaalt hoeveel hoofdstukken automatisch beschikbaar zijn.', 'sbdp'); ?></span>
		</p>
		<?php
	}

	/**
	 * Render the step detail fields.
	 *
	 * @param WP_Post $post Step post.
	 */
	public static function render_step_meta_box(WP_Post $post): void {
		wp_nonce_field('sbdp_save_step_meta', 'sbdp_step_meta_nonce');

		$parent_id    = (int) $post->post_parent;
		$step_type    = (string) get_post_meta($post->ID, '_sbdp_step_type', true);
		$media_url    = (string) get_post_meta($post->ID, '_sbdp_step_media_url', true);
		$vr_asset     = (string) get_post_meta($post->ID, '_sbdp_step_vr_asset', true);
		$gamification = (string) get_post_meta($post->ID, '_sbdp_step_gamification', true);
		$points       = (int) get_post_meta($post->ID, '_sbdp_step_points', true);

		$tours = get_posts(
			array(
				'post_type'      => 'sbdp_private_tour',
				'post_status'    => array('publish', 'draft'),
				'numberposts'    => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		?>
		<p>
			<label for="sbdp_step_parent"><strong><?php esc_html_e('Parent tour', 'sbdp'); ?></strong></label>
			<select name="sbdp_step_parent" id="sbdp_step_parent" class="widefat">
				<option value="0"><?php esc_html_e('Select a tour', 'sbdp'); ?></option>
				<?php foreach ($tours as $tour_id) : ?>
					<option value="<?php echo esc_attr((int) $tour_id); ?>" <?php selected($parent_id, (int) $tour_id); ?>>
						<?php echo esc_html(get_the_title((int) $tour_id)); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="sbdp_step_type"><strong><?php esc_html_e('Step type', 'sbdp'); ?></strong></label>
			<select name="sbdp_step_type" id="sbdp_step_type" class="widefat">
				<?php foreach (SBDP_Private_Tours::get_step_types() as $type => $label) : ?>
					<option value="<?php echo esc_attr($type); ?>" <?php selected($step_type, $type); ?>>
						<?php echo esc_html($label); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="sbdp_step_media_url"><strong><?php esc_html_e('Media URL', 'sbdp'); ?></strong></label>
			<input type="url" name="sbdp_step_media_url" id="sbdp_step_media_url" value="<?php echo esc_attr($media_url); ?>" class="widefat" />
			<span class="description"><?php esc_html_e('Link to video, audio, or downloadable resource.', 'sbdp'); ?></span>
		</p>
		<p>
			<label for="sbdp_step_vr_asset"><strong><?php esc_html_e('VR/AR asset URL', 'sbdp'); ?></strong></label>
			<input type="url" name="sbdp_step_vr_asset" id="sbdp_step_vr_asset" value="<?php echo esc_attr($vr_asset); ?>" class="widefat" />
			<span class="description"><?php esc_html_e('360 scene, WebXR room, or external VR experience link.', 'sbdp'); ?></span>
		</p>
		<p>
			<label for="sbdp_step_gamification"><strong><?php esc_html_e('Gamification payload (JSON)', 'sbdp'); ?></strong></label>
			<textarea name="sbdp_step_gamification" id="sbdp_step_gamification" class="widefat code" rows="4"><?php echo esc_textarea($gamification); ?></textarea>
		</p>
		<p>
			<label for="sbdp_step_points"><strong><?php esc_html_e('Points awarded', 'sbdp'); ?></strong></label>
			<input type="number" min="0" name="sbdp_step_points" id="sbdp_step_points" value="<?php echo esc_attr($points); ?>" class="small-text" />
		</p>
		<?php
	}

	/**
	 * Persist tour metadata.
	 *
	 * @param int     $post_id Post identifier.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_tour_meta(int $post_id, WP_Post $post): void {
		if (! isset($_POST['sbdp_tour_meta_nonce']) || ! wp_verify_nonce(sanitize_key($_POST['sbdp_tour_meta_nonce']), 'sbdp_save_tour_meta')) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if ('sbdp_private_tour' !== $post->post_type) {
			return;
		}

		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$summary       = isset($_POST['sbdp_tour_summary']) ? wp_kses_post(wp_unslash($_POST['sbdp_tour_summary'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$duration      = isset($_POST['sbdp_tour_duration']) ? absint($_POST['sbdp_tour_duration']) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$product_id    = isset($_POST['sbdp_tour_product_id']) ? absint($_POST['sbdp_tour_product_id']) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$support       = isset($_POST['sbdp_tour_support_email']) ? sanitize_email(wp_unslash($_POST['sbdp_tour_support_email'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$chapter_count = isset($_POST['sbdp_tour_chapter_count']) ? max(0, absint($_POST['sbdp_tour_chapter_count'])) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		update_post_meta($post_id, '_sbdp_tour_summary', $summary);
		update_post_meta($post_id, '_sbdp_tour_duration', $duration);
		update_post_meta($post_id, '_sbdp_tour_product_id', $product_id);
		update_post_meta($post_id, '_sbdp_tour_support_email', $support);
		update_post_meta($post_id, '_sbdp_tour_chapter_count', $chapter_count);

		self::sync_tour_steps($post_id, $chapter_count);
	}

	/**
	 * Persist step metadata.
	 *
	 * @param int     $post_id Step identifier.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_step_meta(int $post_id, WP_Post $post): void {
		if (! isset($_POST['sbdp_step_meta_nonce']) || ! wp_verify_nonce(sanitize_key($_POST['sbdp_step_meta_nonce']), 'sbdp_save_step_meta')) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if ('sbdp_private_tour_step' !== $post->post_type) {
			return;
		}

		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$parent_id = isset($_POST['sbdp_step_parent']) ? absint($_POST['sbdp_step_parent']) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ($parent_id > 0 && (int) $post->post_parent !== $parent_id) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_parent' => $parent_id,
				)
			);
		}

		$step_type    = isset($_POST['sbdp_step_type']) ? SBDP_Private_Tours::sanitize_step_type(wp_unslash($_POST['sbdp_step_type'])) : 'text'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$media_url    = isset($_POST['sbdp_step_media_url']) ? esc_url_raw(wp_unslash($_POST['sbdp_step_media_url'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$vr_asset     = isset($_POST['sbdp_step_vr_asset']) ? esc_url_raw(wp_unslash($_POST['sbdp_step_vr_asset'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$gamification = isset($_POST['sbdp_step_gamification']) ? SBDP_Private_Tours::sanitize_json_meta(wp_unslash($_POST['sbdp_step_gamification'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$points       = isset($_POST['sbdp_step_points']) ? absint($_POST['sbdp_step_points']) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		update_post_meta($post_id, '_sbdp_step_type', $step_type);
		update_post_meta($post_id, '_sbdp_step_media_url', $media_url);
		update_post_meta($post_id, '_sbdp_step_vr_asset', $vr_asset);
		update_post_meta($post_id, '_sbdp_step_gamification', $gamification);
		update_post_meta($post_id, '_sbdp_step_points', $points);
	}

	/**
	 * Register custom columns for the tours list.
	 *
	 * @param array<string, string> $columns Existing columns.
	 *
	 * @return array<string, string>
	 */
	public static function register_tour_columns(array $columns): array {
		$updated = array();

		foreach ($columns as $key => $label) {
			$updated[$key] = $label;

			if ('title' === $key) {
				$updated['sbdp_tour_product']  = __('Product', 'sbdp');
				$updated['sbdp_tour_duration'] = __('Duur (min)', 'sbdp');
				$updated['sbdp_tour_steps']    = __('Stappen', 'sbdp');
				$updated['sbdp_tour_updated']  = __('Laatst bijgewerkt', 'sbdp');
			}
		}

		return $updated;
	}

	/**
	 * Render data inside custom columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post identifier.
	 */
	public static function render_tour_columns(string $column, int $post_id): void {
		switch ($column) {
			case 'sbdp_tour_product':
				$product_id = (int) get_post_meta($post_id, '_sbdp_tour_product_id', true);
				if ($product_id > 0 && get_post_status($product_id)) {
					printf(
						'<a href="%s">%s</a>',
						esc_url(get_edit_post_link($product_id)),
						esc_html(get_the_title($product_id))
					);
				} else {
					echo '<span class="dashicons dashicons-warning" aria-hidden="true"></span> ';
					esc_html_e('Geen koppeling', 'sbdp');
				}
				break;

			case 'sbdp_tour_duration':
				$duration = (int) get_post_meta($post_id, '_sbdp_tour_duration', true);
				echo $duration > 0 ? esc_html((string) $duration) : '&mdash;';
				break;

			case 'sbdp_tour_steps':
				$count = SBDP_Private_Tours_Tickets::get_step_count($post_id);
				echo esc_html((string) $count);
				break;

			case 'sbdp_tour_updated':
				$modified = get_post_modified_time('U', true, $post_id);
				if ($modified) {
					printf(
						'%s<br /><span class="description">%s</span>',
						esc_html(get_post_modified_time(get_option('date_format'), true, $post_id)),
						esc_html(
							sprintf(
								/* translators: %s: human readable time difference. */
								__('(%s geleden)', 'sbdp'),
								human_time_diff($modified, current_time('timestamp', true))
							)
						)
					);
				} else {
					echo '&mdash;';
				}
				break;
		}
	}

	/**
	 * Mark relevant columns sortable.
	 *
	 * @param array<string, string> $columns Column map.
	 *
	 * @return array<string, string>
	 */
	public static function register_sortable_columns(array $columns): array {
		$columns['sbdp_tour_duration'] = 'sbdp_tour_duration';
		$columns['sbdp_tour_product']  = 'sbdp_tour_product';

		return $columns;
	}

	/**
	 * Render filter dropdown for product linkage.
	 *
	 * @param string $post_type Current post type.
	 */
	public static function render_tour_filters(string $post_type): void {
		if ('sbdp_private_tour' !== $post_type) {
			return;
		}

		$current = isset($_GET['sbdp_tour_product_filter'])
			? sanitize_text_field((string) wp_unslash($_GET['sbdp_tour_product_filter']))
			: '';

		$options = array(
			''        => __('Alle koppelingen', 'sbdp'),
			'with'    => __('Alleen gekoppelde producten', 'sbdp'),
			'without' => __('Zonder productkoppeling', 'sbdp'),
		);

		echo '<label class="screen-reader-text" for="sbdp_tour_product_filter">'
			. esc_html__('Filter op productkoppeling', 'sbdp')
			. '</label>';

		echo '<select name="sbdp_tour_product_filter" id="sbdp_tour_product_filter">';

		foreach ($options as $value => $label) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr($value),
				selected($current, $value, false),
				esc_html($label)
			);
		}

		echo '</select>';
	}

	/**
	 * Tweak admin list queries based on filters/sorting.
	 *
	 * @param WP_Query $query Query instance.
	 */
	public static function handle_admin_query($query): void {
		if (! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query()) {
			return;
		}

		if ('sbdp_private_tour' !== $query->get('post_type')) {
			return;
		}

		$orderby = $query->get('orderby');

		if ('sbdp_tour_duration' === $orderby) {
			$query->set('meta_key', '_sbdp_tour_duration');
			$query->set('orderby', 'meta_value_num');
		} elseif ('sbdp_tour_product' === $orderby) {
			$query->set('meta_key', '_sbdp_tour_product_id');
			$query->set('orderby', 'meta_value_num');
		}

		$filter = isset($_GET['sbdp_tour_product_filter'])
			? sanitize_text_field((string) wp_unslash($_GET['sbdp_tour_product_filter']))
			: '';

		if ('with' === $filter) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'     => '_sbdp_tour_product_id',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				)
			);
		} elseif ('without' === $filter) {
			$query->set(
				'meta_query',
				array(
					'relation' => 'OR',
					array(
						'key'     => '_sbdp_tour_product_id',
						'value'   => 0,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_sbdp_tour_product_id',
						'compare' => 'NOT EXISTS',
					),
				)
			);
		}
	}

	/**
	 * Add preview row action for quick portal checks.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post    Current post.
	 *
	 * @return array<string, string>
	 */
	public static function register_preview_row_action(array $actions, WP_Post $post): array {
		if ('sbdp_private_tour' !== $post->post_type) {
			return $actions;
		}

		if (! current_user_can('edit_post', $post->ID)) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'sbdp_preview_tour',
					'tour_id' => $post->ID,
				),
				admin_url('admin-post.php')
			),
			'sbdp_preview_tour_' . $post->ID
		);

		$actions['sbdp_preview'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url($url),
			esc_html__('Preview in portal', 'sbdp')
		);

		return $actions;
	}

	/**
	 * Handle preview ticket creation and redirect to the portal.
	 */
	public static function handle_preview_request(): void {
		$tour_id = isset($_GET['tour_id']) ? absint($_GET['tour_id']) : 0;

		if (! $tour_id || ! isset($_GET['_wpnonce'])) {
			wp_die(esc_html__('Previewparameters ontbreken.', 'sbdp'));
		}

		if (! wp_verify_nonce(sanitize_key($_GET['_wpnonce']), 'sbdp_preview_tour_' . $tour_id)) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			wp_die(esc_html__('Ongeldige preview-nonce.', 'sbdp'));
		}

		if (! current_user_can('edit_post', $tour_id)) {
			wp_die(esc_html__('Je hebt geen rechten voor deze tour.', 'sbdp'));
		}

		$token = SBDP_Private_Tours_Tickets::create_preview_ticket($tour_id, get_current_user_id());
		if (! $token) {
			wp_die(esc_html__('Kon geen previewticket genereren.', 'sbdp'));
		}

		$portal = SBDP_Private_Tours_Tickets::portal_url();
		$redirect = add_query_arg(
			'sbdp_preview_token',
			$token,
			$portal ?: home_url('/')
		);

		wp_safe_redirect($redirect);
		exit;
	}

	/**
	 * Create or remove tour steps to match desired chapter count.
	 *
	 * @param int $post_id       Tour identifier.
	 * @param int $chapter_count Desired number of chapters.
	 */
	private static function sync_tour_steps(int $post_id, int $chapter_count): void {
		$chapter_count = max(0, $chapter_count);

		$existing_steps = get_posts(
			array(
				'post_type'      => 'sbdp_private_tour_step',
				'post_parent'    => $post_id,
				'post_status'    => array('publish', 'draft', 'pending'),
				'numberposts'    => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
			)
		);

		$current_count = is_array($existing_steps) ? count($existing_steps) : 0;

		if ($chapter_count > $current_count) {
			for ($i = $current_count; $i < $chapter_count; $i++) {
				$title = sprintf(
					/* translators: %d: chapter index. */
					__('Hoofdstuk %d', 'sbdp'),
					$i + 1
				);

				$step_id = wp_insert_post(
					array(
						'post_type'    => 'sbdp_private_tour_step',
						'post_status'  => 'draft',
						'post_title'   => $title,
						'post_parent'  => $post_id,
						'menu_order'   => $i,
						'post_content' => '',
					)
				);

				if (is_wp_error($step_id) || ! $step_id) {
					continue;
				}

				update_post_meta($step_id, '_sbdp_step_type', 'text');
			}
		} elseif ($chapter_count < $current_count) {
			$remove = array_slice($existing_steps, $chapter_count);
			foreach ($remove as $step) {
				wp_trash_post((int) $step->ID);
			}
			$existing_steps = array_slice($existing_steps, 0, $chapter_count);
		}

		$ordered_steps = get_posts(
			array(
				'post_type'      => 'sbdp_private_tour_step',
				'post_parent'    => $post_id,
				'post_status'    => array('publish', 'draft', 'pending'),
				'numberposts'    => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
			)
		);

		foreach ($ordered_steps as $index => $step) {
			if ((int) $step->menu_order !== $index) {
				wp_update_post(
					array(
						'ID'         => $step->ID,
						'menu_order' => $index,
					)
				);
			}

			if ('draft' === $step->post_status && $index < $chapter_count) {
				wp_update_post(
					array(
						'ID'          => $step->ID,
						'post_status' => 'publish',
					)
				);
			}
		}
	}
}
