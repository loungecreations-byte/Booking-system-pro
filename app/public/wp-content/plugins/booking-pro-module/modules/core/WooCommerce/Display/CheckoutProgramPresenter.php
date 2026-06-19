<?php

declare(strict_types=1);

namespace BSPModule\Core\WooCommerce\Display;

use DateTimeImmutable;
use Exception;
use stdClass;
use WC_Order_Item;

final class CheckoutProgramPresenter {

	private static bool $booted = false;

	private const REQUEST_STATUS_LABEL = 'Aanvraag wordt gecontroleerd';
	private const TIME_PENDING_LABEL = 'Tijd wordt bevestigd';
	private const REQUEST_ONLY_LABEL = 'Op aanvraag';
	private const PRICE_ON_REQUEST_LABEL = 'Prijs op aanvraag';
	private const INCLUDED_LABEL = 'Inbegrepen';
	private const NOT_INCLUDED_LABEL = 'Niet inbegrepen';

	/**
	 * Meta keys that are safe to present to customers.
	 *
	 * @var array<int,string>
	 */
	private const CUSTOMER_META_KEYS = array(
		'sbdp_date',
		'sbdp_time',
		'sbdp_start',
		'sbdp_participants',
		'sbdp_resource_label',
		'sbdp_route_intent',
		'sbdp_booking_capability',
	);

	/**
	 * Internal and technical meta keys that must stay hidden from customers.
	 *
	 * @var array<int,string>
	 */
	private const INTERNAL_META_KEYS = array(
		'sbdp_meta',
		'sbdp_resource_id',
		'sbdp_plan_id',
		'sbdp_plan_day',
		'sbdp_plan_slot',
		'sbdp_plan_date',
		'sbdp_pricing_source',
		'sbdp_canonical_participants',
		'sbdp_quantity',
		'sbdp_calculated_price',
		'sbdp_authoritative_total',
		'sbdp_estimated_total',
		'sbdp_total_kind',
		'sbdp_pricing',
		'sbdp_summary',
		'sbdp_plan_item',
		'sbdp_planner_input',
		'sbdp_plan_item_key',
		'_sbdp_pricing',
		'_sbdp_plan_aggregate',
		'_sbdp_plan_item',
		'_sbdp_planner_input',
		'_sbdp_plan_item_key',
		'sbdp_end',
	);

	/**
	 * Labels to use in customer-facing order/cart summaries.
	 *
	 * @var array<string,string>
	 */
	private const CUSTOMER_LABELS = array(
		'sbdp_date' => 'Datum',
		'sbdp_time' => 'Tijd',
		'sbdp_start' => 'Tijd',
		'sbdp_participants' => 'Aantal personen',
		'sbdp_resource_label' => 'Locatie',
		'sbdp_route_intent' => 'Status',
		'sbdp_booking_capability' => 'Status',
	);

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'woocommerce_checkout_before_order_review', array( __CLASS__, 'render_checkout_program_block' ), 8 );

		// Move coupon toggle into the program block (after totals) instead of above the form.
		remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
	}

	public static function render_checkout_program_block(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return;
		}

		$items = WC()->cart->get_cart();
		if ( empty( $items ) ) {
			return;
		}

		$program_items = self::build_checkout_program_items( $items );
		if ( $program_items === array() ) {
			return;
		}

		$shared_date = self::shared_value( $program_items, 'date_label' );
		$shared_participants = self::shared_value( $program_items, 'participants_label' );

		echo '<section class="ddb-commercial-status-card ddb-checkout-program">';
		echo '<div class="ddb-commercial-status-card__header">';
		echo '<p class="ddb-commercial-status-card__eyebrow">' . esc_html__( 'Jouw dagprogramma', 'sbdp' ) . '</p>';
		echo '<h2 class="ddb-commercial-status-card__title">' . esc_html__( 'Overzicht van jullie dag', 'sbdp' ) . '</h2>';
		echo '</div>';

		if ( $shared_date !== '' || $shared_participants !== '' ) {
			echo '<div class="ddb-checkout-program__summary">';
			if ( $shared_date !== '' ) {
				echo '<span class="ddb-checkout-program__summary-item">' . esc_html( $shared_date ) . '</span>';
			}
			if ( $shared_participants !== '' ) {
				echo '<span class="ddb-checkout-program__summary-item">' . esc_html( $shared_participants ) . '</span>';
			}
			echo '</div>';
		}

		echo '<ol class="ddb-checkout-program__timeline">';
		foreach ( $program_items as $item ) {
			echo '<li class="ddb-checkout-program__step">';
			echo '<div class="ddb-checkout-program__step-head-wrapper">';
			echo '<div class="ddb-checkout-program__step-head">';
			if ( ! empty( $item['time_label'] ) ) {
				echo '<span class="ddb-checkout-program__time">' . esc_html( (string) $item['time_label'] ) . '</span>';
			}
			echo '<strong class="ddb-checkout-program__title">' . esc_html( (string) $item['title'] ) . '</strong>';
			echo '</div>';

			$line_display = self::resolve_line_display( $item );
			if ( isset( $item['qty'] ) && $item['qty'] > 1 || $line_display !== '' ) {
				echo '<div class="ddb-checkout-program__step-cost">';
				if ( isset( $item['qty'] ) && $item['qty'] > 1 ) {
					echo '<span class="ddb-checkout-program__qty">x' . esc_html( (string) $item['qty'] ) . '</span>';
				}
				if ( $line_display !== '' ) {
					echo '<span class="ddb-checkout-program__price">' . wp_kses_post( $line_display ) . '</span>';
				}
				echo '</div>';
			}
			echo '</div>';

			$meta_bits = array();
			if ( $shared_date === '' && ! empty( $item['date_label'] ) ) {
				$meta_bits[] = (string) $item['date_label'];
			}
			if ( $shared_participants === '' && ! empty( $item['participants_label'] ) ) {
				$meta_bits[] = (string) $item['participants_label'];
			}
			$time_label = isset( $item['time_label'] ) ? trim( (string) $item['time_label'] ) : '';
			$status_label = isset( $item['status_label'] ) ? trim( (string) $item['status_label'] ) : '';
			if ( $status_label !== '' && $status_label !== $time_label && ! in_array( $status_label, array( self::TIME_PENDING_LABEL, self::REQUEST_ONLY_LABEL ), true ) ) {
				$meta_bits[] = (string) $item['status_label'];
			}
			if ( ! empty( $item['resource_label'] ) ) {
				$meta_bits[] = (string) $item['resource_label'];
			}

			if ( $meta_bits !== array() ) {
				echo '<p class="ddb-checkout-program__meta">' . esc_html( implode( ' · ', $meta_bits ) ) . '</p>';
			}

			echo '</li>';
		}
		echo '</ol>';

		// ── Totals footer (replaces the WooCommerce review-order table) ──
		$cart = WC()->cart;
		if ( $cart ) {
			$grand_total = (float) $cart->get_total( 'edit' );
			$tax_totals  = $cart->get_tax_totals();
			$has_tax     = ! empty( $tax_totals );

			echo '<div class="ddb-checkout-program__totals">';
			if ( $has_tax ) {
				foreach ( $tax_totals as $tax ) {
					echo '<div class="ddb-checkout-program__totals-row ddb-checkout-program__totals-tax">';
					echo '<span>' . esc_html__( 'Waarvan btw', 'sbdp' ) . '</span>';
					echo '<span>' . wp_kses_post( wc_price( (float) $tax->amount ) ) . '</span>';
					echo '</div>';
				}
			}
			echo '<div class="ddb-checkout-program__totals-row ddb-checkout-program__totals-grand">';
			echo '<span>' . esc_html__( 'Totaal incl. btw', 'sbdp' ) . '</span>';
			echo '<span>' . wp_kses_post( wc_price( $grand_total ) ) . '</span>';
			echo '</div>';
			echo '</div>';
		}

		// Coupon toggle — compact, below totals
		if ( function_exists( 'woocommerce_checkout_coupon_form' ) && wc_coupons_enabled() ) {
			echo '<div class="ddb-checkout-program__coupon">';
			woocommerce_checkout_coupon_form();
			echo '</div>';
		}

		echo '</section>';
	}

	/**
	 * @param array<string,mixed> $cart_item
	 * @return array<int,array<string,string>>
	 */
	public static function build_cart_item_data( array $cart_item ): array {
		if ( self::is_admin_order_screen_context() ) {
			return array();
		}

		$source = self::extract_source_from_cart_item( $cart_item );
		if ( $source === array() ) {
			return array();
		}

		$shared_context = self::checkout_summary_context();
		$is_checkout_context = $shared_context !== array();

		$rows = self::build_display_rows( $source, $is_checkout_context ? $shared_context : array(), $is_checkout_context );
		$result = array();
		foreach ( $rows as $row ) {
			$result[] = array(
				'key'     => (string) $row['label'],
				'value'   => (string) $row['value'],
				'display' => (string) $row['value'],
			);
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $hidden
	 * @return array<int,string>
	 */
	public static function filter_hidden_order_itemmeta( array $hidden ): array {
		$admin_only_hidden_keys = self::is_admin_order_screen_context()
			? array( 'sbdp_route_intent', 'sbdp_booking_capability' )
			: array();
		$merged = array_merge( $hidden, self::INTERNAL_META_KEYS, $admin_only_hidden_keys );
		$visible_admin_keys = self::is_admin_order_screen_context()
			? array( 'sbdp_date', 'sbdp_time', 'sbdp_start', 'sbdp_end', 'sbdp_participants', 'sbdp_resource_label' )
			: array();

		return array_values( array_diff( array_unique( array_map( 'strval', $merged ) ), $visible_admin_keys ) );
	}

	/**
	 * @param array<int,mixed> $formatted_meta
	 * @param mixed            $item
	 * @return array<int,mixed>
	 */
	public static function normalize_formatted_meta_data( array $formatted_meta, $item ): array {
		if ( self::is_admin_order_screen_context() ) {
			return $formatted_meta;
		}

		$source = self::extract_source_from_order_item( $item );
		$product_id = self::get_order_item_product_id( $item );
		if ( $source === array() ) {
			return self::strip_internal_meta_objects( $formatted_meta, $product_id, $source );
		}

		$rows = self::build_display_rows( $source, array(), false );
		$normalized = array();

		foreach ( $formatted_meta as $meta ) {
			if ( ! is_object( $meta ) || self::should_skip_customer_meta_object( $meta ) ) {
				continue;
			}

			if ( ! self::has_non_empty_customer_meta_display( $meta, $product_id, $source ) ) {
				continue;
			}

			$normalized[] = $meta;
		}

		foreach ( $rows as $row ) {
			$label = isset( $row['label'] ) ? self::clean_string( $row['label'] ) : '';
			$value = isset( $row['value'] ) ? self::clean_string( $row['value'] ) : '';
			if ( $label === '' || $value === '' ) {
				continue;
			}

			$meta = new stdClass();
			$meta->key = (string) ( $row['meta_key'] ?? $label );
			$meta->value = (string) $value;
			$meta->display_key = (string) $label;
			$meta->display_value = (string) $value;
			$normalized[] = $meta;
		}

		return $normalized;
	}

	/**
	 * @param string        $display_key
	 * @param object        $meta
	 * @param WC_Order_Item $item
	 */
	public static function filter_order_meta_label( $display_key, $meta, $item ): string {
		if ( self::is_admin_order_screen_context() ) {
			return is_string( $display_key ) ? $display_key : '';
		}

		$product_id = self::get_order_item_product_id( $item );
		$key = is_object( $meta ) && property_exists( $meta, 'key' ) ? (string) $meta->key : '';

		return self::resolve_label( $product_id, $key );
	}

	/**
	 * @param string        $display_value
	 * @param object        $meta
	 * @param WC_Order_Item $item
	 */
	public static function filter_order_meta_value( $display_value, $meta, $item ): string {
		if ( self::is_admin_order_screen_context() ) {
			return is_string( $display_value ) ? $display_value : '';
		}

		$product_id = self::get_order_item_product_id( $item );
		$key = is_object( $meta ) && property_exists( $meta, 'key' ) ? (string) $meta->key : '';
		$value = is_object( $meta ) && property_exists( $meta, 'value' ) ? $meta->value : null;
		$source = self::extract_source_from_order_item( $item );

		return self::format_value( $product_id, $key, $value, $source );
	}

	private static function checkout_summary_context(): array {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return array();
		}

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return array();
		}

		$items = WC()->cart->get_cart();
		if ( count( $items ) < 2 ) {
			return array();
		}

		$dates = array();
		$participants = array();

		foreach ( $items as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			$source = self::extract_source_from_cart_item( $cart_item );
			if ( $source === array() ) {
				continue;
			}

			$date = self::resolve_date_label_from_source( $source );
			if ( $date !== '' ) {
				$dates[] = $date;
			}

			$participants_label = self::resolve_participants_label_from_source( $source );
			if ( $participants_label !== '' ) {
				$participants[] = $participants_label;
			}
		}

		$shared_date = self::shared_value_from_values( $dates );
		$shared_participants = self::shared_value_from_values( $participants );

		return array(
			'shared_date' => $shared_date,
			'shared_participants' => $shared_participants,
		);
	}

	/**
	 * @param array<string,mixed> $source
	 * @param array<string,string> $shared_context
	 * @param bool $is_checkout_context
	 * @return array<int,array<string,mixed>>
	 */
	private static function build_display_rows( array $source, array $shared_context, bool $is_checkout_context ): array {
		$rows = array();

		$dateLabel = self::resolve_date_label_from_source( $source );
		if ( $dateLabel !== '' ) {
			$showDate = true;
			if ( $is_checkout_context && ! empty( $shared_context['shared_date'] ) && $shared_context['shared_date'] === $dateLabel ) {
				$showDate = false;
			}

			if ( $showDate ) {
				$rows[] = array(
					'meta_key' => 'sbdp_date',
					'label'    => __( 'Datum', 'sbdp' ),
					'value'    => $dateLabel,
				);
			}
		}

		$timeValue = self::resolve_time_label_from_source( $source );
		$statusLabel = self::resolve_status_label_from_source( $source );
		if ( $timeValue !== '' ) {
			$rows[] = array(
				'meta_key' => 'sbdp_start',
				'label'    => __( 'Tijd', 'sbdp' ),
				'value'    => $timeValue,
			);
		} elseif ( $statusLabel !== '' ) {
			$rows[] = array(
				'meta_key' => 'sbdp_start',
				'label'    => __( 'Tijd', 'sbdp' ),
				'value'    => $statusLabel,
			);
		} else {
			$rows[] = array(
				'meta_key' => 'sbdp_start',
				'label'    => __( 'Tijd', 'sbdp' ),
				'value'    => self::TIME_PENDING_LABEL,
			);
		}

		$participantsLabel = self::resolve_participants_label_from_source( $source );
		if ( $participantsLabel !== '' ) {
			$showParticipants = true;
			if ( $is_checkout_context && ! empty( $shared_context['shared_participants'] ) && $shared_context['shared_participants'] === $participantsLabel ) {
				$showParticipants = false;
			}

			if ( $showParticipants ) {
				$rows[] = array(
					'meta_key' => 'sbdp_participants',
					'label'    => __( 'Aantal personen', 'sbdp' ),
					'value'    => $participantsLabel,
				);
			}
		}

		$resourceLabel = self::resolve_resource_label_from_source( $source );
		if ( $resourceLabel !== '' ) {
			$rows[] = array(
				'meta_key' => 'sbdp_resource_label',
				'label'    => __( 'Locatie', 'sbdp' ),
				'value'    => $resourceLabel,
			);
		}

		if ( $statusLabel !== '' && $timeValue !== '' ) {
			$rows[] = array(
				'meta_key' => 'sbdp_route_intent',
				'label'    => __( 'Status', 'sbdp' ),
				'value'    => $statusLabel,
			);
		}

		return $rows;
	}

	/**
	 * @param array<string,mixed> $source
	 * @return string
	 */
	private static function resolve_date_label_from_source( array $source ): string {
		$raw = self::first_non_empty_string(
			$source['sbdp_date'] ?? null,
			$source['sbdp_plan_date'] ?? null
		);

		if ( $raw === '' ) {
			return '';
		}

		$dt = self::create_display_datetime( $raw );
		if ( $dt === null ) {
			return sanitize_text_field( $raw );
		}

		return wp_date( 'l j F Y', $dt->getTimestamp() );
	}

	/**
	 * @param array<string,mixed> $source
	 * @return string
	 */
	private static function resolve_time_label_from_source( array $source ): string {
		$start = self::clean_string( $source['sbdp_start'] ?? '' );
		$end = self::clean_string( $source['sbdp_end'] ?? '' );
		$time = self::clean_string( $source['sbdp_time'] ?? '' );

		if ( $start !== '' ) {
			$startLabel = self::format_time_component( $start );
			if ( $end !== '' ) {
				$endLabel = self::format_time_component( $end );
				if ( $startLabel !== '' && $endLabel !== '' ) {
					return trim( $startLabel . ' – ' . $endLabel, " –" );
				}
			}

			return $startLabel !== '' ? $startLabel : sanitize_text_field( $start );
		}

		if ( $time !== '' ) {
			return sanitize_text_field( $time );
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $source
	 * @return string
	 */
	private static function resolve_participants_label_from_source( array $source ): string {
		$count = self::resolve_participants_count_from_source( $source );
		if ( $count <= 0 ) {
			return '';
		}

		return sprintf( _n( '%d persoon', '%d personen', $count, 'sbdp' ), $count );
	}

	/**
	 * @param array<string,mixed> $source
	 * @return int
	 */
	private static function resolve_participants_count_from_source( array $source ): int {
		foreach ( array( 'sbdp_canonical_participants', 'sbdp_participants', 'participants', 'count', 'people' ) as $key ) {
			if ( isset( $source[ $key ] ) && is_numeric( $source[ $key ] ) ) {
				return max( 0, (int) $source[ $key ] );
			}
		}

		return 0;
	}

	/**
	 * @param array<string,mixed> $source
	 * @return string
	 */
	private static function resolve_resource_label_from_source( array $source ): string {
		$label = self::clean_string( $source['sbdp_resource_label'] ?? '' );
		if ( $label === '' ) {
			return '';
		}

		$generic = array(
			'General availability',
			'Algemene beschikbaarheid',
			'Beschikbare locatie',
		);
		if ( in_array( $label, $generic, true ) ) {
			return '';
		}

		return sanitize_text_field( $label );
	}

	/**
	 * @param array<string,mixed> $source
	 * @return string
	 */
	private static function resolve_status_label_from_source( array $source ): string {
		$routeIntent = strtolower( self::clean_string( $source['sbdp_route_intent'] ?? '' ) );
		$capability = strtoupper( self::clean_string( $source['sbdp_booking_capability'] ?? '' ) );
		$time = self::resolve_time_label_from_source( $source );

		if ( in_array( $routeIntent, array( 'quote', 'request' ), true ) || $capability === 'REQUEST' ) {
			return self::REQUEST_STATUS_LABEL;
		}

		if ( $time === '' ) {
			return self::TIME_PENDING_LABEL;
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $cart_item
	 * @return array<string,mixed>
	 */
	private static function extract_source_from_cart_item( array $cart_item ): array {
		$source = array();

		if ( isset( $cart_item['sbdp_meta'] ) && is_array( $cart_item['sbdp_meta'] ) ) {
			$source = $cart_item['sbdp_meta'];
		}

		foreach ( array(
			'sbdp_date',
			'sbdp_time',
			'sbdp_start',
			'sbdp_end',
			'sbdp_participants',
			'sbdp_canonical_participants',
			'sbdp_resource_label',
			'sbdp_route_intent',
			'sbdp_booking_capability',
		) as $key ) {
			if ( isset( $cart_item[ $key ] ) && ! isset( $source[ $key ] ) ) {
				$source[ $key ] = $cart_item[ $key ];
			}
		}

		return $source;
	}

	/**
	 * @param mixed $item
	 * @return array<string,mixed>
	 */
	private static function extract_source_from_order_item( $item ): array {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return array();
		}

		$source = array();
		foreach ( array(
			'sbdp_date',
			'sbdp_time',
			'sbdp_start',
			'sbdp_end',
			'sbdp_participants',
			'sbdp_canonical_participants',
			'sbdp_resource_label',
			'sbdp_route_intent',
			'sbdp_booking_capability',
		) as $key ) {
			$value = $item->get_meta( $key, true );
			if ( $value !== '' && $value !== null ) {
				$source[ $key ] = $value;
			}
		}

		return $source;
	}

	/**
	 * @param mixed $item
	 * @return int
	 */
	private static function get_order_item_product_id( $item ): int {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
			return 0;
		}

		return (int) $item->get_product_id();
	}

	private static function resolve_label( int $product_id, string $meta_key ): string {
		switch ( $meta_key ) {
			case 'sbdp_date':
				return __( 'Datum', 'sbdp' );
			case 'sbdp_time':
			case 'sbdp_start':
				return __( 'Tijd', 'sbdp' );
			case 'sbdp_participants':
				return __( 'Aantal personen', 'sbdp' );
			case 'sbdp_resource_label':
				return __( 'Locatie', 'sbdp' );
			case 'sbdp_route_intent':
			case 'sbdp_booking_capability':
				return __( 'Status', 'sbdp' );
			default:
				return (string) $meta_key;
		}
	}

	/**
	 * @param mixed $value
	 * @param array<string,mixed> $source
	 */
	private static function format_value( int $product_id, string $key, $value, array $source = array() ): string {
		unset( $product_id );

		if ( $value === '' || $value === null ) {
			return '';
		}

		if ( $key === 'sbdp_date' ) {
			return self::resolve_date_label_from_source( array_merge( $source, array( 'sbdp_date' => $value ) ) );
		}

		if ( $key === 'sbdp_time' ) {
			$time = self::clean_string( $value );
			return $time !== '' ? sanitize_text_field( $time ) : '';
		}

		if ( $key === 'sbdp_start' ) {
			$merged = array_merge( $source, array( 'sbdp_start' => $value ) );
			$time = self::resolve_time_label_from_source( $merged );
			if ( $time !== '' ) {
				return $time;
			}

			return self::REQUEST_ONLY_LABEL;
		}

		if ( $key === 'sbdp_participants' || $key === 'sbdp_canonical_participants' ) {
			$count = max( 0, (int) $value );
			if ( $count <= 0 ) {
				return '';
			}

			return sprintf( _n( '%d persoon', '%d personen', $count, 'sbdp' ), $count );
		}

		if ( $key === 'sbdp_resource_label' ) {
			return self::resolve_resource_label_from_source( array( 'sbdp_resource_label' => $value ) );
		}

		if ( $key === 'sbdp_route_intent' || $key === 'sbdp_booking_capability' ) {
			$status = self::resolve_status_label_from_source( array_merge( $source, array( $key => $value ) ) );
			return $status !== '' ? $status : sanitize_text_field( (string) $value );
		}

		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $meta
	 * @return array<int,array<string,mixed>>
	 */
	private static function build_checkout_program_items( array $meta ): array {
		$items = array();

		foreach ( $meta as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			$source = self::extract_source_from_cart_item( $cart_item );
			if ( $source === array() ) {
				continue;
			}

			$product = isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_name' )
				? (string) $cart_item['data']->get_name()
				: ( isset( $cart_item['product_name'] ) ? (string) $cart_item['product_name'] : '' );
			$product = self::normalize_title( $product );

			$items[] = array(
				'title'              => $product !== '' ? $product : __( 'Activiteit', 'sbdp' ),
				'date_label'         => self::resolve_date_label_from_source( $source ),
				'time_label'         => self::resolve_time_label_from_source( $source ),
				'participants_label' => self::resolve_participants_label_from_source( $source ),
				'status_label'       => self::resolve_status_label_from_source( $source ),
				'resource_label'     => self::resolve_resource_label_from_source( $source ),
				'source'             => $source,
				'qty'                => isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1,
				'line_total'         => self::resolve_cart_line_total_including_tax( $cart_item ),
			);
		}

		return $items;
	}

	/**
	 * @param array<string,mixed> $cart_item
	 */
	private static function resolve_cart_line_total_including_tax( array $cart_item ): float {
		$line_total = isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : 0.0;
		$line_tax   = isset( $cart_item['line_tax'] ) ? (float) $cart_item['line_tax'] : 0.0;

		return max( 0.0, $line_total + $line_tax );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	private static function resolve_line_display( array $item ): string {
		$line_total = isset( $item['line_total'] ) ? (float) $item['line_total'] : 0.0;
		if ( $line_total > 0 ) {
			return (string) wc_price( $line_total );
		}

		$source = isset( $item['source'] ) && is_array( $item['source'] ) ? $item['source'] : array();
		$status = self::resolve_non_priced_item_label( $source );

		return $status !== '' ? esc_html( $status ) : '';
	}

	/**
	 * @param array<string,mixed> $source
	 */
	private static function resolve_non_priced_item_label( array $source ): string {
		foreach ( array( 'included', 'is_included', 'sbdp_included' ) as $key ) {
			if ( array_key_exists( $key, $source ) ) {
				return filter_var( $source[ $key ], FILTER_VALIDATE_BOOLEAN ) ? self::INCLUDED_LABEL : self::NOT_INCLUDED_LABEL;
			}
		}

		$route_intent = strtolower( self::clean_string( $source['sbdp_route_intent'] ?? '' ) );
		$capability   = strtoupper( self::clean_string( $source['sbdp_booking_capability'] ?? '' ) );
		if ( in_array( $route_intent, array( 'quote', 'request' ), true ) || $capability === 'REQUEST' ) {
			return self::PRICE_ON_REQUEST_LABEL;
		}

		return self::INCLUDED_LABEL;
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @param string $field
	 */
	private static function shared_value( array $items, string $field ): string {
		$values = array();
		foreach ( $items as $item ) {
			$value = isset( $item[ $field ] ) ? trim( (string) $item[ $field ] ) : '';
			if ( $value !== '' ) {
				$values[] = $value;
			}
		}

		$shared = self::shared_value_from_values( $values );
		return is_string( $shared ) ? $shared : '';
	}

	/**
	 * @param array<int,string> $values
	 */
	private static function shared_value_from_values( array $values ): string {
		$values = array_values( array_unique( array_filter( array_map( 'trim', $values ), static fn( string $value ): bool => $value !== '' ) ) );
		if ( count( $values ) !== 1 ) {
			return '';
		}

		return (string) $values[0];
	}

	/**
	 * @param array<int,mixed> $formatted_meta
	 * @return array<int,mixed>
	 */
	private static function strip_internal_meta_objects( array $formatted_meta, int $product_id = 0, array $source = array() ): array {
		$filtered = array();

		foreach ( $formatted_meta as $meta ) {
			if ( ! is_object( $meta ) || self::should_skip_customer_meta_object( $meta ) ) {
				continue;
			}

			if ( ! self::has_non_empty_customer_meta_display( $meta, $product_id, $source ) ) {
				continue;
			}

			$filtered[] = $meta;
		}

		return $filtered;
	}

	private static function is_customer_meta_key( string $key ): bool {
		return in_array( $key, self::CUSTOMER_META_KEYS, true );
	}

	private static function is_internal_meta_key( string $key ): bool {
		if ( $key === '' ) {
			return false;
		}

		if ( in_array( $key, self::INTERNAL_META_KEYS, true ) ) {
			return true;
		}

		if ( strpos( $key, '_sbdp_' ) === 0 ) {
			return true;
		}

		return false;
	}

	private static function should_skip_customer_meta_object( object $meta ): bool {
		$key = property_exists( $meta, 'key' ) ? (string) $meta->key : '';
		if ( $key !== '' && self::is_internal_meta_key( $key ) ) {
			return true;
		}

		if ( $key !== '' && self::is_customer_meta_key( $key ) ) {
			return true;
		}

		$display_key = property_exists( $meta, 'display_key' ) ? self::clean_string( $meta->display_key ) : '';
		$display_value = property_exists( $meta, 'display_value' ) ? self::clean_string( $meta->display_value ) : '';

		if ( $display_key === '' || $display_value === '' ) {
			return true;
		}

		return false;
	}

	private static function has_non_empty_customer_meta_display( object $meta, int $product_id, array $source ): bool {
		$key = property_exists( $meta, 'key' ) ? (string) $meta->key : '';
		$value = property_exists( $meta, 'value' ) ? $meta->value : null;

		$label = $key !== ''
			? self::resolve_label( $product_id, $key )
			: self::clean_string( property_exists( $meta, 'display_key' ) ? $meta->display_key : '' );

		if ( $label === '' ) {
			return false;
		}

		$display_value = $key !== ''
			? self::format_value( $product_id, $key, $value, $source )
			: self::clean_string( property_exists( $meta, 'display_value' ) ? wp_strip_all_tags( (string) $meta->display_value ) : '' );

		return $display_value !== '';
	}

	private static function is_admin_order_screen_context(): bool {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! is_object( $screen ) || ! isset( $screen->id ) ) {
			return false;
		}

		return in_array( (string) $screen->id, array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' ), true );
	}

	private static function clean_string( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return trim( (string) $value );
	}

	private static function format_time_component( string $value ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}

		if ( preg_match( '/^\d{2}:\d{2}$/', $value ) === 1 ) {
			return $value;
		}

		$dt = self::create_display_datetime( $value );
		if ( $dt === null ) {
			return sanitize_text_field( $value );
		}

		return wp_date( 'H:i', $dt->getTimestamp() );
	}

	/**
	 * Return the first safe scalar value that yields a non-empty trimmed string.
	 *
	 * @param mixed ...$values
	 */
	private static function first_non_empty_string( ...$values ): string {
		foreach ( $values as $value ) {
			if ( $value === null || $value === false || is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$string = trim( (string) $value );
			if ( $string !== '' ) {
				return $string;
			}
		}

		return '';
	}

	private static function create_display_datetime( string $value ): ?DateTimeImmutable {
		try {
			$parsed = new DateTimeImmutable( $value );
		} catch ( Exception $exception ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $parsed->format( 'Y-m-d H:i:s' ), wp_timezone() );
		} catch ( Exception $exception ) {
			return $parsed;
		}
	}

	private static function normalize_title( string $title ): string {
		return preg_replace( '/\bwaling dinner\b/i', 'Walking Dinner', $title ) ?? $title;
	}
}
