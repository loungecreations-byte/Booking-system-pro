<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

const FEATURED_ACTIVITIES_ASSETS_VERSION_CA9727BA = '1.0.1';

function register_featured_activities_widget_ca9727ba( $widgets_manager ) {
    require_once __DIR__ . '/widget-featured-activities.php';
    $widgets_manager->register( new \AngieSnippets\Featured_Activities_ca9727ba() );
}
add_action( 'elementor/widgets/register', 'register_featured_activities_widget_ca9727ba' );

function register_featured_activities_assets_ca9727ba() {
	wp_register_style( 'featured-activities-style-ca9727ba', angie_cs_get_snippet_asset_url( __FILE__, 'style.css' ), [], FEATURED_ACTIVITIES_ASSETS_VERSION_CA9727BA );
}
add_action( 'wp_enqueue_scripts', 'register_featured_activities_assets_ca9727ba' );
