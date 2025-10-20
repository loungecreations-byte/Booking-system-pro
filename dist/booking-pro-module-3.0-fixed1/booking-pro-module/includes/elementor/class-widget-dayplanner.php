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
        return [ 'general' ];
    }

    public function get_keywords() {
        return [ 'booking', 'planner', 'woocommerce', 'calendar' ];
    }

    public function get_script_depends() {
        return [ SBDP_Enqueue::FRONT_HANDLE_SCRIPT ];
    }

    public function get_style_depends() {
        return [ SBDP_Enqueue::FRONT_HANDLE_STYLE ];
    }

    protected function render() {
        if ( ! shortcode_exists( 'sbdp_dayplanner' ) ) {
            echo esc_html__( 'Day planner shortcode not available.', 'sbdp' );
            return;
        }

        echo do_shortcode( '[sbdp_dayplanner]' );
    }

    protected function register_controls() {
        $this->start_controls_section(
            'section_help',
            [
                'label' => __( 'Info', 'sbdp' ),
            ]
        );

        $this->add_control(
            'planner_description',
            [
                'type'        => Controls_Manager::RAW_HTML,
                'raw'         => __( 'De planner gebruikt dezelfde instellingen als de WooCommerce bookable service. Pas labels en teksten aan via de productinstellingen.', 'sbdp' ),
                'content_classes' => 'elementor-descriptor',
            ]
        );

        $this->end_controls_section();
    }
}
