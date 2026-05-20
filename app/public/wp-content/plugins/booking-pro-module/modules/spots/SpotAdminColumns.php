<?php

declare(strict_types=1);

namespace BSP\Spots;

/**
 * SpotAdminColumns — Patch A
 *
 * Adds partner/supplier columns and filter dropdowns to the ddb_spot post list table.
 *
 * Columns added:
 *   ddb_partner_status       Partnerstatus badge
 *   ddb_supplier_provider    Provider label
 *   ddb_resource_control     Resource control label
 *   ddb_booking_authority    Booking authority label
 *   ddb_linked_products      Gekoppelde producten (count, forward-compatible with Patch B)
 *   ddb_supplier_tasks       Open supplier tasks (— in Patch A; Quote OS connects in Patch D)
 *   ddb_vendor_portal        Vendor portal badge
 *
 * Filters added (in Spots list header):
 *   Partnerstatus, Provider, Resource control, Vendor portal enabled
 *
 * SCOPE: admin-only, purely additive.
 * NO booking flow, NO BookingModeService, NO Quote OS, NO Vendor Portal touched.
 */
final class SpotAdminColumns
{
    private const POST_TYPE = 'ddb_spot';

    /** Partner status → badge colour class */
    private const STATUS_COLOURS = [
        'active'    => '#15803d',
        'preferred' => '#0e7490',
        'lead'      => '#b45309',
        'prospect'  => '#7c3aed',
        'paused'    => '#78716c',
        'blocked'   => '#b91c1c',
        'archived'  => '#44403c',
    ];

    /** Human-readable labels (duplicated from meta module for column rendering) */
    private const STATUS_LABELS = [
        'lead'      => 'Lead',
        'prospect'  => 'Prospect',
        'active'    => 'Actief',
        'preferred' => 'Preferred',
        'paused'    => 'Gepauzeerd',
        'blocked'   => 'Geblokkeerd',
        'archived'  => 'Gearchiveerd',
    ];

    private const PROVIDER_LABELS = [
        'none'        => '—',
        'manual'      => 'Handmatig',
        'eliio'       => 'Eliio',
        'recras'      => 'Recras',
        'leisureking' => 'LeisureKing',
        'custom'      => 'Custom',
    ];

    private const RESOURCE_CONTROL_LABELS = [
        'owned'                   => 'Owned',
        'allocated'               => 'Allocated',
        'external_live_check'     => 'Live check',
        'external_confirmed_only' => 'Bevestiging vereist',
        'manual'                  => 'Handmatig',
    ];

    private const BOOKING_AUTHORITY_LABELS = [
        'ddb'      => 'DDB',
        'supplier' => 'Supplier',
    ];

    public function init(): void
    {
        add_filter('manage_' . self::POST_TYPE . '_posts_columns',        [$this, 'addColumns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column',  [$this, 'renderColumn'], 10, 2);
        add_filter('manage_edit-' . self::POST_TYPE . '_sortable_columns', [$this, 'sortableColumns']);
        add_action('restrict_manage_posts', [$this, 'renderFilters'], 10, 1);
        add_action('pre_get_posts',         [$this, 'applyFilters']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Column registration
    // ─────────────────────────────────────────────────────────────────────────

    public function addColumns(array $columns): array
    {
        // Remove default date column so we can re-insert after our columns
        $date = $columns['date'] ?? null;
        unset($columns['date']);

        $columns['ddb_partner_status']    = __('Partnerstatus', 'sbdp');
        $columns['ddb_supplier_provider'] = __('Provider', 'sbdp');
        $columns['ddb_resource_control']  = __('Resource control', 'sbdp');
        $columns['ddb_booking_authority'] = __('Booking authority', 'sbdp');
        $columns['ddb_linked_products']   = __('Producten', 'sbdp');
        $columns['ddb_supplier_tasks']    = __('Open tasks', 'sbdp');
        $columns['ddb_vendor_portal']     = __('Vendor portal', 'sbdp');

        if ($date !== null) {
            $columns['date'] = $date;
        }

        return $columns;
    }

    public function sortableColumns(array $columns): array
    {
        $columns['ddb_partner_status']    = 'ddb_partner_status';
        $columns['ddb_supplier_provider'] = 'ddb_supplier_provider';
        return $columns;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Column rendering
    // ─────────────────────────────────────────────────────────────────────────

    public function renderColumn(string $column, int $postId): void
    {
        switch ($column) {
            case 'ddb_partner_status':
                $this->renderStatusBadge($postId);
                break;

            case 'ddb_supplier_provider':
                $v = (string) get_post_meta($postId, '_ddb_supplier_provider', true);
                echo esc_html(self::PROVIDER_LABELS[$v] ?? '—');
                break;

            case 'ddb_resource_control':
                $v = (string) get_post_meta($postId, '_ddb_resource_control', true);
                echo esc_html(self::RESOURCE_CONTROL_LABELS[$v] ?? '—');
                break;

            case 'ddb_booking_authority':
                $v = (string) get_post_meta($postId, '_ddb_booking_authority', true);
                echo esc_html(self::BOOKING_AUTHORITY_LABELS[$v] ?? '—');
                break;

            case 'ddb_linked_products':
                $this->renderLinkedProducts($postId);
                break;

            case 'ddb_supplier_tasks':
                // Patch D: Quote OS supplier_spot_id not yet available.
                echo '<span style="color:#78716c">—</span>';
                break;

            case 'ddb_vendor_portal':
                $this->renderVendorPortalBadge($postId);
                break;
        }
    }

    private function renderStatusBadge(int $postId): void
    {
        $status = (string) get_post_meta($postId, '_ddb_partner_status', true);
        if ($status === '') {
            echo '<span style="color:#78716c">—</span>';
            return;
        }
        $label  = self::STATUS_LABELS[$status]  ?? $status;
        $colour = self::STATUS_COLOURS[$status] ?? '#78716c';
        printf(
            '<span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;background:%s22;color:%s;border:1px solid %s44">%s</span>',
            esc_attr($colour),
            esc_attr($colour),
            esc_attr($colour),
            esc_html($label)
        );
    }

    private function renderLinkedProducts(int $postId): void
    {
        // Forward-compatible with Patch B (_ddb_supplier_spot_id on product).
        // Returns 0/— gracefully when meta key does not yet exist on any product.
        $args = [
            'post_type'      => 'product',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => '_ddb_supplier_spot_id',
                    'value'   => $postId,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ];
        $ids   = get_posts($args);
        $count = count($ids);

        if ($count === 0) {
            echo '<span style="color:#78716c">—</span>';
            return;
        }

        $url = add_query_arg([
            'post_type'              => 'product',
            'meta_key'               => '_ddb_supplier_spot_id',
            'meta_value'             => $postId,
        ], admin_url('edit.php'));

        printf(
            '<a href="%s"><strong>%d</strong></a>',
            esc_url($url),
            $count
        );
    }

    private function renderVendorPortalBadge(int $postId): void
    {
        $v = (string) get_post_meta($postId, '_ddb_vendor_portal_enabled', true);
        if ($v === 'yes') {
            echo '<span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0">&#10003; Aan</span>';
        } else {
            echo '<span style="color:#78716c;font-size:11px">Uit</span>';
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Filter dropdowns
    // ─────────────────────────────────────────────────────────────────────────

    public function renderFilters(string $postType): void
    {
        if ($postType !== self::POST_TYPE) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter, no state mutation
        $selectedStatus   = isset($_GET['ddb_filter_status'])   ? sanitize_text_field(wp_unslash($_GET['ddb_filter_status']))   : '';
        $selectedProvider = isset($_GET['ddb_filter_provider']) ? sanitize_text_field(wp_unslash($_GET['ddb_filter_provider'])) : '';
        $selectedControl  = isset($_GET['ddb_filter_control'])  ? sanitize_text_field(wp_unslash($_GET['ddb_filter_control']))  : '';
        $selectedPortal   = isset($_GET['ddb_filter_portal'])   ? sanitize_text_field(wp_unslash($_GET['ddb_filter_portal']))   : '';
        // phpcs:enable

        // Partnerstatus filter
        $this->renderFilterSelect(
            'ddb_filter_status',
            __('Alle statussen', 'sbdp'),
            self::STATUS_LABELS,
            $selectedStatus
        );

        // Provider filter
        $this->renderFilterSelect(
            'ddb_filter_provider',
            __('Alle providers', 'sbdp'),
            self::PROVIDER_LABELS,
            $selectedProvider
        );

        // Resource control filter
        $this->renderFilterSelect(
            'ddb_filter_control',
            __('Alle resource control', 'sbdp'),
            self::RESOURCE_CONTROL_LABELS,
            $selectedControl
        );

        // Vendor portal filter
        $this->renderFilterSelect(
            'ddb_filter_portal',
            __('Vendor portal: alle', 'sbdp'),
            ['yes' => 'Aan', 'no' => 'Uit'],
            $selectedPortal
        );
    }

    private function renderFilterSelect(string $name, string $placeholder, array $options, string $selected): void
    {
        printf('<select name="%s">', esc_attr($name));
        printf('<option value="">%s</option>', esc_html($placeholder));
        foreach ($options as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $value),
                selected($selected, $value, false),
                esc_html($label)
            );
        }
        echo '</select> ';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Apply filters to WP_Query
    // ─────────────────────────────────────────────────────────────────────────

    public function applyFilters(\WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        if (($query->get('post_type') ?: '') !== self::POST_TYPE) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter
        $filterStatus   = isset($_GET['ddb_filter_status'])   ? sanitize_text_field(wp_unslash($_GET['ddb_filter_status']))   : '';
        $filterProvider = isset($_GET['ddb_filter_provider']) ? sanitize_text_field(wp_unslash($_GET['ddb_filter_provider'])) : '';
        $filterControl  = isset($_GET['ddb_filter_control'])  ? sanitize_text_field(wp_unslash($_GET['ddb_filter_control']))  : '';
        $filterPortal   = isset($_GET['ddb_filter_portal'])   ? sanitize_text_field(wp_unslash($_GET['ddb_filter_portal']))   : '';
        // phpcs:enable

        $metaQuery = $query->get('meta_query') ?: [];

        if ($filterStatus !== '') {
            $metaQuery[] = ['key' => '_ddb_partner_status', 'value' => $filterStatus, 'compare' => '='];
        }
        if ($filterProvider !== '') {
            $metaQuery[] = ['key' => '_ddb_supplier_provider', 'value' => $filterProvider, 'compare' => '='];
        }
        if ($filterControl !== '') {
            $metaQuery[] = ['key' => '_ddb_resource_control', 'value' => $filterControl, 'compare' => '='];
        }
        if ($filterPortal !== '') {
            $metaQuery[] = ['key' => '_ddb_vendor_portal_enabled', 'value' => $filterPortal, 'compare' => '='];
        }

        if (count($metaQuery) > 0) {
            $query->set('meta_query', $metaQuery);
        }

        // Sortable column support
        $orderby = $query->get('orderby');
        if ($orderby === 'ddb_partner_status') {
            $query->set('meta_key', '_ddb_partner_status');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'ddb_supplier_provider') {
            $query->set('meta_key', '_ddb_supplier_provider');
            $query->set('orderby', 'meta_value');
        }
    }
}
