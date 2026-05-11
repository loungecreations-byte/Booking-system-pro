<?php
if (! defined('ABSPATH')) {
	exit;
}

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI_Command')) {
	/**
	 * Business linking operations for spots.
	 */
	class DDB_Spots_Business_CLI_Command extends WP_CLI_Command {
		/**
		 * Bulk-link spots to businesses by place_id/domain/title.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Preview only. Default mode when --write is not passed.
		 *
		 * [--write]
		 * : Persist link changes.
		 *
		 * [--only-unlinked]
		 * : Process only spots without business_id. Default.
		 *
		 * [--include-linked]
		 * : Also scan already linked spots.
		 *
		 * [--limit=<n>]
		 * : Optional max spots to scan.
		 *
		 * [--set-plan-source]
		 * : Set `_ddb_premium_plan_source=business` when linking. Default.
		 *
		 * [--keep-plan-source]
		 * : Do not change plan source meta.
		 *
		 * [--force-plan-source]
		 * : Force plan source update even on already linked spots.
		 *
		 * [--format=<format>]
		 * : table|json. Default: table.
		 */
		public function link(array $args, array $assoc_args): void {
			$dry_run = ! isset($assoc_args['write']);
			if (isset($assoc_args['dry-run'])) {
				$dry_run = true;
			}
			$only_unlinked = ! isset($assoc_args['include-linked']);
			if (isset($assoc_args['only-unlinked'])) {
				$only_unlinked = true;
			}
			$set_plan_source = ! isset($assoc_args['keep-plan-source']);
			if (isset($assoc_args['set-plan-source'])) {
				$set_plan_source = true;
			}
			$force_plan_source = isset($assoc_args['force-plan-source']);
			$limit = isset($assoc_args['limit']) ? max(0, absint((int) $assoc_args['limit'])) : 0;
			$format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
			if (! in_array($format, array('table', 'json'), true)) {
				$format = 'table';
			}

			$result = DDB_Spots_Business_Linker::bulk_link_spots(
				array(
					'dry_run' => $dry_run,
					'only_unlinked' => $only_unlinked,
					'set_plan_source' => $set_plan_source,
					'force_plan_source' => $force_plan_source,
					'limit' => $limit,
				)
			);

			if ('json' === $format) {
				WP_CLI::line((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			} else {
				$rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
				if (empty($rows)) {
					WP_CLI::line('No matches found.');
				} else {
					WP_CLI\Utils\format_items('table', $rows, array('spot_id', 'spot_title', 'business_id', 'business_title', 'rule', 'confidence'));
				}
			}

			$rules = isset($result['rules']) && is_array($result['rules']) ? $result['rules'] : array();
			WP_CLI::success(
				sprintf(
					'Scanned=%d, Linked=%d, Skipped existing=%d, Unmatched=%d, Rules: place_id=%d domain=%d title_exact=%d%s',
					(int) ($result['scanned'] ?? 0),
					(int) ($result['linked'] ?? 0),
					(int) ($result['skipped_existing'] ?? 0),
					(int) ($result['unmatched'] ?? 0),
					(int) ($rules['place_id'] ?? 0),
					(int) ($rules['domain'] ?? 0),
					(int) ($rules['title_exact'] ?? 0),
					$dry_run ? ' (dry-run)' : ''
				)
			);
		}

		/**
		 * Suggest one business match for a single spot.
		 *
		 * ## OPTIONS
		 *
		 * <spot_id>
		 * : Spot post ID.
		 *
		 * [--format=<format>]
		 * : table|json. Default: table.
		 */
		public function suggest(array $args, array $assoc_args): void {
			$spot_id = isset($args[0]) ? absint((int) $args[0]) : 0;
			if ($spot_id <= 0) {
				WP_CLI::error('Provide a valid <spot_id>.');
			}
			$post = get_post($spot_id);
			if (! $post instanceof WP_Post || 'ddb_spot' !== $post->post_type) {
				WP_CLI::error('Spot not found.');
			}

			$match = DDB_Spots_Business_Linker::match_business_for_spot($spot_id);
			if (empty($match['business_id'])) {
				WP_CLI::warning('No unambiguous business match found.');
				return;
			}

			$row = array(
				'spot_id' => $spot_id,
				'spot_title' => (string) get_the_title($spot_id),
				'business_id' => absint((int) $match['business_id']),
				'business_title' => (string) get_the_title((int) $match['business_id']),
				'rule' => sanitize_key((string) ($match['rule'] ?? '')),
				'confidence' => (float) ($match['confidence'] ?? 0.0),
			);

			$format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
			if ('json' === $format) {
				WP_CLI::line((string) wp_json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
				return;
			}

			WP_CLI\Utils\format_items('table', array($row), array('spot_id', 'spot_title', 'business_id', 'business_title', 'rule', 'confidence'));
		}

		/**
		 * Bootstrap businesses from spots and optionally link them.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Preview only. Default mode when --write is not passed.
		 *
		 * [--write]
		 * : Persist business creation and links.
		 *
		 * [--limit=<n>]
		 * : Optional max spots to scan.
		 *
		 * [--only-unlinked]
		 * : Process only spots without business_id. Default.
		 *
		 * [--include-linked]
		 * : Also process already linked spots.
		 *
		 * [--link-spots]
		 * : Link matched/created businesses back to spots. Default.
		 *
		 * [--no-link-spots]
		 * : Create businesses only, do not link spot records.
		 *
		 * [--set-plan-source]
		 * : Set `_ddb_premium_plan_source=business` when linking. Default.
		 *
		 * [--keep-plan-source]
		 * : Keep existing plan source value.
		 *
		 * [--business-status=<status>]
		 * : New business post status: publish|draft|pending|private. Default: draft.
		 *
		 * [--format=<format>]
		 * : table|json. Default: table.
		 */
		public function bootstrap(array $args, array $assoc_args): void {
			$dry_run = ! isset($assoc_args['write']);
			if (isset($assoc_args['dry-run'])) {
				$dry_run = true;
			}
			$only_unlinked = ! isset($assoc_args['include-linked']);
			if (isset($assoc_args['only-unlinked'])) {
				$only_unlinked = true;
			}
			$link_spots = ! isset($assoc_args['no-link-spots']);
			if (isset($assoc_args['link-spots'])) {
				$link_spots = true;
			}
			$set_plan_source = ! isset($assoc_args['keep-plan-source']);
			if (isset($assoc_args['set-plan-source'])) {
				$set_plan_source = true;
			}
			$limit = isset($assoc_args['limit']) ? max(0, absint((int) $assoc_args['limit'])) : 0;
			$business_status = isset($assoc_args['business-status']) ? sanitize_key((string) $assoc_args['business-status']) : 'draft';
			if (! in_array($business_status, array('publish', 'draft', 'pending', 'private'), true)) {
				$business_status = 'draft';
			}
			$format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
			if (! in_array($format, array('table', 'json'), true)) {
				$format = 'table';
			}

			$result = DDB_Spots_Business_Linker::bootstrap_businesses_from_spots(
				array(
					'dry_run' => $dry_run,
					'limit' => $limit,
					'link_spots' => $link_spots,
					'set_plan_source' => $set_plan_source,
					'only_unlinked' => $only_unlinked,
					'business_status' => $business_status,
				)
			);

			if ('json' === $format) {
				WP_CLI::line((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			} else {
				$rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
				if (empty($rows)) {
					WP_CLI::line('No bootstrap actions generated.');
				} else {
					WP_CLI\Utils\format_items('table', $rows, array('spot_id', 'spot_title', 'business_id', 'business_title', 'action', 'rule'));
				}
			}

			WP_CLI::success(
				sprintf(
					'Scanned=%d, Created=%d, Linked=%d, Skipped existing=%d, Skipped missing identity=%d%s',
					(int) ($result['scanned'] ?? 0),
					(int) ($result['created'] ?? 0),
					(int) ($result['linked'] ?? 0),
					(int) ($result['skipped_existing'] ?? 0),
					(int) ($result['skipped_missing_identity'] ?? 0),
					$dry_run ? ' (dry-run)' : ''
				)
			);
		}

		/**
		 * Repair wrong business links where spot place_id differs from business place_id.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Preview only. Default mode when --write is not passed.
		 *
		 * [--write]
		 * : Persist relinks and optional business creation.
		 *
		 * [--limit=<n>]
		 * : Optional max spots to scan.
		 *
		 * [--set-plan-source]
		 * : Set `_ddb_premium_plan_source=business` on fixed spots. Default.
		 *
		 * [--keep-plan-source]
		 * : Keep existing plan source value.
		 *
		 * [--format=<format>]
		 * : table|json. Default: table.
		 */
		public function reconcile(array $args, array $assoc_args): void {
			$dry_run = ! isset($assoc_args['write']);
			if (isset($assoc_args['dry-run'])) {
				$dry_run = true;
			}
			$limit = isset($assoc_args['limit']) ? max(0, absint((int) $assoc_args['limit'])) : 0;
			$set_plan_source = ! isset($assoc_args['keep-plan-source']);
			if (isset($assoc_args['set-plan-source'])) {
				$set_plan_source = true;
			}
			$format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
			if (! in_array($format, array('table', 'json'), true)) {
				$format = 'table';
			}

			$result = DDB_Spots_Business_Linker::reconcile_place_id_links(
				array(
					'dry_run' => $dry_run,
					'limit' => $limit,
					'set_plan_source' => $set_plan_source,
				)
			);

			if ('json' === $format) {
				WP_CLI::line((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			} else {
				$rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
				if (empty($rows)) {
					WP_CLI::line('No place_id mismatches detected.');
				} else {
					WP_CLI\Utils\format_items('table', $rows, array('spot_id', 'spot_title', 'from_business_id', 'from_business_title', 'to_business_id', 'to_business_title', 'action'));
				}
			}

			WP_CLI::success(
				sprintf(
					'Scanned=%d, Mismatched=%d, Fixed=%d%s',
					(int) ($result['scanned'] ?? 0),
					(int) ($result['mismatched'] ?? 0),
					(int) ($result['fixed'] ?? 0),
					$dry_run ? ' (dry-run)' : ''
				)
			);
		}

		/**
		 * Set a premium plan/status on businesses in bulk.
		 *
		 * @subcommand set-plan
		 *
		 * ## OPTIONS
		 *
		 * --plan=<plan>
		 * : Plan key: free|presence|conversion|partner
		 *
		 * [--status=<status>]
		 * : inactive|trial|active|past_due|canceled. Defaults to active for paid plans.
		 *
		 * [--period-end=<date>]
		 * : YYYY-MM-DD.
		 *
		 * [--business-ids=<ids>]
		 * : Comma-separated business IDs.
		 *
		 * [--with-linked-spots]
		 * : Target all businesses that currently have linked spots.
		 *
		 * [--dry-run]
		 * : Preview only. Default mode when --write is not passed.
		 *
		 * [--write]
		 * : Persist changes.
		 *
		 * [--format=<format>]
		 * : table|json. Default: table.
		 */
		public function set_plan(array $args, array $assoc_args): void {
			$plan = isset($assoc_args['plan']) ? sanitize_key((string) $assoc_args['plan']) : '';
			if ('' === $plan) {
				WP_CLI::error('Use --plan=<free|presence|conversion|partner>.');
			}
			$status = isset($assoc_args['status']) ? sanitize_key((string) $assoc_args['status']) : '';
			$period_end = isset($assoc_args['period-end']) ? sanitize_text_field((string) $assoc_args['period-end']) : '';
			$dry_run = ! isset($assoc_args['write']);
			if (isset($assoc_args['dry-run'])) {
				$dry_run = true;
			}
			$format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
			if (! in_array($format, array('table', 'json'), true)) {
				$format = 'table';
			}

			$business_ids = array();
			if (isset($assoc_args['business-ids'])) {
				$business_ids = array_values(array_filter(array_map('absint', array_map('trim', explode(',', (string) $assoc_args['business-ids'])))));
			}
			if (isset($assoc_args['with-linked-spots'])) {
				$business_ids = array_values(array_unique(array_merge($business_ids, DDB_Spots_Business_Plan_Manager::collect_business_ids_with_linked_spots())));
			}
			if (empty($business_ids)) {
				WP_CLI::error('Provide --business-ids=<ids> or --with-linked-spots.');
			}

			$result = DDB_Spots_Business_Plan_Manager::bulk_set_plan($business_ids, $plan, $status, $period_end, $dry_run);
			if ('json' === $format) {
				WP_CLI::line((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			} else {
				$rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
				if (empty($rows)) {
					WP_CLI::line('No business plans changed.');
				} else {
					WP_CLI\Utils\format_items('table', $rows, array('business_id', 'business_title', 'from_plan', 'from_status', 'to_plan', 'to_status', 'to_period_end'));
				}
			}
			WP_CLI::success(
				sprintf(
					'Updated=%d, Skipped=%d%s',
					(int) ($result['updated'] ?? 0),
					(int) ($result['skipped'] ?? 0),
					$dry_run ? ' (dry-run)' : ''
				)
			);
		}

		/**
		 * Migrate legacy spot override plan data into linked business plans.
		 *
		 * @subcommand migrate-spot-overrides
		 *
		 * ## OPTIONS
		 *
		 * [--only-unset-businesses]
		 * : Only update businesses still on free/inactive. Default.
		 *
		 * [--include-set-businesses]
		 * : Also update already configured businesses.
		 *
		 * [--respect-plan-source]
		 * : Only read spot overrides when spot source is `spot`.
		 *
		 * [--limit=<n>]
		 * : Optional max businesses to scan.
		 *
		 * [--dry-run]
		 * : Preview only. Default mode when --write is not passed.
		 *
		 * [--write]
		 * : Persist changes.
		 *
		 * [--format=<format>]
		 * : table|json. Default: table.
		 */
		public function migrate_spot_overrides(array $args, array $assoc_args): void {
			$dry_run = ! isset($assoc_args['write']);
			if (isset($assoc_args['dry-run'])) {
				$dry_run = true;
			}
			$only_unset = ! isset($assoc_args['include-set-businesses']);
			if (isset($assoc_args['only-unset-businesses'])) {
				$only_unset = true;
			}
			$respect_source = isset($assoc_args['respect-plan-source']);
			$limit = isset($assoc_args['limit']) ? max(0, absint((int) $assoc_args['limit'])) : 0;
			$format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
			if (! in_array($format, array('table', 'json'), true)) {
				$format = 'table';
			}

			$result = DDB_Spots_Business_Plan_Manager::migrate_from_spot_overrides(
				array(
					'dry_run' => $dry_run,
					'only_unset_businesses' => $only_unset,
					'respect_plan_source' => $respect_source,
					'limit' => $limit,
				)
			);

			if ('json' === $format) {
				WP_CLI::line((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			} else {
				$rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
				if (empty($rows)) {
					WP_CLI::line('No migratable spot override plan data found.');
				} else {
					WP_CLI\Utils\format_items('table', $rows, array('business_id', 'business_title', 'from_plan', 'from_status', 'to_plan', 'to_status', 'to_period_end', 'source_spots'));
				}
			}
			WP_CLI::success(
				sprintf(
					'Scanned=%d, Updated=%d, Skipped=%d%s',
					(int) ($result['scanned'] ?? 0),
					(int) ($result['updated'] ?? 0),
					(int) ($result['skipped'] ?? 0),
					$dry_run ? ' (dry-run)' : ''
				)
			);
		}
	}

	WP_CLI::add_command('ddb-spots businesses', 'DDB_Spots_Business_CLI_Command');
}
