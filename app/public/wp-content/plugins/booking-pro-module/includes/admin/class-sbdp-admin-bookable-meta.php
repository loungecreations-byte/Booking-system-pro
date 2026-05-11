<?php

namespace SBDP\Admin\Bookable;

use BSPModule\Core\Product\ProductMeta;
use BSPModule\Core\Resource\ResourceMeta;
use WC_Product;
use WP_Post;
use WP_REST_Request;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Booking product admin meta interface.
 */
class SBDP_Admin_Bookable_Meta
{
    private const META_PREFIX = '_sbdp_';
    private const OPTION_GOOGLE_MAPS_KEY = 'sbdp_google_maps_api_key';
    private const AJAX_ACTION = 'sbdp_duplicate_booking_meta';
    private const OPTION_BASE_PRICE_REPAIR = 'sbdp_bookable_base_price_repair_2026_04_14';

    /**
     * Register admin hooks.
     */
    public static function init(): void
    {
        add_action('add_meta_boxes_product', [ __CLASS__, 'register_meta_box' ]);
        add_action('save_post_product', [ __CLASS__, 'save_meta' ], 10, 2);
        add_action('woocommerce_admin_process_product_object', [ __CLASS__, 'save_meta_via_product_object' ], 20, 1);
        add_action('admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ]);
        add_action('wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'handle_duplicate_meta' ]);
        add_action('init', [ __CLASS__, 'maybe_repair_corrupted_base_prices' ], 20);
    }

    /**
     * Register the modular meta box when editing bookable products.
     */
    public static function register_meta_box(WP_Post $post): void
    {
        if (! self::should_render_for_post($post->ID)) {
            return;
        }

        add_meta_box(
            'sbdp-bookable-meta',
            __('Booking Planner Settings', 'sbdp'),
            [ __CLASS__, 'render_meta_box' ],
            'product',
            'normal',
            'high'
        );
    }

    /**
     * Determine whether the UI should load for the current post.
     */
    private static function should_render_for_post(int $post_id): bool
    {
        if (! $post_id) {
            return self::maybe_is_new_bookable_request();
        }

        if (! function_exists('wc_get_product')) {
            return false;
        }

        $product = wc_get_product($post_id);
        if (! $product) {
            return false;
        }

        return $product->get_type() === self::get_product_type();
    }

    /**
     * For new products, default to showing when the request targets our type.
     */
    private static function maybe_is_new_bookable_request(): bool
    {
        $requested = isset($_GET['product_type']) ? sanitize_text_field(wp_unslash($_GET['product_type'])) : '';// phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (! $requested && isset($_GET['type'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $requested = sanitize_text_field(wp_unslash($_GET['type']));
        }

        return self::get_product_type() === $requested;
    }

    /**
     * Enqueue admin assets.
     */
    public static function enqueue_assets(string $hook_suffix): void
    {
        if ('post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix) {
            return;
        }

        $screen = get_current_screen();
        if (! $screen || 'product' !== $screen->post_type) {
            return;
        }

        $post_id      = isset($_GET['post']) ? absint($_GET['post']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $should_render = $post_id ? self::should_render_for_post($post_id) : self::maybe_is_new_bookable_request();

        if (! $should_render) {
            return;
        }

        wp_enqueue_style('sbdp-admin-bookable', SBDP_URL . 'assets/admin-bookable.css', [], SBDP_VER);
    // Ensure enhanced select assets are available for resource selectors.
        if (function_exists('wp_enqueue_script')) {
            wp_enqueue_style('woocommerce_admin_styles');
            wp_enqueue_script('wc-enhanced-select');
        }

        wp_register_script(
            'sbdp-admin-bookable',
            SBDP_URL . 'assets/admin-bookable.js',
            [ 'jquery', 'wp-i18n' ],
            SBDP_VER,
            true
        );

        $meta = $post_id ? self::get_meta($post_id) : self::get_default_meta();

        wp_localize_script(
            'sbdp-admin-bookable',
            'SBDP_BOOKABLE',
            [
                'ajaxUrl'     => admin_url('admin-ajax.php'),
                'ajaxAction'  => self::AJAX_ACTION,
                'ajaxNonce'   => wp_create_nonce(self::AJAX_ACTION),
                'productId'   => $post_id,
                'meta'        => $meta,
                'mapsApiKey'  => self::get_google_maps_api_key(),
                'restUrlBase' => esc_url_raw(rest_url('sbdp/v1/bookable-meta/')),
                'restNonce'   => wp_create_nonce('wp_rest'),
                'i18n'        => self::get_i18n_strings(),
            ]
        );

        wp_set_script_translations('sbdp-admin-bookable', 'sbdp');
        wp_enqueue_script('sbdp-admin-bookable');
    }

    /**
     * Render the main meta interface.
     */
    public static function render_meta_box(WP_Post $post): void
    {
        $meta = self::get_meta($post->ID);
        $resource_options = self::get_resource_options();
        $combi_options = self::get_combi_options($post->ID);
        wp_nonce_field('sbdp_bookable_meta', 'sbdp_bookable_meta_nonce');
        include __DIR__ . '/views/meta-box.php';
    }
    /**
     * Persist booking meta.
     */
    public static function save_meta(int $post_id, WP_Post $post): void
    {
        if (! self::has_valid_meta_nonce()) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        $product_type = isset($_POST['product-type']) ? sanitize_text_field(wp_unslash($_POST['product-type'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (! $product_type && function_exists('wc_get_product')) {
            $product = wc_get_product($post_id);
            $product_type = $product ? $product->get_type() : '';
        }

        if (self::get_product_type() !== $product_type) {
            return;
        }

        self::persist_posted_meta($post_id);
    }

    public static function save_meta_via_product_object(WC_Product $product): void
    {
        $post_id = $product->get_id();
        if ($post_id <= 0) {
            return;
        }

        if (! self::has_valid_meta_nonce()) {
            return;
        }

        if (self::get_product_type() !== $product->get_type()) {
            return;
        }

        self::persist_posted_meta($post_id, $product);
    }

    /**
     * AJAX duplication handler.
     */
    public static function handle_duplicate_meta(): void
    {
        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error([ 'message' => __('Insufficient permissions.', 'sbdp') ], 403);
        }

        $source_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
        $target_id = isset($_POST['target_id']) ? absint($_POST['target_id']) : 0;

        if (! $source_id || ! $target_id) {
            wp_send_json_error([ 'message' => __('Missing product reference.', 'sbdp') ], 400);
        }

        $source_meta = self::get_meta($source_id);
        if (empty($source_meta)) {
            wp_send_json_error([ 'message' => __('Source product has no booking data.', 'sbdp') ], 404);
        }

        foreach ($source_meta as $key => $value) {
            update_post_meta($target_id, self::META_PREFIX . $key, $value);
        }

        self::sync_legacy_meta($target_id, $source_meta);
        self::sync_product_prices($target_id, $source_meta);

        wp_send_json_success([
            'meta'    => $source_meta,
            'message' => __('Booking settings duplicated successfully.', 'sbdp'),
        ]);
    }

    /**
     * Public helper for REST responses.
     */
    public static function get_meta(int $product_id): array
    {
        $defaults = self::get_default_meta();
        $output   = $defaults;

        foreach (array_keys($defaults) as $key) {
            $meta_key = self::META_PREFIX . $key;
            $stored   = get_post_meta($product_id, $meta_key, true);
            if ('' === $stored || null === $stored) {
                $stored = self::get_legacy_fallback_value($product_id, $key);
                if ('' === $stored || null === $stored || [] === $stored) {
                    continue;
                }
            }

            if (is_array($defaults[ $key ])) {
                if (is_array($stored)) {
                    $output[ $key ] = $stored;
                }
                continue;
            }

            $output[ $key ] = $stored;
        }

        $output['resource_ids'] = self::resolve_resource_ids($product_id);
        return $output;
    }

    /**
     * Normalize decimal input so both commas and dots can be used as decimal markers.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_decimal_input($value)
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return $normalized;
        }

        return str_replace(',', '.', $normalized);
    }
    /**
     * Prepare REST payload.
     */
    public static function prepare_meta_for_rest(int $product_id): array
    {
        $meta = self::get_meta($product_id);

        foreach ($meta as $key => $value) {
            if (is_bool($value)) {
                $meta[ $key ] = $value;
            } elseif (is_numeric($value)) {
                $meta[ $key ] = $value + 0;
            }
        }

        return $meta;
    }

    /**
     * @return mixed
     */
    private static function get_legacy_fallback_value(int $product_id, string $key)
    {
        switch ($key) {
            case 'booking_default_start_date':
                return get_post_meta($product_id, '_sbdp_default_start_date', true);
            case 'booking_default_start_time':
                return get_post_meta($product_id, '_sbdp_default_start_time', true);
            case 'booking_min_duration':
                return get_post_meta($product_id, '_sbdp_duration', true);
            case 'people_enabled':
                return self::normalize_yes_no_meta(get_post_meta($product_id, '_sbdp_enable_people', true));
            case 'people_min':
                return get_post_meta($product_id, '_sbdp_min_people', true);
            case 'people_max':
                return get_post_meta($product_id, '_sbdp_max_people', true);
            case 'people_count_as_booking':
                return self::normalize_yes_no_meta(get_post_meta($product_id, '_sbdp_people_as_bookings', true));
            case 'people_type_enabled':
                return self::normalize_yes_no_meta(get_post_meta($product_id, '_sbdp_enable_person_types', true));
            case 'base_price':
                return get_post_meta($product_id, '_sbdp_base_price', true);
            case 'base_price_per_person':
                $flag = get_post_meta($product_id, '_sbdp_base_price_per_person', true);
                if ($flag !== '' && $flag !== null) {
                    return self::normalize_yes_no_meta($flag);
                }

                // Backward compatibility: older saves incorrectly stored this checkbox
                // inside the numeric per-person price field.
                return self::normalize_yes_no_meta(get_post_meta($product_id, '_sbdp_price_per_person', true));
            case 'fixed_fee':
                return get_post_meta($product_id, '_sbdp_base_fee', true);
            case 'fixed_fee_per_person':
                return self::normalize_yes_no_meta(get_post_meta($product_id, '_sbdp_fixed_fee_per_person', true));
            case 'last_minute_discount':
                return get_post_meta($product_id, '_sbdp_last_minute_discount', true);
            case 'default_availability':
                return self::decode_json_array_meta(get_post_meta($product_id, '_sbdp_default_hours', true));
            case 'additional_rules':
                return self::decode_json_array_meta(get_post_meta($product_id, '_sbdp_availability_rules', true));
            default:
                return null;
        }
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_yes_no_meta($value)
    {
        if ($value === 'yes' || $value === '1' || $value === 1 || $value === true) {
            return true;
        }

        if ($value === 'no' || $value === '0' || $value === 0 || $value === false || $value === '') {
            return false;
        }

        return $value;
    }

    /**
     * @return array<int|string,mixed>
     */
    private static function decode_json_array_meta($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Default meta payload.
     */
    private static function get_default_meta(): array
    {
        $today = gmdate('Y-m-d');
        $defaults = [
            'booking_duration_type'        => 'hours',            'booking_default_start_date'   => $today,
            'booking_default_start_time'   => '09:00',
            'booking_allowed_start_days'   => [ 'mon', 'tue', 'wed', 'thu', 'fri' ],
            'booking_terms_max_per_unit'   => 0,
            'booking_min_advance'          => 0,
            'booking_max_advance'          => 365,
            'booking_min_duration'         => 1,
            'booking_max_duration'         => 4,
            'booking_checkin'              => '09:00',
            'booking_checkout'             => '21:00',
            'booking_buffer_time'          => 0,
            'booking_time_increment_based' => true,
            'booking_requires_confirmation' => false,
            'booking_allow_cancellation'   => true,
            'booking_location'             => '',
            'booking_sync_google_calendar' => false,
            'resource_ids'                 => [],
            'people_enabled'               => false,
            'people_min'                   => 1,
            'people_max'                   => 10,
            'people_count_as_booking'      => false,
            'people_type_enabled'          => false,
            'people_types'                 => [
                [
                    'label' => __('Adults', 'sbdp'),
                    'price' => '',
                ],
                [
                    'label' => __('Children', 'sbdp'),
                    'price' => '',
                ],
            ],
            'base_price'                   => '',
            'base_price_per_person'        => false,
            'fixed_fee'                    => '',
            'fixed_fee_per_person'         => false,
            'last_minute_discount'         => '',
            'last_minute_days_before'      => '',
            'tax_class'                    => '',
            'extra_costs'                  => [],
            'advanced_price_rules'         => [],
            'combi_deals'                  => [],
            'default_availability'         => self::get_default_availability_template(),
            'additional_rules'             => [],
            'exclusions'                   => '',
            'permalink_override'           => '',
        ];

        return $defaults;
    }

    /**
     * Sanitize incoming payload.
     */
    private static function sanitize_meta_payload(array $raw): array
    {
        $defaults = self::get_default_meta();
        $clean    = [];

        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $raw)) {
                $clean[ $key ] = $default;
                continue;
            }

            $value = $raw[ $key ];

            switch ($key) {
                case 'booking_duration_type':
                    $allowed = [ 'minutes', 'hours', 'days', 'months' ];
                    $value   = in_array($value, $allowed, true) ? $value : $default;
                    break;
                case 'booking_allowed_start_days':
                    $value = self::sanitize_days($value);
                    break;
                case 'booking_terms_max_per_unit':
                case 'booking_min_duration':
                case 'booking_max_duration':
                case 'booking_min_advance':
                case 'booking_max_advance':
                case 'booking_buffer_time':
                case 'last_minute_days_before':
                    $value = max(0, absint($value));
                    break;
                case 'resource_ids':
                                    $value = self::sanitize_resource_ids($value);
                    break;
                case 'combi_deals':
                    $value = self::sanitize_product_ids($value);
                    break;
                case 'booking_default_start_date':
                case 'booking_checkin':
                case 'booking_checkout':
                case 'booking_default_start_time':
                    $value = sanitize_text_field($value);
                    break;
                case 'booking_time_increment_based':
                case 'booking_requires_confirmation':
                case 'booking_allow_cancellation':
                case 'people_enabled':
                case 'people_count_as_booking':
                case 'people_type_enabled':
                case 'base_price_per_person':
                case 'fixed_fee_per_person':
                case 'booking_sync_google_calendar':
                    $value = ! empty($value) && 'yes' === $value || '1' === $value || true === $value;
                    break;
                case 'booking_location':
                case 'exclusions':
                case 'permalink_override':
                    $value = sanitize_text_field($value);
                    break;
                case 'people_min':
                case 'people_max':
                    $value = max(0, absint($value));
                    break;
                case 'base_price':
                case 'fixed_fee':
                case 'last_minute_discount':
                    $value = self::normalize_decimal_input($value);
                    $value = is_numeric($value) ? (float) $value : ( '' === $value ? '' : sanitize_text_field($value) );
                    break;
                case 'tax_class':
                    $value = sanitize_text_field($value);
                    break;
                case 'people_types':
                    $value = self::sanitize_people_types($value);
                    break;
                case 'extra_costs':
                    $value = self::sanitize_extra_costs($value);
                    break;
                case 'advanced_price_rules':
                    $value = self::sanitize_advanced_rules($value);
                    break;
                case 'default_availability':
                    $value = self::sanitize_availability($value);
                    break;
                case 'additional_rules':
                    $value = self::sanitize_additional_rules($value);
                    break;
                default:
                    $value = is_array($value) ? array_map('sanitize_text_field', $value) : sanitize_text_field($value);
                    break;
            }

            $clean[ $key ] = $value;
        }

        if ($clean['people_min'] > $clean['people_max'] && $clean['people_max'] > 0) {
            $clean['people_max'] = $clean['people_min'];
        }

        return $clean;
    }

    /**
     * Synchronise key meta to legacy keys used elsewhere in the stack.
     */
    private static function sync_legacy_meta(int $post_id, array $meta): void
    {
        $map = [
            'booking_duration_type'        => '_sbdp_duration_unit',
            'booking_default_start_date'   => '_sbdp_default_start_date',
            'booking_default_start_time'   => '_sbdp_default_start_time',
            'people_enabled'               => '_sbdp_enable_people',
            'people_min'                   => '_sbdp_min_people',
            'people_max'                   => '_sbdp_max_people',
            'people_count_as_booking'      => '_sbdp_people_as_bookings',
            'people_type_enabled'          => '_sbdp_enable_person_types',
            'base_price'                   => '_sbdp_base_price',
            'fixed_fee'                    => '_sbdp_base_fee',
            'last_minute_discount'         => '_sbdp_last_minute_discount',
            'extra_costs'                  => '_sbdp_extra_costs',
            'tax_class'                    => '_tax_class',
        ];

        foreach ($map as $new_key => $legacy_key) {
            if (! array_key_exists($new_key, $meta)) {
                continue;
            }

            $value = $meta[ $new_key ];
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }

            if ('' === $value || [] === $value || null === $value) {
                delete_post_meta($post_id, $legacy_key);
            } else {
                update_post_meta($post_id, $legacy_key, $value);
            }
        }

        if (isset($meta['booking_min_duration'])) {
            update_post_meta($post_id, '_sbdp_duration', absint($meta['booking_min_duration']));
        }

        if (isset($meta['default_availability'])) {
            update_post_meta($post_id, '_sbdp_default_hours', wp_json_encode($meta['default_availability']));
        }

        if (isset($meta['additional_rules'])) {
                         update_post_meta($post_id, '_sbdp_availability_rules', wp_json_encode($meta['additional_rules']));
        }

        if (isset($meta['resource_ids'])) {
                         $resource_ids = self::sanitize_resource_ids($meta['resource_ids']);
            if ([] === $resource_ids) {
                delete_post_meta($post_id, '_sbdp_resource_ids');
                delete_post_meta($post_id, '_sbdp_resource_id');
            } else {
                update_post_meta($post_id, '_sbdp_resource_ids', $resource_ids);
                update_post_meta($post_id, '_sbdp_resource_id', (int) $resource_ids[0]);
            }
        }

        if (isset($meta['tax_class'])) {
            $tax_class = sanitize_text_field((string) $meta['tax_class']);
            update_post_meta($post_id, '_tax_class', $tax_class);
        }

        if (array_key_exists('base_price_per_person', $meta)) {
            $base_price_per_person = ! empty($meta['base_price_per_person']) ? 'yes' : 'no';
            update_post_meta($post_id, '_sbdp_base_price_per_person', $base_price_per_person);

            // Repair earlier saves that incorrectly wrote the checkbox string into the
            // numeric per-person amount field.
            $per_person_meta = get_post_meta($post_id, '_sbdp_price_per_person', true);
            if (! is_numeric($per_person_meta) && in_array(strtolower((string) $per_person_meta), ['yes', 'no', 'true', 'false', 'on', 'off'], true)) {
                delete_post_meta($post_id, '_sbdp_price_per_person');
            }
        }

        if (array_key_exists('fixed_fee_per_person', $meta)) {
            update_post_meta($post_id, '_sbdp_fixed_fee_per_person', ! empty($meta['fixed_fee_per_person']) ? 'yes' : 'no');
        }
    }

    /**
     * Mirror the base price into WooCommerce price fields so the storefront stays in sync.
     *
     * @param array<string, mixed> $meta
     */
    private static function sync_product_prices(int $post_id, array $meta): void
    {
        if (! array_key_exists('base_price', $meta)) {
            return;
        }

        $raw = $meta['base_price'];
        $raw = self::normalize_decimal_input($raw);
        if ($raw === '' || $raw === null) {
            delete_post_meta($post_id, '_price');
            delete_post_meta($post_id, '_regular_price');
            delete_post_meta($post_id, '_sale_price');
            delete_post_meta($post_id, '_sale_price_dates_from');
            delete_post_meta($post_id, '_sale_price_dates_to');

            return;
        }

        if (! is_numeric($raw)) {
            return;
        }

        $price = (float) $raw;
        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        $formatted = number_format($price, (int) $decimals, '.', '');

        update_post_meta($post_id, '_price', $formatted);
        update_post_meta($post_id, '_regular_price', $formatted);
        delete_post_meta($post_id, '_sale_price');
        delete_post_meta($post_id, '_sale_price_dates_from');
        delete_post_meta($post_id, '_sale_price_dates_to');
    }

    private static function has_valid_meta_nonce(): bool
    {
        return isset($_POST['sbdp_bookable_meta_nonce']) && wp_verify_nonce(wp_unslash($_POST['sbdp_bookable_meta_nonce']), 'sbdp_bookable_meta'); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    private static function persist_posted_meta(int $post_id, ?WC_Product $product = null): void
    {
        $raw = isset($_POST['sbdp_bookable']) && is_array($_POST['sbdp_bookable'])
            ? wp_unslash($_POST['sbdp_bookable'])
            : [];// phpcs:ignore WordPress.Security.NonceVerification.Missing

        // Some Woo save paths can be inconsistent with multi-select payloads.
        // Preserve direct resource selectors as a fallback instead of silently dropping them.
        if ((! isset($raw['resource_ids']) || ! is_array($raw['resource_ids'])) && isset($_POST['_sbdp_resource_ids'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $raw['resource_ids'] = wp_unslash($_POST['_sbdp_resource_ids']); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        $sanitized = self::sanitize_meta_payload($raw);

        foreach ($sanitized as $key => $value) {
            $meta_key = self::META_PREFIX . $key;
            if (null === $value || '' === $value || [] === $value) {
                delete_post_meta($post_id, $meta_key);
            } else {
                update_post_meta($post_id, $meta_key, $value);
            }
        }

        self::sync_legacy_meta($post_id, $sanitized);
        self::sync_product_prices($post_id, $sanitized);
        self::sync_product_object_meta($product, $sanitized);
    }

    /**
     * Keep the live Woo product object aligned with direct meta writes during the same save request.
     * Without this, Woo can persist stale in-memory values after our direct `update_post_meta()` calls.
     *
     * @param array<string, mixed> $meta
     */
    private static function sync_product_object_meta(?WC_Product $product, array $meta): void
    {
        if (! $product instanceof WC_Product) {
            return;
        }

        $post_id = $product->get_id();
        if ($post_id <= 0) {
            return;
        }

        foreach ($meta as $key => $value) {
            $meta_key = self::META_PREFIX . $key;
            if ($value === null || $value === '' || $value === []) {
                $product->delete_meta_data($meta_key);
            } else {
                $product->update_meta_data($meta_key, $value);
            }
        }

        if (array_key_exists('base_price', $meta)) {
            $raw = self::normalize_decimal_input($meta['base_price']);
            if ($raw === '' || $raw === null || ! is_numeric($raw)) {
                $product->set_regular_price('');
                $product->set_price('');
                $product->set_sale_price('');
            } else {
                $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
                $formatted = number_format((float) $raw, (int) $decimals, '.', '');
                $product->set_regular_price($formatted);
                $product->set_price($formatted);
                $product->set_sale_price('');
            }
        }

        if (array_key_exists('tax_class', $meta)) {
            $product->set_tax_status('taxable');
            $product->set_tax_class(sanitize_text_field((string) $meta['tax_class']));
        }
    }

    public static function maybe_repair_corrupted_base_prices(): void
    {
        if (get_option(self::OPTION_BASE_PRICE_REPAIR, false)) {
            return;
        }

        if (! function_exists('get_posts')) {
            return;
        }

        $product_ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_sbdp_base_price_per_person',
                    'value'   => [ '1', 'yes', 'true', 'on' ],
                    'compare' => 'IN',
                ],
            ],
        ]);

        if (! is_array($product_ids)) {
            update_option(self::OPTION_BASE_PRICE_REPAIR, 'done', false);
            return;
        }

        foreach ($product_ids as $product_id) {
            $product_id = (int) $product_id;
            if ($product_id <= 0) {
                continue;
            }

            $base_price = get_post_meta($product_id, '_sbdp_base_price', true);
            $base_value = self::normalize_decimal_input((string) $base_price);
            if (is_numeric($base_value) && (float) $base_value > 0.0) {
                continue;
            }

            $candidate = self::recover_base_price_candidate($product_id);
            if ($candidate <= 0.0) {
                continue;
            }

            $formatted = self::format_decimal($candidate);
            update_post_meta($product_id, '_sbdp_base_price', $formatted);

            if (get_post_meta($product_id, '_price', true) === '') {
                update_post_meta($product_id, '_price', $formatted);
            }

            if (get_post_meta($product_id, '_regular_price', true) === '') {
                update_post_meta($product_id, '_regular_price', $formatted);
            }
        }

        update_option(self::OPTION_BASE_PRICE_REPAIR, 'done', false);
    }

    private static function recover_base_price_candidate(int $product_id): float
    {
        $sources = [
            get_post_meta($product_id, '_price', true),
            get_post_meta($product_id, '_regular_price', true),
        ];

        foreach ($sources as $source) {
            $normalized = self::normalize_decimal_input((string) $source);
            if (is_numeric($normalized) && (float) $normalized > 0.0) {
                return round((float) $normalized, 2);
            }
        }

        return 0.0;
    }

    private static function format_decimal(float $amount): string
    {
        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        return number_format($amount, (int) $decimals, '.', '');
    }
    /**
     * Sanitize allowed days array.
     */
    private static function sanitize_days($value): array
    {
        if (! is_array($value)) {
                                              return [];
        }
        $valid   = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];
                                              $cleaned = [];
        foreach ($value as $day) {
            $day = strtolower(sanitize_text_field($day));
            if (in_array($day, $valid, true)) {
                $cleaned[] = $day;
            }
        }

        return array_values(array_unique($cleaned));
    }

    private static function resolve_resource_ids(int $product_id): array
    {
        if ($product_id <= 0) {
                                              return [];
        }

        if (class_exists(ProductMeta::class)) {
                                              return ProductMeta::get_resource_ids($product_id);
        }

        if (class_exists('\SBDP_Product_Meta')) {
                                              return \SBDP_Product_Meta::get_resource_ids($product_id);
        }

        $stored = get_post_meta($product_id, '_sbdp_resource_ids', true);
                                              $ids    = self::sanitize_resource_ids($stored);
        if ($ids === []) {
            $primary = get_post_meta($product_id, '_sbdp_resource_id', true);
            if ($primary) {
                $ids = self::sanitize_resource_ids([ $primary ]);
            }
        }

        return $ids;
    }

    /**
     * @param mixed $value Raw resource ids.
     * @return int[]
     */
    private static function sanitize_resource_ids($value): array
    {
        if (class_exists(ProductMeta::class)) {
                                              return ProductMeta::sanitize_resource_ids($value);
        }

        if (class_exists('\SBDP_Product_Meta')) {
                                              return \SBDP_Product_Meta::sanitize_resource_ids($value);
        }

        if (empty($value)) {
                                              return [];
        }

        if (! is_array($value)) {
                                              $value = [ $value ];
        }

        return array_values(array_filter(array_map(static function ($id) {
                                                                       return (int) $id;
        }, $value), static function ($id) {
                             return $id > 0;
        }));
    }

    /**
     * @param mixed $value Raw product ids.
     * @return int[]
     */
    private static function sanitize_product_ids($value): array
    {
        if (empty($value)) {
            return [];
        }

        if (! is_array($value)) {
            $value = [ $value ];
        }

        $ids = array_values(array_filter(array_map(static function ($id) {
            return (int) $id;
        }, $value), static function ($id) {
            return $id > 0;
        }));

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, array{id:int,title:string}>
     */
    private static function get_resource_options(): array
    {
        $posts = get_posts([
                'post_type'      => 'bookable_resource',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);
        if (! is_array($posts)) {
            return [];
        }

        $options = [];
        foreach ($posts as $resource_post) {
            if (! $resource_post instanceof WP_Post) {
                continue;
            }

            $options[] = [
            'id'    => (int) $resource_post->ID,
            'title' => ResourceMeta::get_admin_label((int) $resource_post->ID),
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array{id:int,title:string}>
     */
    private static function get_combi_options(int $exclude_id = 0): array
    {
        if (! function_exists('wc_get_products')) {
            return [];
        }

        $products = wc_get_products([
            'status'  => 'publish',
            'limit'   => -1,
            'type'    => self::get_product_type(),
            'exclude' => $exclude_id > 0 ? [ $exclude_id ] : [],
            'orderby' => 'title',
            'order'   => 'ASC',
            'return'  => 'objects',
        ]);

        if (! is_array($products) || empty($products)) {
            return [];
        }

        $options = [];
        foreach ($products as $product) {
            if (! $product instanceof \WC_Product) {
                continue;
            }

            $product_id = $product->get_id();
            if ($product_id <= 0) {
                continue;
            }

            $title = $product->get_name();
            if ($title === '') {
                continue;
            }

            $price = $product->get_price_html();
            if ($price) {
                /* translators: %1$s: product title, %2$s: formatted price */
                $title = sprintf(__('%1$s - %2$s', 'sbdp'), $title, wp_strip_all_tags($price));
            }

            $options[] = [
                'id'    => (int) $product_id,
                'title' => $title,
            ];
        }

        return $options;
    }

    private static function sanitize_people_types($value): array
    {
        $clean = [];
        if (! is_array($value)) {
            return $clean;
        }
        foreach ($value as $row) {
            $label = isset($row['label']) ? sanitize_text_field($row['label']) : '';
            if (trim($label) === '') {
                continue;
            }
            $clean[] = [
                'label' => $label,
                'price' => isset($row['price']) && $row['price'] !== '' ? (float) $row['price'] : '',
            ];
        }

        return $clean;
    }

    private static function sanitize_extra_costs($value): array
    {
        $clean = [];
        if (! is_array($value)) {
            return $clean;
        }

        foreach ($value as $row) {
            if (empty($row['label'])) {
                continue;
            }
            $clean[] = [
                'label'        => sanitize_text_field($row['label']),
                'amount'       => isset($row['amount']) && $row['amount'] !== '' ? (float) $row['amount'] : '',
                'multiply_by'  => isset($row['multiply_by']) ? sanitize_text_field($row['multiply_by']) : 'booking',
            ];
        }

        return $clean;
    }

    private static function sanitize_advanced_rules($value): array
    {
        $clean = [];
        if (! is_array($value)) {
            return $clean;
        }

        $allowed_conditions = [ 'date', 'weekday', 'month', 'duration', 'people' ];

        foreach ($value as $row) {
            if (empty($row['condition'])) {
                continue;
            }
            $condition = sanitize_text_field($row['condition']);
            if (! in_array($condition, $allowed_conditions, true)) {
                continue;
            }

            $price = isset($row['price']) ? self::normalize_decimal_input((string) $row['price']) : '';
            $clean[] = [
                'condition' => $condition,
                'value'     => isset($row['value']) ? sanitize_text_field($row['value']) : '',
                'price'     => $price !== '' && is_numeric($price) ? (float) $price : '',
            ];
        }

        return $clean;
    }

    private static function sanitize_availability($value): array
    {
        $valid_days = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];
        $clean      = [];
        if (! is_array($value)) {
            return self::get_default_availability_template();
        }

        foreach ($valid_days as $day) {
            $clean[ $day ] = [];
            if (empty($value[ $day ]) || ! is_array($value[ $day ])) {
                continue;
            }
            foreach ($value[ $day ] as $slot) {
                $start = isset($slot['start']) ? sanitize_text_field($slot['start']) : '';
                $end   = isset($slot['end']) ? sanitize_text_field($slot['end']) : '';
                if (! $start || ! $end) {
                    continue;
                }
                $clean[ $day ][] = [ 'start' => $start, 'end' => $end ];
            }
        }

        return $clean;
    }

    private static function sanitize_additional_rules($value): array
    {
        $clean = [];
        if (! is_array($value)) {
            return $clean;
        }

        foreach ($value as $row) {
            if (empty($row['type'])) {
                continue;
            }
            $clean[] = [
                'type'  => sanitize_text_field($row['type']),
                'from'  => isset($row['from']) ? sanitize_text_field($row['from']) : '',
                'to'    => isset($row['to']) ? sanitize_text_field($row['to']) : '',
                'label' => isset($row['label']) ? sanitize_text_field($row['label']) : '',
            ];
        }

        return $clean;
    }

    private static function get_default_availability_template(): array
    {
        $template = [];
        $days     = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];
        foreach ($days as $day) {
            $template[ $day ] = [];
        }
        return $template;
    }

    private static function get_google_maps_api_key(): string
    {
        $key = get_option(self::OPTION_GOOGLE_MAPS_KEY, '');
        return is_string($key) ? trim($key) : '';
    }

    private static function get_i18n_strings(): array
    {
        return [
            'tab_booking'          => __('Booking Settings', 'sbdp'),
            'tab_people'           => __('People Settings', 'sbdp'),
            'tab_pricing'          => __('Pricing & Discounts', 'sbdp'),
            'tab_availability'     => __('Availability', 'sbdp'),
            'add_row'              => __('Add row', 'sbdp'),
            'remove_row'           => __('Remove', 'sbdp'),
            'mon'                  => __('Monday', 'sbdp'),
            'tue'                  => __('Tuesday', 'sbdp'),
            'wed'                  => __('Wednesday', 'sbdp'),
            'thu'                  => __('Thursday', 'sbdp'),
            'fri'                  => __('Friday', 'sbdp'),
            'sat'                  => __('Saturday', 'sbdp'),
            'sun'                  => __('Sunday', 'sbdp'),
            'duplicate_prompt'     => __('Enter the product ID to duplicate booking settings from:', 'sbdp'),
            'duplicate_success'    => __('Settings duplicated.', 'sbdp'),
            'duplicate_failed'     => __('Duplication failed.', 'sbdp'),
            'maps_unavailable'     => __('Add a Google Maps API key under Booking settings to enable the location picker.', 'sbdp'),
        ];
    }

    private static function get_product_type(): string
    {
        if (class_exists('\\BSPModule\\Core\\WooCommerce\\ProductType\\BookableServiceProductType')) {
            return \BSPModule\Core\WooCommerce\ProductType\BookableServiceProductType::PRODUCT_TYPE;
        }

        if (class_exists('\\SBDP_Product_Type') && defined('\\SBDP_Product_Type::PRODUCT_TYPE')) {
            return \SBDP_Product_Type::PRODUCT_TYPE;
        }

        return 'bookable_service';
    }
}
