<?php

declare(strict_types=1);

namespace BSP\Sales\Promotions;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function __;
use function absint;
use function add_action;
use function array_filter;
use function array_merge;
use function current_user_can;
use function get_current_user_id;
use function is_array;
use function register_rest_route;
use function rest_ensure_response;
use function wp_verify_nonce;

final class PromotionsRestController {

	private const NONCE_HEADER   = 'X-SBDP-Promotions-Nonce';
	private const SESSION_HEADER = 'X-SBDP-Funnel-Session';

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );
	}

	public static function registerRoutes(): void {
		register_rest_route(
			'sbdp/v1',
			'/promotions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'index' ),
					'permission_callback' => array( self::class, 'canManagePromotions' ),
					'args'                => array(
						'status' => array(
							'required' => false,
							'type'     => 'string',
						),
						'code'   => array(
							'required' => false,
							'type'     => 'string',
						),
						'limit'  => array(
							'required' => false,
							'type'     => 'integer',
						),
						'offset' => array(
							'required' => false,
							'type'     => 'integer',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'create' ),
					'permission_callback' => array( self::class, 'canManagePromotions' ),
				),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/promotions/(?P<id>\\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'getItem' ),
					'permission_callback' => array( self::class, 'canManagePromotions' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'update' ),
					'permission_callback' => array( self::class, 'canManagePromotions' ),
				),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/promotions/(?P<id>\\d+)/preview',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'preview' ),
					'permission_callback' => array( self::class, 'canManagePromotions' ),
				),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/promotions/(?P<id>\\d+)/activate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'activate' ),
					'permission_callback' => array( self::class, 'canManagePromotions' ),
				),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/promotions/(?P<id>\\d+)/archive',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'archive' ),
					'permission_callback' => array( self::class, 'canManagePromotions' ),
				),
			)
		);
	}

	public static function index( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'status' => $request->get_param( 'status' ),
			'code'   => $request->get_param( 'code' ),
			'limit'  => $request->get_param( 'limit' ),
			'offset' => $request->get_param( 'offset' ),
		);

		$filteredArgs = array_filter( $args, static fn( $value ) => $value !== null && $value !== '' );
		$promotions   = PromotionsService::listPromotions( $filteredArgs );

		return rest_ensure_response(
			array(
				'promotions' => $promotions,
			)
		);
	}

	public static function getItem( WP_REST_Request $request ) {
		$id        = absint( $request['id'] );
		$promotion = PromotionsService::getPromotion( $id );
		if ( $promotion === null ) {
			return new WP_Error( 'sbdp_promotions_not_found', __( 'Promotion not found.', 'sbdp' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $promotion );
	}

	public static function create( WP_REST_Request $request ) {
		if ( $error = self::verifyNonce( $request ) ) {
			return $error;
		}

		$payload = $request->get_json_params() ?: array();
		$userId  = get_current_user_id();
		$context = self::buildContext( $request );
		$result  = PromotionsService::createPromotion( is_array( $payload ) ? $payload : array(), $userId, $context );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$response = rest_ensure_response( $result );
		$response->set_status( 201 );

		return $response;
	}

	public static function update( WP_REST_Request $request ) {
		if ( $error = self::verifyNonce( $request ) ) {
			return $error;
		}

		$payload = $request->get_json_params() ?: array();
		$userId  = get_current_user_id();
		$id      = absint( $request['id'] );
		$context = self::buildContext( $request );

		$result = PromotionsService::updatePromotion( $id, is_array( $payload ) ? $payload : array(), $userId, $context );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	public static function preview( WP_REST_Request $request ) {
		if ( $error = self::verifyNonce( $request ) ) {
			return $error;
		}

		$id        = absint( $request['id'] );
		$promotion = PromotionsService::getPromotion( $id );
		if ( $promotion === null ) {
			return new WP_Error( 'sbdp_promotions_not_found', __( 'Promotion not found.', 'sbdp' ), array( 'status' => 404 ) );
		}

		$bodyContext = $request->get_json_params();
		$context     = is_array( $bodyContext ) ? $bodyContext : array();
		$context     = array_merge( self::buildContext( $request ), $context );

		return rest_ensure_response(
			PromotionsService::previewPromotion( $promotion, $context )
		);
	}

	public static function activate( WP_REST_Request $request ) {
		if ( $error = self::verifyNonce( $request ) ) {
			return $error;
		}

		$id      = absint( $request['id'] );
		$userId  = get_current_user_id();
		$context = self::buildContext( $request );
		$result  = PromotionsService::transitionPromotion( $id, 'active', $userId, $context );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	public static function archive( WP_REST_Request $request ) {
		if ( $error = self::verifyNonce( $request ) ) {
			return $error;
		}

		$id      = absint( $request['id'] );
		$userId  = get_current_user_id();
		$context = self::buildContext( $request );
		$result  = PromotionsService::transitionPromotion( $id, 'archived', $userId, $context );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	public static function canManagePromotions(): bool {
		return current_user_can( PromotionsService::CAPABILITY ) || current_user_can( 'manage_woocommerce' );
	}

	private static function buildContext( WP_REST_Request $request ): array {
		$sessionId = self::extractSessionId( $request );
		$context   = array(
			'session_id' => $sessionId,
		);

		return array_filter( $context, static fn( $value ) => $value !== null && $value !== '' );
	}

	private static function extractSessionId( WP_REST_Request $request ): string {
		$header = $request->get_header( self::SESSION_HEADER );
		if ( is_string( $header ) && $header !== '' ) {
			return sanitize_text_field( $header );
		}

		$param = $request->get_param( 'session_id' );
		if ( is_string( $param ) && $param !== '' ) {
			return sanitize_text_field( $param );
		}

		return '';
	}

	private static function verifyNonce( WP_REST_Request $request ): ?WP_Error {
		$nonce = $request->get_header( self::NONCE_HEADER );
		if ( ! $nonce ) {
			$nonce = (string) $request->get_param( '_sbdp_nonce' );
		}

		if ( ! $nonce || ! wp_verify_nonce( $nonce, PromotionsService::NONCE_ACTION ) ) {
			return new WP_Error( 'sbdp_promotions_nonce_invalid', __( 'Missing or invalid promotions nonce.', 'sbdp' ), array( 'status' => 403 ) );
		}

		return null;
	}
}
