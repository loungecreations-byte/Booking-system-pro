<?php
/**
 * Cart and order meta presentation helpers.
 *
 * @package SBDP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBDP_Meta_Display {

    /**
     * Default fallback labels for planner metadata.
     *
     * @var array<string,string>
     */
    private static $default_labels = [
        'sbdp_start'          => 'Starttijd',
        'sbdp_end'            => 'Eindtijd',
        'sbdp_participants'   => 'Deelnemers',
        'sbdp_resource_label' => 'Resource',
    ];

    /**
     * Hook WooCommerce filters.
     */
    public static function init() {
        add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'append_cart_item_data' ], 10, 2 );
        add_filter( 'woocommerce_order_item_display_meta_key', [ __CLASS__, 'filter_order_meta_label' ], 10, 3 );
        add_filter( 'woocommerce_order_item_display_meta_value', [ __CLASS__, 'filter_order_meta_value' ], 10, 3 );
        add_filter( 'woocommerce_hidden_order_itemmeta', [ __CLASS__, 'hide_raw_meta' ] );
    }

    /**
     * Append planner data to the cart item display.
     */
    public static function append_cart_item_data( $item_data, $cart_item ) {
        if ( empty( $cart_item['sbdp_meta'] ) || ! is_array( $cart_item['sbdp_meta'] ) ) {
            return $item_data;
        }

        $product_id = 0;
        if ( isset( $cart_item['product_id'] ) ) {
            $product_id = (int) $cart_item['product_id'];
        } elseif ( ! empty( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_id' ) ) {
            $product_id = (int) $cart_item['data']->get_id();
        }

        foreach ( $cart_item['sbdp_meta'] as $key => $value ) {
            if ( 'sbdp_resource_id' === $key ) {
                continue;
            }
            $formatted = self::format_display_value( $product_id, $key, $value );
            if ( '' === $formatted ) {
                continue;
            }
            $item_data[] = [
                'key'     => self::resolve_label( $product_id, $key ),
                'value'   => $formatted,
                'display' => $formatted,
            ];
        }

        return $item_data;
    }

    /**
     * Adjust order meta labels.
     */
    public static function filter_order_meta_label( $display_key, $meta, $item ) {
        $product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
        return self::resolve_label( $product_id, $meta->key );
    }

    /**
     * Adjust order meta values.
     */
    public static function filter_order_meta_value( $display_value, $meta, $item ) {
        $product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
        return self::format_display_value( $product_id, $meta->key, $meta->value );
    }

    /**
     * Hide raw meta keys from emails/front-end output.
     */
    public static function hide_raw_meta( $hidden ) {
        $hidden[] = 'sbdp_meta';
        $hidden[] = 'sbdp_resource_id';
        $hidden[] = '_sbdp_pricing';
        return array_unique( $hidden );
    }

    /**
     * Resolve a human friendly label for a metadata key.
     */
    private static function resolve_label( $product_id, $key ) {
        if ( class_exists( 'SBDP_Product_Meta' ) ) {
            switch ( $key ) {
                case 'sbdp_start':
                    return SBDP_Product_Meta::get_label( $product_id, 'start' );
                case 'sbdp_end':
                    return SBDP_Product_Meta::get_label( $product_id, 'end' );
                case 'sbdp_participants':
                    return SBDP_Product_Meta::get_label( $product_id, 'participants' );
                case 'sbdp_resource_label':
                    return SBDP_Product_Meta::get_label( $product_id, 'resource' );
            }
        }
        return isset( self::$default_labels[ $key ] ) ? self::$default_labels[ $key ] : $key;
    }

    /**
     * Format meta values for display.
     */
    private static function format_display_value( $product_id, $key, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- kept for consistency
        if ( '' === $value || null === $value ) {
            return '';
        }

        if ( in_array( $key, [ 'sbdp_start', 'sbdp_end' ], true ) ) {
            return self::format_datetime( $value );
        }

        if ( 'sbdp_participants' === $key ) {
            $count = max( 1, (int) $value );
            return sprintf( _n( '%d deelnemer', '%d deelnemers', $count, 'sbdp' ), $count );
        }

        if ( 'sbdp_resource_label' === $key ) {
            return sanitize_text_field( $value );
        }

        return is_string( $value ) ? sanitize_text_field( $value ) : $value;
    }

    /**
     * Format ISO timestamps into localised strings.
     */
    private static function format_datetime( $iso ) {
        try {
            $dt = new DateTimeImmutable( $iso );
        } catch ( Exception $e ) {
            return sanitize_text_field( $iso );
        }

        try {
            $dt = $dt->setTimezone( wp_timezone() );
        } catch ( Exception $e ) {
            // leave timezone untouched.
        }

        $timestamp = $dt->getTimestamp();
        $date      = wp_date( get_option( 'date_format' ), $timestamp );
        $time      = wp_date( get_option( 'time_format' ), $timestamp );

        return trim( $date . ' ' . $time );
    }
}

