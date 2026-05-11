<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Admin_Sync_Dashboard {
	public const PAGE_SLUG = 'ddb-spots-sync-dashboard';
	private const LOG_OPTION = 'ddb_spots_sync_logs';
	private const MAX_LOGS = 250;

	public function init(): void {
		add_action('admin_menu', array($this, 'register_menu'));
	}

	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=ddb_spot',
			__('Sync Dashboard', 'ddb-spots'),
			__('Sync Dashboard', 'ddb-spots'),
			DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE,
			self::PAGE_SLUG,
			array($this, 'render_page')
		);
	}

	public static function log_event(string $event, string $status, array $context = array()): void {
		$status = sanitize_key($status);
		if (! in_array($status, array('success', 'warning', 'error', 'info'), true)) {
			$status = 'info';
		}

		$entry = array(
			'timestamp' => gmdate('c'),
			'event' => sanitize_key($event),
			'status' => $status,
			'message' => isset($context['message']) ? sanitize_text_field((string) $context['message']) : '',
			'post_id' => isset($context['post_id']) ? absint($context['post_id']) : 0,
			'source' => isset($context['source']) ? sanitize_key((string) $context['source']) : '',
			'user_id' => get_current_user_id() > 0 ? get_current_user_id() : 0,
			'context' => self::sanitize_context($context),
		);

		$logs = self::get_logs();
		array_unshift($logs, $entry);
		if (count($logs) > self::MAX_LOGS) {
			$logs = array_slice($logs, 0, self::MAX_LOGS);
		}
		update_option(self::LOG_OPTION, $logs, false);
	}

	private static function sanitize_context(array $context): array {
		$safe = array();
		foreach ($context as $key => $value) {
			$safe_key = sanitize_key((string) $key);
			if ('' === $safe_key || in_array($safe_key, array('message', 'post_id', 'source'), true)) {
				continue;
			}
			if (is_scalar($value)) {
				$safe[ $safe_key ] = sanitize_text_field((string) $value);
			}
		}
		return $safe;
	}

	private static function get_logs(): array {
		$logs = get_option(self::LOG_OPTION, array());
		return is_array($logs) ? array_values($logs) : array();
	}

	public function render_page(): void {
		if (! current_user_can(DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE)) {
			wp_die(esc_html__('Insufficient permissions.', 'ddb-spots'));
		}

		$logs = self::get_logs();
		$stats = $this->build_stats($logs);

		echo '<div class="wrap ddb-admin-ui ddb-admin-ui-wrap">';
		echo '<h1>' . esc_html__('DDB Spots Sync Dashboard', 'ddb-spots') . '</h1>';

		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th>' . esc_html__('Last 24h runs', 'ddb-spots') . '</th><td>' . esc_html((string) $stats['last24']) . '</td></tr>';
		echo '<tr><th>' . esc_html__('Success', 'ddb-spots') . '</th><td>' . esc_html((string) $stats['success']) . '</td></tr>';
		echo '<tr><th>' . esc_html__('Warnings', 'ddb-spots') . '</th><td>' . esc_html((string) $stats['warning']) . '</td></tr>';
		echo '<tr><th>' . esc_html__('Errors', 'ddb-spots') . '</th><td>' . esc_html((string) $stats['error']) . '</td></tr>';
		echo '</tbody></table>';

		echo '<h2 style="margin-top:20px;">' . esc_html__('Recent Sync Activity', 'ddb-spots') . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__('Time (UTC)', 'ddb-spots') . '</th>';
		echo '<th>' . esc_html__('Event', 'ddb-spots') . '</th>';
		echo '<th>' . esc_html__('Status', 'ddb-spots') . '</th>';
		echo '<th>' . esc_html__('Message', 'ddb-spots') . '</th>';
		echo '<th>' . esc_html__('Spot', 'ddb-spots') . '</th>';
		echo '</tr></thead><tbody>';

		if (empty($logs)) {
			echo '<tr><td colspan="5">' . esc_html__('Nog geen sync logging beschikbaar.', 'ddb-spots') . '</td></tr>';
		} else {
			foreach ($logs as $entry) {
				$post_id = isset($entry['post_id']) ? absint($entry['post_id']) : 0;
				$spot = '—';
				if ($post_id > 0) {
					$title = get_the_title($post_id);
					$edit = get_edit_post_link($post_id, 'raw');
					$spot = '' !== (string) $edit ? '<a href="' . esc_url((string) $edit) . '">#' . esc_html((string) $post_id) . ' ' . esc_html((string) $title) . '</a>' : '#' . esc_html((string) $post_id);
				}

				echo '<tr>';
				echo '<td>' . esc_html((string) ($entry['timestamp'] ?? '')) . '</td>';
				echo '<td><code>' . esc_html((string) ($entry['event'] ?? '')) . '</code></td>';
				echo '<td>' . esc_html((string) ($entry['status'] ?? 'info')) . '</td>';
				echo '<td>' . esc_html((string) ($entry['message'] ?? '')) . '</td>';
				echo '<td>' . $spot . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function build_stats(array $logs): array {
		$now = time();
		$window = $now - DAY_IN_SECONDS;
		$out = array(
			'last24' => 0,
			'success' => 0,
			'warning' => 0,
			'error' => 0,
		);

		foreach ($logs as $entry) {
			$stamp = isset($entry['timestamp']) ? strtotime((string) $entry['timestamp']) : false;
			$status = isset($entry['status']) ? (string) $entry['status'] : 'info';
			if (false !== $stamp && $stamp >= $window) {
				$out['last24']++;
			}
			if (isset($out[ $status ])) {
				$out[ $status ]++;
			}
		}

		return $out;
	}
}
