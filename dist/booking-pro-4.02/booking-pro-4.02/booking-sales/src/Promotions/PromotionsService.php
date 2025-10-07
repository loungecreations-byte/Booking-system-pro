<?php

declare(strict_types=1);

namespace BSP\Sales\Promotions;

use WP_Error;
use WP_Role;

use function __;
use function absint;
use function add_action;
use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function current_time;
use function get_role;
use function gmdate;
use function in_array;
use function is_array;
use function is_numeric;
use function json_decode;
use function preg_replace;
use function do_action;
use function function_exists;
use function sanitize_key;
use function sanitize_text_field;
use function sprintf;
use function strtotime;
use function strtoupper;

final class PromotionsService {

	public const CAPABILITY   = 'manage_sbdp_promotions';
	public const NONCE_ACTION = 'sbdp_promotions_manage';

	private const ALLOWED_TYPES    = array( 'percentage', 'fixed', 'bundle', 'loyalty_boost' );
	private const ALLOWED_STACKING = array( 'single', 'stackable' );
	private const ALLOWED_STATUSES = array( 'draft', 'scheduled', 'active', 'archived' );

	public static function init(): void {
		add_action( 'init', array( self::class, 'ensureCapability' ) );
	}

	public static function ensureCapability(): void {
		$role = get_role( 'administrator' );
		if ( $role instanceof WP_Role && ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}
	}

	public static function listPromotions( array $args = array() ): array {
		return PromotionsRepository::findAll( $args );
	}

	public static function getPromotion( int $id ): ?array {
		return PromotionsRepository::find( $id );
	}

	public static function createPromotion( array $payload, int $userId, array $context = array() ): array|WP_Error {
		$normalized = self::normalizePayload( $payload, false );
		if ( $normalized instanceof WP_Error ) {
			return $normalized;
		}

		$code = $normalized['code'];
		if ( PromotionsRepository::findByCode( $code ) !== null ) {
			return new WP_Error( 'sbdp_promotions_code_exists', __( 'Promotion code already exists.', 'sbdp' ), array( 'status' => 409 ) );
		}

		$normalized['created_by'] = $userId;
		$normalized['updated_by'] = $userId;
		$normalized['status']     = $normalized['status'] ?? 'draft';
		$normalized['audit_note'] = $normalized['audit_note'] ?? __( 'Created via API.', 'sbdp' );

		$result = PromotionsRepository::insert( $normalized );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		self::dispatchEvent( 'created', $result, $userId, $context );

		return $result;
	}

	public static function updatePromotion( int $id, array $payload, int $userId, array $context = array() ): array|WP_Error {
		$existing = PromotionsRepository::find( $id );
		if ( $existing === null ) {
			return new WP_Error( 'sbdp_promotions_not_found', __( 'Promotion not found.', 'sbdp' ), array( 'status' => 404 ) );
		}

		$normalized = self::normalizePayload( $payload, true );
		if ( $normalized instanceof WP_Error ) {
			return $normalized;
		}

		if ( isset( $normalized['code'] ) && strtoupper( (string) $normalized['code'] ) !== strtoupper( (string) $existing['code'] ) ) {
			if ( PromotionsRepository::findByCode( $normalized['code'] ) !== null ) {
				return new WP_Error( 'sbdp_promotions_code_exists', __( 'Promotion code already exists.', 'sbdp' ), array( 'status' => 409 ) );
			}
		}

		$normalized['updated_by'] = $userId;
		$normalized['audit_note'] = $normalized['audit_note'] ?? __( 'Updated via API.', 'sbdp' );

		$result = PromotionsRepository::update( $id, $normalized );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		self::dispatchEvent( 'updated', $result, $userId, $context );

		return $result;
	}

	public static function transitionPromotion( int $id, string $status, int $userId, array $context = array() ): array|WP_Error {
		$status = sanitize_key( $status );
		if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			return new WP_Error( 'sbdp_promotions_invalid_status', __( 'Unsupported status transition.', 'sbdp' ), array( 'status' => 422 ) );
		}

		$promotion = PromotionsRepository::find( $id );
		if ( $promotion === null ) {
			return new WP_Error( 'sbdp_promotions_not_found', __( 'Promotion not found.', 'sbdp' ), array( 'status' => 404 ) );
		}

		if ( $promotion['status'] === 'archived' && $status !== 'archived' ) {
			return new WP_Error( 'sbdp_promotions_archived', __( 'Archived promotions cannot be reactivated.', 'sbdp' ), array( 'status' => 409 ) );
		}

		$result = PromotionsRepository::changeStatus( $id, $status, $userId, sprintf( __( 'Status changed to %s.', 'sbdp' ), $status ) );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		self::dispatchEvent( 'status_changed', $result, $userId, array_merge( $context, array( 'status' => $status ) ) );

		return $result;
	}

	public static function previewPromotion( array $promotion, array $context = array() ): array {
		return array(
			'promotion' => $promotion,
			'context'   => $context,
			'applies'   => true,
			'notes'     => __( 'Preview simulation is stubbed for initial prototype.', 'sbdp' ),
		);
	}

	private static function dispatchEvent( string $event, array $promotion, int $userId, array $context = array() ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		do_action( 'sbdp/promotions/' . $event, $promotion, $userId, $context );
	}

	private static function normalizePayload( array $payload, bool $isUpdate ): array|WP_Error {
		$normalized = array();

		if ( ! $isUpdate || array_key_exists( 'code', $payload ) ) {
			$rawCode = isset( $payload['code'] ) ? (string) $payload['code'] : '';
			$code    = strtoupper( preg_replace( '/[^A-Z0-9_-]/i', '', $rawCode ) );
			if ( $code === '' ) {
				return new WP_Error( 'sbdp_promotions_missing_code', __( 'Promotion code is required.', 'sbdp' ), array( 'status' => 422 ) );
			}
			$normalized['code'] = $code;
		}

		if ( ! $isUpdate || array_key_exists( 'name', $payload ) ) {
			$name = isset( $payload['name'] ) ? sanitize_text_field( (string) $payload['name'] ) : '';
			if ( $name === '' ) {
				return new WP_Error( 'sbdp_promotions_missing_name', __( 'Promotion name is required.', 'sbdp' ), array( 'status' => 422 ) );
			}
			$normalized['name'] = $name;
		}

		if ( array_key_exists( 'description', $payload ) ) {
			$normalized['description'] = sanitize_text_field( (string) $payload['description'] );
		}

		if ( ! $isUpdate || array_key_exists( 'type', $payload ) ) {
			$type = isset( $payload['type'] ) ? sanitize_key( (string) $payload['type'] ) : '';
			if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
				return new WP_Error( 'sbdp_promotions_invalid_type', __( 'Unsupported promotion type.', 'sbdp' ), array( 'status' => 422 ) );
			}
			$normalized['type'] = $type;
		}

		if ( array_key_exists( 'stacking_policy', $payload ) ) {
			$policy = sanitize_key( (string) $payload['stacking_policy'] );
			if ( ! in_array( $policy, self::ALLOWED_STACKING, true ) ) {
				return new WP_Error( 'sbdp_promotions_invalid_stacking', __( 'Unsupported stacking policy.', 'sbdp' ), array( 'status' => 422 ) );
			}
			$normalized['stacking_policy'] = $policy;
		}

		if ( array_key_exists( 'status', $payload ) ) {
			$status = sanitize_key( (string) $payload['status'] );
			if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
				return new WP_Error( 'sbdp_promotions_invalid_status', __( 'Unsupported promotion status.', 'sbdp' ), array( 'status' => 422 ) );
			}
			$normalized['status'] = $status;
		}

		if ( array_key_exists( 'channel_scope', $payload ) ) {
			$normalized['channel_scope'] = self::normalizeArray( $payload['channel_scope'] );
		}

		if ( array_key_exists( 'booking_scope', $payload ) ) {
			$normalized['booking_scope'] = self::normalizeArray( $payload['booking_scope'] );
		}

		if ( array_key_exists( 'reward_payload', $payload ) ) {
			$normalized['reward_payload'] = is_array( $payload['reward_payload'] ) ? $payload['reward_payload'] : array();
		}

		if ( array_key_exists( 'starts_at', $payload ) ) {
			$normalized['starts_at'] = self::normalizeDate( $payload['starts_at'] );
			if ( $payload['starts_at'] !== null && $normalized['starts_at'] === null ) {
				return new WP_Error( 'sbdp_promotions_invalid_start', __( 'Invalid start date.', 'sbdp' ), array( 'status' => 422 ) );
			}
		}

		if ( array_key_exists( 'ends_at', $payload ) ) {
			$normalized['ends_at'] = self::normalizeDate( $payload['ends_at'] );
			if ( $payload['ends_at'] !== null && $normalized['ends_at'] === null ) {
				return new WP_Error( 'sbdp_promotions_invalid_end', __( 'Invalid end date.', 'sbdp' ), array( 'status' => 422 ) );
			}
		}

		if ( isset( $normalized['starts_at'], $normalized['ends_at'] ) && $normalized['ends_at'] < $normalized['starts_at'] ) {
			return new WP_Error( 'sbdp_promotions_range', __( 'End date must be after start date.', 'sbdp' ), array( 'status' => 422 ) );
		}

		if ( array_key_exists( 'audit_note', $payload ) ) {
			$normalized['audit_note'] = sanitize_text_field( (string) $payload['audit_note'] );
		}

		return $normalized;
	}

	private static function normalizeArray( $value ): array {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				$value = $decoded;
			}
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array_map( static fn( $item ) => sanitize_text_field( (string) $item ), $value );

		return array_values( array_filter( $sanitized, static fn( $item ) => $item !== '' ) );
	}

	private static function normalizeDate( $value ): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}

		$timestamp = is_numeric( $value )
			? (int) $value
			: strtotime( (string) $value );

		if ( $timestamp === false ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
