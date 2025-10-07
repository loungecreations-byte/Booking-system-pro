<?php

declare(strict_types=1);

namespace BSP\Sales\Vendors;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

use function absint;
use function add_action;
use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function current_user_can;
use function is_array;
use function is_wp_error;
use function register_rest_route;
use function rest_ensure_response;
use function sanitize_text_field;
use function wp_parse_args;

final class VendorRestController
{
    private const ROUTE_NAMESPACE = 'bsp/v1';
    private const CAPABILITY      = 'manage_bsp_sales';

    public static function init(): void
    {
        add_action('rest_api_init', array(self::class, 'registerRoutes'));
    }

    public static function registerRoutes(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/vendors',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array(self::class, 'listVendors'),
                    'permission_callback' => array(self::class, 'canManage'),
                    'args'                => array(
                        'status'        => array('type' => 'string', 'required' => false),
                        'with_products' => array('type' => 'boolean', 'required' => false, 'default' => false),
                        'per_page'      => array('type' => 'integer', 'required' => false, 'default' => 50),
                        'page'          => array('type' => 'integer', 'required' => false, 'default' => 1),
                        'search'        => array('type' => 'string', 'required' => false),
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array(self::class, 'createVendor'),
                    'permission_callback' => array(self::class, 'canManage'),
                ),
            )
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/vendors/(?P<id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array(self::class, 'getVendor'),
                    'permission_callback' => array(self::class, 'canManage'),
                    'args'                => array(
                        'with_products' => array('type' => 'boolean', 'required' => false, 'default' => false),
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array(self::class, 'updateVendor'),
                    'permission_callback' => array(self::class, 'canManage'),
                ),
            )
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/vendors/(?P<id>\d+)/status',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array(self::class, 'updateStatus'),
                    'permission_callback' => array(self::class, 'canManage'),
                ),
            )
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/vendors/(?P<id>\d+)/products',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array(self::class, 'syncProducts'),
                    'permission_callback' => array(self::class, 'canManage'),
                ),
            )
        );
    }

    public static function canManage(): bool
    {
        return current_user_can(self::CAPABILITY);
    }

    public static function listVendors(WP_REST_Request $request)
    {
        $page     = max(1, (int) $request->get_param('page'));
        $perPage  = max(1, (int) $request->get_param('per_page'));
        $args     = array(
            'status' => $request->get_param('status'),
            'limit'  => $perPage,
            'offset' => ($page - 1) * $perPage,
            'search' => $request->get_param('search'),
        );
        $withProducts = (bool) $request->get_param('with_products');

        $vendors = VendorService::list($args, $withProducts);

        return rest_ensure_response(
            array(
                'vendors' => $vendors,
                'page'    => $page,
                'limit'   => $perPage,
            )
        );
    }

    public static function getVendor(WP_REST_Request $request)
    {
        $id           = absint($request['id']);
        $withProducts = (bool) $request->get_param('with_products');
        $vendor       = VendorService::get($id, $withProducts);

        if ($vendor === null) {
            return new WP_Error('bsp_sales_vendor_missing', __('Vendor not found.', 'sbdp'), array('status' => 404));
        }

        return rest_ensure_response(array('vendor' => $vendor));
    }

    public static function createVendor(WP_REST_Request $request)
    {
        $payload = self::preparePayload($request);

        $result = VendorService::create($payload);
        if (is_wp_error($result)) {
            return $result;
        }

        $resourceIds = self::extractResourceIds($request);
        if ($resourceIds !== null) {
            $sync = VendorService::syncResources((int) $result['id'], $resourceIds);
            $result['resource_ids'] = $sync['current'];
        }

        $productIds = self::extractProductIds($request);
        if ($productIds !== array()) {
            $sync = VendorService::syncProducts((int) $result['id'], $productIds);
            if (is_wp_error($sync)) {
                return $sync;
            }
            $result = $sync['vendor'];
        }

        $response = rest_ensure_response(array('vendor' => $result));
        $response->set_status(201);

        return $response;
    }

    public static function updateVendor(WP_REST_Request $request)
    {
        $id      = absint($request['id']);
        $payload = self::preparePayload($request);

        $result = VendorService::update($id, $payload);
        if (is_wp_error($result)) {
            return $result;
        }

        $resourceIds = self::extractResourceIds($request);
        if ($resourceIds !== null) {
            $sync = VendorService::syncResources($id, $resourceIds);
            $result['resource_ids'] = $sync['current'];
        }

        $productIds = self::extractProductIds($request);
        if ($productIds !== array()) {
            $sync = VendorService::syncProducts($id, $productIds);
            if (is_wp_error($sync)) {
                return $sync;
            }
            $result = $sync['vendor'];
        }

        return rest_ensure_response(array('vendor' => $result));
    }

    public static function updateStatus(WP_REST_Request $request)
    {
        $status = sanitize_text_field((string) $request->get_param('status'));

        switch ($status) {
            case VendorService::STATUS_ACTIVE:
                $result = VendorService::activate((int) $request['id']);
                break;
            case VendorService::STATUS_SUSPENDED:
                $result = VendorService::suspend((int) $request['id']);
                break;
            case VendorService::STATUS_ARCHIVED:
                $result = VendorService::archive((int) $request['id']);
                break;
            case VendorService::STATUS_PENDING:
                $result = VendorService::update((int) $request['id'], array('status' => VendorService::STATUS_PENDING));
                break;
            default:
                return new WP_Error('bsp_sales_vendor_invalid_status', __('Vendor status is not supported.', 'sbdp'), array('status' => 400));
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array('vendor' => $result));
    }

    public static function syncProducts(WP_REST_Request $request)
    {
        $productIds = self::extractProductIds($request);
        $result     = VendorService::syncProducts((int) $request['id'], $productIds);

        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    private static function preparePayload(WP_REST_Request $request): array
    {
        $body = wp_parse_args($request->get_json_params() ?: array(), $request->get_body_params());

        $payload = array();
        foreach (
            array(
                'name',
                'slug',
                'status',
                'payout_terms',
                'commission_rate',
                'contact_name',
                'contact_email',
                'contact_phone',
                'webhook_url',
                'pricing_currency',
                'pricing_base_rate',
                'pricing_markup_type',
                'pricing_markup_value',
            ) as $field
        ) {
            if (array_key_exists($field, $body)) {
                $payload[$field] = $body[$field];
            }
        }

        if (array_key_exists('channels', $body)) {
            $payload['channels'] = $body['channels'];
        }

        if (array_key_exists('capabilities', $body)) {
            $payload['capabilities'] = $body['capabilities'];
        }

        if (array_key_exists('metadata', $body)) {
            $payload['metadata'] = $body['metadata'];
        }

        return $payload;
    }

    private static function extractProductIds(WP_REST_Request $request): array
    {
        $body = wp_parse_args($request->get_json_params() ?: array(), $request->get_body_params());
        if (! array_key_exists('product_ids', $body)) {
            return array();
        }

        $ids = is_array($body['product_ids']) ? $body['product_ids'] : array($body['product_ids']);

        return array_values(array_filter(array_map('absint', $ids)));
    }

    private static function extractResourceIds(WP_REST_Request $request): ?array
    {
        $body = wp_parse_args($request->get_json_params() ?: array(), $request->get_body_params());
        if (! array_key_exists('resource_ids', $body)) {
            return null;
        }

        $ids = is_array($body['resource_ids']) ? $body['resource_ids'] : array($body['resource_ids']);

        return array_values(array_filter(array_map('absint', $ids)));
    }
}




