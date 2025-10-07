<?php
/**
 * Activation helpers for Booking Pro Module.
 *
 * @package Booking_Pro_Module
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation and demo seeding tasks for Booking Pro Module.
 */
class SBDP_Activation {

	/**
	 * Option key used to determine whether demo content should be seeded.
	 */
	public const OPTION_SHOULD_SEED = 'sbdp_needs_demo_setup';

	/**
	 * Register the hooks required to seed demo content after activation.
	 */
	public static function bootstrap(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed_demo' ) );
	}

	/**
	 * Handle plugin activation.
	 */
	public static function activate(): void {
		add_option( self::OPTION_SHOULD_SEED, '1' );

		require_once SBDP_DIR . 'includes/class-cpt.php';
		if ( class_exists( 'SBDP_CPT' ) ) {
			SBDP_CPT::register();
		}

		flush_rewrite_rules();
	}

	/**
	 * Handle plugin deactivation.
	 */
	public static function deactivate(): void {
		delete_option( self::OPTION_SHOULD_SEED );
		flush_rewrite_rules();
	}

	/**
	 * Handle plugin uninstall.
	 */
	public static function uninstall(): void {
		delete_option( self::OPTION_SHOULD_SEED );
	}

	/**
	 * Seed demo content when required.
	 */
	public static function maybe_seed_demo(): void {
		if ( '1' !== get_option( self::OPTION_SHOULD_SEED ) ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		self::create_demo_products();
		self::ensure_planner_page();

		update_option( self::OPTION_SHOULD_SEED, '0' );
	}

	/**
	 * Create the demo products used for the planner showcase.
	 */
	private static function create_demo_products(): void {
		$products = array(
			array(
				'title'    => __( 'Stadswandeling', 'sbdp' ),
				'slug'     => 'stadswandeling',
				'excerpt'  => __( 'Ontdek Den Bosch met gids', 'sbdp' ),
				'price'    => 20,
				'duration' => 90,
			),
			array(
				'title'    => __( 'Lunchpakket', 'sbdp' ),
				'slug'     => 'lunchpakket',
				'excerpt'  => __( 'Smaakvolle lunch to go', 'sbdp' ),
				'price'    => 15,
				'duration' => 60,
			),
			array(
				'title'    => __( 'Avondeten', 'sbdp' ),
				'slug'     => 'avondeten',
				'excerpt'  => __( 'Gezellig diner in de stad', 'sbdp' ),
				'price'    => 29.95,
				'duration' => 90,
			),
		);

		foreach ( $products as $product_data ) {
			self::create_demo_product( $product_data );
		}
	}

	/**
	 * Ensure a single demo product exists for the provided configuration.
	 *
	 * @param array<string, mixed> $product_data Product data used to seed the post.
	 *
	 * @return int Seeded product ID.
	 */
	private static function create_demo_product( array $product_data ): int {
		$existing = get_page_by_path( $product_data['slug'], OBJECT, 'product' );
		if ( $existing ) {
			return (int) $existing->ID;
		}

		/** @var int|\WP_Error $product_id */
		$product_id = wp_insert_post(
			array(
				'post_type'    => 'product',
				'post_status'  => 'publish',
				'post_title'   => $product_data['title'],
				'post_name'    => $product_data['slug'],
				'post_excerpt' => $product_data['excerpt'],
			)
		);

		if ( is_wp_error( $product_id ) ) {
			return 0;
		}

		if ( ! $product_id ) {
			return 0;
		}

		wp_set_object_terms( $product_id, 'bookable_service', 'product_type' );
		update_post_meta( $product_id, '_regular_price', $product_data['price'] );
		update_post_meta( $product_id, '_price', $product_data['price'] );
		update_post_meta( $product_id, '_sbdp_duration', $product_data['duration'] );

		return (int) $product_id;



	}

	/**
	 * Ensure the planner landing page exists.
	 *
	 * @return int Seeded planner page ID.
	 */
	private static function ensure_planner_page(): int {
		$planner_query = new WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'title'          => __( 'Plan je dag', 'sbdp' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		$planner_ids = $planner_query->posts;
		wp_reset_postdata();

		if ( ! empty( $planner_ids ) ) {
			return (int) $planner_ids[0];
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Plan je dag', 'sbdp' ),
				'post_name'    => 'plan-je-dag',
				'post_content' => '[sbdp_dayplanner]',
			)
		);

		return (int) $page_id;
	}
}


