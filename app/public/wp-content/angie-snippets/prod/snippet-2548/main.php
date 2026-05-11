<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

const PARTNER_COMPARISON_ASSETS_VERSION_af636d83 = '1.0.0';

function register_partner_comparison_widget_af636d83( $widgets_manager ) {
    require_once __DIR__ . '/widget-partner-comparison.php';
    $widgets_manager->register( new \AngieSnippets\Partner_Comparison_af636d83() );
}
add_action( 'elementor/widgets/register', 'register_partner_comparison_widget_af636d83' );

function register_partner_comparison_assets_af636d83() {
	wp_register_style( 'partner-comparison-style-af636d83', angie_cs_get_snippet_asset_url( __FILE__, 'style.css' ), [], PARTNER_COMPARISON_ASSETS_VERSION_af636d83 );
}
add_action( 'wp_enqueue_scripts', 'register_partner_comparison_assets_af636d83' );