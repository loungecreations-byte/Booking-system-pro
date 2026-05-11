<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Rest_Api {
	private DDB_Spots_Domain_Spot_Repository $spots;
	private DDB_Spots_Domain_Event_Repository $events;
	private DDB_Spots_Domain_Audit_Repository $audit;
	private DDB_Spots_Service_Suggest_Service $suggest;
	private DDB_Spots_Service_Rate_Limiter $limiter;
	private DDB_Spots_Service_Quality_Policy $quality_policy;

	public function __construct(
		DDB_Spots_Domain_Spot_Repository $spots,
		DDB_Spots_Domain_Event_Repository $events,
		DDB_Spots_Domain_Audit_Repository $audit,
		DDB_Spots_Service_Suggest_Service $suggest,
		DDB_Spots_Service_Rate_Limiter $limiter,
		DDB_Spots_Service_Quality_Policy $quality_policy
	) {
		$this->spots = $spots;
		$this->events = $events;
		$this->audit = $audit;
		$this->suggest = $suggest;
		$this->limiter = $limiter;
		$this->quality_policy = $quality_policy;
	}

	public function init(): void {
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	public function register_routes(): void {
		$namespace = 'dbspots/v1';

		register_rest_route(
			$namespace,
			'/spots',
			array(
				array(
					'methods' => WP_REST_Server::READABLE,
					'permission_callback' => '__return_true',
					'callback' => array($this, 'get_spots'),
					'args' => array(
						'type' => array('sanitize_callback' => 'sanitize_text_field'),
						'area' => array('sanitize_callback' => 'sanitize_text_field'),
						'tag' => array('sanitize_callback' => 'sanitize_text_field'),
						'category' => array('sanitize_callback' => 'sanitize_text_field'),
						'per_page' => array('default' => 12, 'sanitize_callback' => 'absint'),
						'page' => array('default' => 1, 'sanitize_callback' => 'absint'),
						'lat' => array('sanitize_callback' => 'sanitize_text_field'),
						'lng' => array('sanitize_callback' => 'sanitize_text_field'),
					),
				),
				array(
					'methods' => WP_REST_Server::CREATABLE,
					'permission_callback' => array($this, 'can_edit_spots'),
					'callback' => array($this, 'create_spot'),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/spots/(?P<id>\d+)',
			array(
				array(
					'methods' => WP_REST_Server::READABLE,
					'permission_callback' => '__return_true',
					'callback' => array($this, 'get_spot'),
					'args' => array(
						'id' => array('required' => true, 'sanitize_callback' => 'absint'),
						'lat' => array('sanitize_callback' => 'sanitize_text_field'),
						'lng' => array('sanitize_callback' => 'sanitize_text_field'),
					),
				),
				array(
					'methods' => WP_REST_Server::EDITABLE,
					'permission_callback' => array($this, 'can_edit_spots'),
					'callback' => array($this, 'update_spot'),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/premium/eligibility',
			array(
				'methods' => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback' => array($this, 'get_premium_eligibility'),
				'args' => array(
					'spot_id' => array('required' => true, 'sanitize_callback' => 'absint'),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/events',
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback' => array($this, 'post_event'),
			)
		);

		register_rest_route(
			'dbspots/v1',
			'/suggest',
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback' => array($this, 'post_suggest'),
			)
		);

		register_rest_route(
			'dbspots/v1',
			'/publish',
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'permission_callback' => array($this, 'can_edit_spots'),
				'callback' => array($this, 'publish_spot'),
			)
		);

		register_rest_route(
			'dbspots/v1',
			'/archive',
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'permission_callback' => array($this, 'can_edit_spots'),
				'callback' => array($this, 'archive_spot'),
			)
		);
	}

	public function can_edit_spots(): bool {
		return current_user_can('edit_posts') || current_user_can(DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE);
	}

	public function get_spots(WP_REST_Request $request): WP_REST_Response {
		$status = sanitize_key((string) $request->get_param('status'));
		if (! $this->can_view_non_public()) {
			$status = 'publish';
		} elseif ('all' === $status) {
			$status = '';
		} elseif ('' === $status) {
			$status = 'publish';
		}

			$result = $this->spots->get_spots(
				array(
					'type' => sanitize_key((string) $request->get_param('type')),
					'area' => sanitize_key((string) $request->get_param('area')),
					'tag' => sanitize_title((string) $request->get_param('tag')),
					'category' => sanitize_title((string) $request->get_param('category')),
					'status' => $status,
				),
			max(1, absint((int) $request->get_param('page'))),
			min(100, max(1, absint((int) $request->get_param('per_page') ?: 12)))
		);
		return rest_ensure_response($result);
	}

	public function get_spot(WP_REST_Request $request): WP_REST_Response {
		$row = $this->spots->get_by_id(absint((int) $request->get_param('id')));
		if (! is_array($row)) {
			return new WP_REST_Response(array('message' => __('Spot not found', 'ddb-spots')), 404);
		}
		$status = sanitize_key((string) ($row['status'] ?? ''));
		if ('publish' !== $status && ! $this->can_view_non_public()) {
			return new WP_REST_Response(array('message' => __('Spot not found', 'ddb-spots')), 404);
		}
		return rest_ensure_response($row);
	}

	public function create_spot(WP_REST_Request $request): WP_REST_Response {
		$payload = $this->sanitize_spot_payload((array) $request->get_json_params());
		$row = $this->spots->upsert_from_api_payload($payload, null);
		if (! is_array($row)) {
			return new WP_REST_Response(array('message' => __('Create failed', 'ddb-spots')), 500);
		}
		$this->audit->log('spot', (int) $row['id'], 'api_create', get_current_user_id(), $payload);
		return new WP_REST_Response($row, 201);
	}

	public function update_spot(WP_REST_Request $request): WP_REST_Response {
		$id = absint((int) $request->get_param('id'));
		$row = $this->spots->get_by_id($id);
		if (! is_array($row)) {
			return new WP_REST_Response(array('message' => __('Spot not found', 'ddb-spots')), 404);
		}

		$payload = $this->sanitize_spot_payload((array) $request->get_json_params());
		$updated = $this->spots->upsert_from_api_payload($payload, $id);
		if (! is_array($updated)) {
			return new WP_REST_Response(array('message' => __('Update failed', 'ddb-spots')), 500);
		}
		$this->audit->log('spot', (int) $updated['id'], 'api_update', get_current_user_id(), $payload);
		return rest_ensure_response($updated);
	}

	public function publish_spot(WP_REST_Request $request): WP_REST_Response {
		$id = absint((int) $request->get_param('id'));
		$row = $this->spots->get_by_id($id);
		if (! is_array($row)) {
			return new WP_REST_Response(array('message' => __('Spot not found', 'ddb-spots')), 404);
		}

		$failures = $this->quality_policy->get_publish_failures_for_row($row, DDB_Spots_Admin_Settings_Page::get_config());
		if (! empty($failures)) {
			return new WP_REST_Response(array('message' => __('Publish blocked by quality gates', 'ddb-spots'), 'failures' => $failures), 422);
		}

		$post_id = (int) ($row['spot_post_id'] ?? 0);
		if ($post_id > 0) {
			wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'));
			do_action('ddb_spots_canonical_sync_post', $post_id, 'api_publish');
			$final = $this->spots->get_by_post_id($post_id);
		} else {
			$final = $this->spots->set_status($id, 'publish');
		}
		if (! is_array($final)) {
			return new WP_REST_Response(array('message' => __('Publish failed', 'ddb-spots')), 500);
		}
		$this->audit->log('spot', (int) $final['id'], 'api_publish', get_current_user_id(), array('status' => 'publish'));
		return rest_ensure_response($final);
	}

	public function archive_spot(WP_REST_Request $request): WP_REST_Response {
		$id = absint((int) $request->get_param('id'));
		$row = $this->spots->get_by_id($id);
		if (! is_array($row)) {
			return new WP_REST_Response(array('message' => __('Spot not found', 'ddb-spots')), 404);
		}

		$post_id = (int) ($row['spot_post_id'] ?? 0);
		if ($post_id > 0) {
			wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));
			do_action('ddb_spots_canonical_sync_post', $post_id, 'api_archive');
			$final = $this->spots->get_by_post_id($post_id);
		} else {
			$final = $this->spots->set_status($id, 'archived');
		}
		if (! is_array($final)) {
			return new WP_REST_Response(array('message' => __('Archive failed', 'ddb-spots')), 500);
		}
		$this->audit->log('spot', (int) $final['id'], 'api_archive', get_current_user_id(), array('status' => 'archived'));
		return rest_ensure_response($final);
	}

	public function get_premium_eligibility(WP_REST_Request $request): WP_REST_Response {
		$spot_id = absint((int) $request->get_param('spot_id'));
		if ($spot_id <= 0) {
			return new WP_REST_Response(array('message' => 'Invalid spot ID'), 400);
		}

		if (! function_exists('ddb_spot_health_score') || ! function_exists('ddb_spots_get_spot_plan_info')) {
			return new WP_REST_Response(array('message' => 'Premium engine not loaded'), 500);
		}

		$health = ddb_spot_health_score($spot_id);
		$plan = ddb_spots_get_spot_plan_info($spot_id);
		$eligibility = function_exists('ddb_spots_spot_eligibility') ? ddb_spots_spot_eligibility($spot_id, $health) : array('eligible' => false);

		return rest_ensure_response(array(
			'spot_id' => $spot_id,
			'health_score' => $health,
			'plan' => $plan['plan_key'] ?? 'free',
			'eligible' => ! empty($eligibility['eligible']),
			'reasons' => $eligibility['reasons'] ?? array(),
		));
	}

	public function post_event(WP_REST_Request $request): WP_REST_Response {
		if (! $this->authorize_public_ingest($request)) {
			return new WP_REST_Response(array('message' => __('Forbidden', 'ddb-spots')), 403);
		}

		$event_limit = max(10, (int) DDB_Spots_Admin_Settings_Page::get_value('integrations.public_events_per_minute', 90));
		if (! $this->limiter->allow('events', $event_limit, MINUTE_IN_SECONDS, $this->rate_limit_subject($request))) {
			return new WP_REST_Response(array('message' => __('Rate limit reached', 'ddb-spots')), 429);
		}

		$params = (array) $request->get_json_params();
		$event_type = sanitize_key((string) ($params['event_type'] ?? ''));
		$spot_id = $this->resolve_spot_id(absint((int) ($params['spot_id'] ?? 0)));
		$source = sanitize_key((string) ($params['source'] ?? 'web'));
		$context = isset($params['context']) && is_array($params['context']) ? $params['context'] : array();
		$ok = $this->events->log_event($event_type, $spot_id, $source, $context);
		if (! $ok) {
			return new WP_REST_Response(array('message' => __('Invalid event payload', 'ddb-spots')), 400);
		}
		return rest_ensure_response(array('ok' => true));
	}

	public function post_suggest(WP_REST_Request $request): WP_REST_Response {
		if (! $this->authorize_public_ingest($request)) {
			return new WP_REST_Response(array('message' => __('Forbidden', 'ddb-spots')), 403);
		}

		$suggest_limit = max(5, (int) DDB_Spots_Admin_Settings_Page::get_value('integrations.public_suggest_per_minute', 45));
		if (! $this->limiter->allow('suggest', $suggest_limit, MINUTE_IN_SECONDS, $this->rate_limit_subject($request))) {
			return new WP_REST_Response(array('message' => __('Rate limit reached', 'ddb-spots')), 429);
		}

		$raw_params = (array) $request->get_json_params();
		if (strlen((string) wp_json_encode($raw_params)) > 4096) {
			return new WP_REST_Response(array('message' => __('Payload too large', 'ddb-spots')), 413);
		}
		$params = $this->normalize_suggest_params($raw_params);
		if ('' === $params['intent']) {
			return new WP_REST_Response(array('message' => __('Missing intent', 'ddb-spots')), 400);
		}

		$cache_key = $this->build_suggest_cache_key($params);
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			return rest_ensure_response($cached);
		}

		$result = $this->suggest->suggest($params);
		set_transient($cache_key, $result, 120);
		return rest_ensure_response($result);
	}

	private function sanitize_spot_payload(array $payload): array {
		return array(
			'name' => sanitize_text_field((string) ($payload['name'] ?? '')),
			'type' => sanitize_key((string) ($payload['type'] ?? '')),
			'area' => sanitize_key((string) ($payload['area'] ?? '')),
			'short_desc' => sanitize_textarea_field((string) ($payload['short_desc'] ?? '')),
			'long_desc' => wp_kses_post((string) ($payload['long_desc'] ?? '')),
			'primary_cta_value' => esc_url_raw((string) ($payload['primary_cta_value'] ?? '')),
			'primary_cta_type' => sanitize_key((string) ($payload['primary_cta_type'] ?? 'external')),
			'price_band' => sanitize_text_field((string) ($payload['price_band'] ?? '')),
			'duration_hint' => absint((int) ($payload['duration_hint'] ?? 0)),
			'lat' => sanitize_text_field((string) ($payload['lat'] ?? '')),
			'lng' => sanitize_text_field((string) ($payload['lng'] ?? '')),
			'is_informational' => ! empty($payload['is_informational']) ? '1' : '0',
		);
	}

	private function build_suggest_cache_key(array $params): string {
		$payload = wp_json_encode($params);
		return 'dbspots_suggest_' . md5((string) $payload);
	}

	private function normalize_suggest_params(array $params): array {
		$intent = sanitize_key((string) ($params['intent'] ?? ''));
		$area = sanitize_key((string) ($params['area'] ?? ''));
		$pax = max(1, min(40, absint((int) ($params['pax'] ?? 1))));
		$duration = max(15, min(1440, absint((int) ($params['duration'] ?? 120))));
		$lat = $this->normalize_coordinate((string) ($params['lat'] ?? ''));
		$lng = $this->normalize_coordinate((string) ($params['lng'] ?? ''));

		return array(
			'intent' => $intent,
			'area' => $area,
			'pax' => $pax,
			'duration' => $duration,
			'lat' => $lat,
			'lng' => $lng,
		);
	}

	private function normalize_coordinate(string $value): string {
		$value = trim(str_replace(',', '.', $value));
		if ('' === $value || ! is_numeric($value)) {
			return '';
		}
		return (string) round((float) $value, 5);
	}

	private function resolve_spot_id(int $input): int {
		if ($input <= 0) {
			return 0;
		}
		$by_id = $this->spots->get_by_id($input);
		if (is_array($by_id)) {
			return (int) ($by_id['id'] ?? 0);
		}
		$by_post = $this->spots->get_by_post_id($input);
		if (is_array($by_post)) {
			return (int) ($by_post['id'] ?? 0);
		}
		return 0;
	}

	private function authorize_public_ingest(WP_REST_Request $request): bool {
		if ($this->can_view_non_public()) {
			return true;
		}

		$public_ingest_enabled = (bool) DDB_Spots_Admin_Settings_Page::get_value('integrations.public_ingest_enabled', false);
		if (! $public_ingest_enabled) {
			return false;
		}

		$key = trim((string) DDB_Spots_Admin_Settings_Page::get_value('integrations.ingestion_shared_key', ''));
		if ('' === $key) {
			return false;
		}
		$provided = $this->get_ingest_key_from_request($request);
		if ('' === $provided) {
			return false;
		}
		return hash_equals($key, $provided);
	}

	private function get_ingest_key_from_request(WP_REST_Request $request): string {
		$provided = trim((string) $request->get_header('x-ddb-ingest-key'));
		if ('' !== $provided) {
			return sanitize_text_field($provided);
		}

		$auth = trim((string) $request->get_header('authorization'));
		if (preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
			return sanitize_text_field((string) $matches[1]);
		}

		return '';
	}

	private function rate_limit_subject(WP_REST_Request $request): string {
		$key = $this->get_ingest_key_from_request($request);
		if ('' !== $key) {
			return 'key:' . substr(hash('sha256', $key), 0, 24);
		}
		$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
		return 'ip:' . $ip;
	}

	private function can_view_non_public(): bool {
		return current_user_can('edit_posts') || current_user_can(DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE);
	}
}
