<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DDB_MegaMenu
{
    private const SHORTCODES = ['ddb_mega_menu', 'ddb_new_menu'];

    private static ?self $instance = null;

    private DDB_MegaMenu_Shortcode $shortcode;
    private ?DDB_MegaMenu_Admin $admin = null;

    private array $defaults = [
        'logo_url' => '',
        'cta_label' => 'Plan je dag',
        'cta_url' => '/plan-je-dag/',
        'enable_sticky_header' => 1,
        'enable_transparent_header_home' => 1,
        'enable_mobile_bottom_bar' => 1,
        'default_theme_mode' => 'auto',
        'custom_menu_structure_json' => '',
        'custom_menu_json' => '',
    ];

    private function __construct()
    {
        $this->shortcode = new DDB_MegaMenu_Shortcode($this);

        if (is_admin()) {
            $this->admin = new DDB_MegaMenu_Admin($this);
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 20);
        add_filter('elementor/widget/render_content', [$this, 'render_elementor_widget_shortcodes'], 20, 2);
        add_filter('elementor/frontend/the_content', [$this, 'render_elementor_content_shortcodes'], 20, 1);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function enqueue_assets(): void
    {
        if (is_admin() || wp_doing_ajax() || is_feed()) {
            return;
        }

        if (!$this->should_enqueue_assets()) {
            return;
        }

        $css_path = DDB_MEGAMENU_PATH . 'assets/css/megamenu.css';
        $js_path = DDB_MEGAMENU_PATH . 'assets/js/megamenu.js';

        $css_version = file_exists($css_path) ? (string) filemtime($css_path) : DDB_MEGAMENU_VERSION;
        $js_version = file_exists($js_path) ? (string) filemtime($js_path) : DDB_MEGAMENU_VERSION;

        wp_register_style(
            'ddb-mega-menu',
            DDB_MEGAMENU_URL . 'assets/css/megamenu.css',
            [],
            $css_version
        );

        wp_register_script(
            'ddb-mega-menu',
            DDB_MEGAMENU_URL . 'assets/js/megamenu.js',
            [],
            $js_version,
            true
        );

        wp_enqueue_style('ddb-mega-menu');
        wp_enqueue_script('ddb-mega-menu');
        wp_script_add_data('ddb-mega-menu', 'strategy', 'defer');

        wp_localize_script(
            'ddb-mega-menu',
            'DDBMegaMenuConfig',
            [
                'desktopMin' => 1024,
                'escKey' => 'Escape',
            ]
        );
    }

    public function should_enqueue_assets(): bool
    {
        $always_enqueue = (bool) apply_filters('ddb_megamenu_always_enqueue', true);
        if ($always_enqueue) {
            return true;
        }

        if (is_singular()) {
            $post = get_post();
            if ($post instanceof WP_Post && $this->post_contains_shortcode($post)) {
                return true;
            }
        }

        return (bool) apply_filters('ddb_megamenu_enqueue_assets', false);
    }

    private function post_contains_shortcode(WP_Post $post): bool
    {
        foreach (self::SHORTCODES as $shortcode) {
            if (has_shortcode((string) $post->post_content, $shortcode)) {
                return true;
            }
        }

        $elementor_data = get_post_meta($post->ID, '_elementor_data', true);
        if (is_string($elementor_data)) {
            foreach (self::SHORTCODES as $shortcode) {
                if (strpos($elementor_data, $shortcode) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    public function get_defaults(): array
    {
        return $this->defaults;
    }

    public function get_settings(): array
    {
        $stored = get_option(DDB_MEGAMENU_OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $settings = wp_parse_args($stored, $this->defaults);

        $settings['logo_url'] = isset($settings['logo_url']) ? esc_url_raw((string) $settings['logo_url']) : '';
        $settings['cta_label'] = isset($settings['cta_label']) ? sanitize_text_field((string) $settings['cta_label']) : $this->defaults['cta_label'];
        $settings['cta_url'] = isset($settings['cta_url']) ? esc_url_raw((string) $settings['cta_url']) : $this->defaults['cta_url'];
        $settings['enable_sticky_header'] = !empty($settings['enable_sticky_header']) ? 1 : 0;
        $settings['enable_transparent_header_home'] = !empty($settings['enable_transparent_header_home']) ? 1 : 0;
        $settings['enable_mobile_bottom_bar'] = !empty($settings['enable_mobile_bottom_bar']) ? 1 : 0;

        $theme_mode = isset($settings['default_theme_mode']) ? sanitize_key((string) $settings['default_theme_mode']) : 'auto';
        $settings['default_theme_mode'] = in_array($theme_mode, ['auto', 'light', 'dark'], true) ? $theme_mode : 'auto';

        $settings['custom_menu_structure_json'] = isset($settings['custom_menu_structure_json'])
            ? trim((string) $settings['custom_menu_structure_json'])
            : '';

        $settings['custom_menu_json'] = isset($settings['custom_menu_json'])
            ? trim((string) $settings['custom_menu_json'])
            : '';

        return $settings;
    }

    public function resolve_yes_no(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['yes', 'true', '1'], true)) {
            return true;
        }

        if (in_array($normalized, ['no', 'false', '0'], true)) {
            return false;
        }

        return $default;
    }

    public function resolve_theme_mode(?string $value, string $default): string
    {
        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['auto', 'light', 'dark'], true)) {
            return $normalized;
        }

        return in_array($default, ['auto', 'light', 'dark'], true) ? $default : 'auto';
    }

    public function render_elementor_widget_shortcodes(string $widget_content, mixed $widget): string
    {
        return $this->render_known_shortcodes($widget_content);
    }

    public function render_elementor_content_shortcodes(string $content): string
    {
        return $this->render_known_shortcodes($content);
    }

    private function render_known_shortcodes(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        foreach (self::SHORTCODES as $shortcode) {
            if (strpos($content, '[' . $shortcode) !== false) {
                return do_shortcode($content);
            }
        }

        return $content;
    }
}
