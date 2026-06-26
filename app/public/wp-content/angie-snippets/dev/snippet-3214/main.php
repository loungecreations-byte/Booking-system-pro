<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

const PREMIUM_FOOTER_ASSETS_VERSION_3ea31cf2 = '1.0.0';

function register_premium_footer_widget_3ea31cf2( $widgets_manager ) {
    require_once __DIR__ . '/widget-premium-footer.php';
    $widgets_manager->register( new \AngieSnippets\Premium_Footer_3ea31cf2() );
}
add_action( 'elementor/widgets/register', 'register_premium_footer_widget_3ea31cf2' );

function register_premium_footer_assets_3ea31cf2() {
    wp_register_style( 'premium-footer-style-3ea31cf2', angie_cs_get_snippet_asset_url( __FILE__, 'style.css' ), [], PREMIUM_FOOTER_ASSETS_VERSION_3ea31cf2 );
}
add_action( 'wp_enqueue_scripts', 'register_premium_footer_assets_3ea31cf2' );
