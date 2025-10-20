<?php

declare(strict_types=1);

namespace BSP\Sales\Vendors;

use WP_Error;
use WP_Post;
use WP_Query;

use function __;
use function absint;
use function apply_filters;
use function array_diff;
use function array_filter;
use function array_map;
use function array_unique;
use function delete_post_meta;
use function do_action;
use function get_post;
use function get_post_meta;
use function is_array;
use function is_wp_error;
use function sanitize_text_field;
use function sprintf;
use function update_post_meta;

final class VendorService
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_ARCHIVED  = 'archived';

    public const TOUR_STATUS_UPCOMING   = 'upcoming';
    public const TOUR_STATUS_COMPLETED  = 'completed';
    public const TOUR_STATUS_CANCELLED  = 'cancelled';

    public const TOUR_STATUSES = array(
        self::TOUR_STATUS_UPCOMING,
        self::TOUR_STATUS_COMPLETED,
        self::TOUR_STATUS_CANCELLED,
    );

    public const STATUSES = array(
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_ARCHIVED,
    );

    private static ?VendorRepository $repository = null;

    public static function init(): void
    {
        if (self::$repository === null) {
            self::$repository = new VendorRepository();
        }
    }

    public static function list(array $args = array(), bool $withProducts = false): array
    {
        $vendors = self::repo()->getVendors($args);

        foreach ($vendors as &$vendor) {
            $vendor['resource_ids'] = self::getResourceIds((int) $vendor['id']);
            if ($withProducts) {
                $vendor['product_ids'] = self::repo()->getVendorProductIds((int) $vendor['id']);
            }
        }
        unset($vendor);

        return $vendors;
    }

    public static function get(int $id, bool $withProducts = false)
    {
        $vendor = self::repo()->getVendor($id);
        if ($vendor === null) {
            return null;
        }

        $vendor['resource_ids'] = self::getResourceIds($id);
        if ($withProducts) {
            $vendor['product_ids'] = self::repo()->getVendorProductIds((int) $vendor['id']);
        }

        return $vendor;
    }

    public static function getResources(int $vendorId): array
    {
        $resourceIds = self::getResourceIds($vendorId);
        if ($resourceIds === array()) {
            return array();
        }

        $resources = array();
        foreach ($resourceIds as $resourceId) {
            $post = get_post($resourceId);
            if (! $post instanceof WP_Post) {
                continue;
            }

            $availability = get_post_meta($resourceId, '_sbdp_resource_availability', true);
            $tours        = get_post_meta($resourceId, '_sbdp_resource_tours', true);

            $resources[] = array(
                'id'           => $resourceId,
                'title'        => $post->post_title,
                'availability' => self::normaliseAvailability($availability),
                'tours'        => self::normaliseTours($tours),
            );
        }

        return $resources;
    }

    public static function syncResources(int $vendorId, array $resourceIds): array
    {
        $vendorId = absint($vendorId);
        if ($vendorId <= 0) {
            return array(
                'attached' => array(),
                'detached' => array(),
                'current'  => array(),
            );
        }

        $resourceIds = self::sanitizeResourceIds($resourceIds);
        $current     = self::getResourceIds($vendorId);

        $attach  = array_diff($resourceIds, $current);
        $detach  = array_diff($current, $resourceIds);

        foreach ($attach as $resourceId) {
            $post = get_post($resourceId);
            if ($post instanceof WP_Post) {
                update_post_meta($resourceId, '_sbdp_resource_vendor', $vendorId);
            }
        }

        foreach ($detach as $resourceId) {
            delete_post_meta($resourceId, '_sbdp_resource_vendor');
        }

        return array(
            'attached' => array_values($attach),
            'detached' => array_values($detach),
            'current'  => $resourceIds,
        );
    }

    public static function getResourceIds(int $vendorId): array
    {
        $vendorId = absint($vendorId);
        if ($vendorId <= 0) {
            return array();
        }

        $query = new WP_Query(
            array(
                'post_type'      => 'bookable_resource',
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array(
                        'key'   => '_sbdp_resource_vendor',
                        'value' => $vendorId,
                    ),
                ),
            )
        );

        $ids = $query->posts ? array_map('absint', $query->posts) : array();
        return $ids;
    }

    public static function create(array $input)
    {
        $validated = VendorValidator::validateForCreate($input);
        if ($validated instanceof WP_Error) {
            return $validated;
        }

        $validated = apply_filters('bsp/sales/vendors/prepare_create', $validated);

        $existing = self::repo()->getVendorBySlug($validated['slug']);
        if ($existing !== null) {
            return new WP_Error(
                'bsp_sales_vendor_duplicate_slug',
                sprintf(__('Vendor slug %s is already in use.', 'sbdp'), $validated['slug']),
                array('status' => 409)
            );
        }

        $created = self::repo()->createVendor($validated);

        if (! is_wp_error($created)) {
            do_action('bsp/sales/vendors/created', $created);
        }

        return $created;
    }

    public static function update(int $id, array $input)
    {
        $id      = absint($id);
        $current = self::repo()->getVendor($id);
        if ($current === null) {
            return new WP_Error('bsp_sales_vendor_missing', __('Vendor not found.', 'sbdp'), array('status' => 404));
        }

        $validated = VendorValidator::validateForUpdate($input, $current);
        if ($validated instanceof WP_Error) {
            return $validated;
        }

        $validated = apply_filters('bsp/sales/vendors/prepare_update', $validated, $current);

        if (isset($validated['slug']) && $validated['slug'] !== $current['slug']) {
            $existing = self::repo()->getVendorBySlug($validated['slug']);
            if ($existing !== null && (int) $existing['id'] !== $id) {
                return new WP_Error(
                    'bsp_sales_vendor_duplicate_slug',
                    sprintf(__('Vendor slug %s is already in use.', 'sbdp'), $validated['slug']),
                    array('status' => 409)
                );
            }
        }

        $updated = self::repo()->updateVendor($id, $validated);

        if (! is_wp_error($updated)) {
            do_action('bsp/sales/vendors/updated', $updated, $current);
        }

        return $updated;
    }

    public static function syncProducts(int $id, array $productIds)
    {
        $id     = absint($id);
        $vendor = self::repo()->getVendor($id);
        if ($vendor === null) {
            return new WP_Error('bsp_sales_vendor_missing', __('Vendor not found.', 'sbdp'), array('status' => 404));
        }

        $changes = self::repo()->syncVendorProducts($id, $productIds);
        $vendor  = self::get($id, true);

        do_action('bsp/sales/vendors/products_synced', $vendor, $changes);

        return array(
            'vendor'  => $vendor,
            'changes' => $changes,
        );
    }

    public static function archive(int $id)
    {
        return self::setStatus($id, self::STATUS_ARCHIVED);
    }

    public static function activate(int $id)
    {
        return self::setStatus($id, self::STATUS_ACTIVE);
    }

    public static function suspend(int $id)
    {
        return self::setStatus($id, self::STATUS_SUSPENDED);
    }

    private static function repo(): VendorRepository
    {
        if (self::$repository === null) {
            self::$repository = new VendorRepository();
        }

        return self::$repository;
    }

    private static function setStatus(int $id, string $status)
    {
        $id      = absint($id);
        $current = self::repo()->getVendor($id);
        if ($current === null) {
            return new WP_Error('bsp_sales_vendor_missing', __('Vendor not found.', 'sbdp'), array('status' => 404));
        }

        if ($current['status'] === $status) {
            return $current;
        }

        $updated = self::repo()->setVendorStatus($id, $status);

        if (! is_wp_error($updated)) {
            do_action('bsp/sales/vendors/status_changed', $updated, $current);
        }

        return $updated;
    }

    private static function sanitizeResourceIds(array $resourceIds): array
    {
        $ids = array_filter(array_map('absint', $resourceIds), static function ($id) {
            return $id > 0;
        });

        return array_values(array_unique($ids));
    }

    private static function normaliseAvailability($value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $normalised = array();
        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $start = isset($entry['start']) ? sanitize_text_field((string) $entry['start']) : '';
            $end   = isset($entry['end']) ? sanitize_text_field((string) $entry['end']) : '';
            if ($start === '' || $end === '') {
                continue;
            }

            $normalised[] = array(
                'start' => $start,
                'end'   => $end,
                'notes' => isset($entry['notes']) ? sanitize_text_field((string) $entry['notes']) : '',
            );
        }

        return $normalised;
    }

    private static function normaliseTours($value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $normalised = array();
        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $date   = isset($entry['date']) ? sanitize_text_field((string) $entry['date']) : '';
            $status = isset($entry['status']) ? sanitize_text_field((string) $entry['status']) : self::STATUS_PENDING;
            if ($date === '') {
                continue;
            }

            if (! in_array($status, self::STATUSES, true)) {
                $status = self::STATUS_PENDING;
            }

            $normalised[] = array(
                'date'   => $date,
                'status' => $status,
                'notes'  => isset($entry['notes']) ? sanitize_text_field((string) $entry['notes']) : '',
            );
        }

        return $normalised;
    }
}



