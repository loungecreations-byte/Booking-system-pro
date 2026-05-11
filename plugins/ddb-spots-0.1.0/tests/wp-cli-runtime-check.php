<?php
if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run this with: wp eval-file tests/wp-cli-runtime-check.php\n");
	exit(1);
}

function ddb_runtime_assert(bool $condition, string $message): void {
	if (! $condition) {
		throw new RuntimeException($message);
	}
}

try {
	$original_config = DDB_Spots_Admin_Settings_Page::get_config();
	$runtime_boot_config = DDB_Spots_Admin_Settings_Page::get_config();
	$runtime_boot_config['integrations']['legacy_rest_enabled'] = true;
	update_option(DDB_Spots_Admin_Settings_Page::OPTION_KEY, $runtime_boot_config, false);

	global $wpdb;
	$spots_table = $wpdb->prefix . 'dbspots_spots';
	$events_table = $wpdb->prefix . 'dbspots_events';
	$audit_table = $wpdb->prefix . 'dbspots_audit';
	ddb_runtime_assert($spots_table === (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $spots_table)), 'dbspots_spots table missing.');
	ddb_runtime_assert($events_table === (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $events_table)), 'dbspots_events table missing.');
	ddb_runtime_assert($audit_table === (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $audit_table)), 'dbspots_audit table missing.');
	echo "PASS: db schema\n";

	do_action('rest_api_init');
	$request = new WP_REST_Request('GET', '/ddb/v1/spots');
	$request->set_param('per_page', 2);
	$request->set_param('type', 'restaurant');
	$request->set_param('lat', '51.6889');
	$request->set_param('lng', '5.3030');
	$response = rest_do_request($request);
	if ($response->is_error()) {
		throw new RuntimeException('REST route error: ' . $response->as_error()->get_error_message());
	}
	$data = $response->get_data();
	ddb_runtime_assert(is_array($data), 'REST response is not an array.');
	ddb_runtime_assert(isset($data['items']) && is_array($data['items']), 'REST response has no items array.');
	ddb_runtime_assert(isset($data['pagination']) && is_array($data['pagination']), 'REST response has no pagination array.');
	echo "PASS: REST ranking route\n";

	$cron_schedules = wp_get_schedules();
	ddb_runtime_assert(isset($cron_schedules['every_3_days']), 'Cron schedule every_3_days is missing.');
	DDB_Spots_Cron_Sync_Service::activate();
	$scheduled = wp_next_scheduled(DDB_Spots_Cron_Sync_Service::HOOK);
	ddb_runtime_assert(false !== $scheduled, 'Cron hook is not scheduled after activation.');
	echo "PASS: cron schedule/runtime\n";

	$config = DDB_Spots_Admin_Settings_Page::get_config();
	$config['ux_rules']['block_publish_on_critical'] = true;
	$config['ux_rules']['hero_image_required'] = true;
	$config['integrations']['public_ingest_enabled'] = true;
	$config['integrations']['ingestion_shared_key'] = 'runtime-ingest-key';
	update_option(DDB_Spots_Admin_Settings_Page::OPTION_KEY, $config, false);

	$post_id = wp_insert_post(
		array(
			'post_type' => 'ddb_spot',
			'post_title' => 'Publish gate runtime test',
			'post_status' => 'draft',
		),
		true
	);
	if (is_wp_error($post_id)) {
		throw new RuntimeException('Could not create runtime gate post: ' . $post_id->get_error_message());
	}

	$_POST['post_status'] = 'publish';
	wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'));
	$status = get_post_status($post_id);
	ddb_runtime_assert('draft' === $status, 'Publish gate did not force draft status.');
	echo "PASS: publish gate runtime\n";

	$admin_user_id = 0;
	$admin_users = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => array('ID')));
	if (! empty($admin_users) && isset($admin_users[0]->ID)) {
		$admin_user_id = (int) $admin_users[0]->ID;
		wp_set_current_user($admin_user_id);
	}

	update_post_meta((int) $post_id, '_ddb_lat', '51.6889');
	update_post_meta((int) $post_id, '_ddb_lng', '5.3030');
	$debug_request = new WP_REST_Request('GET', '/ddb/v1/spots/' . (int) $post_id . '/ranking-debug');
	$debug_request->set_param('lat', '51.6889');
	$debug_request->set_param('lng', '5.3030');
	$debug_response = rest_do_request($debug_request);
	ddb_runtime_assert(! $debug_response->is_error(), 'Ranking debug endpoint returned error.');
	$debug_data = $debug_response->get_data();
	ddb_runtime_assert(isset($debug_data['components']) && is_array($debug_data['components']), 'Ranking debug has no components.');
	ddb_runtime_assert(isset($debug_data['final_score']), 'Ranking debug has no final_score.');
	echo "PASS: ranking debug route\n";

	$dbspots_list = new WP_REST_Request('GET', '/dbspots/v1/spots');
	$dbspots_list->set_param('per_page', 5);
	$dbspots_list_response = rest_do_request($dbspots_list);
	ddb_runtime_assert(! $dbspots_list_response->is_error(), 'dbspots list endpoint returned error.');
	$dbspots_data = $dbspots_list_response->get_data();
	ddb_runtime_assert(isset($dbspots_data['items']) && is_array($dbspots_data['items']), 'dbspots list has no items.');
	echo "PASS: dbspots list route\n";

	$dbspots_event = new WP_REST_Request('POST', '/dbspots/v1/events');
	$dbspots_event->set_body(wp_json_encode(array('event_type' => 'spot_view', 'spot_id' => (int) $post_id, 'source' => 'runtime_test', 'context' => array('scenario' => 'runtime'))));
	$dbspots_event->set_header('content-type', 'application/json');
	$dbspots_event->set_header('x-ddb-ingest-key', 'runtime-ingest-key');
	$dbspots_event_response = rest_do_request($dbspots_event);
	ddb_runtime_assert(! $dbspots_event_response->is_error(), 'dbspots event endpoint returned error.');
	echo "PASS: dbspots events route\n";

	$dbspots_suggest = new WP_REST_Request('POST', '/dbspots/v1/suggest');
	$dbspots_suggest->set_body(wp_json_encode(array('intent' => 'restaurant', 'area' => '', 'duration' => 120)));
	$dbspots_suggest->set_header('content-type', 'application/json');
	$dbspots_suggest->set_header('x-ddb-ingest-key', 'runtime-ingest-key');
	$dbspots_suggest_response = rest_do_request($dbspots_suggest);
	ddb_runtime_assert(! $dbspots_suggest_response->is_error(), 'dbspots suggest endpoint returned error.');
	$suggest_data = $dbspots_suggest_response->get_data();
	ddb_runtime_assert(isset($suggest_data['alternatives']) && is_array($suggest_data['alternatives']), 'dbspots suggest has no alternatives key.');
	echo "PASS: dbspots suggest route\n";

	$create_request = new WP_REST_Request('POST', '/dbspots/v1/spots');
	$create_request->set_body(
		wp_json_encode(
			array(
				'name' => 'API Runtime Spot',
				'type' => 'restaurant',
				'area' => 'centrum',
				'short_desc' => 'Dit is een runtime API test spot met extra lange samenvatting zodat de strengere kwaliteitsregel voor minimaal 140 tekens altijd wordt gehaald tijdens publish-validatie in de engine.',
				'long_desc' => 'Lange beschrijving voor runtime validatie met voldoende context over openingstijden, doelgroep, sfeer, praktische tips en combinatie met andere activiteiten in de stad.',
				'primary_cta_value' => 'https://example.com/cta',
				'primary_cta_type' => 'external',
				'duration_hint' => 90,
				'lat' => '51.6889',
				'lng' => '5.3030',
			)
		)
	);
	$create_request->set_header('content-type', 'application/json');
	$create_response = rest_do_request($create_request);
	ddb_runtime_assert(! $create_response->is_error(), 'dbspots create endpoint returned error.');
	$created_data = $create_response->get_data();
	$created_id = isset($created_data['id']) ? (int) $created_data['id'] : 0;
	$created_post_id = isset($created_data['spot_post_id']) ? (int) $created_data['spot_post_id'] : 0;
	ddb_runtime_assert($created_id > 0 && $created_post_id > 0, 'dbspots create returned invalid ids.');
	echo "PASS: dbspots create route\n";

	wp_set_current_user(0);
	$public_draft_read = new WP_REST_Request('GET', '/dbspots/v1/spots/' . $created_id);
	$public_draft_response = rest_do_request($public_draft_read);
	ddb_runtime_assert($public_draft_response->is_error(), 'Public draft spot read should be blocked.');
	if ($admin_user_id > 0) {
		wp_set_current_user($admin_user_id);
	}
	echo "PASS: dbspots draft privacy route\n";

	$publish_request = new WP_REST_Request('POST', '/dbspots/v1/publish');
	$publish_request->set_param('id', $created_id);
	$publish_response = rest_do_request($publish_request);
	ddb_runtime_assert($publish_response->is_error(), 'dbspots publish should be blocked when hero image is required.');
	echo "PASS: dbspots publish gate block\n";

	$config_after = DDB_Spots_Admin_Settings_Page::get_config();
	$config_after['ux_rules']['hero_image_required'] = false;
	update_option(DDB_Spots_Admin_Settings_Page::OPTION_KEY, $config_after, false);

	$publish_request_retry = new WP_REST_Request('POST', '/dbspots/v1/publish');
	$publish_request_retry->set_param('id', $created_id);
	$publish_response_retry = rest_do_request($publish_request_retry);
	ddb_runtime_assert(! $publish_response_retry->is_error(), 'dbspots publish endpoint returned error after relaxing hero image rule.');
	$publish_data = $publish_response_retry->get_data();
	ddb_runtime_assert(isset($publish_data['status']) && 'publish' === (string) $publish_data['status'], 'dbspots publish did not set publish status.');
	echo "PASS: dbspots publish route\n";

	$archive_request = new WP_REST_Request('POST', '/dbspots/v1/archive');
	$archive_request->set_param('id', $created_id);
	$archive_response = rest_do_request($archive_request);
	ddb_runtime_assert(! $archive_response->is_error(), 'dbspots archive endpoint returned error.');
	$archive_data = $archive_response->get_data();
	ddb_runtime_assert(isset($archive_data['status']) && 'draft' === (string) $archive_data['status'], 'dbspots archive did not set draft status.');
	echo "PASS: dbspots archive route\n";

	wp_delete_post($created_post_id, true);

	wp_delete_post($post_id, true);
	echo "RUNTIME CHECKS PASSED\n";
} catch (Throwable $e) {
	fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
	exit(1);
} finally {
	if (isset($original_config) && is_array($original_config)) {
		update_option(DDB_Spots_Admin_Settings_Page::OPTION_KEY, $original_config, false);
	}
}
