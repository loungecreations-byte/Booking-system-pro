<?php

declare(strict_types=1);

namespace BSPModule\Sales\Admin;

use function add_action;
use function add_submenu_page;
use function esc_html__;
use function esc_html;
use function __;

final class Menu {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'sbdp_bookings',
			__( 'Sales', 'sbdp' ),
			__( 'Sales', 'sbdp' ),
			'manage_woocommerce',
			'sbdp_sales',
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Sales Module', 'sbdp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Sales module dashboard placeholder.', 'sbdp' ) . '</p>';
		echo '</div>';
	}
}
