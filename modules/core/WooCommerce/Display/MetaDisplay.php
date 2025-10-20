<?php

declare(strict_types=1);

namespace BSPModule\Core\WooCommerce\Display;

use BSPModule\Core\Product\ProductMeta;
use DateTimeImmutable;
use Exception;
use WC_Order_Item;
use WC_Order_Item_Product;

final class MetaDisplay {

	private static bool $booted = false;

	/**
	 * @var array<string,string>
	 */
	private const DEFAULT_LABELS = array(
		'sbdp_start'          => 'Starttijd',
		'sbdp_end'            => 'Eindtijd',
		'sbdp_participants'   => 'Deelnemers',
		'sbdp_resource_label' => 'Resource',
	);

	/**
	 * @var array<string,string>
	 */
	private const PRODUCT_LABEL_KEYS = array(
		'sbdp_start'          => 'start',
		'sbdp_end'            => 'end',
		'sbdp_participants'   => 'participants',
		'sbdp_resource_label' => 'resource',
	);

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'append_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( __CLASS__, 'filter_order_meta_label' ), 10, 3 );
		add_filter( 'woocommerce_order_item_display_meta_value', array( __CLASS__, 'filter_order_meta_value' ), 10, 3 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_raw_meta' ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $item_data
	 * @param array<string,mixed>            $cart_item
	 * @return array<int,array<string,mixed>>
	 */
	public static function append_cart_item_data( array $item_data, array $cart_item ): array {
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
			if ( $key === 'sbdp_resource_id' ) {
				continue;
			}

			$formatted = self::format_display_value( $product_id, (string) $key, $value );
			if ( $formatted === '' ) {
				continue;
			}

			$item_data[] = array(
				'key'     => self::resolve_label( $product_id, (string) $key ),
				'value'   => $formatted,
				'display' => $formatted,
			);
		}

		return $item_data;
	}

	/**
	 * @param string        $display_key
	 * @param object        $meta
	 * @param WC_Order_Item $item
	 */
	public static function filter_order_meta_label( $display_key, $meta, $item ): string {
		$product_id = self::get_order_item_product_id( $item );
		$key        = is_object( $meta ) && property_exists( $meta, 'key' ) ? (string) $meta->key : '';

		return self::resolve_label( $product_id, $key );
	}

	/**
	 * @param string        $display_value
	 * @param object        $meta
	 * @param WC_Order_Item $item
	 */
	public static function filter_order_meta_value( $display_value, $meta, $item ): string {
		$product_id = self::get_order_item_product_id( $item );
		$key        = is_object( $meta ) && property_exists( $meta, 'key' ) ? (string) $meta->key : '';
		$value      = is_object( $meta ) && property_exists( $meta, 'value' ) ? $meta->value : null;

		return self::format_display_value( $product_id, $key, $value );
	}

	/**
	 * @param array<int,string> $hidden
	 * @return array<int,string>
	 */
	public static function hide_raw_meta( array $hidden ): array {
		$hidden[] = 'sbdp_meta';
		$hidden[] = 'sbdp_resource_id';
		$hidden[] = '_sbdp_pricing';

		return array_values( array_unique( $hidden ) );
	}

	private static function resolve_label( int $product_id, string $meta_key ): string {
		$product_key = self::PRODUCT_LABEL_KEYS[ $meta_key ] ?? null;
		if ( $product_key !== null ) {
			return ProductMeta::get_label( $product_id, $product_key );
		}

		return self::DEFAULT_LABELS[ $meta_key ] ?? $meta_key;
	}

	/**
	 * @param mixed $value
	 */
	private static function format_display_value( int $product_id, string $key, $value ): string {
		if ( $value === '' || $value === null ) {
			return '';
		}

		if ( $key === 'sbdp_start' || $key === 'sbdp_end' ) {
			return self::format_datetime( (string) $value );
		}

		if ( $key === 'sbdp_participants' ) {
			$count = max( 1, (int) $value );

			return sprintf( _n( '%d deelnemer', '%d deelnemers', $count, 'sbdp' ), $count );
		}

		if ( $key === 'sbdp_resource_label' ) {
			return sanitize_text_field( (string) $value );
		}

		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}

		return '';
	}

	private static function format_datetime( string $iso ): string {
		try {
			$dt = new DateTimeImmutable( $iso );
		} catch ( Exception $exception ) {
			return sanitize_text_field( $iso );
		}

		try {
			$dt = $dt->setTimezone( wp_timezone() );
		} catch ( Exception $exception ) {
			// leave timezone untouched when conversion fails.
		}

		$timestamp = $dt->getTimestamp();
		$date      = wp_date( (string) get_option( 'date_format' ), $timestamp );
		$time      = wp_date( (string) get_option( 'time_format' ), $timestamp );

		return trim( $date . ' ' . $time );
	}

	/**
	 * @param WC_Order_Item|WC_Order_Item_Product|mixed $item
	 */
	private static function get_order_item_product_id( $item ): int {
		if ( $item instanceof WC_Order_Item_Product ) {
			return (int) $item->get_product_id();
		}

		if ( $item instanceof WC_Order_Item && method_exists( $item, 'get_product_id' ) ) {
			return (int) $item->get_product_id();
		}

		if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
			return (int) $item->get_product_id();
		}

		return 0;
	}
}
