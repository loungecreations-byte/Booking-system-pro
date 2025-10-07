<?php

declare(strict_types=1);

namespace BSP\Sales\Channels;

use WP_Error;
use WP_Query;
use wpdb;

use function absint;
use function apply_filters;
use function array_column;
use function array_map;
use function current_time;
use function function_exists;
use function is_array;
use function is_wp_error;
use function wc_get_products;
use function __;

use const ARRAY_A;

final class ChannelManager {

	public static function init(): void {
		// Reserved for future hooks (e.g., webhooks or settings).
	}

	public static function getChannels( bool $onlyActive = false ): array {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return array();
		}

		$table = $wpdb->prefix . 'bsp_channels';
		$where = $onlyActive ? ' WHERE active = 1' : '';
		$rows  = $wpdb->get_results( "SELECT id, name, commission_rate, sync_status, last_sync, last_error, active FROM {$table}{$where} ORDER BY name ASC", ARRAY_A ) ?: array();

		return array_map(
			static function ( array $row ): array {
				$row['commission_rate'] = (float) $row['commission_rate'];
				$row['active']          = (int) $row['active'];
				return $row;
			},
			$rows
		);
	}

	public static function getChannel( int $id ): ?array {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return null;
		}

		$table = $wpdb->prefix . 'bsp_channels';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}

		$row['commission_rate'] = (float) $row['commission_rate'];
		$row['active']          = (int) $row['active'];

		return $row;
	}

	public static function syncAll( ?array $ids = null ) {
		$channels = $ids ? array_map( 'absint', $ids ) : array_column( self::getChannels( true ), 'id' );
		if ( $channels === array() ) {
			return new WP_Error( 'bsp_sales_no_channels', __( 'No sales channels configured.', 'sbdp' ), array( 'status' => 404 ) );
		}

		$details = array();
		foreach ( $channels as $channelId ) {
			$result = self::syncChannel( (int) $channelId );
			if ( is_wp_error( $result ) ) {
				$details[] = array(
					'id'      => (int) $channelId,
					'status'  => 'failed',
					'synced'  => 0,
					'errors'  => 1,
					'message' => $result->get_error_message(),
				);
				continue;
			}
			$details[] = $result;
		}

		return array(
			'count'   => count( $details ),
			'details' => $details,
		);
	}

	public static function cliSync( $channel = null ) {
		if ( $channel !== null && $channel !== '' ) {
			$result = self::syncChannel( absint( (int) $channel ) );
			return is_wp_error( $result ) ? $result : array(
				'count'   => 1,
				'details' => array( $result ),
			);
		}

		return self::syncAll();
	}

	public static function syncChannel( int $channelId ) {
		$channel = self::getChannel( $channelId );
		if ( ! $channel ) {
			return new WP_Error( 'bsp_sales_missing_channel', __( 'Channel not found.', 'sbdp' ), array( 'status' => 404 ) );
		}

		if ( ! (int) $channel['active'] ) {
			return new WP_Error( 'bsp_sales_inactive_channel', __( 'Channel is disabled.', 'sbdp' ), array( 'status' => 409 ) );
		}

		$products = self::collectProducts();
		$synced   = count( $products );
		$status   = 'success';
		$message  = __( 'Products synchronised successfully.', 'sbdp' );
		$errors   = array();

		if ( $channel['api_key'] === '' ) {
			$status   = 'failed';
			$message  = __( 'Missing API key.', 'sbdp' );
			$errors[] = $message;
		}

		$payload = apply_filters(
			'bsp/sales/channel/sync_payload',
			array(
				'channel'  => $channel,
				'products' => $products,
				'status'   => $status,
				'errors'   => $errors,
			),
			$channelId
		);

		if ( is_array( $payload ) && ! empty( $payload['errors'] ) ) {
			$status  = 'failed';
			$errors  = array_map( 'strval', (array) $payload['errors'] );
			$message = implode( '; ', $errors );
		}

		$timestamp = current_time( 'mysql', true );
		self::updateChannelState( $channelId, $status, $timestamp, $status === 'failed' ? $message : '' );

		return array(
			'id'      => $channelId,
			'status'  => $status,
			'synced'  => $synced,
			'errors'  => count( $errors ),
			'message' => $message,
		);
	}

	private static function updateChannelState( int $channelId, string $status, string $timestamp, string $message ): void {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		$table = $wpdb->prefix . 'bsp_channels';
		$wpdb->update(
			$table,
			array(
				'sync_status' => $status,
				'last_sync'   => $timestamp,
				'last_error'  => $message,
			),
			array( 'id' => $channelId ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function collectProducts(): array {
		if ( function_exists( 'wc_get_products' ) ) {
			$ids = wc_get_products(
				array(
					'limit'  => -1,
					'status' => 'publish',
					'return' => 'ids',
				)
			);

			return array_map( static fn( $id ) => array( 'product_id' => (int) $id ), $ids );
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 200,
			)
		);

		return array_map( static fn( $id ) => array( 'product_id' => (int) $id ), $query->posts ?: array() );
	}
}

if ( ! class_exists( 'BSPModule\\Sales\\ChannelManager' ) ) {
	\class_alias( ChannelManager::class, 'BSPModule\\Sales\\ChannelManager' );
}
