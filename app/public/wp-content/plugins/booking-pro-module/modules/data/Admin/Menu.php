<?php

declare(strict_types=1);

namespace BSPModule\Data\Admin;

use function add_action;
use function add_submenu_page;
use function esc_html__;
use function esc_html;
use function __;

final class Menu {

	public static function init(): void {
		// Not implemented yet — menu hidden until Data module is built.
	}

	public static function register_menu(): void {
		add_submenu_page(
			'sbdp_bookings',
			__( 'Data', 'sbdp' ),
			__( 'Data', 'sbdp' ),
			'manage_woocommerce',
			'sbdp_data',
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Data-overzicht', 'sbdp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Tijdelijke placeholder voor het data-overzicht.', 'sbdp' ) . '</p>';
		echo '</div>';
	}
}
