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
		'sbdp_date'           => 'Datum',
		'sbdp_time'           => 'Tijd',
		'sbdp_start'          => 'Starttijd',
		'sbdp_end'            => 'Eindtijd',
		'sbdp_participants'   => 'Deelnemers',
		'sbdp_resource_label' => 'Resource',
		'sbdp_display_unit_price' => 'Prijs p.p. (incl. btw)',
		'sbdp_display_total'      => 'Regeltotaal (incl. btw)',
	);

	/**
	 * @var array<string,string>
	 */
	private const PRODUCT_LABEL_KEYS = array(
		'sbdp_date'           => 'date',
		'sbdp_time'           => 'time',
		'sbdp_start'          => 'start',
		'sbdp_end'            => 'end',
		'sbdp_participants'   => 'participants',
		'sbdp_resource_label' => 'resource',
	);

	/**
	 * Metadata that is allowed to be shown from cart `sbdp_meta` payloads.
	 * Everything else remains runtime/internal truth and should stay hidden.
	 *
	 * @var array<int,string>
	 */
	private const ALLOWED_CART_META_KEYS = array(
		'sbdp_date',
		'sbdp_time',
		'sbdp_start',
		'sbdp_end',
		'sbdp_participants',
		'sbdp_resource_label',
		'sbdp_display_unit_price',
		'sbdp_display_total',
	);

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;
		CheckoutProgramPresenter::init();

		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'append_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( __CLASS__, 'filter_order_meta_label' ), 10, 3 );
		add_filter( 'woocommerce_order_item_display_meta_value', array( __CLASS__, 'filter_order_meta_value' ), 10, 3 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_raw_meta' ) );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'filter_formatted_meta_data' ), 10, 2 );
	}

	/**
	 * Remove empty and runtime-only meta rows before Woo renders wc-item-meta lists.
	 *
	 * @param array<int,mixed> $formatted_meta
	 * @param mixed            $item
	 * @return array<int,mixed>
	 */
	public static function filter_formatted_meta_data( array $formatted_meta, $item ): array {
		return CheckoutProgramPresenter::normalize_formatted_meta_data( $formatted_meta, $item );
	}

	/**
	 * @param array<int,array<string,mixed>> $item_data
	 * @param array<string,mixed>            $cart_item
	 * @return array<int,array<string,mixed>>
	 */
	public static function append_cart_item_data( array $item_data, array $cart_item ): array {
		if ( ! empty( $cart_item['sbdp_summary'] ) && is_array( $cart_item['sbdp_summary'] ) ) {
			return $item_data;
		}

		$program_data = CheckoutProgramPresenter::build_cart_item_data( $cart_item );

		if ( $program_data === array() ) {
			return $item_data;
		}

		return array_merge( $item_data, $program_data );
	}

	/**
	 * @param string        $display_key
	 * @param object        $meta
	 * @param WC_Order_Item $item
	 */
	public static function filter_order_meta_label( $display_key, $meta, $item ): string {
		return CheckoutProgramPresenter::filter_order_meta_label( $display_key, $meta, $item );
	}

	/**
	 * @param string        $display_value
	 * @param object        $meta
	 * @param WC_Order_Item $item
	 */
	public static function filter_order_meta_value( $display_value, $meta, $item ): string {
		return CheckoutProgramPresenter::filter_order_meta_value( $display_value, $meta, $item );
	}

	/**
	 * @param array<int,string> $hidden
	 * @return array<int,string>
	 */
	public static function hide_raw_meta( array $hidden ): array {
		return CheckoutProgramPresenter::filter_hidden_order_itemmeta( $hidden );
	}

	private static function format_program_range( string $startIso, string $endIso ): string {
		if ( $startIso === '' ) {
			return '';
		}

		try {
			$start = new DateTimeImmutable( $startIso );
		} catch ( Exception $exception ) {
			return sanitize_text_field( $startIso );
		}

		try {
			$start = $start->setTimezone( wp_timezone() );
		} catch ( Exception $exception ) {
			// keep parsed timezone when conversion fails.
		}

		$startTime = wp_date( 'H:i', $start->getTimestamp() );
		if ( $endIso === '' ) {
			return $startTime;
		}

		try {
			$end = new DateTimeImmutable( $endIso );
		} catch ( Exception $exception ) {
			return $startTime;
		}

		try {
			$end = $end->setTimezone( wp_timezone() );
		} catch ( Exception $exception ) {
			// keep parsed timezone when conversion fails.
		}

		$endTime = wp_date( 'H:i', $end->getTimestamp() );

		return trim( $startTime . ' - ' . $endTime, ' -' );
	}

	private static function is_runtime_meta_key_hidden( string $key, string $display_key ): bool {
		$allowed_runtime_keys = array(
			'sbdp_start',
			'sbdp_date',
			'sbdp_time',
			'sbdp_participants',
			'sbdp_resource_label',
		);

		$allowed_display_keys = array(
			__( 'Programma', 'sbdp' ),
			__( 'Programma onderdeel', 'sbdp' ),
			__( 'Combi planning', 'sbdp' ),
			__( 'Combi timing', 'sbdp' ),
			__( 'Combi-deal', 'sbdp' ),
			__( 'Combi-deals', 'sbdp' ),
		);

		if ( strpos( $key, '_sbdp_' ) === 0 ) {
			return true;
		}

		if ( strpos( $key, 'sbdp_' ) === 0 && ! in_array( $key, $allowed_runtime_keys, true ) ) {
			return true;
		}

		if ( $display_key !== '' && strpos( $display_key, 'sbdp_' ) === 0 && ! in_array( $display_key, $allowed_runtime_keys, true ) ) {
			return true;
		}

		if ( in_array( $display_key, $allowed_display_keys, true ) ) {
			return false;
		}

		return false;
	}

	private static function resolve_label( int $product_id, string $meta_key ): string {
		$product_key = self::PRODUCT_LABEL_KEYS[ $meta_key ] ?? '';
		if ( $product_key !== '' ) {
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

		if ( $key === 'sbdp_date' || $key === 'sbdp_time' ) {
			return sanitize_text_field( (string) $value );
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

		if ( $key === 'sbdp_display_unit_price' || $key === 'sbdp_display_total' ) {
			$amount = (float) $value;
			if ( function_exists( 'wc_price' ) ) {
				return wp_strip_all_tags( wc_price( $amount ) );
			}

			return number_format_i18n( $amount, 2 );
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
