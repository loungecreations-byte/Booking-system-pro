<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Rest;

use BSP\DiscoveryCamera\Service\CommunityService;
use WP_REST_Request;

final class CommunityController
{
    public static function register(): void
    {
        register_rest_route('bsp/v1', '/photo-community', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'feed'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('bsp/v1', '/photo-attempts/(?P<uuid>[a-f0-9-]{36})/community', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'submit'),
            'permission_callback' => array(Controller::class, 'authorize'),
        ));
        register_rest_route('bsp/v1', '/photo-community/(?P<id>\d+)/reaction', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'react'),
            'permission_callback' => array(Controller::class, 'authorize'),
        ));
    }

    public static function feed(WP_REST_Request $request)
    {
        $response = rest_ensure_response(array(
            'photos' => (new CommunityService())->feed(absint($request->get_param('tour_id')), absint($request->get_param('limit') ?: 24)),
        ));
        $response->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
        return $response;
    }

    public static function submit(WP_REST_Request $request)
    {
        $payload = (array) $request->get_json_params();
        return rest_ensure_response((new CommunityService())->submit(
            sanitize_text_field((string) $request['uuid']),
            self::actorUserId($request),
            (string) ($payload['caption'] ?? ''),
            self::actorTicketId($request)
        ));
    }

    public static function react(WP_REST_Request $request)
    {
        $payload = (array) $request->get_json_params();
        return rest_ensure_response((new CommunityService())->react(
            absint($request['id']),
            self::actorUserId($request),
            sanitize_key((string) ($payload['type'] ?? 'like')),
            self::actorTicketId($request)
        ));
    }

    private static function actorUserId(WP_REST_Request $request): int
    {
        return is_user_logged_in() ? get_current_user_id() : absint($request->get_param('ddb_actor_user_id'));
    }

    private static function actorTicketId(WP_REST_Request $request): int
    {
        return is_user_logged_in() ? 0 : absint($request->get_param('ddb_actor_ticket_id'));
    }

    public static function serveImage(): void
    {
        $path = (new CommunityService())->imagePath(absint($_GET['photo_id'] ?? 0));
        if ($path === '') {
            status_header(404);
            exit;
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400, immutable');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }
}
