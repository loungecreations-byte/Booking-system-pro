<?php

declare(strict_types=1);

namespace BSP\VendorPortal\Service;

use BSP\Sales\Vendors\VendorService;
use WC_Order;
use wpdb;

/**
 * Backwards compatible vendor portal sales helper.
 *
 * This thin wrapper keeps legacy BSP integrations alive while the new BPM services are adopted.
 */
final class SalesHubService
{
    private wpdb $db;

    public function __construct(wpdb $db)
    {
        $this->db = $db;
    }

    /**
     * Resolve the vendor that owns a product.
     */
    public function resolveVendorIdForProduct(int $productId): ?int
    {
        return VendorService::getVendorIdForProduct($productId);
    }

    /**
     * Capture a queued WooCommerce order as a sales lead for the vendor portal.
     */
    public function handleOrderQueued(WC_Order $order): void
    {
        $vendorId = $this->detectVendorForOrder($order);
        if ($vendorId === null) {
            return;
        }

        $this->db->insert(
            $this->db->prefix . 'sbdp_sales_leads',
            array(
                'vendor_id'  => $vendorId,
                'order_id'   => $order->get_id(),
                'name'       => $order->get_formatted_billing_full_name(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'notes'      => $order->get_customer_note(),
                'company'    => $order->get_billing_company(),
                'payload'    => wp_json_encode($this->buildLeadPayload($order)),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    private function detectVendorForOrder(WC_Order $order): ?int
    {
        foreach ($order->get_items('line_item') as $item) {
            $productId = $this->resolveProductIdFromItem($item);
            if ($productId === null) {
                continue;
            }

            $vendorId = $this->resolveVendorIdForProduct($productId);
            if ($vendorId !== null) {
                return $vendorId;
            }
        }

        return null;
    }

    /**
     * @param array|object $item
     */
    private function resolveProductIdFromItem($item): ?int
    {
        if (is_object($item) && method_exists($item, 'get_product_id')) {
            $productId = (int) $item->get_product_id();
            return $productId > 0 ? $productId : null;
        }

        if (is_array($item) && isset($item['product_id'])) {
            $productId = (int) $item['product_id'];
            return $productId > 0 ? $productId : null;
        }

        return null;
    }

    private function buildLeadPayload(WC_Order $order): array
    {
        return array(
            'order_id' => $order->get_id(),
            'line_items' => array_values(
                array_filter(
                    array_map(
                        fn ($item): ?int => $this->resolveProductIdFromItem($item),
                        $order->get_items('line_item')
                    )
                )
            ),
            'customer' => array(
                'name'    => $order->get_formatted_billing_full_name(),
                'email'   => $order->get_billing_email(),
                'phone'   => $order->get_billing_phone(),
                'note'    => $order->get_customer_note(),
                'company' => $order->get_billing_company(),
            ),
        );
    }
}
