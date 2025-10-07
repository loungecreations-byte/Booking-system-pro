<?php

namespace WP_CLI {
	if ( ! class_exists( '\\WP_CLI\\WP_CLI_Stub' ) ) {
		class WP_CLI_Stub {
			public static function warning( $message = '' ): void {}
			public static function log( $message = '' ): void {}
			public static function success( $message = '' ): void {}
			public static function error( $message = '' ): void {}
			public static function line( $message = '' ): void {}
			public static function add_command( $name, $callable ): void {}
		}

		class_alias( '\\WP_CLI\\WP_CLI_Stub', '\\WP_CLI' );
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
		function format_items( $format, $items, $fields ): void {}
	}
}

namespace ActionScheduler {
	if ( ! function_exists( __NAMESPACE__ . '\\is_initialized' ) ) {
		function is_initialized(): bool {
			return true;
		}
	}
}

namespace {
	if ( ! class_exists( 'WP_CLI_Command' ) ) {
		abstract class WP_CLI_Command {}
	}

	if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
		function as_schedule_recurring_action( $timestamp, $interval_in_seconds, $hook, $args = array() ) {
			return 0;
		}
	}

	if ( ! function_exists( 'as_has_scheduled_action' ) ) {
		function as_has_scheduled_action( $hook, $args = null ) {
			return false;
		}
	}

	if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
		function as_unschedule_all_actions( $hook, $args = null ) {}
	}
}

