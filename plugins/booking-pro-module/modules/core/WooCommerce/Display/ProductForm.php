<?php

declare(strict_types=1);

namespace BSPModule\Core\WooCommerce\Display;

use BSPModule\Core\Assets\EnqueueService;
use BSPModule\Core\Product\ProductMeta;
use BSPModule\Core\WooCommerce\ProductType\BookableServiceProductType;
use BSPModule\Core\Rest\RestService;
use SBDP\Pricing\SelectionPricing;
use WC_Product;
use WP_Post;

final class ProductForm
{
    private const FALLBACK_TIME = '09:00';

    private static bool $booted = false;

    private static bool $localized = false;

    private static ?bool $elementorTemplate = null;

    public static function init(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue_assets']);
        add_action('woocommerce_before_single_product', [__CLASS__, 'prepare_single_product']);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render'], 25);
        add_filter('body_class', [__CLASS__, 'filter_body_class']);
        add_filter('sbdp/product_form/combi_options', [__CLASS__, 'build_combi_options'], 10, 2);

        if (function_exists('add_filter')) {
            if (did_action('elementor/loaded')) {
                add_filter('elementor/widget/render_content', [__CLASS__, 'maybe_replace_elementor_widget'], 10, 2);
            } else {
                add_action(
                    'elementor/loaded',
                    static function (): void {
                        add_filter('elementor/widget/render_content', [__CLASS__, 'maybe_replace_elementor_widget'], 10, 2);
                    }
                );
            }
        }
    }

    public static function maybe_enqueue_assets(): void
    {
        $product = self::get_current_product();
        if (! self::is_target_product($product)) {
            return;
        }

        wp_enqueue_style(EnqueueService::PRODUCT_HANDLE_STYLE);

        if (! self::$localized) {
            $combi_options = self::build_combi_options([], $product);
            wp_localize_script(
                EnqueueService::PRODUCT_HANDLE_SCRIPT,
                'SBDP_ProductBooking',
                [
                    'compose'           => esc_url_raw(rest_url('sbdp/v1/compose_booking')),
                    'availability'      => esc_url_raw(rest_url('sbdp/v1/availability/plan')),
                    'nonce'             => wp_create_nonce(RestService::PUBLIC_NONCE_ACTION),
                    'fallback_redirect' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout'),
                    'planner_url'       => self::get_planner_url(),
                    'planner_route'     => '/planner/start',
                    'messages'          => [
                        'generic_error'   => __('Er ging iets mis. Probeer het opnieuw.', 'sbdp'),
                        'missing_fields'  => __('Vul datum, starttijd en aantal personen in.', 'sbdp'),
                        'planner_missing' => __('Plannerpagina niet gevonden.', 'sbdp'),
                        'redirecting'     => __('Bezig met doorsturen.', 'sbdp'),
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
                ]
            );

            self::$localized = true;
        }

        wp_enqueue_script(EnqueueService::PRODUCT_HANDLE_SCRIPT);
    }

    public static function prepare_single_product(): void
    {
        $product = self::get_current_product();
        if (! self::is_target_product($product)) {
            return;
        }

        if (self::uses_elementor_template()) {
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50);

            return;
        }

        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_title', 5);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50);
    }

    public static function render(): void
    {
        $product = self::get_current_product();
        if (! self::is_target_product($product)) {
            return;
        }

        if (self::uses_elementor_template()) {
            return;
        }

        self::render_form($product);
    }

    public static function filter_body_class(array $classes): array
    {
        if (! is_product() || ! function_exists('wc_get_product')) {
            return $classes;
        }

        $product = wc_get_product(get_queried_object_id());
        if ($product && $product->get_type() === BookableServiceProductType::PRODUCT_TYPE) {
            if (self::uses_elementor_template()) {
                $classes[] = 'sbdp-booking-layout-elementor';
            } else {
                $classes[] = 'sbdp-booking-layout-enabled';
            }
        }

        return $classes;
    }

    /**
     * @param array<int,array<string,mixed>> $options
     * @return array<int,array<string,mixed>>
     */
    public static function build_combi_options(array $options, WC_Product $product): array
    {
        if (! function_exists('wc_get_product')) {
            return $options;
        }

        $product_id = $product->get_id();
        $stored     = get_post_meta($product_id, '_sbdp_combi_deals', true);
        if (is_string($stored)) {
            $trimmed = trim($stored);
            if ($trimmed !== '' && $trimmed[0] === '[') {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $stored = $decoded;
                }
            } elseif (strpos($trimmed, ',') !== false) {
                $stored = array_map('trim', explode(',', $trimmed));
            }
        }

        $combi_ids = array_filter(
            array_map(
                static fn($value) => (int) $value,
                is_array($stored) ? $stored : (array) $stored
            ),
            static fn(int $value): bool => $value > 0
        );
        $combi_ids = array_values(array_unique($combi_ids));

        if (empty($combi_ids)) {
            return $options;
        }

        foreach ($combi_ids as $combi_id) {
            $related = \wc_get_product($combi_id);
            if (! $related instanceof WC_Product) {
                continue;
            }

            $duration_minutes = null;
            if (class_exists(\SBDP\Core\ProductSettings::class)) {
                try {
                    $settings = \SBDP\Core\ProductSettings::get($related->get_id());
                    $candidateDuration = (int) ($settings['duration_minutes'] ?? 0);
                    if ($candidateDuration > 0) {
                        $duration_minutes = $candidateDuration;
                    }
                } catch (\Throwable $exception) {
                    $duration_minutes = null;
                }
            } else {
                $raw_duration = (int) get_post_meta($related->get_id(), '_sbdp_duration', true);
                $duration_unit = (string) get_post_meta($related->get_id(), '_sbdp_duration_unit', true);
                $duration_unit = strtolower($duration_unit);
                if ($raw_duration > 0) {
                    if (in_array($duration_unit, ['hour', 'hours', 'uur', 'uren'], true)) {
                        $duration_minutes = $raw_duration * 60;
                    } elseif (in_array($duration_unit, ['day', 'days', 'dag', 'dagen'], true)) {
                        $duration_minutes = $raw_duration * 1440;
                    } else {
                        $duration_minutes = $raw_duration;
                    }
                }
            }

            $name = $related->get_name();
            $label = $name;
            $quote = SelectionPricing::quote(
                $related->get_id(),
                1,
                '',
                0,
                array(),
                array(
                    'channel'    => 'product_form_combi',
                    'source'     => 'build_combi_options',
                    'price_mode' => 'gross',
                )
            );
            $price_incl = isset($quote['display_unit_price']) ? (float) $quote['display_unit_price'] : (isset($quote['unit_price']) ? (float) $quote['unit_price'] : 0.0);
            if ($price_incl <= 0.0 && isset($quote['total'])) {
                $price_incl = (float) $quote['total'];
            }
            if ($price_incl <= 0.0) {
                $price_incl = function_exists('wc_get_price_including_tax')
                    ? (float) wc_get_price_including_tax($related, array('qty' => 1))
                    : (float) $related->get_price();
            }

            $supportsPersonsForOption = false;
            if (isset($quote['line_item']['pricing']) && is_array($quote['line_item']['pricing']) && array_key_exists('supports_persons', $quote['line_item']['pricing'])) {
                $supportsPersonsForOption = (bool) $quote['line_item']['pricing']['supports_persons'];
            } elseif (class_exists('\SBDP\Pricing\PricingService')) {
                try {
                    $pricingData = \SBDP\Pricing\PricingService::instance()->getProductPricing(
                        $related->get_id(),
                        array(
                            'channel'    => 'product_form_combi',
                            'source'     => 'build_combi_options',
                            'price_mode' => 'gross',
                        )
                    );
                    $supportsPersonsForOption = ! empty($pricingData['supports_persons']);
                } catch (\Throwable $exception) {
                    $supportsPersonsForOption = false;
                }
            }

            $adjustment = $price_incl;
            $price_label = function_exists('wc_price') ? wc_price($price_incl) : number_format_i18n($price_incl, 2);
            /* translators: %1$s product title, %2$s formatted price */
            $label = sprintf(__('%1$s - %2$s', 'sbdp'), $label, wp_strip_all_tags($price_label));
            $label = html_entity_decode($label, ENT_QUOTES, 'UTF-8');

            $image_url = '';
            if (function_exists('wp_get_attachment_image_url')) {
                $image_id = $related->get_image_id();
                if ($image_id) {
                    $image_url = (string) wp_get_attachment_image_url($image_id, 'thumbnail');
                }
            }

            $options[] = [
                'value'      => $related->get_id(),
                'label'      => $label,
                'adjustment' => $adjustment,
                'image'      => $image_url,
                'supportsPersons' => $supportsPersonsForOption,
                'name'       => html_entity_decode($name, ENT_QUOTES, 'UTF-8'),
                'duration'   => $duration_minutes,
            ];
        }

        return $options;
    }

    /**
     * Replace Elementor's add-to-cart widget output with the booking form markup.
     *
     * @param string $content
     * @param mixed  $widget
     *
     * @return string
     */
    public static function maybe_replace_elementor_widget($content, $widget)
    {
        if (! function_exists('is_product') || ! is_product()) {
            return is_string($content) ? $content : '';
        }

        $product = self::get_current_product();
        if (! self::is_target_product($product)) {
            return is_string($content) ? $content : '';
        }

        if (! is_object($widget) || ! method_exists($widget, 'get_name')) {
            return is_string($content) ? $content : '';
        }

        $name = strtolower((string) $widget->get_name());
        if ($name === '') {
            return is_string($content) ? $content : '';
        }

        $supported = array(
            'woocommerce-product-add-to-cart',
            'woocommerce-product-add_to_cart',
            'product-add-to-cart',
            'product-add_to_cart',
            'woocommerce-add-to-cart',
        );

        $is_supported = in_array($name, $supported, true)
            || (strpos($name, 'add-to-cart') !== false && strpos($name, 'product') !== false);

        if (! $is_supported) {
            return is_string($content) ? $content : '';
        }

        self::$elementorTemplate = true;

        $override = null;
        if (function_exists('apply_filters')) {
            $override = apply_filters('sbdp/product_form/elementor_render', null, $product, $widget);
        }

        if (is_string($override) && $override !== '') {
            return $override;
        }

        ob_start();
        self::render_form(
            $product,
            array(
                'show_hero'    => false,
                'show_planner' => false,
                'layout'       => 'elementor',
            )
        );
        $replacement = ob_get_clean();

        if (! is_string($replacement) || $replacement === '') {
            return is_string($content) ? $content : '';
        }

        return $replacement;
    }

    private static function uses_elementor_template(): bool
    {
        if (self::$elementorTemplate !== null) {
            return self::$elementorTemplate;
        }

        self::$elementorTemplate = false;

        if (! function_exists('is_product') || ! is_product()) {
            return false;
        }

        if (! class_exists('\Elementor\Plugin')) {
            return false;
        }

        $plugin = \Elementor\Plugin::$instance;
        if (! $plugin) {
            return false;
        }

        if (! isset($plugin->theme_builder)) {
            return false;
        }

        $theme_builder = $plugin->theme_builder;
        if (! $theme_builder || ! is_object($theme_builder)) {
            return false;
        }

        $document = null;

        if (method_exists($theme_builder, 'get_locations_manager')) {
            $locations_manager = $theme_builder->get_locations_manager();

            if ($locations_manager && is_object($locations_manager)) {
                if (method_exists($locations_manager, 'get_document_for_location')) {
                    $document = $locations_manager->get_document_for_location('single');

                    if (! $document) {
                        $document = $locations_manager->get_document_for_location('single-product');
                    }
                }

                if (! $document && method_exists($locations_manager, 'get_documents_for_location')) {
                    $documents = $locations_manager->get_documents_for_location('single');
                    if (is_array($documents) && ! empty($documents)) {
                        $document = reset($documents);
                    }
                }

                if (! $document && method_exists($locations_manager, 'has_location') && $locations_manager->has_location('single')) {
                    self::$elementorTemplate = true;

                    return true;
                }
            }
        }

        if ($document) {
            self::$elementorTemplate = true;

            return true;
        }

        if (filter_has_var(INPUT_GET, 'elementor-preview')
            && function_exists('elementor_theme_has_location')
            && elementor_theme_has_location('single')
        ) {
            self::$elementorTemplate = true;

            return true;
        }

        return false;
    }

    private static function render_form(WC_Product $product, array $options = array()): void
    {
        $defaults = array(
            'show_hero'    => true,
            'show_planner' => true,
            'layout'       => 'standard',
        );

        $options = array_merge($defaults, $options);

        $layout = isset($options['layout']) && is_string($options['layout'])
            ? strtolower($options['layout'])
            : 'standard';
        $showHero = ! empty($options['show_hero']);
        $showPlanner = ! empty($options['show_planner']);

        if ($layout === 'elementor') {
            $showHero    = false;
            $showPlanner = false;
        }

        if (! $showHero) {
            $showPlanner = false;
        }

        $container_classes = array('sbdp-booking-shell');
        if ($layout === 'elementor') {
            $container_classes[] = 'sbdp-booking-shell--elementor';
        } elseif (! $showHero) {
            $container_classes[] = 'sbdp-booking-shell--compact';
        }

        $elementorUpsells = array();
        $elementorFaq = array();

        if ($layout === 'elementor' && function_exists('apply_filters')) {
            $upsellRaw = apply_filters('sbdp/product_form/elementor_upsell', array(), $product);
            $faqRaw    = apply_filters('sbdp/product_form/elementor_faq', array(), $product);

            $elementorUpsells = self::normalize_elementor_upsells($upsellRaw);
            $elementorFaq     = self::normalize_elementor_faq($faqRaw);
        }

        $product_id   = $product->get_id();
        $today        = function_exists('wp_date') ? wp_date('Y-m-d') : gmdate('Y-m-d');
        $default_date = (string) get_post_meta($product_id, '_sbdp_default_start_date', true);
        $default_time = (string) get_post_meta($product_id, '_sbdp_default_start_time', true);
        $duration     = (int) get_post_meta($product_id, '_sbdp_duration', true);
        $min_people   = (int) get_post_meta($product_id, '_sbdp_min_people', true);
        $max_people   = (int) get_post_meta($product_id, '_sbdp_max_people', true);

        if ($default_date === '') {
            $default_date = $today;
        }

        $default_time = self::normalize_time($default_time);
        if ($default_time === '') {
            $default_time = '';
        }

        $initial_month = substr($default_date, 0, 7);
        if ($initial_month === '' || ! preg_match('/^\d{4}-\d{2}$/', $initial_month)) {
            $initial_month = substr($today, 0, 7);
        }

        $weekday_labels = [
            ['short' => __('Ma', 'sbdp'), 'full' => __('Maandag', 'sbdp')],
            ['short' => __('Di', 'sbdp'), 'full' => __('Dinsdag', 'sbdp')],
            ['short' => __('Wo', 'sbdp'), 'full' => __('Woensdag', 'sbdp')],
            ['short' => __('Do', 'sbdp'), 'full' => __('Donderdag', 'sbdp')],
            ['short' => __('Vr', 'sbdp'), 'full' => __('Vrijdag', 'sbdp')],
            ['short' => __('Za', 'sbdp'), 'full' => __('Zaterdag', 'sbdp')],
            ['short' => __('Zo', 'sbdp'), 'full' => __('Zondag', 'sbdp')],
        ];

        if ($duration <= 0) {
            $duration = 0;
        }

        if ($min_people <= 0) {
            $min_people = 1;
        }

        $default_people = $min_people > 0 ? $min_people : 1;
        if ($max_people > 0 && $default_people > $max_people) {
            $default_people = $max_people;
        }

        $currency     = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';
        $currency_sym = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol($currency) : '€';

        $labels     = ProductMeta::get_frontend_labels($product_id);
        $resources  = ProductMeta::get_resources_payload($product_id);
        $capacity   = self::format_capacity_notice($resources);
        $stock_html = function_exists('wc_get_stock_html') ? wc_get_stock_html($product) : '';
        $intro      = apply_filters('sbdp/product_form/intro_text', $product->get_short_description(), $product);
        $combi_options = apply_filters('sbdp/product_form/combi_options', [], $product);

        $categories = [];
        $terms      = get_the_terms($product_id, 'product_cat');
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof \WP_Term) {
                    $name = trim((string) $term->name);
                    if ($name !== '') {
                        $categories[] = $name;
                    }
                }
            }
        }
        $categories     = array_slice(array_values(array_unique($categories)), 0, 3);
        $duration_label = self::format_duration_label($duration);
        $people_label   = self::format_people_label($min_people, $max_people);
        $price_from     = self::format_price_from($product, $currency);

        $average_rating = function_exists('wc_clean') ? (float) wc_clean((string) $product->get_average_rating()) : (float) $product->get_average_rating();
        $review_count   = (int) $product->get_review_count();
        $rating_html    = '';
        $review_label   = '';
        if ($average_rating > 0 && function_exists('wc_get_rating_html')) {
            $rating_html = wc_get_rating_html($average_rating, $review_count);
            if ($review_count > 0) {
                /* translators: %d: number of reviews */
                $review_label = sprintf(_n('%d review', '%d reviews', $review_count, 'sbdp'), $review_count);
            }
        }

        $intro_text = self::trim_intro_text($intro);
        if ($intro_text === '') {
            $intro_text = self::trim_intro_text($product->get_description());
        }

        $description_sections = self::build_description_sections($product, $intro_text);
        $intro_text           = $description_sections['intro'];
        $feature_points       = $description_sections['features'];
        $reason_points        = $description_sections['reasons'];

        $experience_blueprint         = self::build_experience_blueprint($product, $feature_points, $reason_points);
        $experience_highlights        = $experience_blueprint['highlights'];
        $experience_timeline          = $experience_blueprint['timeline'];
        $experience_included          = $experience_blueprint['included'];
        $experience_excluded          = $experience_blueprint['excluded'];
        $experience_good_to_know      = $experience_blueprint['good_to_know'];
        $trust_badges                 = $experience_blueprint['trust'];
        $experience_headings          = $experience_blueprint['headings'];

        $planner_features = [
            __('Kalender met drag & drop', 'sbdp'),
            __('Kaart met reistijd en route', 'sbdp'),
            __('Deel of exporteer naar PDF/ICS', 'sbdp'),
            __('Combineer meerdere activiteiten in één boeking', 'sbdp'),
        ];

        $planner_settings = get_option('sbdp_day_planner_settings', []);
        $time_step = isset($planner_settings['time_step_minutes']) ? (int) $planner_settings['time_step_minutes'] : 15;
        if ($time_step <= 0) {
            $time_step = 15;
        }
        $open_start = '';
        $open_end   = '';
        if (isset($planner_settings['open_hours']) && is_array($planner_settings['open_hours'])) {
            $open_start = self::normalize_time((string) ($planner_settings['open_hours']['start'] ?? ''));
            $open_end   = self::normalize_time((string) ($planner_settings['open_hours']['end'] ?? ''));
        }
        if ($open_start === '') {
            $open_start = '08:00';
        }
        if ($open_end === '' || strcmp($open_end, $open_start) <= 0) {
            $open_end = '22:00';
        }

        $effective_base_price  = 0.0;
        $per_person_price_gross = 0.0;
        $fixed_fee_gross       = 0.0;

        if (class_exists('\SBDP\Pricing\PricingService')) {
            $pricing_data = \SBDP\Pricing\PricingService::instance()->getProductPricing($product_id, [
                'channel'    => 'product_form',
                'price_mode' => 'gross'
            ]);
            $effective_base_price   = (float) ($pricing_data['base_price'] ?? 0.0);
            $per_person_price_gross  = (float) ($pricing_data['per_person'] ?? 0.0);
            $fixed_fee_gross         = (float) ($pricing_data['fixed_fee'] ?? 0.0);

            if ($effective_base_price <= 0.0) {
                if ($per_person_price_gross > 0.0) {
                    $effective_base_price = $per_person_price_gross;
                } elseif ($fixed_fee_gross > 0.0) {
                    $effective_base_price = $fixed_fee_gross;
                }
            }
        }

        if ($effective_base_price <= 0.0) {
            $effective_base_price = function_exists('wc_get_price_including_tax')
                ? (float) wc_get_price_including_tax($product, array('qty' => 1))
                : (float) $product->get_price();
        }

        $config = [
            'productId'      => $product_id,
            'productName'    => $product->get_name(),
            'categoryLabels' => $categories,
            'defaults'       => [
                'date'         => $default_date,
                'time'         => $default_time,
                'participants' => $default_people,
            ],
            'limits'         => [
                'min' => $min_people,
                'max' => $max_people > 0 ? $max_people : null,
            ],
            'duration'       => $duration,
            'durationLabel'  => $duration_label,
            'peopleLabel'    => $people_label,
            'priceFrom'      => wp_strip_all_tags($price_from),
            'averageRating'  => $average_rating,
            'reviewCount'    => $review_count,
            'timeStep'       => $time_step,
            'labels'         => $labels,
            'resources'      => $resources,
            'today'          => $today,
            'plannerUrl'     => self::get_planner_url(),
            'openHours'      => [
                'start' => $open_start,
                'end'   => $open_end,
            ],
            'currency'       => $currency,
            'currencySym'    => $currency_sym,
            'basePrice'      => $effective_base_price,
            'perPersonPrice' => $per_person_price_gross,
            'fixedFee'       => $fixed_fee_gross,
            'supportsPersons'=> $people_enabled,
            'locale'         => get_locale(),
            'intro'          => $intro_text,
            'plannerFeatures'=> $planner_features,
            'combiOptions'   => $combi_options,
        ];

        $config_json = wp_json_encode($config);
        if (! is_string($config_json)) {
            return;
        }

        $max_attribute = $max_people > 0 ? ' max="' . esc_attr((string) $max_people) . '"' : '';
        ?>
        <section class="<?php echo esc_attr(implode(' ', $container_classes)); ?>" data-sbdp-product-form data-sbdp-config="<?php echo esc_attr($config_json); ?>">
            <?php if (! $showHero) : ?>
            <header class="sbdp-product-shell__heading">
                <h1 class="sbdp-product-shell__title"><?php echo esc_html($product->get_name()); ?></h1>
            </header>
            <?php if ($layout === 'elementor') : ?>
            <div class="sbdp-elementor-status">
                <div class="sbdp-elementor-status__row">
                    <span class="sbdp-elementor-status__badge" aria-hidden="true"></span>
                </div>
                <p class="sbdp-elementor-status__message">
                    <?php esc_html_e('Open de planner om deze activiteit slim in je dag te plaatsen.', 'sbdp'); ?>
                </p>
                <div class="sbdp-elementor-status__actions">
                    <button type="button" class="sbdp-button sbdp-button--ghost" data-sbdp-action="plan">
                        <?php esc_html_e('Plan mijn dag', 'sbdp'); ?>
                    </button>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($showHero) : ?>
            <header class="sbdp-product-hero">
                <div class="sbdp-product-hero__core">
                    <?php if (! empty($categories)) : ?>
                        <ul class="sbdp-product-hero__categories">
                            <?php foreach ($categories as $category_label) : ?>
                                <li class="sbdp-product-hero__category"><?php echo esc_html($category_label); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <h1 class="sbdp-product-hero__title"><?php echo esc_html($product->get_name()); ?></h1>
                    <div class="sbdp-product-hero__meta">
                        <?php if ($rating_html !== '') : ?>
                            <span class="sbdp-product-hero__meta-item sbdp-product-hero__meta-item--rating">
                                <?php echo wp_kses_post($rating_html); ?>
                                <?php if ($review_label !== '') : ?>
                                    <span class="sbdp-product-hero__meta-note"><?php echo esc_html($review_label); ?></span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($duration_label !== '') : ?>
                            <span class="sbdp-product-hero__meta-item">
                                <?php echo esc_html(sprintf(__('Duur %s', 'sbdp'), $duration_label)); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($people_label !== '') : ?>
                            <span class="sbdp-product-hero__meta-item">
                                <?php echo esc_html($people_label); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($price_from !== '') : ?>
                            <span class="sbdp-product-hero__meta-item">
                                <?php
                                /* translators: %s: formatted price */
                                echo wp_kses_post(sprintf(__('Vanaf %s', 'sbdp'), $price_from));
                                ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($capacity !== '') : ?>
                        <p class="sbdp-product-hero__capacity"><?php echo esc_html($capacity); ?></p>
                    <?php endif; ?>
                    <?php if ($stock_html) : ?>
                        <div class="sbdp-product-hero__stock"><?php echo wp_kses_post($stock_html); ?></div>
                    <?php endif; ?>
                    <?php if ($intro_text !== '') : ?>
                        <p class="sbdp-product-hero__excerpt"><?php echo esc_html($intro_text); ?></p>
                    <?php endif; ?>
                    <?php if (! empty($feature_points) || ! empty($reason_points)) : ?>
                        <div class="sbdp-product-hero__details">
                            <?php if (! empty($feature_points)) : ?>
                                <h3 class="sbdp-product-hero__details-title"><?php esc_html_e('Wat je krijgt', 'sbdp'); ?></h3>
                                <ul class="sbdp-product-hero__details-list">
                                    <?php foreach ($feature_points as $feature) : ?>
                                        <li><?php echo esc_html($feature); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if (! empty($reason_points)) : ?>
                                <h3 class="sbdp-product-hero__details-title"><?php esc_html_e('Waarom het leuk is', 'sbdp'); ?></h3>
                                <ul class="sbdp-product-hero__details-list">
                                    <?php foreach ($reason_points as $reason) : ?>
                                        <li><?php echo esc_html($reason); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </header>
            <?php endif; ?>

            <section class="sbdp-product-layout">
                <div class="sbdp-product-layout__main">
                    <?php if ($experience_highlights !== array()) : ?>
                    <section class="sbdp-experience sbdp-experience--highlights">
                        <div class="sbdp-experience__header">
                            <h2><?php echo esc_html($experience_headings['highlights']); ?></h2>
                            <?php if ($experience_headings['highlights_sub'] !== '') : ?>
                                <p><?php echo esc_html($experience_headings['highlights_sub']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="sbdp-experience__grid">
                            <?php foreach ($experience_highlights as $highlight) : ?>
                                <article class="sbdp-experience-card">
                                    <?php if ($highlight['icon'] !== '') : ?>
                                        <span class="sbdp-experience-card__icon" aria-hidden="true"><?php echo esc_html($highlight['icon']); ?></span>
                                    <?php endif; ?>
                                    <h3 class="sbdp-experience-card__title"><?php echo esc_html($highlight['title']); ?></h3>
                                    <?php if ($highlight['description'] !== '') : ?>
                                        <p class="sbdp-experience-card__description"><?php echo esc_html($highlight['description']); ?></p>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if ($experience_included !== array() || $experience_excluded !== array() || $experience_good_to_know !== array()) : ?>
                    <div class="sbdp-experience sbdp-experience--lists">
                        <?php if ($experience_included !== array()) : ?>
                            <div class="sbdp-experience-card sbdp-experience-card--list">
                                <h3 class="sbdp-experience-card__title"><?php esc_html_e('Inbegrepen', 'sbdp'); ?></h3>
                                <ul class="sbdp-checklist">
                                    <?php foreach ($experience_included as $item) : ?>
                                        <li><?php echo esc_html($item); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <?php if ($experience_excluded !== array()) : ?>
                            <div class="sbdp-experience-card sbdp-experience-card--list sbdp-experience-card--alert">
                                <h3 class="sbdp-experience-card__title"><?php esc_html_e('Niet inbegrepen', 'sbdp'); ?></h3>
                                <ul class="sbdp-checklist sbdp-checklist--alert">
                                    <?php foreach ($experience_excluded as $item) : ?>
                                        <li><?php echo esc_html($item); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <?php if ($experience_good_to_know !== array()) : ?>
                            <div class="sbdp-experience-card sbdp-experience-card--list">
                                <h3 class="sbdp-experience-card__title"><?php esc_html_e('Handig om te weten', 'sbdp'); ?></h3>
                                <ul class="sbdp-checklist sbdp-checklist--info">
                                    <?php foreach ($experience_good_to_know as $item) : ?>
                                        <li><?php echo esc_html($item); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($trust_badges !== array()) : ?>
                    <section class="sbdp-experience sbdp-experience--trust">
                        <div class="sbdp-experience__header">
                            <h2><?php echo esc_html($experience_headings['trust']); ?></h2>
                            <?php if ($experience_headings['trust_sub'] !== '') : ?>
                                <p><?php echo esc_html($experience_headings['trust_sub']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="sbdp-trust">
                            <?php foreach ($trust_badges as $badge) : ?>
                                <div class="sbdp-trust__item">
                                    <?php if ($badge['icon'] !== '') : ?>
                                        <span class="sbdp-trust__icon" aria-hidden="true"><?php echo esc_html($badge['icon']); ?></span>
                                    <?php endif; ?>
                                    <div class="sbdp-trust__content">
                                        <h3 class="sbdp-trust__label"><?php echo esc_html($badge['label']); ?></h3>
                                        <?php if ($badge['description'] !== '') : ?>
                                            <p class="sbdp-trust__description"><?php echo esc_html($badge['description']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>
                </div>

                <aside class="sbdp-product-layout__aside">
                    <div class="sbdp-booking-shell__card" id="sbdp-availability-card">
                        <div class="sbdp-booking-shell__cta">
                            <button type="button" class="sbdp-booking-shell__cta-btn" data-sbdp-action="plan">
                                <?php esc_html_e('Plan je dag', 'sbdp'); ?>
                            </button>
                            <p class="sbdp-booking-shell__cta-copy">
                                <?php esc_html_e('Open de planner en neem deze activiteit direct mee in je dagplanning.', 'sbdp'); ?>
                            </p>
                        </div>
                        <div class="sbdp-booking-shell__grid">
                            <div class="sbdp-booking-shell__controls">
                    <?php $step = 1; ?>
                    <div class="sbdp-booking-step">
                        <span class="sbdp-booking-step__label"><?php echo esc_html((string) $step); ?>.</span>
                        <div class="sbdp-booking-step__content">
                            <h3><?php esc_html_e('Kies de datum', 'sbdp'); ?></h3>
                            <input
                                type="hidden"
                                id="sbdp-date"
                                name="sbdp_date"
                                value="<?php echo esc_attr($default_date); ?>"
                                data-sbdp-date-input
                                data-min-date="<?php echo esc_attr($today); ?>"
                            />
                            <div
                                class="sbdp-date-picker"
                                data-sbdp-calendar
                                data-sbdp-calendar-month="<?php echo esc_attr($initial_month); ?>"
                                aria-label="<?php esc_attr_e('Beschikbare dagen', 'sbdp'); ?>"
                            >
                                <div class="sbdp-date-picker__header">
                                    <button
                                        type="button"
                                        class="sbdp-date-picker__nav sbdp-date-picker__nav--prev"
                                        data-sbdp-calendar-prev
                                        aria-label="<?php esc_attr_e('Vorige maand', 'sbdp'); ?>"
                                    >
                                        <span aria-hidden="true">‹</span>
                                    </button>
                                    <p class="sbdp-date-picker__label" data-sbdp-calendar-label aria-live="polite"></p>
                                    <button
                                        type="button"
                                        class="sbdp-date-picker__nav sbdp-date-picker__nav--next"
                                        data-sbdp-calendar-next
                                        aria-label="<?php esc_attr_e('Volgende maand', 'sbdp'); ?>"
                                    >
                                        <span aria-hidden="true">›</span>
                                    </button>
                                </div>
                                <div class="sbdp-date-picker__weekdays" aria-hidden="true">
                                    <?php foreach ($weekday_labels as $weekday_label) : ?>
                                        <span class="sbdp-date-picker__weekday" title="<?php echo esc_attr($weekday_label['full']); ?>">
                                            <?php echo esc_html($weekday_label['short']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <div
                                    class="sbdp-date-picker__grid"
                                    role="grid"
                                    data-sbdp-calendar-grid
                                ></div>
                            </div>
                            <noscript>
                                <input type="date" name="sbdp_date" value="<?php echo esc_attr($default_date); ?>" min="<?php echo esc_attr($today); ?>" required />
                            </noscript>
                        </div>
                    </div>

                    <?php $step++; ?>
                    <div class="sbdp-booking-step">
                        <span class="sbdp-booking-step__label"><?php echo esc_html((string) $step); ?>.</span>
                        <div class="sbdp-booking-step__content">
                            <h3><?php esc_html_e('Resource', 'sbdp'); ?></h3>
                            <input
                                type="hidden"
                                name="sbdp_resource"
                                value="<?php echo esc_attr((string) ($resources[0]['id'] ?? 0)); ?>"
                                data-sbdp-resource-input
                            />
                            <div class="sbdp-time-picker">
                                <label class="sbdp-time-picker__label" for="sbdp-resource-select">
                                    <?php esc_html_e('Selecteer resource', 'sbdp'); ?>
                                </label>
                                <select
                                    id="sbdp-resource-select"
                                    class="sbdp-time-picker__select"
                                    data-sbdp-resource-select
                                >
                                    <?php foreach ($resources as $resource) :
                                        $resource_id = isset($resource['id']) ? (int) $resource['id'] : 0;
                                        $resource_label = isset($resource['title']) ? (string) $resource['title'] : '';
                                        if ($resource_id <= 0 || $resource_label === '') {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?php echo esc_attr((string) $resource_id); ?>">
                                            <?php echo esc_html($resource_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="sbdp-booking-step">
                        <?php if (! empty($resources)) : ?>
                            <?php $step++; ?>
                        <?php endif; ?>
                        <span class="sbdp-booking-step__label"><?php echo esc_html((string) $step); ?>.</span>
                        <div class="sbdp-booking-step__content">
                            <h3><?php esc_html_e('Kies de starttijd', 'sbdp'); ?></h3>
                            <input
                                type="hidden"
                                id="sbdp-time"
                                name="sbdp_time"
                                value="<?php echo esc_attr($default_time); ?>"
                                data-default-time="<?php echo esc_attr($default_time); ?>"
                                data-sbdp-time-input
                            />
                            <div class="sbdp-time-picker" data-sbdp-time-picker>
                                <label class="sbdp-time-picker__label" for="sbdp-time-select">
                                    <?php esc_html_e('Beschikbare starttijden', 'sbdp'); ?>
                                </label>
                                <select
                                    id="sbdp-time-select"
                                    class="sbdp-time-picker__select"
                                    data-sbdp-timeslot-list
                                    aria-describedby="sbdp-time-status"
                                >
                                    <option value=""><?php esc_html_e('Selecteer een starttijd', 'sbdp'); ?></option>
                                </select>
                                <p id="sbdp-time-status" class="sbdp-time-picker__status" data-sbdp-timeslot-empty>
                                    <?php esc_html_e('Selecteer eerst een datum om beschikbare tijden te zien.', 'sbdp'); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="sbdp-booking-step">
                        <?php $step++; ?>
                        <span class="sbdp-booking-step__label"><?php echo esc_html((string) $step); ?>.</span>
                        <div class="sbdp-booking-step__content">
                            <h3><?php esc_html_e('Kies aantal personen', 'sbdp'); ?></h3>
                            <select id="sbdp-participants" name="sbdp_participants" data-default-participants="<?php echo esc_attr((string) $default_people); ?>" required>
                                <option value=""><?php esc_html_e('Selecteer aantal personen', 'sbdp'); ?></option>
                                <option value="<?php echo esc_attr((string) $default_people); ?>" selected><?php echo esc_html((string) $default_people); ?></option>
                            </select>
                        </div>
                    </div>

                    <details class="sbdp-booking-step sbdp-booking-step--optional">
                        <?php $step++; ?>
                        <summary class="sbdp-booking-step__summary">
                            <span class="sbdp-booking-step__label"><?php echo esc_html((string) $step); ?>.</span>
                            <div class="sbdp-booking-step__content">
                                <h3><?php esc_html_e('Combinatie', 'sbdp'); ?></h3>
                                <p class="sbdp-booking-step__summary-copy"><?php esc_html_e('Voeg alleen iets toe als het je dag aantoonbaar sterker maakt.', 'sbdp'); ?></p>
                            </div>
                        </summary>
                        <div class="sbdp-booking-step__optional-grid">
                            <div class="sbdp-booking-step__optional-field">
                                <label class="sbdp-time-picker__label" for="sbdp-combi">
                                    <?php esc_html_e('Combi-deal', 'sbdp'); ?>
                                </label>
                                <select id="sbdp-combi" name="sbdp_combi">
                                    <?php if (! empty($combi_options)) : ?>
                                        <option value=""><?php esc_html_e('Maak een keuze', 'sbdp'); ?></option>
                                        <?php foreach ($combi_options as $option) :
                                            $value = isset($option['value']) ? (string) $option['value'] : '';
                                            $label = isset($option['label']) ? $option['label'] : $value;
                                            $image = isset($option['image']) ? (string) $option['image'] : '';
                                            $name_label = isset($option['name']) ? (string) $option['name'] : $label;
                                            $duration_min = isset($option['duration']) ? (int) $option['duration'] : 0;
                                            $image_attr = $image !== '' ? ' data-image="' . esc_attr($image) . '"' : '';
                                            $label_attr = $name_label !== '' ? ' data-label="' . esc_attr($name_label) . '"' : '';
                                            ?>
                                            <option value="<?php echo esc_attr($value); ?>" data-duration="<?php echo esc_attr((string)$duration_min); ?>" data-adjustment="<?php echo esc_attr($option['adjustment'] ?? ''); ?>"<?php echo $image_attr; ?><?php echo $label_attr; ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <option value=""><?php esc_html_e('Geen combinaties beschikbaar', 'sbdp'); ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                    </details>
                </div>

                <aside class="sbdp-planner-summary" aria-live="polite" data-sbdp-planner-card>
                    <div class="planner-summary" data-sbdp-summary>
                        <div class="planner-summary__header">
                            <div class="planner-summary__status">
                                <span
                                    class="planner-summary__badge"
                                    data-sbdp-planner-indicator
                                    hidden="hidden"
                                    aria-hidden="true"
                                ></span>
                                <p
                                    class="planner-summary__status-message"
                                    data-sbdp-planner-status
                                    aria-live="polite"
                                    hidden="hidden"
                                    aria-hidden="true"
                                ></p>
                            </div>
                            <h2 class="planner-summary__title"><?php esc_html_e('Jouw planning', 'sbdp'); ?></h2>
                            <p class="planner-summary__hint" data-summary-hint><?php esc_html_e('Kies datum, tijd en gezelschap om direct je actuele boekingssamenvatting te zien.', 'sbdp'); ?></p>
                        </div>
                        <dl class="planner-summary__details">
                            <div class="planner-summary__detail">
                                <dt><?php esc_html_e('Datum', 'sbdp'); ?></dt>
                                <dd data-summary-date><?php esc_html_e('Kies eerst een datum.', 'sbdp'); ?></dd>
                            </div>
                            <div class="planner-summary__detail">
                                <dt><?php esc_html_e('Starttijd', 'sbdp'); ?></dt>
                                <dd data-summary-time><?php esc_html_e('Kies daarna een starttijd.', 'sbdp'); ?></dd>
                            </div>
                            <div class="planner-summary__detail">
                                <dt><?php esc_html_e('Deelnemers', 'sbdp'); ?></dt>
                                <dd data-summary-people><?php esc_html_e('Kies het aantal deelnemers.', 'sbdp'); ?></dd>
                            </div>
                        </dl>
                        <div class="planner-summary__pricing">
                            <div class="planner-summary__price-line">
                                <span class="planner-summary__price-label"><?php esc_html_e('Subtotaal', 'sbdp'); ?></span>
                                <span class="planner-summary__price-value" data-sbdp-total><?php echo esc_html($currency_sym); ?>0,00</span>
                            </div>
                            <div class="planner-summary__price-line planner-summary__price-line--combi" data-summary-combi hidden>
                                <span class="planner-summary__price-label" data-summary-combi-label><?php esc_html_e('Combi-deal', 'sbdp'); ?></span>
                                <span class="planner-summary__price-value planner-summary__price-value--muted" data-summary-combi-value></span>
                            </div>
                        </div>
                        <div class="planner-summary__actions" data-booking-actions>
                            <button type="button" class="planner-summary__action planner-summary__action--primary" data-sbdp-action="book">
                                <?php esc_html_e('Boek nu', 'sbdp'); ?>
                            </button>
                            <button type="button" class="planner-summary__action planner-summary__action--secondary" data-sbdp-action="plan">
                                <?php esc_html_e('Plan je dag', 'sbdp'); ?>
                            </button>
                        </div>
                        <p class="planner-summary__feedback sbdp-product-booking__feedback" data-sbdp-feedback role="status" aria-live="polite"></p>
                    </div>
                </aside>
                    </div>
                    <?php if ($layout === 'elementor' && $elementorUpsells !== array()) : ?>
                    <div class="sbdp-elementor-upsell">
                        <h2 class="sbdp-elementor-upsell__heading"><?php esc_html_e('Aanbevolen uitbreidingen', 'sbdp'); ?></h2>
                        <ul class="sbdp-elementor-upsell__list">
                            <?php foreach ($elementorUpsells as $upsell) : ?>
                                <li class="sbdp-elementor-upsell__item">
                                    <div class="sbdp-elementor-upsell__media">
                                        <?php if ($upsell['image'] !== '') : ?>
                                            <img src="<?php echo esc_url($upsell['image']); ?>" alt="<?php echo esc_attr($upsell['label']); ?>" loading="lazy">
                                        <?php else : ?>
                                            <span class="sbdp-elementor-upsell__media-placeholder" aria-hidden="true"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="sbdp-elementor-upsell__overlay">
                                        <div class="sbdp-elementor-upsell__content">
                                            <span class="sbdp-elementor-upsell__label"><?php echo esc_html($upsell['label']); ?></span>
                                            <?php if ($upsell['price'] !== '') : ?>
                                                <span class="sbdp-elementor-upsell__price"><?php echo esc_html($upsell['price']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($upsell['description'] !== '') : ?>
                                                <p class="sbdp-elementor-upsell__description"><?php echo esc_html($upsell['description']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($upsell['action'] !== '' && $upsell['url'] !== '') : ?>
                                            <a class="sbdp-elementor-upsell__link" href="<?php echo esc_url($upsell['url']); ?>">
                                                <?php echo esc_html($upsell['action']); ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </aside>
            </section>
            <?php if ($layout === 'elementor' && $elementorFaq !== array()) : ?>
            <section class="sbdp-elementor-faq">
                <h2 class="sbdp-elementor-faq__heading"><?php esc_html_e('Veelgestelde vragen', 'sbdp'); ?></h2>
                <div class="sbdp-elementor-faq__items">
                    <?php foreach ($elementorFaq as $faq) : ?>
                        <article class="sbdp-elementor-faq__item">
                            <h3 class="sbdp-elementor-faq__question"><?php echo esc_html($faq['question']); ?></h3>
                            <?php if ($faq['answer'] !== '') : ?>
                                <p class="sbdp-elementor-faq__answer"><?php echo esc_html($faq['answer']); ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </section>

        <?php if ($experience_timeline !== array()) : ?>
        <section class="sbdp-experience sbdp-experience--timeline">
            <div class="sbdp-experience__header">
                <h2><?php echo esc_html($experience_headings['timeline']); ?></h2>
                <?php if ($experience_headings['timeline_sub'] !== '') : ?>
                    <p><?php echo esc_html($experience_headings['timeline_sub']); ?></p>
                <?php endif; ?>
            </div>
            <ol class="sbdp-timeline">
                <?php foreach ($experience_timeline as $step) : ?>
                    <li class="sbdp-timeline__item">
                        <div class="sbdp-timeline__marker" aria-hidden="true"></div>
                        <div class="sbdp-timeline__content">
                            <?php if ($step['time'] !== '') : ?>
                                <span class="sbdp-timeline__time"><?php echo esc_html($step['time']); ?></span>
                            <?php endif; ?>
                            <h3 class="sbdp-timeline__title"><?php echo esc_html($step['label']); ?></h3>
                            <?php if ($step['description'] !== '') : ?>
                                <p class="sbdp-timeline__description"><?php echo esc_html($step['description']); ?></p>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
        <?php endif; ?>

        <?php
    }

    /**
     * @param mixed $raw
     * @return array<int,array{label:string,description:string,action:string,url:string,image:string,price:string}>
     */
    private static function normalize_elementor_upsells($raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $items = array();

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $label = isset($entry['label']) ? trim((string) $entry['label']) : '';
            if ($label === '') {
                continue;
            }

            $description = isset($entry['description']) ? trim((string) $entry['description']) : '';
            $action      = isset($entry['action']) ? trim((string) $entry['action']) : '';
            $url         = isset($entry['url']) ? trim((string) $entry['url']) : '';
            $image       = isset($entry['image']) ? trim((string) $entry['image']) : '';
            $price       = isset($entry['price']) ? trim((string) $entry['price']) : '';

            if ($url !== '' && ! filter_var($url, FILTER_VALIDATE_URL)) {
                $url = '';
            }
            if ($image !== '' && ! filter_var($image, FILTER_VALIDATE_URL)) {
                $image = '';
            }
            if ($price === '' && preg_match('/€\s*[0-9.,]+/u', $label, $priceMatch)) {
                $price = (string) ($priceMatch[0] ?? '');
            }
            if ($action === '' && $url !== '') {
                $action = __('Bekijk', 'sbdp');
            }

            $items[] = array(
                'label'       => $label,
                'description' => $description,
                'action'      => $action,
                'url'         => $url,
                'image'       => $image,
                'price'       => $price,
            );
        }

        return $items;
    }

    /**
     * @param mixed $raw
     * @return array<int,array{question:string,answer:string}>
     */
    private static function normalize_elementor_faq($raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $items = array();

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $question = isset($entry['question']) ? trim((string) $entry['question']) : '';
            if ($question === '') {
                continue;
            }

            $answer = isset($entry['answer']) ? trim((string) $entry['answer']) : '';

            $items[] = array(
                'question' => $question,
                'answer'   => $answer,
            );
        }

        return $items;
    }

    private static function get_current_product(): ?WC_Product
    {
        global $product;

        if ($product instanceof WC_Product) {
            return $product;
        }

        if (function_exists('wc_get_product')) {
            $maybe = wc_get_product(get_the_ID());
            if ($maybe instanceof WC_Product) {
                return $maybe;
            }
        }

        return null;
    }

    private static function is_target_product(?WC_Product $product): bool
    {
        if (! $product instanceof WC_Product) {
            return false;
        }

        return $product->get_type() === BookableServiceProductType::PRODUCT_TYPE;
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
        if ($page instanceof WP_Post) {
            $link = get_permalink($page);
            if ($link) {
                return $link;
            }
        }

        return '';
    }

    private static function format_capacity_notice(array $resources): string
    {
        foreach ($resources as $resource) {
            if (! is_array($resource)) {
                continue;
            }

            $capacity = isset($resource['capacity']) ? (int) $resource['capacity'] : 0;
            if ($capacity <= 0) {
                continue;
            }

            $name = isset($resource['title']) ? trim((string) $resource['title']) : '';
            if ($name === '') {
                $name = __('resource', 'sbdp');
            }

            /* translators: 1: capacity count, 2: resource label. */
            return sprintf(
                _n('Capaciteit per slot: %1$d persoon (%2$s)', 'Capaciteit per slot: %1$d personen (%2$s)', $capacity, 'sbdp'),
                $capacity,
                $name
            );
        }

        return '';
    }

    private static function format_duration_label(int $minutes): string
    {
        if ($minutes <= 0) {
            return '';
        }

        $hours     = (int) floor($minutes / 60);
        $remaining = $minutes % 60;
        $parts     = [];

        if ($hours > 0) {
            $parts[] = sprintf(_n('%d uur', '%d uur', $hours, 'sbdp'), $hours);
        }

        if ($remaining > 0) {
            $parts[] = sprintf(_n('%d minuut', '%d minuten', $remaining, 'sbdp'), $remaining);
        }

        if ($parts === []) {
            $parts[] = sprintf(_n('%d minuut', '%d minuten', $minutes, 'sbdp'), $minutes);
        }

        return implode(' ', $parts);
    }

    private static function format_people_label(int $min, int $max): string
    {
        if ($min <= 0 && $max <= 0) {
            return '';
        }

        if ($min > 0 && $max > 0) {
            if ($min === $max) {
                /* translators: %d: number of participants */
                return sprintf(_n('%d persoon', '%d personen', $min, 'sbdp'), $min);
            }

            return sprintf(
                /* translators: 1: minimum people, 2: maximum people */
                __('%1$d - %2$d personen', 'sbdp'),
                $min,
                $max
            );
        }

        if ($max > 0) {
            /* translators: %d: maximum people */
            return sprintf(_n('Tot %d persoon', 'Tot %d personen', $max, 'sbdp'), $max);
        }

        if ($min > 0) {
            /* translators: %d: minimum people */
            return sprintf(_n('Vanaf %d persoon', 'Vanaf %d personen', $min, 'sbdp'), $min);
        }

        return '';
    }

    private static function format_price_from(WC_Product $product, string $currency): string
    {
        $price = 0.0;

        if (class_exists('\SBDP\Pricing\PricingService')) {
            try {
                $pricing = \SBDP\Pricing\PricingService::instance()->getProductPricing(
                    $product->get_id(),
                    array(
                        'channel'    => 'product_form_price_from',
                        'price_mode' => 'gross',
                    )
                );
                $price = (float) ($pricing['base_price'] ?? 0.0);
                if ($price <= 0.0) {
                    $price = (float) ($pricing['per_person'] ?? 0.0);
                }
            } catch (\Throwable $exception) {
                $price = 0.0;
            }
        }

        if ($price <= 0.0) {
            $rawMeta = get_post_meta($product->get_id(), '_sbdp_base_price', true);
            if (is_numeric($rawMeta) && (float) $rawMeta > 0.0) {
                $price = (float) $rawMeta;
            }
        }

        if ($price <= 0.0) {
            $raw_price = function_exists('wc_get_price_including_tax')
                ? wc_get_price_including_tax($product, array('qty' => 1))
                : $product->get_price();
            if ($raw_price === '' || $raw_price === null) {
                return '';
            }

            $price = (float) $raw_price;
        }

        if ($price < 0) {
            return '';
        }

        if (function_exists('wc_price')) {
            return wc_price($price, ['currency' => $currency]);
        }

        return number_format_i18n($price, 2) . ' ' . $currency;
    }

    /**
     * @param array<int, string> $feature_points
     * @param array<int, string> $reason_points
     * @return array{
     *     highlights: array<int, array{icon:string,title:string,description:string}>,
     *     timeline: array<int, array{time:string,label:string,description:string}>,
     *     included: array<int, string>,
     *     excluded: array<int, string>,
     *     good_to_know: array<int, string>,
     *     trust: array<int, array{icon:string,label:string,description:string}>,
     *     headings: array<string, string>
     * }
     */
    private static function build_experience_blueprint(WC_Product $product, array $feature_points, array $reason_points): array
    {
        $default_highlights = array();
        foreach ($feature_points as $index => $point) {
            $point = self::truncate_text((string) $point, 90);
            if ($point === '') {
                continue;
            }

            $default_highlights[] = array(
                'icon'        => self::get_highlight_icon($index),
                'title'       => $point,
                'description' => '',
            );
        }

        if ($default_highlights === array()) {
            foreach ($reason_points as $index => $point) {
                $point = self::truncate_text((string) $point, 90);
                if ($point === '') {
                    continue;
                }

                $default_highlights[] = array(
                    'icon'        => self::get_highlight_icon($index),
                    'title'       => $point,
                    'description' => '',
                );
            }
        }

        if ($default_highlights === array()) {
            $default_highlights = array(
                array(
                    'icon'        => '🌇',
                    'title'       => __('Curated dagprogramma', 'sbdp'),
                    'description' => __('We begeleiden je van eerste idee tot het afronden van de ervaring.', 'sbdp'),
                ),
                array(
                    'icon'        => '🧭',
                    'title'       => __('Altijd gekoppeld aan de planner', 'sbdp'),
                    'description' => __('Combineer eenvoudig meerdere activiteiten in één boekbare dagplanning.', 'sbdp'),
                ),
                array(
                    'icon'        => '🤝',
                    'title'       => __('Persoonlijk team op locatie', 'sbdp'),
                    'description' => __('Onze hosts staan klaar met lokale tips en directe ondersteuning.', 'sbdp'),
                ),
            );
        }

        $highlights = apply_filters('sbdp/product_form/experience_highlights', $default_highlights, $product);
        $highlights = self::normalize_highlights($highlights);

        $default_timeline = array();

        $timeline = apply_filters('sbdp/product_form/experience_timeline', $default_timeline, $product);
        $timeline = self::normalize_timeline($timeline);

        $default_included = array();

        $included = apply_filters('sbdp/product_form/experience_included', $default_included, $product);
        $included = self::normalize_string_list($included);

        $default_excluded = array();

        $excluded = apply_filters('sbdp/product_form/experience_excluded', $default_excluded, $product);
        $excluded = self::normalize_string_list($excluded);

        $default_good_to_know = array();

        $good_to_know = apply_filters('sbdp/product_form/experience_good_to_know', $default_good_to_know, $product);
        $good_to_know = self::normalize_string_list($good_to_know);

        $default_trust = array();

        $trust = apply_filters('sbdp/product_form/experience_trust', $default_trust, $product);
        $trust = self::normalize_trust_badges($trust);

        $heading_defaults = array(
            'highlights'       => '',
            'highlights_sub'   => '',
            'timeline'         => '',
            'timeline_sub'     => '',
            'essentials'       => '',
            'essentials_sub'   => '',
            'trust'            => '',
            'trust_sub'        => '',
        );

        $headings = apply_filters('sbdp/product_form/experience_headings', $heading_defaults, $product);
        $headings = self::normalize_headings($headings, $heading_defaults);

        return array(
            'highlights'   => $highlights,
            'timeline'     => $timeline,
            'included'     => $included,
            'excluded'     => $excluded,
            'good_to_know' => $good_to_know,
            'trust'        => $trust,
            'headings'     => $headings,
        );
    }

    private static function normalize_highlights($raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $normalised = array();
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $title = isset($entry['title']) ? self::truncate_text((string) $entry['title'], 90) : '';
            if ($title === '') {
                continue;
            }

            $icon        = isset($entry['icon']) ? self::sanitize_icon($entry['icon']) : '';
            $description = isset($entry['description']) ? self::truncate_text((string) $entry['description'], 150) : '';

            $normalised[] = array(
                'icon'        => $icon,
                'title'       => $title,
                'description' => $description,
            );

            if (count($normalised) >= 6) {
                break;
            }
        }

        return $normalised;
    }

    private static function normalize_timeline($raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $normalised = array();
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $label       = isset($entry['label']) ? self::truncate_text((string) $entry['label'], 90) : '';
            $description = isset($entry['description']) ? self::truncate_text((string) $entry['description'], 160) : '';
            if ($label === '') {
                continue;
            }

            $time = isset($entry['time']) ? self::truncate_text((string) $entry['time'], 60) : '';

            $normalised[] = array(
                'time'        => $time,
                'label'       => $label,
                'description' => $description,
            );

            if (count($normalised) >= 6) {
                break;
            }
        }

        return $normalised;
    }

    private static function normalize_string_list($raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $normalised = array();
        foreach ($raw as $entry) {
            $text = self::truncate_text((string) $entry, 140);
            if ($text === '') {
                continue;
            }

            if (in_array($text, $normalised, true)) {
                continue;
            }

            $normalised[] = $text;

            if (count($normalised) >= 8) {
                break;
            }
        }

        return $normalised;
    }

    private static function normalize_trust_badges($raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $normalised = array();
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $label       = isset($entry['label']) ? self::truncate_text((string) $entry['label'], 80) : '';
            if ($label === '') {
                continue;
            }

            $icon        = isset($entry['icon']) ? self::sanitize_icon($entry['icon']) : '';
            $description = isset($entry['description']) ? self::truncate_text((string) $entry['description'], 150) : '';

            $normalised[] = array(
                'icon'        => $icon,
                'label'       => $label,
                'description' => $description,
            );

            if (count($normalised) >= 5) {
                break;
            }
        }

        return $normalised;
    }

    private static function normalize_headings($raw, array $defaults): array
    {
        if (! is_array($raw)) {
            $raw = array();
        }

        $output = array();
        foreach ($defaults as $key => $fallback) {
            $value = isset($raw[$key]) ? (string) $raw[$key] : (string) $fallback;
            $value = self::truncate_text($value, 160);
            $output[$key] = $value;
        }

        return $output;
    }

    private static function get_highlight_icon(int $index): string
    {
        $icons = array('🧭', '🎧', '🚶', '📷', '🍽️');
        $count = count($icons);
        if ($count === 0) {
            return '';
        }

        $position = $index % $count;
        return $icons[$position];
    }

    /**
     * @param mixed $icon
     */
    private static function sanitize_icon($icon): string
    {
        $output = is_string($icon) ? trim($icon) : '';
        if ($output === '') {
            return '';
        }

        if (mb_strlen($output) > 4) {
            $output = mb_substr($output, 0, 4);
        }

        return $output;
    }

    /**
     * @param mixed $content
     */
    private static function trim_intro_text($content): string
    {
        if (! is_string($content)) {
            return '';
        }

        $clean = trim(wp_strip_all_tags($content));
        if ($clean === '') {
            return '';
        }

        $normalised = preg_replace('/\s+/u', ' ', $clean);
        if (! is_string($normalised)) {
            $normalised = $clean;
        }

        return self::truncate_text($normalised, 180);
    }

    private static function build_description_sections(WC_Product $product, string $intro_text): array
    {
        $intro = is_string($intro_text) ? trim($intro_text) : '';
        $description = wp_strip_all_tags($product->get_description());
        $combined = trim($intro . ' ' . $description);
        $combined = preg_replace('/\s+/u', ' ', $combined);
        if (! is_string($combined)) {
            $combined = '';
        }
        if ($combined === '') {
            return [
                'intro'    => $intro,
                'features' => [],
                'reasons'  => [],
            ];
        }

        $sentences = preg_split('/(?<=[\.\?\!])\s+/u', $combined);
        if (! is_array($sentences) || empty($sentences)) {
            $sentences = [$combined];
        }

        $unique = [];
        $seen   = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }
            $key = mb_strtolower($sentence);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[]   = $sentence;
        }

        if ($intro === '' && ! empty($unique)) {
            $intro = array_shift($unique);
        }
        $intro = self::truncate_text($intro, 160);

        $features = [];
        $reasons  = [];
        foreach ($unique as $sentence) {
            if (count($features) < 3) {
                $features[] = self::truncate_text($sentence, 110);
            } elseif (count($reasons) < 3) {
                $reasons[] = self::truncate_text($sentence, 110);
            }
            if (count($features) >= 3 && count($reasons) >= 3) {
                break;
            }
        }

        $totalLength = mb_strlen($intro);
        foreach ($features as $feature) {
            $totalLength += mb_strlen($feature);
        }
        foreach ($reasons as $reason) {
            $totalLength += mb_strlen($reason);
        }

        while ($totalLength > 400 && ! empty($reasons)) {
            $removed = array_pop($reasons);
            $totalLength -= mb_strlen($removed);
        }
        while ($totalLength > 400 && ! empty($features)) {
            $removed = array_pop($features);
            $totalLength -= mb_strlen($removed);
        }

        return [
            'intro'    => $intro,
            'features' => $features,
            'reasons'  => $reasons,
        ];
    }

    private static function truncate_text(string $text, int $limit = 200): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        if (mb_strlen($trimmed) <= $limit) {
            return $trimmed;
        }

        $snippet = mb_substr($trimmed, 0, $limit);
        $lastSpace = mb_strrpos($snippet, ' ');
        if ($lastSpace !== false) {
            $snippet = mb_substr($snippet, 0, $lastSpace);
        }

        return rtrim($snippet, ',;') . '…';
    }

    private static function normalize_time(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (! preg_match('/^\d{2}:\d{2}$/', $raw)) {
            return '';
        }

        return $raw;
    }
}

