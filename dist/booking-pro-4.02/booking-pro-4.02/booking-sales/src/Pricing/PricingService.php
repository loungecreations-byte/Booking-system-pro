<?php

declare(strict_types=1);

namespace BSP\Sales\Pricing;

use WC_Product;
use WP_Error;
use wpdb;

use function abs;
use function current_time;
use function get_option;
use function sanitize_text_field;
use function wc_get_product;
use function wp_json_encode;
use function __;

final class PricingService {

	public static function quote( int $productId, int $quantity = 1, array $context = array() ): array {
		$product = wc_get_product( $productId );
		if ( ! $product instanceof WC_Product ) {
			return array(
				'success' => false,
				'error'   => new WP_Error( 'bsp_sales_invalid_product', __( 'Product not found.', 'sbdp' ) ),
			);
		}

		$quantity = max( 1, $quantity );
		$base     = (float) $product->get_price( 'edit' );
		if ( $base <= 0.0 ) {
			$base = (float) $product->get_regular_price( 'edit' );
		}

		$adjustedSingle = YieldEngine::calculateAdjustedPrice( $product, $base );
		$total          = $adjustedSingle * $quantity;

		self::logPrice( $productId, $base, $adjustedSingle, $context['channel'] ?? 'web', $context );

		return array(
			'success'        => true,
			'product_id'     => $productId,
			'quantity'       => $quantity,
			'currency'       => get_option( 'woocommerce_currency' ),
			'base_price'     => round( $base, 2 ),
			'adjusted_price' => round( $adjustedSingle, 2 ),
			'total_adjusted' => round( $total, 2 ),
			'applied_rules'  => YieldEngine::getMatchedRules(),
		);
	}

	public static function logPrice( int $productId, float $basePrice, float $adjustedPrice, string $channel, array $context = array() ): void {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		$table = $wpdb->prefix . 'bsp_price_log';
		$wpdb->insert(
			$table,
			array(
				'product_id'     => $productId,
				'rule_id'        => $context['rule_id'] ?? null,
				'context'        => wp_json_encode( $context ),
				'base_price'     => $basePrice,
				'adjusted_price' => $adjustedPrice,
				'channel'        => sanitize_text_field( $channel ),
				'logged_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%f', '%f', '%s', '%s' )
		);
	}
}
