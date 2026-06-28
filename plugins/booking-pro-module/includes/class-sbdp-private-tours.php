<?php

/**
 * Private tour bootstrap for DagjeDenBosch experiences.
 *
 * @package Booking_Pro_Module
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates custom post types, REST, and ticket automation.
 */
class SBDP_Private_Tours
{
    public const POST_TYPE_TOUR = 'sbdp_private_tour';
    public const POST_TYPE_TOUR_STEP = 'sbdp_tour_step';
    public const LEGACY_POST_TYPE_TOUR_STEP = 'sbdp_private_tour_step';
    public const STEP_META_HEYGEN_VIDEO = 'tour_stop_heygen_video';
    public const STEP_META_SPOT_NAME = '_sbdp_step_spot_name';
    private const OPTION_STEP_MIGRATION = 'sbdp_private_tour_step_slug_migrated';
    /**
     * Tracks whether the service has been initialised.
     *
     * @var bool
     */
    private static $booted = false;

    /**
     * Hook plugin lifecycle for private tours.
     */
    public static function init(): void
    {
        add_action('elementor/init', function () {
            remove_post_type_support(self::POST_TYPE_TOUR, 'elementor');
            remove_post_type_support(self::POST_TYPE_TOUR_STEP, 'elementor');
        });

        if (self::$booted) {
            return;
        }

        self::$booted = true;

        register_activation_hook(SBDP_FILE, array(__CLASS__, 'activate'));
        register_deactivation_hook(SBDP_FILE, array(__CLASS__, 'deactivate'));

        add_action('init', array(__CLASS__, 'register_post_types'));
        add_action('init', array(__CLASS__, 'register_post_meta'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_assets'));
        add_filter('body_class', array(__CLASS__, 'add_tour_mode_body_class'));

        add_shortcode('sbdp_private_tour_portal', array(__CLASS__, 'render_portal_shortcode'));
        add_shortcode('tour_video', array(__CLASS__, 'render_tour_video_shortcode'));
        SBDP_Private_Tours_Admin::init();
        SBDP_Private_Tours_REST::init();
        SBDP_Private_Tours_Elementor::init();
        add_action('sbdp_private_tours_cleanup', array(SBDP_Private_Tours_Tickets::class, 'cleanup_preview_tokens'));
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'handle_order_completed'), 20);
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'handle_order_completed'), 20);
    }

    /**
     * Add tour-mode body class when viewing a private tour.
     *
     * @param string[] $classes Existing body classes.
     * @return string[]
     */
    public static function add_tour_mode_body_class(array $classes): array
    {
        if (is_singular(self::POST_TYPE_TOUR)) {
            $classes[] = 'sbdp-is-tour-mode';
        }
        return $classes;
    }

    /**
     * Activation callback: ensure schema and demo content.
     */
    public static function activate(): void
    {
        self::register_post_types();
        self::register_post_meta();
        SBDP_Private_Tours_Tickets::create_table();
        SBDP_Private_Tours_Seeder::seed_defaults();
        if (! wp_next_scheduled('sbdp_private_tours_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'sbdp_private_tours_cleanup');
        }
        flush_rewrite_rules();
    }

    /**
     * Run on plugin deactivation to clear scheduled events.
     */
    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled('sbdp_private_tours_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'sbdp_private_tours_cleanup');
        }
    }

    /**
     * Register tour and step post types.
     */
    public static function register_post_types(): void
    {
        self::maybe_migrate_tour_steps();

        register_post_type(
            self::POST_TYPE_TOUR,
            array(
                'labels'            => array(
                    'name'               => __('Private Tours', 'sbdp'),
                    'singular_name'      => __('Private Tour', 'sbdp'),
                    'add_new'            => __('Add New', 'sbdp'),
                    'add_new_item'       => __('Add New Private Tour', 'sbdp'),
                    'edit_item'          => __('Edit Private Tour', 'sbdp'),
                    'new_item'           => __('New Private Tour', 'sbdp'),
                    'view_item'          => __('View Private Tour', 'sbdp'),
                    'search_items'       => __('Search Private Tours', 'sbdp'),
                    'not_found'          => __('No private tours found.', 'sbdp'),
                    'not_found_in_trash' => __('No private tours found in Trash.', 'sbdp'),
                    'all_items'          => __('Private Tours', 'sbdp'),
                ),
                'public'            => true,
                'publicly_queryable' => true,
                'show_ui'           => true,
                'show_in_menu'      => true,
                'menu_icon'         => 'dashicons-tickets-alt',
                'supports'          => array('title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'page-attributes'),
                'capability_type'   => 'page',
                'show_in_rest'      => true,
                'hierarchical'      => false,
                'rewrite'           => array('slug' => 'private-tour'),
            )
        );

        register_post_type(
            self::POST_TYPE_TOUR_STEP,
            array(
                'labels'            => array(
                    'name'               => __('Tour Steps', 'sbdp'),
                    'singular_name'      => __('Tour Step', 'sbdp'),
                    'add_new'            => __('Add New Step', 'sbdp'),
                    'add_new_item'       => __('Add New Tour Step', 'sbdp'),
                    'edit_item'          => __('Edit Tour Step', 'sbdp'),
                    'new_item'           => __('New Tour Step', 'sbdp'),
                    'view_item'          => __('View Tour Step', 'sbdp'),
                    'not_found'          => __('No tour steps found.', 'sbdp'),
                ),
                'public'            => false,
                'show_ui'           => true,
                'show_in_menu'      => 'edit.php?post_type=' . self::POST_TYPE_TOUR,
                'supports'          => array('title', 'editor', 'revisions', 'page-attributes'),
                'capability_type'   => 'page',
                'show_in_rest'      => true,
                'hierarchical'      => false,
                'rewrite'           => false,
            )
        );
    }

    /**
     * Register tour meta for REST access and admin editing.
     */
    public static function register_post_meta(): void
    {
        register_post_meta(
            self::POST_TYPE_TOUR,
            '_sbdp_tour_summary',
            array(
                'single'            => true,
                'type'              => 'string',
                'show_in_rest'      => true,
                'sanitize_callback' => 'wp_kses_post',
                'auth_callback'     => '__return_true',
            )
        );

        register_post_meta(
            self::POST_TYPE_TOUR,
            '_sbdp_tour_duration',
            array(
                'single'            => true,
                'type'              => 'integer',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
                'auth_callback'     => '__return_true',
            )
        );

        register_post_meta(
            self::POST_TYPE_TOUR,
            '_sbdp_tour_product_id',
            array(
                'single'            => true,
                'type'              => 'integer',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
                'auth_callback'     => '__return_true',
            )
        );

        register_post_meta(
            self::POST_TYPE_TOUR,
            '_sbdp_tour_chapter_count',
            array(
                'single'            => true,
                'type'              => 'integer',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
                'auth_callback'     => '__return_true',
            )
        );

        register_post_meta(
            self::POST_TYPE_TOUR,
            '_sbdp_tour_support_email',
            array(
                'single'            => true,
                'type'              => 'string',
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_email',
                'auth_callback'     => '__return_true',
            )
        );

        register_post_meta(
            self::POST_TYPE_TOUR_STEP,
            '_sbdp_step_type',
            array(
                'single'            => true,
                'type'              => 'string',
                'default'           => 'text',
                'show_in_rest'      => true,
                'sanitize_callback' => array(__CLASS__, 'sanitize_step_type'),
                'auth_callback'     => '__return_true',
            )
        );

        register_post_meta(
            self::POST_TYPE_TOUR_STEP,
            '_sbdp_step_media_url',
            array(
                'single'            => true,
                'type'              => 'string',
                'show_in_rest'      => true,
                'sanitize_callback' => 'esc_url_raw',
                'auth_callback'     => '__return_true',
            )
        );

        register_post_meta(
            self::POST_TYPE_TOUR_STEP,
            '_sbdp_step_video_url',
            array(
                'single'            => true,
                'type'              => 'string',
                'show_in_rest'      => true,
                'sanitize_callback' => 'esc_url_raw',
                'auth_callback'     => '__return_true',
            )
        );

        register_post_meta(
            self::POST_TYPE_TOUR_STEP,
            '_sbdp_step_audio_url',
            array(
                'single'            => true,
                'type'              => 'string',
                'show_in_rest'      => true,
                'sanitize_callback' => 'esc_url_raw',
                'auth_callback'     => '__return_true',
            )
        );

        register_post_meta(self::POST_TYPE_TOUR_STEP, '_sbdp_step_image_url', array(
                'single'            => true,
                'type'              => 'string',
                'show_in_rest'      => true,
                'sanitize_callback' => 'esc_url_raw',
                'auth_callback'     => '__return_true',
            ));
        register_post_meta(self::POST_TYPE_TOUR_STEP, self::STEP_META_HEYGEN_VIDEO, array(
                'single'            => true,
                'type'              => 'string',
                'show_in_rest'      => true,
                'sanitize_callback' => array(__CLASS__, 'sanitize_heygen_video_url'),
                'auth_callback'     => '__return_true',
            ));

        register_post_meta(self::POST_TYPE_TOUR_STEP, '_sbdp_step_vr_asset', array(
                'single'            => true,
                'type'              => 'string',
                'show_in_rest'      => true,
                'sanitize_callback' => 'esc_url_raw',
                'auth_callback'     => '__return_true',
                ));

        register_post_meta(
            self::POST_TYPE_TOUR_STEP,
            '_sbdp_step_gamification',
            array(
                'single'            => true,
                'type'              => 'string',
                'show_in_rest'      => true,
                'sanitize_callback' => array(__CLASS__, 'sanitize_json_meta'),
                'auth_callback'     => '__return_true',
            )
        );

        register_post_meta(
            self::POST_TYPE_TOUR_STEP,
            '_sbdp_step_points',
            array(
                'single'            => true,
                'type'              => 'integer',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
                'auth_callback'     => '__return_true',
            )
        );

        register_post_meta(
            self::POST_TYPE_TOUR_STEP,
            '_sbdp_step_lat',
            array(
                'single'            => true,
                'type'              => 'number',
                'show_in_rest'      => true,
                'sanitize_callback' => array(__CLASS__, 'sanitize_coordinate'),
                'auth_callback'     => '__return_true',
            )
        );

        register_post_meta(self::POST_TYPE_TOUR_STEP, '_sbdp_step_lng', array(
                'single'            => true,
                'type'              => 'number',
                'show_in_rest'      => true,
                'sanitize_callback' => array(__CLASS__, 'sanitize_coordinate'),
                'auth_callback'     => '__return_true',
            ));
        register_post_meta(self::POST_TYPE_TOUR_STEP, '_sbdp_step_location_label', array(
                'single'            => true,
                'type'              => 'string',
                'show_in_rest'      => true,
                'sanitize_callback' => array(__CLASS__, 'sanitize_location_label'),
                'auth_callback'     => '__return_true',
            ));
        register_post_meta(self::POST_TYPE_TOUR_STEP, self::STEP_META_SPOT_NAME, array(
                'single'            => true,
                'type'              => 'string',
                'show_in_rest'      => true,
                'sanitize_callback' => array(__CLASS__, 'sanitize_spot_name'),
                'auth_callback'     => '__return_true',
            ));
        register_post_meta(self::POST_TYPE_TOUR_STEP, '_sbdp_step_altitude_m', array(
                'single'            => true,
                'type'              => 'number',
                'show_in_rest'      => true,
                'sanitize_callback' => array(__CLASS__, 'sanitize_altitude'),
                'auth_callback'     => '__return_true',
            ));
        register_post_meta(self::POST_TYPE_TOUR_STEP, '_sbdp_step_area', array(
                'single'            => true,
                'type'              => 'string',
                'show_in_rest'      => true,
                'sanitize_callback' => array(__CLASS__, 'sanitize_location_area'),
                'auth_callback'     => '__return_true',
            ));
        register_post_meta(self::POST_TYPE_TOUR_STEP, '_sbdp_step_location_type', array(
                'single'            => true,
                'type'              => 'string',
                'show_in_rest'      => true,
                'sanitize_callback' => array(__CLASS__, 'sanitize_location_type'),
                'auth_callback'     => '__return_true',
            ));
        register_post_meta(
            self::POST_TYPE_TOUR_STEP,
            '_sbdp_step_template_id',
            array(
                'single'            => true,
                'type'              => 'integer',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
                'auth_callback'     => '__return_true',
            )
        );
    }

    private static function maybe_migrate_tour_steps(): void
    {
        if (get_option(self::OPTION_STEP_MIGRATION)) {
            return;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            array('post_type' => self::POST_TYPE_TOUR_STEP),
            array('post_type' => self::LEGACY_POST_TYPE_TOUR_STEP)
        );

        update_option(self::OPTION_STEP_MIGRATION, 1);
    }

    /**
     * Register the SPA assets for the participant portal.
     */
    public static function register_assets(): void
    {
        $portal_style_version = SBDP_VER;
        $portal_style_file = SBDP_DIR . 'assets/css/private-tour-portal.css';
        if (is_readable($portal_style_file)) {
            $mtime = filemtime($portal_style_file);
            if (false !== $mtime) {
                $portal_style_version = (string) $mtime;
            }
        }

        $portal_script_version = SBDP_VER;
        $portal_script_file = SBDP_DIR . 'assets/js/private-tour-portal.js';
        if (is_readable($portal_script_file)) {
            $mtime = filemtime($portal_script_file);
            if (false !== $mtime) {
                $portal_script_version = (string) $mtime;
            }
        }

        $navigation_style_version = SBDP_VER;
        $navigation_style_file = SBDP_DIR . 'assets/css/tour-navigation.css';
        if (is_readable($navigation_style_file)) {
            $mtime = filemtime($navigation_style_file);
            if (false !== $mtime) {
                $navigation_style_version = (string) $mtime;
            }
        }

        $navigation_script_version = SBDP_VER;
        $navigation_script_file = SBDP_DIR . 'assets/js/tour-navigation.js';
        if (is_readable($navigation_script_file)) {
            $mtime = filemtime($navigation_script_file);
            if (false !== $mtime) {
                $navigation_script_version = (string) $mtime;
            }
        }

        // Portal assets
        wp_register_style(
            'sbdp-private-tour-portal',
            SBDP_URL . 'assets/css/private-tour-portal.css',
            array(),
            $portal_style_version
        );

        wp_register_script(
            'sbdp-private-tour-portal',
            SBDP_URL . 'assets/js/private-tour-portal.js',
            array(),
            $portal_script_version,
            true
        );

        wp_script_add_data('sbdp-private-tour-portal', 'type', 'module');

        wp_localize_script(
            'sbdp-private-tour-portal',
            'sbdpPrivateTours',
            array(
                'apiBase' => esc_url_raw(rest_url('sbdp/v1/private-tours')),
                'nonce'   => wp_create_nonce('wp_rest'),
            )
        );

        // Tour navigation assets (for Elementor)
        $google_maps_embed_api_key = '';
        $google_maps_key_candidates = array(
            get_option('sbdp_google_maps_api_key', ''),
            get_option('elementor_google_maps_api_key', ''),
        );

        foreach ($google_maps_key_candidates as $candidate) {
            if (is_string($candidate) && '' !== trim($candidate)) {
                $google_maps_embed_api_key = trim($candidate);
                break;
            }
        }

        $google_maps_embed_api_key = (string) apply_filters('sbdp/private_tours/google_maps_embed_api_key', $google_maps_embed_api_key);
        $site_locale = function_exists('determine_locale') ? (string) determine_locale() : (string) get_locale();
        $locale_parts = preg_split('/[_-]/', $site_locale) ?: array();
        $google_maps_embed_language = strtolower((string) ($locale_parts[0] ?? 'nl'));
        $google_maps_embed_region = strtoupper((string) ($locale_parts[1] ?? ''));
        $google_maps_embed_units = (string) apply_filters('sbdp/private_tours/google_maps_embed_units', 'metric', $site_locale);
        $google_maps_embed_units = 'imperial' === strtolower($google_maps_embed_units) ? 'imperial' : 'metric';
        $google_maps_embed_language = (string) apply_filters('sbdp/private_tours/google_maps_embed_language', $google_maps_embed_language, $site_locale);
        $google_maps_embed_region = (string) apply_filters('sbdp/private_tours/google_maps_embed_region', $google_maps_embed_region, $site_locale);

        wp_register_style(
            'sbdp-tour-navigation',
            SBDP_URL . 'assets/css/tour-navigation.css',
            array(),
            $navigation_style_version
        );

        wp_register_script('sbdp-tour-navigation', SBDP_URL . 'assets/js/tour-navigation.js', array(), $navigation_script_version, true);
        wp_localize_script('sbdp-tour-navigation', 'sbdpTourNavigation', array(
                'routeEndpoint'        => esc_url_raw(rest_url('sbdp/v1/private-tours/navigation/route')),
                'embedDiagnosticsEndpoint' => esc_url_raw(rest_url('sbdp/v1/private-tours/navigation/embed-diagnostics')),
                'nonce'                => wp_create_nonce('wp_rest'),
                'defaultMapTiles'      => 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
                'defaultMapAttribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
                'googleMapsEmbedApiKey' => $google_maps_embed_api_key,
                'googleMapsEmbedLanguage' => $google_maps_embed_language,
                'googleMapsEmbedRegion' => $google_maps_embed_region,
                'googleMapsEmbedUnits' => $google_maps_embed_units,
            ));
        $leaflet_css = SBDP_DIR . 'assets/css/vendor/leaflet.css';
        $leaflet_js  = SBDP_DIR . 'assets/js/vendor/leaflet.min.js';
        if (is_readable($leaflet_css) && is_readable($leaflet_js)) {
            wp_register_style('leaflet', SBDP_URL . 'assets/css/vendor/leaflet.css', array(), (string) filemtime($leaflet_css));
            wp_register_script('leaflet', SBDP_URL . 'assets/js/vendor/leaflet.min.js', array(), (string) filemtime($leaflet_js), true);
        } else {
            wp_register_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4');
            wp_register_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true);
        }
    }
    /**
     * Render the secure tour portal shortcode wrapper.
     *
     * @return string
     */
    public static function render_portal_shortcode(): string
    {
        wp_enqueue_script('sbdp-private-tour-portal');
        wp_enqueue_style('sbdp-private-tour-portal');
        wp_enqueue_style('dashicons');
        ob_start();
        ?>
        <div class="sbdp-private-tour-portal" data-component="sbdp-private-tour-portal">
            <div class="sbdp-portal__login">
                <h2><?php esc_html_e('Toegang tot je prive tour', 'sbdp'); ?></h2>
                <form class="sbdp-portal__form" novalidate>
                    <label>
                        <span><?php esc_html_e('Ticketcode', 'sbdp'); ?></span>
                        <input type="text" name="ticket" autocomplete="off" required />
                    </label>
                    <label>
                        <span><?php esc_html_e('E-mailadres van je bestelling', 'sbdp'); ?></span>
                        <input type="email" name="email" autocomplete="off" />
                    </label>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Start tour', 'sbdp'); ?></button>
                </form>
                <div class="sbdp-portal__messages" role="alert" aria-live="polite"></div>
            </div>
            <div class="sbdp-portal__session" hidden>
                <div class="sbdp-portal__header">
                    <h2 class="sbdp-portal__title"></h2>
                    <p class="sbdp-portal__summary"></p>
                    <div class="sbdp-portal__meta"></div>
                </div>
                <div class="sbdp-portal__steps" data-steps></div>
            </div>
        </div>
        <?php

        return trim((string) ob_get_clean());
    }

    /**
     * Render a HeyGen embed block for the current or provided tour stop.
     *
     * @param mixed $atts Shortcode attributes.
     *
     * @return string
     */
    public static function render_tour_video_shortcode($atts = array()): string
    {
        if (! is_array($atts)) {
            $atts = array();
        }

        $atts = shortcode_atts(array(
                'step_id' => 0,
            ), $atts, 'tour_video');
        $step_id = absint($atts['step_id']);
        if ($step_id <= 0) {
            $post = get_post();
            if ($post instanceof WP_Post && in_array($post->post_type, array(self::POST_TYPE_TOUR_STEP, self::LEGACY_POST_TYPE_TOUR_STEP), true)) {
                $step_id = (int) $post->ID;
            } elseif ($post instanceof WP_Post && self::POST_TYPE_TOUR === $post->post_type) {
                $step_ids = get_posts(array(
                    'post_type'      => self::POST_TYPE_TOUR_STEP,
                    'post_parent'    => (int) $post->ID,
                    'post_status'    => 'publish',
                    'numberposts'    => 1,
                    'fields'         => 'ids',
                    'orderby'        => array(
                        'menu_order' => 'ASC',
                        'ID'         => 'ASC',
                    ),
                ));
                if (! empty($step_ids)) {
                    $step_id = (int) $step_ids[0];
                }
            }
        }

        if ($step_id <= 0) {
            return '';
        }

        $heygen_url = (string) get_post_meta($step_id, self::STEP_META_HEYGEN_VIDEO, true);
        return self::render_heygen_video_block($heygen_url);
    }
    /**
     * Issue tickets once an order completes.
     *
     * @param int $order_id WooCommerce order ID.
     */
    public static function handle_order_completed(int $order_id): void
    {
        if (! class_exists('WC_Order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            return;
        }

        SBDP_Private_Tours_Tickets::issue_from_order($order);
    }

    /**
     * Provide the supported step type labels.
     *
     * @return array<string, string>
     */
    public static function get_step_types(): array
    {
        return array(
            'text'  => __('Tekst & uitleg', 'sbdp'),
            'audio' => __('Audiofragment', 'sbdp'),
            'video' => __('Video', 'sbdp'),
            'vr'    => __('VR/AR ervaring', 'sbdp'),
            'game'  => __('Gamification', 'sbdp'),
        );
    }

    /**
     * Normalise requested step types.
     *
     * @param string $type Raw request value.
     *
     * @return string
     */
    public static function sanitize_step_type(string $type): string
    {
        $type = strtolower(trim($type));

        if (! array_key_exists($type, self::get_step_types())) {
            return 'text';
        }

        return $type;
    }

    /**
     * Ensure valid JSON meta payloads.
     *
     * @param string $value Raw meta value.
     *
     * @return string
     */
    public static function sanitize_json_meta(string $value): string
    {
        $value = trim($value);
        if ('' === $value) {
            return '';
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return '';
        }

        return wp_json_encode($decoded);
    }

    /**
     * Sanitize coordinate values for storage.
     *
     * @param mixed $value Raw coordinate.
     *
     * @return float|string
     */
    public static function sanitize_coordinate($value)
    {
        if ($value === '' || $value === null) {
            return '';
        }

        if (! is_numeric($value)) {
            return '';
        }

        return (float) $value;
    }

    /**
     * Coerce numeric input to a nullable float for response payloads.
     *
     * @param mixed $value Raw number.
     *
     * @return float|null
     */
    public static function to_nullable_float($value): ?float
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Sanitize location labels before saving.
     *
     * @param mixed $value Raw label.
     *
     * @return string
     */
    public static function sanitize_location_label($value): string
    {
        if ($value === '' || $value === null) {
            return '';
        }

        if (! is_string($value)) {
            return '';
        }

        return sanitize_text_field(trim($value));
    }

    /**
     * Sanitize explicit spot names before saving.
     *
     * @param mixed $value Raw name.
     *
     * @return string
     */
    public static function sanitize_spot_name($value): string
    {
        if ($value === '' || $value === null) {
            return '';
        }

        if (! is_string($value)) {
            return '';
        }

        return sanitize_text_field(trim($value));
    }

    /**
     * Sanitize altitude values (in meters) before saving.
     *
     * @param mixed $value Raw altitude.
     *
     * @return float|string
     */
    public static function sanitize_altitude($value)
    {
        if ($value === '' || $value === null) {
            return '';
        }

        if (! is_numeric($value)) {
            return '';
        }

        return (float) $value;
    }

    /**
     * Sanitize location area values before saving.
     *
     * @param mixed $value Raw area.
     *
     * @return string
     */
    public static function sanitize_location_area($value): string
    {
        if ($value === '' || $value === null) {
            return '';
        }

        if (! is_string($value)) {
            return '';
        }

        return sanitize_text_field(trim($value));
    }

    /**
     * Sanitize semantic location type values before saving.
     *
     * @param mixed $value Raw location type.
     *
     * @return string
     */
    public static function sanitize_location_type($value): string
    {
        if ($value === '' || $value === null) {
            return '';
        }

        if (! is_string($value)) {
            return '';
        }

        return strtolower(sanitize_text_field(trim($value)));
    }

    /**
     * Build a normalized spot payload with canonical keys.
     *
     * @param array<string, mixed> $input Raw spot-like payload.
     *
     * @return array{name:string,lat:float|null,lng:float|null,altitude_m:float|null,area:string,type:string}
     */
    public static function build_spot_payload(array $input): array
    {
        $name = '';
        $name_candidates = array(
            $input['spot_name'] ?? null,
            $input['spotName'] ?? null,
            $input['name'] ?? null,
            $input['location_label'] ?? null,
            $input['locationLabel'] ?? null,
            $input['title'] ?? null,
        );

        foreach ($name_candidates as $candidate) {
            $sanitized = self::sanitize_spot_name($candidate);
            if ('' !== $sanitized) {
                $name = $sanitized;
                break;
            }
        }

        $lat = self::to_nullable_float($input['lat'] ?? ($input['latitude'] ?? null));
        $lng = self::to_nullable_float($input['lng'] ?? ($input['lon'] ?? ($input['longitude'] ?? null)));
        $altitude = self::to_nullable_float($input['altitude_m'] ?? ($input['altitudeM'] ?? ($input['altitude'] ?? null)));
        $area = self::sanitize_location_area($input['area'] ?? '');
        $type = self::sanitize_location_type($input['location_type'] ?? ($input['locationType'] ?? ($input['type'] ?? '')));

        return array(
            'name'       => $name,
            'lat'        => $lat,
            'lng'        => $lng,
            'altitude_m' => $altitude,
            'area'       => $area,
            'type'       => $type,
        );
    }

    /**
     * Sanitize a HeyGen URL and normalize it to an embed URL.
     *
     * @param mixed $value Raw URL.
     *
     * @return string
     */
    public static function sanitize_heygen_video_url($value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return self::normalize_heygen_video_url($value);
    }

    /**
     * Normalize supported HeyGen URLs (`/embeds/{id}` and `/share/{id}`) to embed format.
     *
     * @param string $value Raw URL.
     *
     * @return string
     */
    public static function normalize_heygen_video_url(string $value): string
    {
        $value = trim($value);
        if ('' === $value) {
            return '';
        }

        $parts = wp_parse_url($value);
        if (! is_array($parts)) {
            return '';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ('app.heygen.com' !== $host) {
            return '';
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ('' === $path) {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $path)));
        if (count($segments) < 2) {
            return '';
        }

        $context = strtolower($segments[0]);
        if (! in_array($context, array('embeds', 'share', 'videos'), true)) {
            return '';
        }

        $video_id = (string) $segments[1];
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $video_id)) {
            return '';
        }
        $query = '';
        if (isset($parts['query']) && is_string($parts['query']) && '' !== $parts['query']) {
            $query = '?' . $parts['query'];
        }

        return sprintf('https://app.heygen.com/embeds/%s%s', rawurlencode($video_id), $query);
    }

    /**
     * Render the lazy-load HeyGen video block.
     *
     * @param string $url Raw or normalized HeyGen URL.
     *
     * @return string
     */
    public static function render_heygen_video_block(string $url): string
    {
        $embed_url = self::normalize_heygen_video_url($url);
        if ('' === $embed_url) {
            return '';
        }

        $instance_id = wp_unique_id('sbdp-heygen-');
        ob_start();
        ?>
        <div class="sbdp-heygen-video" data-sbdp-heygen-root="<?php echo esc_attr($instance_id); ?>">
            <button type="button" class="sbdp-heygen-video__button" data-sbdp-heygen-trigger="<?php echo esc_attr($instance_id); ?>">
                <?php esc_html_e('Bekijk video', 'sbdp'); ?>
            </button>
            <div class="sbdp-heygen-video__frame-wrap" data-sbdp-heygen-frame="<?php echo esc_attr($instance_id); ?>" data-src="<?php echo esc_url($embed_url); ?>" hidden></div>
        </div>
        <style>
            .sbdp-heygen-video{margin-top:16px}
            .sbdp-heygen-video__button{display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border:1px solid #d1d5db;border-radius:10px;background:#fff;cursor:pointer}
            .sbdp-heygen-video__frame-wrap{margin-top:12px;position:relative;width:100%;border-radius:12px;overflow:hidden}
            .sbdp-heygen-video__frame-wrap iframe{display:block;width:100%;max-width:100%;border:0;height:min(450px,70vh)}
        </style>
        <script>
        (function() {
            const id = <?php echo wp_json_encode($instance_id); ?>;
            const root = document.querySelector('[data-sbdp-heygen-root="' + id + '"]');
            if (!root || root.dataset.bound === '1') {
                return;
            }
            root.dataset.bound = '1';

            const trigger = root.querySelector('[data-sbdp-heygen-trigger="' + id + '"]');
            const frameWrap = root.querySelector('[data-sbdp-heygen-frame="' + id + '"]');
            if (!trigger || !frameWrap) {
                return;
            }

            trigger.addEventListener('click', function() {
                if (frameWrap.dataset.loaded !== '1') {
                    const src = frameWrap.getAttribute('data-src') || '';
                    if (!src) {
                        return;
                    }

                    const iframe = document.createElement('iframe');
                    iframe.src = src;
                    iframe.width = '100%';
                    iframe.height = '450';
                    iframe.frameBorder = '0';
                    iframe.allowFullscreen = true;
                    iframe.loading = 'lazy';
                    frameWrap.appendChild(iframe);
                    frameWrap.dataset.loaded = '1';
                }

                frameWrap.hidden = false;
                trigger.hidden = true;
            });
        })();
        </script>
        <?php

        return trim((string) ob_get_clean());
    }
}
require_once SBDP_DIR . 'includes/class-sbdp-private-tours-admin.php';
require_once SBDP_DIR . 'includes/class-sbdp-private-tours-rest.php';
require_once SBDP_DIR . 'includes/class-sbdp-private-tours-tickets.php';
require_once SBDP_DIR . 'includes/class-sbdp-private-tours-seeder.php';
require_once SBDP_DIR . 'includes/class-sbdp-private-tours-elementor.php';
