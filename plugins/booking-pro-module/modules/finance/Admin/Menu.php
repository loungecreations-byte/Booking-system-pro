<?php

declare(strict_types=1);

namespace BSPModule\Finance\Admin;

use BPM\Modules\Finance\FinanceModule;

use function add_action;
use function apply_filters;
use function add_submenu_page;
use function esc_html__;
use function esc_html;
use function __;
use function class_exists;

final class Menu {

	public static function init(): void {
		// Not implemented yet — menu hidden until FinanceModule is loaded.
	}

	public static function register_menu(): void {
		if ( class_exists( FinanceModule::class ) ) {
			$module = FinanceModule::boot();

			$should_register_top_level = true;

			if ( function_exists( 'apply_filters' ) ) {
				$should_register_top_level = (bool) apply_filters(
					'sbdp/finance/admin/register_top_level',
					true,
					$module
				);
			}

			if ( $should_register_top_level ) {
				return;
			}

			add_submenu_page(
				'sbdp_bookings',
			__( 'Financieel overzicht', 'sbdp' ),
			__( 'Finance', 'sbdp' ),
				'manage_woocommerce',
				FinanceModule::menuSlug(),
				array( $module, 'renderPage' )
			);

			return;
		}

		add_submenu_page(
			'sbdp_bookings',
			__( 'Finance', 'sbdp' ),
			__( 'Finance', 'sbdp' ),
			'manage_woocommerce',
			'sbdp_finance',
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( class_exists( FinanceModule::class ) ) {
			FinanceModule::boot()->renderPage();
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Financieel overzicht', 'sbdp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Tijdelijke placeholder voor het financiële dashboard.', 'sbdp' ) . '</p>';
		echo '</div>';
	}
}
