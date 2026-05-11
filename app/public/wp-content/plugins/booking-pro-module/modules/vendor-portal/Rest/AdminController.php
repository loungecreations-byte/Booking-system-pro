<?php

declare(strict_types=1);

namespace BSP\VendorPortal\Rest;

use BSP\VendorPortal\Service\VendorPortalAdminService;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use Throwable;
use function current_user_can;
use function rest_ensure_response;

final class AdminController
{
    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            'bsp/v1',
            '/vendor-portal/admin/vendors',
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'listVendors'),
                'permission_callback' => array(__CLASS__, 'canManage'),
                'args'                => array(
                    'search'   => array(
                        'type'              => 'string',
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'status'   => array(
                        'type'              => 'string',
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'page'     => array(
                        'type'              => 'integer',
                        'required'          => false,
                        'default'           => 1,
                        'sanitize_callback' => 'absint',
                    ),
                    'per_page' => array(
                        'type'              => 'integer',
                        'required'          => false,
                        'default'           => 20,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );

        register_rest_route(
            'bsp/v1',
            '/vendor-portal/admin/vendors/(?P<vendor_id>\d+)',
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'getVendor'),
                'permission_callback' => array(__CLASS__, 'canManage'),
                'args'                => array(
                    'vendor_id' => array(
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );

        register_rest_route(
            'bsp/v1',
            '/vendor-portal/admin/vendors/(?P<vendor_id>\d+)/access-key',
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'updateAccessKey'),
                'permission_callback' => array(__CLASS__, 'canManage'),
                'args'                => array(
                    'vendor_id' => array(
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );
    }

    public static function canManage(): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    public static function listVendors(WP_REST_Request $request)
    {
        try {
            $service = new VendorPortalAdminService();
            $result  = $service->listVendors(
                (string) $request->get_param('search'),
                (string) $request->get_param('status'),
                (int) $request->get_param('page'),
                (int) $request->get_param('per_page')
            );

            return rest_ensure_response($result);
        } catch (Throwable $exception) {
            return self::error($exception->getMessage());
        }
    }

    public static function getVendor(WP_REST_Request $request)
    {
        $vendorId = (int) $request->get_param('vendor_id');

        try {
            $service = new VendorPortalAdminService();
            $vendor  = $service->getVendorDetails($vendorId);

            return rest_ensure_response(array(
                'vendor' => $vendor,
            ));
        } catch (Throwable $exception) {
            return self::error($exception->getMessage());
        }
    }

    public static function updateAccessKey(WP_REST_Request $request)
    {
        $vendorId = (int) $request->get_param('vendor_id');
        $payload  = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = array();
        }

        $action = isset($payload['action']) ? (string) $payload['action'] : 'generate';
        $key    = '';

        try {
            $service = new VendorPortalAdminService();

            if ($action === 'set') {
                $key = isset($payload['key']) ? (string) $payload['key'] : '';
                if ($key === '') {
                    throw new InvalidArgumentException('Key cannot be empty.');
                }
            } else {
                $key = $service->generateAccessKey();
            }

            $summary = $service->updateAccessKey($vendorId, $key);

            return rest_ensure_response(array(
                'vendor' => $summary,
                'key'    => $key,
            ));
        } catch (Throwable $exception) {
            return self::error($exception->getMessage());
        }
    }

    private static function error(string $message, int $code = 400)
    {
        return new WP_Error('sbdp_vendor_portal_admin', $message, array('status' => $code));
    }
}
