<?php

declare(strict_types=1);

namespace BSP\Sales;

use WP_Error;
use WP_REST_Request;
use wpdb;

use function absint;
use function add_action;
use function apply_filters;
use function class_exists;
use function current_time;
use function is_array;
use function is_user_logged_in;
use function is_wp_error;
use function max;
use function min;
use function rest_authorization_required_code;
use function rest_ensure_response;
use function round;
use function sanitize_textarea_field;
use function str_contains;
use function strtolower;
use function wp_unslash;
use function __;

if ( class_exists( __NAMESPACE__ . '\\ReviewManager', false ) ) {
	return;
}

final class ReviewManager {

	private static bool $booted = false;

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );
	}

	public static function registerRoutes(): void {
		\register_rest_route(
			'bsp/v1',
			'/reviews',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'restCreateReview' ),
				'permission_callback' => array( self::class, 'permissionCheck' ),
				'args'                => array(
					'booking_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'rating'     => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => 5,
						'sanitize_callback' => 'absint',
					),
					'comment'    => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	public static function permissionCheck() {
		$allowPublic = apply_filters( 'bsp/sales/reviews/allow_public', false );
		if ( $allowPublic || is_user_logged_in() ) {
			return true;
		}

		return new WP_Error(
			'bsp_sales_reviews_forbidden',
			__( 'Authentication is required to submit reviews.', 'sbdp' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public static function restCreateReview( WP_REST_Request $request ) {
		$bookingId = absint( $request->get_param( 'booking_id' ) );
		$rating    = absint( $request->get_param( 'rating' ) );
		$comment   = $request->get_param( 'comment' );

		if ( $bookingId <= 0 ) {
			return new WP_Error( 'bsp_sales_invalid_booking', __( 'Invalid booking identifier.', 'sbdp' ), array( 'status' => 400 ) );
		}

		if ( $rating < 1 || $rating > 5 ) {
			return new WP_Error( 'bsp_sales_invalid_rating', __( 'Rating must be between 1 and 5.', 'sbdp' ), array( 'status' => 422 ) );
		}

		$comment = $comment !== null ? sanitize_textarea_field( wp_unslash( (string) $comment ) ) : '';

		$sentiment = self::calculateSentimentScore( $rating, $comment );

		$review = self::storeReview( $bookingId, $rating, $comment, $sentiment );
		if ( is_wp_error( $review ) ) {
			return $review;
		}

		return rest_ensure_response(
			array(
				'review' => $review,
			)
		);
	}

	public static function cliAnalyze( string $mode = 'latest' ) {
		$mode = strtolower( $mode );
		if ( $mode !== 'latest' ) {
			return new WP_Error( 'bsp_sales_unknown_mode', __( 'Unsupported analyze mode.', 'sbdp' ) );
		}

		$review = self::getLatestReview();
		if ( ! $review ) {
			return new WP_Error( 'bsp_sales_no_reviews', __( 'No reviews available for analysis.', 'sbdp' ) );
		}

		$sentiment = self::calculateSentimentScore( (int) $review['rating'], (string) $review['comment'] );
		self::updateSentiment( (int) $review['id'], $sentiment );
		$review['sentiment_score'] = $sentiment;

		return array(
			'message' => __( 'Latest review sentiment recalculated.', 'sbdp' ),
			'review'  => $review,
		);
	}

	private static function storeReview( int $bookingId, int $rating, string $comment, float $sentiment ) {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return new WP_Error( 'bsp_sales_db_unavailable', __( 'Database unavailable.', 'sbdp' ), array( 'status' => 500 ) );
		}

		$table    = $wpdb->prefix . 'bsp_reviews';
		$inserted = $wpdb->insert(
			$table,
			array(
				'booking_id'      => $bookingId,
				'rating'          => $rating,
				'comment'         => $comment,
				'sentiment_score' => $sentiment,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%f', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'bsp_sales_save_failed', __( 'Unable to store review.', 'sbdp' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;

		return array(
			'id'              => $id,
			'booking_id'      => $bookingId,
			'rating'          => $rating,
			'comment'         => $comment,
			'sentiment_score' => $sentiment,
			'created_at'      => current_time( 'mysql', true ),
		);
	}

	private static function calculateSentimentScore( int $rating, string $comment ): float {
		$score        = $rating * 20.0;
		$commentLower = strtolower( $comment );
		$positiveHits = 0;
		$negativeHits = 0;

		foreach ( array( 'amazing', 'great', 'wonderful', 'perfect', 'friendly' ) as $needle ) {
			if ( str_contains( $commentLower, $needle ) ) {
				++$positiveHits;
			}
		}

		foreach ( array( 'bad', 'poor', 'terrible', 'late', 'cold' ) as $needle ) {
			if ( str_contains( $commentLower, $needle ) ) {
				++$negativeHits;
			}
		}

		$score += $positiveHits * 5;
		$score -= $negativeHits * 7;

		$score = apply_filters( 'bsp/sales/reviews/sentiment', $score, $rating, $comment );

		return round( max( 0.0, min( 100.0, (float) $score ) ), 2 );
	}

	private static function getLatestReview(): ?array {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return null;
		}

		$table = $wpdb->prefix . 'bsp_reviews';
		$row   = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT 1", ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		$row['rating']          = (int) $row['rating'];
		$row['sentiment_score'] = (float) $row['sentiment_score'];

		return $row;
	}

	private static function updateSentiment( int $reviewId, float $sentiment ): void {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		$table = $wpdb->prefix . 'bsp_reviews';
		$wpdb->update(
			$table,
			array( 'sentiment_score' => $sentiment ),
			array( 'id' => $reviewId ),
			array( '%f' ),
			array( '%d' )
		);
	}
}

if ( ! class_exists( 'BSPModule\\Sales\\ReviewManager' ) ) {
	\class_alias( ReviewManager::class, 'BSPModule\\Sales\\ReviewManager' );
}
