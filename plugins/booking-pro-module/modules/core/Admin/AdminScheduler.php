<?php

declare(strict_types=1);

namespace BSPModule\Core\Admin;

/**
 * Admin scheduler page shell.
 *
 * @package SBDP
 */

final class AdminScheduler {

	/**
	 * Register scheduler admin screen.
	 */
	public static function init() {
		// Disabled — use the top-level Planboard menu instead (planboard/Module.php).
	}

	/**
	 * Add submenu item for the planner scheduler.
	 */
	public static function register_page() {
        add_submenu_page(
            'sbdp_bookings',
            __( 'Planner management', 'sbdp' ),
            __( 'Planner management', 'sbdp' ),
            AdminMenu::capability(),
			'sbdp_scheduler',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the Vue/React mount point for the scheduler SPA.
	 */
	public static function render_page() {
		echo '<div class="wrap sbdp-scheduler-wrap">';
		echo '<h1>' . esc_html__( 'Planboard', 'sbdp' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Welkom bij het planboard van Dagje Den Bosch.nl.', 'sbdp' ) . '</p>';
		echo '<div id="sbdp-scheduler-app" class="sbdp-scheduler-app"></div>';
		echo '</div>';
	}
}
