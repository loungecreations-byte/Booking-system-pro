<?php

/**
 * Asset loader for Booking System Pro Core.
 *
 * @package Booking_Core
 */

namespace Booking_Core;

final class Assets {

	/**
	 * Register asset hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_admin' ) );
	}

	/**
	 * Register scripts and styles for reuse.
	 */
	public static function register_assets(): void {
		wp_register_style(
			'booking-core/public',
			BOOKING_CORE_URL . 'assets/css/public.css',
			array(),
			BOOKING_CORE_VERSION
		);

		wp_register_script(
			'booking-core/public',
			BOOKING_CORE_URL . 'assets/js/public.js',
			array( 'wp-element', 'wp-i18n' ),
			BOOKING_CORE_VERSION,
			true
		);
	}

	/**
	 * Enqueue frontend assets when the planner/cart is present.
	 */
	public static function maybe_enqueue_frontend(): void {
		if ( is_admin() ) {
			return;
		}

		if ( has_shortcode( get_post_field( 'post_content', get_the_ID() ), 'sbdp_dayplanner' ) || has_shortcode( get_post_field( 'post_content', get_the_ID() ), 'sbdp_cart' ) ) {
			wp_enqueue_style( 'booking-core/public' );
			wp_enqueue_script( 'booking-core/public' );

			wp_localize_script(
				'booking-core/public',
				'BookingCoreConfig',
				self::get_public_config()
			);
		}
	}

	/**
	 * Enqueue admin assets for add-ons when needed.
	 *
	 * @param string $hook Current admin screen.
	 */
	public static function maybe_enqueue_admin( string $hook ): void {
		do_action( 'booking_core/admin_enqueue', $hook );
	}

	/**
	 * Data passed to front-end.
	 */
	private static function get_public_config(): array {
		return array(
			'rest' => array(
				'base'  => esc_url_raw( rest_url( 'sbdp/v1' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			),
		);
	}
}
