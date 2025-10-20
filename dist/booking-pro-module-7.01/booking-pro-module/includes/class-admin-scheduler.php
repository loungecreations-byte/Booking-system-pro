<?php
/**
 * Admin scheduler page shell.
 *
 * @package SBDP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBDP_Admin_Scheduler {

    /**
     * Register scheduler admin screen.
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_page' ] );
    }

    /**
     * Add submenu item for the planner scheduler.
     */
    public static function register_page() {
        add_submenu_page(
            'sbdp_bookings',
            __( 'Planner management', 'sbdp' ),
            __( 'Planner management', 'sbdp' ),
            'manage_woocommerce',
            'sbdp_scheduler',
            [ __CLASS__, 'render_page' ]
        );
    }

    /**
     * Render the Vue/React mount point for the scheduler SPA.
     */
    public static function render_page() {
        echo '<div class="wrap sbdp-scheduler-wrap">';
        echo '<h1>' . esc_html__( 'Team & capacity planning', 'sbdp' ) . '</h1>';
        echo '<p class="description">' . esc_html__( 'See at a glance which activities, guides, and locations are planned. Filter quickly to keep your team aligned.', 'sbdp' ) . '</p>';
        echo '<div id="sbdp-scheduler-app" class="sbdp-scheduler-app"></div>';
        echo '</div>';
    }
}

