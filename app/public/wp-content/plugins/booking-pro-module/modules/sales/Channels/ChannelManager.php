<?php

declare(strict_types=1);

namespace BSP\Sales\Channels;

use WP_Error;
use WP_Post;
use WP_Query;
use wpdb;

use function absint;
use function add_action;
use function apply_filters;
use function array_column;
use function array_diff;
use function array_map;
use function array_unique;
use function current_time;
use function function_exists;
use function get_post;
use function is_array;
use function is_wp_error;
use function wc_get_products;
use function wp_is_post_autosave;
use function wp_is_post_revision;
use function __;

use const ARRAY_A;

final class ChannelManager {

	public static function init(): void {
		add_action( 'save_post_product', array( __CLASS__, 'handleProductSave' ), 20, 3 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'handleProductUpdate' ), 20, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'handleProductDelete' ), 20 );
	}

	public static function handleProductSave( int $postId, WP_Post $post, bool $update ): void {
		if ( $post->post_type !== 'product' || 'auto-draft' === $post->post_status ) {
			return;
		}

		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $postId ) ) {
			return;
		}

		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $postId ) ) {
			return;
		}

		self::enqueueProductSync( $postId );
	}

	public static function handleProductUpdate( int $productId ): void {
		if ( $productId <= 0 ) {
			return;
		}

		self::enqueueProductSync( $productId );
	}

	public static function handleProductDelete( int $postId ): void {
		$post = get_post( $postId );
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}

		ChannelSyncQueue::enqueue( 'product', $postId, array( 'product_id' => $postId, 'action' => 'delete' ) );
	}

	public static function enqueueProductSync( int $productId, ?int $channelId = null ): void {
		ChannelSyncQueue::enqueue( 'product', $productId, array( 'product_id' => $productId ), $channelId );
	}

	public static function processQueue( int $limit = 25 ): array {
		ChannelSyncQueue::releaseStaleLocks();
		$items = ChannelSyncQueue::claim( $limit );

		$stats = array(
			'processed' => 0,
			'failed'    => 0,
		);

		if ( $items === array() ) {
			return $stats;
		}

		$activeChannels = null;

		foreach ( $items as $item ) {
			$targets = array();
			if ( ! empty( $item['channel_id'] ) ) {
				$targets[] = (int) $item['channel_id'];
			} else {
				if ( null === $activeChannels ) {
					$activeChannels = array_column( self::getChannels( true ), 'id' );
				}
				$targets = $activeChannels ?: array();
			}

			if ( $targets === array() ) {
				ChannelSyncQueue::markCompleted( $item['id'] );
				$stats['processed']++;
				continue;
			}

			$payload    = is_array( $item['payload'] ) ? $item['payload'] : array();
			$productIds = array();
			if ( $item['entity_type'] === 'product' ) {
				if ( ! empty( $payload['product_id'] ) ) {
					$productIds[] = (int) $payload['product_id'];
				} elseif ( ! empty( $item['entity_id'] ) ) {
					$productIds[] = (int) $item['entity_id'];
				}
			}

			$productIds = array_values( array_unique( $productIds ) );
			$allSucceeded = true;

			foreach ( $targets as $channelId ) {
				$result = self::syncChannel( (int) $channelId, $productIds ?: null );
				if ( is_wp_error( $result ) || ( is_array( $result ) && isset( $result['status'] ) && 'failed' === $result['status'] ) ) {
					$message = is_wp_error( $result ) ? $result->get_error_message() : (string) ( $result['message'] ?? __( 'Onbekende synchronisatiefout.', 'sbdp' ) );
					ChannelSyncQueue::markFailedAttempt( $item['id'], $message );
					$stats['failed']++;
					$allSucceeded = false;
					break;
				}
			}

			if ( $allSucceeded ) {
				ChannelSyncQueue::markCompleted( $item['id'] );
				$stats['processed']++;
			}
		}

		return $stats;
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
			$result = self::syncChannel( (int) $channelId, null );
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
			$result = self::syncChannel( absint( (int) $channel ), null );
			return is_wp_error( $result ) ? $result : array(
				'count'   => 1,
				'details' => array( $result ),
			);
		}

		return self::syncAll();
	}

	public static function syncChannel( int $channelId, ?array $productIds = null ) {
		$channel = self::getChannel( $channelId );
		if ( ! $channel ) {
			return new WP_Error( 'bsp_sales_missing_channel', __( 'Channel not found.', 'sbdp' ), array( 'status' => 404 ) );
		}

		if ( ! (int) $channel['active'] ) {
			return new WP_Error( 'bsp_sales_inactive_channel', __( 'Channel is disabled.', 'sbdp' ), array( 'status' => 409 ) );
		}

		$products = self::collectProducts( $productIds );
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

	private static function collectProducts( ?array $includeIds = null ): array {
		$include = null;
		if ( $includeIds ) {
			$include = array_values( array_unique( array_map( 'absint', $includeIds ) ) );
			$include = array_filter( $include, static fn( $id ) => $id > 0 );
			if ( $include === array() ) {
				return array();
			}
		}

		$ids = array();
		if ( function_exists( 'wc_get_products' ) ) {
			$args = array(
				'limit'  => $include ? count( $include ) : -1,
				'limit_usage_to_manual_purchases' => false,
				'paginate' => false,
				'status' => array( 'publish' ),
				'return' => 'ids',
			);
			if ( $include ) {
				$args['include'] = $include;
			}
			$ids = wc_get_products( $args );
		} else {
			$queryArgs = array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => $include ? count( $include ) : 200,
			);
			if ( $include ) {
				$queryArgs['post__in'] = $include;
			}
			$query = new WP_Query( $queryArgs );
			$ids   = $query->posts ?: array();
		}

		$ids      = array_map( 'intval', $ids ?: array() );
		$products = array_map( static fn( $id ) => array( 'product_id' => (int) $id ), $ids );

		if ( $include ) {
			$missing = array_diff( $include, $ids );
			foreach ( $missing as $missingId ) {
				$products[] = array(
					'product_id' => (int) $missingId,
					'status'     => 'deleted',
				);
			}
		}

		return $products;
	}
}

if ( ! class_exists( 'BSPModule\\Sales\\ChannelManager' ) ) {
	\class_alias( ChannelManager::class, 'BSPModule\\Sales\\ChannelManager' );
}
