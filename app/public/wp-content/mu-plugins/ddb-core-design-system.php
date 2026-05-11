<?php
/**
 * Plugin Name: DDB Core Design System
 * Description: Core token/theme/bootstrap layer for DDB app surfaces.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Silence PHP 8.4+ deprecation output from third-party plugins on rendered pages.
if (!defined('WP_CLI') || !WP_CLI) {
    $ddb_reporting = error_reporting();
    if (is_int($ddb_reporting)) {
        error_reporting($ddb_reporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    }
    @ini_set('display_errors', '0');
}

if (!class_exists('DDB_Core_Design_System')) {
    final class DDB_Core_Design_System
    {
        private const THEME_COOKIE = 'ddb_theme';
        private const STYLE_HANDLE = 'ddb-ui';
        private const SITE_STYLE_HANDLE = 'ddb-design-system';
        private const NORMALIZE_STYLE_HANDLE = 'ddb-platform-normalization';
        private const SCRIPT_HANDLE = 'ddb-theme';
        private const FONT_STYLE_HANDLE = 'ddb-fonts';
        private const CORE_UI_STYLE_HANDLE = 'ddb-core-ui';
        private const CORE_UI_ANTI_FOUC_HANDLE = 'ddb-core-ui-anti-fouc';
        private const CORE_UI_LIGHT_HANDLE = 'ddb-core-ui-light';
        private const CORE_UI_DARK_HANDLE = 'ddb-core-ui-dark';
        private const CORE_UI_LISTING_HANDLE = 'ddb-core-ui-listing-cards';
        private const FONT_CSS_RELATIVE = '/plugins/ddb-core-ui/assets/css/fonts-local.css';
        // Keep the legacy handle name for compatibility, but serve the canonical
        // design-system bundle instead of the oversized legacy CSS blob.
        private const UI_CSS_RELATIVE = '/plugins/booking-pro-module/assets/css/design-system.css';
        private const SITE_CSS_RELATIVE = '/plugins/booking-pro-module/assets/css/design-system.css';
        private const NORMALIZE_CSS_RELATIVE = '/plugins/booking-pro-module/assets/css/ddb-platform-normalization.css';
        private const HOMEPAGE_CSS_RELATIVE = '/plugins/booking-pro-module/assets/css/homepage.css';
        private const HOMEPAGE_STYLE_HANDLE = 'ddb-homepage';
        private const THEME_JS_RELATIVE = '/plugins/booking-pro-module/assets/js/theme.js';

        private const APP_ROUTE_MAP = [
            'plan-je-dag' => 'plan',
            'activiteiten' => 'spots',
            'plattegrond' => 'spots',
            'spots' => 'spots',
            'private-tour' => 'tour',
            'bossche-wiel' => 'rad',
            'my-account' => 'account',
            'partner-profile' => 'account',
            'premium-members' => 'account',
            'checkout' => 'checkout',
            'cart' => 'checkout',
        ];

        private const APP_SHORTCODES = [
            'sbdp_dayplanner',
            'sbdp_activities_overview',
            'ddb_spinwheel',
            'woocommerce_checkout',
            'woocommerce_my_account',
        ];

        private const LEGACY_UI_FILES = [
            'plan-je-dag-ultimate.php',
            'sbdp-single-product-planner.php',
        ];

        private const LEGACY_UI_HOOKS = [
            'template_redirect',
            'wp_enqueue_scripts',
            'wp_print_styles',
            'wp_head',
            'wp_footer',
        ];
        private const LEGACY_FRONT_STYLE_HANDLES = [
            'sbdp-global-theme-css',
            'sbdp-global-theme',
        ];

        private static bool $did_print_meta = false;
        private static bool $did_enqueue_site_assets = false;
        private static bool $did_enqueue_front_assets = false;
        private static bool $did_enqueue_admin_assets = false;

        public static function boot(): void
        {
            add_filter('determine_locale', [self::class, 'normalize_front_locale'], 20, 1);
            add_filter('locale', [self::class, 'normalize_front_locale'], 20, 1);
            add_filter('language_attributes', [self::class, 'inject_theme_attribute'], 20, 1);
            add_filter('gettext', [self::class, 'normalize_frontend_translations'], 20, 3);
            add_filter('ngettext', [self::class, 'normalize_frontend_translations_plural'], 20, 5);
            add_filter('sbdp_planner_config', [self::class, 'normalize_planner_config_locale'], 20, 2);

            add_action('init', [self::class, 'suppress_deprecation_display'], 0);
            add_action('init', [self::class, 'enforce_local_elementor_defaults'], 1);
            add_action('init', [self::class, 'disable_external_emoji_assets'], 2);
            add_action('init', [self::class, 'start_external_asset_output_buffer'], 3);
            add_action('wp', [self::class, 'remove_legacy_app_ui_hooks'], 0);
            add_action('wp', [self::class, 'disable_spot_content_wrapping'], 1);
            add_action('template_redirect', [self::class, 'maybe_redirect_legacy_menu_routes'], 0);
            add_action('template_redirect', [self::class, 'start_semantic_output_buffer'], 1);
            add_action('template_redirect', [self::class, 'enforce_account_variant_routes'], 2);
            add_filter('template_include', [self::class, 'block_canvas_on_product_pages'], 999);
            add_action('wp_head', [self::class, 'output_route_canonical_link'], 1);
            add_action('wp_head', [self::class, 'output_color_scheme_meta'], 0);
            add_action('admin_head', [self::class, 'output_color_scheme_meta'], 0);

            add_action('wp_enqueue_scripts', [self::class, 'override_leaflet_script_handle'], 5);
            add_action('wp_enqueue_scripts', [self::class, 'enqueue_site_assets'], 900);
            add_action('wp_enqueue_scripts', [self::class, 'enqueue_front_assets'], 1000);
            add_action('wp_enqueue_scripts', [self::class, 'prune_plan_route_assets'], 1001);
            add_action('wp_print_styles', [self::class, 'prune_core_ui_conflicts'], PHP_INT_MAX - 1);
            add_action('wp_print_styles', [self::class, 'prune_plan_route_assets'], PHP_INT_MAX);
            add_action('wp_print_styles', [self::class, 'assert_single_front_stylesheet'], PHP_INT_MAX);
            add_action('wp_print_scripts', [self::class, 'sanitize_front_script_queue'], PHP_INT_MAX);
            add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets'], 1000);
            add_action('init', [self::class, 'ensure_account_roles'], 5);
            add_action('ddb_design_system_style_mismatch', [self::class, 'log_style_mismatch'], 10, 2);

            add_filter('the_content', [self::class, 'wrap_front_content'], 20, 1);
            add_filter('document_title_parts', [self::class, 'filter_document_title_parts'], 20, 1);
            add_filter('body_class', [self::class, 'append_front_body_class'], 20, 1);
            add_filter('admin_body_class', [self::class, 'append_admin_body_class'], 20, 1);
            add_filter('woocommerce_login_redirect', [self::class, 'redirect_woocommerce_login'], 20, 2);
            add_filter('login_redirect', [self::class, 'redirect_wordpress_login'], 20, 3);
            add_filter('woocommerce_account_menu_items', [self::class, 'filter_account_menu_items'], 20, 1);
            add_filter('woocommerce_get_endpoint_url', [self::class, 'filter_account_endpoint_url'], 20, 4);
            add_shortcode('ddb_account_hub', [self::class, 'render_account_hub_shortcode']);

            // Keep one centralized header/theme switch system.
            add_filter('ddb_spots_show_legacy_theme_button', '__return_false', 99);
            add_filter('sbdp_enable_legacy_theme_toggle', '__return_false', 99);

            // Strict enforcement mode (single stylesheet + HTML sanitization) is opt-in only.
            if (!self::safe_mode_enabled()) {
                add_action('template_redirect', [self::class, 'start_front_output_buffer'], 0);
                add_action('wp_print_styles', [self::class, 'prune_front_style_queue'], PHP_INT_MAX);
            }
        }

        public static function enforce_local_elementor_defaults(): void
        {
            self::maybe_update_option('elementor_disable_color_schemes', 'yes');
            self::maybe_update_option('elementor_disable_typography_schemes', 'yes');
            self::maybe_update_option('elementor_disable_google_fonts', 'yes');
            self::maybe_update_option('elementor_css_print_method', 'external');
            self::maybe_update_option('elementor_load_fa4_shim', 'no');
            self::maybe_update_option('elementor_experiment-e_font_icon_svg', 'active');
            self::maybe_update_option('elementor_experiment-e_optimized_css_loading', 'active');
        }

        public static function suppress_deprecation_display(): void
        {
            if (defined('WP_CLI') && WP_CLI) {
                return;
            }

            $current_reporting = error_reporting();
            if (is_int($current_reporting)) {
                error_reporting($current_reporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
            }

            if (!is_admin()) {
                @ini_set('display_errors', '0');
            }
        }

        public static function remove_legacy_app_ui_hooks(): void
        {
            if (is_admin() || wp_doing_ajax()) {
                return;
            }

            foreach (self::LEGACY_UI_HOOKS as $hook) {
                self::remove_callbacks_from_files($hook, self::LEGACY_UI_FILES);
            }
        }

        public static function disable_external_emoji_assets(): void
        {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('admin_print_scripts', 'print_emoji_detection_script');
            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_action('admin_print_styles', 'print_emoji_styles');
            add_filter('emoji_svg_url', '__return_false');
        }

        public static function inject_theme_attribute(string $attributes): string
        {
            $attributes = self::normalize_front_language_attributes($attributes);

            if (stripos($attributes, 'data-theme=') !== false) {
                return $attributes;
            }

            return $attributes . ' data-theme="' . esc_attr(self::get_theme_preference()) . '"';
        }

        public static function normalize_front_locale(string $locale): string
        {
            if (!self::should_force_dutch_frontend_locale()) {
                return $locale;
            }

            return 'nl_NL';
        }

        public static function normalize_planner_config_locale(array $config, array $defaults): array
        {
            unset($defaults);

            if (!self::should_force_dutch_frontend_locale()) {
                return $config;
            }

            $config['locale'] = 'nl-NL';

            return $config;
        }

        public static function normalize_frontend_translations(string $translation, string $text, string $domain): string
        {
            unset($domain);

            if (!self::should_force_dutch_frontend_locale()) {
                return $translation;
            }

            $map = [
                'Your cart is currently empty!' => 'Je planning is nog leeg.',
                'Your cart is currently empty.' => 'Je planning is nog leeg.',
                'New in store' => 'Verder ontdekken',
                'Login' => 'Inloggen',
                'Lost your password?' => 'Wachtwoord vergeten?',
                'Return to shop' => 'Bekijk activiteiten',
                'View cart' => 'Bekijk planning',
                'Proceed to checkout' => 'Verder naar afrekenen',
            ];

            return $map[$text] ?? $translation;
        }

        public static function normalize_frontend_translations_plural(string $translation, string $single, string $plural, int $number, string $domain): string
        {
            unset($single, $plural, $number);

            return self::normalize_frontend_translations($translation, $translation, $domain);
        }

        public static function output_color_scheme_meta(): void
        {
            if (self::$did_print_meta) {
                return;
            }

            self::$did_print_meta = true;
            echo '<meta name="color-scheme" content="light dark">' . "\n";
        }

        public static function enqueue_front_assets(): void
        {
            if (!self::is_front_app_route()) {
                return;
            }

            if (self::core_ui_public_runtime_active()) {
                self::dequeue_legacy_front_styles();
                return;
            }

            self::enqueue_design_assets(false);
            self::dequeue_legacy_front_styles();
        }

        public static function enqueue_site_assets(): void
        {
            if (is_admin() || wp_doing_ajax() || self::is_front_app_route()) {
                return;
            }

            if (self::$did_enqueue_site_assets) {
                return;
            }
            self::$did_enqueue_site_assets = true;

            if (self::core_ui_public_runtime_active()) {
                self::dequeue_legacy_front_styles();
                return;
            }

            self::register_assets();
            wp_enqueue_style(self::SITE_STYLE_HANDLE);
            wp_enqueue_style(self::NORMALIZE_STYLE_HANDLE);
            self::attach_token_inline_css(self::SITE_STYLE_HANDLE);
            self::attach_ui_bridge_inline_css(self::SITE_STYLE_HANDLE);
            self::attach_component_canon_inline_css(self::SITE_STYLE_HANDLE);

            if (function_exists('is_front_page') && is_front_page()) {
                wp_enqueue_style(self::HOMEPAGE_STYLE_HANDLE);
            }

            wp_enqueue_script(self::SCRIPT_HANDLE);
            wp_localize_script(
                self::SCRIPT_HANDLE,
                'DDBThemeConfig',
                [
                    'cookieName' => self::THEME_COOKIE,
                    'defaultTheme' => 'system',
                ]
            );

            // Discover / Fit / Match context state — only on browse/list pages.
            $is_browse_page = (
                (function_exists('is_post_type_archive') && is_post_type_archive(['activity', 'gd_place'])) ||
                (function_exists('is_page') && is_page(['activiteiten', 'spots']))
            );
            if ($is_browse_page) {
                wp_enqueue_script('ddb-context-state');
            }
        }

        public static function override_leaflet_script_handle(): void
        {
            if (!self::is_front_app_route()) {
                return;
            }

            $leaflet_path = WP_CONTENT_DIR . '/plugins/booking-pro-module/assets/js/vendor/leaflet.min.js';
            if (!file_exists($leaflet_path)) {
                return;
            }

            $leaflet_url = content_url('/plugins/booking-pro-module/assets/js/vendor/leaflet.min.js');
            $leaflet_ver = (string) filemtime($leaflet_path);

            if (wp_script_is('leaflet', 'registered')) {
                wp_deregister_script('leaflet');
            }

            wp_register_script('leaflet', $leaflet_url, [], $leaflet_ver, true);
        }

        public static function enqueue_admin_assets(): void
        {
            if (!self::is_plugin_admin_screen()) {
                return;
            }

            self::enqueue_design_assets(true);
        }

        public static function prune_front_style_queue(): void
        {
            if (!self::is_front_app_route()) {
                return;
            }

            global $wp_styles;

            if (!$wp_styles instanceof WP_Styles) {
                return;
            }

            $allowed = [
                self::NORMALIZE_STYLE_HANDLE,
                self::CORE_UI_STYLE_HANDLE,
                self::CORE_UI_ANTI_FOUC_HANDLE,
                self::CORE_UI_LIGHT_HANDLE,
                self::CORE_UI_DARK_HANDLE,
                self::CORE_UI_LISTING_HANDLE,
            ];

            foreach (array_keys($wp_styles->registered) as $handle) {
                if (!in_array($handle, $allowed, true)) {
                    wp_dequeue_style($handle);
                }
            }

            $wp_styles->queue = array_values(
                array_filter(
                    $wp_styles->queue,
                    static fn(string $handle): bool => in_array($handle, $allowed, true)
                )
            );

            foreach ($allowed as $required_handle) {
                if (!in_array($required_handle, $wp_styles->queue, true)) {
                    $wp_styles->queue[] = $required_handle;
                }
            }
        }

        public static function sanitize_front_script_queue(): void
        {
            if (!self::is_front_app_route()) {
                return;
            }

            self::prune_plan_route_assets();

            // This file is ESM but gets enqueued as a classic script by legacy paths.
            // In strict mode this causes "Unexpected token 'export'" and blocks app boot.
            if (wp_script_is('sbdp-fullcalendar', 'enqueued') || wp_script_is('sbdp-fullcalendar', 'registered')) {
                wp_dequeue_script('sbdp-fullcalendar');
                wp_deregister_script('sbdp-fullcalendar');
                if (wp_script_is('sbdp-planner', 'enqueued') || wp_script_is('sbdp-planner', 'registered')) {
                    wp_dequeue_script('sbdp-planner');
                    wp_deregister_script('sbdp-planner');
                }
            }

            // Ensure the planner SPA handles are available on the planner route.
            if (self::detect_app_context() === 'plan') {
                foreach (['sbdp-day-planner-app', 'sbdp-mobile-day-planner-app'] as $handle) {
                    if (wp_script_is($handle, 'registered')) {
                        wp_enqueue_script($handle);
                    }
                }
            }
        }

        public static function prune_plan_route_assets(): void
        {
            if (self::detect_app_context() !== 'plan' || self::is_visual_builder_request()) {
                return;
            }

            $script_handles = [
                'wc-jquery-blockui',
                'wc-add-to-cart',
                'wc-js-cookie',
                'woocommerce',
                'sourcebuster-js',
                'sourcebuster-js-js',
                'wc-order-attribution',
                'elementor-frontend',
                'elementor-pro-frontend',
                'pro-elements-handlers',
            ];

            foreach ($script_handles as $handle) {
                if (wp_script_is($handle, 'enqueued') || wp_script_is($handle, 'registered')) {
                    wp_dequeue_script($handle);
                    wp_deregister_script($handle);
                }
            }

            $style_handles = [
                'sbdp-day-planner-mobile',
                'hello-biz',
                'hello-biz-header-footer',
                'elementor-post-17',
                'elementor-post-140',
                'elementor-post-292',
                'woocommerce-layout',
                'woocommerce-smallscreen',
                'woocommerce-general',
                'wc-blocks-style',
                'ddb-core-ui-listing-cards',
            ];

            foreach ($style_handles as $handle) {
                if (wp_style_is($handle, 'enqueued') || wp_style_is($handle, 'registered')) {
                    wp_dequeue_style($handle);
                    wp_deregister_style($handle);
                }
            }
        }

        public static function prune_core_ui_conflicts(): void
        {
            if (!self::is_front_app_route()) {
                return;
            }

            $core_ui_active = wp_style_is(self::CORE_UI_STYLE_HANDLE, 'registered') || wp_style_is(self::CORE_UI_STYLE_HANDLE, 'enqueued');
            if (!$core_ui_active) {
                return;
            }

            $conflicting_handles = array_merge(
                [
                    self::STYLE_HANDLE,
                    self::SITE_STYLE_HANDLE,
                    self::HOMEPAGE_STYLE_HANDLE,
                ],
                self::LEGACY_FRONT_STYLE_HANDLES
            );

            foreach ($conflicting_handles as $handle) {
                if (wp_style_is($handle, 'enqueued') || wp_style_is($handle, 'registered')) {
                    wp_dequeue_style($handle);
                }
            }

            foreach ([
                self::NORMALIZE_STYLE_HANDLE,
                self::CORE_UI_STYLE_HANDLE,
                self::CORE_UI_ANTI_FOUC_HANDLE,
                self::CORE_UI_LIGHT_HANDLE,
                self::CORE_UI_DARK_HANDLE,
            ] as $handle) {
                if (wp_style_is($handle, 'registered') && !wp_style_is($handle, 'enqueued')) {
                    wp_enqueue_style($handle);
                }
            }
        }

        public static function assert_single_front_stylesheet(): void
        {
            if (!self::is_front_app_route()) {
                return;
            }

            global $wp_styles;

            if (!$wp_styles instanceof WP_Styles) {
                return;
            }

            $active = array_values(array_filter($wp_styles->queue));
            $required = [
                self::NORMALIZE_STYLE_HANDLE,
                self::CORE_UI_STYLE_HANDLE,
                self::CORE_UI_ANTI_FOUC_HANDLE,
                self::CORE_UI_LIGHT_HANDLE,
                self::CORE_UI_DARK_HANDLE,
            ];

            $missing = array_values(
                array_filter(
                    $required,
                    static fn(string $handle): bool => ! in_array($handle, $active, true)
                )
            );

            if ($missing === []) {
                return;
            }

            do_action('ddb_design_system_style_mismatch', $active, $missing);
        }

        /**
         * @param array<int, string> $active
         * @param array<int, string> $missing
         */
        public static function log_style_mismatch(array $active, array $missing): void
        {
            if (!function_exists('error_log')) {
                return;
            }

            $message = sprintf(
                '[DDB] Design-system stylesheet mismatch on %s. Active: %s. Missing: %s',
                (string) wp_parse_url(home_url(add_query_arg([])), PHP_URL_PATH),
                implode(', ', $active),
                implode(', ', $missing)
            );

            error_log($message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        public static function wrap_front_content(string $content): string
        {
            if (!self::should_wrap_front_content() || !is_main_query() || !in_the_loop()) {
                return $content;
            }

            if (self::is_spot_detail_route()) {
                $content = self::demote_fragment_h1_to_h2($content);
            }

            if (
                strpos($content, 'class="ddb-semantic-main') !== false ||
                strpos($content, "class='ddb-semantic-main") !== false
            ) {
                return $content;
            }

            $wrapped_content = $content;
            if (
                self::is_front_app_route() &&
                strpos($content, 'class="ddb-app"') === false &&
                strpos($content, "class='ddb-app'") === false
            ) {
                $wrapped_content = '<div class="ddb-app" data-app="' . esc_attr(self::detect_app_context()) . '">' . $content . '</div>';
            }

            if (self::route_uses_theme_main_wrapper()) {
                return $wrapped_content;
            }

            if (stripos($wrapped_content, '<main') !== false) {
                return $wrapped_content;
            }

            $main_attributes = ' id="content" class="ddb-semantic-main site-main" tabindex="-1"';
            $title_markup = '';
            $title = self::current_semantic_title();

            if (stripos($wrapped_content, '<h1') === false && $title !== '') {
                $main_attributes .= ' aria-labelledby="ddb-semantic-title"';
                $title_markup = '<h1 id="ddb-semantic-title" class="screen-reader-text">' . esc_html($title) . '</h1>';
            }

            return '<main' . $main_attributes . '>' . $title_markup . $wrapped_content . '</main>';
        }

        public static function filter_document_title_parts(array $parts): array
        {
            $contextual_title = self::contextual_front_title();
            if ($contextual_title === '') {
                return $parts;
            }

            $parts['title'] = $contextual_title;

            return $parts;
        }

        public static function append_admin_body_class(string $classes): string
        {
            if (!self::is_plugin_admin_screen()) {
                return $classes;
            }

            return trim($classes . ' ddb-admin');
        }

        public static function append_front_body_class(array $classes): array
        {
            $family = method_exists(self::class, 'detect_page_family')
                ? self::detect_page_family()
                : 'marketing';

            $classes[] = 'ui-scope';
            $classes[] = 'ddb-family-' . $family;

            if (!self::is_front_app_route()) {
                return array_values(array_unique($classes));
            }

            $classes[] = 'ddb-app-route';
            $classes[] = 'ddb-app-context-' . self::detect_app_context();

            return array_values(array_unique($classes));
        }

        public static function ensure_account_roles(): void
        {
            if (!function_exists('get_role') || !function_exists('add_role')) {
                return;
            }

            if (!get_role('partner')) {
                add_role('partner', __('Partner', 'woocommerce'), ['read' => true]);
            }

            if (!get_role('premium_member')) {
                add_role('premium_member', __('Premium member', 'woocommerce'), ['read' => true]);
            }
        }

        public static function redirect_woocommerce_login(string $redirect, $user): string
        {
            if (!$user instanceof WP_User) {
                return $redirect;
            }

            $target = self::account_home_url_for_user($user);
            if ($target !== '') {
                return $target;
            }

            return $redirect;
        }

        public static function redirect_wordpress_login(string $redirect_to, string $requested_redirect_to, $user): string
        {
            unset($requested_redirect_to);

            if (!$user instanceof WP_User) {
                return $redirect_to;
            }

            $target = self::account_home_url_for_user($user);
            if ($target !== '') {
                return $target;
            }

            return $redirect_to;
        }

        public static function filter_account_menu_items(array $items): array
        {
            if (!is_user_logged_in()) {
                return $items;
            }

            $user = wp_get_current_user();
            $experience = self::resolve_account_experience($user);

            $items = self::build_account_menu_items($experience, $items);
            $allowed_items = array_fill_keys(self::account_menu_keys($experience), true);

            foreach (array_keys($items) as $key) {
                if (!isset($allowed_items[$key])) {
                    unset($items[$key]);
                }
            }

            $preferred_order = self::account_menu_order($experience);
            $ordered = [];

            foreach ($preferred_order as $key) {
                if (array_key_exists($key, $items)) {
                    $ordered[$key] = $items[$key];
                    unset($items[$key]);
                }
            }

            foreach ($items as $key => $label) {
                $ordered[$key] = $label;
            }

            return $ordered;
        }

        private static function account_menu_keys(string $experience): array
        {
            if ($experience === 'partner') {
                return [
                    'ddb-partner-hub',
                    'dashboard',
                    'ddb-partner-profile',
                    'ddb-partner-visibility',
                    'ddb-partner-requests',
                    'edit-account',
                    'customer-logout',
                ];
            }

            if ($experience === 'premium') {
                return [
                    'ddb-premium-hub',
                    'dashboard',
                    'ddb-nearby-spots',
                    'ddb-club-deals',
                    'ddb-saved-trips',
                    'orders',
                    'downloads',
                    'edit-account',
                    'customer-logout',
                ];
            }

            return [
                'dashboard',
                'orders',
                'downloads',
                'edit-address',
                'edit-account',
                'customer-logout',
            ];
        }

        private static function account_menu_order(string $experience): array
        {
            if ($experience === 'partner') {
                return [
                    'ddb-partner-hub',
                    'dashboard',
                    'ddb-partner-profile',
                    'ddb-partner-visibility',
                    'ddb-partner-requests',
                    'edit-account',
                    'customer-logout',
                ];
            }

            if ($experience === 'premium') {
                return [
                    'ddb-premium-hub',
                    'dashboard',
                    'ddb-nearby-spots',
                    'ddb-club-deals',
                    'ddb-saved-trips',
                    'orders',
                    'downloads',
                    'edit-account',
                    'customer-logout',
                ];
            }

            return [
                'dashboard',
                'orders',
                'downloads',
                'edit-address',
                'edit-account',
                'customer-logout',
            ];
        }

        public static function filter_account_endpoint_url(string $url, string $endpoint, $value, string $permalink): string
        {
            unset($value, $permalink);

            $overrides = [
                'ddb-partner-hub' => self::account_page_url('partner'),
                'ddb-partner-profile' => self::account_page_url('partner'),
                'ddb-partner-visibility' => home_url('/activiteiten/'),
                'ddb-partner-requests' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') . 'orders/' : home_url('/my-account/orders/'),
                'ddb-premium-hub' => self::account_page_url('premium'),
                'ddb-nearby-spots' => home_url('/activiteiten/'),
                'ddb-club-deals' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/'),
                'ddb-saved-trips' => home_url('/plan-je-dag/'),
            ];

            if (isset($overrides[$endpoint])) {
                return $overrides[$endpoint];
            }

            return $url;
        }

        public static function render_account_hub_shortcode(array $atts = [], ?string $content = null, string $tag = ''): string
        {
            unset($content, $tag);

            $atts = shortcode_atts(
                [
                    'variant' => 'auto',
                ],
                $atts,
                'ddb_account_hub'
            );

            $variant = self::normalize_account_variant((string) $atts['variant']);

            return self::render_account_hub_markup($variant);
        }

        public static function disable_spot_content_wrapping(): void
        {
            if (!self::is_spot_detail_route()) {
                return;
            }

            remove_filter('the_content', [self::class, 'wrap_front_content'], 20);
        }

        public static function start_front_output_buffer(): void
        {
            if (!self::is_front_app_route() || is_feed() || is_trackback() || is_robots()) {
                return;
            }

            if (self::core_ui_public_runtime_active()) {
                return;
            }

            ob_start([self::class, 'sanitize_front_html']);
        }

        public static function start_semantic_output_buffer(): void
        {
            if (!self::should_normalize_semantics()) {
                return;
            }

            ob_start([self::class, 'normalize_semantic_html']);
        }

        /**
         * Block "Elementor Canvas" page template on WooCommerce single-product pages.
         *
         * Canvas strips get_header()/get_footer(), so the Elementor header/footer
         * locations are never fired. A manual nav section inside the canvas layout
         * then appears inline with the product content instead of at the shell top.
         *
         * Runs at priority 999 (after Elementor's own template_include at ~99).
         * If the child-theme registers the same filter, the child-theme version
         * (also 999) runs last — both are safe and idempotent.
         *
         * @param string $template Resolved template path.
         * @return string
         */
        public static function block_canvas_on_product_pages(string $template): string
        {
            if (!function_exists('is_product') || !is_product()) {
                return $template;
            }

            if (false === stripos($template, 'elementor-canvas') &&
                false === stripos($template, 'elementor_canvas')) {
                return $template;
            }

            // Canvas on a product page → fall back to theme's WooCommerce override or
            // WooCommerce's own default single-product.php.
            $theme_override = locate_template(['woocommerce/single-product.php']);
            if ($theme_override !== '') {
                return $theme_override;
            }

            $wc_plugin_template = WC_ABSPATH . 'templates/single-product.php';
            if (file_exists($wc_plugin_template)) {
                return $wc_plugin_template;
            }

            return $template;
        }

        public static function start_external_asset_output_buffer(): void
        {
            if (!self::safe_mode_enabled()) {
                return;
            }

            if (self::core_ui_public_runtime_active()) {
                return;
            }

            if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
                return;
            }

            if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
                return;
            }

            $handlers = ob_list_handlers();
            foreach ($handlers as $handler) {
                if (stripos((string) $handler, 'DDB_Core_Design_System::sanitize_external_asset_html') !== false) {
                    return;
                }
            }

            ob_start([self::class, 'sanitize_external_asset_html']);
        }

        public static function sanitize_external_asset_html(string $html): string
        {
            $html = self::strip_legacy_dark_toggle_markup($html);
            $html = self::sanitize_agent_widget_markup($html);
            $html = self::sanitize_plan_route_markup($html);
            $html = self::normalize_document_closing_tags($html);

            if (!self::is_front_app_route()) {
                return $html;
            }

            $html = preg_replace_callback(
                '/<(link|script)\b[^>]*(href|src)=(["\'])(https?:\/\/[^"\']+)\3[^>]*>/i',
                static function (array $matches): string {
                    $url = $matches[4];
                    if (self::is_local_asset_url($url)) {
                        return $matches[0];
                    }

                    return '';
                },
                $html
            );

            return (string) $html;
        }

        public static function normalize_semantic_html(string $html): string
        {
            if ($html === '' || stripos($html, '<body') === false) {
                return $html;
            }

            $html = self::relocate_leading_markup_into_body($html);
            $html = self::strip_experience_browser_bar_markup($html);
            $html = self::inject_route_shell_fallback_markup($html);
            $html = self::inject_spot_context_strip_markup($html);
            $html = self::sanitize_spot_detail_markup($html);
            $html = self::sanitize_private_tour_markup($html);
            $html = self::sanitize_cart_empty_markup($html);
            $html = self::ensure_canonical_link($html);
            $html = self::ensure_main_landmark($html);
            $html = self::inject_semantic_h1_markup($html);
            $html = self::inject_browser_bar_markup($html);
            $html = self::normalize_document_closing_tags($html);

            return (string) $html;
        }

        private static function inject_route_shell_fallback_markup(string $html): string
        {
            $canonical = self::current_canonical_url();
            $html_without_code = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html);
            $has_main = is_string($html_without_code) && preg_match('/<main\b/i', $html_without_code);
            $has_h1 = is_string($html_without_code) && preg_match('/<h1\b/i', $html_without_code);
            $needs_shell = (function_exists('is_front_page') && is_front_page())
                || (function_exists('is_page') && is_page(['plan-je-dag', 'activiteiten']));
            $needs_canonical_fallback = $canonical !== ''
                && !preg_match('/<link\b[^>]+rel=(["\'])canonical\1/i', $html);

            if (!$needs_shell && !$needs_canonical_fallback) {
                return $html;
            }

            $injection = '';
            if ($needs_canonical_fallback) {
                $injection .= '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
            }

            if ($needs_shell && !$has_main) {
                $title = self::current_semantic_title();
                $injection .= '<main id="content" class="ddb-semantic-main site-main" tabindex="-1"';
                if ($title !== '') {
                    $injection .= ' aria-labelledby="ddb-shell-route-title"';
                }
                $injection .= '>';
                if (!$has_h1 && $title !== '') {
                    $injection .= '<h1 id="ddb-shell-route-title" class="screen-reader-text">' . esc_html($title) . '</h1>';
                }
            } elseif ($needs_shell && !$has_h1) {
                $title = self::current_semantic_title();
                if ($title !== '') {
                    $injection .= '<h1 class="screen-reader-text ddb-semantic-route-title">' . esc_html($title) . '</h1>';
                }
            }

            if ($injection !== '') {
                $html = (string) preg_replace('/(<body\b[^>]*>)/i', '$1' . "\n" . $injection, $html, 1);
            }

            if ($needs_shell && !$has_main) {
                $footer_pattern = '/(<(?:div|footer)\b[^>]*(?:data-elementor-type=(["\'])(?:ehp-footer|footer)\2|class=(["\'])[^"\']*elementor-location-footer[^"\']*\3|id=(["\'])site-footer\4)[^>]*>)/i';
                if (preg_match($footer_pattern, $html)) {
                    $html = (string) preg_replace($footer_pattern, "</main>\n" . '$1', $html, 1);
                } else {
                    $html = (string) preg_replace('/<\/body>/i', "</main>\n</body>", $html, 1);
                }
            }

            return $html;
        }

        private static function strip_experience_browser_bar_markup(string $html): string
        {
            if (stripos($html, 'ddb-browser-bar--experience') === false) {
                return $html;
            }

            $stripped = preg_replace(
                '/<section\b[^>]*\bddb-browser-bar--experience\b[^>]*>.*?<\/section>/is',
                '',
                $html
            );

            return is_string($stripped) ? $stripped : $html;
        }

        private static function strip_legacy_dark_toggle_markup(string $html): string
        {
            $html = preg_replace(
                '/<style\b[^>]*id=(["\'])ddb-premium-apple-master\1[^>]*>.*?<\/style>/is',
                '',
                $html
            );

            $html = preg_replace(
                '/<style\b[^>]*id=(["\'])sbdp-dark-toggle-styles\1[^>]*>.*?<\/style>/is',
                '',
                $html
            );

            $html = preg_replace(
                '/<script\b[^>]*id=(["\'])sbdp-dark-toggle-consolidated\1[^>]*>.*?<\/script>/is',
                '',
                $html
            );

            return (string) $html;
        }

        private static function normalize_document_closing_tags(string $html): string
        {
            if (!preg_match('/<\/body>\s*<\/html>\s*$/i', $html)) {
                return $html;
            }

            return (string) preg_replace(
                '/(?:\s*<\/body>\s*<\/html>\s*)+$/i',
                "\n</body>\n</html>\n",
                $html
            );
        }

        private static function sanitize_plan_route_markup(string $html): string
        {
            if (self::detect_app_context() !== 'plan') {
                return $html;
            }

            $patterns = [
                '/<script\b[^>]*id=(["\'])sourcebuster-js-js\1[^>]*>.*?<\/script>\s*/is',
                '/<script\b[^>]*src=(["\'])[^"\']*sourcebuster[^"\']*\1[^>]*><\/script>\s*/is',
                '/<link\b[^>]*id=(["\'])(?:sbdp-day-planner-mobile-css|ddb-core-ui-listing-cards-css|hello-biz-css|hello-biz-header-footer-css|elementor-post-17-css|elementor-post-140-css|elementor-post-292-css)\1[^>]*>\s*/is',
                '/<link\b[^>]*href=(["\'])[^"\']*(?:mobile-day-planner\.css|listing-card-system\.css|themes\/hello-biz\/assets\/css\/theme\.css|themes\/hello-biz\/assets\/css\/header-footer\.css|uploads\/elementor\/css\/post-(?:17|140|292)\.css)[^"\']*\1[^>]*>\s*/is',
            ];

            return (string) preg_replace($patterns, '', $html);
        }

        public static function sanitize_front_html(string $html): string
        {
            if (self::core_ui_public_runtime_active()) {
                return $html;
            }

            $html = self::sanitize_agent_widget_markup($html);
            $html = self::sanitize_plan_route_markup($html);
            $html = self::sanitize_discover_route_markup($html);
            $html = self::normalize_document_closing_tags($html);

            $html = preg_replace_callback(
                '/<link\b[^>]*rel=(["\'])stylesheet\1[^>]*>/i',
                static function (array $matches): string {
                    $tag = $matches[0];

                    if (
                        stripos($tag, 'ddb-ui.css') !== false ||
                        stripos($tag, 'ddb-platform-normalization.css') !== false
                    ) {
                        return $tag;
                    }

                    return '';
                },
                $html
            );

            $html = preg_replace_callback(
                '/<style\b[^>]*>.*?<\/style>/is',
                static function (array $matches): string {
                    $tag = $matches[0];

                    if (
                        stripos($tag, 'id="ddb-ui-inline-css"') !== false ||
                        stripos($tag, "id='ddb-ui-inline-css'") !== false
                    ) {
                        return $tag;
                    }

                    return '';
                },
                $html
            );

            $html = preg_replace_callback(
                '/<(link|script)\b[^>]*(href|src)=(["\'])(https?:\/\/[^"\']+)\3[^>]*>/i',
                static function (array $matches): string {
                    $url = $matches[4];

                    if (self::is_local_asset_url($url)) {
                        return $matches[0];
                    }

                    return '';
                },
                $html
            );

            if (self::should_normalize_semantics()) {
                $html = self::ensure_canonical_link((string) $html);
                $html = self::ensure_main_landmark((string) $html);
                $html = self::inject_semantic_h1_markup((string) $html);
                $html = self::normalize_document_closing_tags((string) $html);
            }

            return (string) $html;
        }

        private static function sanitize_discover_route_markup(string $html): string
        {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
            $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
            $path = '/' . trim(strtolower($path), '/') . '/';

            if ($path === '/spots/') {
                $html = preg_replace(
                    '/<header class="ddb-spots-intro ui-summary ui-summary--compact">\s*<h1>Activiteiten<\/h1>\s*<p>Kies, vergelijk en voeg toe aan je dag\.<\/p>\s*<\/header>/i',
                    '<header class="ddb-spots-intro ui-summary ui-summary--compact"><h1>Spots</h1><p>Kies een plek, vergelijk rustig en voeg hem toe aan je dag.</p></header>',
                    $html,
                    1
                );
            }

            return $html;
        }

        private static function sanitize_agent_widget_markup(string $html): string
        {
            if (!self::should_disable_remote_agent_widget()) {
                return $html;
            }

            $local_api_url = esc_url_raw(rest_url('bsp/v1'));
            if ($local_api_url === '') {
                $local_api_url = '/wp-json/bsp/v1';
            }

            $html = str_replace(
                [
                    "window.DDB_AGENT_API_URL = 'https://agent.dagjedenbosch.nl/agent';",
                    "window.DDB_AGENT_DEMO_VIDEO = 'https://agent.dagjedenbosch.nl/videos/avatar-jeroen.mp4';",
                ],
                [
                    "window.DDB_AGENT_API_URL = '" . esc_js($local_api_url) . "';",
                    "window.DDB_AGENT_DEMO_VIDEO = 'https://agent.dagjedenbosch.nl/videos/avatar-jeroen.mp4';",
                ],
                $html
            );

            // Also replace direct host literals that may appear in bundled scripts or inline snippets.
            $html = str_replace('https://agent.dagjedenbosch.nl/agent', esc_js($local_api_url), $html);

            // Catch minified variants that do not exactly match the canonical assignment string.
            $html = (string) preg_replace(
                '/window\.DDB_AGENT_API_URL\s*=\s*["\'][^"\']+["\'];/i',
                "window.DDB_AGENT_API_URL = '" . esc_js($local_api_url) . "';",
                $html
            );

            $html = preg_replace(
                '/<script\b[^>]*src=(["\'])https?:\/\/agent\.dagjedenbosch\.nl\/[^"\']*\1[^>]*><\/script>/i',
                '',
                $html
            );
            $html = preg_replace(
                '/<link\b[^>]*href=(["\'])https?:\/\/agent\.dagjedenbosch\.nl\/[^"\']*\1[^>]*>/i',
                '',
                $html
            );

            return (string) $html;
        }

        private static function ensure_canonical_link(string $html): string
        {
            if (preg_match('/<link\b[^>]+rel=(["\'])canonical\1/i', $html)) {
                return $html;
            }

            $canonical = self::current_canonical_url();
            if ($canonical === '') {
                return $html;
            }

            $canonical_tag = '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
            $updated = preg_replace('/<\/head>/i', $canonical_tag . '</head>', $html, 1);

            return is_string($updated) ? $updated : $html;
        }

        private static function relocate_leading_markup_into_body(string $html): string
        {
            $doctype_pos = stripos($html, '<!DOCTYPE html');
            if ($doctype_pos === false || $doctype_pos === 0) {
                return $html;
            }

            $leading_markup = trim(substr($html, 0, $doctype_pos));
            if ($leading_markup === '') {
                return $html;
            }

            $document = (string) substr($html, $doctype_pos);
            $wrapped_markup = $leading_markup;

            if (!preg_match('/<main\b/i', $wrapped_markup)) {
                $wrapped_markup = self::semantic_main_opening_markup($document) . $wrapped_markup . '</main>';
            }

            $footer_pattern = '/(<(?:div|footer)\b[^>]*(?:data-elementor-type=(["\'])(?:ehp-footer|footer)\2|class=(["\'])[^"\']*elementor-location-footer[^"\']*\3|id=(["\'])site-footer\4)[^>]*>)/i';
            if (preg_match($footer_pattern, $document)) {
                $updated = preg_replace($footer_pattern, $wrapped_markup . "\n" . '$1', $document, 1);
                return is_string($updated) ? $updated : $html;
            }

            $updated = preg_replace('/<\/body>/i', $wrapped_markup . "\n</body>", $document, 1);

            return is_string($updated) ? $updated : $html;
        }

        private static function inject_spot_context_strip_markup(string $html): string
        {
            if (!self::is_spot_detail_route()) {
                return $html;
            }

            // Inject after <body> opening — spot detail has no theme template to call from.
            if (stripos($html, 'ddb-context-strip') !== false) {
                return $html; // Already injected (safety guard).
            }

            $strip = self::render_spot_context_strip();

            return (string) preg_replace(
                '/(<body\b[^>]*>)/i',
                '$1' . $strip,
                $html,
                1
            );
        }

        private static function sanitize_spot_detail_markup(string $html): string
        {
            if (!self::is_spot_detail_route()) {
                return $html;
            }

            $html = preg_replace(
                '/<div class="ddb-node-description">\s*<main\b[^>]*ddb-semantic-main[^>]*>\s*(?:<div\b[^>]*class="ddb-app"[^>]*>)?/i',
                '<div class="ddb-node-description">',
                $html
            );

            $html = preg_replace(
                '/(?:<\/div>\s*)?<\/main>\s*<\/div>\s*<\/details>/i',
                '</div></details>',
                (string) $html
            );

            $html = preg_replace_callback(
                '/(<div class="ddb-node-description">)(.*?)(<\/div>\s*<\/details>)/is',
                static function (array $matches): string {
                    return $matches[1] . self::demote_fragment_h1_to_h2($matches[2]) . $matches[3];
                },
                (string) $html,
                1
            );

            return (string) $html;
        }

        private static function sanitize_private_tour_markup(string $html): string
        {
            if (!(function_exists('is_singular') && is_singular('sbdp_private_tour'))) {
                return $html;
            }

            $html = preg_replace(
                '/(<div class="page-content">\s*)<main\b[^>]*ddb-semantic-main[^>]*>/i',
                '$1<div class="ddb-private-tour-content" aria-labelledby="ddb-semantic-title">',
                $html,
                1
            );

            $html = preg_replace(
                '/<\/main>(\s*<\/div>\s*<\/main>)/i',
                '</div>$1',
                (string) $html,
                1
            );

            return (string) $html;
        }

        private static function sanitize_cart_empty_markup(string $html): string
        {
            if (!(function_exists('is_cart') && is_cart())) {
                return $html;
            }

            $html = str_replace('Your cart is currently empty!', 'Je planning is nog leeg.', $html);
            $html = str_replace('Your cart is currently empty.', 'Je planning is nog leeg.', $html);
            $html = str_replace('New in store', 'Verder ontdekken', $html);
            // Cart is an execution page — no Browser Bar injected here.
            return $html;
        }

        private static function ensure_main_landmark(string $html): string
        {
            $html_without_code = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html);
            if (is_string($html_without_code) && preg_match('/<main\b/i', $html_without_code)) {
                return $html;
            }

            $main_opening = self::semantic_main_opening_markup($html);
            if ($main_opening === '') {
                return $html;
            }

            $content_pattern = '/(<div\b[^>]*data-elementor-type=(["\'])(?:wp-page|wp-post|single-post|archive|product)\2[^>]*>)/i';
            if (preg_match($content_pattern, $html)) {
                $updated = preg_replace($content_pattern, $main_opening . '$1', $html, 1);
            } else {
                $updated = preg_replace(
                    '/(<\/aside>\s*)(?=<div\b[^>]*data-elementor-type=(["\'])(?:wp-page|wp-post|single-post|archive|product)\2[^>]*>)/i',
                    '$1' . $main_opening . "\n",
                    $html,
                    1
                );
                if (!is_string($updated) || $updated === $html) {
                    $updated = preg_replace('/(<\/header>)/i', '$1' . "\n" . $main_opening, $html, 1);
                }
                if (!is_string($updated) || $updated === $html) {
                    $updated = preg_replace('/(<body\b[^>]*>)/i', '$1' . "\n" . $main_opening, $html, 1);
                }
            }
            if (!is_string($updated)) {
                return $html;
            }

            $footer_pattern = '/(<(?:div|footer)\b[^>]*(?:data-elementor-type=(["\'])(?:ehp-footer|footer)\2|class=(["\'])[^"\']*elementor-location-footer[^"\']*\3|id=(["\'])site-footer\4)[^>]*>)/i';
            if (preg_match($footer_pattern, $updated)) {
                $closed = preg_replace($footer_pattern, "</main>\n" . '$1', $updated, 1);
                return is_string($closed) ? $closed : $updated;
            }

            $closed = preg_replace('/<\/body>/i', "</main>\n</body>", $updated, 1);

            return is_string($closed) ? $closed : $updated;
        }

        private static function semantic_main_opening_markup(string $html): string
        {
            $main_attributes = ' id="content" class="ddb-semantic-main site-main" tabindex="-1"';
            if (preg_match('/<h1\b/i', $html)) {
                return '<main' . $main_attributes . '>';
            }

            $title = self::current_semantic_title();
            if ($title === '') {
                return '<main' . $main_attributes . '>';
            }

            return '<main' . $main_attributes . ' aria-labelledby="ddb-semantic-title"><h1 id="ddb-semantic-title" class="screen-reader-text">' . esc_html($title) . '</h1>';
        }

        private static function inject_browser_bar_markup(string $html): string
        {
            if ($html === '' || stripos($html, 'ddb-browser-bar') !== false) {
                return $html;
            }

            $browser_bar = self::current_route_browser_bar_markup();
            if ($browser_bar === '') {
                return $html;
            }

            $updated = preg_replace('/<main\b[^>]*>/i', '$0' . $browser_bar, $html, 1);

            return is_string($updated) ? $updated : $html;
        }

        private static function inject_semantic_h1_markup(string $html): string
        {
            if ($html === '' || preg_match('/<h1\b/i', $html)) {
                return $html;
            }

            $title = self::current_semantic_title();
            if ($title === '') {
                return $html;
            }

            $heading = '<h1 class="screen-reader-text ddb-semantic-route-title">' . esc_html($title) . '</h1>';
            $updated = preg_replace('/<main\b[^>]*>/i', '$0' . $heading, $html, 1);

            return is_string($updated) ? $updated : $html;
        }

        private static function current_route_browser_bar_markup(): string
        {
            // Browser Bar is ONLY allowed on browse/list pages (Activities Overview, Spots Overview).
            // Home, Product Detail, Planner, Cart, Checkout, Account, and Tour pages
            // use context strips, summary bars, or progress bars instead.
            // Templates for those pages must not call render_browser_bar() either.
            return '';
        }

        /**
         * Render a slim context strip for product detail pages.
         *
         * This replaces the Browser Bar on detail pages. It shows breadcrumb-style
         * navigation and a minimal "Terug" link — no search, no chips, no filter logic.
         * The context strip is not a discovery tool; it is orientation only.
         */
        public static function render_spot_context_strip(): string
        {
            $back_url   = home_url('/spots/');
            $back_label = 'Terug naar plekken';
            $plan_url   = home_url('/plan-je-dag/');

            $html  = '<nav class="ddb-context-strip" aria-label="Paginanavigatie">';
            $html .= '<div class="ddb-context-strip__inner">';
            $html .= '<a class="ddb-context-strip__back" href="' . esc_url($back_url) . '">';
            $html .= '<span class="ddb-context-strip__back-arrow" aria-hidden="true">←</span>';
            $html .= esc_html($back_label);
            $html .= '</a>';
            $html .= '<div class="ddb-context-strip__actions">';
            $html .= '<a class="ui-btn ui-btn--secondary ui-btn--sm" href="' . esc_url($plan_url) . '">Plan je dag</a>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</nav>';

            return $html;
        }

        public static function render_product_context_strip(): string
        {
            $back_url  = home_url('/activiteiten/');
            $back_label = 'Terug naar activiteiten';
            $plan_url  = home_url('/plan-je-dag/');

            $html  = '<nav class="ddb-context-strip" aria-label="Paginanavigatie">';
            $html .= '<div class="ddb-context-strip__inner">';
            $html .= '<a class="ddb-context-strip__back" href="' . esc_url($back_url) . '">';
            $html .= '<span class="ddb-context-strip__back-arrow" aria-hidden="true">←</span>';
            $html .= esc_html($back_label);
            $html .= '</a>';
            $html .= '<div class="ddb-context-strip__actions">';
            $html .= '<a class="ui-btn ui-btn--secondary ui-btn--sm" href="' . esc_url($plan_url) . '">Plan je dag</a>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</nav>';

            return $html;
        }

        private static function is_homepage_route(): bool
        {
            if (function_exists('is_front_page') && is_front_page()) {
                return true;
            }

            $front_id = (int) get_option('page_on_front');
            if ($front_id > 0 && function_exists('get_queried_object_id') && (int) get_queried_object_id() === $front_id) {
                return true;
            }

            $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
            $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
            $path = '/' . trim($path, '/') . '/';

            return $path === '/';
        }

        private static function is_planner_route(): bool
        {
            if (function_exists('is_page') && is_page('plan-je-dag')) {
                return true;
            }

            $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
            $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
            $path = '/' . trim($path, '/') . '/';

            return $path === '/plan-je-dag/';
        }

        private static function current_semantic_title(): string
        {
            $contextual_title = self::contextual_front_title();
            if ($contextual_title !== '') {
                return $contextual_title;
            }

            if (function_exists('is_front_page') && is_front_page()) {
                $front_id = (int) get_option('page_on_front');
                if ($front_id > 0) {
                    $title = get_the_title($front_id);
                    if (is_string($title) && $title !== '') {
                        return $title;
                    }
                }

                return (string) get_bloginfo('name');
            }

            if (is_home()) {
                $posts_page = (int) get_option('page_for_posts');
                if ($posts_page > 0) {
                    $title = get_the_title($posts_page);
                    if (is_string($title) && $title !== '') {
                        return $title;
                    }
                }

                return __('Nieuws', 'ddb-core');
            }

            if (is_singular()) {
                $object_id = (int) get_queried_object_id();
                if ($object_id > 0) {
                    $title = get_the_title($object_id);
                    if (is_string($title) && $title !== '') {
                        return $title;
                    }
                }
            }

            if (is_search()) {
                $query = trim((string) get_search_query());
                return $query !== ''
                    ? sprintf(__('Zoekresultaten voor %s', 'ddb-core'), $query)
                    : __('Zoekresultaten', 'ddb-core');
            }

            if (is_archive()) {
                $title = wp_strip_all_tags((string) get_the_archive_title(), true);
                if ($title !== '') {
                    return $title;
                }
            }

            return wp_strip_all_tags((string) wp_get_document_title(), true);
        }

        private static function contextual_front_title(): string
        {
            if (function_exists('is_cart') && is_cart()) {
                return __('Je planning', 'ddb-core');
            }

            if (function_exists('is_checkout') && is_checkout() && !(function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received'))) {
                return __('Afrekenen', 'ddb-core');
            }

            if (function_exists('is_account_page') && is_account_page()) {
                return __('Mijn account', 'ddb-core');
            }

            if (self::is_planner_route()) {
                return __('Plan je dag', 'ddb-core');
            }

            return '';
        }

        private static function current_canonical_url(): string
        {
            if (function_exists('is_front_page') && is_front_page()) {
                return home_url('/');
            }

            if (function_exists('is_page') && is_page()) {
                $page_id = (int) get_queried_object_id();
                if ($page_id > 0) {
                    $permalink = get_permalink($page_id);
                    if (is_string($permalink) && $permalink !== '') {
                        return $permalink;
                    }
                }
            }

            if (is_singular()) {
                $permalink = get_permalink();
                return is_string($permalink) ? $permalink : '';
            }

            if (is_home()) {
                $posts_page = (int) get_option('page_for_posts');
                if ($posts_page > 0) {
                    $permalink = get_permalink($posts_page);
                    return is_string($permalink) ? $permalink : home_url('/');
                }

                return home_url('/');
            }

            if (is_post_type_archive()) {
                $post_type = get_query_var('post_type');
                if (is_array($post_type)) {
                    $post_type = reset($post_type);
                }

                $archive_link = is_string($post_type) && $post_type !== '' ? get_post_type_archive_link($post_type) : '';
                return is_string($archive_link) ? $archive_link : '';
            }

            if (is_tax() || is_category() || is_tag()) {
                $term = get_queried_object();
                if ($term instanceof WP_Term) {
                    $term_link = get_term_link($term);
                    return is_string($term_link) ? $term_link : '';
                }
            }

            if (is_search()) {
                return get_search_link();
            }

            if (is_author()) {
                $author = get_queried_object();
                if ($author instanceof WP_User) {
                    return get_author_posts_url($author->ID);
                }
            }

            $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
            $path = '/' . trim((string) wp_parse_url($request_uri, PHP_URL_PATH), '/') . '/';
            $known_paths = [
                '/activiteiten/' => home_url('/activiteiten/'),
                '/spots/' => home_url('/spots/'),
                '/plan-je-dag/' => home_url('/plan-je-dag/'),
                '/' => home_url('/'),
            ];

            return $known_paths[$path] ?? '';
        }

        public static function output_route_canonical_link(): void
        {
            if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
                return;
            }

            $canonical = self::current_canonical_url();
            if ($canonical === '') {
                return;
            }

            echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
        }

        private static function should_normalize_semantics(): bool
        {
            if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
                return false;
            }

            if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
                return false;
            }

            if (is_feed() || is_trackback() || is_robots()) {
                return false;
            }

            if (function_exists('is_embed') && is_embed()) {
                return false;
            }

            return true;
        }

        private static function should_wrap_front_content(): bool
        {
            if (is_admin() || wp_doing_ajax()) {
                return false;
            }

            if (function_exists('is_front_page') && is_front_page()) {
                return true;
            }

            return self::is_front_app_route();
        }

        private static function route_uses_theme_main_wrapper(): bool
        {
            return (function_exists('is_cart') && is_cart())
                || (function_exists('is_checkout') && is_checkout())
                || (function_exists('is_account_page') && is_account_page())
                || self::is_spot_detail_route();
        }

        private static function is_spot_detail_route(): bool
        {
            if (function_exists('is_singular') && is_singular(['ddb_spot', 'gd_place'])) {
                return true;
            }

            $post_type = function_exists('get_post_type') ? (string) get_post_type() : '';
            if (in_array($post_type, ['ddb_spot', 'gd_place'], true)) {
                return true;
            }

            $object = function_exists('get_queried_object') ? get_queried_object() : null;

            return $object instanceof \WP_Post && in_array((string) $object->post_type, ['ddb_spot', 'gd_place'], true);
        }

        private static function demote_fragment_h1_to_h2(string $content): string
        {
            if (stripos($content, '<h1') === false) {
                return $content;
            }

            $content = preg_replace('/<h1\b([^>]*)>/i', '<h2$1>', $content);
            $content = preg_replace('/<\/h1>/i', '</h2>', (string) $content);

            return (string) $content;
        }

        public static function maybe_redirect_legacy_menu_routes(): void
        {
            if (is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_trackback() || is_robots()) {
                return;
            }

            $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
            if ($request_uri === '') {
                return;
            }

            $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
            $path = '/' . trim(strtolower($path), '/') . '/';
            if ($path === '/private-tour/' && !(function_exists('is_singular') && is_singular('sbdp_private_tour'))) {
                wp_safe_redirect((string) home_url('/activiteiten/?ddb_q=private+tours'), 302);
                exit;
            }

            if (!is_404()) {
                return;
            }

            if ($path === '//' || $path === '/') {
                return;
            }

            $target = self::legacy_route_redirect_target($path);
            if ($target === '') {
                return;
            }

            wp_safe_redirect($target, 302);
            exit;
        }

        public static function enforce_account_variant_routes(): void
        {
            if (is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_trackback() || is_robots()) {
                return;
            }

            if (!function_exists('is_page') || !is_page()) {
                return;
            }

            $required_variant = null;
            if (is_page('partner-profile') || is_page('partner-dashboard') || is_page('partner-uitbetaling')) {
                $required_variant = 'partner';
            } elseif (is_page('premium-members')) {
                $required_variant = 'premium';
            }

            if ($required_variant === null) {
                return;
            }

            if (!is_user_logged_in()) {
                $current_url = function_exists('home_url') ? home_url((string) wp_parse_url((string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)) : '/';
                wp_safe_redirect(wp_login_url($current_url), 302);
                exit;
            }

            if (function_exists('current_user_can') && (current_user_can('manage_options') || current_user_can('manage_woocommerce'))) {
                return;
            }

            $user = wp_get_current_user();
            if (!($user instanceof WP_User) || !self::user_can_access_variant($required_variant, $user)) {
                wp_safe_redirect(self::account_page_url(self::resolve_account_experience($user instanceof WP_User ? $user : null)), 302);
                exit;
            }
        }

        private static function legacy_route_redirect_target(string $path): string
        {
            $exact = [
                '/zoek/' => home_url('/activiteiten/'),
                '/favorieten/' => home_url('/plan-je-dag/'),
                '/ontdek/' => home_url('/activiteiten/'),
                '/eten-drinken/' => home_url('/activiteiten/?ddb_type=restaurant'),
                '/groepen/' => home_url('/activiteiten/?ddb_q=groepen'),
                '/deals/' => home_url('/activiteiten/?ddb_q=deals'),
                '/private-tour/' => home_url('/activiteiten/?ddb_q=private+tours'),
            ];

            if (isset($exact[$path])) {
                return (string) $exact[$path];
            }

            if (str_starts_with($path, '/plan-je-dag/')) {
                return home_url('/plan-je-dag/');
            }

            $prefixes = [
                '/ontdek/' => ['base' => '/activiteiten/', 'query' => ['ddb_q' => true]],
                '/inspiratie/' => ['base' => '/activiteiten/', 'query' => ['ddb_q' => true]],
                '/activiteiten/' => ['base' => '/activiteiten/', 'query' => ['ddb_q' => true]],
                '/eten-drinken/' => ['base' => '/activiteiten/', 'query' => ['ddb_type' => 'restaurant', 'ddb_q' => true]],
                '/groepen/' => ['base' => '/activiteiten/', 'query' => ['ddb_q' => true]],
                '/deals/' => ['base' => '/activiteiten/', 'query' => ['ddb_q' => true]],
            ];

            foreach ($prefixes as $prefix => $config) {
                if (!str_starts_with($path, $prefix)) {
                    continue;
                }

                $slug = trim(substr($path, strlen($prefix)), '/');
                $params = [];
                foreach ($config['query'] as $key => $value) {
                    if ($value === true) {
                        $params[$key] = $slug !== '' ? str_replace('-', ' ', $slug) : trim(str_replace('/', ' ', trim($prefix, '/')));
                    } else {
                        $params[$key] = (string) $value;
                    }
                }

                $url = home_url((string) $config['base']);
                return add_query_arg($params, $url);
            }

            return '';
        }

        private static function register_assets(): void
        {
            $css_path = WP_CONTENT_DIR . self::UI_CSS_RELATIVE;
            $css_url = content_url(self::UI_CSS_RELATIVE);
            $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : '1.0.0';

            $normalize_css_path = WP_CONTENT_DIR . self::NORMALIZE_CSS_RELATIVE;
            $normalize_css_url = content_url(self::NORMALIZE_CSS_RELATIVE);
            $normalize_css_ver = file_exists($normalize_css_path) ? (string) filemtime($normalize_css_path) : '1.0.0';

            $site_css_path = WP_CONTENT_DIR . self::SITE_CSS_RELATIVE;
            $site_css_url = content_url(self::SITE_CSS_RELATIVE);
            $site_css_ver = file_exists($site_css_path) ? (string) filemtime($site_css_path) : '1.0.0';

            $js_path = WP_CONTENT_DIR . self::THEME_JS_RELATIVE;
            $js_url = content_url(self::THEME_JS_RELATIVE);
            $js_ver = file_exists($js_path) ? (string) filemtime($js_path) : '1.0.0';

            $homepage_css_path = WP_CONTENT_DIR . self::HOMEPAGE_CSS_RELATIVE;
            $homepage_css_url = content_url(self::HOMEPAGE_CSS_RELATIVE);
            $homepage_css_ver = file_exists($homepage_css_path) ? (string) filemtime($homepage_css_path) : '1.0.0';
            $font_css_path = WP_CONTENT_DIR . self::FONT_CSS_RELATIVE;
            $font_css_url  = content_url(self::FONT_CSS_RELATIVE);
            $font_css_ver  = file_exists($font_css_path) ? (string) filemtime($font_css_path) : '1.0.0';

            // Context state script (discover / fit / match) — browse pages only.
            $context_js_path = WP_CONTENT_DIR . '/plugins/booking-pro-module/assets/js/ddb-context-state.js';
            $context_js_url  = content_url('/plugins/booking-pro-module/assets/js/ddb-context-state.js');
            $context_js_ver  = file_exists($context_js_path) ? (string) filemtime($context_js_path) : '1.0.0';

            wp_register_style(self::STYLE_HANDLE, $css_url, [], $css_ver);
            // Keep normalization coupled to the core UI stylesheet only.
            // The site stylesheet is enqueued separately on non-app routes.
            wp_register_style(self::NORMALIZE_STYLE_HANDLE, $normalize_css_url, [self::STYLE_HANDLE], $normalize_css_ver);
            wp_register_style(self::SITE_STYLE_HANDLE, $site_css_url, [], $site_css_ver);
            wp_register_style(self::HOMEPAGE_STYLE_HANDLE, $homepage_css_url, [self::SITE_STYLE_HANDLE], $homepage_css_ver);
            wp_register_style(self::FONT_STYLE_HANDLE, $font_css_url, [], $font_css_ver);
            wp_register_script(self::SCRIPT_HANDLE, $js_url, [], $js_ver, true);
            wp_register_script('ddb-context-state', $context_js_url, [], $context_js_ver, true);
        }

        private static function enqueue_design_assets(bool $is_admin = false): void
        {
            if ($is_admin) {
                if (self::$did_enqueue_admin_assets) {
                    return;
                }
                self::$did_enqueue_admin_assets = true;
            } else {
                if (self::$did_enqueue_front_assets) {
                    return;
                }
                self::$did_enqueue_front_assets = true;
            }

            self::register_assets();

            if ($is_admin) {
                wp_enqueue_style(self::FONT_STYLE_HANDLE);
                wp_enqueue_style(self::STYLE_HANDLE);
                self::attach_token_inline_css(self::STYLE_HANDLE);
            } else {
                $frontend_handle = self::CORE_UI_STYLE_HANDLE;

                if (wp_style_is(self::CORE_UI_STYLE_HANDLE, 'registered') || wp_style_is(self::CORE_UI_STYLE_HANDLE, 'enqueued')) {
                    wp_enqueue_style(self::CORE_UI_STYLE_HANDLE);
                    wp_enqueue_style(self::CORE_UI_ANTI_FOUC_HANDLE);
                    wp_enqueue_style(self::CORE_UI_LIGHT_HANDLE);
                    wp_enqueue_style(self::CORE_UI_DARK_HANDLE);
                } else {
                    $frontend_handle = self::STYLE_HANDLE;
                    wp_enqueue_style(self::STYLE_HANDLE);
                }

                self::attach_token_inline_css($frontend_handle);
                self::attach_ui_bridge_inline_css($frontend_handle);
                self::attach_component_canon_inline_css($frontend_handle);
            }

            wp_enqueue_style(self::NORMALIZE_STYLE_HANDLE);

            wp_enqueue_script(self::SCRIPT_HANDLE);
            wp_localize_script(
                self::SCRIPT_HANDLE,
                'DDBThemeConfig',
                [
                    'cookieName' => self::THEME_COOKIE,
                    'defaultTheme' => 'system',
                ]
            );
        }

        private static function attach_token_inline_css(string $handle): void
        {
            self::attach_inline_css_once($handle, self::build_token_css());
        }

        private static function attach_ui_bridge_inline_css(string $handle): void
        {
            self::attach_inline_css_once($handle, self::build_ui_bridge_css());
        }

        private static function attach_component_canon_inline_css(string $handle): void
        {
            self::attach_inline_css_once($handle, self::build_component_canon_css());
        }

        private static function attach_inline_css_once(string $handle, string $css): void
        {
            global $wp_styles;

            if ($wp_styles instanceof WP_Styles && isset($wp_styles->registered[$handle])) {
                $existing = $wp_styles->registered[$handle]->extra['after'] ?? [];
                if (in_array($css, $existing, true)) {
                    return;
                }
            }

            wp_add_inline_style($handle, $css);
        }

        private static function dequeue_legacy_front_styles(): void
        {
            foreach (self::LEGACY_FRONT_STYLE_HANDLES as $handle) {
                if (wp_style_is($handle, 'enqueued') || wp_style_is($handle, 'registered')) {
                    wp_dequeue_style($handle);
                }
            }
        }

        private static function core_ui_public_runtime_active(): bool
        {
            if (is_admin() || wp_doing_ajax()) {
                return false;
            }

            return defined('DDB_CORE_UI_FILE') || class_exists('DDB_Core_UI', false);
        }

        private static function get_theme_preference(): string
        {
            $raw = isset($_COOKIE[self::THEME_COOKIE]) ? wp_unslash((string) $_COOKIE[self::THEME_COOKIE]) : 'system';

            return self::normalize_theme($raw);
        }

        private static function normalize_theme(string $theme): string
        {
            $theme = strtolower(trim($theme));
            $valid = ['light', 'dark', 'system'];

            return in_array($theme, $valid, true) ? $theme : 'system';
        }

        private static function build_token_css(): string
        {
            $tokens = self::token_definitions();

            $root_vars = [];
            foreach ($tokens['global'] as $key => $value) {
                $root_vars['--ddb-' . $key] = $value;
            }
            foreach ($tokens['light'] as $key => $value) {
                $root_vars['--ddb-light-' . $key] = $value;
            }
            foreach ($tokens['dark'] as $key => $value) {
                $root_vars['--ddb-dark-' . $key] = $value;
            }

            $css = self::build_var_block(':root', $root_vars);
            $css .= self::build_theme_assignment_block('html[data-theme="light"]', 'light');
            $css .= self::build_theme_assignment_block('html[data-theme="dark"]', 'dark');
            $css .= self::build_theme_assignment_block('html[data-theme="system"]', 'light', 'light dark');
            $css .= '@media (prefers-color-scheme: dark) {' .
                self::build_theme_assignment_block('html[data-theme="system"]', 'dark', 'light dark') .
                '}';

            return $css;
        }

        private static function build_ui_bridge_css(): string
        {
            return ':root{' .
                '--ui-font-sans:var(--ddb-font-sans);' .
                '--ui-font-display:var(--ddb-font-display);' .
                '--ui-font-serif:var(--ddb-font-serif);' .
                '--ui-font-mono:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;' .
                '--ui-color-bg:var(--ddb-bg);' .
                '--ui-color-surface:var(--ddb-surface);' .
                '--ui-color-surface-2:var(--ddb-surface-2,var(--ddb-surface-alt));' .
                '--ui-color-surface-3:var(--ddb-surface-3,var(--ddb-surface-alt));' .
                '--ui-color-surface-card:var(--ddb-surface);' .
                '--ui-color-surface-input:var(--ddb-input,var(--ddb-surface));' .
                '--ui-color-text:var(--ddb-text);' .
                '--ui-color-text-primary:var(--ddb-text);' .
                '--ui-color-text-secondary:var(--ddb-muted);' .
                '--ui-color-text-muted:var(--ddb-muted);' .
                '--ui-color-border:var(--ddb-border);' .
                '--ui-color-primary:var(--ddb-primary);' .
                '--ui-color-primary-hover:var(--ddb-primary-hover);' .
                '--ui-color-primary-contrast:var(--ddb-primary-contrast);' .
                '--ui-color-cta:var(--ddb-primary);' .
                '--ui-color-cta-hover:var(--ddb-primary-hover);' .
                '--ui-color-cta-contrast:var(--ddb-primary-contrast);' .
                '--ui-color-accent:var(--ddb-primary);' .
                '--ui-color-accent-contrast:var(--ddb-primary-contrast);' .
                '--ui-color-success:var(--ddb-success);' .
                '--ui-color-warning:var(--ddb-warning);' .
                '--ui-color-danger:var(--ddb-danger);' .
                '--ui-color-focus:var(--ddb-focus);' .
                '--ui-shadow-sm:var(--ddb-shadow-1);' .
                '--ui-shadow-md:var(--ddb-shadow-2);' .
                '--ui-shadow-lg:var(--ddb-shadow-2);' .
                '--ui-border-width:1px;' .
                '--ui-radius-sm:var(--ddb-radius-1);' .
                '--ui-radius-md:var(--ddb-radius-1);' .
                '--ui-radius-lg:var(--ddb-radius-2);' .
                '--ui-radius-xl:var(--ddb-radius-3);' .
                '--ui-radius-card:var(--ddb-radius-3);' .
                '--ui-radius-section:1.5rem;' .
                '--ui-radius-pill:var(--ddb-radius-pill);' .
                '--ui-space-2xs:0.125rem;' .
                '--ui-space-xs:var(--ddb-space-1);' .
                '--ui-space-sm:var(--ddb-space-2);' .
                '--ui-space-md:var(--ddb-space-3);' .
                '--ui-space-lg:var(--ddb-space-4);' .
                '--ui-space-xl:var(--ddb-space-5);' .
                '--ui-space-2xl:var(--ddb-space-6);' .
                '--ui-space-3xl:3rem;' .
                '--ui-text-xs:0.75rem;' .
                '--ui-text-sm:var(--ddb-font-size-100);' .
                '--ui-text-md:var(--ddb-font-size-200);' .
                '--ui-text-lg:1.125rem;' .
                '--ui-text-xl:var(--ddb-font-size-300);' .
                '--ui-text-2xl:var(--ddb-font-size-400);' .
                '--ui-text-3xl:clamp(1.875rem,3vw,2.5rem);' .
                '--ui-weight-regular:400;' .
                '--ui-weight-medium:500;' .
                '--ui-weight-semibold:600;' .
                '--ui-weight-bold:700;' .
                '--ui-leading-body:var(--ddb-line-height);' .
                '--ui-leading-tight:1.2;' .
                '--ui-motion-fast:var(--ddb-duration-fast);' .
                '--ui-motion-base:var(--ddb-duration-base);' .
                '--ui-motion-panel:320ms;' .
                '--ui-ease-standard:cubic-bezier(0.2,0.8,0.2,1);' .
                '--ui-motion-oase:var(--ui-ease-standard);' .
                '--ui-container-pad:clamp(1rem,2vw,1.5rem);' .
                '--ui-container-sm:36rem;' .
                '--ui-container-md:48rem;' .
                '--ui-container-lg:72rem;' .
                '--ui-container-xl:84rem;' .
                '--ui-card-bg:var(--ui-color-surface-card);' .
                '--ui-card-border:var(--ui-color-border);' .
                '--ui-card-radius:var(--ui-radius-card);' .
                '--ui-listing-card-aspect:4 / 5;' .
                '--ui-listing-card-radius:var(--ui-radius-card);' .
                '--ui-listing-card-bg:var(--ui-color-surface-card);' .
                '--ui-listing-card-surface:var(--ui-color-surface-2);' .
                '--ui-listing-card-border:var(--ui-color-border);' .
                '--ui-listing-card-border-strong:var(--ui-color-primary);' .
                '--ui-listing-card-text:var(--ui-color-text);' .
                '--ui-listing-card-text-muted:var(--ui-color-text-muted);' .
                '--ui-listing-card-accent:var(--ui-color-accent);' .
                '--ui-listing-card-accent-strong:var(--ui-color-cta-hover);' .
                '--ui-listing-card-chip-bg:var(--ui-color-surface-3);' .
                '--ui-listing-card-chip-border:var(--ui-color-border);' .
                '--ui-listing-card-shadow:var(--ui-shadow-sm);' .
                '--ui-listing-card-shadow-hover:var(--ui-shadow-md);' .
                '--ui-listing-card-glow:0 0 0 1px color-mix(in srgb, var(--ui-color-primary) 18%, transparent);' .
                '--ui-listing-card-overlay-top:rgba(0,0,0,0.02);' .
                '--ui-listing-card-overlay-bottom:rgba(0,0,0,0.58);' .
                '--ui-listing-card-media-brightness:0.98;' .
                '--ui-listing-card-media-scale:1.02;' .
                '--ui-listing-card-success:var(--ui-color-success);' .
                '--ui-listing-card-danger:var(--ui-color-danger);' .
                '--ui-listing-card-disabled:var(--ui-color-text-muted);' .
                '--ui-nav-bg:var(--ui-color-surface);' .
                '--ui-nav-border:var(--ui-color-border);' .
                '--ui-nav-text:var(--ui-color-text);' .
                '--ui-nav-shadow:var(--ui-shadow-sm);' .
                '--ui-nav-pill-bg:var(--ui-color-surface-2);' .
                '--ui-nav-pill-bg-hover:var(--ui-color-surface-3);' .
                '--ui-nav-pill-border:var(--ui-color-border);' .
                '--ui-nav-toggle-bg:var(--ui-color-surface-2);' .
                '--ui-nav-toggle-bg-hover:var(--ui-color-surface-3);' .
                '--ui-nav-toggle-border:var(--ui-color-border);' .
                '--ui-nav-toggle-shadow:var(--ui-shadow-sm);' .
                '--ui-z-header:80;' .
                '--ui-z-dropdown:120;' .
                '--ui-z-overlay:240;' .
                '--ui-hidden:none;' .
                '--ui-host:auto;' .
                '--ui-port:1;' .
                '--sbdp-font-family:var(--ddb-font-body,var(--ddb-font-sans,"Quattrocento Sans","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Helvetica,Arial,sans-serif));' .
                '--ddb-font:var(--ddb-font-body,var(--ddb-font-sans,"Quattrocento Sans","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Helvetica,Arial,sans-serif));' .
                '}' .
                'html,body,button,input,select,textarea{' .
                'font-family:var(--ddb-font-body,var(--ddb-font-sans,"Quattrocento Sans","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Helvetica,Arial,sans-serif));' .
                '}' .
                'p,li,blockquote,figcaption,small,label,.elementor-widget-text-editor,.elementor-widget-text-editor *{' .
                'font-family:var(--ddb-font-body,var(--ddb-font-sans,"Quattrocento Sans","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Helvetica,Arial,sans-serif));' .
                '}' .
                'h1,h2,h3,h4,h5,h6,.elementor-heading-title,.wp-block-heading,.entry-title,.site-title{' .
                'font-family:var(--ddb-font-display,"Quattrocento",Georgia,"Times New Roman",serif);' .
                '}' .
                'nav a,.main-navigation a,.menu a,.elementor-nav-menu a,.elementor-nav-menu--main .elementor-item,header .menu-item>a{' .
                'font-family:var(--ddb-font-body,var(--ddb-font-sans,"Quattrocento Sans","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Helvetica,Arial,sans-serif));' .
                'color:var(--ddb-text,#1d1d1f);' .
                'transition:color 0.2s ease, background-color 0.2s ease;' .
                '}' .
                'nav a:hover,.elementor-nav-menu a:hover,.elementor-item:hover,header .menu-item>a:hover{' .
                'color:var(--ddb-text-muted,#86868b);' .
                '}' .
                '.elementor-nav-menu--dropdown,.elementor-nav-menu__container.elementor-nav-menu--dropdown{' .
                'background:rgba(255,255,255,0.85);' .
                '-webkit-backdrop-filter:blur(16px);' .
                'backdrop-filter:blur(16px);' .
                'border-radius:12px;' .
                'border:1px solid rgba(0,0,0,0.05);' .
                'box-shadow:0 8px 32px rgba(0,0,0,0.08);' .
                'padding:16px;' .
                'overflow:hidden;' .
                '}' .
                '.elementor-nav-menu--dropdown a{' .
                'padding:10px 16px;' .
                'border-radius:8px;' .
                'font-size:15px;' .
                'font-weight:500;' .
                '}' .
                '.elementor-nav-menu--dropdown a:hover{' .
                'background-color:rgba(0,0,0,0.04);' .
                'color:#1d1d1f;' .
                '}' .
                'header .elementor-button, nav .elementor-button{' .
                'background-color:#1d1d1f;' .
                'color:#fff;' .
                'border-radius:980px;' .
                'padding:12px 24px;' .
                'font-weight:600;' .
                'box-shadow:none;' .
                'transition:transform 0.2s ease, background-color 0.2s ease;' .
                '}' .
                'header .elementor-button:hover, nav .elementor-button:hover{' .
                'background-color:#333336;' .
                'transform:scale(1.02);' .
                '}' .
                // WooCommerce page backgrounds — ensure dark surfaces apply on all woo route bodies.
                'body.woocommerce-page,body.woocommerce-account,body.woocommerce-cart,body.woocommerce-checkout,body.woocommerce-order-received{' .
                'background-color:var(--ui-color-bg);color:var(--ui-color-text);' .
                '}' .
                // WooCommerce wrappers and notice blocks.
                '.woocommerce-page .woocommerce,.woocommerce .woocommerce-page{' .
                'color:var(--ui-color-text);' .
                '}' .
                '.woocommerce-message,.woocommerce-info,.woocommerce-error{' .
                'background:var(--ui-color-surface);color:var(--ui-color-text);border-top-color:var(--ui-color-primary);' .
                '}' .
                '.ddb-filter-container,.ddb-filter-container *,#ddb-no-results{' .
                'font-family:var(--ddb-font-body,var(--ddb-font-sans,"Quattrocento Sans","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Helvetica,Arial,sans-serif));' .
                '}';
        }

        private static function build_component_canon_css(): string
        {
            return
                ':where(.ddb-card,.ui-card){' .
                    'position:relative;display:flex;flex-direction:column;gap:var(--ui-space-md);min-width:0;padding:var(--ui-space-lg);' .
                    'background:var(--ui-card-bg);color:var(--ui-color-text);border:var(--ui-border-width) solid var(--ui-card-border);' .
                    'border-radius:var(--ui-card-radius);box-shadow:var(--ui-shadow-sm);' .
                '}' .
                ':where(.ddb-card--raised,.ui-card.ui-card--raised){box-shadow:var(--ui-shadow-md);}' .
                ':where(.ddb-card--interactive,.ui-card.ui-card--interactive){cursor:pointer;transition:transform var(--ui-motion-base) var(--ui-ease-standard),box-shadow var(--ui-motion-base) var(--ui-ease-standard),border-color var(--ui-motion-fast) var(--ui-ease-standard),background-color var(--ui-motion-fast) var(--ui-ease-standard);}' .
                ':where(.ddb-card--interactive,.ui-card.ui-card--interactive):hover{transform:translateY(-1px);box-shadow:var(--ui-shadow-md);border-color:color-mix(in srgb, var(--ui-color-primary) 40%, var(--ui-color-border));}' .
                ':where(.ddb-card--interactive,.ui-card.ui-card--interactive):focus-within{box-shadow:0 0 0 1px color-mix(in srgb, var(--ui-color-focus) 42%, transparent),var(--ui-shadow-md);border-color:var(--ui-color-focus);}' .
                ':where(.ddb-card--selected,.ui-card.ui-card--selected){border-color:var(--ui-color-primary);box-shadow:0 0 0 1px color-mix(in srgb, var(--ui-color-primary) 28%, transparent),var(--ui-shadow-md);background:color-mix(in srgb, var(--ui-color-surface) 88%, var(--ui-color-primary) 12%);}' .
                ':where(.ddb-card__media){overflow:hidden;border-radius:calc(var(--ui-card-radius) - var(--ui-space-xs));background:var(--ui-color-surface-2);}' .
                ':where(.ddb-card__media img){display:block;width:100%;height:auto;object-fit:cover;}' .
                ':where(.ddb-card__body){display:flex;flex-direction:column;gap:var(--ui-space-sm);min-width:0;}' .
                ':where(.ddb-card__title){margin:0;font-family:var(--ui-font-display);font-size:var(--ui-text-xl);font-weight:var(--ui-weight-semibold);line-height:var(--ui-leading-tight);color:var(--ui-color-text-primary);}' .
                ':where(.ddb-card__meta){margin:0;font-size:var(--ui-text-sm);font-weight:var(--ui-weight-regular);line-height:var(--ui-leading-body);color:var(--ui-color-text-muted);}' .
                ':where(.ddb-card__actions){display:flex;flex-wrap:wrap;gap:var(--ui-space-sm);align-items:center;margin-top:auto;}' .

                ':where(.ddb-button){display:inline-flex;align-items:center;justify-content:center;gap:var(--ui-space-xs);min-height:2.875rem;padding:0 var(--ui-space-lg);border:var(--ui-border-width) solid transparent;border-radius:var(--ui-radius-pill);background:var(--ui-color-surface-2);color:var(--ui-color-text);font-family:var(--ui-font-sans);font-size:var(--ui-text-sm);font-weight:var(--ui-weight-semibold);line-height:1;text-decoration:none;box-shadow:none;transition:transform var(--ui-motion-fast) var(--ui-ease-standard),background-color var(--ui-motion-fast) var(--ui-ease-standard),border-color var(--ui-motion-fast) var(--ui-ease-standard),color var(--ui-motion-fast) var(--ui-ease-standard),box-shadow var(--ui-motion-fast) var(--ui-ease-standard);cursor:pointer;}' .
                ':where(.ddb-button:hover){transform:translateY(-1px);text-decoration:none;}' .
                ':where(.ddb-button:focus-visible){outline:none;box-shadow:0 0 0 1px color-mix(in srgb, var(--ui-color-focus) 38%, transparent),0 0 0 4px color-mix(in srgb, var(--ui-color-focus) 18%, transparent);}' .
                ':where(.ddb-button[disabled],.ddb-button[aria-disabled="true"]){opacity:0.56;cursor:not-allowed;transform:none;pointer-events:none;box-shadow:none;}' .
                ':where(.ddb-button--primary){background:var(--ui-color-cta);border-color:var(--ui-color-cta);color:var(--ui-color-cta-contrast);box-shadow:var(--ui-shadow-sm);}' .
                ':where(.ddb-button--primary:hover){background:var(--ui-color-cta-hover);border-color:var(--ui-color-cta-hover);color:var(--ui-color-cta-contrast);}' .
                ':where(.ddb-button--secondary){background:var(--ui-color-surface-2);border-color:var(--ui-color-border);color:var(--ui-color-text-primary);}' .
                ':where(.ddb-button--secondary:hover){background:var(--ui-color-surface-3);border-color:color-mix(in srgb, var(--ui-color-primary) 32%, var(--ui-color-border));}' .
                ':where(.ddb-button--ghost){background:transparent;border-color:transparent;color:var(--ui-color-text-primary);}' .
                ':where(.ddb-button--ghost:hover){background:color-mix(in srgb, var(--ui-color-surface-2) 88%, transparent);border-color:color-mix(in srgb, var(--ui-color-border) 60%, transparent);}' .
                ':where(.ddb-button--icon){width:2.875rem;min-width:2.875rem;padding:0;}' .

                ':where(.ddb-field){display:flex;flex-direction:column;gap:var(--ui-space-xs);min-width:0;}' .
                ':where(.ddb-field__label){margin:0;font-size:var(--ui-text-sm);font-weight:var(--ui-weight-medium);line-height:var(--ui-leading-body);color:var(--ui-color-text-secondary);}' .
                ':where(.ddb-input,.ddb-select,.ddb-textarea){display:block;width:100%;min-height:3rem;padding:0.875rem 1rem;border:var(--ui-border-width) solid var(--ui-color-border);border-radius:var(--ui-radius-lg);background:var(--ui-color-surface-input);color:var(--ui-color-text-primary);font:inherit;line-height:var(--ui-leading-body);box-shadow:none;transition:border-color var(--ui-motion-fast) var(--ui-ease-standard),box-shadow var(--ui-motion-fast) var(--ui-ease-standard),background-color var(--ui-motion-fast) var(--ui-ease-standard);}' .
                ':where(.ddb-textarea){min-height:7.5rem;resize:vertical;}' .
                ':where(.ddb-input:focus,.ddb-select:focus,.ddb-textarea:focus){outline:none;border-color:var(--ui-color-focus);box-shadow:0 0 0 1px color-mix(in srgb, var(--ui-color-focus) 44%, transparent),0 0 0 4px color-mix(in srgb, var(--ui-color-focus) 16%, transparent);}' .
                ':where(.ddb-input[disabled],.ddb-select[disabled],.ddb-textarea[disabled],.ddb-field[aria-disabled="true"] :is(.ddb-input,.ddb-select,.ddb-textarea)){background:var(--ui-color-surface-3);color:var(--ui-color-text-muted);cursor:not-allowed;opacity:0.72;}' .
                ':where(.ddb-field--error .ddb-input,.ddb-field--error .ddb-select,.ddb-field--error .ddb-textarea,.ddb-input[aria-invalid="true"],.ddb-select[aria-invalid="true"],.ddb-textarea[aria-invalid="true"]){border-color:var(--ui-color-danger);box-shadow:0 0 0 1px color-mix(in srgb, var(--ui-color-danger) 28%, transparent);}' .

                ':where(.ddb-chip,.ddb-status){display:inline-flex;align-items:center;justify-content:center;gap:var(--ui-space-2xs);min-height:2rem;padding:0.375rem 0.75rem;border:var(--ui-border-width) solid var(--ui-color-border);border-radius:var(--ui-radius-pill);background:var(--ui-color-surface-2);color:var(--ui-color-text-secondary);font-size:var(--ui-text-xs);font-weight:var(--ui-weight-semibold);line-height:1;letter-spacing:0.01em;text-transform:none;}' .
                ':where(.ddb-chip--selected){background:color-mix(in srgb, var(--ui-color-primary) 14%, var(--ui-color-surface) 86%);border-color:color-mix(in srgb, var(--ui-color-primary) 42%, var(--ui-color-border));color:var(--ui-color-text-primary);}' .
                ':where(.ddb-status--success){background:color-mix(in srgb, var(--ui-color-success) 16%, var(--ui-color-surface) 84%);border-color:color-mix(in srgb, var(--ui-color-success) 42%, var(--ui-color-border));color:var(--ui-color-success);}' .
                ':where(.ddb-status--warning){background:color-mix(in srgb, var(--ui-color-warning) 16%, var(--ui-color-surface) 84%);border-color:color-mix(in srgb, var(--ui-color-warning) 42%, var(--ui-color-border));color:var(--ui-color-warning);}' .
                ':where(.ddb-status--danger){background:color-mix(in srgb, var(--ui-color-danger) 16%, var(--ui-color-surface) 84%);border-color:color-mix(in srgb, var(--ui-color-danger) 42%, var(--ui-color-border));color:var(--ui-color-danger);}' .
                ':where(.ddb-status--neutral){background:var(--ui-color-surface-2);border-color:var(--ui-color-border);color:var(--ui-color-text-secondary);}' .

                ':where(.ddb-price){display:inline-flex;align-items:baseline;gap:var(--ui-space-2xs);font-family:var(--ui-font-sans);font-size:var(--ui-text-lg);font-weight:var(--ui-weight-semibold);line-height:var(--ui-leading-tight);color:var(--ui-color-text-primary);}' .
                ':where(.ddb-summary,.ui-summary){display:flex;flex-direction:column;gap:var(--ui-space-sm);padding:var(--ui-space-lg);background:var(--ui-color-surface);border:var(--ui-border-width) solid var(--ui-color-border);border-radius:var(--ui-radius-xl);box-shadow:var(--ui-shadow-sm);color:var(--ui-color-text-primary);}' .
                ':where(.ddb-summary__row){display:flex;align-items:center;justify-content:space-between;gap:var(--ui-space-sm);font-size:var(--ui-text-sm);color:var(--ui-color-text-secondary);}' .
                ':where(.ddb-summary__total){display:flex;align-items:baseline;justify-content:space-between;gap:var(--ui-space-sm);padding-top:var(--ui-space-sm);border-top:var(--ui-border-width) solid color-mix(in srgb, var(--ui-color-border) 82%, transparent);font-size:var(--ui-text-lg);font-weight:var(--ui-weight-semibold);color:var(--ui-color-text-primary);}' .
                ':where(.ddb-itinerary){display:flex;flex-direction:column;gap:var(--ui-space-sm);}' .
                ':where(.ddb-itinerary__item){display:grid;grid-template-columns:minmax(4.5rem,auto) minmax(0,1fr) auto;gap:var(--ui-space-sm);align-items:start;padding:var(--ui-space-md) 0;border-bottom:var(--ui-border-width) solid color-mix(in srgb, var(--ui-color-border) 76%, transparent);}' .
                ':where(.ddb-itinerary__item:last-child){border-bottom:0;padding-bottom:0;}' .
                ':where(.ddb-itinerary__time){font-size:var(--ui-text-sm);font-weight:var(--ui-weight-semibold);color:var(--ui-color-text-primary);}' .
                ':where(.ddb-itinerary__title){font-size:var(--ui-text-md);font-weight:var(--ui-weight-semibold);line-height:var(--ui-leading-tight);color:var(--ui-color-text-primary);}' .
                ':where(.ddb-itinerary__meta){font-size:var(--ui-text-sm);color:var(--ui-color-text-muted);}' .
                ':where(.ddb-itinerary__price){font-size:var(--ui-text-sm);font-weight:var(--ui-weight-semibold);color:var(--ui-color-text-primary);white-space:nowrap;}';
        }

        private static function build_theme_assignment_block(string $selector, string $theme, string $color_scheme = ''): string
        {
            $keys = [
                'bg',
                'surface',
                'surface-alt',
                'surface-2',
                'surface-3',
                'text',
                'muted',
                'border',
                'primary',
                'primary-contrast',
                'primary-hover',
                'focus',
                'success',
                'warning',
                'danger',
                'shadow-1',
                'shadow-2',
                'input',
                'input-disabled',
            ];

            $vars = [];
            foreach ($keys as $key) {
                $vars['--ddb-' . $key] = 'var(--ddb-' . $theme . '-' . $key . ')';
            }

            if ($theme === 'light') {
                $vars['--ui-color-bg-rgb'] = '246 244 239';
                $vars['--ui-color-surface-rgb'] = '255 255 255';
            } else {
                $vars['--ui-color-bg-rgb'] = '0 0 0';
                $vars['--ui-color-surface-rgb'] = '10 10 10';
            }

            if ($color_scheme !== '') {
                $vars['color-scheme'] = $color_scheme;
            }

            return self::build_var_block($selector, $vars);
        }

        private static function build_var_block(string $selector, array $vars): string
        {
            $parts = [];

            foreach ($vars as $name => $value) {
                $parts[] = $name . ':' . trim((string) $value);
            }

            return $selector . '{' . implode(';', $parts) . ';}';
        }

        private static function token_definitions(): array
        {
            return [
                'global' => [
                    'font-sans' => '"Quattrocento Sans","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Helvetica,Arial,sans-serif',
                    'font-body' => '"Quattrocento Sans","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Helvetica,Arial,sans-serif',
                    'font-display' => '"Quattrocento",Georgia,"Times New Roman",serif',
                    'font-serif' => '"Quattrocento",Georgia,"Times New Roman",serif',
                    'font-size-100' => '0.875rem',
                    'font-size-200' => '1rem',
                    'font-size-300' => '1.25rem',
                    'font-size-400' => '1.5rem',
                    'line-height' => '1.5',
                    'space-1' => '0.25rem',
                    'space-2' => '0.5rem',
                    'space-3' => '0.75rem',
                    'space-4' => '1rem',
                    'space-5' => '1.5rem',
                    'space-6' => '2rem',
                    'radius-1' => '0.375rem',
                    'radius-2' => '0.625rem',
                    'radius-3' => '0.875rem',
                    'radius-pill' => '999px',
                    'duration-fast' => '150ms',
                    'duration-base' => '220ms',
                ],
                'light' => [
                    'bg'               => '#f6f4ef',
                    'surface'          => '#ffffff',
                    'surface-alt'      => '#f2ede6',
                    'surface-2'        => '#f2ede6',
                    'surface-3'        => '#ece4d8',
                    'text'             => '#171412',
                    'muted'            => '#5f5852',
                    'border'           => '#ddd2c4',
                    'primary'          => '#9a6433',
                    'primary-contrast' => '#fffaf6',
                    'primary-hover'    => '#805126',
                    'focus'            => '#c58a53',
                    'success'          => '#15803d',
                    'warning'          => '#b45309',
                    'danger'           => '#b91c1c',
                    'shadow-1'         => '0 8px 20px rgba(26, 22, 18, 0.08)',
                    'shadow-2'         => '0 18px 38px rgba(26, 22, 18, 0.14)',
                    'input'            => '#ffffff',
                    'input-disabled'   => '#ece4d8',
                ],
                'dark' => [
                    // CSOT — OLED black spec: #000000 bg, near-black surfaces, no blue-navy
                    'bg'               => '#000000',
                    'surface'          => '#0a0a0a',
                    'surface-alt'      => '#121212',
                    'surface-2'        => '#121212',
                    'surface-3'        => '#1a1a1a',
                    'text'             => '#f1efeb',
                    'muted'            => '#b6aea3',
                    'border'           => '#252525',
                    'primary'          => '#d1a06a',
                    'primary-contrast' => '#100b02',
                    'primary-hover'    => '#e0b582',
                    'focus'            => '#e0b582',
                    'success'          => '#34d399',
                    'warning'          => '#f59e0b',
                    'danger'           => '#f87171',
                    'shadow-1'         => '0 12px 28px rgba(0, 0, 0, 0.42)',
                    'shadow-2'         => '0 24px 52px rgba(0, 0, 0, 0.58)',
                    'input'            => '#0f0f0f',
                    'input-disabled'   => '#1a1a1a',
                ],
            ];
        }

        private static function is_front_app_route(): bool
        {
            if (is_admin() || wp_doing_ajax()) {
                return false;
            }

            $slugs = array_keys(self::APP_ROUTE_MAP);
            if (function_exists('is_page') && is_page($slugs)) {
                return true;
            }

            if ((function_exists('is_account_page') && is_account_page()) || (function_exists('is_checkout') && is_checkout())) {
                return true;
            }

            if (function_exists('is_cart') && is_cart()) {
                return true;
            }

            $post = get_post();
            if ($post instanceof WP_Post) {
                foreach (self::APP_SHORTCODES as $shortcode) {
                    if (has_shortcode($post->post_content, $shortcode)) {
                        return true;
                    }
                }

                if (self::elementor_document_contains_app_shortcodes($post->ID)) {
                    return true;
                }
            }

            $uri = isset($_SERVER['REQUEST_URI']) ? strtolower((string) wp_unslash($_SERVER['REQUEST_URI'])) : '';
            foreach ($slugs as $slug) {
                if ($slug !== '' && strpos($uri, '/' . $slug) !== false) {
                    return true;
                }
            }

            return false;
        }

        private static function detect_app_context(): string
        {
            foreach (self::APP_ROUTE_MAP as $slug => $context) {
                if (function_exists('is_page') && is_page($slug)) {
                    return $context;
                }
            }

            if (function_exists('is_account_page') && is_account_page()) {
                return 'account';
            }

            if ((function_exists('is_checkout') && is_checkout()) || (function_exists('is_cart') && is_cart())) {
                return 'checkout';
            }

            $uri = isset($_SERVER['REQUEST_URI']) ? strtolower((string) wp_unslash($_SERVER['REQUEST_URI'])) : '';
            foreach (self::APP_ROUTE_MAP as $slug => $context) {
                if ($slug !== '' && strpos($uri, '/' . $slug) !== false) {
                    return $context;
                }
            }

            return 'app';
        }

        private static function detect_page_family(): string
        {
            if (
                (function_exists('is_cart') && is_cart()) ||
                (function_exists('is_checkout') && is_checkout()) ||
                (function_exists('is_account_page') && is_account_page()) ||
                (function_exists('is_shop') && is_shop()) ||
                (function_exists('is_woocommerce') && is_woocommerce() && !self::is_front_app_route()) ||
                (function_exists('is_page') && is_page(['my-account', 'mijn-boekingen']))
            ) {
                return 'commerce';
            }

            if (function_exists('is_page') && is_page([
                'partner-portal',
                'partner-dashboard',
                'partner-profile',
                'partner-onboarding',
                'partner-claim',
                'partner-verify',
                'partner-uitbetaling',
                'partner-prijzen',
                'dieet-opgave',
            ])) {
                return 'partner';
            }

            if (
                (function_exists('is_singular') && is_singular(['sbdp_private_tour'])) ||
                (function_exists('is_page') && is_page(['tour', 'beleef']))
            ) {
                return 'tour';
            }

            if (
                (function_exists('is_post_type_archive') && (is_post_type_archive('activiteiten') || is_post_type_archive('activity') || is_post_type_archive('gd_place'))) ||
                (function_exists('is_page') && is_page(['activiteiten', 'spots', 'plattegrond', 'plan-je-dag', 'bossche-wiel'])) ||
                self::is_spot_detail_route() ||
                (function_exists('is_singular') && is_singular(['product']))
            ) {
                return 'discover';
            }

            if (function_exists('is_page') && is_page(['privacy-policy', 'refund_returns', 'terms-and-conditions', 'cookiebeleid'])) {
                return 'utility';
            }

            if ((function_exists('is_front_page') && is_front_page()) || (function_exists('is_home') && is_home()) || (function_exists('is_page') && is_page(['homepage', 'home', 'about', 'contact', 'offerte', 'premium-members']))) {
                return 'marketing';
            }

            return 'marketing';
        }

        private static function resolve_account_experience(?WP_User $user = null): string
        {
            if (!$user instanceof WP_User) {
                $user = wp_get_current_user();
            }

            if (!$user || !$user->exists()) {
                return 'customer';
            }

            $roles = array_map('strval', (array) $user->roles);

            if (self::user_has_role($roles, self::partner_roles())) {
                return 'partner';
            }

            if (self::user_has_role($roles, self::premium_roles())) {
                return 'premium';
            }

            return 'customer';
        }

        private static function normalize_account_variant(string $variant): string
        {
            $variant = strtolower(trim($variant));
            if ($variant === '') {
                return 'auto';
            }

            if (in_array($variant, ['auto', 'customer', 'partner', 'premium'], true)) {
                return $variant;
            }

            return 'auto';
        }

        private static function account_home_url_for_user(WP_User $user): string
        {
            $roles = array_map('strval', (array) $user->roles);
            if (self::user_has_role($roles, ['administrator', 'editor', 'shop_manager'])) {
                return '';
            }

            $experience = self::resolve_account_experience($user);

            if ($experience === 'partner') {
                return self::account_page_url('partner');
            }

            if ($experience === 'premium') {
                return self::account_page_url('premium');
            }

            return self::account_page_url('customer');
        }

        private static function account_page_url(string $variant): string
        {
            $variant = self::normalize_account_variant($variant);

            if ($variant === 'partner') {
                $page = get_page_by_path('partner-dashboard');
                if ($page instanceof WP_Post) {
                    $permalink = get_permalink($page);
                    if (is_string($permalink) && $permalink !== '') {
                        return $permalink;
                    }
                }

                $page = get_page_by_path('partner-profile');
                if ($page instanceof WP_Post) {
                    $permalink = get_permalink($page);
                    if (is_string($permalink) && $permalink !== '') {
                        return $permalink;
                    }
                }
            }

            if ($variant === 'premium') {
                $page = get_page_by_path('premium-members');
                if ($page instanceof WP_Post) {
                    $permalink = get_permalink($page);
                    if (is_string($permalink) && $permalink !== '') {
                        return $permalink;
                    }
                }
            }

            if (function_exists('wc_get_page_permalink')) {
                $account_url = wc_get_page_permalink('myaccount');
                if (is_string($account_url) && $account_url !== '') {
                    return $account_url;
                }
            }

            return home_url('/my-account/');
        }

        private static function user_can_access_variant(string $variant, ?WP_User $user = null): bool
        {
            $variant = self::normalize_account_variant($variant);

            if ($variant === 'auto' || $variant === 'customer') {
                return true;
            }

            if (!$user instanceof WP_User) {
                $user = wp_get_current_user();
            }

            if (!$user || !$user->exists()) {
                return false;
            }

            $roles = array_map('strval', (array) $user->roles);

            if ($variant === 'partner') {
                return self::user_has_role($roles, self::partner_roles());
            }

            if ($variant === 'premium') {
                return self::user_has_role($roles, self::premium_roles());
            }

            return false;
        }

        private static function partner_roles(): array
        {
            return [
                'partner',
                'partner_manager',
                'partner-manager',
                'account_partner',
                'ddb_spots_analyst',
            ];
        }

        private static function premium_roles(): array
        {
            return [
                'premium',
                'premium_member',
                'premium-member',
                'member',
                'premium_customer',
            ];
        }

        private static function user_has_role(array $roles, array $needles): bool
        {
            foreach ($roles as $role) {
                foreach ($needles as $needle) {
                    if ($role === $needle) {
                        return true;
                    }
                }
            }

            return false;
        }

        private static function account_hub_data(string $variant): array
        {
            $variant = self::normalize_account_variant($variant);
            $experience = $variant === 'auto' ? self::resolve_account_experience() : $variant;
            $login_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
            $logout_url = function_exists('wc_logout_url') ? wc_logout_url() : wp_logout_url(home_url('/'));
            $orders_url = function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('orders', '', function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/')) : home_url('/my-account/orders/');
            $addresses_url = function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('edit-address', '', function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/')) : home_url('/my-account/edit-address/');
            $account_url = function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('edit-account', '', function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/')) : home_url('/my-account/edit-account/');
            $downloads_url = function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('downloads', '', function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/')) : home_url('/my-account/downloads/');
            $shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
            $plan_url = home_url('/plan-je-dag/');
            $spots_url = home_url('/activiteiten/');
            $map_url = home_url('/plattegrond/');
            $contact_url = home_url('/contact/');
            $partner_url = self::account_page_url('partner');
            $premium_url = self::account_page_url('premium');
            $partner_requests_url = function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('ddb-partner-requests', '', function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/')) : $orders_url;
            $partner_visibility_url = function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('ddb-partner-visibility', '', function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/')) : $spots_url;

            $base = [
                'customer' => [
                    'eyebrow' => 'Account overzicht',
                    'title' => 'Alles van je account op één plek',
                    'summary' => 'Bewaar bestellingen, adressen en profielgegevens in één consistente omgeving.',
                    'primary' => [
                        'label' => 'Bestellingen bekijken',
                        'url' => $orders_url,
                    ],
                    'secondary' => [
                        'label' => 'Account bewerken',
                        'url' => $account_url,
                    ],
                    'highlights' => [
                        'Bestellingen en facturen',
                        'Adressen en profiel',
                        'Snelle toegang tot support',
                    ],
                    'cards' => [
                        [
                            'title' => 'Bestellingen',
                            'description' => 'Open je recente bestellingen, facturen en bestelstatussen.',
                            'label' => 'Bestellingen openen',
                            'url' => $orders_url,
                        ],
                        [
                            'title' => 'Adressen',
                            'description' => 'Houd factuur- en verzendgegevens klaar voor een volgende boeking.',
                            'label' => 'Adressen bewerken',
                            'url' => $addresses_url,
                        ],
                        [
                            'title' => 'Accountgegevens',
                            'description' => 'Werk je naam, e-mail en wachtwoord in één keer bij.',
                            'label' => 'Profiel bewerken',
                            'url' => $account_url,
                        ],
                        [
                            'title' => 'Support',
                            'description' => 'Neem contact op als er iets handmatig gecontroleerd of aangepast moet worden.',
                            'label' => 'Support contacteren',
                            'url' => $contact_url,
                        ],
                    ],
                ],
                'premium' => [
                    'eyebrow' => 'Premium leden',
                    'title' => 'Jouw premium leden-dashboard',
                    'summary' => 'Ga verder met plekken in de buurt, ledenvoordelen en je opgeslagen dagplannen.',
                    'primary' => [
                        'label' => 'Plekken in de buurt',
                        'url' => $spots_url,
                    ],
                    'secondary' => [
                        'label' => 'Dagje uit plannen',
                        'url' => $plan_url,
                    ],
                    'highlights' => [
                        'Plekken in de buurt met navigatie',
                        'Ledenvoordelen en deals',
                        'Opgeslagen plannen en boekingen',
                    ],
                    'cards' => [
                        [
                            'title' => 'Plekken in de buurt',
                            'description' => 'Zie wat dichtbij is en open direct navigatie vanuit dezelfde flow.',
                            'label' => 'Kaart openen',
                            'url' => $map_url,
                        ],
                        [
                            'title' => 'Ledenvoordelen',
                            'description' => 'Ontgrendel ledenaanbiedingen, bundels en tijdelijke perks.',
                            'label' => 'Deals bekijken',
                            'url' => $shop_url,
                        ],
                        [
                            'title' => 'Opgeslagen plannen',
                            'description' => 'Ga verder met plannen zonder opnieuw te beginnen.',
                            'label' => 'Planner openen',
                            'url' => $plan_url,
                        ],
                        [
                            'title' => 'Boekingen',
                            'description' => 'Bekijk huidige bestellingen, tickets en boekingsgeschiedenis.',
                            'label' => 'Boekingen bekijken',
                            'url' => $orders_url,
                        ],
                        [
                            'title' => 'Tickets',
                            'description' => 'Open je downloads en opgeslagen bewijzen opnieuw vanuit je account.',
                            'label' => 'Tickets openen',
                            'url' => $downloads_url,
                        ],
                    ],
                ],
                'partner' => [
                    'eyebrow' => 'Partner profiel',
                    'title' => 'Jouw partnerprofielpagina',
                    'summary' => 'Houd profiel, aanbiedingen en zichtbaarheid samen in hetzelfde accountsysteem.',
                    'primary' => [
                        'label' => 'Partnerprofiel bewerken',
                        'url' => $account_url,
                    ],
                    'secondary' => [
                        'label' => 'Aanvragen bekijken',
                        'url' => $orders_url,
                    ],
                    'highlights' => [
                        'Profiel- en contactgegevens',
                        'Aanbiedingen en zichtbaarheid',
                        'Aanvragen en opvolging',
                    ],
                    'cards' => [
                        [
                            'title' => 'Profielgegevens',
                            'description' => 'Werk naam, logo, contactgegevens en openbare partnerinformatie bij.',
                            'label' => 'Profiel bewerken',
                            'url' => $account_url,
                        ],
                        [
                            'title' => 'Zichtbaarheid',
                            'description' => 'Bereid aanbiedingen, highlights en seizoensplekken voor op de site.',
                            'label' => 'Vermeldingen bekijken',
                            'url' => $partner_visibility_url,
                        ],
                        [
                            'title' => 'Aanvragen',
                            'description' => 'Houd aanvragen, offertes en inkomende bestellingen bij.',
                            'label' => 'Aanvragen openen',
                            'url' => $partner_requests_url,
                        ],
                        [
                            'title' => 'Support',
                            'description' => 'Neem contact op als iets aangepast of verduidelijkt moet worden.',
                            'label' => 'Support contacteren',
                            'url' => $contact_url,
                        ],
                    ],
                ],
            ];

            $data = $base[$experience] ?? $base['customer'];
            $data['variant'] = $experience;
            $data['login_url'] = $login_url;
            $data['logout_url'] = $logout_url;
            $data['account_url'] = $account_url;
            $data['orders_url'] = $orders_url;
            $data['partner_url'] = $partner_url;
            $data['premium_url'] = $premium_url;

            if (!is_user_logged_in()) {
                $data['primary'] = [
                    'label' => 'Naar inloggen',
                    'url' => $login_url,
                ];
                $data['secondary'] = [
                    'label' => 'Activiteiten bekijken',
                    'url' => $spots_url,
                ];
            } elseif ($experience === 'partner' && self::user_can_access_variant('premium')) {
                $data['switch'] = [
                    'label' => 'Schakel naar premium ledenweergave',
                    'url' => $premium_url,
                ];
            } elseif ($experience === 'premium' && self::user_can_access_variant('partner')) {
                $data['switch'] = [
                    'label' => 'Schakel naar partnerprofiel',
                    'url' => $partner_url,
                ];
            }

            return $data;
        }

        private static function build_account_menu_items(string $experience, array $items): array
        {
            $custom_items = [
                'customer' => [
                    'dashboard' => 'Overzicht',
                    'orders' => 'Bestellingen',
                    'downloads' => 'Downloads',
                    'edit-address' => 'Adressen',
                    'edit-account' => 'Accountgegevens',
                    'customer-logout' => 'Uitloggen',
                ],
                'premium' => [
                    'dashboard' => 'Premium overzicht',
                    'ddb-nearby-spots' => 'Plekken in de buurt',
                    'ddb-club-deals' => 'Ledenvoordelen',
                    'ddb-saved-trips' => 'Opgeslagen plannen',
                    'orders' => 'Boekingen',
                    'downloads' => 'Tickets',
                    'edit-account' => 'Profiel',
                    'customer-logout' => 'Uitloggen',
                ],
                'partner' => [
                    'dashboard' => 'Partner profiel',
                    'ddb-partner-profile' => 'Partner profielpagina',
                    'ddb-partner-visibility' => 'Zichtbaarheid',
                    'ddb-partner-requests' => 'Aanvragen',
                    'edit-account' => 'Partnergegevens',
                    'customer-logout' => 'Uitloggen',
                ],
            ];

            $labels = $custom_items[$experience] ?? $custom_items['customer'];

            foreach ($labels as $key => $label) {
                if (array_key_exists($key, $items)) {
                    $items[$key] = $label;
                    continue;
                }

                if (in_array($key, ['ddb-nearby-spots', 'ddb-club-deals', 'ddb-saved-trips', 'ddb-partner-hub', 'ddb-partner-profile', 'ddb-partner-visibility', 'ddb-partner-requests'], true)) {
                    $items[$key] = $label;
                }
            }

            if ($experience === 'partner' && !array_key_exists('ddb-partner-hub', $items)) {
                $items = ['ddb-partner-hub' => 'Partner overzicht'] + $items;
            }

            if ($experience === 'premium' && !array_key_exists('ddb-premium-hub', $items)) {
                $items = ['ddb-premium-hub' => 'Premium overzicht'] + $items;
            }

            return $items;
        }

        public static function render_browser_bar(array $config = []): string
        {
            $variant = sanitize_key((string) ($config['variant'] ?? 'overview'));
            if ($variant === '') {
                $variant = 'overview';
            }

            $context = trim((string) ($config['context'] ?? ''));
            $title = trim((string) ($config['title'] ?? ''));

            $meta = array_values(array_filter(array_map(
                static fn($item): string => is_scalar($item) ? trim((string) $item) : '',
                (array) ($config['meta'] ?? [])
            )));

            $chips = [];
            foreach ((array) ($config['chips'] ?? []) as $chip) {
                if (!is_array($chip)) {
                    continue;
                }

                $label = trim((string) ($chip['label'] ?? ''));
                if ($label === '') {
                    continue;
                }

                $chips[] = [
                    'label' => $label,
                    'url' => is_string($chip['url'] ?? null) ? (string) $chip['url'] : '',
                    'active' => !empty($chip['active']),
                ];
            }

            $actions = [];
            foreach ((array) ($config['actions'] ?? []) as $action) {
                if (!is_array($action)) {
                    continue;
                }

                $label = trim((string) ($action['label'] ?? ''));
                $url = trim((string) ($action['url'] ?? ''));
                if ($label === '' || $url === '') {
                    continue;
                }

                $style = sanitize_key((string) ($action['style'] ?? 'secondary'));
                if (!in_array($style, ['primary', 'secondary', 'ghost'], true)) {
                    $style = 'secondary';
                }

                $actions[] = [
                    'label' => $label,
                    'url' => $url,
                    'style' => $style,
                ];
            }

            $search = is_array($config['search'] ?? null) ? $config['search'] : null;
            $search_enabled = false;
            $search_action = '';
            $search_name = 'ddb_q';
            $search_value = '';
            $search_placeholder = '';
            $search_button = 'Zoeken';

            if (is_array($search)) {
                $search_action = trim((string) ($search['action'] ?? ''));
                $search_name = sanitize_key((string) ($search['name'] ?? 'ddb_q'));
                if ($search_name === '') {
                    $search_name = 'ddb_q';
                }
                $search_value = trim((string) ($search['value'] ?? ''));
                $search_placeholder = trim((string) ($search['placeholder'] ?? 'Zoek verder'));
                $search_button = trim((string) ($search['button'] ?? 'Zoeken'));
                $search_enabled = $search_action !== '';
            }

            $html = '<section class="ddb-browser-bar ddb-browser-bar--' . esc_attr($variant) . '" aria-label="' . esc_attr__('Pagina oriëntatie', 'ddb-core') . '">';
            $html .= '<div class="ddb-browser-bar__inner">';

            if ($context !== '' || $title !== '' || $meta !== []) {
                $html .= '<div class="ddb-browser-bar__lead">';
                if ($context !== '') {
                    $html .= '<p class="ddb-browser-bar__eyebrow">' . esc_html($context) . '</p>';
                }
                if ($title !== '') {
                    $html .= '<p class="ddb-browser-bar__title">' . esc_html($title) . '</p>';
                }
                if ($meta !== []) {
                    $html .= '<div class="ddb-browser-bar__meta">';
                    foreach ($meta as $item) {
                        $html .= '<span class="ddb-browser-bar__meta-item">' . esc_html($item) . '</span>';
                    }
                    $html .= '</div>';
                }
                $html .= '</div>';
            }

            $html .= '<div class="ddb-browser-bar__body">';

            if ($search_enabled) {
                $html .= '<form class="ddb-browser-bar__search" role="search" method="get" action="' . esc_url($search_action) . '">';
                $html .= '<label class="screen-reader-text" for="ddb-browser-search-' . esc_attr($variant) . '">' . esc_html($search_placeholder) . '</label>';
                $html .= '<input id="ddb-browser-search-' . esc_attr($variant) . '" class="ddb-browser-bar__search-input" type="search" name="' . esc_attr($search_name) . '" value="' . esc_attr($search_value) . '" placeholder="' . esc_attr($search_placeholder) . '" />';
                $html .= '<button class="ddb-browser-bar__search-submit" type="submit">' . esc_html($search_button) . '</button>';
                $html .= '</form>';
            }

            if ($chips !== []) {
                $html .= '<div class="ddb-browser-bar__chips" role="list">';
                foreach ($chips as $chip) {
                    $classes = 'ddb-browser-bar__chip' . ($chip['active'] ? ' is-active' : '');
                    if ($chip['url'] !== '') {
                        $html .= '<a class="' . esc_attr($classes) . '" href="' . esc_url($chip['url']) . '" role="listitem">' . esc_html($chip['label']) . '</a>';
                    } else {
                        $html .= '<span class="' . esc_attr($classes) . '" role="listitem">' . esc_html($chip['label']) . '</span>';
                    }
                }
                $html .= '</div>';
            }

            if ($actions !== []) {
                $html .= '<div class="ddb-browser-bar__actions">';
                foreach ($actions as $action) {
                    $html .= '<a class="ui-btn ui-btn--' . esc_attr($action['style']) . '" href="' . esc_url($action['url']) . '">' . esc_html($action['label']) . '</a>';
                }
                $html .= '</div>';
            }

            $html .= '</div></div></section>';

            return $html;
        }

        private static function render_account_hub_markup(string $variant): string
        {
            $data = self::account_hub_data($variant);
            $active_variant = $data['variant'] ?? 'customer';
            $current_variant = self::resolve_account_experience();
            $is_logged_in = is_user_logged_in();
            $is_allowed = self::user_can_access_variant($active_variant);
            if (!$is_logged_in) {
                $is_allowed = true;
            }

            ob_start();
            ?>
            <section class="ui-section ui-section--tight ddb-account-hub" data-account-variant="<?php echo esc_attr($active_variant); ?>">
                <div class="ui-container ui-container--lg">
                    <div class="ui-stack">
                        <?php
                        // Account is a management page — no Browser Bar here.
                        // The ui-card hero below already provides orientation.
                        ?>
                        <div class="ui-card ui-card--featured ddb-account-hub__hero">
                            <div class="ui-card__body">
                                <p class="ddb-account-hub__eyebrow"><?php echo esc_html($data['eyebrow']); ?></p>
                                <h1 class="ui-card__title"><?php echo esc_html($data['title']); ?></h1>
                                <p class="ui-card__desc"><?php echo esc_html($data['summary']); ?></p>
                                <div class="ddb-account-hub__highlights">
                                    <?php foreach ((array) ($data['highlights'] ?? []) as $highlight) : ?>
                                        <span class="ddb-account-hub__highlight"><?php echo esc_html($highlight); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="ddb-account-hub__actions">
                                    <a class="ui-btn ui-btn--primary" href="<?php echo esc_url($data['primary']['url']); ?>">
                                        <?php echo esc_html($data['primary']['label']); ?>
                                    </a>
                                    <a class="ui-btn ui-btn--secondary" href="<?php echo esc_url($data['secondary']['url']); ?>">
                                        <?php echo esc_html($data['secondary']['label']); ?>
                                    </a>
                                    <?php if (!empty($data['switch']['url'])) : ?>
                                        <a class="ui-btn ui-btn--ghost" href="<?php echo esc_url($data['switch']['url']); ?>">
                                            <?php echo esc_html($data['switch']['label']); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($is_logged_in) : ?>
                                        <a class="ui-btn ui-btn--ghost" href="<?php echo esc_url($data['logout_url']); ?>">
                                            <?php echo esc_html('Uitloggen'); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!$is_logged_in) : ?>
                            <div class="ui-card ui-card--info">
                                <div class="ui-card__body">
                                    <h2 class="ui-card__title"><?php echo esc_html('Log in om verder te gaan'); ?></h2>
                                    <p class="ui-card__desc"><?php echo esc_html('Gebruik je account om de member- en partnerervaring te ontgrendelen.'); ?></p>
                                    <a class="ui-btn ui-btn--primary" href="<?php echo esc_url($data['login_url']); ?>"><?php echo esc_html('Naar inloggen'); ?></a>
                                </div>
                            </div>
                        <?php elseif (!$is_allowed) : ?>
                            <div class="ui-card ui-card--info">
                                <div class="ui-card__body">
                                    <h2 class="ui-card__title"><?php echo esc_html('Deze pagina hoort bij een ander accounttype'); ?></h2>
                                    <p class="ui-card__desc"><?php echo esc_html('Gebruik je hoofdaccount of wissel naar de juiste profielpagina.'); ?></p>
                                    <a class="ui-btn ui-btn--primary" href="<?php echo esc_url(self::account_page_url($current_variant)); ?>"><?php echo esc_html('Mijn account openen'); ?></a>
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="ui-grid ui-grid--3 ddb-account-hub__cards">
                                <?php foreach ((array) ($data['cards'] ?? []) as $card) : ?>
                                    <article class="ui-card ddb-account-hub__card">
                                    <div class="ui-card__body">
                                        <h2 class="ui-card__title"><?php echo esc_html($card['title']); ?></h2>
                                        <p class="ui-card__desc"><?php echo esc_html($card['description']); ?></p>
                                            <a class="ui-btn ui-btn--secondary ddb-account-hub__card-action" href="<?php echo esc_url($card['url']); ?>">
                                                <?php echo esc_html($card['label']); ?>
                                            </a>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
            <?php

            return (string) ob_get_clean();
        }

        private static function elementor_document_contains_app_shortcodes(int $post_id): bool
        {
            if ($post_id <= 0) {
                return false;
            }

            $raw_data = get_post_meta($post_id, '_elementor_data', true);
            if (!is_string($raw_data) || $raw_data === '') {
                return false;
            }

            foreach (self::APP_SHORTCODES as $shortcode) {
                if (strpos($raw_data, $shortcode) !== false) {
                    return true;
                }
            }

            return false;
        }

        private static function is_plugin_admin_screen(): bool
        {
            if (!is_admin() || !function_exists('get_current_screen')) {
                return false;
            }

            $screen = get_current_screen();
            if (!$screen) {
                return false;
            }

            $haystack = strtolower(
                implode(
                    ' ',
                    array_filter(
                        [
                            (string) $screen->id,
                            (string) $screen->base,
                            (string) $screen->post_type,
                            (string) $screen->parent_base,
                        ]
                    )
                )
            );

            $keywords = [
                'sbdp',
                'bsp',
                'ddb-spinwheel',
                'ddb-spots',
                'spots-business',
            ];

            foreach ($keywords as $keyword) {
                if (strpos($haystack, $keyword) !== false) {
                    return true;
                }
            }

            return false;
        }

        private static function maybe_update_option(string $key, string $expected): void
        {
            $current = get_option($key, null);
            if ($current !== $expected) {
                update_option($key, $expected);
            }
        }

        private static function is_local_asset_url(string $url): bool
        {
            $asset_host = wp_parse_url($url, PHP_URL_HOST);
            $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);

            if (!is_string($asset_host) || $asset_host === '') {
                return true;
            }

            if (!is_string($site_host) || $site_host === '') {
                return false;
            }

            return strtolower($asset_host) === strtolower($site_host);
        }

        private static function safe_mode_enabled(): bool
        {
            if (defined('DDB_DS_SAFE_MODE')) {
                return (bool) DDB_DS_SAFE_MODE;
            }

            // Default to safe mode to prevent destructive output/style pruning on live pages.
            return true;
        }

        private static function should_disable_remote_agent_widget(): bool
        {
            $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
            $request_host = isset($_SERVER['HTTP_HOST']) ? (string) wp_unslash($_SERVER['HTTP_HOST']) : '';
            $request_host = strtolower(trim(preg_replace('/:\\d+$/', '', $request_host) ?? ''));

            if (is_string($site_host) && $site_host !== '') {
                $site_host = strtolower($site_host);
                if (str_ends_with($site_host, '.local') || $site_host === 'localhost') {
                    return true;
                }
            }

            return str_ends_with($request_host, '.local') || $request_host === 'localhost';
        }

        private static function normalize_front_language_attributes(string $attributes): string
        {
            if (!self::should_force_dutch_frontend_locale()) {
                return $attributes;
            }

            if (preg_match('/\blang=(["\']).*?\1/i', $attributes)) {
                return (string) preg_replace('/\blang=(["\']).*?\1/i', 'lang="nl-NL"', $attributes, 1);
            }

            return trim($attributes . ' lang="nl-NL"');
        }

        private static function should_force_dutch_frontend_locale(): bool
        {
            if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
                return false;
            }

            if (
                (defined('REST_REQUEST') && REST_REQUEST) ||
                (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            ) {
                return false;
            }

            if (function_exists('wp_is_json_request') && wp_is_json_request()) {
                return false;
            }

            return true;
        }

        private static function is_visual_builder_request(): bool
        {
            if (filter_has_var(INPUT_GET, 'elementor-preview')) {
                return true;
            }

            if (filter_has_var(INPUT_GET, 'elementor_library') || filter_has_var(INPUT_GET, 'elementor-library')) {
                return true;
            }

            if (!class_exists('\\Elementor\\Plugin')) {
                return false;
            }

            $plugin = \Elementor\Plugin::$instance;
            if ($plugin && method_exists($plugin, 'editor') && $plugin->editor) {
                if (method_exists($plugin->editor, 'is_edit_mode') && $plugin->editor->is_edit_mode()) {
                    return true;
                }
            }

            return $plugin && isset($plugin->preview)
                && method_exists($plugin->preview, 'is_preview_mode')
                && $plugin->preview->is_preview_mode();
        }

        private static function remove_callbacks_from_files(string $hook, array $file_needles): void
        {
            global $wp_filter;

            if (empty($wp_filter[$hook]) || !($wp_filter[$hook] instanceof WP_Hook)) {
                return;
            }

            foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    $callable = $callback['function'] ?? null;
                    if (!$callable) {
                        continue;
                    }

                    $source_file = self::resolve_callable_file($callable);
                    if ($source_file === null) {
                        continue;
                    }

                    foreach ($file_needles as $needle) {
                        if (stripos($source_file, $needle) === false) {
                            continue;
                        }

                        remove_action($hook, $callable, (int) $priority);
                        break;
                    }
                }
            }
        }

        private static function resolve_callable_file($callable): ?string
        {
            try {
                if ($callable instanceof Closure) {
                    $reflection = new ReflectionFunction($callable);

                    return $reflection->getFileName() ?: null;
                }

                if (is_array($callable) && count($callable) === 2) {
                    $target = $callable[0];
                    $method = (string) $callable[1];

                    if (is_object($target) || (is_string($target) && class_exists($target))) {
                        $reflection = new ReflectionMethod($target, $method);

                        return $reflection->getFileName() ?: null;
                    }
                }

                if (is_string($callable) && function_exists($callable)) {
                    $reflection = new ReflectionFunction($callable);

                    return $reflection->getFileName() ?: null;
                }
            } catch (ReflectionException $exception) {
                return null;
            }

            return null;
        }
    }
}

// Legacy MU runtime is disabled by default so the platform can run with one
// active design-system authority. Re-enable only for rollback/debug.
if (defined('DDB_ENABLE_LEGACY_MU_DESIGN_SYSTEM') && DDB_ENABLE_LEGACY_MU_DESIGN_SYSTEM) {
    DDB_Core_Design_System::boot();
}
