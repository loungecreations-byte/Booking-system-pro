<?php
/**
 * Plugin Name: DDB Core UI
 * Description: Core design system tokens, utilities, and reusable UI components for Elementor and custom modules.
 * Version: 1.0.0
 * Author: DDB Engineering
 * Requires at least: 6.1
 * Requires PHP: 8.0
 * Text Domain: ddb-core-ui
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('DDB_CORE_UI_VERSION')) {
    define('DDB_CORE_UI_VERSION', '1.0.0');
}

if (!defined('DDB_CORE_UI_FILE')) {
    define('DDB_CORE_UI_FILE', __FILE__);
}

if (!defined('DDB_CORE_UI_PATH')) {
    define('DDB_CORE_UI_PATH', plugin_dir_path(__FILE__));
}

if (!defined('DDB_CORE_UI_URL')) {
    define('DDB_CORE_UI_URL', plugin_dir_url(__FILE__));
}

final class DDB_Core_UI
{
    private const STYLE_FONTS = 'ddb-fonts';
    private const STYLE_BASE = 'ddb-core-ui';
    private const STYLE_ANTI_FOUC = 'ddb-core-ui-anti-fouc';
    private const STYLE_LIGHT = 'ddb-core-ui-light';
    private const STYLE_DARK = 'ddb-core-ui-dark';
    private const STYLE_LISTING_CARDS = 'ddb-core-ui-listing-cards';
    private const SCRIPT_HANDLE = 'ddb-core-ui';
    private const THEME_COOKIE = 'ddb_theme';
    private static ?bool $should_enqueue_frontend_assets = null;
    private static ?array $current_request_sources = null;

    public static function boot(): void
    {
        add_action('wp_head', [self::class, 'inject_anti_fouc'], 1);
        add_action('wp_head', [self::class, 'inject_runtime_guardrails_bootstrap'], 2);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_fonts'], 5);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets'], 40);
        add_action('wp_footer', [self::class, 'render_runtime_guardrails'], 1);
        add_action('init', [self::class, 'register_shortcodes']);
    }

    public static function enqueue_fonts(): void
    {
        $fonts_css = DDB_CORE_UI_PATH . 'assets/css/fonts-local.css';

        wp_register_style(
            self::STYLE_FONTS,
            DDB_CORE_UI_URL . 'assets/css/fonts-local.css',
            [],
            file_exists($fonts_css) ? (string) filemtime($fonts_css) : DDB_CORE_UI_VERSION
        );

        wp_enqueue_style(self::STYLE_FONTS);
    }

    public static function inject_anti_fouc(): void
    {
        if (!self::should_enqueue_assets()) {
            return;
        }

        $theme_cookie = wp_json_encode(self::THEME_COOKIE);
        $default_theme = wp_json_encode('system');

        echo '<script id="ddb-theme-bootstrap">(function(){';
        echo 'var cookieName=' . $theme_cookie . ';';
        echo 'var defaultTheme=' . $default_theme . ';';
        echo 'var valid={system:true,light:true,dark:true};';
        echo 'var readCookie=function(name){var escaped=name.replace(/[.*+?^${}()|[\]\\\\]/g,"\\\\$&");var match=document.cookie.match(new RegExp("(?:^|; )"+escaped+"=([^;]*)"));return match?decodeURIComponent(match[1]):"";};';
        echo "var fromStorage='';";
        echo 'try{fromStorage=window.localStorage.getItem(cookieName)||"";}catch(error){}';
        echo 'var fromCookie=readCookie(cookieName);';
        echo 'var resolved=valid[fromStorage]?fromStorage:(valid[fromCookie]?fromCookie:defaultTheme);';
        echo "document.documentElement.setAttribute('data-theme',resolved);";
        echo '})();</script>';
    }

    public static function inject_runtime_guardrails_bootstrap(): void
    {
        if (!self::should_enqueue_assets()) {
            return;
        }

        ?>
<script id="ddb-core-ui-runtime-guardrails-bootstrap">
(function(){
  if (window.DDBCoreUIGuardrailsBootstrap) {
    return;
  }
  window.DDBCoreUIGuardrailsBootstrap = true;
  var run = function(){
    if (window.DDBCoreUIGuardrails) {
      return;
    }
    window.DDBCoreUIGuardrails = true;
    var markMissingImage = function(image) {
      image.classList.add('is-ddb-image-missing');
      var media = image.closest('figure, .ui-listing-card__media, .ddb-spot-card__media, .ao-spot-card__media, .woocommerce-product-gallery__image, .sbdp-combi-thumb');
      if (media) {
        media.classList.add('is-ddb-media-missing');
      }
    };
    document.querySelectorAll('.ui-listing-card__image, .ddb-spot-card__image, .ao-spot-card__image, .attachment-woocommerce_thumbnail, .woocommerce-product-gallery img').forEach(function(image) {
      if (!(image instanceof HTMLImageElement)) {
        return;
      }
      image.addEventListener('error', function(){ markMissingImage(image); }, { once: true });
      if (image.complete && image.naturalWidth === 0) {
        markMissingImage(image);
      }
    });
    if (!document.querySelector('footer, .site-footer, [role="contentinfo"]')) {
      var footer = document.createElement('footer');
      footer.id = 'ddb-runtime-footer';
      footer.className = 'ddb-runtime-footer site-footer';
      footer.setAttribute('role', 'contentinfo');
      footer.innerHTML = '<div class="ddb-runtime-footer__inner"><a class="ddb-runtime-footer__brand" href="/">DagjeDenBosch.nl</a><nav class="ddb-runtime-footer__nav" aria-label="Footer"><a href="/activiteiten/">Activiteiten</a><a href="/spots/">Plekken</a><a href="/plan-je-dag/">Plan je dag</a><a href="/offerte/">Offerte aanvragen</a></nav></div>';
      document.body.appendChild(footer);
    }
    if (!document.querySelector('main, [role="main"]')) {
      var mainCandidate = document.querySelector('.elementor:not(.elementor-location-header):not(.elementor-location-footer), .sbdp-day-planner, .ddb-spots-listing, .woocommerce, .entry-content');
      if (mainCandidate) {
        mainCandidate.setAttribute('role', 'main');
        if (!mainCandidate.id) {
          mainCandidate.id = 'content';
        }
      }
    }
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run, { once: true });
  } else {
    run();
  }
})();
</script>
        <?php
    }

    public static function enqueue_assets(): void
    {
        if (!self::should_enqueue_assets()) {
            return;
        }

        $base_css = DDB_CORE_UI_PATH . 'assets/css/design-system.css';
        $anti_fouc_css = DDB_CORE_UI_PATH . 'assets/css/anti-fouc.css';
        $light_css = DDB_CORE_UI_PATH . 'assets/css/light-mode.css';
        $dark_css = DDB_CORE_UI_PATH . 'assets/css/dark-mode.css';
        $listing_cards_css = DDB_CORE_UI_PATH . 'assets/css/listing-card-system.css';
        $js_path = DDB_CORE_UI_PATH . 'assets/js/ui-interactions.js';

        wp_register_style(
            self::STYLE_BASE,
            DDB_CORE_UI_URL . 'assets/css/design-system.css',
            [],
            file_exists($base_css) ? (string) filemtime($base_css) : DDB_CORE_UI_VERSION
        );
        wp_register_style(
            self::STYLE_ANTI_FOUC,
            DDB_CORE_UI_URL . 'assets/css/anti-fouc.css',
            [],
            file_exists($anti_fouc_css) ? (string) filemtime($anti_fouc_css) : DDB_CORE_UI_VERSION
        );
        wp_register_style(
            self::STYLE_LIGHT,
            DDB_CORE_UI_URL . 'assets/css/light-mode.css',
            [self::STYLE_BASE, self::STYLE_ANTI_FOUC],
            file_exists($light_css) ? (string) filemtime($light_css) : DDB_CORE_UI_VERSION
        );
        wp_register_style(
            self::STYLE_DARK,
            DDB_CORE_UI_URL . 'assets/css/dark-mode.css',
            [self::STYLE_BASE, self::STYLE_ANTI_FOUC],
            file_exists($dark_css) ? (string) filemtime($dark_css) : DDB_CORE_UI_VERSION
        );
        wp_register_style(
            self::STYLE_LISTING_CARDS,
            DDB_CORE_UI_URL . 'assets/css/listing-card-system.css',
            [self::STYLE_BASE, self::STYLE_ANTI_FOUC, self::STYLE_LIGHT, self::STYLE_DARK],
            file_exists($listing_cards_css) ? (string) filemtime($listing_cards_css) : DDB_CORE_UI_VERSION
        );
        wp_register_script(
            self::SCRIPT_HANDLE,
            DDB_CORE_UI_URL . 'assets/js/ui-interactions.js',
            [],
            file_exists($js_path) ? (string) filemtime($js_path) : DDB_CORE_UI_VERSION,
            true
        );

        wp_enqueue_style(self::STYLE_BASE);
        wp_enqueue_style(self::STYLE_ANTI_FOUC);
        wp_enqueue_style(self::STYLE_LIGHT);
        wp_enqueue_style(self::STYLE_DARK);
        if (self::needs_listing_card_assets()) {
            wp_enqueue_style(self::STYLE_LISTING_CARDS);
        }

        wp_enqueue_script(self::SCRIPT_HANDLE);
        wp_script_add_data(self::SCRIPT_HANDLE, 'strategy', 'defer');

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'DDBCoreUIConfig',
            [
                'themeCookie' => self::THEME_COOKIE,
                'defaultTheme' => 'system',
            ]
        );
    }

    public static function render_runtime_guardrails(): void
    {
        if (!self::should_enqueue_assets()) {
            return;
        }

        ?>
<script id="ddb-core-ui-runtime-guardrails">
(function(){
  if (window.DDBCoreUIGuardrails) {
    return;
  }
  window.DDBCoreUIGuardrails = true;

  var markMissingImage = function(image) {
    image.classList.add('is-ddb-image-missing');
    var media = image.closest('figure, .ui-listing-card__media, .ddb-spot-card__media, .ao-spot-card__media, .woocommerce-product-gallery__image, .sbdp-combi-thumb');
    if (media) {
      media.classList.add('is-ddb-media-missing');
    }
  };

  document.querySelectorAll('.ui-listing-card__image, .ddb-spot-card__image, .ao-spot-card__image, .attachment-woocommerce_thumbnail, .woocommerce-product-gallery img').forEach(function(image) {
    if (!(image instanceof HTMLImageElement)) {
      return;
    }
    image.addEventListener('error', function(){ markMissingImage(image); }, { once: true });
    if (image.complete && image.naturalWidth === 0) {
      markMissingImage(image);
    }
  });

  if (!document.querySelector('footer, .site-footer, [role="contentinfo"]')) {
    var footer = document.createElement('footer');
    footer.id = 'ddb-runtime-footer';
    footer.className = 'ddb-runtime-footer site-footer';
    footer.setAttribute('role', 'contentinfo');
    footer.innerHTML = '<div class="ddb-runtime-footer__inner"><a class="ddb-runtime-footer__brand" href="/">DagjeDenBosch.nl</a><nav class="ddb-runtime-footer__nav" aria-label="Footer"><a href="/activiteiten/">Activiteiten</a><a href="/spots/">Plekken</a><a href="/plan-je-dag/">Plan je dag</a><a href="/offerte/">Offerte aanvragen</a></nav></div>';
    document.body.appendChild(footer);
  }
  if (!document.querySelector('main, [role="main"]')) {
    var mainCandidate = document.querySelector('.elementor:not(.elementor-location-header):not(.elementor-location-footer), .sbdp-day-planner, .ddb-spots-listing, .woocommerce, .entry-content');
    if (mainCandidate) {
      mainCandidate.setAttribute('role', 'main');
      if (!mainCandidate.id) {
        mainCandidate.id = 'content';
      }
    }
  }
})();
</script>
        <?php
    }

    private static function should_enqueue_assets(): bool
    {
        if (self::$should_enqueue_frontend_assets !== null) {
            return self::$should_enqueue_frontend_assets;
        }

        // Never on admin, AJAX, or feeds.
        if (is_admin() || wp_doing_ajax() || is_feed()) {
            self::$should_enqueue_frontend_assets = false;
            return self::$should_enqueue_frontend_assets;
        }

        // Always load on every public frontend page — design system is platform law.
        $forced = apply_filters('ddb_core_ui_force_enqueue', null);
        if (is_bool($forced)) {
            self::$should_enqueue_frontend_assets = $forced;
            return self::$should_enqueue_frontend_assets;
        }

        self::$should_enqueue_frontend_assets = true;
        return self::$should_enqueue_frontend_assets;
    }

    private static function request_references_core_ui(): bool
    {
        if (function_exists('is_singular') && is_singular('activity')) {
            return true;
        }

        $queried_object = get_queried_object();
        if ($queried_object instanceof WP_Post && self::post_references_core_ui($queried_object)) {
            return true;
        }

        $current_post = get_post();
        if (
            $current_post instanceof WP_Post &&
            (!($queried_object instanceof WP_Post) || $current_post->ID !== $queried_object->ID) &&
            self::post_references_core_ui($current_post)
        ) {
            return true;
        }

        $front_page_id = (int) get_option('page_on_front');
        if (is_front_page() && $front_page_id > 0 && self::post_id_references_core_ui($front_page_id)) {
            return true;
        }

        $posts_page_id = (int) get_option('page_for_posts');
        if (is_home() && $posts_page_id > 0 && self::post_id_references_core_ui($posts_page_id)) {
            return true;
        }

        return false;
    }

    private static function post_id_references_core_ui(int $post_id): bool
    {
        $post = get_post($post_id);
        return $post instanceof WP_Post && self::post_references_core_ui($post);
    }

    private static function post_references_core_ui(WP_Post $post): bool
    {
        return self::sources_contain_any(
            self::get_post_sources($post),
            [
                '[ddb_ui_',
                '[ddb_cta_block',
                'ddb_ui_',
                'ddb_cta_block',
                'ddb-cta-block',
                'sbdp_homepage_block',
                'booking-homepage-block',
                'sbdp_dayplanner',
                'data-ui-',
                'ui-container',
                'ui-grid',
                'ui-shell',
                'ui-summary',
                'ui-panel',
                'ui-field',
                'ui-input',
                'ui-select',
                'ui-tabs',
                'ui-chip',
                'ui-badge',
                'ui-card',
                'ui-btn',
                'ui-theme-',
                'ui-listing-card',
            ]
        );
    }

    private static function get_post_sources(WP_Post $post): array
    {
        return [
            self::normalize_source_value($post->post_content),
            self::normalize_source_value(get_post_meta($post->ID, '_elementor_data', true)),
            self::normalize_source_value(get_post_meta($post->ID, '_elementor_page_settings', true)),
        ];
    }

    private static function normalize_source_value(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            $encoded = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($encoded) ? $encoded : '';
        }

        return '';
    }

    private static function get_current_request_sources(): array
    {
        if (self::$current_request_sources !== null) {
            return self::$current_request_sources;
        }

        $sources = [];
        $queried_object = get_queried_object();
        if ($queried_object instanceof WP_Post) {
            $sources = array_merge($sources, self::get_post_sources($queried_object));
        }

        $current_post = get_post();
        if ($current_post instanceof WP_Post && (!($queried_object instanceof WP_Post) || $current_post->ID !== $queried_object->ID)) {
            $sources = array_merge($sources, self::get_post_sources($current_post));
        }

        if (is_front_page()) {
            $front_page_id = (int) get_option('page_on_front');
            if ($front_page_id > 0) {
                $front_page = get_post($front_page_id);
                if ($front_page instanceof WP_Post) {
                    $sources = array_merge($sources, self::get_post_sources($front_page));
                }
            }
        }

        if (is_home()) {
            $posts_page_id = (int) get_option('page_for_posts');
            if ($posts_page_id > 0) {
                $posts_page = get_post($posts_page_id);
                if ($posts_page instanceof WP_Post) {
                    $sources = array_merge($sources, self::get_post_sources($posts_page));
                }
            }
        }

        self::$current_request_sources = array_values(array_unique(array_filter($sources, static fn ($value) => $value !== '')));

        return self::$current_request_sources;
    }

    private static function sources_contain_any(array $sources, array $needles): bool
    {
        foreach ($sources as $source) {
            foreach ($needles as $needle) {
                if (stripos($source, $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function needs_listing_card_assets(): bool
    {
        if (function_exists('is_singular') && is_singular('activity')) {
            return true;
        }

        return self::sources_contain_any(
            self::get_current_request_sources(),
            [
                'ui-listing-card',
                'ui-shell',
                'ui-summary',
                'ui-chip',
                'ui-panel',
                'ui-tabs',
                'ui-badge',
                'ui-input',
                'ui-select',
                '[ddb_cta_block',
                'ddb_cta_block',
                'ddb-cta-block',
                '[ddb_ui_section',
                '[ddb_ui_card',
                '[ddb_ui_btn',
                '[ddb_ui_grid',
            ]
        );
    }

    private static function needs_interactions_script(): bool
    {
        return self::sources_contain_any(
            self::get_current_request_sources(),
            [
                'data-ui-',
                'data-ui-card-save',
                'data-ui-theme-toggle',
                'data-ui-nav-toggle',
                'data-ui-accordion-trigger',
                'data-ui-date-chip',
            ]
        );
    }

    public static function register_shortcodes(): void
    {
        add_shortcode('ddb_ui_section', [self::class, 'render_section_shortcode']);
        add_shortcode('ddb_ui_card', [self::class, 'render_card_shortcode']);
        add_shortcode('ddb_ui_btn', [self::class, 'render_button_shortcode']);
        add_shortcode('ddb_ui_grid', [self::class, 'render_grid_shortcode']);
    }

    public static function render_section_shortcode(array $atts, ?string $content = null): string
    {
        $atts = shortcode_atts(
            [
                'title' => '',
                'class' => '',
                'alt' => 'no',
            ],
            $atts,
            'ddb_ui_section'
        );

        $classes = ['ui-section'];
        if ($atts['alt'] === 'yes') {
            $classes[] = 'ui-section--alt';
        }
        if ($atts['class'] !== '') {
            $classes[] = sanitize_html_class((string) $atts['class']);
        }

        $title = trim((string) $atts['title']);
        $html = '<section class="' . esc_attr(implode(' ', $classes)) . '"><div class="ui-container">';
        if ($title !== '') {
            $html .= '<h2 class="ui-section__title">' . esc_html($title) . '</h2>';
        }
        $html .= do_shortcode((string) $content);
        $html .= '</div></section>';

        return $html;
    }

    public static function render_card_shortcode(array $atts, ?string $content = null): string
    {
        $atts = shortcode_atts(
            [
                'title' => '',
                'class' => '',
            ],
            $atts,
            'ddb_ui_card'
        );

        $classes = ['ui-card'];
        if ($atts['class'] !== '') {
            $classes[] = sanitize_html_class((string) $atts['class']);
        }

        $html = '<article class="' . esc_attr(implode(' ', $classes)) . '">';
        if (trim((string) $atts['title']) !== '') {
            $html .= '<h3 class="ui-card__title">' . esc_html((string) $atts['title']) . '</h3>';
        }
        $html .= '<div class="ui-card__body">' . do_shortcode((string) $content) . '</div>';
        $html .= '</article>';

        return $html;
    }

    public static function render_button_shortcode(array $atts, ?string $content = null): string
    {
        $atts = shortcode_atts(
            [
                'url' => '#',
                'variant' => 'primary',
                'size' => 'md',
                'class' => '',
            ],
            $atts,
            'ddb_ui_btn'
        );

        $variant = in_array($atts['variant'], ['primary', 'secondary', 'ghost'], true) ? $atts['variant'] : 'primary';
        $size = in_array($atts['size'], ['sm', 'md', 'lg'], true) ? $atts['size'] : 'md';
        $classes = ['ui-btn', 'ui-btn--' . $variant, 'ui-btn--' . $size];
        if ($atts['class'] !== '') {
            $classes[] = sanitize_html_class((string) $atts['class']);
        }

        $label = trim((string) $content) !== '' ? do_shortcode((string) $content) : __('Lees meer', 'ddb-core-ui');

        return '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url((string) $atts['url']) . '">' . $label . '</a>';
    }

    public static function render_grid_shortcode(array $atts, ?string $content = null): string
    {
        $atts = shortcode_atts(
            [
                'cols' => '3',
                'class' => '',
            ],
            $atts,
            'ddb_ui_grid'
        );

        $cols = in_array((string) $atts['cols'], ['2', '3', '4'], true) ? (string) $atts['cols'] : '3';
        $classes = ['ui-grid', 'ui-grid--' . $cols];
        if ($atts['class'] !== '') {
            $classes[] = sanitize_html_class((string) $atts['class']);
        }

        return '<div class="' . esc_attr(implode(' ', $classes)) . '">' . do_shortcode((string) $content) . '</div>';
    }
}

DDB_Core_UI::boot();
