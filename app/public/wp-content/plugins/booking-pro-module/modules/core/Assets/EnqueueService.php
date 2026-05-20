<?php

declare(strict_types=1);

namespace BSPModule\Core\Assets;

use BSPModule\Core\Admin\AdminMenu;
use BSPModule\Core\Rest\RestService as PlannerRestService;
use BSPModule\Core\Services\BookingTruthRuntimeService;
use BSPModule\Core\WooCommerce\Display\ProductForm;
use BSPModule\Core\WooCommerce\ProductPageContext;
use BSPModule\Core\WooCommerce\ProductType\BookableServiceProductType;

/**
 * Script and style loading for frontend and admin interfaces.
 */
final class EnqueueService
{
    public const FRONT_HANDLE_STYLE  = 'sbdp-planner';
    public const FRONT_HANDLE_SCRIPT = 'sbdp-planner';
    public const FRONT_HANDLE_VENDOR = 'sbdp-fullcalendar';
    public const PRODUCT_HANDLE_STYLE = 'sbdp-product-booking';
    public const PRODUCT_HANDLE_SCRIPT = 'sbdp-product-booking';
    public const THEME_TOGGLE_HANDLE  = 'sbdp-theme-toggle';
    public const GLOBAL_THEME_HANDLE  = 'sbdp-global-theme';
    public const COMMERCIAL_FLOW_HANDLE = 'sbdp-commercial-flow';
    public static function init(): void
    {
        if (did_action('init')) {
            self::register_front_assets();
        } else {
            add_action('init', [__CLASS__, 'register_front_assets']);
        }
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue_front_assets']);
        add_action('wp_footer', [__CLASS__, 'inject_product_booking_loader'], 5);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_theme_toggle'], 1);
        add_action('login_enqueue_scripts', [__CLASS__, 'enqueue_login_theme']);
        add_action('elementor/editor/before_enqueue_scripts', [__CLASS__, 'enqueue_for_elementor']);
        add_action('elementor/preview/enqueue_styles', [__CLASS__, 'enqueue_for_elementor']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        add_action('admin_head', [__CLASS__, 'inline_admin_theme_script'], 1);
        add_action('admin_bar_menu', [__CLASS__, 'register_admin_bar_dark_mode_toggle'], 999);
        add_filter('mce_css', [__CLASS__, 'add_tinymce_theme_css']);
    }

    public static function register_front_assets(): void
    {
        wp_register_style(
            self::FRONT_HANDLE_STYLE,
            SBDP_URL . 'assets/planner.css',
            [],
            SBDP_VER
        );

        wp_register_script(
            self::FRONT_HANDLE_VENDOR,
            SBDP_URL . 'assets/js/vendor/vue.esm-browser.prod.js',
            [],
            SBDP_VER,
            true
        );
        wp_script_add_data(self::FRONT_HANDLE_VENDOR, 'type', 'module');

        wp_register_script(
            self::FRONT_HANDLE_SCRIPT,
            SBDP_URL . 'assets/js/planner.js',
            [self::FRONT_HANDLE_VENDOR, 'jquery'],
            SBDP_VER,
            true
        );
        wp_script_add_data(self::FRONT_HANDLE_SCRIPT, 'type', 'module');

        wp_register_style(
            self::PRODUCT_HANDLE_STYLE,
            SBDP_URL . 'assets/product-booking.css',
            [],
            SBDP_VER
        );

        wp_register_script(
            self::PRODUCT_HANDLE_SCRIPT,
            SBDP_URL . 'assets/product-booking.js',
            [],
            SBDP_VER,
            true
        );

        wp_register_script(
            self::THEME_TOGGLE_HANDLE,
            SBDP_URL . 'assets/theme-toggle.js',
            [],
            SBDP_VER,
            true
        );

        wp_register_style(
            self::GLOBAL_THEME_HANDLE,
            SBDP_URL . 'assets/global-theme.css',
            [],
            SBDP_VER
        );

        wp_register_style(
            self::COMMERCIAL_FLOW_HANDLE,
            SBDP_URL . 'assets/css/sbdp-cart-checkout.css',
            [self::GLOBAL_THEME_HANDLE, 'sbdp-flow-system'],
            SBDP_VER
        );
    }

    public static function maybe_enqueue_front_assets(): void
    {
        // Don't enqueue Vue/planner in Elementor editor or preview
        if (self::is_elementor_preview()) {
            return;
        }

        self::register_front_assets();
        self::maybe_enqueue_commercial_flow();

        if (function_exists('is_product') && is_product()) {
            if (! self::should_enqueue_product_booking_assets()) {
                return;
            }
            wp_enqueue_style(self::PRODUCT_HANDLE_STYLE);
            wp_enqueue_script(self::PRODUCT_HANDLE_SCRIPT);
            self::add_defer(self::PRODUCT_HANDLE_SCRIPT);
            self::localize_product_booking_data();
            return;
        }

        if (apply_filters('sbdp_force_enqueue_planner', false)) {
            self::enqueue_front_assets();
            return;
        }

        if (! self::should_enqueue_legacy_planner()) {
            return;
        }

        self::enqueue_front_assets();
    }

    private static function localize_product_booking_data(): void
    {
        if (! function_exists('wc_get_product')) {
            return;
        }

        $product = wc_get_product(get_queried_object_id());
        if (! $product instanceof \WC_Product) {
            return;
        }

        $product_type = $product->get_type();
        if ($product_type !== self::resolve_bookable_type()) {
            return;
        }

        wp_localize_script(
            self::PRODUCT_HANDLE_SCRIPT,
            'SBDP_ProductBooking',
            self::get_product_booking_payload($product)
        );
    }

    public static function inject_product_booking_loader(): void
    {
        if (is_admin() || ! function_exists('is_product') || ! is_product()) {
            return;
        }

        if (! self::should_enqueue_product_booking_assets()) {
            return;
        }

        if (! function_exists('wc_get_product')) {
            return;
        }

        $product = wc_get_product(get_queried_object_id());
        if (! $product instanceof \WC_Product) {
            return;
        }

        $product_type = $product->get_type();
        if ($product_type !== self::resolve_bookable_type()) {
            return;
        }

        $src = SBDP_URL . 'assets/product-booking.js';
        $ver = defined('SBDP_VER') ? SBDP_VER : time();
        $payload = self::get_product_booking_payload($product);
        ?>
        <script>
        (function(){
          if (window.SBDPProductBookingLoaded) {
            return;
          }
          if (!window.SBDP_ProductBooking) {
            window.SBDP_ProductBooking = <?php echo wp_json_encode($payload); ?>;
          }
          var bookingForm = document.querySelector('#sbdp-booking-form');
          if (!bookingForm) {
            return;
          }
          if (
            (bookingForm.hasAttribute && bookingForm.hasAttribute('data-sbdp-legacy-form')) ||
            (bookingForm.closest && bookingForm.closest('[data-sbdp-legacy-form="true"]'))
          ) {
            return;
          }
          if (document.querySelector('script[data-sbdp-product-booking]')) {
            return;
          }
          var script = document.createElement('script');
          script.src = <?php echo wp_json_encode(add_query_arg('ver', $ver, $src)); ?>;
          script.defer = true;
          script.dataset.sbdpProductBooking = 'true';
          document.head.appendChild(script);
        })();
        </script>
        <?php
    }

    /**
     * @return array<string, mixed>
     */
    private static function get_product_booking_payload(\WC_Product $product): array
    {
        $combi_options = [];
        if (class_exists(ProductForm::class)) {
            $combi_options = ProductForm::build_combi_options([], $product);
        }

        $base_price_gross = 0.0;
        $per_person_gross = 0.0;
        if (class_exists('\SBDP\Pricing\PricingService')) {
            try {
                $pricing_data = \SBDP\Pricing\PricingService::instance()->getProductPricing(
                    $product->get_id(),
                    [
                        'channel'    => 'product_booking_payload',
                        'source'     => 'enqueue_service',
                        'price_mode' => 'gross',
                    ]
                );
                $base_price_gross = (float) ($pricing_data['base_price'] ?? 0.0);
                $per_person_gross = ! empty($pricing_data['supports_persons'])
                    ? (float) ($pricing_data['per_person'] ?? 0.0)
                    : 0.0;
                if ($base_price_gross <= 0.0 && $per_person_gross > 0.0) {
                    $base_price_gross = $per_person_gross;
                }
            } catch (\Throwable $exception) {
                $base_price_gross = 0.0;
                $per_person_gross = 0.0;
            }
        }
        if ($base_price_gross <= 0.0) {
            $base_price_gross = function_exists('wc_get_price_including_tax')
                ? (float) wc_get_price_including_tax($product, array('qty' => 1))
                : (float) $product->get_price();
        }

        $duration = 90;
        if (class_exists(\SBDP\Core\ProductSettings::class)) {
            try {
                $settings = \SBDP\Core\ProductSettings::get($product->get_id());
                $duration = (int) ($settings['duration_minutes'] ?? $duration);
            } catch (\Throwable $exception) {
                $duration = 90;
            }
        } else {
            $duration = (int) get_post_meta($product->get_id(), '_sbdp_duration', true);
            if ($duration <= 0) {
                $duration = 90;
            }
        }

        $booking_profile = self::build_initial_booking_profile($product, $duration);

        return [
            'compose'           => esc_url_raw(rest_url('sbdp/v1/compose_booking')),
            'availability'      => esc_url_raw(rest_url('sbdp/v1/availability/plan')),
            'availability_slots'=> esc_url_raw(rest_url('sbdp/v1/availability/slots')),
            'nonce'             => wp_create_nonce(PlannerRestService::PUBLIC_NONCE_ACTION),
            'fallback_redirect' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout'),
            'planner_url'       => self::get_planner_url(),
            'quote_url'         => self::get_quote_url(),
            'planner_route'     => '/planner/start',
            'bookingCapability' => $booking_profile,
            'messages'          => [
                'generic_error'   => __('Er ging iets mis. Probeer het opnieuw.', 'sbdp'),
                'missing_fields'  => __('Vul datum, starttijd en aantal personen in.', 'sbdp'),
                'planner_missing' => __('Plannerpagina niet gevonden.', 'sbdp'),
                'redirecting'     => __('Bezig met doorsturen.', 'sbdp'),
                'request_redirecting' => __('We openen de offerte-aanvraag. Prijs en beschikbaarheid worden eerst bevestigd.', 'sbdp'),
                'no_slots'        => __('Geen tijdsloten beschikbaar voor deze datum.', 'sbdp'),
                'no_capacity'     => __('De geselecteerde capaciteit is niet beschikbaar.', 'sbdp'),
                'select_time'     => __('Selecteer een starttijd', 'sbdp'),
                'select_participants' => __('Selecteer aantal personen', 'sbdp'),
                'planner_idle'    => __('Nog geen activiteiten in je planning.', 'sbdp'),
                'planner_ready_single' => __('1 activiteit staat klaar voor Plan je dag.', 'sbdp'),
                'planner_ready_multi'  => __('%s activiteiten staan klaar voor Plan je dag.', 'sbdp'),
                'planner_pending' => __('Activiteit wordt toegevoegd aan je planning...', 'sbdp'),
                'planner_queue_count' => __('%s activiteiten klaar voor Plan je dag', 'sbdp'),
                'planner_pending_short' => __('Bezig...', 'sbdp'),
                'planner_error_short'   => __('Let op', 'sbdp'),
                'planner_success_short' => __('Gereed', 'sbdp'),
                'planner_info_short'    => __('Info', 'sbdp'),
            ],
            'pricing_preview'  => esc_url_raw(rest_url('sbdp/v1/pricing/preview')),
            'combiOptions'     => $combi_options,
            'basePrice'        => $base_price_gross,
            'perPersonPrice'   => $per_person_gross,
            'supportsPersons'  => $per_person_gross > 0.0,
            'duration'         => $duration,
        ];
    }

    private static function should_enqueue_product_booking_assets(): bool
    {
        if (! ProductPageContext::isBookableServiceProductRequest()) {
            return false;
        }

        if (ProductPageContext::shouldUseLegacyPlannerOverrides()) {
            return true;
        }

        $summary_enabled = get_option('sbdp_product_layout_enabled', '1');
        $summary_enabled = $summary_enabled === '1' || $summary_enabled === 1 || $summary_enabled === true;
        $summary_enabled = (bool) apply_filters('sbdp/product_summary/enabled', $summary_enabled);

        return ! $summary_enabled;
    }

    private static function resolve_bookable_type(): string
    {
        if (class_exists(BookableServiceProductType::class)) {
            return BookableServiceProductType::PRODUCT_TYPE;
        }

        if (class_exists('\\SBDP_Product_Type') && defined('\\SBDP_Product_Type::PRODUCT_TYPE')) {
            return \SBDP_Product_Type::PRODUCT_TYPE;
        }

        return 'bookable_service';
    }

    private static function get_planner_url(): string
    {
        $page_id = (int) get_option('sbdp_planner_page_id', 0);
        if ($page_id > 0) {
            $link = get_permalink($page_id);
            if ($link) {
                return $link;
            }
        }

        $page = get_page_by_path('plan-je-dag');
        if ($page instanceof \WP_Post) {
            $link = get_permalink($page);
            if ($link) {
                return $link;
            }
        }

        return '';
    }

    /**
     * @return array{status:string,route_intent:string,reason_code:?string,legacy_status:string}
     */
    private static function build_initial_booking_profile(\WC_Product $product, int $duration): array
    {
        $product_id = (int) $product->get_id();
        $date = (string) get_post_meta($product_id, '_sbdp_default_start_date', true);
        if ($date === '') {
            $date = function_exists('wp_date') ? wp_date('Y-m-d') : gmdate('Y-m-d');
        }

        $time = self::normalize_time((string) get_post_meta($product_id, '_sbdp_default_start_time', true));
        if ($time === '') {
            $time = '10:00';
        }

        if ($duration <= 0) {
            $duration = 60;
        }

        $start = sprintf('%sT%s:00', $date, $time);
        $end_timestamp = strtotime($start) + ($duration * MINUTE_IN_SECONDS);
        $end = $end_timestamp > 0 ? gmdate('Y-m-d\TH:i:s', $end_timestamp) : sprintf('%sT%s:00', $date, $time);

        $participants = (int) get_post_meta($product_id, '_sbdp_min_people', true);
        if ($participants <= 0) {
            $participants = 1;
        }

        $resource_id = (int) get_post_meta($product_id, '_sbdp_resource_id', true);
        if ($resource_id <= 0) {
            $resources = \BSPModule\Core\Product\ProductMeta::get_resource_ids($product_id);
            if ($resources !== array()) {
                $resource_id = (int) $resources[0];
            }
        }

        $runtime = new BookingTruthRuntimeService();
        $item = array(
                'product_id' => $product_id,
                'resource_id' => $resource_id,
                'date' => $date,
                'start' => $start,
                'end' => $end,
                'participants' => $participants,
        );
        $profile = $runtime->resolveBookingCapabilityProfile($item);
        if (($profile['route_intent'] ?? '') !== BookingTruthRuntimeService::ROUTE_INTENT_CHECKOUT && in_array((string) ($profile['reason_code'] ?? ''), array('selected_time_invalid', 'time_unavailable'), true)) {
            for ($offset = 1; $offset <= 14; $offset++) {
                $candidate_date = function_exists('wp_date')
                    ? wp_date('Y-m-d', strtotime('+' . $offset . ' days'))
                    : gmdate('Y-m-d', strtotime('+' . $offset . ' days'));
                $candidate_start = sprintf('%sT%s:00', $candidate_date, $time);
                $candidate_end_timestamp = strtotime($candidate_start) + ($duration * MINUTE_IN_SECONDS);
                $candidate_end = $candidate_end_timestamp > 0 ? gmdate('Y-m-d\TH:i:s', $candidate_end_timestamp) : $candidate_start;
                $candidate = $runtime->resolveBookingCapabilityProfile(array_merge($item, array(
                    'date' => $candidate_date,
                    'start' => $candidate_start,
                    'end' => $candidate_end,
                )));
                if (($candidate['route_intent'] ?? '') === BookingTruthRuntimeService::ROUTE_INTENT_CHECKOUT) {
                    return $candidate;
                }
            }
        }

        return $profile;
    }

    private static function normalize_time(string $time): string
    {
        $time = trim($time);
        if ($time === '') {
            return '';
        }

        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches) !== 1) {
            return '';
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return '';
        }

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    private static function get_quote_url(): string
    {
        $page = get_page_by_path('offerte');
        if ($page instanceof \WP_Post) {
            $link = get_permalink($page);
            if ($link) {
                return $link;
            }
        }

        return home_url('/offerte/');
    }

    public static function enqueue_for_elementor(): void
    {
        self::register_front_assets();

        // Keep the editor aligned with the shared theme layer, but don't flood every
        // Elementor document with planner/product CSS that it doesn't render.
        wp_enqueue_style(self::GLOBAL_THEME_HANDLE);

        $document_id = self::get_current_elementor_document_id();
        if ($document_id <= 0) {
            return;
        }

        if (self::should_enqueue_elementor_planner_style($document_id)) {
            wp_enqueue_style(self::FRONT_HANDLE_STYLE);
        }

        if (self::should_enqueue_elementor_product_style($document_id)) {
            wp_enqueue_style(self::PRODUCT_HANDLE_STYLE);
        }
    }

    private static function maybe_enqueue_commercial_flow(): void
    {
        if (! self::should_enqueue_commercial_flow()) {
            return;
        }

        wp_enqueue_style(self::GLOBAL_THEME_HANDLE);
        wp_enqueue_style(self::COMMERCIAL_FLOW_HANDLE);
    }

    public static function enqueue_theme_toggle(): void
    {
        if (! self::should_enqueue_legacy_theme_toggle()) {
            return;
        }

        self::register_front_assets();
        wp_enqueue_style(self::GLOBAL_THEME_HANDLE);
        wp_enqueue_script(self::THEME_TOGGLE_HANDLE);
        self::add_defer(self::THEME_TOGGLE_HANDLE);
    }

    public static function enqueue_login_theme(): void
    {
        if (! self::should_enqueue_legacy_theme_toggle()) {
            return;
        }

        self::register_front_assets();
        wp_enqueue_style(self::GLOBAL_THEME_HANDLE);
        wp_enqueue_script(self::THEME_TOGGLE_HANDLE);
        self::add_defer(self::THEME_TOGGLE_HANDLE);
    }

    /**
     * Wave 2 — Anti-flash: set data-adm-theme on <html> before first paint.
     * Must run in admin_head (priority 1) to beat any CSS.
     */
    public static function inline_admin_theme_script(): void
    {
        echo '<script id="ddb-admin-theme-init">' .
            '(function(){' .
            'var t="";' .
            'try{t=localStorage.getItem("ddb-admin-theme")||""}catch(e){}' .
            'if(t!=="dark"&&t!=="light"){' .
            'try{t=window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"}catch(e){t="light"}' .
            '}' .
            'document.documentElement.setAttribute("data-adm-theme",t);' .
            '}());' .
            '</script>' . "\n";
    }

    /**
     * Wave 2 — Admin bar: add dark/light toggle button to the WP top bar.
     */
    public static function register_admin_bar_dark_mode_toggle(\WP_Admin_Bar $bar): void
    {
        if (! function_exists('is_admin_bar_showing') || ! is_admin_bar_showing()) {
            return;
        }
        $bar->add_node([
            'id'     => 'ddb-dark-mode',
            'title'  => '<span class="ddb-theme-toggle-icon ddb-theme-icon-moon" aria-hidden="true">&#9790;</span>' .
                        '<span class="ddb-theme-toggle-icon ddb-theme-icon-sun" aria-hidden="true">&#9728;</span>',
            'href'   => '#',
            'parent' => 'top-secondary',
            'meta'   => [
                'class' => 'ddb-theme-toggle',
                'title' => 'Schakel donker/lichtmodus (Ctrl+Shift+D)',
            ],
        ]);
    }

    /**
     * Wave 2 — Inject admin-tinymce.css into the TinyMCE editor iframe.
     * CSS custom properties don't cross iframe boundaries, so dark mode
     * uses the .ddb-dark body class toggled by admin-dark-mode-toggle.js.
     */
    public static function add_tinymce_theme_css(string $mce_css): string
    {
        if (! defined('SBDP_URL') || ! defined('SBDP_DIR')) {
            return $mce_css;
        }
        $version = (string) @filemtime(SBDP_DIR . 'assets/admin-tinymce.css');
        $url     = SBDP_URL . 'assets/admin-tinymce.css?' . $version;
        return $mce_css !== '' ? $mce_css . ',' . $url : $url;
    }

    public static function enqueue_admin_assets(string $hook): void
    {
        // Wave 1 + 2: global design tokens + dark mode toggle on every admin page.
        $tokenVersion  = defined('SBDP_DIR') && function_exists('filemtime')
            ? (string) @filemtime(SBDP_DIR . 'assets/admin-design-tokens.css')
            : (defined('SBDP_VER') ? SBDP_VER : null);
        $toggleVersion = defined('SBDP_DIR') && function_exists('filemtime')
            ? (string) @filemtime(SBDP_DIR . 'assets/js/admin/admin-dark-mode-toggle.js')
            : (defined('SBDP_VER') ? SBDP_VER : null);
        wp_enqueue_style(
            'ddb-admin-design-tokens',
            SBDP_URL . 'assets/admin-design-tokens.css',
            [],
            $tokenVersion
        );
        wp_enqueue_script(
            'ddb-admin-dark-mode-toggle',
            SBDP_URL . 'assets/js/admin/admin-dark-mode-toggle.js',
            [],
            $toggleVersion,
            false  // load in <head> so theme applies before body renders
        );

        if ('sbdp_bookings_page_sbdp_governance' === $hook || 'sbdp_bookings_page_sbdp_design_backend' === $hook) {
            wp_enqueue_style('sbdp-admin-governance', SBDP_URL . 'assets/admin-governance.css', array(), SBDP_VER);
            return;
        }

        if ('toplevel_page_sbdp_bookings' === $hook) {
            $bootstrap = AdminMenu::get_dashboard_bootstrap();
            $rest_base = esc_url_raw(rest_url('sbdp/v1/dashboard'));

            wp_enqueue_style(self::FRONT_HANDLE_STYLE);
            wp_enqueue_style('sbdp-admin-dashboard', SBDP_URL . 'assets/admin-dashboard.css', [], SBDP_VER);
            wp_enqueue_script(self::FRONT_HANDLE_VENDOR);
            wp_enqueue_script(
                'sbdp-admin-dashboard',
                SBDP_URL . 'assets/admin-dashboard.js',
                ['wp-element', 'wp-i18n', self::FRONT_HANDLE_VENDOR],
                SBDP_VER,
                true
            );

            wp_localize_script(
                'sbdp-admin-dashboard',
                'SBDP_ADMIN_DASHBOARD',
                [
                    'nonce'                => wp_create_nonce('wp_rest'),
                    'restBase'             => $rest_base,
                    'metricsEndpoint'      => $rest_base . '/metrics',
                    'availabilityEndpoint' => $rest_base . '/availability',
                    'exportUrl'            => esc_url_raw(rest_url('sbdp/v1/dashboard/export')),
                    'initialMetrics'       => $bootstrap['metrics'] ?? [],
                    'quickLinks'           => $bootstrap['quickLinks'] ?? [],
                    'availabilityWindow'   => $bootstrap['availabilityWindow'] ?? 14,
                    'plannerPageUrl'       => $bootstrap['plannerPageUrl'] ?? '',
                    'i18n'                 => [
                        'refresh'          => __('Vernieuwen', 'sbdp'),
                        'export'           => __('Exporteren', 'sbdp'),
                        'filter7d'         => __('Laatste 7 dagen', 'sbdp'),
                        'filter30d'        => __('Laatste 30 dagen', 'sbdp'),
                        'noData'           => __('Geen gegevens beschikbaar voor de gekozen periode.', 'sbdp'),
                        'calendarTitle'    => __('Beschikbaarheidskalender', 'sbdp'),
                        'metricsTitle'     => __('Kerncijfers', 'sbdp'),
                        'channelsTitle'    => __('Kanaalprestaties', 'sbdp'),
                        'pipelineTitle'    => __('Pipeline vooruitblik', 'sbdp'),
                        'pendingApprovals' => __('In afwachting van goedkeuring', 'sbdp'),
                        'bookingsLabel'    => __('Boekingen', 'sbdp'),
                        'revenueLabel'     => __('Omzet', 'sbdp'),
                        'currencyMixed'    => __('Meerdere valuta', 'sbdp'),
                        'calendarLoadError'=> __('Beschikbaarheid kan niet worden geladen.', 'sbdp'),
                    ],
                ]
            );
        }

        if (in_array($hook, ['sbdp_bookings_page_sbdp_availability', 'sbdp_bookings_page_sbdp_pricing'], true)) {
            wp_enqueue_script(self::FRONT_HANDLE_VENDOR);
            wp_enqueue_style('sbdp-admin-availability', SBDP_URL . 'assets/admin-availability.css', [], SBDP_VER);
            wp_enqueue_script(
                'sbdp-admin-visual-editors',
                SBDP_URL . 'assets/admin-visual-editors.js',
                [self::FRONT_HANDLE_VENDOR, 'jquery', 'wp-i18n'],
                SBDP_VER,
                true
            );
            wp_localize_script(
                'sbdp-admin-visual-editors',
                'SBDP_ADMIN_AV',
                [
                    'api_base'            => esc_url_raw(rest_url('sbdp/v1/availability')),
                    'publish_endpoint'   => esc_url_raw(rest_url('sbdp/v1/availability/rules')),
                    'services_endpoint'  => esc_url_raw(rest_url('sbdp/v1/services')),
                    'resources_endpoint' => esc_url_raw(rest_url('sbdp/v1/resources')),
                    'pricing_base'       => esc_url_raw(rest_url('sbdp/v1/pricing')),
                    'bookable_meta_base' => esc_url_raw(rest_url('sbdp/v1/bookable-meta')),
                    'nonce'              => wp_create_nonce('wp_rest'),
                ]
            );
        }

        if (false !== strpos($hook, 'sbdp_scheduler')) {
            $scriptVersion = defined('SBDP_VER') ? SBDP_VER : null;
            $styleVersion  = $scriptVersion;
            if (defined('SBDP_DIR') && function_exists('filemtime')) {
                $scriptPath = SBDP_DIR . 'assets/admin-scheduler.js';
                $stylePath  = SBDP_DIR . 'assets/admin-scheduler.css';
                if (is_string($scriptPath) && file_exists($scriptPath)) {
                    $scriptVersion = (string) filemtime($scriptPath);
                }
                if (is_string($stylePath) && file_exists($stylePath)) {
                    $styleVersion = (string) filemtime($stylePath);
                }
            }

            wp_enqueue_style('sbdp-admin-scheduler', SBDP_URL . 'assets/admin-scheduler.css', [], $styleVersion);
            wp_enqueue_script(
                'sbdp-admin-scheduler',
                SBDP_URL . 'assets/admin-scheduler.js',
                ['wp-i18n'],
                $scriptVersion,
                true
            );
            wp_localize_script(
                'sbdp-admin-scheduler',
                'SBDP_ADMIN_SCHEDULER',
                [
                    'endpoint' => esc_url_raw(rest_url('sbdp/v1/schedule/overview')),
                    'nonce'    => wp_create_nonce('wp_rest'),
                    'v2'       => [
                        'snapshot' => esc_url_raw(rest_url('bsp/v2/planboard/snapshot')),
                        'create'   => esc_url_raw(rest_url('bsp/v2/planboard/bookings')),
                        'move'     => esc_url_raw(rest_url('bsp/v2/planboard/bookings/move')),
                        'checkin'  => esc_url_raw(rest_url('bsp/v2/planboard/bookings/checkin')),
                        'payment'  => esc_url_raw(rest_url('bsp/v2/planboard/bookings/payment')),
                        'closures' => esc_url_raw(rest_url('bsp/v2/planboard/closures')),
                        'products' => esc_url_raw(rest_url('bsp/v2/planboard/products')),
                        'pricing'  => esc_url_raw(rest_url('bsp/v2/planboard/pricing/preview')),
                    ],
                ]
            );
        }
    }

    private static function enqueue_front_assets(): void
    {
        wp_enqueue_style(self::FRONT_HANDLE_STYLE);
        wp_enqueue_script(self::FRONT_HANDLE_VENDOR);
        wp_enqueue_script(self::FRONT_HANDLE_SCRIPT);
        self::add_defer(self::FRONT_HANDLE_VENDOR);
        self::add_defer(self::FRONT_HANDLE_SCRIPT);

        $config = self::get_frontend_config();
        if (! empty($config)) {
            wp_localize_script(self::FRONT_HANDLE_SCRIPT, 'SBDP_CFG', $config);
        }
    }

    /**
     * Add defer to a registered script handle if possible.
     */
    private static function add_defer(string $handle): void
    {
        add_filter('script_loader_tag', function($tag, $h) use ($handle) {
            if ($h === $handle) {
                return str_replace('<script', '<script defer', $tag);
            }
            return $tag;
        }, 10, 2);
    }

    /**
     */
    private static function add_force_participants_inline(): void
    {
        $force_participants_js = "(function(){\n" .
            "  function forceParticipants(){\n" .
            "    var input=document.querySelector('[name=\"sbdp_participants\"]');\n" .
            "    if(!input){return;}\n" .
            "    var force='2';\n" .
            "    input.disabled=false;\n" .
            "    input.min=force; input.max=force; input.value=force;\n" .
            "    var ev=new Event('change',{bubbles:true});\n" .
            "    input.dispatchEvent(ev);\n" .
            "  }\n" .
            "  if(document.readyState==='complete' || document.readyState==='interactive'){\n" .
            "    setTimeout(forceParticipants,100);\n" .
            "  } else {\n" .
            "    document.addEventListener('DOMContentLoaded',function(){ setTimeout(forceParticipants,100); });\n" .
            "  }\n" .
            "})();";
        wp_add_inline_script(self::PRODUCT_HANDLE_SCRIPT, $force_participants_js, 'after');
    }

    private static function should_enqueue_legacy_planner(): bool
    {
        if (is_admin()) {
            return false;
        }

        if (self::is_elementor_preview()) {
            return true;
        }

        $post = get_post();
        if (! $post instanceof \WP_Post) {
            return false;
        }

        return false;
    }

    private static function get_frontend_config(): array
    {
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';

        $config = [
            'services'        => esc_url_raw(rest_url('sbdp/v1/services')),
            'resources'       => esc_url_raw(rest_url('sbdp/v1/resources')),
            'availability'    => esc_url_raw(rest_url('sbdp/v1/availability/plan')),
            'pricing_preview' => esc_url_raw(rest_url('sbdp/v1/pricing/preview')),
            'compose'         => esc_url_raw(rest_url('sbdp/v1/compose_booking')),
            'nonce'           => wp_create_nonce('wp_rest'),
            'public_nonce'    => wp_create_nonce(PlannerRestService::PUBLIC_NONCE_ACTION),
            'currency'        => $currency,
            'locale'          => get_locale(),
            'i18n'            => self::get_i18n_strings(),
            'bundle_endpoint' => esc_url_raw(rest_url('sbdp/v1/planner/bundles')),
            'bundles'         => apply_filters('sbdp_planner_bundles', array()),
        ];

        return (array) apply_filters('sbdp_frontend_config', $config, get_post());
    }

    private static function get_i18n_strings(): array
    {
        return [
            'participants'               => __('deelnemers', 'sbdp'),
            'participant_single'         => __('deelnemer', 'sbdp'),
            'total'                      => __('Totaal', 'sbdp'),
            'pick_date'                  => __('Kies eerst een datum', 'sbdp'),
            'remove_item'                => __('Verwijder "%s" uit de planner?', 'sbdp'),
            'no_items'                   => __('Geen items geselecteerd', 'sbdp'),
            'generic_error'              => __('Er ging iets mis. Probeer het opnieuw.', 'sbdp'),
            'success'                    => __('Je programma is opgeslagen.', 'sbdp'),
            'clamped'                    => __('De activiteit is aangepast naar de gekozen datum. Controleer de tijden.', 'sbdp'),
            'add_to_plan'                => __('Toevoegen aan planner', 'sbdp'),
            'no_availability'            => __('Geen beschikbaar tijdslot gevonden voor deze dag. Kies een andere datum of pas de regels aan.', 'sbdp'),
            'conflict'                   => __('Valt buiten de beschikbaarheid', 'sbdp'),
            'capacity_warning'           => __('Aantal deelnemers hoger dan de capaciteit.', 'sbdp'),
            'slot_conflict'              => __('Het gekozen tijdslot botst met de beschikbaarheidsregels.', 'sbdp'),
            'no_date_selected'           => __('Kies eerst een datum om een activiteit te plannen.', 'sbdp'),
            'loading'                    => __('Bezig met laden...', 'sbdp'),
            'add_first_service'          => __('Sleep of voeg een activiteit toe aan de planner.', 'sbdp'),
            'per_booking_label'          => __('per boeking', 'sbdp'),
            'per_participant_label'      => __('per deelnemer', 'sbdp'),
            'filter_search_placeholder'  => __('Zoek op naam of omschrijving', 'sbdp'),
            'toast_added'                => __('%s toegevoegd aan je planning', 'sbdp'),
            'share_title'                => __('Mijn dagje Den Bosch', 'sbdp'),
            'share_intro'                => __('Bekijk mijn planning voor', 'sbdp'),
            'share_success'              => __('Planning gekopieerd naar klembord.', 'sbdp'),
            'share_error'                => __('Delen is mislukt. Probeer opnieuw.', 'sbdp'),
            'bundles_heading'           => __('Aanbevolen arrangementen', 'sbdp'),
            'bundles_intro'             => __('Kies een samengesteld programma als startpunt.', 'sbdp'),
            'bundle_apply'              => __('Gebruik arrangement', 'sbdp'),
            'bundle_empty'              => __('Er zijn momenteel geen arrangementen beschikbaar.', 'sbdp'),
            'bundle_items_label'        => __('Inclusief', 'sbdp'),
            'bundle_vendor_label'       => __('Aanbieder', 'sbdp'),
            'bundle_channel_label'      => __('Kanaal', 'sbdp'),
            'bundle_placeholder'        => __('Arrangementselectie wordt binnenkort geactiveerd.', 'sbdp'),
            'offline_mode'                => __('Offline modus gedetecteerd. We versturen je verzoek zodra je weer online bent.', 'sbdp'),
            'offline_queued'              => __('Geen verbinding. Je verzoek staat in de wachtrij.', 'sbdp'),
            'offline_saved'               => __('Verzoek opgeslagen voor verzending zodra je weer online bent.', 'sbdp'),
            'offline_flush_success'       => __('Wachtrij succesvol verzonden.', 'sbdp'),
            'offline_flush_partial'       => __('Sommige verzoeken konden niet worden verstuurd. Controleer je verbinding.', 'sbdp'),
            'offline_storage_failed'      => __('Offline wachtrij niet beschikbaar in deze browser.', 'sbdp'),
            'offline_back_online'         => __('Verbinding hersteld. We werken je planner bij.', 'sbdp'),
        ];
    }

    private static function is_elementor_preview(): bool
    {
        if (is_admin()) {
            return false;
        }

		if ( filter_has_var( INPUT_GET, 'elementor-preview' ) ) {
			return true;
		}

		if ( filter_has_var( INPUT_GET, 'elementor_library' ) || filter_has_var( INPUT_GET, 'elementor-library' ) ) {
			return true;
		}

        if (class_exists('\\Elementor\\Plugin')) {
            $plugin = \Elementor\Plugin::$instance;
            if ($plugin && method_exists($plugin, 'editor') && $plugin->editor) {
                if (method_exists($plugin->editor, 'is_edit_mode') && $plugin->editor->is_edit_mode()) {
                    return true;
                }
            }
            if ($plugin && isset($plugin->preview) && method_exists($plugin->preview, 'is_preview_mode') && $plugin->preview->is_preview_mode()) {
                return true;
            }
        }

        return false;
    }

    private static function should_enqueue_legacy_theme_toggle(): bool
    {
        return (bool) apply_filters('sbdp_enable_legacy_theme_toggle', false);
    }

    private static function should_enqueue_commercial_flow(): bool
    {
        if (function_exists('is_cart') && is_cart()) {
            return true;
        }

        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }

        if (function_exists('is_account_page') && is_account_page()) {
            return true;
        }

        if (! function_exists('is_singular') || ! is_singular()) {
            return false;
        }

        $post_id = get_queried_object_id();
        if (! is_int($post_id) || $post_id <= 0) {
            return false;
        }

        return self::document_contains_shortcode_reference($post_id, 'sbdp_offerte_aanvragen');
    }

    private static function get_current_elementor_document_id(): int
    {
        $keys = ['post', 'post_id', 'elementor-preview', 'preview_id'];

        foreach ($keys as $key) {
            $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
            if (is_int($value) && $value > 0) {
                return $value;
            }

            if (isset($_REQUEST[$key])) {
                $fallback = absint(wp_unslash((string) $_REQUEST[$key]));
                if ($fallback > 0) {
                    return $fallback;
                }
            }
        }

        return 0;
    }

    private static function should_enqueue_elementor_planner_style(int $document_id): bool
    {
        return false;
    }

    private static function should_enqueue_elementor_product_style(int $document_id): bool
    {
        $post = get_post($document_id);
        if ($post instanceof \WP_Post && $post->post_type === 'product') {
            return true;
        }

        $template_type = get_post_meta($document_id, '_elementor_template_type', true);
        if (is_string($template_type) && $template_type === 'product') {
            return true;
        }

        return self::document_contains_shortcode_reference($document_id, 'sbdp_product_planner');
    }

    private static function document_contains_shortcode_reference(int $document_id, string $shortcode): bool
    {
        $post = get_post($document_id);
        if ($post instanceof \WP_Post && has_shortcode((string) $post->post_content, $shortcode)) {
            return true;
        }

        return self::elementor_document_contains_shortcode($document_id, $shortcode);
    }

    private static function elementor_document_contains_shortcode($post_id, $shortcode): bool
    {
        if (! $post_id) {
            return false;
        }

        $raw_data = get_post_meta($post_id, '_elementor_data', true);
        if (empty($raw_data)) {
            return false;
        }

        if (is_string($raw_data) && strpos($raw_data, $shortcode) !== false) {
            return true;
        }

        $data = is_string($raw_data) ? json_decode($raw_data, true) : $raw_data;
        if (empty($data) || ! is_array($data)) {
            return false;
        }

        return self::search_elementor_nodes_for_shortcode($data, $shortcode);
    }

    private static function search_elementor_nodes_for_shortcode($nodes, $shortcode): bool
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (isset($node['widgetType'])) {
                $widget_type = $node['widgetType'];
                if ('shortcode' === $widget_type) {
                    $content = $node['settings']['shortcode'] ?? '';
                    if (is_string($content) && strpos($content, $shortcode) !== false) {
                        return true;
                    }
                }

                if ('sbdp_dayplanner' === $widget_type) {
                    return true;
                }
            }

            if (! empty($node['elements']) && self::search_elementor_nodes_for_shortcode($node['elements'], $shortcode)) {
                return true;
            }
        }

        return false;
    }
}
