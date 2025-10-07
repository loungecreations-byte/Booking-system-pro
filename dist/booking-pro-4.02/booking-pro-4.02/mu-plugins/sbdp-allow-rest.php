<?php

/**
 * Allow Booking System Pro REST calls even when REST is globally restricted.
 */

add_filter(
	'rest_authentication_errors',
	function ( $result ) {
		if ( ! empty( $result ) ) {
			$route = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
			if ( strpos( $route, '/wp-json/sbdp/v1/' ) !== false ) {
				// Clear the blocking error so the request can proceed.
				return null;
			}
		}
		return $result;
	},
	5
);
