<?php
namespace SBDP\Admin\Bookable;

use WP_Post;
use WP_REST_Request;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Booking product admin meta interface.
 */
class SBDP_Admin_Bookable_Meta {
    private const META_PREFIX = '_sbdp_';
    private const OPTION_GOOGLE_MAPS_KEY = 'sbdp_google_maps_api_key';
    private const AJAX_ACTION = 'sbdp_duplicate_booking_meta';

    /**
     * Register admin hooks.
     */
    public static function init(): void {
        add_action( 'add_meta_boxes_product', [ __CLASS__, 'register_meta_box' ] );
        add_action( 'save_post_product', [ __CLASS__, 'save_meta' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'handle_duplicate_meta' ] );
    }

    /**
     * Register the modular meta box when editing bookable products.
     */
    public static function register_meta_box( WP_Post $post ): void {
        if ( ! self::should_render_for_post( $post->ID ) ) {
            return;
        }

        add_meta_box(
            'sbdp-bookable-meta',
            __( 'Booking Planner Settings', 'sbdp' ),
            [ __CLASS__, 'render_meta_box' ],
            'product',
            'normal',
            'high'
        );
    }

    /**
     * Determine whether the UI should load for the current post.
     */
    private static function should_render_for_post( int $post_id ): bool {
        if ( ! $post_id ) {
            return self::maybe_is_new_bookable_request();
        }

        if ( ! function_exists( 'wc_get_product' ) ) {
            return false;
        }

        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            return false;
        }

        return $product->get_type() === self::get_product_type();
    }

    /**
     * For new products, default to showing when the request targets our type.
     */
    private static function maybe_is_new_bookable_request(): bool {
        $requested = isset( $_GET['product_type'] ) ? sanitize_text_field( wp_unslash( $_GET['product_type'] ) ) : '';// phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! $requested && isset( $_GET['type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $requested = sanitize_text_field( wp_unslash( $_GET['type'] ) );
        }

        return self::get_product_type() === $requested;
    }

    /**
     * Enqueue admin assets.
     */
    public static function enqueue_assets( string $hook_suffix ): void {
        if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'product' !== $screen->post_type ) {
            return;
        }

        $post_id      = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $should_render = $post_id ? self::should_render_for_post( $post_id ) : self::maybe_is_new_bookable_request();

        if ( ! $should_render ) {
            return;
        }

        wp_enqueue_style(
            'sbdp-admin-bookable',
            SBDP_URL . 'assets/admin-bookable.css',
            [],
            SBDP_VER
        );

        wp_register_script(
            'sbdp-admin-bookable',
            SBDP_URL . 'assets/admin-bookable.js',
            [ 'jquery', 'wp-i18n' ],
            SBDP_VER,
            true
        );

        $meta = $post_id ? self::get_meta( $post_id ) : self::get_default_meta();

        wp_localize_script(
            'sbdp-admin-bookable',
            'SBDP_BOOKABLE',
            [
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'ajaxAction'  => self::AJAX_ACTION,
                'ajaxNonce'   => wp_create_nonce( self::AJAX_ACTION ),
                'productId'   => $post_id,
                'meta'        => $meta,
                'mapsApiKey'  => self::get_google_maps_api_key(),
                'restUrlBase' => esc_url_raw( rest_url( 'sbdp/v1/bookable-meta/' ) ),
                'restNonce'   => wp_create_nonce( 'wp_rest' ),
                'i18n'        => self::get_i18n_strings(),
            ]
        );

        wp_set_script_translations( 'sbdp-admin-bookable', 'sbdp' );
        wp_enqueue_script( 'sbdp-admin-bookable' );
    }

    /**
     * Render the main meta interface.
     */
    public static function render_meta_box( WP_Post $post ): void {
        $meta = self::get_meta( $post->ID );

        wp_nonce_field( 'sbdp_bookable_meta', 'sbdp_bookable_meta_nonce' );

        include __DIR__ . '/views/meta-box.php';
    }

    /**
     * Persist booking meta.
     */
    public static function save_meta( int $post_id, WP_Post $post ): void {
        if ( ! isset( $_POST['sbdp_bookable_meta_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['sbdp_bookable_meta_nonce'] ), 'sbdp_bookable_meta' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $product_type = isset( $_POST['product-type'] ) ? sanitize_text_field( wp_unslash( $_POST['product-type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! $product_type && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post_id );
            $product_type = $product ? $product->get_type() : '';
        }

        if ( self::get_product_type() !== $product_type ) {
            return;
        }

        $raw = isset( $_POST['sbdp_bookable'] ) && is_array( $_POST['sbdp_bookable'] )
            ? wp_unslash( $_POST['sbdp_bookable'] )
            : [];// phpcs:ignore WordPress.Security.NonceVerification.Missing

        $sanitized = self::sanitize_meta_payload( $raw );

        foreach ( $sanitized as $key => $value ) {
            $meta_key = self::META_PREFIX . $key;
            if ( null === $value || '' === $value || [] === $value ) {
                delete_post_meta( $post_id, $meta_key );
            } else {
                update_post_meta( $post_id, $meta_key, $value );
            }
        }

        self::sync_legacy_meta( $post_id, $sanitized );
    }

    /**
     * AJAX duplication handler.
     */
    public static function handle_duplicate_meta(): void {
        check_ajax_referer( self::AJAX_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'sbdp' ) ], 403 );
        }

        $source_id = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
        $target_id = isset( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;

        if ( ! $source_id || ! $target_id ) {
            wp_send_json_error( [ 'message' => __( 'Missing product reference.', 'sbdp' ) ], 400 );
        }

        $source_meta = self::get_meta( $source_id );
        if ( empty( $source_meta ) ) {
            wp_send_json_error( [ 'message' => __( 'Source product has no booking data.', 'sbdp' ) ], 404 );
        }

        foreach ( $source_meta as $key => $value ) {
            update_post_meta( $target_id, self::META_PREFIX . $key, $value );
        }

        self::sync_legacy_meta( $target_id, $source_meta );

        wp_send_json_success( [
            'meta'    => $source_meta,
            'message' => __( 'Booking settings duplicated successfully.', 'sbdp' ),
        ] );
    }

    /**
     * Public helper for REST responses.
     */
    public static function get_meta( int $product_id ): array {
        $defaults = self::get_default_meta();
        $output   = $defaults;

        foreach ( array_keys( $defaults ) as $key ) {
            $meta_key = self::META_PREFIX . $key;
            $stored   = get_post_meta( $product_id, $meta_key, true );
            if ( '' === $stored || null === $stored ) {
                continue;
            }

            if ( is_array( $defaults[ $key ] ) ) {
                $output[ $key ] = is_array( $stored ) ? $stored : []; // fall back to empty array.
                continue;
            }

            $output[ $key ] = $stored;
        }

        return $output;
    }

    /**
     * Prepare REST payload.
     */
    public static function prepare_meta_for_rest( int $product_id ): array {
        $meta = self::get_meta( $product_id );

        foreach ( $meta as $key => $value ) {
            if ( is_bool( $value ) ) {
                $meta[ $key ] = $value;
            } elseif ( is_numeric( $value ) ) {
                $meta[ $key ] = $value + 0;
            }
        }

        return $meta;
    }

    /**
     * Default meta payload.
     */
    private static function get_default_meta(): array {
        $today = gmdate( 'Y-m-d' );
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
            'booking_requires_confirmation'=> false,
            'booking_allow_cancellation'   => true,
            'booking_location'             => '',
            'booking_sync_google_calendar' => false,
            'people_enabled'               => false,
            'people_min'                   => 1,
            'people_max'                   => 10,
            'people_count_as_booking'      => false,
            'people_type_enabled'          => false,
            'people_types'                 => [
                [
                    'label' => __( 'Adults', 'sbdp' ),
                    'price' => '',
                ],
                [
                    'label' => __( 'Children', 'sbdp' ),
                    'price' => '',
                ],
            ],
            'base_price'                   => '',
            'base_price_per_person'        => false,
            'fixed_fee'                    => '',
            'fixed_fee_per_person'         => false,
            'last_minute_discount'         => '',
            'last_minute_days_before'      => '',
            'extra_costs'                  => [],
            'advanced_price_rules'         => [],
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
    private static function sanitize_meta_payload( array $raw ): array {
        $defaults = self::get_default_meta();
        $clean    = [];

        foreach ( $defaults as $key => $default ) {
            if ( ! array_key_exists( $key, $raw ) ) {
                $clean[ $key ] = $default;
                continue;
            }

            $value = $raw[ $key ];

            switch ( $key ) {
                case 'booking_duration_type':
                    $allowed = [ 'minutes', 'hours', 'days', 'months' ];
                    $value   = in_array( $value, $allowed, true ) ? $value : $default;
                    break;
                case 'booking_allowed_start_days':
                    $value = self::sanitize_days( $value );
                    break;
                case 'booking_terms_max_per_unit':
                case 'booking_min_duration':
                case 'booking_max_duration':
                case 'booking_min_advance':
                case 'booking_max_advance':
                case 'booking_buffer_time':
                case 'last_minute_days_before':
                    $value = max( 0, absint( $value ) );
                    break;
                case 'booking_default_start_date':
                case 'booking_checkin':
                case 'booking_checkout':
                case 'booking_default_start_time':
                    $value = sanitize_text_field( $value );
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
                    $value = ! empty( $value ) && 'yes' === $value || '1' === $value || true === $value;
                    break;
                case 'booking_location':
                case 'exclusions':
                case 'permalink_override':
                    $value = sanitize_text_field( $value );
                    break;
                case 'people_min':
                case 'people_max':
                    $value = max( 0, absint( $value ) );
                    break;
                case 'base_price':
                case 'fixed_fee':
                case 'last_minute_discount':
                    $value = is_numeric( $value ) ? (float) $value : ( '' === $value ? '' : sanitize_text_field( $value ) );
                    break;
                case 'people_types':
                    $value = self::sanitize_people_types( $value );
                    break;
                case 'extra_costs':
                    $value = self::sanitize_extra_costs( $value );
                    break;
                case 'advanced_price_rules':
                    $value = self::sanitize_advanced_rules( $value );
                    break;
                case 'default_availability':
                    $value = self::sanitize_availability( $value );
                    break;
                case 'additional_rules':
                    $value = self::sanitize_additional_rules( $value );
                    break;
                default:
                    $value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value );
                    break;
            }

            $clean[ $key ] = $value;
        }

        if ( $clean['people_min'] > $clean['people_max'] && $clean['people_max'] > 0 ) {
            $clean['people_max'] = $clean['people_min'];
        }

        return $clean;
    }

    /**
     * Synchronise key meta to legacy keys used elsewhere in the stack.
     */
    private static function sync_legacy_meta( int $post_id, array $meta ): void {
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
            'base_price_per_person'        => '_sbdp_price_per_person',
            'fixed_fee'                    => '_sbdp_base_fee',
            'last_minute_discount'         => '_sbdp_last_minute_discount',
            'extra_costs'                  => '_sbdp_extra_costs',
        ];

        foreach ( $map as $new_key => $legacy_key ) {
            if ( ! array_key_exists( $new_key, $meta ) ) {
                continue;
            }

            $value = $meta[ $new_key ];
            if ( is_bool( $value ) ) {
                $value = $value ? 'yes' : 'no';
            }

            if ( '' === $value || [] === $value || null === $value ) {
                delete_post_meta( $post_id, $legacy_key );
            } else {
                update_post_meta( $post_id, $legacy_key, $value );
            }
        }

        if ( isset( $meta['booking_min_duration'] ) ) {
            update_post_meta( $post_id, '_sbdp_duration', absint( $meta['booking_min_duration'] ) );
        }

        if ( isset( $meta['default_availability'] ) ) {
            update_post_meta( $post_id, '_sbdp_default_hours', wp_json_encode( $meta['default_availability'] ) );
        }

        if ( isset( $meta['additional_rules'] ) ) {
            update_post_meta( $post_id, '_sbdp_availability_rules', wp_json_encode( $meta['additional_rules'] ) );
        }
    }

    /**
     * Sanitize allowed days array.
     */
    private static function sanitize_days( $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $valid   = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];
        $cleaned = [];
        foreach ( $value as $day ) {
            $day = strtolower( sanitize_text_field( $day ) );
            if ( in_array( $day, $valid, true ) ) {
                $cleaned[] = $day;
            }
        }

        return array_values( array_unique( $cleaned ) );
    }

    private static function sanitize_people_types( $value ): array {
        $clean = [];
        if ( ! is_array( $value ) ) {
            return $clean;
        }

        foreach ( $value as $row ) {
            $label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
            if ( trim( $label ) === '' ) {
                continue;
            }
            $clean[] = [
                'label' => $label,
                'price' => isset( $row['price'] ) && $row['price'] !== '' ? (float) $row['price'] : '',
            ];
        }

        return $clean;
    }

    private static function sanitize_extra_costs( $value ): array {
        $clean = [];
        if ( ! is_array( $value ) ) {
            return $clean;
        }

        foreach ( $value as $row ) {
            if ( empty( $row['label'] ) ) {
                continue;
            }
            $clean[] = [
                'label'        => sanitize_text_field( $row['label'] ),
                'amount'       => isset( $row['amount'] ) && $row['amount'] !== '' ? (float) $row['amount'] : '',
                'multiply_by'  => isset( $row['multiply_by'] ) ? sanitize_text_field( $row['multiply_by'] ) : 'booking',
            ];
        }

        return $clean;
    }

    private static function sanitize_advanced_rules( $value ): array {
        $clean = [];
        if ( ! is_array( $value ) ) {
            return $clean;
        }

        foreach ( $value as $row ) {
            if ( empty( $row['condition'] ) ) {
                continue;
            }
            $clean[] = [
                'condition' => sanitize_text_field( $row['condition'] ),
                'value'     => isset( $row['value'] ) ? sanitize_text_field( $row['value'] ) : '',
                'price'     => isset( $row['price'] ) && $row['price'] !== '' ? (float) $row['price'] : '',
            ];
        }

        return $clean;
    }

    private static function sanitize_availability( $value ): array {
        $valid_days = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];
        $clean      = [];
        if ( ! is_array( $value ) ) {
            return self::get_default_availability_template();
        }

        foreach ( $valid_days as $day ) {
            $clean[ $day ] = [];
            if ( empty( $value[ $day ] ) || ! is_array( $value[ $day ] ) ) {
                continue;
            }
            foreach ( $value[ $day ] as $slot ) {
                $start = isset( $slot['start'] ) ? sanitize_text_field( $slot['start'] ) : '';
                $end   = isset( $slot['end'] ) ? sanitize_text_field( $slot['end'] ) : '';
                if ( ! $start || ! $end ) {
                    continue;
                }
                $clean[ $day ][] = [ 'start' => $start, 'end' => $end ];
            }
        }

        return $clean;
    }

    private static function sanitize_additional_rules( $value ): array {
        $clean = [];
        if ( ! is_array( $value ) ) {
            return $clean;
        }

        foreach ( $value as $row ) {
            if ( empty( $row['type'] ) ) {
                continue;
            }
            $clean[] = [
                'type'  => sanitize_text_field( $row['type'] ),
                'from'  => isset( $row['from'] ) ? sanitize_text_field( $row['from'] ) : '',
                'to'    => isset( $row['to'] ) ? sanitize_text_field( $row['to'] ) : '',
                'label' => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
            ];
        }

        return $clean;
    }

    private static function get_default_availability_template(): array {
        $template = [];
        $days     = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];
        foreach ( $days as $day ) {
            $template[ $day ] = [];
        }
        return $template;
    }

    private static function get_google_maps_api_key(): string {
        $key = get_option( self::OPTION_GOOGLE_MAPS_KEY, '' );
        return is_string( $key ) ? trim( $key ) : '';
    }

    private static function get_i18n_strings(): array {
        return [
            'tab_booking'          => __( 'Booking Settings', 'sbdp' ),
            'tab_people'           => __( 'People Settings', 'sbdp' ),
            'tab_pricing'          => __( 'Pricing & Discounts', 'sbdp' ),
            'tab_availability'     => __( 'Availability', 'sbdp' ),
            'add_row'              => __( 'Add row', 'sbdp' ),
            'remove_row'           => __( 'Remove', 'sbdp' ),
            'mon'                  => __( 'Monday', 'sbdp' ),
            'tue'                  => __( 'Tuesday', 'sbdp' ),
            'wed'                  => __( 'Wednesday', 'sbdp' ),
            'thu'                  => __( 'Thursday', 'sbdp' ),
            'fri'                  => __( 'Friday', 'sbdp' ),
            'sat'                  => __( 'Saturday', 'sbdp' ),
            'sun'                  => __( 'Sunday', 'sbdp' ),
            'duplicate_prompt'     => __( 'Enter the product ID to duplicate booking settings from:', 'sbdp' ),
            'duplicate_success'    => __( 'Settings duplicated.', 'sbdp' ),
            'duplicate_failed'     => __( 'Duplication failed.', 'sbdp' ),
            'maps_unavailable'     => __( 'Add a Google Maps API key under Booking settings to enable the location picker.', 'sbdp' ),
        ];
    }

    private static function get_product_type(): string {
        if ( class_exists( '\\BSPModule\\Core\\WooCommerce\\ProductType\\BookableServiceProductType' ) ) {
            return \BSPModule\Core\WooCommerce\ProductType\BookableServiceProductType::PRODUCT_TYPE;
        }

        if ( class_exists( '\\SBDP_Product_Type' ) && defined( '\\SBDP_Product_Type::PRODUCT_TYPE' ) ) {
            return \SBDP_Product_Type::PRODUCT_TYPE;
        }

        return 'bookable_service';
    }
}