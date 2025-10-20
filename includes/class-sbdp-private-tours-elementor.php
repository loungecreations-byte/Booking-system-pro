<?php
/**
 * Elementor integration for private tours.
 *
 * @package Booking_Pro_Module
 */

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Hooks Elementor support into SBDP private tours.
 */
class SBDP_Private_Tours_Elementor {

	/**
	 * Register the Elementor integration hooks.
	 */
	public static function init(): void {
		add_filter( 'elementor_cpt_support_types', array( __CLASS__, 'register_cpt_support' ) );
		add_action( 'elementor/init', array( __CLASS__, 'bootstrap_widgets' ) );
	}

	/**
	 * Ensure the Elementor widget registration happens only when Elementor is active.
	 */
	public static function bootstrap_widgets(): void {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widgets' ) );
	}

	/**
	 * Register the custom Elementor widgets.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
	 */
	public static function register_widgets( $widgets_manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		require_once SBDP_DIR . 'includes/elementor/class-sbdp-elementor-tour-meta-widget.php';
		require_once SBDP_DIR . 'includes/elementor/class-sbdp-elementor-tour-steps-widget.php';

		$widgets_manager->register( new SBDP_Elementor_Tour_Meta_Widget() );
		$widgets_manager->register( new SBDP_Elementor_Tour_Steps_Widget() );
	}

	/**
	 * Add private tour post types to Elementor-supported CPTs.
	 *
	 * @param array<int, string> $types Supported types.
	 *
	 * @return array<int, string>
	 */
	public static function register_cpt_support( array $types ): array {
		$types[] = 'sbdp_private_tour';
		$types[] = 'sbdp_private_tour_step';

		return array_values( array_unique( $types ) );
	}
}
