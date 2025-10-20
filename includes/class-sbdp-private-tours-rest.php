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
        register_rest_route(
            'sbdp/v1',
            '/private-tours',
            array(
                'methods'             => 'GET',
                'permission_callback' => '__return_true',
                'callback'            => array(__CLASS__, 'rest_get_tours'),
            )
        );

        register_rest_route(
            'sbdp/v1',
            '/private-tours/session',
            array(
                'methods'             => 'POST',
                'permission_callback' => '__return_true',
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
            )
        );

        register_rest_route(
            'sbdp/v1',
            '/private-tours/session/(?P<session>[A-Za-z0-9_-]+)',
            array(
                'methods'             => 'GET',
                'permission_callback' => array(__CLASS__, 'rest_validate_session'),
                'callback'            => array(__CLASS__, 'rest_get_session'),
            )
        );

        register_rest_route(
            'sbdp/v1',
            '/private-tours/session/(?P<session>[A-Za-z0-9_-]+)/progress',
            array(
                'methods'             => 'POST',
                'permission_callback' => array(__CLASS__, 'rest_validate_session'),
                'callback'            => array(__CLASS__, 'rest_update_progress'),
                'args'                => array(
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
            )
        );
    }

    /**
     * List available tours.
     *
     * @return WP_REST_Response
     */
    public static function rest_get_tours(): WP_REST_Response
    {
        $posts = get_posts(
            array(
                'post_type'      => 'sbdp_private_tour',
                'post_status'    => 'publish',
                'numberposts'    => -1,
                'orderby'        => array(
                    'menu_order' => 'ASC',
                    'title'      => 'ASC',
                ),
            )
        );

        $tours = array();

        foreach ($posts as $post) {
            $tours[] = array(
                'id'          => (int) $post->ID,
                'title'       => get_the_title($post),
                'summary'     => (string) get_post_meta($post->ID, '_sbdp_tour_summary', true),
                'duration'    => (int) get_post_meta($post->ID, '_sbdp_tour_duration', true),
                'chapterCount'=> (int) get_post_meta($post->ID, '_sbdp_tour_chapter_count', true),
                'slug'        => $post->post_name,
                'excerpt'     => wp_strip_all_tags($post->post_excerpt),
                'thumbnail'   => get_the_post_thumbnail_url($post, 'medium'),
                'supportMail' => (string) get_post_meta($post->ID, '_sbdp_tour_support_email', true),
            );
        }

        return new WP_REST_Response(
            array(
                'tours'   => $tours,
                'updated' => current_time('mysql', true),
            )
        );
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

        if ('' !== $ticket['email'] && '' !== $email && strtolower($ticket['email']) !== strtolower($email)) {
            return new WP_Error('sbdp_email_mismatch', __('Ticket and e-mail do not match.', 'sbdp'), array('status' => 403));
        }

        if (! empty($ticket['expires_at'])) {
            $expires = strtotime($ticket['expires_at'] . ' UTC');
            if ($expires && $expires < time()) {
                return new WP_Error('sbdp_ticket_expired', __('This ticket has expired.', 'sbdp'), array('status' => 403));
            }
        }

        $session = SBDP_Private_Tours_Tickets::create_session((int) $ticket['id']);
        SBDP_Private_Tours_Tickets::touch_redeemed((int) $ticket['id']);

        return new WP_REST_Response(
            array(
                'session'   => $session,
                'tourId'    => (int) $ticket['tour_id'],
                'orderId'   => (int) $ticket['order_id'],
                'expiresIn' => SBDP_Private_Tours_Tickets::SESSION_TTL,
            )
        );
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
        if (! $tour || 'sbdp_private_tour' !== $tour->post_type) {
            return new WP_Error('sbdp_missing_tour', __('Linked tour could not be found.', 'sbdp'), array('status' => 404));
        }

        $steps = SBDP_Private_Tours_Tickets::get_steps_for_tour($tour_id);

        $response = array(
            'ticket'   => array(
                'tokenTail'  => substr((string) $ticket['token'], -6),
                'email'      => $ticket['email'],
                'status'     => $ticket['status'],
                'redeemedAt' => $ticket['redeemed_at'],
            ),
            'tour'     => array(
                'id'          => $tour_id,
                'title'       => get_the_title($tour),
                'content'     => apply_filters('the_content', $tour->post_content),
                'summary'     => (string) get_post_meta($tour_id, '_sbdp_tour_summary', true),
                'duration'    => (int) get_post_meta($tour_id, '_sbdp_tour_duration', true),
                'chapterCount'=> (int) get_post_meta($tour_id, '_sbdp_tour_chapter_count', true),
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
        if (! $step || 'sbdp_private_tour_step' !== $step->post_type || (int) $step->post_parent !== (int) $ticket['tour_id']) {
            return new WP_Error('sbdp_step_mismatch', __('Step does not belong to this tour.', 'sbdp'), array('status' => 403));
        }

        $progress = SBDP_Private_Tours_Tickets::decode_progress($ticket['progress']);

        $payload = $request->get_param('payload');
        if (is_array($payload)) {
            $payload = array_map(
                static function ($value) {
                    if (is_scalar($value)) {
                        return sanitize_text_field((string) $value);
                    }

                    return $value;
                },
                $payload
            );
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

        return new WP_REST_Response(
            array(
                'stepId'   => $step_id,
                'progress' => $progress[$step_id],
            )
        );
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
}
