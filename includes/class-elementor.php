<?php

/**
 * Elementor integration for the Booking System Pro day planner.
 *
 * @package SBDP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SBDP_Elementor_Integration {

	/**
	 * Register Elementor hooks when the builder is available.
	 */
	public static function init() {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return;
		}

		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widget' ) );
	}

	/**
	 * Register the planner widget with Elementor.
	 *
	 * @param Elementor\Widgets_Manager $widgets_manager Widgets manager instance.
	 */
	public static function register_widget( $widgets_manager ) {
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			return;
		}

		require_once __DIR__ . '/elementor/class-widget-dayplanner.php';

		$widgets_manager->register( new SBDP_Elementor_Dayplanner_Widget() );
	}
}
