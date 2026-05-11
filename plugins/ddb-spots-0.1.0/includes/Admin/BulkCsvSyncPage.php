<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Admin_Bulk_Csv_Sync_Page {
	private const PAGE_SLUG = 'ddb-spots-bulk-csv-sync';
	private const EXPORT_ACTION = 'ddb_spots_bulk_csv_export';
	private const EXPORT_NONCE_ACTION = 'ddb_spots_bulk_csv_export_nonce';
	private const IMPORT_NONCE_ACTION = 'ddb_spots_bulk_csv_import_nonce';
	private const POST_TYPE = 'ddb_spot';
	private const MAX_ROWS = 5000;
	private const MAX_REPORT_ROWS = 250;
	private const EXPORT_DELIMITER = ';';
	private const STATUS_VALUES = array('inactive', 'trial', 'active', 'past_due', 'canceled');
	private const PLAN_SOURCE_VALUES = array('spot', 'business');

	/**
	 * @var array<string,string>
	 */
	private const META_COLUMN_MAP = array(
		'address' => '_ddb_address',
		'city' => '_ddb_city',
		'region' => '_ddb_region',
		'country' => '_ddb_country',
		'google_website' => '_ddb_google_website',
		'google_phone' => '_ddb_google_phone',
		'cta_url' => '_ddb_cta_url',
		'generic_cta_url' => '_ddb_spot_cta_url',
		'restaurant_booking_url' => '_ddb_restaurant_booking_url',
		'event_ticket_url' => '_ddb_event_ticket_url',
		'hotel_booking_url' => '_ddb_hotel_booking_url',
		'informational_only' => '_ddb_informational_only',
		'business_id' => '_ddb_business_id',
		'plan_source' => '_ddb_premium_plan_source',
		'premium_plan_key' => '_ddb_premium_plan_key',
		'premium_status' => '_ddb_premium_status',
		'premium_period_end' => '_ddb_premium_period_end',
		'premium_top_pick' => '_ddb_premium_top_pick',
		'premium_module_cta_variant' => '_ddb_premium_module_cta_variant',
		'premium_module_highlight_badge' => '_ddb_premium_module_highlight_badge',
		'premium_module_lead_form' => '_ddb_premium_module_lead_form',
	);

	public function init(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_init', array($this, 'handle_export_request'));
	}

	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			__('Bulk CSV Sync', 'ddb-spots'),
			__('Bulk CSV Sync', 'ddb-spots'),
			DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE,
			self::PAGE_SLUG,
			array($this, 'render_page')
		);
	}

	public function render_page(): void {
		if (! current_user_can(DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE)) {
			wp_die(esc_html__('Insufficient permissions.', 'ddb-spots'));
		}

		$report = null;
		$mode = '';
		if (isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['ddb_spots_csv_import_submit'])) {
			check_admin_referer(self::IMPORT_NONCE_ACTION);
			$mode = isset($_POST['ddb_import_mode']) ? sanitize_key((string) wp_unslash($_POST['ddb_import_mode'])) : 'dry_run';
			$commit = 'commit' === $mode;
			$report = $this->handle_import_upload($commit);
		}

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'post_type' => self::POST_TYPE,
					'page' => self::PAGE_SLUG,
					'action' => self::EXPORT_ACTION,
				),
				admin_url('edit.php')
			),
			self::EXPORT_NONCE_ACTION
		);

		echo '<div class="wrap ddb-admin-ui ddb-admin-ui-wrap">';
		echo '<h1>' . esc_html__('DDB Spots Bulk CSV Sync', 'ddb-spots') . '</h1>';
		echo '<p>' . esc_html__('Werk alle spots tegelijk bij via Excel/CSV. Gebruik altijd de kolom spot_id als vaste sleutel.', 'ddb-spots') . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url($export_url) . '">' . esc_html__('Export CSV', 'ddb-spots') . '</a></p>';

		echo '<hr />';
		echo '<h2>' . esc_html__('Import CSV', 'ddb-spots') . '</h2>';
		echo '<p>' . esc_html__('Stap 1: start met dry-run. Stap 2: commit pas als het rapport klopt.', 'ddb-spots') . '</p>';
		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field(self::IMPORT_NONCE_ACTION);
		echo '<p><input type="file" name="ddb_spots_csv_file" accept=".csv,text/csv" required /></p>';
		echo '<p>';
		echo '<label><input type="radio" name="ddb_import_mode" value="dry_run" ' . checked('commit' !== $mode, true, false) . ' /> ' . esc_html__('Dry-run (geen wijzigingen)', 'ddb-spots') . '</label><br />';
		echo '<label><input type="radio" name="ddb_import_mode" value="commit" ' . checked('commit' === $mode, true, false) . ' /> ' . esc_html__('Commit (wijzigingen opslaan)', 'ddb-spots') . '</label>';
		echo '</p>';
		echo '<p><button class="button button-secondary" type="submit" name="ddb_spots_csv_import_submit" value="1">' . esc_html__('Run Import', 'ddb-spots') . '</button></p>';
		echo '</form>';

		echo '<h3>' . esc_html__('Ondersteunde CSV kolommen', 'ddb-spots') . '</h3>';
		echo '<p><code>spot_id,post_title,post_excerpt,post_content,address,city,region,country,google_website,google_phone,cta_url,generic_cta_url,restaurant_booking_url,event_ticket_url,hotel_booking_url,informational_only,business_id,plan_source,premium_plan_key,premium_status,premium_period_end,premium_top_pick,premium_module_cta_variant,premium_module_highlight_badge,premium_module_lead_form</code></p>';
		echo '<p class="description">' . esc_html__('Extra exportkolommen: business_title, effective_plan_key, effective_plan_status, permalink. Import accepteert zowel komma als puntkomma delimiter.', 'ddb-spots') . '</p>';

		if (is_array($report)) {
			$this->render_import_report($report);
		}
		echo '</div>';
	}

	public function handle_export_request(): void {
		if (! is_admin()) {
			return;
		}
		$page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
		$action = isset($_GET['action']) ? sanitize_key((string) wp_unslash($_GET['action'])) : '';
		if (self::PAGE_SLUG !== $page || self::EXPORT_ACTION !== $action) {
			return;
		}
		if (! current_user_can(DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE)) {
			wp_die(esc_html__('Insufficient permissions.', 'ddb-spots'));
		}
		check_admin_referer(self::EXPORT_NONCE_ACTION);
		$this->stream_export_csv();
		exit;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function handle_import_upload(bool $commit): array {
		if (! isset($_FILES['ddb_spots_csv_file']) || ! is_array($_FILES['ddb_spots_csv_file'])) {
			return array(
				'ok' => false,
				'message' => __('Geen CSV bestand ontvangen.', 'ddb-spots'),
			);
		}

		$file = $_FILES['ddb_spots_csv_file'];
		$error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if (UPLOAD_ERR_OK !== $error) {
			return array(
				'ok' => false,
				'message' => sprintf(__('Upload foutcode: %d', 'ddb-spots'), $error),
			);
		}

		$tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
		if ('' === $tmp || ! is_uploaded_file($tmp)) {
			return array(
				'ok' => false,
				'message' => __('Upload bestand is ongeldig.', 'ddb-spots'),
			);
		}

		return $this->process_csv_file($tmp, $commit);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function process_csv_file(string $path, bool $commit): array {
		$delimiter = $this->detect_csv_delimiter($path);
		$fh = fopen($path, 'r');
		if (false === $fh) {
			return array(
				'ok' => false,
				'message' => __('CSV kon niet gelezen worden.', 'ddb-spots'),
			);
		}

		$raw_header = fgetcsv($fh, 0, $delimiter);
		if (! is_array($raw_header) || empty($raw_header)) {
			fclose($fh);
			return array(
				'ok' => false,
				'message' => __('CSV mist header rij.', 'ddb-spots'),
			);
		}

		$header_map = $this->build_header_index($raw_header);
		if (! isset($header_map['spot_id'])) {
			fclose($fh);
			return array(
				'ok' => false,
				'message' => __('CSV moet een spot_id kolom bevatten.', 'ddb-spots'),
			);
		}

		$rows = 0;
		$processed = 0;
		$updated = 0;
		$skipped = 0;
		$errors = 0;
		$report_rows = array();
		$limit_hit = false;

		while (($csv_row = fgetcsv($fh, 0, $delimiter)) !== false) {
			$rows++;
			if ($rows > self::MAX_ROWS) {
				$limit_hit = true;
				break;
			}
			if (! is_array($csv_row) || empty($csv_row)) {
				continue;
			}

			$row_assoc = $this->row_to_assoc($header_map, $csv_row);
			$spot_id = isset($row_assoc['spot_id']) ? absint((int) $row_assoc['spot_id']) : 0;
			if ($spot_id <= 0) {
				$errors++;
				$this->push_report_row($report_rows, array('row' => $rows + 1, 'spot_id' => 0, 'status' => 'error', 'message' => __('spot_id ontbreekt of ongeldig.', 'ddb-spots')));
				continue;
			}

			$post = get_post($spot_id);
			if (! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type) {
				$errors++;
				$this->push_report_row($report_rows, array('row' => $rows + 1, 'spot_id' => $spot_id, 'status' => 'error', 'message' => __('Spot niet gevonden.', 'ddb-spots')));
				continue;
			}
			if (! current_user_can('edit_post', $spot_id)) {
				$errors++;
				$this->push_report_row($report_rows, array('row' => $rows + 1, 'spot_id' => $spot_id, 'status' => 'error', 'message' => __('Geen rechten voor deze spot.', 'ddb-spots')));
				continue;
			}

			$changes = $this->detect_changes($post, $row_assoc);
			if (empty($changes['post']) && empty($changes['meta'])) {
				$skipped++;
				$this->push_report_row($report_rows, array('row' => $rows + 1, 'spot_id' => $spot_id, 'status' => 'skip', 'message' => __('Geen wijzigingen.', 'ddb-spots')));
				continue;
			}
			$validation_error = $this->validate_row_changes($spot_id, $changes);
			if ('' !== $validation_error) {
				$errors++;
				$this->push_report_row($report_rows, array('row' => $rows + 1, 'spot_id' => $spot_id, 'status' => 'error', 'message' => $validation_error));
				continue;
			}

			$processed++;
			if (! $commit) {
				$this->push_report_row(
					$report_rows,
					array(
						'row' => $rows + 1,
						'spot_id' => $spot_id,
						'status' => 'dry_run',
						'message' => sprintf(
							/* translators: 1: number of post field changes, 2: number of meta changes */
							__('Zou updaten: post velden %1$d, meta velden %2$d', 'ddb-spots'),
							count($changes['post']),
							count($changes['meta'])
						),
					)
				);
				continue;
			}

			$ok = $this->apply_changes($spot_id, $changes);
			if ($ok) {
				$updated++;
				$this->push_report_row($report_rows, array('row' => $rows + 1, 'spot_id' => $spot_id, 'status' => 'updated', 'message' => __('Spot bijgewerkt.', 'ddb-spots')));
			} else {
				$errors++;
				$this->push_report_row($report_rows, array('row' => $rows + 1, 'spot_id' => $spot_id, 'status' => 'error', 'message' => __('Update mislukt.', 'ddb-spots')));
			}
		}
		fclose($fh);

		return array(
			'ok' => true,
			'mode' => $commit ? 'commit' : 'dry_run',
			'rows_read' => $rows,
			'processed' => $processed,
			'updated' => $updated,
			'skipped' => $skipped,
			'errors' => $errors,
			'limit_hit' => $limit_hit,
			'report_rows' => $report_rows,
		);
	}

	/**
	 * @param array<int,string> $raw_header
	 * @return array<string,int>
	 */
	private function build_header_index(array $raw_header): array {
		$map = array();
		foreach ($raw_header as $index => $label_raw) {
			$key = $this->normalize_header_label((string) $label_raw);
			if ('' === $key || isset($map[ $key ])) {
				continue;
			}
			$map[ $key ] = (int) $index;
		}
		return $map;
	}

	private function normalize_header_label(string $label): string {
		$label = preg_replace('/^\xEF\xBB\xBF/', '', $label);
		$label = strtolower(trim((string) $label));
		$label = str_replace(array('-', ' '), '_', $label);
		$aliases = array(
			'id' => 'spot_id',
			'post_id' => 'spot_id',
			'title' => 'post_title',
			'excerpt' => 'post_excerpt',
			'summary' => 'post_excerpt',
			'content' => 'post_content',
			'plan_key' => 'premium_plan_key',
			'status' => 'premium_status',
			'period_end' => 'premium_period_end',
		);
		return $aliases[ $label ] ?? $label;
	}

	/**
	 * @param array<string,int> $header_map
	 * @param array<int,string> $csv_row
	 * @return array<string,string>
	 */
	private function row_to_assoc(array $header_map, array $csv_row): array {
		$out = array();
		foreach ($header_map as $key => $idx) {
			$out[ $key ] = isset($csv_row[ $idx ]) ? (string) $csv_row[ $idx ] : '';
		}
		return $out;
	}

	/**
	 * @param array<string,string> $row
	 * @return array<string,array<string,string>>
	 */
	private function detect_changes(WP_Post $post, array $row): array {
		$post_changes = array();
		$meta_changes = array();

		foreach (array('post_title', 'post_excerpt', 'post_content') as $field) {
			if (! array_key_exists($field, $row)) {
				continue;
			}
			$new_value = (string) $row[ $field ];
			$old_value = (string) $post->{$field};
			if ($new_value !== $old_value) {
				$post_changes[ $field ] = $new_value;
			}
		}

		foreach (self::META_COLUMN_MAP as $column => $meta_key) {
			if (! array_key_exists($column, $row)) {
				continue;
			}
			$new_raw = (string) $row[ $column ];
			$new_value = $this->sanitize_import_meta_value($column, $new_raw);
			$old_value = (string) get_post_meta((int) $post->ID, $meta_key, true);
			if ($new_value !== $old_value) {
				$meta_changes[ $meta_key ] = $new_value;
			}
		}

		return array(
			'post' => $post_changes,
			'meta' => $meta_changes,
		);
	}

	private function sanitize_import_meta_value(string $column, string $value): string {
		if ($this->is_boolean_column($column)) {
			return $this->normalize_boolean($value);
		}
		if ('business_id' === $column) {
			return (string) absint((int) $value);
		}
		if ('plan_source' === $column) {
			return $this->normalize_plan_source($value);
		}
		if ('premium_plan_key' === $column) {
			return $this->normalize_plan_key($value);
		}
		if ('premium_status' === $column) {
			return $this->normalize_plan_status($value);
		}
		if ('premium_period_end' === $column) {
			return $this->sanitize_period_end($value);
		}
		if (str_contains($column, 'url') || 'google_website' === $column) {
			return esc_url_raw($value);
		}
		return sanitize_text_field($value);
	}

	private function detect_csv_delimiter(string $path): string {
		$line = '';
		$fh = fopen($path, 'r');
		if (false !== $fh) {
			$line = (string) fgets($fh);
			fclose($fh);
		}
		if ('' === $line) {
			return ',';
		}
		$delimiters = array(';', ',', "\t", '|');
		$best = ',';
		$best_count = -1;
		foreach ($delimiters as $delimiter) {
			$count = substr_count($line, $delimiter);
			if ($count > $best_count) {
				$best_count = $count;
				$best = $delimiter;
			}
		}
		return $best;
	}

	private function is_boolean_column(string $column): bool {
		return in_array(
			$column,
			array(
				'informational_only',
				'premium_top_pick',
				'premium_module_cta_variant',
				'premium_module_highlight_badge',
				'premium_module_lead_form',
			),
			true
		);
	}

	private function normalize_boolean(string $value): string {
		$norm = strtolower(trim($value));
		return in_array($norm, array('1', 'true', 'yes', 'ja', 'on'), true) ? '1' : '0';
	}

	private function normalize_plan_source(string $value, bool $has_business = false): string {
		$value = sanitize_key(trim($value));
		if (in_array($value, self::PLAN_SOURCE_VALUES, true)) {
			return $value;
		}
		return $has_business ? 'business' : 'spot';
	}

	private function normalize_plan_key(string $value): string {
		if (function_exists('ddb_spots_normalize_plan_key')) {
			return ddb_spots_normalize_plan_key(sanitize_key(trim($value)));
		}
		$value = sanitize_key(trim($value));
		return in_array($value, array('free', 'presence', 'conversion', 'partner'), true) ? $value : 'free';
	}

	private function normalize_plan_status(string $value): string {
		$value = sanitize_key(trim($value));
		return in_array($value, self::STATUS_VALUES, true) ? $value : 'inactive';
	}

	private function sanitize_period_end(string $value): string {
		$value = trim(sanitize_text_field($value));
		if ('' === $value) {
			return '';
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return $value;
		}
		$ts = strtotime($value);
		if (false === $ts) {
			return '';
		}
		return gmdate('Y-m-d', $ts);
	}

	/**
	 * @param array<string,array<string,string>> $changes
	 */
	private function validate_row_changes(int $spot_id, array $changes): string {
		$meta = isset($changes['meta']) && is_array($changes['meta']) ? $changes['meta'] : array();
		if (empty($meta)) {
			return '';
		}

		$business_key = self::META_COLUMN_MAP['business_id'];
		$plan_source_key = self::META_COLUMN_MAP['plan_source'];
		$plan_key_key = self::META_COLUMN_MAP['premium_plan_key'];
		$top_pick_key = self::META_COLUMN_MAP['premium_top_pick'];
		$module_map = array(
			self::META_COLUMN_MAP['premium_module_cta_variant'] => 'cta_variant',
			self::META_COLUMN_MAP['premium_module_highlight_badge'] => 'highlight_badge',
			self::META_COLUMN_MAP['premium_module_lead_form'] => 'lead_form',
		);

		$target_business_id = array_key_exists($business_key, $meta)
			? absint((int) $meta[ $business_key ])
			: absint((int) get_post_meta($spot_id, $business_key, true));
		if ($target_business_id > 0 && class_exists('DDB_Spots_Business_Registry') && ! DDB_Spots_Business_Registry::is_valid_business_id($target_business_id)) {
			return __('Ongeldige business_id voor deze spot.', 'ddb-spots');
		}

		$target_plan_source = array_key_exists($plan_source_key, $meta)
			? $this->normalize_plan_source((string) $meta[ $plan_source_key ], $target_business_id > 0)
			: $this->normalize_plan_source((string) get_post_meta($spot_id, $plan_source_key, true), $target_business_id > 0);
		if ('business' === $target_plan_source && $target_business_id <= 0) {
			return __('Plan source=business vereist een geldige business_id.', 'ddb-spots');
		}

		$target_override_plan = array_key_exists($plan_key_key, $meta)
			? $this->normalize_plan_key((string) $meta[ $plan_key_key ])
			: $this->normalize_plan_key((string) get_post_meta($spot_id, $plan_key_key, true));

		$effective_plan_key = $target_override_plan;
		if ('business' === $target_plan_source && $target_business_id > 0 && class_exists('DDB_Spots_Business_Registry')) {
			$business_info = DDB_Spots_Business_Registry::get_business_plan_info($target_business_id);
			if ($target_business_id === (int) ($business_info['business_id'] ?? 0)) {
				$effective_plan_key = $this->normalize_plan_key((string) ($business_info['plan_key'] ?? 'free'));
			}
		}
		$entitlements = function_exists('ddb_spots_plan_entitlements') ? ddb_spots_plan_entitlements($effective_plan_key) : array();
		$allowed_modules = array_values(array_filter(array_map('sanitize_key', (array) ($entitlements['modules'] ?? array()))));
		$can_top_picks = ! empty($entitlements['top_picks']);

		foreach ($module_map as $meta_key => $module_key) {
			if (! array_key_exists($meta_key, $meta)) {
				continue;
			}
			if ('1' === (string) $meta[ $meta_key ] && ! in_array($module_key, $allowed_modules, true)) {
				return sprintf(
					/* translators: %s: module key */
					__('Module "%s" niet toegestaan voor effectief plan.', 'ddb-spots'),
					$module_key
				);
			}
		}

		if (array_key_exists($top_pick_key, $meta) && '1' === (string) $meta[ $top_pick_key ]) {
			if (! $can_top_picks) {
				return __('Top Pick alleen toegestaan op Partner entitlement.', 'ddb-spots');
			}
			$current_top_pick = '1' === (string) get_post_meta($spot_id, $top_pick_key, true);
			$slot = function_exists('ddb_spots_top_pick_context_for_spot') ? ddb_spots_top_pick_context_for_spot($spot_id) : array();
			$slot_available = ! empty($slot['slot_available']);
			if (! $current_top_pick && ! $slot_available) {
				return __('Geen vrij Top Pick slot voor category/area.', 'ddb-spots');
			}
		}

		return '';
	}

	/**
	 * @param array<string,array<string,string>> $changes
	 */
	private function apply_changes(int $spot_id, array $changes): bool {
		$ok = true;
		$post_changes = isset($changes['post']) && is_array($changes['post']) ? $changes['post'] : array();
		$meta_changes = isset($changes['meta']) && is_array($changes['meta']) ? $changes['meta'] : array();

		if (! empty($post_changes)) {
			$post_data = array_merge(array('ID' => $spot_id), $post_changes);
			$result = wp_update_post($post_data, true);
			if (is_wp_error($result)) {
				$ok = false;
			}
		}

		foreach ($meta_changes as $meta_key => $meta_value) {
			if (! update_post_meta($spot_id, (string) $meta_key, (string) $meta_value) && (string) get_post_meta($spot_id, (string) $meta_key, true) !== (string) $meta_value) {
				$ok = false;
			}
		}

		if ($ok) {
			DDB_Spots::invalidate_cache();
		}
		return $ok;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,mixed> $row
	 */
	private function push_report_row(array &$rows, array $row): void {
		if (count($rows) >= self::MAX_REPORT_ROWS) {
			return;
		}
		$rows[] = $row;
	}

	private function render_import_report(array $report): void {
		$ok = ! empty($report['ok']);
		$message = (string) ($report['message'] ?? '');
		if (! $ok && '' !== $message) {
			echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
			return;
		}

		$mode = (string) ($report['mode'] ?? 'dry_run');
		$summary = sprintf(
			/* translators: 1: rows read, 2: processed, 3: updated, 4: skipped, 5: errors */
			__('Rijen gelezen: %1$d | verwerkt: %2$d | updates: %3$d | overgeslagen: %4$d | errors: %5$d', 'ddb-spots'),
			(int) ($report['rows_read'] ?? 0),
			(int) ($report['processed'] ?? 0),
			(int) ($report['updated'] ?? 0),
			(int) ($report['skipped'] ?? 0),
			(int) ($report['errors'] ?? 0)
		);
		echo '<div class="notice notice-' . ('commit' === $mode ? 'success' : 'info') . '"><p><strong>' . esc_html(strtoupper($mode)) . '</strong> - ' . esc_html($summary) . '</p></div>';
		if (! empty($report['limit_hit'])) {
			echo '<div class="notice notice-warning"><p>' . esc_html(sprintf(__('Import gestopt na %d rijen (veiligheidslimiet).', 'ddb-spots'), self::MAX_ROWS)) . '</p></div>';
		}

		$rows = isset($report['report_rows']) && is_array($report['report_rows']) ? $report['report_rows'] : array();
		if (empty($rows)) {
			return;
		}

		echo '<h3>' . esc_html__('Rapport (eerste regels)', 'ddb-spots') . '</h3>';
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>spot_id</th><th>status</th><th>message</th></tr></thead><tbody>';
		foreach ($rows as $row) {
			echo '<tr>';
			echo '<td>' . esc_html((string) ($row['row'] ?? '')) . '</td>';
			echo '<td>' . esc_html((string) ($row['spot_id'] ?? '')) . '</td>';
			echo '<td>' . esc_html((string) ($row['status'] ?? '')) . '</td>';
			echo '<td>' . esc_html((string) ($row['message'] ?? '')) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function stream_export_csv(): void {
		$filename = 'ddb-spots-export-' . gmdate('Ymd-His') . '.csv';
		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=' . $filename);

		$out = fopen('php://output', 'w');
		if (false === $out) {
			return;
		}

		// UTF-8 BOM for Excel compatibility.
		fwrite($out, "\xEF\xBB\xBF");

		$columns = array(
			'spot_id',
			'post_title',
			'post_excerpt',
			'post_content',
			'address',
			'city',
			'region',
			'country',
			'google_website',
			'google_phone',
			'cta_url',
			'generic_cta_url',
			'restaurant_booking_url',
			'event_ticket_url',
			'hotel_booking_url',
			'informational_only',
			'business_id',
			'business_title',
			'plan_source',
			'premium_plan_key',
			'premium_status',
			'premium_period_end',
			'effective_plan_key',
			'effective_plan_status',
			'premium_top_pick',
			'premium_module_cta_variant',
			'premium_module_highlight_badge',
			'premium_module_lead_form',
			'permalink',
		);
		fputcsv($out, $columns, self::EXPORT_DELIMITER);

		$ids = get_posts(
			array(
				'post_type' => self::POST_TYPE,
				'post_status' => array('publish', 'draft', 'pending', 'private'),
				'fields' => 'ids',
				'posts_per_page' => -1,
				'orderby' => 'ID',
				'order' => 'ASC',
				'no_found_rows' => true,
			)
		);
		foreach ((array) $ids as $spot_id_raw) {
			$spot_id = absint((int) $spot_id_raw);
			if ($spot_id <= 0) {
				continue;
			}
			$post = get_post($spot_id);
			if (! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type) {
				continue;
			}
			$business_id = absint((int) get_post_meta($spot_id, '_ddb_business_id', true));
			$plan_source = $this->normalize_plan_source((string) get_post_meta($spot_id, '_ddb_premium_plan_source', true), $business_id > 0);
			$plan = class_exists('DDB_Spots_Premium_Engine') ? DDB_Spots_Premium_Engine::get_spot_plan_info($spot_id) : array();
			$row = array(
				$spot_id,
				(string) $post->post_title,
				(string) $post->post_excerpt,
				(string) $post->post_content,
				(string) get_post_meta($spot_id, '_ddb_address', true),
				(string) get_post_meta($spot_id, '_ddb_city', true),
				(string) get_post_meta($spot_id, '_ddb_region', true),
				(string) get_post_meta($spot_id, '_ddb_country', true),
				(string) get_post_meta($spot_id, '_ddb_google_website', true),
				(string) get_post_meta($spot_id, '_ddb_google_phone', true),
				(string) get_post_meta($spot_id, '_ddb_cta_url', true),
				(string) get_post_meta($spot_id, '_ddb_spot_cta_url', true),
				(string) get_post_meta($spot_id, '_ddb_restaurant_booking_url', true),
				(string) get_post_meta($spot_id, '_ddb_event_ticket_url', true),
				(string) get_post_meta($spot_id, '_ddb_hotel_booking_url', true),
				(string) get_post_meta($spot_id, '_ddb_informational_only', true),
				(string) $business_id,
				$business_id > 0 ? (string) get_the_title($business_id) : '',
				$plan_source,
				(string) get_post_meta($spot_id, '_ddb_premium_plan_key', true),
				(string) get_post_meta($spot_id, '_ddb_premium_status', true),
				(string) get_post_meta($spot_id, '_ddb_premium_period_end', true),
				(string) ($plan['plan_key'] ?? 'free'),
				(string) ($plan['status'] ?? 'inactive'),
				(string) get_post_meta($spot_id, '_ddb_premium_top_pick', true),
				(string) get_post_meta($spot_id, '_ddb_premium_module_cta_variant', true),
				(string) get_post_meta($spot_id, '_ddb_premium_module_highlight_badge', true),
				(string) get_post_meta($spot_id, '_ddb_premium_module_lead_form', true),
				(string) get_permalink($spot_id),
			);
			fputcsv($out, $row, self::EXPORT_DELIMITER);
		}
		fclose($out);
	}
}
