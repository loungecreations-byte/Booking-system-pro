<?php

declare(strict_types=1);

namespace BSP\Sales\Vendors;

use WP_Error;
use wpdb;

use const ARRAY_A;

use function absint;
use function array_diff;
use function array_fill;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function current_time;
use function is_array;
use function is_string;
use function json_decode;
use function number_format;
use function wp_json_encode;
use function __;

final class VendorRepository
{
    private const JSON_FIELDS = array('channels', 'capabilities', 'metadata');

    private ?wpdb $db;

    public function __construct(?wpdb $db = null)
    {
        if ($db instanceof wpdb) {
            $this->db = $db;
            return;
        }

        global $wpdb;
        $this->db = $wpdb instanceof wpdb ? $wpdb : null;
    }

    public function getVendors(array $args = array()): array
    {
        if (! $this->db instanceof wpdb) {
            return array();
        }

        $defaults = array(
            'status'   => null,
            'statuses' => array(),
            'search'   => '',
            'limit'    => 50,
            'offset'   => 0,
        );
        $args = array_merge($defaults, $args);

        $conditions = array();
        $values     = array();

        $statuses = $this->normaliseStatuses($args);
        if ($statuses !== array()) {
            $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
            $conditions[] = "status IN ({$placeholders})";
            $values       = array_merge($values, $statuses);
        }

        if (is_string($args['search']) && $args['search'] !== '') {
            $conditions[] = 'name LIKE %s';
            $values[]     = '%' . $this->db->esc_like($args['search']) . '%';
        }

        $where = $conditions !== array() ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $limit  = max(1, (int) $args['limit']);
        $offset = max(0, (int) $args['offset']);

        $values[] = $limit;
        $values[] = $offset;

        $sql      = sprintf(
            'SELECT * FROM %s %s ORDER BY name ASC LIMIT %%d OFFSET %%d',
            $this->getVendorTable(),
            $where
        );
        $prepared = $this->db->prepare($sql, $values);
        $rows     = $this->db->get_results($prepared, ARRAY_A) ?: array();

        return array_map(array($this, 'hydrate'), $rows);
    }

    public function getVendor(int $id): ?array
    {
        if (! $this->db instanceof wpdb) {
            return null;
        }

        $query = 'SELECT * FROM ' . $this->getVendorTable() . ' WHERE id = %d';
        $row   = $this->db->get_row(
            $this->db->prepare($query, absint($id)),
            ARRAY_A
        );

        return $row ? $this->hydrate($row) : null;
    }

    public function getVendorBySlug(string $slug): ?array
    {
        if (! $this->db instanceof wpdb) {
            return null;
        }

        $query = 'SELECT * FROM ' . $this->getVendorTable() . ' WHERE slug = %s';
        $row   = $this->db->get_row(
            $this->db->prepare($query, $slug),
            ARRAY_A
        );

        return $row ? $this->hydrate($row) : null;
    }

    public function createVendor(array $data)
    {
        if (! $this->db instanceof wpdb) {
            return new WP_Error('bsp_sales_db_unavailable', __('Database connection unavailable.', 'sbdp'));
        }

        $payload = $this->preparePayload($data, true);
        $result  = $this->db->insert($this->getVendorTable(), $payload);

        if (! $result) {
            return new WP_Error('bsp_sales_vendor_create_failed', __('Unable to create vendor record.', 'sbdp'));
        }

        return $this->getVendor((int) $this->db->insert_id);
    }

    public function updateVendor(int $id, array $data)
    {
        if (! $this->db instanceof wpdb) {
            return new WP_Error('bsp_sales_db_unavailable', __('Database connection unavailable.', 'sbdp'));
        }

        $payload = $this->preparePayload($data, false);
        $updated = $this->db->update(
            $this->getVendorTable(),
            $payload,
            array('id' => absint($id))
        );

        if ($updated === false) {
            return new WP_Error('bsp_sales_vendor_update_failed', __('Unable to update vendor record.', 'sbdp'));
        }

        return $this->getVendor($id);
    }

    public function setVendorStatus(int $id, string $status)
    {
        if (! $this->db instanceof wpdb) {
            return new WP_Error('bsp_sales_db_unavailable', __('Database connection unavailable.', 'sbdp'));
        }

        $updated = $this->db->update(
            $this->getVendorTable(),
            array(
                'status'     => $status,
                'updated_at' => current_time('mysql', true),
            ),
            array('id' => absint($id))
        );

        if ($updated === false) {
            return new WP_Error('bsp_sales_vendor_status_failed', __('Unable to update vendor status.', 'sbdp'));
        }

        return $this->getVendor($id);
    }

    public function getVendorProductIds(int $vendorId): array
    {
        if (! $this->db instanceof wpdb) {
            return array();
        }

        $query = 'SELECT product_id FROM ' . $this->getProductTable() . ' WHERE vendor_id = %d ORDER BY product_id ASC';
        $ids   = $this->db->get_col($this->db->prepare($query, absint($vendorId))) ?: array();

        return array_map('absint', $ids);
    }

    public function syncVendorProducts(int $vendorId, array $productIds): array
    {
        if (! $this->db instanceof wpdb) {
            return array(
                'attached' => array(),
                'detached' => array(),
            );
        }

        $vendorId   = absint($vendorId);
        $productIds = array_values(array_unique(array_map('absint', $productIds)));
        $productIds = array_filter($productIds);

        $current = $this->getVendorProductIds($vendorId);
        $attach  = array_values(array_diff($productIds, $current));
        $detach  = array_values(array_diff($current, $productIds));

        if ($attach !== array()) {
            $this->assignVendorProducts($vendorId, $attach);
        }

        if ($detach !== array()) {
            $this->detachVendorProducts($vendorId, $detach);
        }

        return array(
            'attached' => $attach,
            'detached' => $detach,
        );
    }

    private function assignVendorProducts(int $vendorId, array $productIds): void
    {
        if (! $this->db instanceof wpdb) {
            return;
        }

        $timestamp = current_time('mysql', true);
        $table     = $this->getProductTable();

        $rows   = array();
        $values = array();

        foreach ($productIds as $productId) {
            $productId = absint($productId);
            if ($productId <= 0) {
                continue;
            }

            $rows[]   = '(%d, %d, %s)';
            $values[] = $productId;
            $values[] = $vendorId;
            $values[] = $timestamp;
        }

        if ($rows === array()) {
            return;
        }

        $sql = 'INSERT INTO ' . $table . ' (product_id, vendor_id, updated_at) VALUES ' . implode(', ', $rows)
            . ' ON DUPLICATE KEY UPDATE vendor_id = VALUES(vendor_id), updated_at = VALUES(updated_at)';

        $this->db->query($this->db->prepare($sql, $values));
    }

    private function detachVendorProducts(int $vendorId, array $productIds): void
    {
        if (! $this->db instanceof wpdb) {
            return;
        }

        $productIds = array_values(array_filter(array_map('absint', $productIds)));
        if ($productIds === array()) {
            return;
        }

        $timestamp    = current_time('mysql', true);
        $placeholders = implode(', ', array_fill(0, count($productIds), '%d'));
        $parameters   = array_merge(array($timestamp, absint($vendorId)), $productIds);

        $sql = 'UPDATE ' . $this->getProductTable()
            . ' SET vendor_id = NULL, updated_at = %s WHERE vendor_id = %d AND product_id IN (' . $placeholders . ')';

        $this->db->query($this->db->prepare($sql, $parameters));
    }

    private function getVendorTable(): string
    {
        return $this->db instanceof wpdb ? $this->db->prefix . 'bsp_vendors' : '';
    }

    private function getProductTable(): string
    {
        return $this->db instanceof wpdb ? $this->db->prefix . 'bsp_products' : '';
    }

    private function preparePayload(array $data, bool $isCreate): array
    {
        $payload = array(
            'name'            => $data['name'] ?? '',
            'slug'            => $data['slug'] ?? '',
            'status'          => $data['status'] ?? 'pending',
            'channels'        => wp_json_encode($data['channels'] ?? array()),
            'capabilities'    => wp_json_encode($data['capabilities'] ?? array()),
            'payout_terms'    => $data['payout_terms'] ?? null,
            'commission_rate' => isset($data['commission_rate']) ? number_format((float) $data['commission_rate'], 2, '.', '') : null,
            'contact_name'    => $data['contact_name'] ?? null,
            'contact_email'   => $data['contact_email'] ?? null,
            'contact_phone'   => $data['contact_phone'] ?? null,
            'webhook_url'     => $data['webhook_url'] ?? null,
            'pricing_currency' => $data['pricing_currency'] ?? null,
            'pricing_base_rate' => isset($data['pricing_base_rate']) && $data['pricing_base_rate'] !== null && $data['pricing_base_rate'] !== ''
                ? number_format((float) $data['pricing_base_rate'], 2, '.', '')
                : null,
            'pricing_markup_type' => $data['pricing_markup_type'] ?? null,
            'pricing_markup_value' => isset($data['pricing_markup_value']) && $data['pricing_markup_value'] !== null && $data['pricing_markup_value'] !== ''
                ? number_format((float) $data['pricing_markup_value'], 2, '.', '')
                : null,
            'metadata'        => wp_json_encode($data['metadata'] ?? array()),
            'updated_at'      => current_time('mysql', true),
        );

        if ($isCreate) {
            $payload['created_at'] = current_time('mysql', true);
        }

        return $payload;
    }

    private function hydrate(array $row): array
    {
        foreach (self::JSON_FIELDS as $field) {
            if (isset($row[$field])) {
                $decoded     = json_decode((string) $row[$field], true);
                $row[$field] = is_array($decoded) ? $decoded : array();
            }
        }

        $row['id'] = absint($row['id'] ?? 0);

        if (isset($row['commission_rate']) && $row['commission_rate'] !== null) {
            $row['commission_rate'] = (float) $row['commission_rate'];
        }

        if (isset($row['pricing_base_rate']) && $row['pricing_base_rate'] !== null) {
            $row['pricing_base_rate'] = (float) $row['pricing_base_rate'];
        }

        if (isset($row['pricing_markup_value']) && $row['pricing_markup_value'] !== null) {
            $row['pricing_markup_value'] = (float) $row['pricing_markup_value'];
        }

        return $row;
    }

    private function normaliseStatuses(array $args): array
    {
        if (! empty($args['statuses']) && is_array($args['statuses'])) {
            return array_map('strval', array_filter($args['statuses']));
        }

        if (is_string($args['status']) && $args['status'] !== '') {
            return array((string) $args['status']);
        }

        return array();
    }
}



