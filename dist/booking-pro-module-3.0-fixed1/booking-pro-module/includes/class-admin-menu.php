<?php
/**
 * Admin menu registration.
 *
 * @package SBDP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBDP_Admin_Menu {

    /**
     * Hook menu registration.
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
    }

    /**
     * Register top-level and child menus for the booking planner.
     */
    public static function menu() {
        add_menu_page(
            __( 'Bookings', 'sbdp' ),
            __( 'Bookings', 'sbdp' ),
            'manage_woocommerce',
            'sbdp_bookings',
            [ __CLASS__, 'render_overview' ],
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
            [ __CLASS__, 'render_availability' ]
        );

        add_submenu_page(
            'sbdp_bookings',
            __( 'Pricing & Rules', 'sbdp' ),
            __( 'Pricing & Rules', 'sbdp' ),
            'manage_woocommerce',
            'sbdp_pricing',
            [ __CLASS__, 'render_pricing' ]
        );

        add_submenu_page(
            'sbdp_bookings',
            __( 'Planner Frontend', 'sbdp' ),
            __( 'Planner Frontend', 'sbdp' ),
            'manage_woocommerce',
            'sbdp_plan_link',
            [ __CLASS__, 'render_plan_link' ]
        );
    }

    /**
     * Render the booking overview landing page.
     */
    public static function render_overview() {
        $page     = get_page_by_title( __( 'Plan je dag', 'sbdp' ) );
        $products = new WP_Query(
            [
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'tax_query'      => [
                    [
                        'taxonomy' => 'product_type',
                        'field'    => 'slug',
                        'terms'    => [ 'bookable_service' ],
                    ],
                ],
            ]
        );

        $planner_link = $page ? sprintf(
            '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
            esc_url( get_permalink( $page ) ),
            esc_html( get_the_title( $page ) )
        ) : esc_html__( 'Not assigned', 'sbdp' );

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

    /**
     * Render the availability editor container.
     */
    public static function render_availability() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Availability & Calendar Editor', 'sbdp' ) . '</h1>';
        echo '<div id="sbdp-av-app"></div>';
        echo '</div>';
    }

    /**
     * Render the pricing editor container.
     */
    public static function render_pricing() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Pricing Rules & Fees', 'sbdp' ) . '</h1>';
        echo '<div id="sbdp-pricing-app"></div>';
        echo '</div>';
    }

    /**
     * Render quick link to the public planner page.
     */
    public static function render_plan_link() {
        $page = get_page_by_title( __( 'Plan je dag', 'sbdp' ) );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Planner Frontend', 'sbdp' ) . '</h1>';

        if ( $page ) {
            printf(
                '<a class="button button-primary" target="_blank" rel="noopener" href="%1$s">%2$s</a>',
                esc_url( get_permalink( $page->ID ) ),
                esc_html__( 'Open planner', 'sbdp' )
            );
        } else {
            echo '<p>' . esc_html__( 'No planner page found. Create one containing the [sbdp_dayplanner] shortcode.', 'sbdp' ) . '</p>';
        }

        echo '</div>';
    }
}

