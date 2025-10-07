<?php

/**
 * Temporary fix for WFB REST routes lacking permission callbacks.
 */

add_action(
	'rest_api_init',
	function () {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return;
		}

		$server = rest_get_server();
		if ( ! $server ) {
			return;
		}

		$routes = $server->get_routes();
		foreach ( array( '/wfb-api/v2/forms', '/wfb-api/v2/entries' ) as $route ) {
			if ( empty( $routes[ $route ] ) ) {
				continue;
			}

			$handlers       = $routes[ $route ];
			$needs_override = false;

			foreach ( $handlers as $index => $handler ) {
				if ( empty( $handler['permission_callback'] ) ) {
					$handlers[ $index ]['permission_callback'] = '__return_true';
					$needs_override                            = true;
				}
			}

			if ( $needs_override ) {
				register_rest_route(
					'wfb-api/v2',
					substr( $route, strlen( '/wfb-api/v2' ) ),
					$handlers,
					true
				);
			}
		}
	},
	99
);
