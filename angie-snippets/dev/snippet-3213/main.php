<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

const DARK_MODE_TOGGLE_ASSETS_VERSION_a59c2cb1 = '1.0.0';

function register_dark_mode_toggle_a59c2cb1( $widgets_manager ) {
    require_once __DIR__ . '/widget-dark-mode-toggle.php';
    $widgets_manager->register( new \AngieSnippets\Dark_Mode_Toggle_a59c2cb1() );
}
add_action( 'elementor/widgets/register', 'register_dark_mode_toggle_a59c2cb1' );

function register_dark_mode_toggle_assets_a59c2cb1() {
	wp_register_script( 'dark-mode-toggle-script-a59c2cb1', angie_cs_get_snippet_asset_url( __FILE__, 'script.js' ), [ 'elementor-frontend' ], DARK_MODE_TOGGLE_ASSETS_VERSION_a59c2cb1, true );
	wp_register_style( 'dark-mode-toggle-style-a59c2cb1', angie_cs_get_snippet_asset_url( __FILE__, 'style.css' ), [], DARK_MODE_TOGGLE_ASSETS_VERSION_a59c2cb1 );
}
add_action( 'wp_enqueue_scripts', 'register_dark_mode_toggle_assets_a59c2cb1' );
