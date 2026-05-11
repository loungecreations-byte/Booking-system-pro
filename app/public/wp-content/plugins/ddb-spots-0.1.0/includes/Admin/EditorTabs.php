<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Admin_Editor_Tabs {
	private const POST_TYPE = 'ddb_spot';

	private DDB_Spots $plugin;

	public function __construct(DDB_Spots $plugin) {
		$this->plugin = $plugin;
	}

	public function init(): void {
		add_action('add_meta_boxes_' . self::POST_TYPE, array($this, 'register_workspace_metabox'), 5);
		add_action('add_meta_boxes_' . self::POST_TYPE, array($this, 'cleanup_meta_boxes'), 100);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_filter('default_hidden_meta_boxes', array($this, 'default_hidden_meta_boxes'), 10, 2);
		add_action('admin_notices', array($this, 'render_sync_notices'));
	}

	public function render_sync_notices(): void {
		$screen = get_current_screen();
		if (! $screen || self::POST_TYPE !== $screen->post_type || 'post' !== $screen->base) {
			return;
		}
		if (isset($_GET['ddb_success'])) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Google data succesvol vernieuwd.', 'ddb-spots') . '</p></div>';
		}
		if (isset($_GET['ddb_error'])) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(rawurldecode((string) wp_unslash($_GET['ddb_error']))) . '</p></div>';
		}
	}

	public function register_workspace_metabox(): void {
		add_meta_box('ddb_spot_workspace', __('Spot Workspace', 'ddb-spots'), array($this, 'render_workspace_metabox'), self::POST_TYPE, 'normal', 'high');
	}

	public function cleanup_meta_boxes(): void {
		remove_meta_box('ddb_spot_meta', self::POST_TYPE, 'normal');
		$remove_ids = array('bookmark_button', 'spots_owner', 'linktargetdiv', 'link_suggestions', 'authordiv', 'commentstatusdiv', 'commentsdiv', 'postcustom', 'trackbacksdiv');
		foreach ($remove_ids as $id) {
			remove_meta_box($id, self::POST_TYPE, 'normal');
			remove_meta_box($id, self::POST_TYPE, 'side');
			remove_meta_box($id, self::POST_TYPE, 'advanced');
		}
	}

	public function default_hidden_meta_boxes(array $hidden, WP_Screen $screen): array {
		if (self::POST_TYPE !== $screen->post_type) {
			return $hidden;
		}
		$hidden[] = 'rank_math_content_ai';
		return array_values(array_unique($hidden));
	}

	public function enqueue_assets(string $hook): void {
		if (! in_array($hook, array('post.php', 'post-new.php'), true)) {
			return;
		}
		$screen = get_current_screen();
		if (! $screen || self::POST_TYPE !== $screen->post_type) {
			return;
		}

		$css_ver = filemtime(DDB_SPOTS_PATH . 'assets/css/ddb-spots-admin.css');
		$js_ver = filemtime(DDB_SPOTS_PATH . 'assets/js/ddb-spots-admin.js');

		wp_enqueue_style('ddb-spots-admin', DDB_SPOTS_URL . 'assets/css/ddb-spots-admin.css', array(), $css_ver);
		wp_enqueue_media();
		wp_enqueue_script('ddb-spots-admin', DDB_SPOTS_URL . 'assets/js/ddb-spots-admin.js', array('jquery', 'jquery-ui-sortable'), $js_ver, true);
		wp_localize_script('ddb-spots-admin', 'ddbSpotsAdmin', array(
			'postId' => (int) get_the_ID(),
			'tabStorageKey' => 'ddb_spots_tab_' . get_current_user_id(),
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'prePublishAction' => 'ddb_spots_prepublish_validate',
			'prePublishNonce' => wp_create_nonce('ddb_spots_prepublish_validate'),
		));
	}

	public function render_workspace_metabox(WP_Post $post): void {
		wp_nonce_field('ddb_spots_save_meta', 'ddb_spots_meta_nonce');
		$values = $this->get_meta_values($post->ID);
		$event_value = $this->format_event_date_for_input($values['event_date']);

		// Partner info
		$parent_business_id = absint((int) get_post_meta($post->ID, '_ddb_business_id', true));
		if ($parent_business_id <= 0) {
			$parent_business_id = absint((int) get_post_meta($post->ID, DDB_Spots_Core_Schema::META['parent_business_id'], true));
		}
		$business_options = DDB_Spots_Business_Registry::list_business_options();

		$current_scheme = sanitize_key((string) get_user_option('admin_color', get_current_user_id()));
		$is_dark = 'midnight' === $current_scheme;
		$theme_state_label = $is_dark ? __('Dark actief', 'ddb-spots') : __('Light actief', 'ddb-spots');
		$theme_toggle_label = $is_dark ? __('Schakel naar Light', 'ddb-spots') : __('Schakel naar Dark', 'ddb-spots');
		$theme_toggle_url = wp_nonce_url(add_query_arg(array('action' => 'ddb_spots_toggle_admin_theme'), admin_url('admin-post.php')), 'ddb_spots_toggle_admin_theme');

		$area_terms = get_terms(array('taxonomy' => 'ddb_area', 'hide_empty' => false));
		if (is_wp_error($area_terms) || ! is_array($area_terms)) {
			$area_terms = array();
		}
		$current_area_ids = wp_get_post_terms($post->ID, 'ddb_area', array('fields' => 'ids'));
		if (is_wp_error($current_area_ids) || ! is_array($current_area_ids)) {
			$current_area_ids = array();
		}
		$selected_area_id = ! empty($current_area_ids) ? absint((int) $current_area_ids[0]) : 0;
		if ($selected_area_id <= 0) {
			foreach ($area_terms as $term) {
				if (! $term instanceof WP_Term) {
					continue;
				}
				if ('centrum' === sanitize_title((string) $term->slug) || 'centrum' === strtolower((string) $term->name)) {
					$selected_area_id = absint((int) $term->term_id);
					break;
				}
			}
		}
		?>
		<div id="ddb-spots-editor" class="ddb-spots-editor ddb-admin-ui" data-ddb-post-id="<?php echo esc_attr((string) $post->ID); ?>">
			<div class="ddb-editor-toolbar">
				<div class="ddb-editor-toolbar__item"><strong><?php esc_html_e('Editor thema', 'ddb-spots'); ?>:</strong> <span><?php echo esc_html($theme_state_label); ?></span></div>
				<a class="button button-secondary" href="<?php echo esc_url($theme_toggle_url); ?>"><?php echo esc_html($theme_toggle_label); ?></a>
			</div>

			<div class="ddb-spots-editor__tabs" role="tablist" aria-label="<?php esc_attr_e('Spot bewerk tabs', 'ddb-spots'); ?>">
				<button class="ddb-tab-button" type="button" role="tab" aria-selected="true" aria-controls="ddb-tab-essentials" id="ddb-tab-button-essentials" data-ddb-tab="essentials"><?php esc_html_e('Essentials', 'ddb-spots'); ?></button>
				<button class="ddb-tab-button" type="button" role="tab" aria-selected="false" aria-controls="ddb-tab-daylogic" id="ddb-tab-button-daylogic" data-ddb-tab="daylogic"><?php esc_html_e('Day Logic', 'ddb-spots'); ?></button>
				<button class="ddb-tab-button" type="button" role="tab" aria-selected="false" aria-controls="ddb-tab-bundles" id="ddb-tab-button-bundles" data-ddb-tab="bundles"><?php esc_html_e('Bundles', 'ddb-spots'); ?></button>
				<button class="ddb-tab-button" type="button" role="tab" aria-selected="false" aria-controls="ddb-tab-media" id="ddb-tab-button-media" data-ddb-tab="media"><?php esc_html_e('Media', 'ddb-spots'); ?></button>
				<button class="ddb-tab-button" type="button" role="tab" aria-selected="false" aria-controls="ddb-tab-health" id="ddb-tab-button-health" data-ddb-tab="health"><?php esc_html_e('Health Score', 'ddb-spots'); ?></button>
			</div>

			<section class="ddb-tab-panel is-active" role="tabpanel" id="ddb-tab-essentials" aria-labelledby="ddb-tab-button-essentials" data-ddb-panel="essentials">
				<div class="ddb-workspace-grid ddb-workspace-grid--2">
					<div>
						<div class="ddb-slot" data-ddb-slot="titlediv"></div>
						<div class="ddb-slot" data-ddb-slot="taxonomy-ddb_spot_type"></div>
						<div class="ddb-slot" data-ddb-slot="postexcerpt"></div>
						<div class="ddb-slot" data-ddb-slot="postdivrich"></div>
					</div>
					<div>
						<table class="form-table">
							<tr><th><label for="ddb_booking_provider"><?php esc_html_e('Booking Provider', 'ddb-spots'); ?></label></th><td><select id="ddb_booking_provider" name="ddb_booking_provider"><option value="none" <?php selected('none', $values['booking_provider']); ?>>none</option><option value="formitable" <?php selected('formitable', $values['booking_provider']); ?>>formitable</option><option value="external" <?php selected('external', $values['booking_provider']); ?>>external</option><option value="ticket" <?php selected('ticket', $values['booking_provider']); ?>>ticket</option></select></td></tr>
							<tr data-ddb-provider="external,ticket"><th><label for="ddb_cta_url"><?php esc_html_e('CTA URL', 'ddb-spots'); ?></label></th><td><input class="regular-text" type="url" id="ddb_cta_url" name="ddb_cta_url" value="<?php echo esc_url($values['cta_url']); ?>" /></td></tr>
							<tr><th><label for="ddb_duration_hint"><?php esc_html_e('Duration (minutes)', 'ddb-spots'); ?></label></th><td><input class="small-text" type="number" min="0" id="ddb_duration_hint" name="ddb_duration_hint" value="<?php echo esc_attr($values['duration_hint']); ?>" /></td></tr>
							<tr><th><label for="ddb_group_max"><?php esc_html_e('Capaciteit', 'ddb-spots'); ?></label></th><td><input class="small-text" type="number" min="0" id="ddb_group_max" name="ddb_group_max" value="<?php echo esc_attr($values['group_max']); ?>" /></td></tr>
							<tr data-ddb-types="event,events"><th><label for="ddb_event_date"><?php esc_html_e('Event Date', 'ddb-spots'); ?></label></th><td><input type="datetime-local" id="ddb_event_date" name="ddb_event_date" value="<?php echo esc_attr($event_value); ?>" /></td></tr>
						</table>
					</div>
				</div>
			</section>
			<section class="ddb-tab-panel" role="tabpanel" id="ddb-tab-daylogic" aria-labelledby="ddb-tab-button-daylogic" data-ddb-panel="daylogic" hidden>
				<div class="ddb-slot" data-ddb-slot="taxonomy-ddb_area"></div>
				<table class="form-table">
					<tr>
						<th><label for="ddb_area_term_id"><?php esc_html_e('Area', 'ddb-spots'); ?></label></th>
						<td>
							<select id="ddb_area_term_id" name="ddb_area_term_id">
								<option value="0"><?php esc_html_e('Selecteer area', 'ddb-spots'); ?></option>
								<?php foreach ($area_terms as $area_term) : ?>
									<?php if (! $area_term instanceof WP_Term) { continue; } ?>
									<option value="<?php echo esc_attr((string) absint((int) $area_term->term_id)); ?>" <?php selected($selected_area_id, absint((int) $area_term->term_id)); ?>><?php echo esc_html((string) $area_term->name); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e('Centrum wordt standaard gekozen wanneer leeg.', 'ddb-spots'); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="ddb_parent_business_id"><?php esc_html_e('Linked Business (Partner)', 'ddb-spots'); ?></label></th>
						<td>
							<select id="ddb_parent_business_id" name="ddb_parent_business_id">
								<option value="0">-- <?php esc_html_e('Geen partner gekoppeld', 'ddb-spots'); ?> --</option>
								<?php foreach ($business_options as $biz) : ?>
									<option value="<?php echo esc_attr((string) $biz['id']); ?>" <?php selected($parent_business_id, $biz['id']); ?>><?php echo esc_html((string) $biz['title']); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e('Koppel deze activiteit aan een geregistreerd bedrijf voor premium functies.', 'ddb-spots'); ?></p>
						</td>
					</tr>
					<?php if (! empty($values['google_place_id'])) : ?>
					<tr>
						<th><?php esc_html_e('Google Insights', 'ddb-spots'); ?></th>
						<td>
							<div class="ddb-google-insights-card" style="background: #f0f0f1; padding: 15px; border-left: 4px solid #4f46e5; border-radius: 4px;">
								<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
									<strong style="font-size: 1.1em; color: #1e293b;">📊 Google Places Live Data</strong>
									<span class="status-tag" style="background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 12px; font-size: 0.8em;">ID: <?php echo esc_html($values['google_place_id']); ?></span>
								</div>
								<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 0.9em;">
									<div>
										<p>⭐ <strong>Rating:</strong> <?php echo esc_html($values['google_rating'] ?: 'N/A'); ?> (<?php echo esc_html($values['google_user_ratings_total'] ?: '0'); ?> reviews)</p>
										<p>📞 <strong>Phone:</strong> <?php echo esc_html($values['google_phone'] ?: 'N/A'); ?></p>
									</div>
									<div>
										<p>🌐 <strong>Website:</strong> <?php echo $values['google_website'] ? '<a href="' . esc_url($values['google_website']) . '" target="_blank">' . esc_html__('Visit', 'ddb-spots') . '</a>' : 'N/A'; ?></p>
										<p>📅 <strong>Last Sync:</strong> <span title="<?php echo esc_attr($values['google_last_synced_at']); ?>"><?php echo $values['google_last_synced_at'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($values['google_last_synced_at'])) : 'Never'; ?></span></p>
									</div>
								</div>
								<div style="margin-top: 15px; border-top: 1px solid #cbd5e1; padding-top: 10px;">
									<button type="button" class="button button-secondary ddb-sync-now" data-place-id="<?php echo esc_attr($values['google_place_id']); ?>">🔄 <?php esc_html_e('Synchroniseer nu', 'ddb-spots'); ?></button>
								</div>
							</div>
						</td>
					</tr>
					<?php endif; ?>
					<tr><th><label for="ddb_source"><?php esc_html_e('Source', 'ddb-spots'); ?></label></th><td><select id="ddb_source" name="ddb_source"><option value="manual" <?php selected('manual', $values['source']); ?>>manual</option><option value="google_places" <?php selected('google_places', $values['source']); ?>>google_places</option><option value="partner" <?php selected('partner', $values['source']); ?>>partner</option></select></td></tr>
					<tr><th><label for="ddb_google_last_synced_at"><?php esc_html_e('Google Last Synced', 'ddb-spots'); ?></label></th><td><input class="regular-text" type="text" id="ddb_google_last_synced_at" name="ddb_google_last_synced_at" value="<?php echo esc_attr($values['google_last_synced_at']); ?>" /></td></tr>
					<tr><th><label for="ddb_address"><?php esc_html_e('Address', 'ddb-spots'); ?></label></th><td><input class="regular-text" type="text" id="ddb_address" name="ddb_address" value="<?php echo esc_attr($values['address']); ?>" /></td></tr>
					<tr><th><label for="ddb_city"><?php esc_html_e('City', 'ddb-spots'); ?></label></th><td><input class="regular-text" type="text" id="ddb_city" name="ddb_city" value="<?php echo esc_attr($values['city']); ?>" /></td></tr>
					<tr><th><label for="ddb_lat"><?php esc_html_e('Latitude', 'ddb-spots'); ?></label></th><td><input class="regular-text" type="text" id="ddb_lat" name="ddb_lat" value="<?php echo esc_attr($values['lat']); ?>" /></td></tr>
					<tr><th><label for="ddb_lng"><?php esc_html_e('Longitude', 'ddb-spots'); ?></label></th><td><input class="regular-text" type="text" id="ddb_lng" name="ddb_lng" value="<?php echo esc_attr($values['lng']); ?>" /></td></tr>
					<tr><th><label for="ddb_best_time_slot"><?php esc_html_e('Best time slot', 'ddb-spots'); ?></label></th><td><select id="ddb_best_time_slot" name="ddb_best_time_slot"><option value="" <?php selected('', $values['best_time_slot']); ?>>—</option><option value="morning" <?php selected('morning', $values['best_time_slot']); ?>>morning</option><option value="lunch" <?php selected('lunch', $values['best_time_slot']); ?>>lunch</option><option value="afternoon" <?php selected('afternoon', $values['best_time_slot']); ?>>afternoon</option><option value="evening" <?php selected('evening', $values['best_time_slot']); ?>>evening</option></select></td></tr>
					<tr><th><label for="ddb_weather_compatibility"><?php esc_html_e('Weather compatibility', 'ddb-spots'); ?></label></th><td><select id="ddb_weather_compatibility" name="ddb_weather_compatibility"><option value="" <?php selected('', $values['weather_compatibility']); ?>>—</option><option value="rainproof" <?php selected('rainproof', $values['weather_compatibility']); ?>>rainproof</option><option value="outdoor" <?php selected('outdoor', $values['weather_compatibility']); ?>>outdoor</option></select></td></tr>
					<tr><th><label for="ddb_group_fit_score"><?php esc_html_e('Group fit score (0-100)', 'ddb-spots'); ?></label></th><td><input class="small-text" type="number" min="0" max="100" id="ddb_group_fit_score" name="ddb_group_fit_score" value="<?php echo esc_attr($values['group_fit_score']); ?>" /></td></tr>
					<tr><th><label for="ddb_walk_distance_to_core"><?php esc_html_e('Walk distance to core (min)', 'ddb-spots'); ?></label></th><td><input class="small-text" type="number" min="0" id="ddb_walk_distance_to_core" name="ddb_walk_distance_to_core" value="<?php echo esc_attr($values['walk_distance_to_core']); ?>" /></td></tr>
					<tr>
						<th><label for="ddb_lock_hours"><?php esc_html_e('Vergrendel data', 'ddb-spots'); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="ddb_lock_hours" name="ddb_lock_hours" value="1" <?php checked('1', $values['lock_hours']); ?> />
								<?php esc_html_e('Voorkom dat Google Sync de openingstijden overschrijft', 'ddb-spots'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e('Openingstijden', 'ddb-spots'); ?></label></th>
						<td>
							<div id="ddb-opening-hours-editor" class="ddb-repeater" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
								<div class="ddb-repeater-items">
									<?php 
									$days = array('Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag', 'Zondag');
									$hours = json_decode($values['opening_hours_json'], true) ?: array();
									foreach ($days as $index => $day) : 
										$day_key = strtolower($day);
										$val = $hours[$day_key] ?? '';
									?>
									<div class="ddb-repeater-row" style="display: grid; grid-template-columns: 120px 1fr; align-items: center; margin-bottom: 8px;">
										<strong><?php echo esc_html($day); ?></strong>
										<input type="text" name="ddb_opening_hours[<?php echo esc_attr($day_key); ?>]" value="<?php echo esc_attr($val); ?>" placeholder="bijv. 09:00 - 18:00 of Gesloten" class="regular-text" style="width: 100%;">
									</div>
									<?php endforeach; ?>
								</div>
								<p class="description"><?php esc_html_e('Google data overschrijft dit bij een sync, tenzij het "Hours" slot vergrendeld is.', 'ddb-spots'); ?></p>
							</div>
							<textarea class="large-text code" style="display:none;" id="ddb_opening_hours_json" name="ddb_opening_hours_json"><?php echo esc_textarea($values['opening_hours_json']); ?></textarea>
						</td>
					</tr>
				</table>
			</section>

			<section class="ddb-tab-panel" role="tabpanel" id="ddb-tab-bundles" aria-labelledby="ddb-tab-button-bundles" data-ddb-panel="bundles" hidden>
				<table class="form-table">
					<tr><th><label for="ddb_near_spots_json"><?php esc_html_e('Near spots JSON', 'ddb-spots'); ?></label></th><td><textarea class="large-text code" rows="3" id="ddb_near_spots_json" name="ddb_near_spots_json"><?php echo esc_textarea($values['near_spots_json']); ?></textarea></td></tr>
					<tr><th><label for="ddb_bundles_json"><?php esc_html_e('Bundles JSON', 'ddb-spots'); ?></label></th><td><textarea class="large-text code" rows="5" id="ddb_bundles_json" name="ddb_bundles_json"><?php echo esc_textarea($values['bundles_json']); ?></textarea></td></tr>
					<tr><th><label for="ddb_priority"><?php esc_html_e('Margin/Priority hint', 'ddb-spots'); ?></label></th><td><input class="small-text" type="number" min="0" id="ddb_priority" name="ddb_priority" value="<?php echo esc_attr($values['priority']); ?>" /></td></tr>
				</table>
				<div class="ddb-premium-inline"><?php do_action('ddb_spots_editor_render_premium_tab', $post); ?></div>
			</section>

			<section class="ddb-tab-panel" role="tabpanel" id="ddb-tab-media" aria-labelledby="ddb-tab-button-media" data-ddb-panel="media" hidden>
				<div class="ddb-workspace-grid ddb-workspace-grid--2">
					<div>
                        <!-- Feature Image (native post thumbnail meta box slot) -->
						<div class="ddb-slot" data-ddb-slot="postimagediv"></div>
                        
                        <!-- Logo -->
                        <div class="ddb-media-group">
                            <h4><?php esc_html_e('Spot Logo', 'ddb-spots'); ?></h4>
                            <p class="description">Selecteer een transparant logo (bijv. PNG of SVG).</p>
                            <div id="ddb-logo-preview" class="ddb-media-preview-container"></div>
                            <input type="hidden" id="ddb_logo_id" name="ddb_logo_id" value="<?php echo esc_attr($values['logo_id']); ?>" />
                            <br/>
                            <button type="button" class="button ddb-media-select-btn" data-input="ddb_logo_id" data-preview="ddb-logo-preview" data-multiple="false">
                                <?php esc_html_e('Logo selecteren', 'ddb-spots'); ?>
                            </button>
                        </div>
                    </div>
                    <div>
                        <!-- Gallery -->
                        <div class="ddb-media-group">
                            <h4><?php esc_html_e('Spot Galerij', 'ddb-spots'); ?></h4>
                            <p class="description">Voeg sfeerbeelden toe. Versleep de foto's om de volgorde te bepalen.</p>
                            <div id="ddb-gallery-preview" class="ddb-media-preview-container ddb-gallery-sortable" style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:10px;"></div>
                            <input type="hidden" id="ddb_gallery_ids" name="ddb_gallery_ids" value="<?php echo esc_attr($values['gallery_ids']); ?>" />
                            <button type="button" class="button ddb-media-select-btn" data-input="ddb_gallery_ids" data-preview="ddb-gallery-preview" data-multiple="true">
                                <?php esc_html_e('Galerij afbeeldingen toevoegen', 'ddb-spots'); ?>
                            </button>
                        </div>
                    </div>
                </div>
			</section>

			<section class="ddb-tab-panel" role="tabpanel" id="ddb-tab-health" aria-labelledby="ddb-tab-button-health" data-ddb-panel="health" hidden>
				<div class="ddb-slot" data-ddb-slot="ddb_spot_health"></div>
				<div class="ddb-slot" data-ddb-slot="taxonomy-ddb_tag"></div>
				<div class="ddb-slot" data-ddb-slot="taxonomy-ddb_category"></div>
				<div class="ddb-slot" data-ddb-slot="rank_math_metabox"></div>
			</section>
		</div>
		<?php
	}

	private function get_meta_values(int $post_id): array {
		return array(
			'booking_provider'        => (string) get_post_meta($post_id, '_ddb_booking_provider', true),
			'cta_url'                 => (string) get_post_meta($post_id, '_ddb_cta_url', true),
			'group_max'               => (string) get_post_meta($post_id, '_ddb_group_max', true),
			'duration_hint'           => (string) get_post_meta($post_id, '_ddb_duration_hint', true),
			'source'                  => (string) get_post_meta($post_id, '_ddb_source', true),
			'address'                 => (string) get_post_meta($post_id, '_ddb_address', true),
			'city'                    => (string) get_post_meta($post_id, '_ddb_city', true),
			'lat'                     => (string) get_post_meta($post_id, '_ddb_lat', true),
			'lng'                     => (string) get_post_meta($post_id, '_ddb_lng', true),
			'best_time_slot'          => (string) get_post_meta($post_id, '_ddb_best_time_slot', true),
			'weather_compatibility'   => (string) get_post_meta($post_id, '_ddb_weather_compatibility', true),
			'group_fit_score'         => (string) get_post_meta($post_id, '_ddb_group_fit_score', true),
			'walk_distance_to_core'   => (string) get_post_meta($post_id, '_ddb_walk_distance_to_core', true),
			'opening_hours_json'      => (string) get_post_meta($post_id, '_ddb_opening_hours_json', true),
			'near_spots_json'         => (string) get_post_meta($post_id, '_ddb_near_spots_json', true),
			'bundles_json'            => (string) get_post_meta($post_id, '_ddb_bundles_json', true),
			'gallery_ids'             => (string) get_post_meta($post_id, '_ddb_gallery_ids', true),
			'logo_id'                 => (string) get_post_meta($post_id, '_ddb_logo_id', true),
			'priority'                => (string) get_post_meta($post_id, '_ddb_priority', true),
			'event_date'              => (string) get_post_meta($post_id, '_ddb_event_date', true),
			'google_place_id'         => (string) get_post_meta($post_id, DDB_Spots_Core_Schema::META['google_place_id'], true),
			'google_rating'           => (string) get_post_meta($post_id, DDB_Spots_Core_Schema::META['google_rating'], true),
			'google_user_ratings_total' => (string) get_post_meta($post_id, DDB_Spots_Core_Schema::META['google_user_ratings_total'], true),
			'google_phone'            => (string) get_post_meta($post_id, DDB_Spots_Core_Schema::META['google_phone'], true),
			'google_website'          => (string) get_post_meta($post_id, DDB_Spots_Core_Schema::META['google_website'], true),
			'google_last_synced_at'   => (string) get_post_meta($post_id, DDB_Spots_Core_Schema::META['google_last_synced_at'], true),
			'lock_hours'              => (string) get_post_meta($post_id, DDB_Spots_Core_Schema::META['lock_hours'], true),
		);
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
}

