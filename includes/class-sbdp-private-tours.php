<?php
/**
 * Private tour bootstrap for DagjeDenBosch experiences.
 *
 * @package Booking_Pro_Module
 */

if (! defined('ABSPATH')) {
    exit;
}

require_once SBDP_DIR . 'includes/class-sbdp-private-tours-admin.php';
require_once SBDP_DIR . 'includes/class-sbdp-private-tours-rest.php';
require_once SBDP_DIR . 'includes/class-sbdp-private-tours-tickets.php';
require_once SBDP_DIR . 'includes/class-sbdp-private-tours-seeder.php';
require_once SBDP_DIR . 'includes/class-sbdp-private-tours-elementor.php';

/**
 * Coordinates custom post types, REST, and ticket automation.
 */
class SBDP_Private_Tours
{
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
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        register_activation_hook(SBDP_FILE, array(__CLASS__, 'activate'));
        register_deactivation_hook(SBDP_FILE, array(__CLASS__, 'deactivate'));

        add_action('init', array(__CLASS__, 'register_post_types'));
        add_action('init', array(__CLASS__, 'register_post_meta'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_assets'));

        add_shortcode('sbdp_private_tour_portal', array(__CLASS__, 'render_portal_shortcode'));

        SBDP_Private_Tours_Admin::init();
        SBDP_Private_Tours_REST::init();
        SBDP_Private_Tours_Elementor::init();
        add_action('sbdp_private_tours_cleanup', array('SBDP_Private_Tours_Tickets', 'cleanup_preview_tokens'));

        add_action('woocommerce_order_status_completed', array(__CLASS__, 'handle_order_completed'), 20);
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
        register_post_type(
            'sbdp_private_tour',
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
                'public'            => false,
                'show_ui'           => true,
                'show_in_menu'      => true,
                'menu_icon'         => 'dashicons-tickets-alt',
                'supports'          => array('title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'page-attributes'),
                'capability_type'   => 'page',
                'show_in_rest'      => true,
                'hierarchical'      => false,
                'rewrite'           => false,
            )
        );

        register_post_type(
            'sbdp_private_tour_step',
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
                'show_in_menu'      => 'edit.php?post_type=sbdp_private_tour',
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
            'sbdp_private_tour',
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
            'sbdp_private_tour',
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
            'sbdp_private_tour',
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
            'sbdp_private_tour',
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
            'sbdp_private_tour',
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
            'sbdp_private_tour_step',
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
            'sbdp_private_tour_step',
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
            'sbdp_private_tour_step',
            '_sbdp_step_vr_asset',
            array(
                'single'            => true,
                'type'              => 'string',
                'show_in_rest'      => true,
                'sanitize_callback' => 'esc_url_raw',
                'auth_callback'     => '__return_true',
            )
        );

        register_post_meta(
            'sbdp_private_tour_step',
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
            'sbdp_private_tour_step',
            '_sbdp_step_points',
            array(
                'single'            => true,
                'type'              => 'integer',
                'show_in_rest'      => true,
                'sanitize_callback' => 'absint',
                'auth_callback'     => '__return_true',
            )
        );
    }

    /**
     * Register the SPA assets for the participant portal.
     */
    public static function register_assets(): void
    {
        wp_register_script(
            'sbdp-private-tour-portal',
            SBDP_URL . 'assets/js/private-tour-portal.js',
            array(),
            SBDP_VER,
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
    }

    /**
     * Render the secure tour portal shortcode wrapper.
     *
     * @return string
     */
    public static function render_portal_shortcode(): string
    {
        wp_enqueue_script('sbdp-private-tour-portal');
        wp_enqueue_style('dashicons');

        ob_start();
        ?>
        <div class="sbdp-private-tour-portal" data-component="sbdp-private-tour-portal">
            <div class="sbdp-portal__login">
                <h2><?php esc_html_e('Toegang tot je privé tour', 'sbdp'); ?></h2>
                <form class="sbdp-portal__form" novalidate>
                    <label>
                        <span><?php esc_html_e('Ticketcode', 'sbdp'); ?></span>
                        <input type="text" name="ticket" autocomplete="off" required />
                    </label>
                    <label>
                        <span><?php esc_html_e('E-mailadres (optioneel)', 'sbdp'); ?></span>
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
}
