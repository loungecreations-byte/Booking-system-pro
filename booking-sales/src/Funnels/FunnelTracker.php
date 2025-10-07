<?php

declare(strict_types=1);

namespace BSP\Sales\Funnels;

use wpdb;

use function add_action;
use function current_time;
use function get_current_user_id;
use function is_ssl;
use function sanitize_key;
use function sanitize_text_field;
use function setcookie;
use function wp_generate_uuid4;
use function wp_json_encode;

final class FunnelTracker {

	private const SESSION_COOKIE = 'sbdp_funnel_session';

	public static function init(): void {
		add_action( 'init', array( self::class, 'ensureSession' ), 5 );
		add_action( 'sbdp/promotions/created', array( self::class, 'handlePromotionCreated' ), 10, 3 );
		add_action( 'sbdp/promotions/updated', array( self::class, 'handlePromotionUpdated' ), 10, 3 );
		add_action( 'sbdp/promotions/status_changed', array( self::class, 'handlePromotionStatusChanged' ), 10, 5 );
	}

	public static function ensureSession(): void {
		if ( isset( $_COOKIE[ self::SESSION_COOKIE ] ) ) {
			return;
		}

		$sessionId = 'web-' . wp_generate_uuid4();
		setcookie( self::SESSION_COOKIE, $sessionId, time() + DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true );
		$_COOKIE[ self::SESSION_COOKIE ] = $sessionId;
	}

	public static function handlePromotionCreated( array $promotion, int $userId, array $context ): void {
		self::logEvent(
			array(
				'step'         => 'promotion_created',
				'promotion_id' => $promotion['id'] ?? null,
				'payload'      => array(
					'code'   => $promotion['code'] ?? '',
					'status' => $promotion['status'] ?? '',
				),
				'context'      => $context,
			)
		);
	}

	public static function handlePromotionUpdated( array $promotion, int $userId, array $context ): void {
		self::logEvent(
			array(
				'step'         => 'promotion_updated',
				'promotion_id' => $promotion['id'] ?? null,
				'payload'      => array(
					'code'   => $promotion['code'] ?? '',
					'status' => $promotion['status'] ?? '',
				),
				'context'      => $context,
			)
		);
	}

	public static function handlePromotionStatusChanged( array $promotion, int $userId, array $context, string $status, int $promotionId ): void {
		self::logEvent(
			array(
				'step'         => 'promotion_status_changed',
				'promotion_id' => $promotionId,
				'payload'      => array(
					'code'   => $promotion['code'] ?? '',
					'status' => $status,
				),
				'context'      => $context,
			)
		);
	}

	private static function logEvent( array $args ): void {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		$table     = $wpdb->prefix . 'bsp_funnel_events';
		$sessionId = self::resolveSessionId( $args['context'] ?? array() );
		$step      = sanitize_key( (string) ( $args['step'] ?? 'unknown' ) );
		$payload   = isset( $args['payload'] ) ? wp_json_encode( $args['payload'] ) : '{}';

		$wpdb->insert(
			$table,
			array(
				'session_id'   => $sessionId,
				'customer_id'  => isset( $args['context']['customer_id'] ) ? (int) $args['context']['customer_id'] : null,
				'channel'      => self::sanitizeString( $args['context']['channel'] ?? null ),
				'outlet_id'    => isset( $args['context']['outlet_id'] ) ? (int) $args['context']['outlet_id'] : null,
				'step'         => $step,
				'step_payload' => $payload,
				'utm_source'   => self::sanitizeString( $args['context']['utm_source'] ?? null ),
				'utm_medium'   => self::sanitizeString( $args['context']['utm_medium'] ?? null ),
				'utm_campaign' => self::sanitizeString( $args['context']['utm_campaign'] ?? null ),
				'promotion_id' => isset( $args['promotion_id'] ) ? (int) $args['promotion_id'] : null,
				'occurred_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	private static function resolveSessionId( array $context ): string {
		if ( ! empty( $context['session_id'] ) && is_string( $context['session_id'] ) ) {
			return sanitize_text_field( $context['session_id'] );
		}

		if ( isset( $_COOKIE[ self::SESSION_COOKIE ] ) ) {
			return sanitize_text_field( (string) $_COOKIE[ self::SESSION_COOKIE ] );
		}

		$userId = (int) get_current_user_id();
		if ( $userId > 0 ) {
			return 'admin-' . $userId;
		}

		return 'anon-' . wp_generate_uuid4();
	}

	private static function sanitizeString( $value ): string {
		return $value ? sanitize_text_field( (string) $value ) : '';
	}
}
