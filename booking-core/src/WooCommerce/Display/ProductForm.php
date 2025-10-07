<?php

declare(strict_types=1);

namespace BSPModule\Core\WooCommerce\Display;

use BSPModule\Core\Assets\EnqueueService;
use BSPModule\Core\Product\ProductMeta;
use BSPModule\Core\WooCommerce\ProductType\BookableServiceProductType;
use WC_Product;
use WP_Post;

final class ProductForm
{
    private const FALLBACK_TIME = '09:00';

    private static bool $booted = false;

    private static bool $localized = false;

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
    }

    public static function maybe_enqueue_assets(): void
    {
        $product = self::get_current_product();
        if (! self::is_target_product($product)) {
            return;
        }

        wp_enqueue_style(EnqueueService::PRODUCT_HANDLE_STYLE);

        if (! self::$localized) {
            wp_localize_script(
                EnqueueService::PRODUCT_HANDLE_SCRIPT,
                'SBDP_ProductBooking',
                [
                    'compose'           => esc_url_raw(rest_url('sbdp/v1/compose_booking')),
                    'nonce'             => wp_create_nonce('wp_rest'),
                    'fallback_redirect' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout'),
                    'planner_url'       => self::get_planner_url(),
                    'messages'          => [
                        'generic_error'   => __('Er ging iets mis. Probeer het opnieuw.', 'sbdp'),
                        'missing_fields'  => __('Vul datum, starttijd en aantal personen in.', 'sbdp'),
                        'planner_missing' => __('Plannerpagina niet gevonden.', 'sbdp'),
                        'redirecting'     => __('Bezig met doorsturen.', 'sbdp'),
                    ],
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

        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
    }

    public static function render(): void
    {
        $product = self::get_current_product();
        if (! self::is_target_product($product)) {
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
            $classes[] = 'sbdp-booking-layout-enabled';
        }

        return $classes;
    }

    private static function render_form(WC_Product $product): void
    {
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
            $default_time = self::FALLBACK_TIME;
        }

        if ($duration <= 0) {
            $duration = 60;
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

        $config = [
            'productId'   => $product_id,
            'defaults'    => [
                'date'         => $default_date,
                'time'         => $default_time,
                'participants' => $default_people,
            ],
            'limits'      => [
                'min' => $min_people,
                'max' => $max_people > 0 ? $max_people : null,
            ],
            'duration'    => $duration,
            'labels'      => $labels,
            'resources'   => $resources,
            'today'       => $today,
            'plannerUrl'  => self::get_planner_url(),
            'currency'    => $currency,
            'currencySym' => $currency_sym,
            'basePrice'   => (float) $product->get_price(),
            'locale'      => get_locale(),
        ];

        $config_json = wp_json_encode($config);
        if (! is_string($config_json)) {
            return;
        }

        $max_attribute = $max_people > 0 ? ' max="' . esc_attr((string) $max_people) . '"' : '';
        ?>
        <section class="sbdp-booking-shell" data-sbdp-product-form data-sbdp-config="<?php echo esc_attr($config_json); ?>">
            <header class="sbdp-booking-shell__header">
                <div class="sbdp-booking-shell__headline">
                    <h2><?php esc_html_e('Boek of stel je dag samen', 'sbdp'); ?></h2>
                    <?php if ($intro) : ?>
                        <div class="sbdp-booking-shell__intro"><?php echo wp_kses_post($intro); ?></div>
                    <?php endif; ?>
                </div>
                <div class="sbdp-booking-shell__badges">
                    <?php if ($stock_html) : ?>
                        <div class="sbdp-booking-shell__badge"><?php echo wp_kses_post($stock_html); ?></div>
                    <?php endif; ?>
                    <?php if ($capacity !== '') : ?>
                        <div class="sbdp-booking-shell__badge"><?php echo esc_html($capacity); ?></div>
                    <?php endif; ?>
                </div>
            </header>

            <div class="sbdp-booking-shell__grid">
                <div class="sbdp-booking-shell__controls">
                    <div class="sbdp-booking-step">
                        <span class="sbdp-booking-step__label">1.</span>
                        <div class="sbdp-booking-step__content">
                            <h3><?php esc_html_e('Kies de datum', 'sbdp'); ?></h3>
                            <input type="date" id="sbdp-date" name="sbdp_date" value="<?php echo esc_attr($default_date); ?>" min="<?php echo esc_attr($today); ?>" required />
                        </div>
                    </div>

                    <div class="sbdp-booking-step">
                        <span class="sbdp-booking-step__label">2.</span>
                        <div class="sbdp-booking-step__content">
                            <h3><?php esc_html_e('Kies de starttijd', 'sbdp'); ?></h3>
                            <input type="time" id="sbdp-time" name="sbdp_time" value="<?php echo esc_attr($default_time); ?>" required />
                        </div>
                    </div>

                    <div class="sbdp-booking-step">
                        <span class="sbdp-booking-step__label">3.</span>
                        <div class="sbdp-booking-step__content">
                            <h3><?php esc_html_e('Kies aantal personen', 'sbdp'); ?></h3>
                            <input type="number" id="sbdp-participants" name="sbdp_participants" value="<?php echo esc_attr((string) $default_people); ?>" min="<?php echo esc_attr((string) $min_people); ?>"<?php echo $max_attribute; ?> step="1" required />
                        </div>
                    </div>

                    <div class="sbdp-booking-step">
                        <span class="sbdp-booking-step__label">4.</span>
                        <div class="sbdp-booking-step__content">
                            <h3><?php esc_html_e('Combi deal', 'sbdp'); ?></h3>
                            <select id="sbdp-combi" name="sbdp_combi">
                                <?php if (! empty($combi_options)) : ?>
                                    <option value=""><?php esc_html_e('Maak een keuze', 'sbdp'); ?></option>
                                    <?php foreach ($combi_options as $option) :
                                        $value = isset($option['value']) ? (string) $option['value'] : '';
                                        $label = isset($option['label']) ? $option['label'] : $value;
                                        ?>
                                        <option value="<?php echo esc_attr($value); ?>" data-adjustment="<?php echo esc_attr($option['adjustment'] ?? ''); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <option value=""><?php esc_html_e('Geen combinaties beschikbaar', 'sbdp'); ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <aside class="sbdp-booking-shell__summary" data-sbdp-summary aria-live="polite">
                    <div class="sbdp-booking-shell__summary-inner">
                        <h3><?php esc_html_e('Totaalprijs incl. btw', 'sbdp'); ?></h3>
                        <strong class="sbdp-booking-shell__total" data-sbdp-total><?php echo esc_html($currency_sym); ?>0,00</strong>
                        <dl class="sbdp-booking-shell__details">
                            <div>
                                <dt><?php esc_html_e('Duur', 'sbdp'); ?></dt>
                                <dd data-summary="duration"><?php esc_html_e('Nog geen duur gekozen.', 'sbdp'); ?></dd>
                            </div>
                            <div>
                                <dt><?php esc_html_e('Deelnemers', 'sbdp'); ?></dt>
                                <dd data-summary="people"><?php esc_html_e('Nog geen aantal gekozen.', 'sbdp'); ?></dd>
                            </div>
                            <div>
                                <dt><?php esc_html_e('Combi deal', 'sbdp'); ?></dt>
                                <dd data-summary="combi"><?php esc_html_e('Geen combi geselecteerd.', 'sbdp'); ?></dd>
                            </div>
                        </dl>
                        <p class="sbdp-booking-shell__hint" data-summary-hint><?php esc_html_e('Pas de velden aan om een prijsberekening te zien.', 'sbdp'); ?></p>
                        <div class="sbdp-booking-shell__buttons">
                            <button type="button" class="button button-primary button-large" data-sbdp-action="book"><?php esc_html_e('Direct boeken', 'sbdp'); ?></button>
                            <button type="button" class="button button-secondary button-large" data-sbdp-action="plan"><?php esc_html_e('Informatie aanvragen', 'sbdp'); ?></button>
                        </div>
                        <p class="sbdp-product-booking__feedback" data-sbdp-feedback role="status" aria-live="polite"></p>
                    </div>
                </aside>
            </div>
        </section>
        <?php
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

