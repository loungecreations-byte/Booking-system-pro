<?php
declare(strict_types=1);

namespace BSP\Intelligence\Rest;

use BSP\Intelligence\ReportsService;
use WP_REST_Request;

use function current_user_can;
use function register_rest_route;
use function rest_ensure_response;

final class ReportsController
{
    private ReportsService $reports;

    public function __construct(?ReportsService $reports = null)
    {
        $this->reports = $reports ?? new ReportsService();
    }

    public function register(): void
    {
        if (! \function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            'bsp/v1',
            '/reports/config',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'getConfig'],
                'permission_callback' => [$this, 'canView'],
            ]
        );

        register_rest_route(
            'bsp/v1',
            '/reports/config',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'enable'],
                'permission_callback' => [$this, 'canManage'],
            ]
        );

        register_rest_route(
            'bsp/v1',
            '/reports/snapshot',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'snapshot'],
                'permission_callback' => [$this, 'canView'],
            ]
        );
    }

    public function getConfig()
    {
        $config = $this->reports->getConfiguration();
        $data   = ['config' => $config];

        return \function_exists('rest_ensure_response') ? rest_ensure_response($data) : $data;
    }

    public function enable(WP_REST_Request $request)
    {
        $config = $this->reports->enable((array) $request->get_json_params());
        $data   = [
            'success' => true,
            'config'  => $config,
        ];

        return \function_exists('rest_ensure_response') ? rest_ensure_response($data) : $data;
    }

    public function snapshot()
    {
        $snapshot = $this->reports->generateSnapshot();
        $data     = ['snapshot' => $snapshot];

        return \function_exists('rest_ensure_response') ? rest_ensure_response($data) : $data;
    }

    public function canView(): bool
    {
        if (! \function_exists('current_user_can')) {
            return true;
        }

        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    public function canManage(): bool
    {
        if (! \function_exists('current_user_can')) {
            return true;
        }

        return current_user_can('manage_options');
    }
}
