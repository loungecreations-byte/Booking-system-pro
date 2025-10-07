<?php

/**
 * Custom post types used by Booking System Pro Core.
 *
 * @package Booking_Core
 */

namespace Booking_Core;

final class CPT {

	/**
	 * Hook into init.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
	}

	/**
	 * Register bookable resources and assignments CPTs when required.
	 */
	public static function register_post_types(): void {
		$labels = array(
			'name'          => _x( 'Resources', 'Post type general name', 'booking-core' ),
			'singular_name' => _x( 'Resource', 'Post type singular name', 'booking-core' ),
		);

		register_post_type(
			'bookable_resource',
			array(
				'label'             => __( 'Resources', 'booking-core' ),
				'labels'            => $labels,
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_nav_menus' => false,
				'supports'          => array( 'title', 'thumbnail' ),
				'capability_type'   => 'post',
				'map_meta_cap'      => true,
				'has_archive'       => false,
				'show_in_rest'      => false,
			)
		);
	}
}
