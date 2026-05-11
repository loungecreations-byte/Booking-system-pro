<?php
/**
 * Live override for private tour storytelling UI.
 *
 * Forces the updated tour CSS/JS inline on the active local site and
 * normalizes published preview URLs back to their canonical permalink.
 */

if (! defined('ABSPATH')) {
	exit;
}

// Emergency rollback file only. The canonical private-tour runtime already
// registers its own assets and REST wiring inside booking-pro-module.
if (! defined('SBDP_ENABLE_TOUR_LIVE_OVERRIDE') || ! SBDP_ENABLE_TOUR_LIVE_OVERRIDE) {
	return;
}

add_action(
	'init',
	static function (): void {
		if (is_admin()) {
			return;
		}

		$preview_id = isset($_GET['preview_id']) ? absint((int) $_GET['preview_id']) : 0;
		$is_preview = isset($_GET['preview']) && 'true' === (string) $_GET['preview'];
		if ($preview_id <= 0 || ! $is_preview) {
			return;
		}

		if ('sbdp_private_tour' !== get_post_type($preview_id)) {
			return;
		}

		if ('publish' !== get_post_status($preview_id)) {
			return;
		}

		$target = get_permalink($preview_id);
		if (is_string($target) && '' !== $target) {
			wp_safe_redirect($target, 302);
			exit;
		}
	},
	1
);

add_action(
	'template_redirect',
	static function (): void {
		if (! is_singular('sbdp_private_tour')) {
			return;
		}

		nocache_headers();
	},
	0
);

add_action(
	'send_headers',
	static function (): void {
		if (! is_singular('sbdp_private_tour')) {
			return;
		}

		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
		header('Pragma: no-cache', true);
		header('Expires: Wed, 11 Jan 1984 05:00:00 GMT', true);
	},
	999
);

add_action(
	'wp_headers',
	static function (array $headers): array {
		if (! is_singular('sbdp_private_tour')) {
			return $headers;
		}

		$headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';
		$headers['Pragma']        = 'no-cache';
		$headers['Expires']       = 'Wed, 11 Jan 1984 05:00:00 GMT';

		return $headers;
	},
	999
);

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if (! is_singular('sbdp_private_tour')) {
			return;
		}

		$base = WP_CONTENT_DIR . '/plugins/booking-pro-module/';
		$css_file = $base . 'assets/css/tour-navigation.css';
		$js_file  = $base . 'assets/js/tour-navigation.js';
		$js  = is_readable($js_file) ? (string) file_get_contents($js_file) : '';

		if (is_readable($css_file)) {
			wp_dequeue_style('sbdp-tour-navigation');
			wp_deregister_style('sbdp-tour-navigation');
			wp_register_style(
				'sbdp-tour-navigation-inline',
				WP_CONTENT_URL . '/plugins/booking-pro-module/assets/css/tour-navigation.css',
				array(),
				(string) filemtime($css_file)
			);
			wp_enqueue_style('sbdp-tour-navigation-inline');
		}

		if ('' !== $js) {
			wp_dequeue_script('sbdp-tour-navigation');
			wp_deregister_script('sbdp-tour-navigation');
			wp_register_script('sbdp-tour-navigation-inline', false, array(), (string) filemtime($js_file), true);
			wp_enqueue_script('sbdp-tour-navigation-inline');
			wp_add_inline_script(
				'sbdp-tour-navigation-inline',
				'window.sbdpTourNavigation = window.sbdpTourNavigation || ' . wp_json_encode(
					array(
						'routeEndpoint'         => esc_url_raw(rest_url('sbdp/v1/private-tours/navigation/route')),
						'nonce'                 => wp_create_nonce('wp_rest'),
						'defaultMapTiles'       => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
						'defaultMapAttribution' => '&copy; OpenStreetMap contributors',
					)
				) . ';',
				'before'
			);
			wp_add_inline_script('sbdp-tour-navigation-inline', $js, 'after');
		}
	},
	1000
);
