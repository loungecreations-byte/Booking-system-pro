<?php
if (! defined('ABSPATH')) {
	exit;
}

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI_Command')) {
	/**
	 * Media integrity operations for spots.
	 */
	class DDB_Spots_CLI_Media_Command extends WP_CLI_Command {
		/**
		 * Audit spot thumbnail and gallery files.
		 *
		 * ## OPTIONS
		 *
		 * [--post-ids=<ids>]
		 * : Comma-separated spot post IDs. Defaults to all published spots.
		 *
		 * [--format=<format>]
		 * : table|json. Default: table.
		 */
		public function audit(array $args, array $assoc_args): void {
			unset($args);

			$spot_ids = $this->resolve_spot_ids($assoc_args);
			$result = $this->audit_spots($spot_ids);
			$this->render_result($result, $assoc_args);
		}

		/**
		 * Repair missing spot media by pruning broken gallery refs and optionally redownloading Google photos.
		 *
		 * ## OPTIONS
		 *
		 * [--post-ids=<ids>]
		 * : Comma-separated spot post IDs. Defaults to all published spots.
		 *
		 * [--write]
		 * : Persist repairs. Without this flag the command is a dry-run.
		 *
		 * [--sync-google]
		 * : Use the existing Google Places importer to redownload missing media for spots with a place_id.
		 *
		 * [--format=<format>]
		 * : table|json. Default: table.
		 */
		public function repair(array $args, array $assoc_args): void {
			unset($args);

			$dry_run = ! isset($assoc_args['write']);
			$sync_google = isset($assoc_args['sync-google']);
			$spot_ids = $this->resolve_spot_ids($assoc_args);
			$rows = array();
			$synced = 0;
			$fixed = 0;

			foreach ($spot_ids as $spot_id) {
				$before = $this->inspect_spot($spot_id);
				$actions = array();

				if (! empty($before['missing_thumbnail']) || ! empty($before['missing_gallery_ids'])) {
					if ($sync_google && ! $dry_run) {
						$sync_result = $this->sync_google_media($spot_id);
						if (is_wp_error($sync_result)) {
							$actions[] = 'google_error:' . $sync_result->get_error_code();
						} else {
							$synced++;
							$actions[] = 'google_sync';
						}
					} elseif ($sync_google) {
						$actions[] = 'google_sync_dry_run';
					}
				}

				if (! $dry_run) {
					$pruned = $this->prune_broken_gallery($spot_id);
					if ($pruned > 0) {
						$actions[] = 'prune_gallery';
					}

					if ($this->repair_thumbnail_from_gallery($spot_id)) {
						$actions[] = 'set_thumbnail_from_gallery';
					}

					if (! empty($actions)) {
						do_action('ddb_spots_canonical_sync_post', $spot_id, 'media_repair');
					}
				}

				$after = $this->inspect_spot($spot_id);
				if (! empty($actions) && empty($after['missing_thumbnail']) && empty($after['missing_gallery_ids'])) {
					$fixed++;
				}

				$rows[] = array(
					'spot_id' => $spot_id,
					'spot_title' => (string) get_the_title($spot_id),
					'thumbnail_id' => (int) $after['thumbnail_id'],
					'thumbnail_file' => (string) $after['thumbnail_file'],
					'missing_thumbnail' => empty($after['missing_thumbnail']) ? 'no' : 'yes',
					'missing_gallery_ids' => implode(',', array_map('strval', $after['missing_gallery_ids'])),
					'actions' => implode(',', $actions),
				);
			}

			$result = array(
				'scanned' => count($spot_ids),
				'fixed' => $fixed,
				'synced_google' => $synced,
				'missing_thumbnails' => count(array_filter($rows, static fn($row) => 'yes' === ($row['missing_thumbnail'] ?? ''))),
				'missing_gallery_refs' => array_sum(array_map(static function ($row): int {
					$missing = trim((string) ($row['missing_gallery_ids'] ?? ''));
					return '' === $missing ? 0 : count(array_filter(array_map('trim', explode(',', $missing))));
				}, $rows)),
				'dry_run' => $dry_run,
				'rows' => $rows,
			);

			$this->render_result($result, $assoc_args);
		}

		private function render_result(array $result, array $assoc_args): void {
			$format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
			if ('json' === $format) {
				WP_CLI::line((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
				return;
			}

			$rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
			if (empty($rows)) {
				WP_CLI::line('No spots found.');
			} else {
				WP_CLI\Utils\format_items('table', $rows, array('spot_id', 'spot_title', 'thumbnail_id', 'missing_thumbnail', 'missing_gallery_ids', 'actions'));
			}

			WP_CLI::success(
				sprintf(
					'Scanned=%d, Missing thumbnails=%d, Missing gallery refs=%d%s',
					(int) ($result['scanned'] ?? 0),
					(int) ($result['missing_thumbnails'] ?? 0),
					(int) ($result['missing_gallery_refs'] ?? 0),
					! empty($result['dry_run']) ? ' (dry-run)' : ''
				)
			);
		}

		private function resolve_spot_ids(array $assoc_args): array {
			if (isset($assoc_args['post-ids'])) {
				$ids = array_values(array_filter(array_map('absint', array_map('trim', explode(',', (string) $assoc_args['post-ids'])))));
				return $this->filter_spot_ids($ids);
			}

			$query = new WP_Query(
				array(
					'post_type' => 'ddb_spot',
					'post_status' => 'publish',
					'posts_per_page' => -1,
					'fields' => 'ids',
					'orderby' => 'ID',
					'order' => 'ASC',
				)
			);

			return $this->filter_spot_ids(array_map('absint', $query->posts));
		}

		private function filter_spot_ids(array $ids): array {
			$out = array();
			foreach ($ids as $id) {
				$post = get_post($id);
				if ($post instanceof WP_Post && 'ddb_spot' === $post->post_type) {
					$out[] = $id;
				}
			}
			return array_values(array_unique($out));
		}

		private function audit_spots(array $spot_ids): array {
			$rows = array();
			$missing_thumbnails = 0;
			$missing_gallery_refs = 0;

			foreach ($spot_ids as $spot_id) {
				$info = $this->inspect_spot($spot_id);
				if (! empty($info['missing_thumbnail'])) {
					$missing_thumbnails++;
				}
				$missing_gallery_refs += count($info['missing_gallery_ids']);

				$rows[] = array(
					'spot_id' => $spot_id,
					'spot_title' => (string) get_the_title($spot_id),
					'thumbnail_id' => (int) $info['thumbnail_id'],
					'thumbnail_file' => (string) $info['thumbnail_file'],
					'missing_thumbnail' => empty($info['missing_thumbnail']) ? 'no' : 'yes',
					'missing_gallery_ids' => implode(',', array_map('strval', $info['missing_gallery_ids'])),
					'actions' => '',
				);
			}

			return array(
				'scanned' => count($spot_ids),
				'missing_thumbnails' => $missing_thumbnails,
				'missing_gallery_refs' => $missing_gallery_refs,
				'rows' => $rows,
			);
		}

		private function inspect_spot(int $spot_id): array {
			$thumbnail_id = (int) get_post_thumbnail_id($spot_id);
			$thumbnail_file = $thumbnail_id > 0 ? (string) get_post_meta($thumbnail_id, '_wp_attached_file', true) : '';
			$gallery_ids = $this->get_gallery_ids($spot_id);
			$missing_gallery_ids = array();

			foreach ($gallery_ids as $gallery_id) {
				if (! $this->attachment_file_exists($gallery_id)) {
					$missing_gallery_ids[] = $gallery_id;
				}
			}

			return array(
				'thumbnail_id' => $thumbnail_id,
				'thumbnail_file' => $thumbnail_file,
				'missing_thumbnail' => $thumbnail_id <= 0 || ! $this->attachment_file_exists($thumbnail_id),
				'gallery_ids' => $gallery_ids,
				'missing_gallery_ids' => $missing_gallery_ids,
			);
		}

		private function get_gallery_ids(int $spot_id): array {
			$raw = (string) get_post_meta($spot_id, '_ddb_gallery_ids', true);
			return array_values(array_unique(array_filter(array_map('absint', array_map('trim', explode(',', $raw))))));
		}

		private function attachment_file_exists(int $attachment_id): bool {
			if ($attachment_id <= 0) {
				return false;
			}
			$file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
			if ('' === $file) {
				return false;
			}
			return file_exists(WP_CONTENT_DIR . '/uploads/' . ltrim($file, '/'));
		}

		private function sync_google_media(int $spot_id): int|WP_Error {
			if (! class_exists('DDB_Spots_Integrations_Google_Places')) {
				return new WP_Error('ddb_spots_google_missing', 'Google Places integration is not available.');
			}

			$place_id = (string) get_post_meta($spot_id, '_ddb_google_place_id', true);
			if ('' === trim($place_id)) {
				return new WP_Error('ddb_spots_no_place_id', 'No Google Place ID found for this spot.');
			}

			$this->remove_stale_google_media_map_entries($spot_id);
			$autosync = (string) get_post_meta($spot_id, '_ddb_google_autosync', true);
			if ('' === $autosync) {
				$autosync = '1';
			}

			$google = new DDB_Spots_Integrations_Google_Places();
			return $google->import_place_by_id($place_id, $autosync, $spot_id, true);
		}

		private function remove_stale_google_media_map_entries(int $spot_id): void {
			$raw = (string) get_post_meta($spot_id, '_ddb_google_photo_media_map_json', true);
			$map = json_decode($raw, true);
			if (! is_array($map) || empty($map)) {
				return;
			}

			$clean = array();
			foreach ($map as $photo_ref => $attachment_id) {
				$id = absint((int) $attachment_id);
				if ($id > 0 && $this->attachment_file_exists($id)) {
					$clean[(string) $photo_ref] = $id;
				}
			}

			if ($clean !== $map) {
				update_post_meta($spot_id, '_ddb_google_photo_media_map_json', wp_json_encode($clean));
			}
		}

		private function prune_broken_gallery(int $spot_id): int {
			$gallery_ids = $this->get_gallery_ids($spot_id);
			$valid = array_values(array_filter($gallery_ids, array($this, 'attachment_file_exists')));

			if ($valid === $gallery_ids) {
				return 0;
			}

			update_post_meta($spot_id, '_ddb_gallery_ids', implode(',', array_map('strval', $valid)));
			return count($gallery_ids) - count($valid);
		}

		private function repair_thumbnail_from_gallery(int $spot_id): bool {
			$thumbnail_id = (int) get_post_thumbnail_id($spot_id);
			if ($thumbnail_id > 0 && $this->attachment_file_exists($thumbnail_id)) {
				return false;
			}

			foreach ($this->get_gallery_ids($spot_id) as $gallery_id) {
				if ($this->attachment_file_exists($gallery_id)) {
					set_post_thumbnail($spot_id, $gallery_id);
					return true;
				}
			}

			return false;
		}
	}

	WP_CLI::add_command('ddb-spots media', 'DDB_Spots_CLI_Media_Command');
}
