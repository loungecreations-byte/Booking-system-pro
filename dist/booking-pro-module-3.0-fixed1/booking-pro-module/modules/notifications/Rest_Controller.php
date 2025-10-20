<?php
declare(strict_types=1);

namespace BSP\Notifications;

use function register_rest_route;

final class Rest_Controller
{
    public function register_routes(): void
    {
        register_rest_route('bsp/v1', '/notifications', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_notifications'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function list_notifications(): array
    {
        return ['notifications' => []];
    }
}
