<?php

require_once __DIR__ . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php';
require_once __DIR__ . '/vendor/php-stubs/woocommerce-stubs/woocommerce-stubs.php';
require_once __DIR__ . '/phpstan-stubs/wp-cli.php';
require_once __DIR__ . '/phpstan-stubs/wp-rest-controller.php';

if ( ! class_exists( 'SBDP_Enqueue' ) ) {
    class SBDP_Enqueue {
        public const FRONT_HANDLE_SCRIPT = 'sbdp-frontend-script';
        public const FRONT_HANDLE_STYLE  = 'sbdp-frontend-style';

        public static function init(): void {}
    }
}

if ( ! class_exists( 'BSP_Core_Agent' ) && class_exists( '\BSPModule\Shared\Agents\CoreAgent' ) ) {
    class_alias( '\BSPModule\Shared\Agents\CoreAgent', 'BSP_Core_Agent' );
}

if ( ! interface_exists( 'BSP_Module_Agent_Interface' ) && interface_exists( '\BSPModule\Shared\Agents\ModuleAgentInterface' ) ) {
    class_alias( '\BSPModule\Shared\Agents\ModuleAgentInterface', 'BSP_Module_Agent_Interface' );
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
    abstract class Elementor_Widget_Base {
        public function get_name() {
            return '';
        }

        public function get_title() {
            return '';
        }

        public function get_icon() {
            return '';
        }

        public function get_categories() {
            return array();
        }

        public function get_keywords() {
            return array();
        }

        protected function start_controls_section( $id, $args = array() ): void {}

        protected function add_control( $id, $args = array() ): void {}

        protected function end_controls_section(): void {}

        protected function render(): void {}
    }

    class_alias( 'Elementor_Widget_Base', '\Elementor\Widget_Base' );
}

if ( ! class_exists( '\Elementor\Controls_Manager' ) ) {
    class Elementor_Controls_Manager {
        public const RAW_HTML = 'raw_html';
    }

    class_alias( 'Elementor_Controls_Manager', '\Elementor\Controls_Manager' );
}

if ( ! class_exists( '\Elementor\Widgets_Manager' ) ) {
    class Elementor_Widgets_Manager {
        public function register( $widget ): void {}
    }

    class_alias( 'Elementor_Widgets_Manager', '\Elementor\Widgets_Manager' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'SBDP_DIR' ) ) {
    define( 'SBDP_DIR', __DIR__ . DIRECTORY_SEPARATOR );
}

if ( ! defined( 'SBDP_FILE' ) ) {
    define( 'SBDP_FILE', SBDP_DIR . 'booking-pro-module.php' );
}

if ( ! defined( 'SBDP_URL' ) ) {
    define( 'SBDP_URL', 'https://example.com/wp-content/plugins/booking-pro-module/' );
}

if ( ! defined( 'SBDP_VER' ) ) {
    define( 'SBDP_VER', '0.0.0' );
}

if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}
