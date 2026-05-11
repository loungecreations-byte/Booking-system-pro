<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Service_Canonical_Sync {
	private DDB_Spots_Domain_Spot_Repository $spots;
	private DDB_Spots_Domain_Audit_Repository $audit;

	public function __construct(DDB_Spots_Domain_Spot_Repository $spots, DDB_Spots_Domain_Audit_Repository $audit) {
		$this->spots = $spots;
		$this->audit = $audit;
	}

	public function init(): void {
		add_action('save_post_ddb_spot', array($this, 'sync_post_from_hook'), 30, 3);
		add_action('before_delete_post', array($this, 'handle_delete'));
		add_action('ddb_spots_canonical_sync_post', array($this, 'sync_post'), 10, 2);
	}

	public function sync_post_from_hook(int $post_id, WP_Post $post, bool $update): void {
		unset($post, $update);
		$this->sync_post($post_id, 'save_post');
	}

	public function sync_post(int $post_id, string $source = 'manual'): void {
		$before = $this->spots->get_by_post_id($post_id);
		$after = $this->spots->upsert_from_post($post_id);
		if (! is_array($after)) {
			return;
		}

		$diff = $this->build_diff(is_array($before) ? $before : array(), $after);
		$this->audit->log(
			'spot',
			(int) $after['id'],
			'sync_' . sanitize_key($source),
			get_current_user_id(),
			$diff
		);
	}

	public function handle_delete(int $post_id): void {
		$post = get_post($post_id);
		if (! $post instanceof WP_Post || 'ddb_spot' !== $post->post_type) {
			return;
		}
		$row = $this->spots->get_by_post_id($post_id);
		if (! is_array($row)) {
			return;
		}
		$this->spots->set_status((int) $row['id'], 'archived');
		$this->audit->log('spot', (int) $row['id'], 'archive_on_delete', get_current_user_id(), array('status' => 'archived'));
	}

	private function build_diff(array $before, array $after): array {
		$diff = array();
		foreach ($after as $key => $value) {
			if (in_array($key, array('updated_at', 'created_at'), true)) {
				continue;
			}
			$previous = $before[ $key ] ?? null;
			if ($previous !== $value) {
				$diff[ $key ] = array('before' => $previous, 'after' => $value);
			}
		}
		return $diff;
	}
}

