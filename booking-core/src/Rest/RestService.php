<?php

declare(strict_types=1);

namespace BSPModule\Core\Rest;

use WC_Cart;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use BSPModule\Core\Product\AvailabilityRules;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use DateTimeImmutable;
use Exception;

final class RestService {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'sbdp/v1',
			'/services',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'get_services' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/compose_booking',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'compose_booking' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/availability/rules',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'callback'            => array( __CLASS__, 'get_rules' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/availability/rules',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'callback'            => array( __CLASS__, 'save_rules' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/availability/preview',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'callback'            => array( __CLASS__, 'preview_availability' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/availability/plan',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'plan_availability' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/resources',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'callback'            => array( __CLASS__, 'get_resources' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/pricing/rules',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'callback'            => array( __CLASS__, 'get_pricing_rules' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/pricing/rules',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'callback'            => array( __CLASS__, 'save_pricing_rules' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/pricing/preview',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'preview_pricing' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/schedule/overview',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'callback'            => array( __CLASS__, 'get_schedule_overview' ),
			)
		);
	}

	public static function get_services( WP_REST_Request $request ) {
		$q = new WP_Query(
			array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => array( 'bookable_service' ),
					),
				),
			)
		);

		$services = array();
		while ( $q->have_posts() ) {
			$q->the_post();
			$pid        = get_the_ID();
			$services[] = array(
				'id'          => $pid,
				'name'        => get_the_title(),
				'price'       => (float) get_post_meta( $pid, '_price', true ),
				'duration'    => (int) ( get_post_meta( $pid, '_sbdp_duration', true ) ?: 60 ),
				'resource_id' => (int) get_post_meta( $pid, '_sbdp_resource_id', true ),
				'thumb'       => get_the_post_thumbnail_url( $pid, 'thumbnail' ),
				'excerpt'     => wp_strip_all_tags( get_the_excerpt( $pid ) ),
			);
		}
		wp_reset_postdata();

		return rest_ensure_response( $services );
	}

	public static function compose_booking( WP_REST_Request $request ) {
		$payload      = $request->get_json_params();
		$mode         = sanitize_text_field( $payload['mode'] ?? 'pay' );
		$participants = max( 1, intval( $payload['participants'] ?? 1 ) );
		$items        = self::sanitize_items( $payload['items'] ?? array() );

		if ( empty( $items ) ) {
			return new WP_Error( 'sbdp_no_items', __( 'Geen geldige items ontvangen.', 'sbdp' ), array( 'status' => 400 ) );
		}

		$validation = self::validate_items( $items, $participants );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( $mode === 'pay' ) {
			if ( ! function_exists( 'WC' ) ) {
				return new WP_Error( 'sbdp_no_wc', __( 'WooCommerce niet beschikbaar.', 'sbdp' ), array( 'status' => 500 ) );
			}

			self::ensure_cart_session();
			if ( ! WC()->cart ) {
				return new WP_Error( 'sbdp_no_cart', __( 'Winkelwagen kon niet worden geopend.', 'sbdp' ), array( 'status' => 500 ) );
			}

			WC()->cart->empty_cart();
			$added = false;

			if ( function_exists( 'WC' ) && WC()->session ) {
				WC()->session->set( 'sbdp_mode', 'pay' );
				WC()->session->set( 'sbdp_itinerary', self::snapshot_itinerary( $items, $participants ) );
			}

			foreach ( $items as $item ) {
				$product = wc_get_product( $item['product_id'] );
				if ( ! $product ) {
					return new WP_Error( 'sbdp_invalid_product', __( 'Ongeldige productreferentie.', 'sbdp' ), array( 'status' => 400 ) );
				}

				$resource_id    = intval( $item['resource_id'] ?? get_post_meta( $item['product_id'], '_sbdp_resource_id', true ) );
				$resource_label = self::get_resource_label( $resource_id );
				$pricing        = self::calculate_pricing_for_item( $product, $resource_id, $item['start'], $participants );

				$cart_key = WC()->cart->add_to_cart(
					$item['product_id'],
					$participants,
					0,
					array(),
					array(
						'sbdp_meta' => array(
							'sbdp_start'          => $item['start'],
							'sbdp_end'            => $item['end'],
							'sbdp_participants'   => $participants,
							'sbdp_resource_id'    => $resource_id,
							'sbdp_resource_label' => $resource_label,
						),
					)
				);

				if ( $cart_key ) {
					$added = true;
					if ( isset( WC()->cart->cart_contents[ $cart_key ] ) ) {
						$cart_item = WC()->cart->cart_contents[ $cart_key ];
						if ( isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ) {
							$cart_item['data']->set_price( $pricing['unit_price'] );
						}
						$cart_item['sbdp_pricing']             = $pricing;
						WC()->cart->cart_contents[ $cart_key ] = $cart_item;
					}
				}
			}

			if ( ! $added ) {
				return new WP_Error( 'sbdp_cart_failed', __( 'Kon geen items aan de winkelwagen toevoegen.', 'sbdp' ), array( 'status' => 500 ) );
			}

			if ( WC()->cart ) {
				WC()->cart->calculate_totals();
			}

			return array(
				'ok'       => true,
				'redirect' => wc_get_checkout_url(),
			);
		}
		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$has_items = false;
		foreach ( $items as $item ) {
			$product = wc_get_product( $item['product_id'] );
			if ( ! $product ) {
				continue;
			}

			$qty     = $participants;
			$item_id = $order->add_product( $product, $qty );
			if ( $item_id ) {
				$has_items   = true;
				$resource_id = intval( $item['resource_id'] ?? get_post_meta( $item['product_id'], '_sbdp_resource_id', true ) );
				$resource_label = self::get_resource_label( $resource_id );
				$pricing     = self::calculate_pricing_for_item( $product, $resource_id, $item['start'], $participants );
				wc_add_order_item_meta( $item_id, 'sbdp_start', $item['start'] );
				wc_add_order_item_meta( $item_id, 'sbdp_end', $item['end'] );
				wc_add_order_item_meta( $item_id, 'sbdp_participants', $qty );
				wc_add_order_item_meta( $item_id, 'sbdp_resource_id', $resource_id );
				wc_add_order_item_meta( $item_id, 'sbdp_resource_label', $resource_label );
				wc_add_order_item_meta( $item_id, '_sbdp_pricing', $pricing );

				$order_item = $order->get_item( $item_id );
				if ( $order_item instanceof WC_Order_Item_Product ) {
					$line_total = round( $pricing['unit_price'] * $qty, 2 );
					$order_item->set_subtotal( $line_total );
					$order_item->set_total( $line_total );
					$order_item->save();
				}
			}
		}

		if ( ! $has_items ) {
			return new WP_Error( 'sbdp_order_failed', __( 'Kon geen items aan de order toevoegen.', 'sbdp' ), array( 'status' => 500 ) );
		}

		$order->calculate_totals();
		$order->update_status( 'on-hold', 'Concept programma via planner' );
		$order->update_meta_data( 'sbdp_mode', 'request' );
		$order->save();

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( 'sbdp_mode' );
			WC()->session->__unset( 'sbdp_itinerary' );
		}

		$order_key    = $order->get_order_key();
		$received_url = method_exists( $order, 'get_checkout_order_received_url' ) ? $order->get_checkout_order_received_url() : '';
		$view_url     = $order->get_view_order_url();
		$redirect     = $received_url ? $received_url : $view_url;

		return array(
			'ok'        => true,
			'redirect'  => $redirect,
			'order_id'  => $order->get_id(),
			'order_key' => $order_key,
			'view_url'  => $view_url,
		);
	}

	public static function get_rules( WP_REST_Request $request ) {
		$product_id  = intval( $request->get_param( 'product_id' ) );
		$resource_id = intval( $request->get_param( 'resource_id' ) );

		if ( ! $product_id ) {
			return new WP_Error( 'bad_request', 'product_id required', array( 'status' => 400 ) );
		}

		$key   = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
		$rules = get_post_meta( $product_id, $key, true );
		if ( ! is_array( $rules ) ) {
			$rules = AvailabilityRules::defaultRules();
		}

		$cap_key  = $resource_id ? "_sbdp_capacity_res_{$resource_id}" : '_sbdp_capacity_default';
		$capacity = (int) get_post_meta( $product_id, $cap_key, true );
		if ( $capacity < 0 ) {
			$capacity = 0;
		}

		if ( ! is_array( $rules ) ) {
			$rules = array(
				'default'          => 'open',
				'exclude_weekdays' => array(),
				'exclude_months'   => array(),
				'exclude_times'    => array(),
				'overrides'        => array(),
			);
		}

		return array(
			'rules'    => $rules,
			'capacity' => $capacity,
		);
	}

	public static function save_rules( WP_REST_Request $request ) {
		$payload     = $request->get_json_params();
		$product_id  = intval( $payload['product_id'] ?? 0 );
		$resource_id = intval( $payload['resource_id'] ?? 0 );
		$rules       = $payload['rules'] ?? null;
		$capacity    = intval( $payload['capacity'] ?? 1 );

		if ( ! $product_id || ! is_array( $rules ) ) {
			return new WP_Error( 'bad_request', 'product_id & rules required', array( 'status' => 400 ) );
		}

		$key = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
		update_post_meta( $product_id, $key, $rules );

		$cap_key = $resource_id ? "_sbdp_capacity_res_{$resource_id}" : '_sbdp_capacity_default';
		update_post_meta( $product_id, $cap_key, $capacity );

		return array( 'ok' => true );
	}

	public static function preview_availability( WP_REST_Request $request ) {
		$payload     = $request->get_json_params();
		$product_id  = intval( $payload['product_id'] ?? 0 );
		$resource_id = intval( $payload['resource_id'] ?? 0 );
		$date        = sanitize_text_field( $payload['date'] ?? '' );

		if ( ! $product_id || ! $date ) {
			return new WP_Error( 'bad_request', 'product_id & date required', array( 'status' => 400 ) );
		}

		$key   = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
		$rules = get_post_meta( $product_id, $key, true );
		if ( ! is_array( $rules ) ) {
			$rules = AvailabilityRules::defaultRules();
		}

		$blocks = self::blocks_for_date( $date, $rules );

		$cap_key  = $resource_id ? "_sbdp_capacity_res_{$resource_id}" : '_sbdp_capacity_default';
		$capacity = (int) get_post_meta( $product_id, $cap_key, true );
		if ( $capacity < 0 ) {
			$capacity = 0;
		}

		return array(
			'blocks'   => $blocks,
			'capacity' => $capacity,
		);
	}

	public static function plan_availability( WP_REST_Request $request ) {
		$product_id  = intval( $request->get_param( 'product_id' ) );
		$resource_id = intval( $request->get_param( 'resource_id' ) );
		$date        = sanitize_text_field( $request->get_param( 'date' ) );

		if ( ! $product_id || ! $date ) {
			return new WP_Error( 'bad_request', 'product_id & date required', array( 'status' => 400 ) );
		}

		$key   = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
		$rules = get_post_meta( $product_id, $key, true );
		if ( ! is_array( $rules ) ) {
			$rules = AvailabilityRules::defaultRules();
		}

		$blocks = self::blocks_for_date( $date, $rules );

		$cap_key  = $resource_id ? "_sbdp_capacity_res_{$resource_id}" : '_sbdp_capacity_default';
		$capacity = (int) get_post_meta( $product_id, $cap_key, true );
		if ( $capacity < 0 ) {
			$capacity = 0;
		}

		return array(
			'blocks'   => $blocks,
			'capacity' => $capacity,
		);
	}

	public static function get_resources( WP_REST_Request $request ) {
		$resources = get_posts(
			array(
				'post_type'      => 'bookable_resource',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$out = array();
		foreach ( $resources as $resource ) {
			$out[] = array(
				'id'    => (int) $resource->ID,
				'title' => get_the_title( $resource ),
			);
		}

		return rest_ensure_response( $out );
	}

	public static function get_pricing_rules( WP_REST_Request $request ) {
		$product_id  = intval( $request->get_param( 'product_id' ) );
		$resource_id = intval( $request->get_param( 'resource_id' ) );

		if ( ! $product_id ) {
			return new WP_Error( 'bad_request', 'product_id required', array( 'status' => 400 ) );
		}

		$key   = $resource_id ? "_sbdp_price_rules_res_{$resource_id}" : '_sbdp_price_rules';
		$rules = get_post_meta( $product_id, $key, true );

		return array( 'rules' => $rules );
	}

	public static function save_pricing_rules( WP_REST_Request $request ) {
		$payload     = $request->get_json_params();
		$product_id  = intval( $payload['product_id'] ?? 0 );
		$resource_id = intval( $payload['resource_id'] ?? 0 );
		$rules_raw   = $payload['rules'] ?? array();

		if ( ! $product_id || ! is_array( $rules_raw ) ) {
			return new WP_Error( 'bad_request', 'product_id & rules required', array( 'status' => 400 ) );
		}

		$rules = self::sanitize_price_rules( $rules_raw );

		$key = $resource_id ? "_sbdp_price_rules_res_{$resource_id}" : '_sbdp_price_rules';
		update_post_meta( $product_id, $key, $rules );

		return array( 'ok' => true );
	}

	public static function preview_pricing( WP_REST_Request $request ) {
		$payload      = $request->get_json_params();
		$product_id   = intval( $payload['product_id'] ?? 0 );
		$resource_id  = intval( $payload['resource_id'] ?? 0 );
		$participants = max( 1, intval( $payload['participants'] ?? 1 ) );
		$start        = sanitize_text_field( $payload['start'] ?? '' );

		if ( ! $product_id || ! $start ) {
			return new WP_Error( 'bad_request', 'product_id & start required', array( 'status' => 400 ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'sbdp_invalid_product', __( 'Ongeldige productreferentie.', 'sbdp' ), array( 'status' => 400 ) );
		}

		$pricing = self::calculate_pricing_for_item( $product, $resource_id, $start, $participants );

		return rest_ensure_response( $pricing );
	}

	public static function get_schedule_overview( WP_REST_Request $request ) {
		$date_raw = sanitize_text_field( $request->get_param( 'date' ) );
		$date     = $date_raw && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_raw ) ? $date_raw : current_time( 'Y-m-d' );

		$resources = get_posts(
			array(
				'post_type'      => 'bookable_resource',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$resource_lookup = array();
		foreach ( $resources as $resource ) {
			$resource_lookup[ $resource->ID ] = get_the_title( $resource );
		}

		$status_filter = apply_filters( 'sbdp_schedule_order_statuses', array( 'processing', 'on-hold', 'completed', 'pending' ) );
		$orders        = wc_get_orders(
			array(
				'status'  => $status_filter,
				'limit'   => apply_filters( 'sbdp_schedule_order_limit', 200 ),
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);

		$events = array();
		if ( $orders ) {
			foreach ( $orders as $order ) {
				foreach ( $order->get_items() as $item ) {
					$start = wc_get_order_item_meta( $item->get_id(), 'sbdp_start' );
					if ( ! $start ) {
						continue;
					}
					$start_ts = strtotime( $start );
					if ( ! $start_ts ) {
						continue;
					}
					$start_date = gmdate( 'Y-m-d', $start_ts );
					if ( $start_date !== $date ) {
						continue;
					}

					$end          = wc_get_order_item_meta( $item->get_id(), 'sbdp_end' );
					$participants = (int) wc_get_order_item_meta( $item->get_id(), 'sbdp_participants' );
					if ( $participants < 1 ) {
						$participants = 1;
					}

					$product_id  = $item->get_product_id();
					$resource_id = 0;
					if ( $product_id ) {
							$resource_id = (int) get_post_meta( $product_id, '_sbdp_resource_id', true );
					}

					$events[] = array(
						'order_id'     => $order->get_id(),
						'order_status' => $order->get_status(),
						'product_id'   => $product_id,
						'product_name' => $item->get_name(),
						'start'        => $start,
						'end'          => $end,
						'participants' => $participants,
						'customer'     => $order->get_formatted_billing_full_name(),
						'resource'     => array(
							'id'   => $resource_id,
							'name' => $resource_id && isset( $resource_lookup[ $resource_id ] ) ? $resource_lookup[ $resource_id ] : '',
						),
						'link'         => $order->get_edit_order_url(),
					);
				}
			}
		}

		return rest_ensure_response(
			array(
				'date'      => $date,
				'resources' => array_map(
					function ( $ID ) use ( $resource_lookup ) {
						return array(
							'id'   => $ID,
							'name' => $resource_lookup[ $ID ],
						);
					},
					array_keys( $resource_lookup )
				),
				'events'    => $events,
			)
		);
	}

	private static function sanitize_price_rules( $rules ) {
		$out = array();

		foreach ( $rules as $rule ) {
			$clean = array(
				'label'     => sanitize_text_field( $rule['label'] ?? '' ),
				'type'      => sanitize_text_field( $rule['type'] ?? 'fixed' ),
				'amount'    => (float) ( $rule['amount'] ?? 0 ),
				'apply_to'  => sanitize_text_field( $rule['apply_to'] ?? 'booking' ),
				'weekdays'  => array(),
				'time_from' => '',
				'time_to'   => '',
				'date_from' => '',
				'date_to'   => '',
			);

			if ( ! in_array( $clean['type'], array( 'fixed', 'percent' ), true ) ) {
				$clean['type'] = 'fixed';
			}

			if ( ! in_array( $clean['apply_to'], array( 'booking', 'participant' ), true ) ) {
				$clean['apply_to'] = 'booking';
			}

			if ( isset( $rule['weekdays'] ) && is_array( $rule['weekdays'] ) ) {
				foreach ( $rule['weekdays'] as $weekday ) {
					$wd = (int) $weekday;
					if ( $wd >= 0 && $wd <= 6 ) {
						$clean['weekdays'][] = $wd;
					}
				}
			}

			if ( ! empty( $rule['time_from'] ) ) {
				$clean['time_from'] = preg_replace( '/[^0-9:]/', '', substr( $rule['time_from'], 0, 5 ) );
			}

			if ( ! empty( $rule['time_to'] ) ) {
				$clean['time_to'] = preg_replace( '/[^0-9:]/', '', substr( $rule['time_to'], 0, 5 ) );
			}

			if ( ! empty( $rule['date_from'] ) ) {
				$clean['date_from'] = sanitize_text_field( $rule['date_from'] );
			}

			if ( ! empty( $rule['date_to'] ) ) {
				$clean['date_to'] = sanitize_text_field( $rule['date_to'] );
			}

			$out[] = $clean;
		}

		return $out;
	}

	private static function get_local_datetime( $iso ) {
		try {
			$dt = new DateTimeImmutable( $iso );
		} catch ( Exception $e ) {
			return null;
		}

		try {
			$timezone = wp_timezone();
			return $dt->setTimezone( $timezone );
		} catch ( Exception $e ) {
			return $dt;
		}
	}

	private static function check_item_rules( $product_id, $resource_id, $start, $end, $participants ) {
		$start_dt = self::get_local_datetime( $start );
		$end_dt   = self::get_local_datetime( $end );

		if ( ! $start_dt || ! $end_dt ) {
			return new WP_Error( 'sbdp_bad_time', __( 'Ongeldige datum of tijd ontvangen.', 'sbdp' ), array( 'status' => 400 ) );
		}

		$date = $start_dt->format( 'Y-m-d' );

		$rules_key = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
		$rules     = get_post_meta( $product_id, $rules_key, true );

		$blocks = self::blocks_for_date( $date, $rules );
		foreach ( $blocks as $block ) {
			$block_start = $block['start'] ?? '';
			$block_end   = $block['end'] ?? '';
			if ( self::ranges_overlap( $start, $end, $block_start, $block_end ) ) {
				return new WP_Error( 'sbdp_conflict', __( 'De geselecteerde tijd is niet beschikbaar.', 'sbdp' ), array( 'status' => 400 ) );
			}
		}

		$cap_key  = $resource_id ? "_sbdp_capacity_res_{$resource_id}" : '_sbdp_capacity_default';
		$capacity = (int) get_post_meta( $product_id, $cap_key, true );
		if ( $capacity < 0 ) {
			$capacity = 0;
		}

		$conflicts = self::find_overlapping_bookings( $product_id, $resource_id, $start, $end );
		$occupied  = 0;
		foreach ( $conflicts as $existing ) {
			$occupied += max( 1, (int) ( $existing['participants'] ?? 0 ) );
		}

		if ( $capacity > 0 && ( $occupied + $participants ) > $capacity ) {
			return new WP_Error(
				'sbdp_capacity',
				__( 'Er zijn onvoldoende plaatsen beschikbaar voor dit tijdslot.', 'sbdp' ),
				array(
					'status'    => 400,
					'available' => max( 0, $capacity - $occupied ),
					'conflicts' => wp_list_pluck( $conflicts, 'order_id' ),
				)
			);
		}

		$allow_parallel = apply_filters( 'sbdp_allow_parallel_bookings', false, $product_id, $resource_id );
		if ( ! $allow_parallel && ! empty( $conflicts ) ) {
			return new WP_Error(
				'sbdp_conflict',
				__( 'De geselecteerde tijd is niet beschikbaar.', 'sbdp' ),
				array(
					'status'    => 400,
					'conflicts' => wp_list_pluck( $conflicts, 'order_id' ),
				)
			);
		}

		return true;
	}
	private static function find_overlapping_bookings( $product_id, $resource_id, $start, $end ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! $wpdb ) {
			return array();
		}

		$status_filter = apply_filters( 'sbdp_booking_conflict_statuses', array( 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-pending' ) );
		if ( empty( $status_filter ) ) {
			return array();
		}

		$day = substr( $start, 0, 10 );
		$like = $day ? $wpdb->esc_like( $day ) . '%' : '%';

		$order_items_table     = $wpdb->prefix . 'woocommerce_order_items';
		$order_itemmeta_table  = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$posts_table           = $wpdb->posts;
		$status_placeholders   = implode( ',', array_fill( 0, count( $status_filter ), '%s' ) );

		$sql = "SELECT o.ID AS order_id,
					   o.post_status,
					   start_meta.meta_value AS start_time,
					   end_meta.meta_value AS end_time,
					   participants_meta.meta_value AS participants,
					   COALESCE(resource_meta.meta_value, '') AS resource_id
			FROM {$order_items_table} AS oi
			INNER JOIN {$posts_table} AS o ON o.ID = oi.order_id
			LEFT JOIN {$order_itemmeta_table} AS product_meta ON product_meta.order_item_id = oi.order_item_id AND product_meta.meta_key = '_product_id'
			LEFT JOIN {$order_itemmeta_table} AS start_meta ON start_meta.order_item_id = oi.order_item_id AND start_meta.meta_key = 'sbdp_start'
			LEFT JOIN {$order_itemmeta_table} AS end_meta ON end_meta.order_item_id = oi.order_item_id AND end_meta.meta_key = 'sbdp_end'
			LEFT JOIN {$order_itemmeta_table} AS participants_meta ON participants_meta.order_item_id = oi.order_item_id AND participants_meta.meta_key = 'sbdp_participants'
			LEFT JOIN {$order_itemmeta_table} AS resource_meta ON resource_meta.order_item_id = oi.order_item_id AND resource_meta.meta_key = 'sbdp_resource_id'
			WHERE oi.order_item_type = 'line_item'
			  AND o.post_type = 'shop_order'
			  AND product_meta.meta_value = %d
			  AND o.post_status IN ( {$status_placeholders} )
			  AND start_meta.meta_value IS NOT NULL
			  AND end_meta.meta_value IS NOT NULL
			  AND start_meta.meta_value LIKE %s";

		$params = array_merge( array( $product_id ), $status_filter, array( $like ) );
		if ( $resource_id > 0 ) {
			$sql    .= ' AND ( resource_meta.meta_value = %s )';
			$params[] = (string) $resource_id;
		}

		$prepared = $wpdb->prepare( $sql, $params );
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );

		if ( empty( $rows ) ) {
			return array();
		}

		$conflicts = array();

		foreach ( $rows as $row ) {
			$row_start = $row['start_time'] ?? '';
			$row_end   = $row['end_time'] ?? '';
			if ( ! $row_start || ! $row_end ) {
				continue;
			}
			if ( ! self::ranges_overlap( $start, $end, $row_start, $row_end ) ) {
				continue;
			}

			$conflicts[] = array(
				'order_id'     => (int) $row['order_id'],
				'status'       => (string) $row['post_status'],
				'start'        => $row_start,
				'end'          => $row_end,
				'participants' => max( 1, (int) $row['participants'] ),
				'resource_id'  => (int) ( $row['resource_id'] !== '' ? $row['resource_id'] : 0 ),
			);
		}

		return $conflicts;
	}
	private static function get_resource_label( int $resource_id ): string {
		if ( $resource_id <= 0 ) {
			return '';
		}

		$label = get_the_title( $resource_id );
		if ( ! $label ) {
			return '';
		}

		return sanitize_text_field( $label );
	}

	private static function ranges_overlap( $start, $end, $block_start, $block_end ) {
		$start_ts       = strtotime( $start );
		$end_ts         = strtotime( $end );
		$block_start_ts = strtotime( $block_start );
		$block_end_ts   = strtotime( $block_end );

		if ( ! $start_ts || ! $end_ts || ! $block_start_ts || ! $block_end_ts ) {
			return false;
		}

		return ( $block_end_ts > $start_ts ) && ( $block_start_ts < $end_ts );
	}

	private static function get_price_rules_for( $product_id, $resource_id ) {
		$rules        = array();
		$global_rules = get_post_meta( $product_id, '_sbdp_price_rules', true );
		if ( is_array( $global_rules ) ) {
			$rules = array_merge( $rules, $global_rules );
		}

		if ( $resource_id ) {
			$resource_rules = get_post_meta( $product_id, "_sbdp_price_rules_res_{$resource_id}", true );
			if ( is_array( $resource_rules ) ) {
				$rules = array_merge( $rules, $resource_rules );
			}
		}

		return $rules;
	}

	private static function price_rule_applies( $rule, DateTimeImmutable $moment ) {
		$weekday = (int) $moment->format( 'w' );
		$date    = $moment->format( 'Y-m-d' );
		$time    = $moment->format( 'H:i' );

		if ( ! empty( $rule['weekdays'] ) && is_array( $rule['weekdays'] ) ) {
			if ( ! in_array( $weekday, array_map( 'intval', $rule['weekdays'] ), true ) ) {
				return false;
			}
		}

		if ( ! empty( $rule['date_from'] ) && $date < $rule['date_from'] ) {
			return false;
		}
		if ( ! empty( $rule['date_to'] ) && $date > $rule['date_to'] ) {
			return false;
		}

		if ( ! empty( $rule['time_from'] ) && $time < $rule['time_from'] ) {
			return false;
		}
		if ( ! empty( $rule['time_to'] ) && $time > $rule['time_to'] ) {
			return false;
		}

		return true;
	}

	private static function calculate_pricing_for_item( $product, $resource_id, $start, $participants ) {
		$base_price = (float) $product->get_price();
		$moment     = self::get_local_datetime( $start );

		$breakdown = array(
			'base_price'         => round( $base_price, 2 ),
			'unit_price'         => round( $base_price, 2 ),
			'booking_adjustment' => 0.0,
			'applied_rules'      => array(),
			'participants'       => $participants,
			'total'              => round( $base_price * $participants, 2 ),
		);

		if ( ! $moment ) {
			return $breakdown;
		}

		$rules = self::get_price_rules_for( $product->get_id(), $resource_id );
		if ( empty( $rules ) ) {
			return $breakdown;
		}

		$unit_price         = $base_price;
		$booking_adjustment = 0.0;

		foreach ( $rules as $rule ) {
			if ( ! self::price_rule_applies( $rule, $moment ) ) {
				continue;
			}

			$type    = $rule['type'] ?? 'fixed';
			$scope   = $rule['apply_to'] ?? 'booking';
			$amount  = (float) ( $rule['amount'] ?? 0 );
			$applied = 0.0;

			if ( 'percent' === $type ) {
				if ( 'participant' === $scope ) {
					$applied     = $base_price * ( $amount / 100 );
					$unit_price += $applied;
				} else {
					$applied             = ( $base_price * $participants ) * ( $amount / 100 );
					$booking_adjustment += $applied;
				}
			} elseif ( 'participant' === $scope ) {
					$applied     = $amount;
					$unit_price += $applied;
			} else {
				$applied             = $amount;
				$booking_adjustment += $applied;
			}

			$breakdown['applied_rules'][] = array(
				'label'  => $rule['label'],
				'scope'  => $scope,
				'type'   => $type,
				'amount' => round( $applied, 2 ),
			);
		}

		if ( $booking_adjustment !== 0 && $participants > 0 ) {
			$unit_price += ( $booking_adjustment / $participants );
		}

		$unit_price                      = max( 0, $unit_price );
		$breakdown['unit_price']         = round( $unit_price, 2 );
		$breakdown['booking_adjustment'] = round( $booking_adjustment, 2 );
		$breakdown['total']              = round( $breakdown['unit_price'] * $participants, 2 );

		return $breakdown;
	}

	private static function validate_items( $items, $participants ) {
		if ( empty( $items ) ) {
			return true;
		}

		foreach ( $items as $item ) {
			$start = strtotime( $item['start'] );
			$end   = strtotime( $item['end'] );
			if ( ! $start || ! $end ) {
				return new WP_Error( 'sbdp_bad_time', __( 'Ongeldige datum of tijd ontvangen.', 'sbdp' ), array( 'status' => 400 ) );
			}
			if ( $end <= $start ) {
				return new WP_Error( 'sbdp_bad_range', __( 'Eindtijd moet later zijn dan starttijd.', 'sbdp' ), array( 'status' => 400 ) );
			}
			if ( $start < current_time( 'timestamp' ) ) {
				return new WP_Error( 'sbdp_past_time', __( 'De geselecteerde tijd mag niet in het verleden liggen.', 'sbdp' ), array( 'status' => 400 ) );
			}

			$check = self::check_item_rules(
				intval( $item['product_id'] ),
				intval( $item['resource_id'] ?? 0 ),
				$item['start'],
				$item['end'],
				$participants
			);
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		$sorted = $items;
		usort(
			$sorted,
			static function ( $a, $b ) {
				return strcmp( (string) ( $a['start'] ?? '' ), (string) ( $b['start'] ?? '' ) );
			}
		);

		for ( $i = 1, $count = count( $sorted ); $i < $count; $i++ ) {
			$prev    = $sorted[ $i - 1 ];
			$current = $sorted[ $i ];
			if ( self::ranges_overlap( $prev['start'], $prev['end'], $current['start'], $current['end'] ) ) {
				return new WP_Error( 'sbdp_overlap', __( 'Activiteiten overlappen elkaar; pas de planning aan.', 'sbdp' ), array( 'status' => 400 ) );
			}
		}

		return true;
	}
	private static function sanitize_items( $items ) {
		$out = array();
		if ( ! is_array( $items ) ) {
			return $out;
		}

		foreach ( $items as $entry ) {
			$pid   = intval( $entry['product_id'] ?? 0 );
			$start = sanitize_text_field( $entry['start'] ?? '' );
			$end   = sanitize_text_field( $entry['end'] ?? '' );
			if ( ! $pid || ! $start || ! $end ) {
				continue;
			}

			$resource_id = isset( $entry['resource_id'] ) ? (int) $entry['resource_id'] : (int) get_post_meta( $pid, '_sbdp_resource_id', true );
			if ( $resource_id < 0 ) {
				$resource_id = 0;
			}

			$out[] = array(
				'product_id'  => $pid,
				'start'       => $start,
				'end'         => $end,
				'resource_id' => $resource_id,
			);
		}
		return $out;
	}
	private static function snapshot_itinerary( array $items, int $participants ): array {
		$snapshot = array(
			'participants' => max( 1, (int) $participants ),
			'items'        => array(),
		);

		foreach ( $items as $entry ) {
			$snapshot['items'][] = array(
				'product_id'  => intval( $entry['product_id'] ?? 0 ),
				'resource_id' => intval( $entry['resource_id'] ?? 0 ),
				'start'       => sanitize_text_field( $entry['start'] ?? '' ),
				'end'         => sanitize_text_field( $entry['end'] ?? '' ),
			);
		}

		return $snapshot;
	}

	private static function ensure_cart_session() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		if ( null === WC()->session && method_exists( WC(), 'initialize_session' ) ) {
			WC()->initialize_session();
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			if ( null === WC()->cart || ! WC()->cart ) {
				wc_load_cart();
			}
		} elseif ( null === WC()->cart && class_exists( 'WC_Cart' ) ) {
			WC()->cart = new WC_Cart();
		}
	}

	private static function blocks_for_date( $date, $rules ) {
		$blocks = array();
		$start  = $date . 'T10:00:00';
		$end    = $date . 'T24:00:00';

		$default = $rules['default'] ?? 'open';
		if ( 'closed' === $default ) {
			$blocks[] = array(
				'start'   => $start,
				'end'     => $end,
				'display' => 'background',
				'color'   => '#fee2e2',
			);
		}

		if ( ! empty( $rules['exclude_weekdays'] ) ) {
			$dow = (int) date( 'w', strtotime( $date ) );
			if ( in_array( $dow, array_map( 'intval', $rules['exclude_weekdays'] ), true ) ) {
				$blocks[] = array(
					'start'   => $start,
					'end'     => $end,
					'display' => 'background',
					'color'   => '#fecaca',
				);
			}
		}

		if ( ! empty( $rules['exclude_months'] ) ) {
			$month = (int) date( 'n', strtotime( $date ) );
			if ( in_array( $month, array_map( 'intval', $rules['exclude_months'] ), true ) ) {
				$blocks[] = array(
					'start'   => $start,
					'end'     => $end,
					'display' => 'background',
					'color'   => '#fecaca',
				);
			}
		}

		if ( ! empty( $rules['exclude_times'] ) && is_array( $rules['exclude_times'] ) ) {
			foreach ( $rules['exclude_times'] as $time ) {
				$s        = $date . 'T' . sanitize_text_field( $time['start'] ?? '00:00' ) . ':00';
				$e        = $date . 'T' . sanitize_text_field( $time['end'] ?? '00:00' ) . ':00';
				$blocks[] = array(
					'start'   => $s,
					'end'     => $e,
					'display' => 'background',
					'color'   => '#fca5a5',
				);
			}
		}

		if ( ! empty( $rules['overrides'] ) && is_array( $rules['overrides'] ) ) {
			$midday = strtotime( $date . ' 12:00' );
			foreach ( $rules['overrides'] as $override ) {
				$from = strtotime( ( $override['from'] ?? '' ) . ' 00:00' );
				$to   = strtotime( ( $override['to'] ?? '' ) . ' 23:59' );
				if ( ! $from || ! $to ) {
					continue;
				}
				if ( $midday >= $from && $midday <= $to ) {
					$mode = $override['mode'] ?? 'closed';
					if ( 'closed' === $mode ) {
						$blocks[] = array(
							'start'   => $start,
							'end'     => $end,
							'display' => 'background',
							'color'   => '#f87171',
						);
					} else {
						$blocks = array();
					}
				}
			}
		}

		return $blocks;
	}
}




