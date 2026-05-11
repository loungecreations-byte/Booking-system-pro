<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Admin_Insights_Page {
	private const PAGE_SLUG = 'ddb-spots-insights';
	private DDB_Spots_Domain_Event_Repository $events;
	private DDB_Spots_Domain_Audit_Repository $audit;

	public function __construct(DDB_Spots_Domain_Event_Repository $events, DDB_Spots_Domain_Audit_Repository $audit) {
		$this->events = $events;
		$this->audit = $audit;
	}

	public function init(): void {
		add_action('admin_menu', array($this, 'register_menu'));
	}

	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=ddb_spot',
			__('Insights', 'ddb-spots'),
			__('Insights', 'ddb-spots'),
			DDB_Spots_Core_Roles::CAP_VIEW_INSIGHTS,
			self::PAGE_SLUG,
			array($this, 'render_page')
		);
	}

	public function render_page(): void {
		if (! current_user_can(DDB_Spots_Core_Roles::CAP_VIEW_INSIGHTS)) {
			wp_die(esc_html__('Insufficient permissions.', 'ddb-spots'));
		}

		$event_counts = $this->events->counts_by_event_type(14);
		$funnel = $this->events->funnel_metrics(14);
		$recent_audit = $this->audit->recent(20);

		echo '<div class="wrap ddb-admin-ui ddb-admin-ui-wrap">';
		echo '<h1>' . esc_html__('DDB Spots Insights', 'ddb-spots') . '</h1>';
		echo '<h2>' . esc_html__('Conversion Funnel (14 days)', 'ddb-spots') . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th>' . esc_html__('Views', 'ddb-spots') . '</th><td>' . esc_html((string) number_format_i18n((int) ($funnel['view'] ?? 0))) . '</td></tr>';
		echo '<tr><th>' . esc_html__('CTA clicks', 'ddb-spots') . '</th><td>' . esc_html((string) number_format_i18n((int) ($funnel['click'] ?? 0))) . '</td></tr>';
		echo '<tr><th>' . esc_html__('Added to plan', 'ddb-spots') . '</th><td>' . esc_html((string) number_format_i18n((int) ($funnel['plan'] ?? 0))) . '</td></tr>';
		echo '<tr><th>' . esc_html__('Book clicks', 'ddb-spots') . '</th><td>' . esc_html((string) number_format_i18n((int) ($funnel['book'] ?? 0))) . '</td></tr>';
		echo '<tr><th>' . esc_html__('CTR', 'ddb-spots') . '</th><td>' . esc_html((string) ($funnel['ctr'] ?? 0.0)) . '%</td></tr>';
		echo '<tr><th>' . esc_html__('Plan rate', 'ddb-spots') . '</th><td>' . esc_html((string) ($funnel['plan_rate'] ?? 0.0)) . '%</td></tr>';
		echo '<tr><th>' . esc_html__('Book rate', 'ddb-spots') . '</th><td>' . esc_html((string) ($funnel['book_rate'] ?? 0.0)) . '%</td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__('Event Volume (14 days)', 'ddb-spots') . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Event', 'ddb-spots') . '</th><th>' . esc_html__('Count', 'ddb-spots') . '</th></tr></thead><tbody>';
		if (empty($event_counts)) {
			echo '<tr><td colspan="2">' . esc_html__('No event data yet.', 'ddb-spots') . '</td></tr>';
		} else {
			foreach ($event_counts as $event_type => $count) {
				echo '<tr><td><code>' . esc_html((string) $event_type) . '</code></td><td>' . esc_html((string) $count) . '</td></tr>';
			}
		}
		echo '</tbody></table>';

		echo '<h2 style="margin-top:20px;">' . esc_html__('Recent Audit Trail', 'ddb-spots') . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Time (UTC)', 'ddb-spots') . '</th><th>' . esc_html__('Action', 'ddb-spots') . '</th><th>' . esc_html__('Entity', 'ddb-spots') . '</th><th>' . esc_html__('User', 'ddb-spots') . '</th></tr></thead><tbody>';
		if (empty($recent_audit)) {
			echo '<tr><td colspan="4">' . esc_html__('No audit entries yet.', 'ddb-spots') . '</td></tr>';
		} else {
			foreach ($recent_audit as $row) {
				$user = isset($row['user_id']) ? get_user_by('id', (int) $row['user_id']) : false;
				$user_label = $user instanceof WP_User ? $user->user_login : 'system';
				echo '<tr>';
				echo '<td>' . esc_html((string) ($row['created_at'] ?? '')) . '</td>';
				echo '<td><code>' . esc_html((string) ($row['action'] ?? '')) . '</code></td>';
				echo '<td>' . esc_html((string) ($row['entity_type'] ?? '')) . ' #' . esc_html((string) ($row['entity_id'] ?? '0')) . '</td>';
				echo '<td>' . esc_html((string) $user_label) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';
		echo '</div>';
	}
}
