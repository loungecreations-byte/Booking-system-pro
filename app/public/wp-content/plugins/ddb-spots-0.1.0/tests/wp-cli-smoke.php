<?php
if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run this with: wp eval-file tests/wp-cli-smoke.php\n");
	exit(1);
}

function ddb_assert(bool $condition, string $message): void {
	if (! $condition) {
		throw new RuntimeException($message);
	}
}

function ddb_fake_place_details(string $name, string $address, string $website, float $lat = 51.6903, float $lng = 5.3037, string $phone = '+31 73 000 0000', string $hours = 'Mon-Fri: 09:00-17:00'): array {
	return array(
		'status' => 'OK',
		'result' => array(
			'name' => $name,
			'formatted_address' => $address,
			'rating' => 4.6,
			'geometry' => array('location' => array('lat' => $lat, 'lng' => $lng)),
			'website' => $website,
			'formatted_phone_number' => $phone,
			'opening_hours' => array('weekday_text' => array($hours)),
			'photos' => array(array('photo_reference' => 'photo_ref_1')),
			'address_components' => array(
				array('long_name' => 'Den Bosch', 'types' => array('locality')),
				array('long_name' => 'Noord-Brabant', 'types' => array('administrative_area_level_1')),
				array('long_name' => 'Nederland', 'types' => array('country')),
			),
			'html_attributions' => array(),
		),
	);
}

try {
	$original_config = DDB_Spots_Admin_Settings_Page::get_config();
	ddb_assert(class_exists('DDB_Spots'), 'DDB_Spots class is not loaded.');
	ddb_assert(class_exists('DDB_Spots_Integrations_Google_Places'), 'Google Places class is not loaded.');
	ddb_assert(class_exists('DDB_Spots_Core_Roles'), 'Roles class is not loaded.');
	ddb_assert(class_exists('DDB_Spots_Rest_Api'), 'REST API class is not loaded.');
	ddb_assert(get_role(DDB_Spots_Core_Roles::ROLE_ANALYST) instanceof WP_Role, 'Analyst role is missing.');

	$plugin = new DDB_Spots();
	ddb_assert('ticket' === $plugin->sanitize_booking_provider('ticket'), 'sanitize_booking_provider failed for valid value.');
	ddb_assert('none' === $plugin->sanitize_booking_provider('invalid'), 'sanitize_booking_provider failed for invalid value.');
	ddb_assert('52.1001' === $plugin->sanitize_coordinate('52,1001'), 'sanitize_coordinate failed.');
	ddb_assert('1' === $plugin->sanitize_checkbox('1'), 'sanitize_checkbox failed for 1.');
	ddb_assert('0' === $plugin->sanitize_checkbox('0'), 'sanitize_checkbox failed for 0.');
	echo "PASS: sanitizer checks\n";

	$cta_post_id = wp_insert_post(
		array(
			'post_type' => 'ddb_spot',
			'post_status' => 'draft',
			'post_title' => 'CTA test',
		),
		true
	);
	ddb_assert(! is_wp_error($cta_post_id) && (int) $cta_post_id > 0, 'Could not create CTA test post.');
	update_post_meta((int) $cta_post_id, '_ddb_spot_type_primary', 'event');
	update_post_meta((int) $cta_post_id, '_ddb_booking_provider', 'ticket');
	update_post_meta((int) $cta_post_id, '_ddb_cta_url', 'https://tickets.example.com/event');

	$reflection = new ReflectionClass($plugin);
	$cta_method = $reflection->getMethod('get_cta_url_by_type');
	$cta_method->setAccessible(true);
	$resolved_cta = (string) $cta_method->invoke($plugin, (int) $cta_post_id, 'api');
	ddb_assert('https://tickets.example.com/event' === $resolved_cta, 'CTA resolution did not prefer direct CTA URL.');
	echo "PASS: CTA resolution checks\n";

	$config = DDB_Spots_Admin_Settings_Page::get_config();
	$config['data_sources']['google_api_key'] = 'test-key';
	update_option(DDB_Spots_Admin_Settings_Page::OPTION_KEY, $config, false);

	$google_details = ddb_fake_place_details('Google Place A', 'Address A', 'https://google-a.example.com');
	$http_filter = static function ($preempt, array $request_args, string $url) use (&$google_details) {
		if (false === strpos($url, '/place/details/json')) {
			return $preempt;
		}
		return array(
			'headers' => array(),
			'body' => wp_json_encode($google_details),
			'response' => array('code' => 200, 'message' => 'OK'),
			'cookies' => array(),
			'filename' => null,
		);
	};
	add_filter('pre_http_request', $http_filter, 10, 3);

	$importer = new DDB_Spots_Integrations_Google_Places();
	$sync_post_id = $importer->import_place_by_id('place_sync_test');
	ddb_assert(is_int($sync_post_id) && $sync_post_id > 0, 'Initial import failed.');

	wp_update_post(
		array(
			'ID' => $sync_post_id,
			'post_title' => 'Manual locked title',
			'post_excerpt' => 'Manual locked excerpt',
		)
	);
	update_post_meta($sync_post_id, '_ddb_lock_title', '1');
	update_post_meta($sync_post_id, '_ddb_lock_excerpt', '1');
	update_post_meta($sync_post_id, '_ddb_lock_cta', '1');
	update_post_meta($sync_post_id, '_ddb_lock_location', '1');
	update_post_meta($sync_post_id, '_ddb_lock_contact', '1');
	update_post_meta($sync_post_id, '_ddb_lock_hours', '1');
	update_post_meta($sync_post_id, '_ddb_spot_cta_url', 'https://manual-cta.example.com');
	update_post_meta($sync_post_id, '_ddb_address', 'Manual Address');
	update_post_meta($sync_post_id, '_ddb_city', 'Manual City');
	update_post_meta($sync_post_id, '_ddb_lat', '52.0900');
	update_post_meta($sync_post_id, '_ddb_lng', '5.1210');
	update_post_meta($sync_post_id, '_ddb_google_phone', '+31 73 111 1111');
	update_post_meta($sync_post_id, '_ddb_google_website', 'https://manual-contact.example.com');
	update_post_meta($sync_post_id, '_ddb_google_opening_hours_json', wp_json_encode(array('weekday_text' => array('Locked hours'))));

	$google_details = ddb_fake_place_details('Google Place B', 'Address B', 'https://google-b.example.com', 52.0001, 5.0002, '+31 73 222 2222', 'Daily: 10:00-22:00');
	$sync_result = $importer->sync_post($sync_post_id);
	ddb_assert(! is_wp_error($sync_result), 'sync_post failed.');

	$after_sync = get_post($sync_post_id);
	ddb_assert($after_sync instanceof WP_Post, 'Could not load synced post.');
	ddb_assert('Manual locked title' === (string) $after_sync->post_title, 'Title lock failed during sync.');
	ddb_assert('Manual locked excerpt' === (string) $after_sync->post_excerpt, 'Excerpt lock failed during sync.');
	ddb_assert('https://manual-cta.example.com' === (string) get_post_meta($sync_post_id, '_ddb_spot_cta_url', true), 'CTA lock failed during sync.');
	ddb_assert('Manual Address' === (string) get_post_meta($sync_post_id, '_ddb_address', true), 'Location lock failed for address.');
	ddb_assert('Manual City' === (string) get_post_meta($sync_post_id, '_ddb_city', true), 'Location lock failed for city.');
	ddb_assert('52.0900' === (string) get_post_meta($sync_post_id, '_ddb_lat', true), 'Location lock failed for latitude.');
	ddb_assert('5.1210' === (string) get_post_meta($sync_post_id, '_ddb_lng', true), 'Location lock failed for longitude.');
	ddb_assert('+31 73 111 1111' === (string) get_post_meta($sync_post_id, '_ddb_google_phone', true), 'Contact lock failed for phone.');
	ddb_assert('https://manual-contact.example.com' === (string) get_post_meta($sync_post_id, '_ddb_google_website', true), 'Contact lock failed for website.');
	$locked_hours = (string) get_post_meta($sync_post_id, '_ddb_google_opening_hours_json', true);
	ddb_assert(false !== strpos($locked_hours, 'Locked hours'), 'Hours lock failed.');
	echo "PASS: sync lock checks\n";

	$repo = new DDB_Spots_Domain_Spot_Repository();
	$canonical_row = $repo->get_by_post_id($sync_post_id);
	ddb_assert(is_array($canonical_row), 'Canonical db row missing after sync.');
	ddb_assert((int) ($canonical_row['spot_post_id'] ?? 0) === (int) $sync_post_id, 'Canonical row has wrong post link.');
	echo "PASS: canonical table sync checks\n";

	remove_filter('pre_http_request', $http_filter, 10);
	wp_delete_post((int) $cta_post_id, true);
	wp_delete_post((int) $sync_post_id, true);

	echo "ALL TESTS PASSED\n";
} catch (Throwable $e) {
	fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
	exit(1);
} finally {
	if (isset($original_config) && is_array($original_config)) {
		update_option(DDB_Spots_Admin_Settings_Page::OPTION_KEY, $original_config, false);
	}
}
