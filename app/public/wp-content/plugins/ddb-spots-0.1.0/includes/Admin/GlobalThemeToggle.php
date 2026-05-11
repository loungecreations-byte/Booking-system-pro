<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Admin_Global_Theme_Toggle {
	private const ACTION = 'ddb_spots_toggle_admin_theme';
	private const NONCE_ACTION = 'ddb_spots_toggle_admin_theme';
	private const SCHEME_LIGHT = 'fresh';
	private const SCHEME_DARK = 'midnight';

	public function init(): void {
		add_action('admin_bar_menu', array($this, 'add_admin_bar_toggle'), 1);
		add_action('admin_post_' . self::ACTION, array($this, 'handle_toggle_action'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('enqueue_block_assets', array($this, 'enqueue_block_editor_assets'));
	}

	public function add_admin_bar_toggle(WP_Admin_Bar $admin_bar): void {
		if (! is_admin() || ! is_admin_bar_showing() || ! current_user_can('read')) {
			return;
		}

		$user_id = get_current_user_id();
		if ($user_id <= 0) {
			return;
		}

		$current_scheme = $this->get_current_scheme($user_id);
		$is_dark = $this->is_dark_scheme($current_scheme);
		$label = $is_dark ? __('Light modus', 'ddb-spots') : __('Dark modus', 'ddb-spots');
		$url = wp_nonce_url(
			add_query_arg(
				array('action' => self::ACTION),
				admin_url('admin-post.php')
			),
			self::NONCE_ACTION
		);

		$admin_bar->add_node(
			array(
				'id' => 'ddb-spots-theme-toggle',
				'title' => $label,
				'href' => $url,
				'meta' => array(
					'title' => $label,
				),
			)
		);
	}

	public function handle_toggle_action(): void {
		if (! current_user_can('read')) {
			wp_die(esc_html__('Insufficient permissions.', 'ddb-spots'));
		}
		check_admin_referer(self::NONCE_ACTION);

		$user_id = get_current_user_id();
		if ($user_id <= 0) {
			wp_die(esc_html__('Geen ingelogde gebruiker.', 'ddb-spots'));
		}

		$current_scheme = $this->get_current_scheme($user_id);
		$next_scheme = $this->is_dark_scheme($current_scheme) ? self::SCHEME_LIGHT : self::SCHEME_DARK;
		update_user_option($user_id, 'admin_color', $next_scheme, true);

		$redirect = wp_get_referer();
		if (! $redirect) {
			$redirect = admin_url();
		}

		wp_safe_redirect($redirect);
		exit;
	}

	public function enqueue_assets(): void {
		if (! is_admin()) {
			return;
		}
		$this->enqueue_dark_stylesheet();
	}

	public function enqueue_block_editor_assets(): void {
		if (! is_admin()) {
			return;
		}
		$this->enqueue_dark_stylesheet();
	}

	private function enqueue_dark_stylesheet(): void {
		wp_enqueue_style(
			'ddb-spots-global-admin-dark',
			DDB_SPOTS_URL . 'assets/css/ddb-global-admin-dark.css',
			array(),
			DDB_SPOTS_VERSION
		);
	}

	private function get_current_scheme(int $user_id): string {
		$scheme = (string) get_user_option('admin_color', $user_id);
		return '' !== $scheme ? sanitize_key($scheme) : self::SCHEME_LIGHT;
	}

	private function is_dark_scheme(string $scheme): bool {
		return self::SCHEME_DARK === sanitize_key($scheme);
	}
}
