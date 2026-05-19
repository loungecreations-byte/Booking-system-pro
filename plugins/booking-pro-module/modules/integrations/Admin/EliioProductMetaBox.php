<?php

declare(strict_types=1);

namespace BSP\Integrations\Admin;

use WP_Post;

final class EliioProductMetaBox
{
    private const PRODUCT_ID = 115;

    /**
     * @var array<string, string>
     */
    private const META_LABELS = array(
        '_ddb_supplier_provider'              => 'Supplier provider',
        '_ddb_supplier_availability_mode'     => 'Availability mode',
        '_ddb_supplier_direct_booking'        => 'Direct booking',
        '_ddb_supplier_confirmation_required' => 'Supplier confirmation required',
        '_ddb_eliio_company_id'               => 'Eliio company ID',
        '_ddb_eliio_product_id'               => 'Eliio product ID',
        '_ddb_eliio_branch_id'                => 'Eliio branch ID',
        '_ddb_eliio_resource_id'              => 'Eliio resource ID',
        '_ddb_eliio_duration_id'              => 'Eliio duration ID',
    );

    public function register(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('add_meta_boxes_product', array($this, 'registerMetaBox'));
    }

    public function registerMetaBox(WP_Post $post): void
    {
        if (! $this->shouldRender((int) $post->ID)) {
            return;
        }

        add_meta_box(
            'ddb-eliio-product-status',
            __('Eliio availability koppeling', 'sbdp'),
            array($this, 'render'),
            'product',
            'side',
            'high'
        );
    }

    public function render(WP_Post $post): void
    {
        $productId = (int) $post->ID;
        $meta = $this->readMeta($productId);
        $missing = $this->missingRequiredMapping($meta);
        $availabilityUrl = $this->availabilityUrl($productId);

        echo '<p><strong>' . esc_html__('Status', 'sbdp') . ':</strong> ';
        if ($missing === array()) {
            echo '<span style="color:#0a7f2e;">' . esc_html__('Mapping compleet', 'sbdp') . '</span>';
        } else {
            echo '<span style="color:#b32d2e;">' . esc_html__('Mapping mist velden', 'sbdp') . '</span>';
        }
        echo '</p>';

        if ($missing !== array()) {
            echo '<p><strong>' . esc_html__('Ontbreekt', 'sbdp') . ':</strong> ' . esc_html(implode(', ', $missing)) . '</p>';
        }

        echo '<table class="widefat striped" style="margin-top:8px;">';
        echo '<tbody>';
        foreach (self::META_LABELS as $key => $label) {
            $value = isset($meta[$key]) ? (string) $meta[$key] : '';
            echo '<tr>';
            echo '<th scope="row" style="width:46%;font-weight:600;">' . esc_html($label) . '</th>';
            echo '<td><code style="white-space:normal;word-break:break-all;">' . esc_html($value !== '' ? $value : '-') . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';

        echo '<p style="margin-top:10px;">';
        echo esc_html__('Direct boeken blijft uit. WooCommerce blijft prijs/order/payment/tax truth. Eliio wordt alleen server-side gebruikt voor participant-sensitive availability pre-checks.', 'sbdp');
        echo '</p>';

        if ($availabilityUrl !== '') {
            echo '<p><a class="button button-secondary" href="' . esc_url($availabilityUrl) . '" target="_blank" rel="noopener noreferrer">';
            echo esc_html__('Test DDB availability endpoint', 'sbdp');
            echo '</a></p>';
        }
    }

    private function shouldRender(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }

        $provider = strtolower(trim((string) get_post_meta($productId, '_ddb_supplier_provider', true)));

        return $productId === self::PRODUCT_ID || $provider === 'eliio';
    }

    /**
     * @return array<string, string>
     */
    private function readMeta(int $productId): array
    {
        $meta = array();
        foreach (array_keys(self::META_LABELS) as $key) {
            $meta[$key] = (string) get_post_meta($productId, $key, true);
        }

        return $meta;
    }

    /**
     * @param array<string, string> $meta
     * @return list<string>
     */
    private function missingRequiredMapping(array $meta): array
    {
        $required = array(
            '_ddb_supplier_provider',
            '_ddb_supplier_availability_mode',
            '_ddb_supplier_direct_booking',
            '_ddb_supplier_confirmation_required',
            '_ddb_eliio_company_id',
            '_ddb_eliio_product_id',
            '_ddb_eliio_branch_id',
            '_ddb_eliio_resource_id',
        );

        $missing = array();
        foreach ($required as $key) {
            if (trim((string) ($meta[$key] ?? '')) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function availabilityUrl(int $productId): string
    {
        if (! function_exists('rest_url') || ! function_exists('add_query_arg')) {
            return '';
        }

        $date = function_exists('wp_date')
            ? wp_date('Y-m-d', strtotime('+7 days'))
            : gmdate('Y-m-d', strtotime('+7 days'));

        return add_query_arg(
            array(
                'product_id'   => $productId,
                'date'         => $date,
                'participants' => 10,
            ),
            rest_url('ddb/v1/supplier/eliio/availability')
        );
    }
}
