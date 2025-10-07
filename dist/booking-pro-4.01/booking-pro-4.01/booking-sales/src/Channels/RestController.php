<?php

declare(strict_types=1);

namespace BSP\Sales\Channels;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

use function absint;
use function add_action;
use function current_user_can;
use function is_array;
use function is_wp_error;
use function register_rest_route;
use function rest_ensure_response;

final class RestController {

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );
	}

	public static function registerRoutes(): void {
		register_rest_route(
			'bsp/v1',
			'/channels',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'getChannels' ),
					'permission_callback' => array( self::class, 'permissionCheck' ),
				),
			)
		);

		register_rest_route(
			'bsp/v1',
			'/channels/sync',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'syncChannels' ),
					'permission_callback' => array( self::class, 'permissionCheck' ),
					'args'                => array(
						'channel_id'  => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'channel_ids' => array(
							'type'     => 'array',
							'required' => false,
						),
					),
				),
			)
		);
	}

	public static function permissionCheck(): bool {
		return current_user_can( 'manage_bsp_sales' ) || current_user_can( 'manage_woocommerce' );
	}

	public static function getChannels( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'channels' => ChannelManager::getChannels() ) );
	}

	public static function syncChannels( WP_REST_Request $request ) {
		$channelId  = $request->get_param( 'channel_id' );
		$channelIds = $request->get_param( 'channel_ids' );

		if ( $channelId !== null ) {
			$result = ChannelManager::syncChannel( (int) $channelId );
			return is_wp_error( $result ) ? $result : rest_ensure_response(
				array(
					'count'   => 1,
					'details' => array( $result ),
				)
			);
		}

		if ( is_array( $channelIds ) && $channelIds !== array() ) {
			$details = array();
			foreach ( $channelIds as $id ) {
				$result    = ChannelManager::syncChannel( (int) $id );
				$details[] = is_wp_error( $result )
					? array(
						'id'      => (int) $id,
						'status'  => 'failed',
						'synced'  => 0,
						'errors'  => 1,
						'message' => $result->get_error_message(),
					)
					: $result;
			}

			return rest_ensure_response(
				array(
					'count'   => count( $details ),
					'details' => $details,
				)
			);
		}

		$result = ChannelManager::syncAll();
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}

if ( ! class_exists( 'BSPModule\\Sales\\Rest\\Channels' ) ) {
	\class_alias( RestController::class, 'BSPModule\\Sales\\Rest\\Channels' );
}
