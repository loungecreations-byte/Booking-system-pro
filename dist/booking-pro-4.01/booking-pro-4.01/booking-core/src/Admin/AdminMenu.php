<?php

declare(strict_types=1);

namespace BSPModule\Core\Admin;

use WP_Post;
use WP_Query;

use function add_action;
use function add_menu_page;
use function add_submenu_page;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_permalink;
use function get_the_title;
use function sanitize_title;
use function sprintf;
use function wp_kses_post;
use function wp_reset_postdata;
use function __;

/**
 * Admin menu registration.
 */
final class AdminMenu {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Bookings', 'sbdp' ),
			__( 'Bookings', 'sbdp' ),
			'manage_woocommerce',
			'sbdp_bookings',
			array( __CLASS__, 'render_overview' ),
			'dashicons-calendar-alt',
			56
		);

		add_submenu_page(
			'sbdp_bookings',
			__( 'Bookable Items', 'sbdp' ),
			__( 'Bookable Items', 'sbdp' ),
			'manage_woocommerce',
			'edit.php?post_type=bookable_item'
		);

		add_submenu_page(
			'sbdp_bookings',
			__( 'Resources', 'sbdp' ),
			__( 'Resources', 'sbdp' ),
			'manage_woocommerce',
			'edit.php?post_type=bookable_resource'
		);

		add_submenu_page(
			'sbdp_bookings',
			__( 'Availability', 'sbdp' ),
			__( 'Availability', 'sbdp' ),
			'manage_woocommerce',
			'sbdp_availability',
			array( __CLASS__, 'render_availability' )
		);

		add_submenu_page(
			'sbdp_bookings',
			__( 'Pricing & Rules', 'sbdp' ),
			__( 'Pricing & Rules', 'sbdp' ),
			'manage_woocommerce',
			'sbdp_pricing',
			array( __CLASS__, 'render_pricing' )
		);

		add_submenu_page(
			'sbdp_bookings',
			__( 'Planner Frontend', 'sbdp' ),
			__( 'Planner Frontend', 'sbdp' ),
			'manage_woocommerce',
			'sbdp_plan_link',
			array( __CLASS__, 'render_plan_link' )
		);
	}

	public static function render_overview(): void {
		$planner_page = self::locate_planner_page();
		$products     = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => array( 'bookable_service' ),
					),
				),
			)
		);

		$planner_link = $planner_page instanceof WP_Post
			? sprintf(
				'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
				esc_url( get_permalink( $planner_page ) ),
				esc_html( get_the_title( $planner_page ) )
			)
			: esc_html__( 'Not assigned', 'sbdp' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Bookings dashboard', 'sbdp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Use the shortcuts below to manage services, resources, availability rules, and pricing for the planner.', 'sbdp' ) . '</p>';
		echo '<ul class="ul-disc">';
		printf(
			'<li>%s</li>',
			wp_kses_post( sprintf( __( 'Linked planner page: %s', 'sbdp' ), $planner_link ) )
		);
		printf(
			'<li>%s</li>',
			esc_html( sprintf( __( 'Bookable products available: %d', 'sbdp' ), (int) $products->found_posts ) )
		);
		echo '</ul>';
		echo '</div>';

		wp_reset_postdata();
	}

	public static function render_availability(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Availability & Calendar Editor', 'sbdp' ) . '</h1>';
		echo '<div id="sbdp-av-app"></div>';
		echo '</div>';
	}

	public static function render_pricing(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Pricing Rules & Fees', 'sbdp' ) . '</h1>';
		echo '<div id="sbdp-pricing-app"></div>';
		echo '</div>';
	}

	public static function render_plan_link(): void {
		$planner_page = self::locate_planner_page();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Planner Frontend', 'sbdp' ) . '</h1>';

		if ( $planner_page instanceof WP_Post ) {
			printf(
				'<a class="button button-primary" target="_blank" rel="noopener" href="%1$s">%2$s</a>',
				esc_url( get_permalink( $planner_page ) ),
				esc_html__( 'Open planner', 'sbdp' )
			);
		} else {
			echo '<p>' . esc_html__( 'No planner page found. Create one containing the [sbdp_dayplanner] shortcode.', 'sbdp' ) . '</p>';
		}

		echo '</div>';
	}

	private static function locate_planner_page(): ?WP_Post {
		$target_slug = sanitize_title( __( 'Plan je dag', 'sbdp' ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'name'           => $target_slug,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		if ( $query->have_posts() ) {
			$post = $query->posts[0];
			wp_reset_postdata();

			return $post instanceof WP_Post ? $post : null;
		}

		wp_reset_postdata();

		$query = new WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				's'              => '[sbdp_dayplanner]',
			)
		);

		if ( $query->have_posts() ) {
			$post = $query->posts[0];
			wp_reset_postdata();

			return $post instanceof WP_Post ? $post : null;
		}

		wp_reset_postdata();

		return null;
	}
}
