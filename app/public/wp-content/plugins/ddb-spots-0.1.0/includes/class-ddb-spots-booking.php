<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Booking {
	private const POST_TYPE = 'ddb_spot';
	private const NOTICE_KEY = 'ddb_spots_embed_removed_notice_';

	public function init(): void {
		add_filter('wp_insert_post_data', array($this, 'strip_booking_embed_from_content'), 10, 2);
		add_action('admin_notices', array($this, 'render_embed_removed_notice'));
	}

	public function strip_booking_embed_from_content(array $data, array $postarr): array {
		$post_type = isset($data['post_type']) ? (string) $data['post_type'] : '';
		if (self::POST_TYPE !== $post_type) {
			return $data;
		}
		$content = isset($data['post_content']) ? (string) $data['post_content'] : '';
		if ('' === $content) {
			return $data;
		}

		$cleaned = preg_replace('#<(script|iframe)\b[^>]*>.*?</\1>#is', '', $content);
		if (null === $cleaned) {
			return $data;
		}
		$cleaned = trim($cleaned);
		if ($cleaned === trim($content)) {
			return $data;
		}

		$data['post_content'] = $cleaned;
		set_transient(self::NOTICE_KEY . get_current_user_id(), 1, MINUTE_IN_SECONDS);
		return $data;
	}

	public function render_embed_removed_notice(): void {
		$screen = get_current_screen();
		if (! $screen || self::POST_TYPE !== $screen->post_type) {
			return;
		}
		$key = self::NOTICE_KEY . get_current_user_id();
		if (! get_transient($key)) {
			return;
		}
		delete_transient($key);
		?>
		<div class="notice notice-warning is-dismissible">
			<p><?php esc_html_e('Embed code is verwijderd uit de hoofdcontent. Gebruik de tab Boeken & CTA.', 'ddb-spots'); ?></p>
		</div>
		<?php
	}
}
