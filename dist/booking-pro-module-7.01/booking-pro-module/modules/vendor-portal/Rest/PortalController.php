<?php

declare(strict_types=1);

namespace BSP\VendorPortal\Rest;

use BSP\VendorPortal\Service\VendorAuthService;
use BSP\VendorPortal\Service\VendorDashboardService;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;

final class PortalController
{
    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route('bsp/v1', '/vendor-portal/login', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'login'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('bsp/v1', '/vendor-portal/dashboard', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'dashboard'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('bsp/v1', '/vendor-portal/logout', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'logout'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function login(WP_REST_Request $request)
    {
        $payload = self::getJson($request);

        try {
            $vendorId  = isset($payload['vendor_id']) ? (int) $payload['vendor_id'] : 0;
            $accessKey = isset($payload['access_key']) ? (string) $payload['access_key'] : '';

            $auth  = new VendorAuthService();
            $login = $auth->login($vendorId, $accessKey);

            return self::respond($login);
        } catch (InvalidArgumentException $exception) {
            return self::respond(new WP_Error('sbdp_vendor_portal_login_failed', $exception->getMessage(), array('status' => 400)));
        }
    }

    public static function dashboard(WP_REST_Request $request)
    {
        $token = (string) $request->get_param('token');
        if ($token === '') {
            $token = (string) $request->get_header('X-SBDP-Vendor-Token');
        }

        try {
            $auth      = new VendorAuthService();
            $session   = $auth->validateToken($token);
            $dashboard = (new VendorDashboardService())->buildDashboard((int) $session['vendor_id']);

            return self::respond(array(
                'dashboard' => $dashboard,
                'session'   => $session,
            ));
        } catch (InvalidArgumentException $exception) {
            return self::respond(new WP_Error('sbdp_vendor_portal_unauthorised', $exception->getMessage(), array('status' => 403)));
        }
    }

    public static function logout(WP_REST_Request $request)
    {
        $payload = self::getJson($request);
        $token   = isset($payload['token']) ? (string) $payload['token'] : '';

        if ($token === '') {
            return self::respond(array('success' => true));
        }

        $auth = new VendorAuthService();
        $auth->destroyToken($token);

        return self::respond(array('success' => true));
    }

    /**
     * @return array<string, mixed>
     */
    private static function getJson(WP_REST_Request $request): array
    {
        $params = $request->get_json_params();
        if (! is_array($params)) {
            return array();
        }

        return $params;
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    private static function respond($data)
    {
        if (function_exists('rest_ensure_response')) {
            return rest_ensure_response($data);
        }

        return $data;
    }
}

