<?php

declare(strict_types=1);

namespace BSPModule\Core\WooCommerce\Checkout;

use WC_Order;

final class CheckoutService {

	private static bool $booted = false;

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach_metadata' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'clear_session' ), 10 );
		add_action( 'woocommerce_cart_emptied', array( __CLASS__, 'clear_session' ) );
	}

	public static function attach_metadata( $order, $data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			$mode = WC()->session->get( 'sbdp_mode' );
			if ( $mode ) {
				$order->update_meta_data( 'sbdp_mode', sanitize_text_field( (string) $mode ) );
			}

			$itinerary = WC()->session->get( 'sbdp_itinerary' );
			if ( is_array( $itinerary ) ) {
				$order->update_meta_data( 'sbdp_itinerary', self::sanitize_itinerary_snapshot( $itinerary ) );
			}
		}
	}

	public static function clear_session(): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( 'sbdp_mode' );
			WC()->session->__unset( 'sbdp_itinerary' );
		}
	}

	private static function sanitize_itinerary_snapshot( array $snapshot ): array {
		$items = array();

		if ( ! empty( $snapshot['items'] ) && is_array( $snapshot['items'] ) ) {
			foreach ( $snapshot['items'] as $item ) {
				$items[] = array(
					'product_id'  => isset( $item['product_id'] ) ? (int) $item['product_id'] : 0,
					'resource_id' => isset( $item['resource_id'] ) ? (int) $item['resource_id'] : 0,
					'start'       => isset( $item['start'] ) ? sanitize_text_field( $item['start'] ) : '',
					'end'         => isset( $item['end'] ) ? sanitize_text_field( $item['end'] ) : '',
				);
			}
		}

		return array(
			'participants' => isset( $snapshot['participants'] ) ? max( 1, (int) $snapshot['participants'] ) : 1,
			'items'        => $items,
		);
	}
}
