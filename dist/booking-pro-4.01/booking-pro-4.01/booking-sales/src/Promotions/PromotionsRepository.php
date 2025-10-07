<?php

declare(strict_types=1);

namespace BSP\Sales\Promotions;

use WP_Error;
use wpdb;

use function absint;
use function current_time;
use function is_array;
use function json_decode;
use function sanitize_text_field;
use function wp_json_encode;

use const ARRAY_A;

final class PromotionsRepository {

	/** @var string[] */
	private const JSON_FIELDS = array( 'channel_scope', 'booking_scope', 'reward_payload' );

	public static function findAll( array $args = array() ): array {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return array();
		}

		$table  = self::tablePromotions();
		$where  = array();
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_text_field( (string) $args['status'] );
		}

		if ( ! empty( $args['code'] ) ) {
			$where[]  = 'code = %s';
			$params[] = sanitize_text_field( (string) $args['code'] );
		}

		$limit  = isset( $args['limit'] ) ? min( 100, max( 1, absint( (int) $args['limit'] ) ) ) : 50;
		$offset = isset( $args['offset'] ) ? max( 0, absint( (int) $args['offset'] ) ) : 0;

		$sql = "SELECT * FROM {$table}";
		if ( $where !== array() ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= ' ORDER BY updated_at DESC, id DESC';
		$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );

		if ( $params !== array() ) {
			$sql = $wpdb->prepare( $sql, ...$params );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ) ?: array();

		return array_map( array( self::class, 'hydrateRow' ), $rows );
	}

	public static function find( int $id ): ?array {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return null;
		}

		$table = self::tablePromotions();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return self::hydrateRow( $row );
	}

	public static function findByCode( string $code ): ?array {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return null;
		}

		$table = self::tablePromotions();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", sanitize_text_field( $code ) ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return self::hydrateRow( $row );
	}

	public static function insert( array $data ): array|WP_Error {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return new WP_Error( 'sbdp_promotions_db_unavailable', __( 'Database unavailable.', 'sbdp' ) );
		}

		$table   = self::tablePromotions();
		$payload = self::preparePersistencePayload( $data, true );

		$result = $wpdb->insert( $table, $payload['data'], $payload['format'] );
		if ( $result === false ) {
			return new WP_Error( 'sbdp_promotions_insert_failed', __( 'Could not create promotion.', 'sbdp' ) );
		}

		$promotionId = (int) $wpdb->insert_id;
		$promotion   = self::find( $promotionId );

		self::logAudit(
			$promotionId,
			'created',
			array(),
			$promotion ?? array(),
			absint( $data['updated_by'] ?? 0 ),
			(string) ( $data['audit_note'] ?? __( 'Created promotion.', 'sbdp' ) )
		);

		return $promotion ?? array();
	}

	public static function update( int $id, array $data ): array|WP_Error {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return new WP_Error( 'sbdp_promotions_db_unavailable', __( 'Database unavailable.', 'sbdp' ) );
		}

		$existing = self::find( $id );
		if ( $existing === null ) {
			return new WP_Error( 'sbdp_promotions_not_found', __( 'Promotion not found.', 'sbdp' ), array( 'status' => 404 ) );
		}

		$table   = self::tablePromotions();
		$payload = self::preparePersistencePayload( $data, false );
		$result  = $wpdb->update( $table, $payload['data'], array( 'id' => $id ), $payload['format'], array( '%d' ) );

		if ( $result === false ) {
			return new WP_Error( 'sbdp_promotions_update_failed', __( 'Could not update promotion.', 'sbdp' ) );
		}

		$updated = self::find( $id );

		self::logAudit(
			$id,
			'updated',
			$existing,
			$updated ?? array(),
			absint( $data['updated_by'] ?? 0 ),
			(string) ( $data['audit_note'] ?? __( 'Updated promotion.', 'sbdp' ) )
		);

		return $updated ?? array();
	}

	public static function changeStatus( int $id, string $status, int $userId, string $note = '' ): array|WP_Error {
		$promotion = self::find( $id );
		if ( $promotion === null ) {
			return new WP_Error( 'sbdp_promotions_not_found', __( 'Promotion not found.', 'sbdp' ), array( 'status' => 404 ) );
		}

		$result = self::update(
			$id,
			array(
				'status'     => $status,
				'updated_by' => $userId,
				'audit_note' => $note !== '' ? $note : sprintf( __( 'Status changed to %s.', 'sbdp' ), $status ),
			)
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return $result;
	}

	private static function preparePersistencePayload( array $data, bool $includeDefaults ): array {
		$now = current_time( 'mysql', true );
		if ( ! isset( $data['updated_at'] ) ) {
			$data['updated_at'] = $now;
		}

		if ( $includeDefaults && ! isset( $data['created_at'] ) ) {
			$data['created_at'] = $now;
		}

		$fields = array(
			'code'            => array(
				'format'  => '%s',
				'default' => '',
			),
			'name'            => array(
				'format'  => '%s',
				'default' => '',
			),
			'description'     => array(
				'format'  => '%s',
				'default' => '',
			),
			'type'            => array(
				'format'  => '%s',
				'default' => 'percentage',
			),
			'channel_scope'   => array(
				'format'  => '%s',
				'default' => '[]',
			),
			'booking_scope'   => array(
				'format'  => '%s',
				'default' => '[]',
			),
			'reward_payload'  => array(
				'format'  => '%s',
				'default' => '[]',
			),
			'stacking_policy' => array(
				'format'  => '%s',
				'default' => 'single',
			),
			'status'          => array(
				'format'  => '%s',
				'default' => 'draft',
			),
			'starts_at'       => array(
				'format'  => '%s',
				'default' => null,
			),
			'ends_at'         => array(
				'format'  => '%s',
				'default' => null,
			),
			'created_by'      => array(
				'format'  => '%d',
				'default' => 0,
			),
			'updated_by'      => array(
				'format'  => '%d',
				'default' => 0,
			),
			'created_at'      => array(
				'format'  => '%s',
				'default' => $now,
			),
			'updated_at'      => array(
				'format'  => '%s',
				'default' => $now,
			),
		);

		$dataPayload   = array();
		$formatPayload = array();

		foreach ( $fields as $key => $meta ) {
			if ( ! array_key_exists( $key, $data ) ) {
				if ( $includeDefaults ) {
					$dataPayload[ $key ] = $meta['default'];
					$formatPayload[]     = $meta['format'];
				}
				continue;
			}

			$value = $data[ $key ];
			if ( in_array( $key, self::JSON_FIELDS, true ) ) {
				$value = self::encodeJsonValue( $value );
			}

			if ( $meta['default'] === null && ( $value === '' || $value === null ) ) {
				$value = null;
			}

			$dataPayload[ $key ] = $value;
			$formatPayload[]     = $meta['format'];
		}

		return array(
			'data'   => $dataPayload,
			'format' => $formatPayload,
		);
	}

	private static function encodeJsonValue( $value ): string {
		if ( $value === null ) {
			return '[]';
		}

		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				return wp_json_encode( $decoded );
			}
		}

		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}

		return wp_json_encode( $value );
	}

	private static function hydrateRow( array $row ): array {
		foreach ( self::JSON_FIELDS as $field ) {
			$raw           = $row[ $field ] ?? '[]';
			$decoded       = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
			$row[ $field ] = is_array( $decoded ) ? $decoded : array();
		}

		return $row;
	}

	private static function tablePromotions(): string {
		global $wpdb;
		return $wpdb->prefix . 'bsp_promotions';
	}

	private static function logAudit( int $promotionId, string $changeType, array $before, array $after, int $userId, string $note ): void {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		$wpdb->insert(
			self::tableAudit(),
			array(
				'promotion_id'   => $promotionId,
				'changed_by'     => $userId,
				'change_type'    => sanitize_text_field( $changeType ),
				'payload_before' => wp_json_encode( $before ),
				'payload_after'  => wp_json_encode( $after ),
				'note'           => $note,
				'changed_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	private static function tableAudit(): string {
		global $wpdb;
		return $wpdb->prefix . 'bsp_promotion_audit';
	}
}
