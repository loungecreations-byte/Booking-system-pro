<?php
declare(strict_types=1);

namespace BSP\Notifications;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function current_user_can;
use function register_rest_route;
use function rest_ensure_response;

final class Rest_Controller
{
    private SetupService $setup;

    public function __construct(?SetupService $setup = null)
    {
        $this->setup = $setup ?? new SetupService();
    }

    public function register_routes(): void
    {
        if (! \function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            'bsp/v1',
            '/notifications',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list_notifications'],
                'permission_callback' => [$this, 'allow_public_read'],
            ]
        );

        register_rest_route(
            'bsp/v1',
            '/notifications/setup',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'configure'],
                'permission_callback' => [$this, 'can_manage'],
            ]
        );
    }

    /**
     * @return WP_REST_Response|array<string, mixed>
     */
    public function list_notifications()
    {
        $config = $this->setup->getConfiguration();

        $data = [
            'notifications' => $config['templates'],
            'methods'       => $config['methods'],
            'timing'        => $config['timing'],
            'variables'     => $config['variables'],
        ];

        return \function_exists('rest_ensure_response') ? rest_ensure_response($data) : $data;
    }

    /**
     * Persist configuration updates from the REST client.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function configure(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        $payload = \is_array($payload) ? $payload : [];

        $config = $this->setup->saveConfiguration($payload);

        $data = [
            'success' => true,
            'config'  => $config,
        ];

        return \function_exists('rest_ensure_response') ? rest_ensure_response($data) : $data;
    }

    public function can_manage(): bool
    {
        return \function_exists('current_user_can') ? current_user_can('manage_options') : true;
    }

    public function allow_public_read(): bool
    {
        return true;
    }
}
