<?php
/**
 * Custom post type registrations.
 *
 * @package SBDP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBDP_CPT {

    /**
     * Hook CPT registration into WordPress.
     */
    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
    }

    /**
     * Register custom post types used to back the planner UI.
     */
    public static function register() {
        register_post_type(
            'bookable_item',
            [
                'label'               => __( 'Bookable Items', 'sbdp' ),
                'labels'              => [
                    'name'          => __( 'Bookable Items', 'sbdp' ),
                    'singular_name' => __( 'Bookable Item', 'sbdp' ),
                ],
                'public'              => false,
                'show_ui'             => true,
                'show_in_menu'        => false,
                'show_in_rest'        => true,
                'supports'            => [ 'title', 'editor', 'thumbnail' ],
                'capability_type'     => 'post',
                'hierarchical'        => false,
                'rewrite'             => false,
                'has_archive'         => false,
                'menu_position'       => null,
            ]
        );

        register_post_type(
            'bookable_resource',
            [
                'label'               => __( 'Resources', 'sbdp' ),
                'labels'              => [
                    'name'          => __( 'Resources', 'sbdp' ),
                    'singular_name' => __( 'Resource', 'sbdp' ),
                ],
                'public'              => false,
                'show_ui'             => true,
                'show_in_menu'        => false,
                'show_in_rest'        => true,
                'supports'            => [ 'title', 'thumbnail' ],
                'capability_type'     => 'post',
                'hierarchical'        => false,
                'rewrite'             => false,
                'has_archive'         => false,
                'menu_position'       => null,
            ]
        );
    }
}

