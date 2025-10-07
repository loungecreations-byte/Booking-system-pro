<?php

declare(strict_types=1);

namespace BSP\Sales\Pricing;

use WC_Product;
use wpdb;

use function abs;
use function add_filter;
use function array_map;
use function apply_filters;
use function ceil;
use function current_time;
use function floor;
use function get_option;
use function get_post_meta;
use function in_array;
use function is_array;
use function is_numeric;
use function json_decode;
use function max;
use function min;
use function round;
use function strtolower;
use function str_contains;
use function wc_get_product;
use function wp_cache_delete;
use function wp_cache_get;
use function wp_cache_set;
use function wp_date;

use const MINUTE_IN_SECONDS;

if ( class_exists( __NAMESPACE__ . '\\YieldEngine', false ) ) {
	return;
}

final class YieldEngine {

	private const CACHE_GROUP     = 'bsp_sales';
	private const CACHE_KEY_RULES = 'yield_rules';

	private static bool $booted   = false;
	private static bool $applying = false;

	/**
	 * @var array<int,array<string,mixed>>
	 */
	private static array $matchedRules = array();

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_filter( 'woocommerce_product_get_price', array( self::class, 'filterPrice' ), 20, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( self::class, 'filterPrice' ), 20, 2 );
	}

	/**
	 * @param string|float $price
	 * @return string|float
	 */
	public static function filterPrice( $price, WC_Product $product ) {
		if ( self::$applying || $price === '' || $price === null ) {
			return $price;
		}

		$numeric = (float) $price;
		if ( $numeric <= 0.0 ) {
			return $price;
		}

		self::$applying = true;

		try {
			$adjusted = self::calculateAdjustedPrice( $product, $numeric );
		} finally {
			self::$applying = false;
		}

		return $adjusted;
	}

	public static function calculateAdjustedPrice( WC_Product $product, float $basePrice ): float {
		$context            = self::buildContext( $product, $basePrice );
		$price              = $basePrice;
		self::$matchedRules = array();

		foreach ( self::getActiveRules() as $rule ) {
			if ( ! self::ruleMatches( $rule, $context ) ) {
				continue;
			}

			self::$matchedRules[] = array(
				'id'   => isset( $rule['id'] ) ? (int) $rule['id'] : 0,
				'name' => isset( $rule['name'] ) ? (string) $rule['name'] : '',
			);

			$price = self::applyAdjustment( $rule, $price, $context, $product );
		}

		$price = apply_filters( 'bsp/sales/yield/final_price', $price, $context, $product );

		return max( 0.0, round( (float) $price, 2 ) );
	}

	public static function applyRulesForProductId( int $productId ): float {
		$product = wc_get_product( $productId );
		if ( ! $product instanceof WC_Product ) {
			return 0.0;
		}

		$base = (float) $product->get_price( 'edit' );
		if ( $base <= 0.0 ) {
			$base = (float) $product->get_regular_price( 'edit' );
		}

		$previousState  = self::$applying;
		self::$applying = true;

		try {
			$adjusted = self::calculateAdjustedPrice( $product, $base );
		} finally {
			self::$applying = $previousState;
		}

		return $adjusted;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function getMatchedRules(): array {
		return self::$matchedRules;
	}

	public static function flushRuleCache(): void {
		wp_cache_delete( self::CACHE_KEY_RULES, self::CACHE_GROUP );
	}

	public static function rebuildCache(): void {
		self::flushRuleCache();
		self::getActiveRules();
	}

	private static function getActiveRules(): array {
		$cached = wp_cache_get( self::CACHE_KEY_RULES, self::CACHE_GROUP );
		if ( $cached !== false ) {
			return is_array( $cached ) ? $cached : array();
		}

		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return array();
		}

		$table = $wpdb->prefix . 'bsp_yield_rules';
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE active = 1 ORDER BY priority DESC, id ASC", ARRAY_A ) ?: array();

		wp_cache_set( self::CACHE_KEY_RULES, $rows, self::CACHE_GROUP, MINUTE_IN_SECONDS );

		return $rows;
	}

	private static function ruleMatches( array $ruleRow, array $context ): bool {
		$definition = self::decodeJson( $ruleRow['condition_json'] ?? null );
		if ( $definition === array() || $definition === null ) {
			return true;
		}

		$conditions = $definition['conditions'] ?? $definition;
		if ( ! is_array( $conditions ) ) {
			return true;
		}

		$mode = strtolower( (string) ( $definition['mode'] ?? 'all' ) );
		$mode = in_array( $mode, array( 'any', 'or' ), true ) ? 'any' : 'all';

		$results = array();
		foreach ( $conditions as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$results[] = self::evaluateCondition( $condition, $context );
		}

		if ( $results === array() ) {
			return true;
		}

		return $mode === 'any' ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	private static function evaluateCondition( array $condition, array $context ): bool {
		$metric   = strtolower( (string) ( $condition['metric'] ?? '' ) );
		$operator = strtolower( (string) ( $condition['operator'] ?? '==' ) );
		$value    = $condition['value'] ?? null;

		$subject = $context[ $metric ] ?? null;
		if ( $subject === null ) {
			$subject = apply_filters( 'bsp/sales/yield/condition_subject', $subject, $metric, $context, $condition );
		}

		if ( $operator === 'between' && is_array( $value ) && count( $value ) >= 2 ) {
			$minValue = (float) $value[0];
			$maxValue = (float) $value[1];
			$lower    = min( $minValue, $maxValue );
			$upper    = max( $minValue, $maxValue );

			return is_numeric( $subject ) && (float) $subject >= $lower && (float) $subject <= $upper;
		}

		if ( is_numeric( $subject ) && is_numeric( $value ) ) {
			return self::compareNumeric( (float) $subject, (float) $value, $operator );
		}

		return self::compareText( (string) $subject, $value, $operator );
	}

	private static function applyAdjustment( array $ruleRow, float $price, array $context, WC_Product $product ): float {
		$definition = self::decodeJson( $ruleRow['adjustment_json'] ?? null ) ?? array();
		$type       = strtolower( (string) ( $definition['type'] ?? 'percentage' ) );
		$value      = (float) ( $definition['value'] ?? 0.0 );

		switch ( $type ) {
			case 'set':
				$price = max( 0.0, $value );
				break;
			case 'fixed':
				$price += $value;
				break;
			case 'percentage':
			default:
				$price += $price * ( $value / 100 );
				break;
		}

		$rounding = strtolower( (string) ( $definition['rounding'] ?? '' ) );
		if ( $rounding !== '' ) {
			$precision = (int) ( $definition['precision'] ?? 2 );
			$price     = self::roundPrice( $price, $rounding, $precision );
		}

		return apply_filters( 'bsp/sales/yield/price_after_rule', $price, $ruleRow, $definition, $context, $product, $context['base_price'] );
	}

	private static function buildContext( WC_Product $product, float $basePrice ): array {
		$productId = $product->get_id();

		$occupancy = (float) get_post_meta( $productId, '_bsp_occupancy', true );
		$occupancy = apply_filters( 'bsp/sales/occupancy', $occupancy, $productId, $product );
		if ( $occupancy < 0.0 ) {
			$occupancy = 0.0;
		}

		$weather = apply_filters( 'bsp/sales/weather', strtolower( (string) get_option( 'bsp_weather_condition', 'clear' ) ), $productId, $product );
		$season  = apply_filters( 'bsp/sales/season', self::determineSeason(), $productId, $product );
		$weekday = apply_filters( 'bsp/sales/weekday', strtolower( wp_date( 'l' ) ), $productId, $product );

		return array(
			'product_id' => $productId,
			'base_price' => $basePrice,
			'occupancy'  => (float) $occupancy,
			'weather'    => strtolower( (string) $weather ),
			'season'     => strtolower( (string) $season ),
			'weekday'    => strtolower( (string) $weekday ),
		);
	}

	private static function determineSeason(): string {
		$month = (int) wp_date( 'n' );

		return match ( true ) {
			$month === 12, $month <= 2 => 'winter',
			$month <= 5                => 'spring',
			$month <= 8                => 'summer',
			default                    => 'autumn',
		};
	}

	private static function compareNumeric( float $subject, float $value, string $operator ): bool {
		return match ( $operator ) {
			'>'  => $subject > $value,
			'>=' => $subject >= $value,
			'<'  => $subject < $value,
			'<=' => $subject <= $value,
			'!=', 'not_equals', '!==' => $subject !== $value,
			default => $subject === $value,
		};
	}

	private static function compareText( string $subject, $value, string $operator ): bool {
		$subject = strtolower( $subject );

		if ( is_array( $value ) ) {
			$value = array_map( static fn( $item ) => strtolower( (string) $item ), $value );
		} else {
			$value = strtolower( (string) $value );
		}

		return match ( $operator ) {
			'in'      => is_array( $value ) ? in_array( $subject, $value, true ) : $subject === $value,
			'not_in'  => is_array( $value ) ? ! in_array( $subject, $value, true ) : $subject !== $value,
			'!=', 'not_equals', '!==' => $subject !== ( is_array( $value ) ? (string) reset( $value ) : (string) $value ),
			'contains' => ! is_array( $value ) && str_contains( $subject, (string) $value ),
			default    => $subject === ( is_array( $value ) ? (string) reset( $value ) : (string) $value ),
		};
	}

	private static function roundPrice( float $price, string $strategy, int $precision ): float {
		$precision = max( 0, min( 4, $precision ) );
		$factor    = 10 ** $precision;

		return match ( $strategy ) {
			'up', 'ceil'    => ceil( $price * $factor ) / $factor,
			'down', 'floor' => floor( $price * $factor ) / $factor,
			default         => round( $price, $precision ),
		};
	}

	private static function decodeJson( ?string $json ): ?array {
		if ( ! $json ) {
			return null;
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\bsp_apply_yield_rules' ) ) {
	function bsp_apply_yield_rules( int $product_id ): float {
		return YieldEngine::applyRulesForProductId( $product_id );
	}
}

if ( ! class_exists( 'BSPModule\\Sales\\Pricing\\YieldEngine' ) ) {
	\class_alias( YieldEngine::class, 'BSPModule\\Sales\\Pricing\\YieldEngine' );
}
