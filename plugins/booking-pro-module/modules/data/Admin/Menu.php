<?php

declare(strict_types=1);

namespace BSPModule\Data\Admin;

use BSP\Intelligence\ReportsService;

use function add_action;
use function add_submenu_page;
use function esc_html__;
use function esc_html;
use function __;

final class Menu {

	public static function init(): void {
		add_action('admin_menu', array(__CLASS__, 'register_menu'));
		add_action('admin_post_sbdp_data_export_csv', array(__CLASS__, 'export_csv'));
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
		if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
			wp_die(esc_html__('Je hebt geen toegang tot dit data-overzicht.', 'sbdp'));
		}
		$snapshot = (new ReportsService())->generateSnapshot();
		$channels = is_array($snapshot['channels'] ?? null) ? $snapshot['channels'] : array();
		$products = is_array($snapshot['top_products'] ?? null) ? $snapshot['top_products'] : array();
		$exportUrl = wp_nonce_url(admin_url('admin-post.php?action=sbdp_data_export_csv'), 'sbdp_data_export_csv');
		$spotImportUrl = add_query_arg(array('post_type' => 'ddb_spot', 'page' => 'ddb-spots-bulk-csv-sync'), admin_url('edit.php'));

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('Data & Analytics', 'sbdp') . '</h1>';
		echo '<p>' . esc_html__('Read-only rapportage op betaalde WooCommerce-orders. Woo blijft eigenaar van omzet en ordertotalen.', 'sbdp') . '</p>';
		echo '<p><a class="button button-secondary" href="' . esc_url($exportUrl) . '">' . esc_html__('Exporteer CSV', 'sbdp') . '</a></p>';
		$canImportSpots = post_type_exists('ddb_spot')
			&& class_exists('DDB_Spots_Core_Roles')
			&& current_user_can(\DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE);
		if ($canImportSpots) {
			echo '<p><a class="button button-secondary" href="' . esc_url($spotImportUrl) . '">' . esc_html__('Open Spots CSV-import', 'sbdp') . '</a> <span class="description">' . esc_html__('Vaste spot_id, dry-run standaard en geen Woo-/bookingvelden.', 'sbdp') . '</span></p>';
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('KPI', 'sbdp') . '</th><th>' . esc_html__('Waarde', 'sbdp') . '</th></tr></thead><tbody>';
		echo '<tr><td>' . esc_html__('Betaalde boekingen', 'sbdp') . '</td><td><strong>' . esc_html((string) ((int) ($snapshot['bookings'] ?? 0))) . '</strong></td></tr>';
		echo '<tr><td>' . esc_html__('Omzet', 'sbdp') . '</td><td><strong>' . esc_html(self::money((float) ($snapshot['revenue'] ?? 0))) . '</strong></td></tr>';
		echo '<tr><td>' . esc_html__('Gegenereerd', 'sbdp') . '</td><td>' . esc_html((string) ($snapshot['generated_at'] ?? '')) . '</td></tr>';
		echo '</tbody></table>';
		self::render_breakdown(__('Omzet per kanaal', 'sbdp'), $channels);
		self::render_breakdown(__('Topproducten op omzet', 'sbdp'), $products);
		echo '</div>';
	}

	public static function export_csv(): void {
		if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
			wp_die(esc_html__('Je hebt geen toegang tot deze export.', 'sbdp'));
		}
		check_admin_referer('sbdp_data_export_csv');
		$snapshot = (new ReportsService())->generateSnapshot();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="ddb-analytics-' . gmdate('Y-m-d') . '.csv"');
		$output = fopen('php://output', 'wb');
		if ($output === false) {
			wp_die(esc_html__('CSV-export kon niet worden geopend.', 'sbdp'));
		}
		fputcsv($output, array('type', 'naam', 'waarde'));
		fputcsv($output, array('kpi', 'bookings', (int) ($snapshot['bookings'] ?? 0)));
		fputcsv($output, array('kpi', 'revenue', number_format((float) ($snapshot['revenue'] ?? 0), 2, '.', '')));
		foreach (array('channels', 'top_products') as $type) {
			foreach ((array) ($snapshot[$type] ?? array()) as $name => $value) {
				fputcsv($output, array($type, (string) $name, number_format((float) $value, 2, '.', '')));
			}
		}
		fclose($output);
		exit;
	}

	/** @param array<string,float|int> $rows */
	private static function render_breakdown(string $title, array $rows): void {
		echo '<h2>' . esc_html($title) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__('Naam', 'sbdp') . '</th><th>' . esc_html__('Omzet', 'sbdp') . '</th></tr></thead><tbody>';
		if ($rows === array()) {
			echo '<tr><td colspan="2">' . esc_html__('Nog geen betaalde orderdata beschikbaar.', 'sbdp') . '</td></tr>';
		}
		foreach ($rows as $name => $value) {
			echo '<tr><td>' . esc_html((string) $name) . '</td><td>' . esc_html(self::money((float) $value)) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function money(float $amount): string {
		return function_exists('wc_price') ? wp_strip_all_tags((string) wc_price($amount)) : '€ ' . number_format($amount, 2, ',', '.');
	}
}
