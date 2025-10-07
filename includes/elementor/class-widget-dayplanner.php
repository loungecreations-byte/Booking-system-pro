<?php

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SBDP_Elementor_Dayplanner_Widget extends Widget_Base {

	public function get_name() {
		return 'sbdp_dayplanner';
	}

	public function get_title() {
		return __( 'Booking Day Planner', 'sbdp' );
	}

	public function get_icon() {
		return 'eicon-calendar';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'booking', 'planner', 'woocommerce', 'calendar' );
	}

	public function get_script_depends() {
		return array( $this->resolve_front_handle( 'script' ) );
	}

	public function get_style_depends() {
		return array( $this->resolve_front_handle( 'style' ) );
	}

	protected function render(): void {
		if ( ! shortcode_exists( 'sbdp_dayplanner' ) ) {
			echo esc_html__( 'Day planner shortcode not available.', 'sbdp' );
			return;
		}

		echo do_shortcode( '[sbdp_dayplanner]' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_help',
			array(
				'label' => __( 'Info', 'sbdp' ),
			)
		);

		$this->add_control(
			'planner_description',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'De planner gebruikt dezelfde instellingen als de WooCommerce bookable service. Pas labels en teksten aan via de productinstellingen.', 'sbdp' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->end_controls_section();
	}

	private function resolve_front_handle( string $type ): string {
		$suffix = strtoupper( $type );
		$legacy_const = 'SBDP_Enqueue::FRONT_HANDLE_' . $suffix;

		if ( class_exists( 'SBDP_Enqueue' ) && defined( $legacy_const ) ) {
			return (string) constant( $legacy_const );
		}

		$modern_class = '\\BSPModule\\Core\\Assets\\EnqueueService';
		$modern_const = $modern_class . '::FRONT_HANDLE_' . $suffix;

		if ( class_exists( $modern_class ) && defined( $modern_const ) ) {
			return (string) constant( $modern_const );
		}

		return 'sbdp-planner';
	}
}
