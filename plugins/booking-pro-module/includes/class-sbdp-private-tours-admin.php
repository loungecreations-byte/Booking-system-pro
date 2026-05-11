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
class SBDP_Private_Tours_Admin
{
    /**
     * Track bootstrap state.
     *
     * @var bool
     */
    private static $booted = false;

    /**
     * Wire admin hooks.
     */
    public static function init(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
        add_filter('admin_body_class', array(__CLASS__, 'add_admin_body_class'));
        add_action('add_meta_boxes', array(__CLASS__, 'register_meta_boxes'));
        add_action('save_post_' . SBDP_Private_Tours::POST_TYPE_TOUR, array(__CLASS__, 'save_tour_meta'), 10, 2);
        add_action('save_post_' . SBDP_Private_Tours::POST_TYPE_TOUR_STEP, array(__CLASS__, 'save_step_meta'), 10, 2);
        add_action('save_post_' . SBDP_Private_Tours::LEGACY_POST_TYPE_TOUR_STEP, array(__CLASS__, 'save_step_meta'), 10, 2);

        add_filter('manage_edit-' . SBDP_Private_Tours::POST_TYPE_TOUR . '_columns', array(__CLASS__, 'register_tour_columns'));
        add_action('manage_' . SBDP_Private_Tours::POST_TYPE_TOUR . '_posts_custom_column', array(__CLASS__, 'render_tour_columns'), 10, 2);
        add_filter('manage_edit-' . SBDP_Private_Tours::POST_TYPE_TOUR . '_sortable_columns', array(__CLASS__, 'register_sortable_columns'));
        add_action('restrict_manage_posts', array(__CLASS__, 'render_tour_filters'));
        add_action('pre_get_posts', array(__CLASS__, 'handle_admin_query'));

        add_filter('post_row_actions', array(__CLASS__, 'register_preview_row_action'), 10, 2);
        add_action('admin_post_sbdp_preview_tour', array(__CLASS__, 'handle_preview_request'));
        add_action('wp_ajax_sbdp_private_tour_templates', array(__CLASS__, 'ajax_search_templates'));
        add_action('wp_ajax_sbdp_private_tour_products', array(__CLASS__, 'ajax_search_products'));
    }

    /**
     * Register admin meta boxes.
     */
    public static function register_meta_boxes(): void
    {
        add_meta_box(
            'sbdp_tour_details',
            __('Tourinstellingen', 'sbdp'),
            array(__CLASS__, 'render_tour_meta_box'),
            SBDP_Private_Tours::POST_TYPE_TOUR,
            'normal',
            'high'
        );

        add_meta_box(
            'sbdp_tour_steps_builder',
            __('Tourstops', 'sbdp'),
            array(__CLASS__, 'render_tour_steps_builder'),
            SBDP_Private_Tours::POST_TYPE_TOUR,
            'normal',
            'default'
        );

        add_meta_box(
            'sbdp_tour_step_details',
            __('Tourstop details', 'sbdp'),
            array(__CLASS__, 'render_step_meta_box'),
            SBDP_Private_Tours::POST_TYPE_TOUR_STEP,
            'normal',
            'high'
        );
    }

    /**
     * Render the tour detail fields.
     *
     * @param WP_Post $post Tour post.
     */
    public static function render_tour_meta_box(WP_Post $post): void
    {
        wp_nonce_field('sbdp_save_tour_meta', 'sbdp_tour_meta_nonce');

        $summary       = (string) get_post_meta($post->ID, '_sbdp_tour_summary', true);
        $duration      = (int) get_post_meta($post->ID, '_sbdp_tour_duration', true);
        $product_id    = (int) get_post_meta($post->ID, '_sbdp_tour_product_id', true);
        $product_title = $product_id > 0 ? get_the_title($product_id) : '';
        $support_mail  = (string) get_post_meta($post->ID, '_sbdp_tour_support_email', true);
        $chapter_count = (int) get_post_meta($post->ID, '_sbdp_tour_chapter_count', true);

        if ($chapter_count <= 0) {
            $chapter_count = SBDP_Private_Tours_Tickets::get_step_count($post->ID);
        }

        ?>
        <div class="sbdp-tour-fields">
            <div class="sbdp-tour-field sbdp-tour-field--notice">
                <strong><?php esc_html_e('Editorflow', 'sbdp'); ?></strong>
                <span class="description"><?php esc_html_e('Gebruik dit scherm voor tourinstellingen en overzicht. Verhaaltekst, headings en missies beheer je per stop in de sectie “Tourstops” hieronder.', 'sbdp'); ?></span>
            </div>

            <label class="sbdp-tour-field" for="sbdp_tour_summary">
                <strong><?php esc_html_e('Short summary', 'sbdp'); ?></strong>
                <textarea name="sbdp_tour_summary" id="sbdp_tour_summary" class="widefat" rows="4"><?php echo esc_textarea($summary); ?></textarea>
            </label>

            <label class="sbdp-tour-field sbdp-tour-field--inline" for="sbdp_tour_duration">
                <strong><?php esc_html_e('Duration (minutes)', 'sbdp'); ?></strong>
                <input type="number" min="0" name="sbdp_tour_duration" id="sbdp_tour_duration" value="<?php echo esc_attr((string) $duration); ?>" class="small-text" />
            </label>

            <div class="sbdp-tour-field" data-private-tour-product data-product-title="<?php echo esc_attr($product_title); ?>">
                <strong><?php esc_html_e('WooCommerce product', 'sbdp'); ?></strong>
                <div class="sbdp-tour-product__controls">
                    <input type="hidden" name="sbdp_tour_product_id" id="sbdp_tour_product_id" value="<?php echo esc_attr((string) $product_id); ?>" />
                    <span data-product-label><?php echo $product_title ? esc_html($product_title) : esc_html__('Link product', 'sbdp'); ?></span>
                    <button type="button" class="button button-secondary" data-product-search-trigger><?php esc_html_e('Search product', 'sbdp'); ?></button>
                    <button type="button" class="button button-link" data-product-clear <?php echo $product_id ? '' : 'hidden'; ?>><?php esc_html_e('Clear link', 'sbdp'); ?></button>
                </div>
                <span class="description"><?php esc_html_e('Link this tour to a product to auto-issue tickets after purchase.', 'sbdp'); ?></span>
                <div class="sbdp-product-search-panel" data-product-panel hidden>
                    <input type="search" data-product-search placeholder="<?php esc_attr_e('Search by title or ID', 'sbdp'); ?>" />
                    <div class="sbdp-product-search-panel__body">
                        <ul class="sbdp-product-search-panel__results" data-product-results></ul>
                        <p class="description" data-product-empty hidden><?php esc_html_e('No products found.', 'sbdp'); ?></p>
                    </div>
                </div>
            </div>

            <label class="sbdp-tour-field" for="sbdp_tour_support_email">
                <strong><?php esc_html_e('Support email', 'sbdp'); ?></strong>
                <input type="email" name="sbdp_tour_support_email" id="sbdp_tour_support_email" value="<?php echo esc_attr($support_mail); ?>" class="regular-text" />
            </label>

            <label class="sbdp-tour-field sbdp-tour-field--inline" for="sbdp_tour_chapter_count">
                <strong><?php esc_html_e('Aantal hoofdstukken', 'sbdp'); ?></strong>
                <input type="number" min="0" name="sbdp_tour_chapter_count" id="sbdp_tour_chapter_count" value="<?php echo esc_attr((string) $chapter_count); ?>" class="small-text" />
                <span class="description"><?php esc_html_e('Bepaalt hoeveel hoofdstukken automatisch beschikbaar zijn.', 'sbdp'); ?></span>
            </label>
        </div>
        <?php
    }

    /**
     * Render the builder UI for steps.
     *
     * @param WP_Post $post Tour post.
     */
    public static function render_tour_steps_builder(WP_Post $post): void
    {
        $blueprint = self::build_tour_blueprint($post->ID);
        $step_count = ! empty($blueprint['steps']) && is_array($blueprint['steps']) ? count($blueprint['steps']) : 0;
        ?>
        <div class="sbdp-private-tour-builder" data-private-tour-builder>
            <div class="sbdp-builder__header">
                <div class="sbdp-builder__header-copy">
                    <h3 class="sbdp-builder__title">Tourstops</h3>
                    <p class="sbdp-builder__subtitle">Beheer per stop het verhaal, de missie, media en locatie. Verhaaltekst ondersteunt <code>h1</code>, <code>h2</code>, <code>h3</code>, paragrafen en sterke tekst.</p>
                </div>
                <div class="sbdp-builder__actions">
                    <button type="button" class="button button-primary" data-private-tour-add-step>
                        Nieuwe stop
                    </button>
                </div>
            </div>

            <div class="sbdp-builder__meta">
                <span class="sbdp-builder__meta-pill"><?php echo esc_html(sprintf(_n('%d stop', '%d stops', $step_count, 'sbdp'), $step_count)); ?></span>
                <span class="sbdp-builder__meta-pill">Klik op <strong>Bewerken</strong> voor tekst, headings en missie</span>
                <span class="sbdp-builder__meta-pill">Kaart hieronder is alleen voor locatie en volgorde</span>
            </div>
            
            <!-- Hidden field to store JSON blueprint -->
            <input type="hidden" id="sbdp_tour_blueprint" name="sbdp_tour_blueprint" value="<?php echo esc_attr(wp_json_encode($blueprint)); ?>" />
            
            <!-- Interactive map for location picking -->
            <div data-builder-map></div>
            
            <!-- Step list with drag & drop -->
            <div data-builder-list></div>
            
            <!-- Empty state -->
            <div data-builder-empty style="display: none;">
                <p style="font-size: 48px; margin: 0;">🗺️</p>
                <p><strong>Nog geen stappen toegevoegd</strong></p>
                <p>Klik op "Nieuwe Stap Toevoegen" om te beginnen met het samenstellen van je tour.</p>
            </div>
        </div>
        
        <div class="sbdp-builder-instructions">
            <p class="sbdp-builder-instructions__title">Werkflow</p>
            <ul class="sbdp-builder-instructions__list">
                <li><strong>Nieuwe stop:</strong> voeg een stop toe en open direct de editor.</li>
                <li><strong>Verhaaltekst:</strong> gebruik headings en alinea's voor een nette storytelling-opbouw.</li>
                <li><strong>Missie:</strong> vul opdracht, hint en reveal apart in.</li>
                <li><strong>Locatie:</strong> klik op de kaart of vul een locatie-label / coördinaten in.</li>
                <li><strong>Volgorde:</strong> sleep stops omhoog of omlaag met het handvat.</li>
            </ul>
        </div>
        <?php
    }

    /**
     * Render the step detail fields.
     *
     * @param WP_Post $post Step post.
     */
    public static function render_step_meta_box(WP_Post $post): void
    {
        wp_nonce_field('sbdp_save_step_meta', 'sbdp_step_meta_nonce');

        $parent_id    = (int) $post->post_parent;
        $step_type    = (string) get_post_meta($post->ID, '_sbdp_step_type', true);
        $media_url    = (string) get_post_meta($post->ID, '_sbdp_step_media_url', true);
        $video_url    = (string) get_post_meta($post->ID, '_sbdp_step_video_url', true);
        $audio_url    = (string) get_post_meta($post->ID, '_sbdp_step_audio_url', true);
        $image_url    = (string) get_post_meta($post->ID, '_sbdp_step_image_url', true);
        $heygen_url   = SBDP_Private_Tours::normalize_heygen_video_url((string) get_post_meta($post->ID, SBDP_Private_Tours::STEP_META_HEYGEN_VIDEO, true));
        $lat          = get_post_meta($post->ID, '_sbdp_step_lat', true);
        $lng          = get_post_meta($post->ID, '_sbdp_step_lng', true);
        $altitude_m   = get_post_meta($post->ID, '_sbdp_step_altitude_m', true);
        $area         = (string) get_post_meta($post->ID, '_sbdp_step_area', true);
        $location_type = (string) get_post_meta($post->ID, '_sbdp_step_location_type', true);
        $location_lbl = (string) get_post_meta($post->ID, '_sbdp_step_location_label', true);
        $template_id  = (int) get_post_meta($post->ID, '_sbdp_step_template_id', true);
        $vr_asset     = (string) get_post_meta($post->ID, '_sbdp_step_vr_asset', true);
        $gamification = (string) get_post_meta($post->ID, '_sbdp_step_gamification', true);
        $mission      = self::parse_gamification_payload($gamification);
        $points       = (int) get_post_meta($post->ID, '_sbdp_step_points', true);

        $tours = get_posts(
            array(
                'post_type'      => SBDP_Private_Tours::POST_TYPE_TOUR,
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
                    <option value="<?php echo esc_attr((string) ((int) $tour_id)); ?>" <?php selected($parent_id, (int) $tour_id); ?>>
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
            <label for="sbdp_step_media_url"><strong><?php esc_html_e('Media URL (fallback)', 'sbdp'); ?></strong></label>
            <input type="url" name="sbdp_step_media_url" id="sbdp_step_media_url" value="<?php echo esc_attr($media_url); ?>" class="widefat" />
            <span class="description"><?php esc_html_e('Used when no video/audio/image URL is set.', 'sbdp'); ?></span>
        </p>
        <p>
            <label for="sbdp_step_video_url"><strong><?php esc_html_e('Video URL', 'sbdp'); ?></strong></label>
            <input type="url" name="sbdp_step_video_url" id="sbdp_step_video_url" value="<?php echo esc_attr($video_url); ?>" class="widefat" />
            <span class="description"><?php esc_html_e('Direct link to a video file or embed URL.', 'sbdp'); ?></span>
        </p>
        <p>
            <label for="sbdp_step_audio_url"><strong><?php esc_html_e('Audio URL', 'sbdp'); ?></strong></label>
            <input type="url" name="sbdp_step_audio_url" id="sbdp_step_audio_url" value="<?php echo esc_attr($audio_url); ?>" class="widefat" />
            <span class="description"><?php esc_html_e('Direct link to an audio file.', 'sbdp'); ?></span>
        </p>
        <p>
            <label for="sbdp_step_image_url"><strong><?php esc_html_e('Image URL', 'sbdp'); ?></strong></label>
            <input type="url" name="sbdp_step_image_url" id="sbdp_step_image_url" value="<?php echo esc_attr($image_url); ?>" class="widefat" />
            <span class="description"><?php esc_html_e('Cover image for the step or a gallery link.', 'sbdp'); ?></span>
        </p>
        <p>
            <label for="sbdp_step_heygen_video_url"><strong><?php esc_html_e('HeyGen Video URL', 'sbdp'); ?></strong></label>
            <input type="url" name="sbdp_step_heygen_video_url" id="sbdp_step_heygen_video_url" value="<?php echo esc_attr($heygen_url); ?>" class="widefat" />
            <span class="description"><?php esc_html_e('Gebruik een HeyGen embed- of share-link (app.heygen.com).', 'sbdp'); ?></span>
        </p>
        <p>
            <label for="sbdp_step_lat"><strong><?php esc_html_e('Latitude', 'sbdp'); ?></strong></label>
            <input type="number" step="any" name="sbdp_step_lat" id="sbdp_step_lat" value="<?php echo esc_attr($lat); ?>" class="small-text" />
        </p>
        <p>
            <label for="sbdp_step_lng"><strong><?php esc_html_e('Longitude', 'sbdp'); ?></strong></label>
            <input type="number" step="any" name="sbdp_step_lng" id="sbdp_step_lng" value="<?php echo esc_attr($lng); ?>" class="small-text" />
        </p>
        <p>
            <label for="sbdp_step_altitude_m"><strong><?php esc_html_e('Altitude (m)', 'sbdp'); ?></strong></label>
            <input type="number" step="any" name="sbdp_step_altitude_m" id="sbdp_step_altitude_m" value="<?php echo esc_attr($altitude_m); ?>" class="small-text" />
        </p>
        <p>
            <label for="sbdp_step_area"><strong><?php esc_html_e('Area', 'sbdp'); ?></strong></label>
            <input type="text" name="sbdp_step_area" id="sbdp_step_area" value="<?php echo esc_attr($area); ?>" class="widefat" placeholder="<?php esc_attr_e('Bijv. Markt', 'sbdp'); ?>" />
        </p>
        <p>
            <label for="sbdp_step_location_type"><strong><?php esc_html_e('Locatie type', 'sbdp'); ?></strong></label>
            <input type="text" name="sbdp_step_location_type" id="sbdp_step_location_type" value="<?php echo esc_attr($location_type); ?>" class="widefat" placeholder="<?php esc_attr_e('Bijv. monument', 'sbdp'); ?>" />
        </p>
        <p>
            <label for="sbdp_step_location_label"><strong><?php esc_html_e('Locatie label', 'sbdp'); ?></strong></label>
            <input type="text" name="sbdp_step_location_label" id="sbdp_step_location_label" value="<?php echo esc_attr($location_lbl); ?>" class="widefat" />
            <span class="description"><?php esc_html_e('Optioneel: geef een herkenbare naam (bijv. “Sint-Janskathedraal”). Laat leeg voor automatische plaatsnaam.', 'sbdp'); ?></span>
        </p>
        <p>
            <label for="sbdp_step_template_id"><strong><?php esc_html_e('Elementor template ID', 'sbdp'); ?></strong></label>
            <input type="number" min="0" name="sbdp_step_template_id" id="sbdp_step_template_id" value="<?php echo esc_attr((string) $template_id); ?>" class="small-text" />
            <span class="description">
                <?php esc_html_e('Use a saved Elementor template to override the step content.', 'sbdp'); ?>
                <?php if ($template_id > 0 && get_post_status($template_id)) : ?>
                    <a href="<?php echo esc_url(get_edit_post_link($template_id)); ?>" target="_blank" rel="noopener">
                        <?php echo esc_html(get_the_title($template_id)); ?>
                    </a>
                <?php endif; ?>
            </span>
        </p>
        <p>
            <label for="sbdp_step_vr_asset"><strong><?php esc_html_e('VR/AR asset URL', 'sbdp'); ?></strong></label>
            <input type="url" name="sbdp_step_vr_asset" id="sbdp_step_vr_asset" value="<?php echo esc_attr($vr_asset); ?>" class="widefat" />
            <span class="description"><?php esc_html_e('360 scene, WebXR room, or external VR experience link.', 'sbdp'); ?></span>
        </p>
        <p>
            <strong><?php esc_html_e('Verhaaltekst', 'sbdp'); ?></strong><br />
            <span class="description"><?php esc_html_e('Gebruik de standaard WordPress editor boven deze velden. H1, H2, H3, paragrafen en sterke tekst worden ondersteund in de tourweergave.', 'sbdp'); ?></span>
        </p>
        <p>
            <label for="sbdp_step_mission_challenge"><strong><?php esc_html_e('Missie opdracht', 'sbdp'); ?></strong></label>
            <textarea name="sbdp_step_mission_challenge" id="sbdp_step_mission_challenge" class="widefat" rows="3"><?php echo esc_textarea((string) ($mission['challenge'] ?? '')); ?></textarea>
            <span class="description"><?php esc_html_e('Korte, concrete opdracht voor deze stop. Bijvoorbeeld: “Zoek de uil boven de deur.”', 'sbdp'); ?></span>
        </p>
        <p>
            <label for="sbdp_step_mission_clue"><strong><?php esc_html_e('Missie hint', 'sbdp'); ?></strong></label>
            <textarea name="sbdp_step_mission_clue" id="sbdp_step_mission_clue" class="widefat" rows="2"><?php echo esc_textarea((string) ($mission['clue'] ?? '')); ?></textarea>
            <span class="description"><?php esc_html_e('Optioneel. Geef een zachte aanwijzing zodat de missie speelser wordt.', 'sbdp'); ?></span>
        </p>
        <p>
            <label for="sbdp_step_mission_reveal"><strong><?php esc_html_e('Missie reveal', 'sbdp'); ?></strong></label>
            <textarea name="sbdp_step_mission_reveal" id="sbdp_step_mission_reveal" class="widefat" rows="2"><?php echo esc_textarea((string) ($mission['reveal'] ?? '')); ?></textarea>
            <span class="description"><?php esc_html_e('Optioneel. Toon wat de gebruiker ontdekt na afronding of aankomst.', 'sbdp'); ?></span>
        </p>
        <p>
            <label for="sbdp_step_gamification"><strong><?php esc_html_e('Gamification payload (JSON)', 'sbdp'); ?></strong></label>
            <textarea name="sbdp_step_gamification" id="sbdp_step_gamification" class="widefat code" rows="4"><?php echo esc_textarea($gamification); ?></textarea>
            <span class="description"><?php esc_html_e('Alleen nodig voor geavanceerde velden. De missievelden hierboven vullen dit automatisch aan.', 'sbdp'); ?></span>
        </p>
        <p>
            <label for="sbdp_step_points"><strong><?php esc_html_e('Points awarded', 'sbdp'); ?></strong></label>
            <input type="number" min="0" name="sbdp_step_points" id="sbdp_step_points" value="<?php echo esc_attr((string) $points); ?>" class="small-text" />
        </p>
        <?php
    }

    /**
     * Enqueue admin assets for the private tour editor.
     *
     * @param string $hook Current admin hook.
     */
    public static function enqueue_admin_assets(string $hook): void
    {
        if (! in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! self::is_private_tour_screen($screen)) {
            return;
        }

        // Force jQuery to load first
        wp_enqueue_script('jquery');

        // Enqueue jQuery UI Sortable (depends on jQuery)
        wp_enqueue_script('jquery-ui-sortable');

        // Enqueue Leaflet for map picker (no dependencies on jQuery)
        wp_enqueue_style(
            'leaflet',
            SBDP_URL . 'assets/css/vendor/leaflet.css',
            array(),
            SBDP_VER
        );

        wp_enqueue_script(
            'leaflet',
            SBDP_URL . 'assets/js/vendor/leaflet.min.js',
            array(),
            SBDP_VER,
            false  // Load in header to ensure it's available
        );

        // Enqueue WordPress media library
        wp_enqueue_media();

        // Enqueue Tour Builder styles
        wp_enqueue_style(
            'sbdp-tour-builder',
            SBDP_URL . 'assets/css/admin/tour-builder.css',
            array(),
            SBDP_VER . '-' . time()  // Cache busting
        );

        // Enqueue old admin styles (for compatibility)
        $private_tour_css_version = SBDP_VER;
        $private_tour_css_file = SBDP_DIR . 'assets/css/admin/private-tour.css';
        if (is_readable($private_tour_css_file)) {
            $mtime = filemtime($private_tour_css_file);
            if (false !== $mtime) {
                $private_tour_css_version = (string) $mtime;
            }
        }

        wp_enqueue_style(
            'sbdp-private-tour-admin',
            SBDP_URL . 'assets/css/admin/private-tour.css',
            array(),
            $private_tour_css_version
        );

        // Enqueue Tour Builder script (load AFTER all dependencies)
        wp_enqueue_script(
            'sbdp-tour-builder',
            SBDP_URL . 'assets/js/admin/tour-builder.js',
            array('jquery', 'jquery-ui-sortable', 'leaflet'),
            SBDP_VER . '-' . time(),  // Cache busting
            true  // Load in footer
        );

        // Add inline debug script to check if everything loads
        wp_add_inline_script(
            'sbdp-tour-builder',
            'console.log("[Tour Builder PHP] Script enqueued successfully");
			console.log("[Tour Builder PHP] jQuery:", typeof jQuery);
			console.log("[Tour Builder PHP] Leaflet:", typeof L);
			console.log("[Tour Builder PHP] wp.media:", typeof wp !== "undefined" ? typeof wp.media : "undefined");',
            'after'
        );

        // OLD admin script - DISABLED to prevent conflicts with new tour-builder.js
        // We keep this enqueued but empty to maintain compatibility
        // Uncomment if you need the old functionality back
        /*
        wp_enqueue_script(
            'sbdp-private-tour-admin',
            SBDP_URL . 'assets/js/admin/private-tour.js',
            array(),
            SBDP_VER,
            true
        );
        */

        $post_id = 0;
        if (! empty($_GET['post'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $post_id = absint($_GET['post']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        } elseif (! empty($_POST['post_ID'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $post_id = absint($_POST['post_ID']); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        } elseif (! empty($GLOBALS['post']) && $GLOBALS['post'] instanceof WP_Post) {
            $post_id = (int) $GLOBALS['post']->ID;
        }

        wp_localize_script(
            'sbdp-tour-builder',
            'sbdpPrivateTourAdmin',
            array(
                'blueprintField' => 'sbdp_tour_blueprint',
                'blueprint'      => $post_id ? self::build_tour_blueprint($post_id) : array('steps' => array()),
                'stepTypes'      => SBDP_Private_Tours::get_step_types(),
                'templateSearch' => array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce'   => wp_create_nonce('sbdp_private_tour_templates'),
                ),
                'productSearch' => array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce'   => wp_create_nonce('sbdp_private_tour_products'),
                ),
                'i18n' => array(
                    'addStep' => __('Nieuwe stap toevoegen', 'sbdp'),
                    'emptyBuilder' => __('Nog geen stappen. Voeg de eerste stap toe om te starten.', 'sbdp'),
                    'contentLabel' => __('Omschrijving', 'sbdp'),
                    'duplicateStep' => __('Kopie', 'sbdp'),
                    'moveUp' => __('Omhoog', 'sbdp'),
                    'moveDown' => __('Omlaag', 'sbdp'),
                    'deleteStep' => __('Verwijder', 'sbdp'),
                    'videoLabel' => __('Video URL', 'sbdp'),
                    'audioLabel' => __('Audio URL', 'sbdp'),
                    'imageLabel' => __('Afbeelding URL', 'sbdp'),
                    'heygenVideoLabel' => __('HeyGen Video URL', 'sbdp'),
                    'pointsLabel' => __('Punten', 'sbdp'),
                    'templateLabel' => __('Elementor template', 'sbdp'),
                    'chooseTemplate' => __('Kies template', 'sbdp'),
                    'clearTemplate' => __('Template ontkoppelen', 'sbdp'),
                    'templateSearchPlaceholder' => __('Zoek op titel of ID van een template.', 'sbdp'),
                    'templateInUse' => __('De inhoud van dit hoofdstuk komt uit de geselecteerde Elementor template.', 'sbdp'),
                    'templateEmpty' => __('Nog geen template gekoppeld.', 'sbdp'),
                    'templateNoResults' => __('Geen templates gevonden. Probeer een andere zoekterm.', 'sbdp'),
                    'templateLoading' => __('Templates laden...', 'sbdp'),
                    'linkProduct' => __('Link product', 'sbdp'),
                    'typeLabel' => __('Type', 'sbdp'),
                    'draftLabel' => __('Concept', 'sbdp'),
                    'noResults' => __('Geen producten gevonden.', 'sbdp'),
                ),
            )
        );
    }

    /**
     * Add a scoped admin body class for tour editors.
     *
     * @param string $classes Existing body classes.
     *
     * @return string
     */
    public static function add_admin_body_class(string $classes): string
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! self::is_private_tour_screen($screen)) {
            return $classes;
        }

        return trim($classes . ' sbdp-tour-admin');
    }

    /**
     * Determine whether the current screen is a tour or tour-step editor.
     *
     * @param mixed $screen Current screen.
     *
     * @return bool
     */
    private static function is_private_tour_screen($screen): bool
    {
        if (! $screen || empty($screen->post_type)) {
            return false;
        }

        return in_array(
            (string) $screen->post_type,
            array(
                SBDP_Private_Tours::POST_TYPE_TOUR,
                SBDP_Private_Tours::POST_TYPE_TOUR_STEP,
                SBDP_Private_Tours::LEGACY_POST_TYPE_TOUR_STEP,
            ),
            true
        );
    }

    /**
     * Build an editable blueprint payload from existing steps.
     *
     * @param int $post_id Tour ID.
     *
     * @return array<string, mixed>
     */
    private static function build_tour_blueprint(int $post_id): array
    {
        $posts = get_posts(
            array(
                'post_type'      => SBDP_Private_Tours::POST_TYPE_TOUR_STEP,
                'post_parent'    => $post_id,
                'post_status'    => array('publish', 'draft', 'pending'),
                'numberposts'    => -1,
                'orderby'        => array(
                    'menu_order' => 'ASC',
                    'ID'         => 'ASC',
                ),
            )
        );

        $steps = array();

        foreach ($posts as $index => $step) {
            $template_id = (int) get_post_meta($step->ID, '_sbdp_step_template_id', true);
            $template_post = $template_id ? get_post($template_id) : null;
            $media_url = (string) get_post_meta($step->ID, '_sbdp_step_media_url', true);
            $audio_url = (string) get_post_meta($step->ID, '_sbdp_step_audio_url', true);
            $gamification = (string) get_post_meta($step->ID, '_sbdp_step_gamification', true);
            $mission = self::parse_gamification_payload($gamification);

            if ('' === $audio_url && '' !== $media_url) {
                $audio_url = $media_url;
            }

            $steps[] = array(
                'id'            => (int) $step->ID,
                'number'        => $index + 1,
                'title'         => (string) $step->post_title,
                'content'       => (string) $step->post_content,
                'videoUrl'      => (string) get_post_meta($step->ID, '_sbdp_step_video_url', true),
                'audioUrl'      => $audio_url,
                'imageUrl'      => (string) get_post_meta($step->ID, '_sbdp_step_image_url', true),
                'heygenVideoUrl' => SBDP_Private_Tours::normalize_heygen_video_url((string) get_post_meta($step->ID, SBDP_Private_Tours::STEP_META_HEYGEN_VIDEO, true)),
                'lat'           => get_post_meta($step->ID, '_sbdp_step_lat', true),
                'lng'           => get_post_meta($step->ID, '_sbdp_step_lng', true),
                'altitudeM'     => get_post_meta($step->ID, '_sbdp_step_altitude_m', true),
                'area'          => (string) get_post_meta($step->ID, '_sbdp_step_area', true),
                'locationType'  => (string) get_post_meta($step->ID, '_sbdp_step_location_type', true),
                'locationLabel' => (string) get_post_meta($step->ID, '_sbdp_step_location_label', true),
                'type'          => (string) get_post_meta($step->ID, '_sbdp_step_type', true),
                'points'        => (int) get_post_meta($step->ID, '_sbdp_step_points', true),
                'vrAsset'       => (string) get_post_meta($step->ID, '_sbdp_step_vr_asset', true),
                'templateId'    => $template_id,
                'templateTitle' => $template_post ? (string) $template_post->post_title : '',
                'templateType'  => $template_post ? (string) get_post_meta($template_post->ID, '_elementor_template_type', true) : '',
                'templateStatus' => $template_post ? (string) $template_post->post_status : '',
                'templateEditUrl' => $template_post ? (string) get_edit_post_link($template_post->ID) : '',
                'gamification'  => $gamification,
                'missionChallenge' => (string) ($mission['challenge'] ?? ''),
                'missionClue'   => (string) ($mission['clue'] ?? ''),
                'missionReveal' => (string) ($mission['reveal'] ?? ''),
            );
        }

        return array('steps' => $steps);
    }

    /**
     * AJAX search for Elementor templates.
     */
    public static function ajax_search_templates(): void
    {
        if (! current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }

        check_ajax_referer('sbdp_private_tour_templates', 'nonce');

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $args = array(
            'post_type'      => 'elementor_library',
            'post_status'    => array('publish', 'draft', 'private'),
            'posts_per_page' => 20,
            's'              => $search,
        );

        if (is_numeric($search)) {
            $args['p'] = absint($search);
            unset($args['s']);
        }

        $query = new WP_Query($args);
        $items = array();

        foreach ($query->posts as $post) {
            $items[] = array(
                'id'     => (int) $post->ID,
                'title'  => $post->post_title,
                'type'   => (string) get_post_meta($post->ID, '_elementor_template_type', true),
                'status' => $post->post_status,
                'edit'   => (string) get_edit_post_link($post->ID),
            );
        }

        wp_send_json_success(array('items' => $items));
    }

    /**
     * AJAX search for WooCommerce products.
     */
    public static function ajax_search_products(): void
    {
        if (! current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }

        check_ajax_referer('sbdp_private_tour_products', 'nonce');

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $items = array();

        if (function_exists('wc_get_products')) {
            $args = array(
                'status' => array('publish', 'draft'),
                'limit'  => 20,
                'search' => $search,
            );

            if (is_numeric($search)) {
                $args['include'] = array(absint($search));
                unset($args['search']);
            }

            $products = wc_get_products($args);
            foreach ($products as $product) {
                $items[] = array(
                    'id'     => (int) $product->get_id(),
                    'title'  => $product->get_name(),
                    'type'   => $product->get_type(),
                    'status' => $product->get_status(),
                );
            }
        } else {
            $args = array(
                'post_type'      => 'product',
                'post_status'    => array('publish', 'draft'),
                'posts_per_page' => 20,
                's'              => $search,
            );
            if (is_numeric($search)) {
                $args['p'] = absint($search);
                unset($args['s']);
            }
            $query = new WP_Query($args);
            foreach ($query->posts as $post) {
                $items[] = array(
                    'id'     => (int) $post->ID,
                    'title'  => $post->post_title,
                    'type'   => 'simple',
                    'status' => $post->post_status,
                );
            }
        }

        wp_send_json_success(array('items' => $items));
    }

    /**
     * Persist tour metadata.
     *
     * @param int     $post_id Post identifier.
     * @param WP_Post $post    Post object.
     */
    public static function save_tour_meta(int $post_id, WP_Post $post): void
    {
        if (! isset($_POST['sbdp_tour_meta_nonce']) || ! wp_verify_nonce(sanitize_key($_POST['sbdp_tour_meta_nonce']), 'sbdp_save_tour_meta')) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (SBDP_Private_Tours::POST_TYPE_TOUR !== $post->post_type) {
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
        $blueprint_raw = isset($_POST['sbdp_tour_blueprint']) ? wp_unslash($_POST['sbdp_tour_blueprint']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        update_post_meta($post_id, '_sbdp_tour_summary', $summary);
        update_post_meta($post_id, '_sbdp_tour_duration', $duration);
        update_post_meta($post_id, '_sbdp_tour_product_id', $product_id);
        update_post_meta($post_id, '_sbdp_tour_support_email', $support);

        $blueprint = self::decode_tour_blueprint($blueprint_raw);
        if (! empty($blueprint['steps']) && is_array($blueprint['steps'])) {
            $applied = self::apply_tour_blueprint($post_id, $blueprint['steps']);
            $chapter_count = count($applied);
        } else {
            self::sync_tour_steps($post_id, $chapter_count);
        }

        update_post_meta($post_id, '_sbdp_tour_chapter_count', $chapter_count);
    }

    /**
     * Decode the blueprint payload from the admin builder.
     *
     * @param mixed $raw Raw payload.
     *
     * @return array<string, mixed>
     */
    private static function decode_tour_blueprint($raw): array
    {
        if (! is_string($raw)) {
            return array();
        }

        $raw = trim($raw);
        if ('' === $raw) {
            return array();
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return array();
        }

        return $decoded;
    }

    /**
     * Decode a stored mission/gamification payload.
     *
     * @param string $value Stored JSON payload.
     *
     * @return array<string, mixed>
     */
    private static function parse_gamification_payload(string $value): array
    {
        $value = trim($value);
        if ('' === $value) {
            return array();
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Merge dedicated mission fields into the gamification payload.
     *
     * @param array<string, mixed> $payload Existing payload.
     * @param array<string, mixed> $fields  Mission fields.
     *
     * @return string
     */
    private static function build_gamification_payload(array $payload, array $fields): string
    {
        foreach (array('challenge', 'clue', 'reveal') as $key) {
            $value = isset($fields[$key]) ? sanitize_textarea_field((string) $fields[$key]) : '';
            if ('' !== $value) {
                $payload[$key] = $value;
            } else {
                unset($payload[$key]);
            }
        }

        return empty($payload) ? '' : SBDP_Private_Tours::sanitize_json_meta(wp_json_encode($payload));
    }

    /**
     * Apply the blueprint steps to tour step posts.
     *
     * @param int   $post_id Tour ID.
     * @param array $steps   Blueprint steps.
     *
     * @return array<int> Step IDs in order.
     */
    private static function apply_tour_blueprint(int $post_id, array $steps): array
    {
        $existing = get_posts(
            array(
                'post_type'      => SBDP_Private_Tours::POST_TYPE_TOUR_STEP,
                'post_parent'    => $post_id,
                'post_status'    => array('publish', 'draft', 'pending'),
                'numberposts'    => -1,
                'orderby'        => array(
                    'menu_order' => 'ASC',
                    'ID'         => 'ASC',
                ),
                'fields'         => 'ids',
            )
        );

        $kept_ids = array();

        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                continue;
            }

            $step_id = isset($step['id']) ? absint($step['id']) : 0;
            $title = isset($step['title']) ? sanitize_text_field($step['title']) : '';
            if ('' === $title) {
                $title = sprintf(
                    /* translators: %d: step index. */
                    __('Hoofdstuk %d', 'sbdp'),
                    $index + 1
                );
            }

            $post_data = array(
                'post_type'    => SBDP_Private_Tours::POST_TYPE_TOUR_STEP,
                'post_status'  => 'publish',
                'post_title'   => $title,
                'post_parent'  => $post_id,
                'menu_order'   => $index,
                'post_content' => isset($step['content']) ? wp_kses_post($step['content']) : '',
            );

            if ($step_id && SBDP_Private_Tours::POST_TYPE_TOUR_STEP === get_post_type($step_id)) {
                $post_data['ID'] = $step_id;
                $updated_id = wp_update_post($post_data, true);
                if (is_wp_error($updated_id) || ! $updated_id) {
                    continue;
                }
                $step_id = (int) $updated_id;
            } else {
                $created_id = wp_insert_post($post_data, true);
                if (is_wp_error($created_id) || ! $created_id) {
                    continue;
                }
                $step_id = (int) $created_id;
            }

            $kept_ids[] = $step_id;

            $step_type = isset($step['type']) ? SBDP_Private_Tours::sanitize_step_type(wp_unslash($step['type'])) : 'text';
            $video_url = isset($step['videoUrl']) ? esc_url_raw(wp_unslash($step['videoUrl'])) : '';
            $audio_url = isset($step['audioUrl']) ? esc_url_raw(wp_unslash($step['audioUrl'])) : '';
            $image_url = isset($step['imageUrl']) ? esc_url_raw(wp_unslash($step['imageUrl'])) : '';
            $heygen_video_url = isset($step['heygenVideoUrl'])
                ? SBDP_Private_Tours::sanitize_heygen_video_url(wp_unslash($step['heygenVideoUrl']))
                : (isset($step['heygen_video_url']) ? SBDP_Private_Tours::sanitize_heygen_video_url(wp_unslash($step['heygen_video_url'])) : '');
            $legacy_media_url = isset($step['mediaUrl']) ? esc_url_raw(wp_unslash($step['mediaUrl'])) : '';
            $media_url = $video_url ?: $audio_url;
            if ('' === $media_url) {
                $media_url = $image_url;
            }
            if ('' === $media_url) {
                $media_url = $legacy_media_url;
            }

            update_post_meta($step_id, '_sbdp_step_type', $step_type);
            update_post_meta($step_id, '_sbdp_step_media_url', $media_url);
            update_post_meta($step_id, '_sbdp_step_video_url', $video_url);
            update_post_meta($step_id, '_sbdp_step_audio_url', $audio_url);
            update_post_meta($step_id, '_sbdp_step_image_url', $image_url);
            update_post_meta($step_id, SBDP_Private_Tours::STEP_META_HEYGEN_VIDEO, $heygen_video_url);

            $vr_asset = isset($step['vrAsset']) ? esc_url_raw(wp_unslash($step['vrAsset'])) : '';
            update_post_meta($step_id, '_sbdp_step_vr_asset', $vr_asset);

            $gamification = isset($step['gamification']) ? wp_unslash($step['gamification']) : '';
            $gamification_payload = self::parse_gamification_payload((string) $gamification);
            $gamification_value = self::build_gamification_payload(
                $gamification_payload,
                array(
                    'challenge' => isset($step['missionChallenge']) ? $step['missionChallenge'] : '',
                    'clue'      => isset($step['missionClue']) ? $step['missionClue'] : '',
                    'reveal'    => isset($step['missionReveal']) ? $step['missionReveal'] : '',
                )
            );
            update_post_meta($step_id, '_sbdp_step_gamification', $gamification_value);

            $points = isset($step['points']) ? absint($step['points']) : 0;
            update_post_meta($step_id, '_sbdp_step_points', $points);

            $lat = isset($step['lat']) ? $step['lat'] : '';
            $lng = isset($step['lng']) ? $step['lng'] : '';
            update_post_meta($step_id, '_sbdp_step_lat', SBDP_Private_Tours::sanitize_coordinate($lat));
            update_post_meta($step_id, '_sbdp_step_lng', SBDP_Private_Tours::sanitize_coordinate($lng));
            $altitude_m = isset($step['altitudeM']) ? $step['altitudeM'] : (isset($step['altitude_m']) ? $step['altitude_m'] : '');
            update_post_meta($step_id, '_sbdp_step_altitude_m', SBDP_Private_Tours::sanitize_altitude($altitude_m));
            $area = isset($step['area']) && is_string($step['area']) ? SBDP_Private_Tours::sanitize_location_area(wp_unslash($step['area'])) : '';
            update_post_meta($step_id, '_sbdp_step_area', $area);
            $location_type = isset($step['locationType']) && is_string($step['locationType'])
                ? SBDP_Private_Tours::sanitize_location_type(wp_unslash($step['locationType']))
                : (isset($step['location_type']) && is_string($step['location_type']) ? SBDP_Private_Tours::sanitize_location_type(wp_unslash($step['location_type'])) : '');
            update_post_meta($step_id, '_sbdp_step_location_type', $location_type);
            $location_label = isset($step['locationLabel']) ? sanitize_text_field(wp_unslash($step['locationLabel'])) : '';
            update_post_meta($step_id, '_sbdp_step_location_label', $location_label);

            $template_id = isset($step['templateId']) ? absint($step['templateId']) : 0;
            update_post_meta($step_id, '_sbdp_step_template_id', $template_id);
        }

        if (! empty($existing)) {
            $remove_ids = array_diff(array_map('absint', $existing), $kept_ids);
            foreach ($remove_ids as $remove_id) {
                if ($remove_id > 0) {
                    wp_trash_post($remove_id);
                }
            }
        }

        return $kept_ids;
    }

    /**
     * Persist step metadata.
     *
     * @param int     $post_id Step identifier.
     * @param WP_Post $post    Post object.
     */
    public static function save_step_meta(int $post_id, WP_Post $post): void
    {
        if (! isset($_POST['sbdp_step_meta_nonce']) || ! wp_verify_nonce(sanitize_key($_POST['sbdp_step_meta_nonce']), 'sbdp_save_step_meta')) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (SBDP_Private_Tours::POST_TYPE_TOUR_STEP !== $post->post_type) {
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
        $video_url    = isset($_POST['sbdp_step_video_url']) ? esc_url_raw(wp_unslash($_POST['sbdp_step_video_url'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $audio_url    = isset($_POST['sbdp_step_audio_url']) ? esc_url_raw(wp_unslash($_POST['sbdp_step_audio_url'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $image_url    = isset($_POST['sbdp_step_image_url']) ? esc_url_raw(wp_unslash($_POST['sbdp_step_image_url'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $heygen_video_url = isset($_POST['sbdp_step_heygen_video_url']) ? SBDP_Private_Tours::sanitize_heygen_video_url(wp_unslash($_POST['sbdp_step_heygen_video_url'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $vr_asset     = isset($_POST['sbdp_step_vr_asset']) ? esc_url_raw(wp_unslash($_POST['sbdp_step_vr_asset'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $gamification_raw = isset($_POST['sbdp_step_gamification']) ? wp_unslash($_POST['sbdp_step_gamification']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $gamification_payload = self::parse_gamification_payload((string) $gamification_raw);
        $gamification = self::build_gamification_payload(
            $gamification_payload,
            array(
                'challenge' => isset($_POST['sbdp_step_mission_challenge']) ? wp_unslash($_POST['sbdp_step_mission_challenge']) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                'clue'      => isset($_POST['sbdp_step_mission_clue']) ? wp_unslash($_POST['sbdp_step_mission_clue']) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                'reveal'    => isset($_POST['sbdp_step_mission_reveal']) ? wp_unslash($_POST['sbdp_step_mission_reveal']) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            )
        );
        $points       = isset($_POST['sbdp_step_points']) ? absint($_POST['sbdp_step_points']) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $lat          = isset($_POST['sbdp_step_lat']) ? SBDP_Private_Tours::sanitize_coordinate(wp_unslash($_POST['sbdp_step_lat'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $lng          = isset($_POST['sbdp_step_lng']) ? SBDP_Private_Tours::sanitize_coordinate(wp_unslash($_POST['sbdp_step_lng'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $altitude_m   = isset($_POST['sbdp_step_altitude_m']) ? SBDP_Private_Tours::sanitize_altitude(wp_unslash($_POST['sbdp_step_altitude_m'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $area         = isset($_POST['sbdp_step_area']) ? SBDP_Private_Tours::sanitize_location_area(wp_unslash($_POST['sbdp_step_area'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $location_type = isset($_POST['sbdp_step_location_type']) ? SBDP_Private_Tours::sanitize_location_type(wp_unslash($_POST['sbdp_step_location_type'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $location_label = isset($_POST['sbdp_step_location_label']) ? sanitize_text_field(wp_unslash($_POST['sbdp_step_location_label'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $template_id  = isset($_POST['sbdp_step_template_id']) ? absint($_POST['sbdp_step_template_id']) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ('' === $media_url) {
            $media_url = $video_url ?: $audio_url;
            if ('' === $media_url) {
                $media_url = $image_url;
            }
        }

        update_post_meta($post_id, '_sbdp_step_type', $step_type);
        update_post_meta($post_id, '_sbdp_step_media_url', $media_url);
        update_post_meta($post_id, '_sbdp_step_video_url', $video_url);
        update_post_meta($post_id, '_sbdp_step_audio_url', $audio_url);
        update_post_meta($post_id, '_sbdp_step_image_url', $image_url);
        update_post_meta($post_id, SBDP_Private_Tours::STEP_META_HEYGEN_VIDEO, $heygen_video_url);
        update_post_meta($post_id, '_sbdp_step_vr_asset', $vr_asset);
        update_post_meta($post_id, '_sbdp_step_gamification', $gamification);
        update_post_meta($post_id, '_sbdp_step_points', $points);
        update_post_meta($post_id, '_sbdp_step_lat', $lat);
        update_post_meta($post_id, '_sbdp_step_lng', $lng);
        update_post_meta($post_id, '_sbdp_step_altitude_m', $altitude_m);
        update_post_meta($post_id, '_sbdp_step_area', $area);
        update_post_meta($post_id, '_sbdp_step_location_type', $location_type);
        update_post_meta($post_id, '_sbdp_step_location_label', $location_label);
        update_post_meta($post_id, '_sbdp_step_template_id', $template_id);
    }

    /**
     * Register custom columns for the tours list.
     *
     * @param array<string, string> $columns Existing columns.
     *
     * @return array<string, string>
     */
    public static function register_tour_columns(array $columns): array
    {
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
    public static function render_tour_columns(string $column, int $post_id): void
    {
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
    public static function register_sortable_columns(array $columns): array
    {
        $columns['sbdp_tour_duration'] = 'sbdp_tour_duration';
        $columns['sbdp_tour_product']  = 'sbdp_tour_product';

        return $columns;
    }

    /**
     * Render filter dropdown for product linkage.
     *
     * @param string $post_type Current post type.
     */
    public static function render_tour_filters(string $post_type): void
    {
        if (SBDP_Private_Tours::POST_TYPE_TOUR !== $post_type) {
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
    public static function handle_admin_query(WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        if (SBDP_Private_Tours::POST_TYPE_TOUR !== $query->get('post_type')) {
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
    public static function register_preview_row_action(array $actions, WP_Post $post): array
    {
        if (SBDP_Private_Tours::POST_TYPE_TOUR !== $post->post_type) {
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
    public static function handle_preview_request(): void
    {
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
    private static function sync_tour_steps(int $post_id, int $chapter_count): void
    {
        $chapter_count = max(0, $chapter_count);

        $existing_steps = get_posts(
            array(
                'post_type'      => SBDP_Private_Tours::POST_TYPE_TOUR_STEP,
                'post_parent'    => $post_id,
                'post_status'    => array('publish', 'draft', 'pending'),
                'numberposts'    => -1,
                'orderby'        => array(
                    'menu_order' => 'ASC',
                    'ID'         => 'ASC',
                ),
            )
        );

        $current_count = count($existing_steps);

        if ($chapter_count > $current_count) {
            for ($i = $current_count; $i < $chapter_count; $i++) {
                $title = sprintf(
                    /* translators: %d: chapter index. */
                    __('Hoofdstuk %d', 'sbdp'),
                    $i + 1
                );

                $step_id = wp_insert_post(
                    array(
                        'post_type'    => SBDP_Private_Tours::POST_TYPE_TOUR_STEP,
                        'post_status'  => 'draft',
                        'post_title'   => $title,
                        'post_parent'  => $post_id,
                        'menu_order'   => $i,
                        'post_content' => '',
                    ),
                    true
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
                'post_type'      => SBDP_Private_Tours::POST_TYPE_TOUR_STEP,
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
