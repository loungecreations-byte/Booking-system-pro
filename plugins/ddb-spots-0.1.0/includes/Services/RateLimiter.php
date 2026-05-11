<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Service_Rate_Limiter {
	public function allow(string $bucket, int $limit, int $window_seconds, string $subject = ''): bool {
		$limit = max(1, $limit);
		$window_seconds = max(10, $window_seconds);
		$key = $this->build_key($bucket, $subject);
		$current = (int) get_transient($key);
		if ($current >= $limit) {
			return false;
		}
		set_transient($key, $current + 1, $window_seconds);
		return true;
	}

	private function build_key(string $bucket, string $subject): string {
		$bucket = sanitize_key($bucket);
		$subject = sanitize_text_field($subject);
		if ('' === $subject) {
			$subject = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
		}
		$fingerprint = sha1($bucket . '|' . $subject);
		return 'dbspots_rl_' . $fingerprint;
	}
}
