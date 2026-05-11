<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use BSPModule\Core\Product\ProductMeta;
use wpdb;

use function array_filter;
use function array_map;
use function array_unique;
use function count;
use function current_time;
use function get_post_meta;
use function get_the_title;

/**
 * Repair tooling for canonical product/vendor and booking/resource mappings.
 *
 * Only deterministic repairs are applied automatically.
 */
final class CanonicalMappingRepairService
{
    public static function audit(): array
    {
        $productAudit = self::auditProductVendorLinks();
        $orderAudit = self::auditOrderItemResources();

        return [
            'repairable_product_vendor_links' => $productAudit['repairable'],
            'repairable_order_item_resources' => $orderAudit['repairable'],
            'conflicts' => array_merge(
                $productAudit['conflicts'],
                $orderAudit['conflicts']
            ),
        ];
    }

    public static function apply(): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return [
                'updated_product_vendor_links' => 0,
                'updated_order_item_resources' => 0,
                'conflicts' => [],
            ];
        }

        $productAudit = self::auditProductVendorLinks();
        $orderAudit = self::auditOrderItemResources();
        $productsTable = $wpdb->prefix . 'bsp_products';
        $updatedProductVendorLinks = 0;
        $updatedOrderItemResources = 0;

        foreach ($productAudit['repairable'] as $row) {
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT product_id FROM {$productsTable} WHERE product_id = %d LIMIT 1",
                (int) $row['product_id']
            ));

            if ($existing > 0) {
                $updated = $wpdb->update(
                    $productsTable,
                    [
                        'vendor_id' => (int) $row['vendor_id'],
                        'updated_at' => current_time('mysql', true),
                    ],
                    ['product_id' => (int) $row['product_id']],
                    ['%d', '%s'],
                    ['%d']
                );
                if ($updated !== false && $updated > 0) {
                    $updatedProductVendorLinks++;
                }
                continue;
            }

            $inserted = $wpdb->insert(
                $productsTable,
                [
                    'product_id' => (int) $row['product_id'],
                    'vendor_id' => (int) $row['vendor_id'],
                    'updated_at' => current_time('mysql', true),
                ],
                ['%d', '%d', '%s']
            );

            if ($inserted) {
                $updatedProductVendorLinks++;
            }
        }

        foreach ($orderAudit['repairable'] as $row) {
            $metaId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id
                 FROM {$wpdb->prefix}woocommerce_order_itemmeta
                 WHERE order_item_id = %d
                   AND meta_key = 'sbdp_resource_id'
                 LIMIT 1",
                (int) $row['order_item_id']
            ));

            if ($metaId > 0) {
                $updated = $wpdb->update(
                    $wpdb->prefix . 'woocommerce_order_itemmeta',
                    ['meta_value' => (string) $row['resource_id']],
                    ['meta_id' => $metaId],
                    ['%s'],
                    ['%d']
                );
                if ($updated !== false && $updated > 0) {
                    $updatedOrderItemResources++;
                }
            } else {
                $inserted = $wpdb->insert(
                    $wpdb->prefix . 'woocommerce_order_itemmeta',
                    [
                        'order_item_id' => (int) $row['order_item_id'],
                        'meta_key' => 'sbdp_resource_id',
                        'meta_value' => (string) $row['resource_id'],
                    ],
                    ['%d', '%s', '%s']
                );
                if ($inserted) {
                    $updatedOrderItemResources++;
                }
            }

            $labelMetaId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id
                 FROM {$wpdb->prefix}woocommerce_order_itemmeta
                 WHERE order_item_id = %d
                   AND meta_key = 'sbdp_resource_label'
                 LIMIT 1",
                (int) $row['order_item_id']
            ));

            $label = (string) get_the_title((int) $row['resource_id']);
            if ($label !== '') {
                if ($labelMetaId > 0) {
                    $wpdb->update(
                        $wpdb->prefix . 'woocommerce_order_itemmeta',
                        ['meta_value' => $label],
                        ['meta_id' => $labelMetaId],
                        ['%s'],
                        ['%d']
                    );
                } else {
                    $wpdb->insert(
                        $wpdb->prefix . 'woocommerce_order_itemmeta',
                        [
                            'order_item_id' => (int) $row['order_item_id'],
                            'meta_key' => 'sbdp_resource_label',
                            'meta_value' => $label,
                        ],
                        ['%d', '%s', '%s']
                    );
                }
            }
        }

        return [
            'updated_product_vendor_links' => $updatedProductVendorLinks,
            'updated_order_item_resources' => $updatedOrderItemResources,
            'conflicts' => array_merge($productAudit['conflicts'], $orderAudit['conflicts']),
        ];
    }

    private static function auditProductVendorLinks(): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return ['repairable' => [], 'conflicts' => []];
        }

        $productsTable = $wpdb->prefix . 'bsp_products';
        $postsTable = $wpdb->posts;
        $rows = $wpdb->get_results(
            "SELECT p.ID AS product_id, bp.vendor_id AS canonical_vendor_id
             FROM {$postsTable} p
             LEFT JOIN {$productsTable} bp ON bp.product_id = p.ID
             WHERE p.post_type = 'product'
               AND p.post_status NOT IN ('trash', 'auto-draft')",
            ARRAY_A
        ) ?: [];

        $repairable = [];
        $conflicts = [];

        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $canonicalVendorId = (int) ($row['canonical_vendor_id'] ?? 0);
            if ($productId <= 0 || $canonicalVendorId > 0) {
                continue;
            }

            $legacyVendorId = (int) get_post_meta($productId, '_sbdp_vendor_id', true);
            $resourceVendors = [];
            foreach (ProductMeta::get_resource_ids($productId) as $resourceId) {
                $resourceVendorId = (int) get_post_meta((int) $resourceId, '_sbdp_resource_vendor', true);
                if ($resourceVendorId > 0) {
                    $resourceVendors[] = $resourceVendorId;
                }
            }
            $resourceVendors = array_values(array_unique(array_filter(array_map('intval', $resourceVendors))));

            if ($legacyVendorId > 0 && $resourceVendors !== [] && ! in_array($legacyVendorId, $resourceVendors, true)) {
                $conflicts[] = [
                    'type' => 'product_vendor_conflict',
                    'product_id' => $productId,
                    'legacy_vendor_id' => $legacyVendorId,
                    'resource_vendor_ids' => $resourceVendors,
                ];
                continue;
            }

            $candidateVendorId = 0;
            if ($legacyVendorId > 0) {
                $candidateVendorId = $legacyVendorId;
            } elseif (count($resourceVendors) === 1) {
                $candidateVendorId = (int) $resourceVendors[0];
            }

            if ($candidateVendorId > 0) {
                $repairable[] = [
                    'product_id' => $productId,
                    'vendor_id' => $candidateVendorId,
                ];
            } elseif (count($resourceVendors) > 1) {
                $conflicts[] = [
                    'type' => 'product_vendor_conflict',
                    'product_id' => $productId,
                    'legacy_vendor_id' => $legacyVendorId,
                    'resource_vendor_ids' => $resourceVendors,
                ];
            }
        }

        return ['repairable' => $repairable, 'conflicts' => $conflicts];
    }

    private static function auditOrderItemResources(): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return ['repairable' => [], 'conflicts' => []];
        }

        $rows = $wpdb->get_results(
            "SELECT oi.order_item_id,
                    product_meta.meta_value AS product_id,
                    resource_meta.meta_value AS resource_id
             FROM {$wpdb->prefix}woocommerce_order_items oi
             INNER JOIN {$wpdb->posts} o ON o.ID = oi.order_id
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta product_meta
                ON product_meta.order_item_id = oi.order_item_id AND product_meta.meta_key = '_product_id'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta start_meta
                ON start_meta.order_item_id = oi.order_item_id AND start_meta.meta_key = 'sbdp_start'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta resource_meta
                ON resource_meta.order_item_id = oi.order_item_id AND resource_meta.meta_key = 'sbdp_resource_id'
             WHERE oi.order_item_type = 'line_item'
               AND o.post_type = 'shop_order'
               AND start_meta.meta_value IS NOT NULL
               AND (resource_meta.meta_value IS NULL OR resource_meta.meta_value = '' OR resource_meta.meta_value = '0')",
            ARRAY_A
        ) ?: [];

        $repairable = [];
        $conflicts = [];

        foreach ($rows as $row) {
            $orderItemId = (int) ($row['order_item_id'] ?? 0);
            $productId = (int) ($row['product_id'] ?? 0);
            if ($orderItemId <= 0 || $productId <= 0) {
                continue;
            }

            $resourceIds = ProductMeta::get_resource_ids($productId);
            if ($resourceIds === []) {
                $primary = ProductMeta::get_primary_resource_id($productId);
                if ($primary > 0) {
                    $resourceIds[] = $primary;
                }
            }
            $resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));

            if (count($resourceIds) === 1) {
                $repairable[] = [
                    'order_item_id' => $orderItemId,
                    'product_id' => $productId,
                    'resource_id' => (int) $resourceIds[0],
                ];
                continue;
            }

            $conflicts[] = [
                'type' => 'order_item_resource_conflict',
                'order_item_id' => $orderItemId,
                'product_id' => $productId,
                'candidate_resource_ids' => $resourceIds,
            ];
        }

        return ['repairable' => $repairable, 'conflicts' => $conflicts];
    }
}
