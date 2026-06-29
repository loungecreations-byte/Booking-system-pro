<?php

/**
 * REST endpoints for the private tour portal.
 *
 * @package Booking_Pro_Module
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Exposes REST resources for tours, sessions, and progress.
 */
class SBDP_Private_Tours_REST
{
    private const RATE_LIMIT_WINDOW = 60;
    private const RATE_LIMIT_READ_MAX = 60;
    private const RATE_LIMIT_WRITE_MAX = 20;
    private const RATE_LIMIT_PREFIX = 'sbdp_private_tours_rest_';

    /**
     * Register REST hooks.
     */
    public static function init(): void
    {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    /**
     * Register endpoint routes.
     */
    public static function register_routes(): void
    {
        register_rest_route('sbdp/v1', '/private-tours', array(
                'methods'             => 'GET',
                'permission_callback' => array(__CLASS__, 'rest_allow_public_read'),
                'callback'            => array(__CLASS__, 'rest_get_tours'),
            ));
        register_rest_route('sbdp/v1', '/private-tours/session', array(
                'methods'             => 'POST',
                'permission_callback' => array(__CLASS__, 'rest_allow_public_write'),
                'callback'            => array(__CLASS__, 'rest_create_session'),
                'args'                => array(
                    'token' => array(
                        'required'          => true,
                        'sanitize_callback' => array(__CLASS__, 'sanitize_token'),
                    ),
                    'email' => array(
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_email',
                    ),
                ),
            ));
        register_rest_route('sbdp/v1', '/private-tours/session/(?P<session>[A-Za-z0-9_-]+)', array(
                'methods'             => 'GET',
                'permission_callback' => array(__CLASS__, 'rest_validate_session'),
                'callback'            => array(__CLASS__, 'rest_get_session'),
                'args'                => array(
                    'session' => array(
                        'required'          => true,
                        'sanitize_callback' => array(__CLASS__, 'sanitize_token'),
                    ),
                ),
            ));
        register_rest_route('sbdp/v1', '/private-tours/session/(?P<session>[A-Za-z0-9_-]+)/progress', array(
                'methods'             => 'POST',
                'permission_callback' => array(__CLASS__, 'rest_validate_session'),
                'callback'            => array(__CLASS__, 'rest_update_progress'),
                'args'                => array(
                    'session' => array(
                        'required'          => true,
                        'sanitize_callback' => array(__CLASS__, 'sanitize_token'),
                    ),
                    'stepId' => array(
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ),
                    'completed' => array(
                        'required'          => false,
                        'sanitize_callback' => array(__CLASS__, 'sanitize_bool'),
                    ),
                    'payload' => array(
                        'required' => false,
                    ),
                ),
            ));
        register_rest_route('sbdp/v1', '/private-tours/navigation/route', array(
                'methods'             => 'GET',
                'permission_callback' => array(__CLASS__, 'rest_allow_public_read'),
                'callback'            => array(__CLASS__, 'rest_get_navigation_route'),
                'args'                => array(
                    'fromLat' => array(
                        'required'          => true,
                        'sanitize_callback' => array(__CLASS__, 'sanitize_coordinate_value'),
                    ),
                    'fromLng' => array(
                        'required'          => true,
                        'sanitize_callback' => array(__CLASS__, 'sanitize_coordinate_value'),
                    ),
                    'toLat' => array(
                        'required'          => true,
                        'sanitize_callback' => array(__CLASS__, 'sanitize_coordinate_value'),
                    ),
                    'toLng' => array(
                        'required'          => true,
                        'sanitize_callback' => array(__CLASS__, 'sanitize_coordinate_value'),
                    ),
                    'profile' => array(
                        'required'          => false,
                        'default'           => 'walking',
                        'sanitize_callback' => array(__CLASS__, 'sanitize_route_profile'),
                    ),
                ),
            ));
        register_rest_route('sbdp/v1', '/private-tours/navigation/embed-diagnostics', array(
                'methods'             => 'GET',
                'permission_callback' => array(__CLASS__, 'rest_allow_public_read'),
                'callback'            => array(__CLASS__, 'rest_get_navigation_embed_diagnostics'),
                'args'                => array(
                    'origin' => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'destination' => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'mode' => array(
                        'required'          => false,
                        'default'           => 'walking',
                        'sanitize_callback' => array(__CLASS__, 'sanitize_route_profile'),
                    ),
                    'language' => array(
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'region' => array(
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'units' => array(
                        'required'          => false,
                        'default'           => 'metric',
                        'sanitize_callback' => array(__CLASS__, 'sanitize_embed_units'),
                    ),
                ),
            ));
    }

    /**
     * @return true|WP_Error
     */
    public static function rest_allow_public_read(WP_REST_Request $request)
    {
        return self::check_rate_limit($request, self::RATE_LIMIT_READ_MAX);
    }

    /**
     * @return true|WP_Error
     */
    public static function rest_allow_public_write(WP_REST_Request $request)
    {
        return self::check_rate_limit($request, self::RATE_LIMIT_WRITE_MAX);
    }
    /**
     * List available tours.
     *
     * @return WP_REST_Response
     */
    public static function rest_get_tours(): WP_REST_Response
    {
        $posts = get_posts(array(
                'post_type'      => SBDP_Private_Tours::POST_TYPE_TOUR,
                'post_status'    => 'publish',
                'numberposts'    => -1,
                'orderby'        => array(
                    'menu_order' => 'ASC',
                    'title'      => 'ASC',
                ),
            ));
        $tours = array();
        foreach ($posts as $post) {
            $tours[] = array(
                'id'          => (int) $post->ID,
                'title'       => get_the_title($post),
                'summary'     => (string) get_post_meta($post->ID, '_sbdp_tour_summary', true),
                'duration'    => (int) get_post_meta($post->ID, '_sbdp_tour_duration', true),
                'chapterCount' => (int) get_post_meta($post->ID, '_sbdp_tour_chapter_count', true),
                'slug'        => $post->post_name,
                'excerpt'     => wp_strip_all_tags($post->post_excerpt),
                'thumbnail'   => get_the_post_thumbnail_url($post, 'medium'),
                'supportMail' => (string) get_post_meta($post->ID, '_sbdp_tour_support_email', true),
            );
        }

        return new WP_REST_Response(array(
                'tours'   => $tours,
                'updated' => current_time('mysql', true),
            ));
    }

    /**
     * Exchange ticket token for a session.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_create_session(WP_REST_Request $request)
    {
        $token = (string) $request->get_param('token');
        $email = (string) $request->get_param('email');
        if ('' === $token) {
            return new WP_Error('sbdp_missing_token', __('Ticket token is required.', 'sbdp'), array('status' => 400));
        }

        $ticket = SBDP_Private_Tours_Tickets::get_ticket_by_token($token);
        if (! $ticket) {
            return new WP_Error('sbdp_invalid_token', __('The provided ticket is not valid.', 'sbdp'), array('status' => 404));
        }

        if ('disabled' === $ticket['status']) {
            return new WP_Error('sbdp_ticket_disabled', __('This ticket has been disabled.', 'sbdp'), array('status' => 403));
        }

        $require_email = (bool) apply_filters('sbdp/private_tours/require_email', true, $ticket);
        if ($require_email && 'preview' !== $ticket['status'] && '' === $email) {
            return new WP_Error('sbdp_missing_email', __('E-mail address is required.', 'sbdp'), array('status' => 400));
        }

        if ($require_email && 'preview' !== $ticket['status'] && '' === $ticket['email']) {
            return new WP_Error('sbdp_missing_ticket_email', __('Ticket does not have an e-mail address. Contact support.', 'sbdp'), array('status' => 403));
        }

        if ('' !== $ticket['email'] && '' !== $email && strtolower($ticket['email']) !== strtolower($email)) {
            return new WP_Error('sbdp_email_mismatch', __('Ticket and e-mail do not match.', 'sbdp'), array('status' => 403));
        }

        if (! empty($ticket['expires_at'])) {
            $expires = strtotime($ticket['expires_at'] . ' UTC');
            if ($expires && $expires < time()) {
                return new WP_Error('sbdp_ticket_expired', __('This ticket has expired.', 'sbdp'), array('status' => 403));
            }
        }

        $ticket = SBDP_Private_Tours_Tickets::ensure_access_window((int) $ticket['id'], $ticket);
        if (is_wp_error($ticket)) {
            return $ticket;
        }

        $session = '';
        $access_remaining = SBDP_Private_Tours_Tickets::access_remaining_seconds($ticket);
        if ($access_remaining <= 0) {
            return new WP_Error('sbdp_ticket_access_expired', __('This ticket access period has expired.', 'sbdp'), array('status' => 403));
        }

        $expires_in = min(SBDP_Private_Tours_Tickets::SESSION_TTL, $access_remaining);
        $now = time();
        if (! empty($ticket['session_token']) && ! empty($ticket['session_expires_at'])) {
            $session_expires = strtotime($ticket['session_expires_at'] . ' UTC');
            if ($session_expires && $session_expires > $now) {
                $session = (string) $ticket['session_token'];
                $ttl = min(max(0, $session_expires - $now), $access_remaining);
                if ($ttl > 0) {
                    set_transient('sbdp_private_session_' . $session, (int) $ticket['id'], $ttl);
                    $expires_in = $ttl;
                }
            }
        }

        if ('' === $session) {
            $session = SBDP_Private_Tours_Tickets::create_session((int) $ticket['id'], $access_remaining);
            SBDP_Private_Tours_Tickets::touch_redeemed((int) $ticket['id']);
        }

        return new WP_REST_Response(array(
                'session'   => $session,
                'tourId'    => (int) $ticket['tour_id'],
                'orderId'   => (int) $ticket['order_id'],
                'expiresIn' => $expires_in,
                'accessExpiresAt' => (string) ($ticket['access_expires_at'] ?? ''),
            ));
    }

    /**
     * Validate session token for protected endpoints.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return true|WP_Error
     */
    public static function rest_validate_session(WP_REST_Request $request)
    {
        $allowed = self::check_rate_limit($request, self::RATE_LIMIT_WRITE_MAX);
        if (is_wp_error($allowed)) {
            return $allowed;
        }

        $session = (string) $request->get_param('session');
        if ('' === $session) {
            return new WP_Error('sbdp_missing_session', __('Session token is required.', 'sbdp'), array('status' => 401));
        }

        $ticket = SBDP_Private_Tours_Tickets::get_ticket_by_session($session);
        if (! $ticket) {
            return new WP_Error('sbdp_invalid_session', __('Session token is invalid or expired.', 'sbdp'), array('status' => 401));
        }

        $request->set_param('ticket_record', $ticket);
        return true;
    }

    /**
     * @return true|WP_Error
     */
    private static function check_rate_limit(WP_REST_Request $request, int $max_attempts)
    {
        $bucket = self::RATE_LIMIT_PREFIX . md5($request->get_route() . '|' . self::request_ip());
        $state = get_transient($bucket);
        $state = is_array($state) ? $state : array();
        $attempts = (int) ($state['attempts'] ?? 0) + 1;

        if ($attempts > $max_attempts) {
            return new WP_Error('sbdp_private_tours_rate_limited', __('Too many requests.', 'sbdp'), array('status' => 429));
        }

        set_transient($bucket, array('attempts' => $attempts), self::RATE_LIMIT_WINDOW);

        return true;
    }

    private static function request_ip(): string
    {
        $candidates = array(
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        );

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || '' === $candidate) {
                continue;
            }

            $parts = explode(',', $candidate);
            $ip = trim((string) ($parts[0] ?? ''));
            if ('' !== $ip) {
                return $ip;
            }
        }

        return 'unknown';
    }

    /**
     * Retrieve tour context for a session.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_get_session(WP_REST_Request $request)
    {
        $ticket = $request->get_param('ticket_record');
        if (! is_array($ticket)) {
            return new WP_Error('sbdp_missing_ticket', __('Ticket lookup failed.', 'sbdp'), array('status' => 500));
        }

        $tour_id = (int) $ticket['tour_id'];
        $tour    = get_post($tour_id);
        if (! $tour || SBDP_Private_Tours::POST_TYPE_TOUR !== $tour->post_type) {
            return new WP_Error('sbdp_missing_tour', __('Linked tour could not be found.', 'sbdp'), array('status' => 404));
        }

        $steps = SBDP_Private_Tours_Tickets::get_steps_for_tour($tour_id);
        $response = array(
            'ticket'   => array(
                'tokenTail'  => substr((string) $ticket['token'], -6),
                'email'      => $ticket['email'],
                'status'     => $ticket['status'],
                'redeemedAt' => $ticket['redeemed_at'],
                'activatedAt' => $ticket['activated_at'] ?? null,
                'accessExpiresAt' => $ticket['access_expires_at'] ?? null,
            ),
            'tour'     => array(
                'id'          => $tour_id,
                'title'       => get_the_title($tour),
                'content'     => apply_filters('the_content', $tour->post_content),
                'summary'     => (string) get_post_meta($tour_id, '_sbdp_tour_summary', true),
                'duration'    => (int) get_post_meta($tour_id, '_sbdp_tour_duration', true),
                'chapterCount' => (int) get_post_meta($tour_id, '_sbdp_tour_chapter_count', true),
                'supportMail' => (string) get_post_meta($tour_id, '_sbdp_tour_support_email', true),
                'thumbnail'   => get_the_post_thumbnail_url($tour, 'large'),
            ),
            'steps'    => $steps,
            'progress' => SBDP_Private_Tours_Tickets::decode_progress($ticket['progress']),
            'navigation' => array(
                'total' => count($steps),
            ),
        );
        return new WP_REST_Response($response);
    }

    /**
     * Update step progress.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_update_progress(WP_REST_Request $request)
    {

        $ticket = $request->get_param('ticket_record');
        if (! is_array($ticket)) {
            return new WP_Error('sbdp_missing_ticket', __('Ticket lookup failed.', 'sbdp'), array('status' => 500));
        }

        $step_id = (int) $request->get_param('stepId');
        if ($step_id <= 0) {
            return new WP_Error('sbdp_invalid_step', __('Step identifier is required.', 'sbdp'), array('status' => 422));
        }

        $step = get_post($step_id);
        if (! $step || SBDP_Private_Tours::POST_TYPE_TOUR_STEP !== $step->post_type || (int) $step->post_parent !== (int) $ticket['tour_id']) {
            return new WP_Error('sbdp_step_mismatch', __('Step does not belong to this tour.', 'sbdp'), array('status' => 403));
        }

        $progress = SBDP_Private_Tours_Tickets::decode_progress($ticket['progress']);
        $payload = $request->get_param('payload');
        if (is_array($payload)) {
            $payload = array_map(static function ($value) {

                if (is_scalar($value)) {
                    return sanitize_text_field((string) $value);
                }

                    return $value;
            }, $payload);
        } else {
            $payload = array();
        }

        $completed = (bool) $request->get_param('completed');
        $progress[$step_id] = array(
            'completed' => $completed,
            'updatedAt' => current_time('mysql', true),
            'payload'   => $payload,
        );
        SBDP_Private_Tours_Tickets::store_progress((int) $ticket['id'], $progress);
        return new WP_REST_Response(array(
                'stepId'   => $step_id,
                'progress' => $progress[$step_id],
            ));
    }

    /**
     * Build a route payload from current location to a tour step.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_get_navigation_route(WP_REST_Request $request)
    {

        $raw_from_lat = $request->get_param('fromLat');
        $raw_from_lng = $request->get_param('fromLng');
        $raw_to_lat   = $request->get_param('toLat');
        $raw_to_lng   = $request->get_param('toLng');
        if (! is_numeric($raw_from_lat) || ! is_numeric($raw_from_lng) || ! is_numeric($raw_to_lat) || ! is_numeric($raw_to_lng)) {
            return new WP_Error('sbdp_invalid_coordinates', __('Route coordinates must be numeric.', 'sbdp'), array('status' => 422));
        }

        $from_lat = (float) $raw_from_lat;
        $from_lng = (float) $raw_from_lng;
        $to_lat   = (float) $raw_to_lat;
        $to_lng   = (float) $raw_to_lng;
        $profile  = self::sanitize_route_profile((string) $request->get_param('profile'));
        if (! self::is_valid_coordinate($from_lat, $from_lng) || ! self::is_valid_coordinate($to_lat, $to_lng)) {
            return new WP_Error('sbdp_invalid_coordinates', __('Invalid route coordinates.', 'sbdp'), array('status' => 422));
        }

        $cache_key = sprintf('sbdp_nav_route_%s', md5(implode('|', array(
                        $profile,
                        number_format($from_lat, 5, '.', ''),
                        number_format($from_lng, 5, '.', ''),
                        number_format($to_lat, 5, '.', ''),
                        number_format($to_lng, 5, '.', ''),
                    ))));
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['path']) && is_array($cached['path'])) {
            return new WP_REST_Response($cached);
        }

        $api_base_url = (string) apply_filters('sbdp/private_tours/route_api_base', 'https://router.project-osrm.org/route/v1', $profile, $from_lat, $from_lng, $to_lat, $to_lng);
        $api_base_url = trim($api_base_url);
        if ($api_base_url === '') {
            $fallback = self::build_fallback_route_payload($profile, $from_lat, $from_lng, $to_lat, $to_lng);
            set_transient($cache_key, $fallback, 5 * MINUTE_IN_SECONDS);
            return new WP_REST_Response($fallback);
        }

        $coordinates = rawurlencode($from_lng . ',' . $from_lat . ';' . $to_lng . ',' . $to_lat);
        $request_url = trailingslashit(untrailingslashit($api_base_url))
            . rawurlencode($profile)
            . '/'
            . $coordinates
            . '?overview=full&geometries=geojson&steps=false';
        $request_url = (string) apply_filters('sbdp/private_tours/route_api_url', $request_url, array(
                'profile' => $profile,
                'fromLat' => $from_lat,
                'fromLng' => $from_lng,
                'toLat'   => $to_lat,
                'toLng'   => $to_lng,
            ));
        $http_response = wp_remote_get($request_url, array(
                'timeout'     => 8,
                'redirection' => 2,
                'headers'     => array(
                    'Accept' => 'application/json',
                ),
            ));
        if (is_wp_error($http_response)) {
            $fallback = self::build_fallback_route_payload($profile, $from_lat, $from_lng, $to_lat, $to_lng);
            $fallback['error'] = $http_response->get_error_message();
            set_transient($cache_key, $fallback, 2 * MINUTE_IN_SECONDS);
            return new WP_REST_Response($fallback);
        }

        $status_code = (int) wp_remote_retrieve_response_code($http_response);
        if ($status_code < 200 || $status_code >= 300) {
            $fallback = self::build_fallback_route_payload($profile, $from_lat, $from_lng, $to_lat, $to_lng);
            $fallback['error'] = sprintf('Route API status code: %d', $status_code);
            set_transient($cache_key, $fallback, 2 * MINUTE_IN_SECONDS);
            return new WP_REST_Response($fallback);
        }

        $payload = json_decode((string) wp_remote_retrieve_body($http_response), true);
        if (! is_array($payload) || empty($payload['routes'][0]) || ! is_array($payload['routes'][0])) {
            $fallback = self::build_fallback_route_payload($profile, $from_lat, $from_lng, $to_lat, $to_lng);
            $fallback['error'] = 'Route API payload invalid';
            set_transient($cache_key, $fallback, 2 * MINUTE_IN_SECONDS);
            return new WP_REST_Response($fallback);
        }

        $route = $payload['routes'][0];
        $coordinates = $route['geometry']['coordinates'] ?? array();
        $path = array();
        if (is_array($coordinates)) {
            foreach ($coordinates as $coordinate) {
                if (! is_array($coordinate) || count($coordinate) < 2) {
                    continue;
                }

                $lng = (float) $coordinate[0];
                $lat = (float) $coordinate[1];
                if (! self::is_valid_coordinate($lat, $lng)) {
                    continue;
                }

                $path[] = array($lat, $lng);
            }
        }

        if (count($path) < 2) {
            $fallback = self::build_fallback_route_payload($profile, $from_lat, $from_lng, $to_lat, $to_lng);
            $fallback['error'] = 'Route geometry missing';
            set_transient($cache_key, $fallback, 2 * MINUTE_IN_SECONDS);
            return new WP_REST_Response($fallback);
        }

        $result = array(
            'provider'  => 'osrm',
            'fallback'  => false,
            'profile'   => $profile,
            'distance'  => round((float) ($route['distance'] ?? 0), 1),
            'duration'  => round((float) ($route['duration'] ?? 0), 1),
            'path'      => $path,
            'updatedAt' => current_time('mysql', true),
        );
        set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
        return new WP_REST_Response($result);
    }

    /**
     * Probe Google Maps Embed API availability for the current route.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_get_navigation_embed_diagnostics(WP_REST_Request $request)
    {
        $key = self::get_google_maps_embed_api_key();
        if ('' === $key) {
            return new WP_REST_Response(array(
                'ok'        => false,
                'status'    => 424,
                'reason'    => 'missing_key',
                'message'   => __('Google Maps Embed API-key ontbreekt.', 'sbdp'),
                'checkedAt' => current_time('mysql', true),
            ), 424);
        }

        $origin = trim((string) $request->get_param('origin'));
        $destination = trim((string) $request->get_param('destination'));
        if ('' === $origin || '' === $destination) {
            return new WP_Error('sbdp_missing_embed_route', __('Origin and destination are required for embed diagnostics.', 'sbdp'), array('status' => 422));
        }

        $mode = self::sanitize_route_profile((string) $request->get_param('mode'));
        $language = strtolower(trim((string) $request->get_param('language')));
        $region = strtoupper(trim((string) $request->get_param('region')));
        $units = self::sanitize_embed_units((string) $request->get_param('units'));
        $referer = trim((string) $request->get_header('referer'));
        if ('' === $referer) {
            $referer = home_url('/');
        }

        $referer_host = (string) wp_parse_url($referer, PHP_URL_HOST);
        $cache_key = sprintf('sbdp_embed_probe_%s', md5(implode('|', array($origin, $destination, $mode, $language, $region, $units, $referer_host))));
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['ok'])) {
            return new WP_REST_Response($cached, (int) ($cached['status'] ?? 200));
        }

        $embed_url = add_query_arg(array_filter(array(
            'key' => $key,
            'origin' => $origin,
            'destination' => $destination,
            'mode' => $mode,
            'units' => $units,
            'language' => $language,
            'region' => $region,
        )), 'https://www.google.com/maps/embed/v1/directions');

        $http_response = wp_remote_get($embed_url, array(
            'timeout'             => 10,
            'redirection'         => 2,
            'limit_response_size' => 20000,
            'headers'             => array(
                'Referer'         => $referer,
                'Accept-Language' => '' !== $language ? $language : get_locale(),
            ),
        ));

        if (is_wp_error($http_response)) {
            $result = array(
                'ok'        => false,
                'status'    => 502,
                'reason'    => 'request_error',
                'message'   => sprintf(__('Google Maps embed kon niet worden gecontroleerd: %s', 'sbdp'), $http_response->get_error_message()),
                'checkedAt' => current_time('mysql', true),
            );
            set_transient($cache_key, $result, MINUTE_IN_SECONDS);
            return new WP_REST_Response($result, 502);
        }

        $status_code = (int) wp_remote_retrieve_response_code($http_response);
        $body = (string) wp_remote_retrieve_body($http_response);
        $reason = self::detect_google_embed_rejection_reason($status_code, $body);
        $ok = $status_code >= 200 && $status_code < 300 && '' === $reason;
        $message = self::get_google_embed_diagnostic_message($ok, $status_code, $reason, $referer_host);

        $result = array(
            'ok'        => $ok,
            'status'    => $status_code > 0 ? $status_code : ($ok ? 200 : 500),
            'reason'    => '' !== $reason ? $reason : ($ok ? 'ok' : 'unknown'),
            'message'   => $message,
            'embedUrl'  => $embed_url,
            'checkedAt' => current_time('mysql', true),
        );

        set_transient($cache_key, $result, $ok ? 5 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS);
        return new WP_REST_Response($result, (int) $result['status']);
    }
    /**
     * Sanitize ticket tokens.
     *
     * @param string $token Raw token.
     *
     * @return string
     */
    public static function sanitize_token(string $token): string
    {
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token);
        return substr((string) $token, 0, 64);
    }

    /**
     * Sanitize boolean request values.
     *
     * @param mixed $value Raw value.
     *
     * @return bool
     */
    public static function sanitize_bool($value): bool
    {

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Sanitize coordinate values for route requests.
     *
     * @param mixed $value Raw value.
     *
     * @return float
     */
    public static function sanitize_coordinate_value($value): float
    {

        return (float) $value;
    }

    /**
     * Sanitize route profile values for map provider compatibility.
     *
     * @param string $profile Raw route profile.
     *
     * @return string
     */
    public static function sanitize_route_profile(string $profile): string
    {

        $profile = strtolower(trim($profile));
        if (in_array($profile, array('foot', 'walk'), true)) {
            $profile = 'walking';
        }

        $allowed = array('walking', 'cycling', 'driving');
        if (! in_array($profile, $allowed, true)) {
            return 'walking';
        }

        return $profile;
    }

    /**
     * Sanitize embed units values.
     *
     * @param string $units Raw units value.
     *
     * @return string
     */
    public static function sanitize_embed_units(string $units): string
    {
        $units = strtolower(trim($units));
        return 'imperial' === $units ? 'imperial' : 'metric';
    }

    /**
     * Validate latitude and longitude ranges.
     *
     * @param float $lat Latitude.
     * @param float $lng Longitude.
     *
     * @return bool
     */
    private static function is_valid_coordinate(float $lat, float $lng): bool
    {

        return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
    }

    /**
     * Resolve the Google Maps Embed API key for tours.
     *
     * @return string
     */
    private static function get_google_maps_embed_api_key(): string
    {
        $candidates = array(
            get_option('sbdp_google_maps_api_key', ''),
            get_option('elementor_google_maps_api_key', ''),
        );

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && '' !== trim($candidate)) {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * Detect common rejection modes in Google Embed responses.
     *
     * @param int    $status_code HTTP status code.
     * @param string $body Response body.
     *
     * @return string
     */
    private static function detect_google_embed_rejection_reason(int $status_code, string $body): string
    {
        if (403 === $status_code) {
            return 'forbidden';
        }

        $haystack = strtolower(wp_strip_all_tags($body));
        $signatures = array(
            'referernotallowedmaperror' => 'referrer_not_allowed',
            'google maps platform rejected your request' => 'request_rejected',
            'this page can\'t load google maps correctly' => 'request_rejected',
            'api project is not authorized' => 'api_not_authorized',
            'billing' => 'billing_required',
            'request_denied' => 'request_rejected',
            'api keys with referer restrictions cannot be used with this api' => 'referrer_not_allowed',
        );

        foreach ($signatures as $signature => $reason) {
            if (false !== strpos($haystack, $signature)) {
                return $reason;
            }
        }

        return '';
    }

    /**
     * Build a user-facing diagnostic message for Google Embed checks.
     *
     * @param bool   $ok Whether the probe succeeded.
     * @param int    $status_code HTTP status code.
     * @param string $reason Diagnostic reason.
     * @param string $referer_host Referrer host.
     *
     * @return string
     */
    private static function get_google_embed_diagnostic_message(bool $ok, int $status_code, string $reason, string $referer_host): string
    {
        if ($ok) {
            return sprintf(__('Google Maps embed bevestigd (%d).', 'sbdp'), $status_code);
        }

        if ('referrer_not_allowed' === $reason) {
            return sprintf(__('Google Maps embed geweigerd: HTTP referrer niet toegestaan. Voeg %s/* toe in Google Cloud.', 'sbdp'), '' !== $referer_host ? 'http://' . $referer_host : 'je domein');
        }

        if ('billing_required' === $reason) {
            return __('Google Maps embed geweigerd: billing ontbreekt of is niet actief voor dit project.', 'sbdp');
        }

        if ('api_not_authorized' === $reason) {
            return __('Google Maps embed geweigerd: deze API-key mag de Maps Embed API nog niet gebruiken.', 'sbdp');
        }

        if ('forbidden' === $reason) {
            return sprintf(__('Google Maps embed geweigerd (403). Controleer Maps Embed API, billing en HTTP referrer voor %s.', 'sbdp'), '' !== $referer_host ? $referer_host . '/*' : __('je domein', 'sbdp'));
        }

        if ($status_code >= 400) {
            return sprintf(__('Google Maps embed gaf status %d terug. Controleer key, billing en API-rechten.', 'sbdp'), $status_code);
        }

        return __('Google Maps embed kon niet bevestigd worden. Controleer key, billing en toegestane referrers.', 'sbdp');
    }

    /**
     * Build a lightweight fallback route when API routing is unavailable.
     *
     * @param string $profile Route profile.
     * @param float  $from_lat Origin latitude.
     * @param float  $from_lng Origin longitude.
     * @param float  $to_lat Destination latitude.
     * @param float  $to_lng Destination longitude.
     *
     * @return array<string, mixed>
     */
    private static function build_fallback_route_payload(string $profile, float $from_lat, float $from_lng, float $to_lat, float $to_lng): array
    {

        $distance = self::calculate_haversine_distance($from_lat, $from_lng, $to_lat, $to_lng);
        $speed_meters_per_second = 1.38;
// approx 5 km/h walking speed.
        $duration = $speed_meters_per_second > 0 ? $distance / $speed_meters_per_second : 0;
        return array(
            'provider'  => 'fallback',
            'fallback'  => true,
            'profile'   => $profile,
            'distance'  => round($distance, 1),
            'duration'  => round($duration, 1),
            'path'      => array(
                array($from_lat, $from_lng),
                array($to_lat, $to_lng),
            ),
            'updatedAt' => current_time('mysql', true),
        );
    }

    /**
     * Calculate straight-line distance between two coordinates.
     *
     * @param float $lat_a Latitude A.
     * @param float $lng_a Longitude A.
     * @param float $lat_b Latitude B.
     * @param float $lng_b Longitude B.
     *
     * @return float
     */
    private static function calculate_haversine_distance(float $lat_a, float $lng_a, float $lat_b, float $lng_b): float
    {

        $earth_radius = 6371000.0;
        $delta_lat = deg2rad($lat_b - $lat_a);
        $delta_lng = deg2rad($lng_b - $lng_a);
        $a = sin($delta_lat / 2) * sin($delta_lat / 2)
            + cos(deg2rad($lat_a)) * cos(deg2rad($lat_b))
            * sin($delta_lng / 2) * sin($delta_lng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
        return $earth_radius * $c;
    }
}
