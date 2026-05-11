<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Domain;

use SBDP\Admin\Bookable\SBDP_Admin_Bookable_Meta;
use WC_Product;

use function __;
use function array_filter;
use function array_map;
use function array_values;
use function class_exists;
use function count;
use function function_exists;
use function get_edit_post_link;
use function get_post_meta;
use function get_post_status;
use function html_entity_decode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function sanitize_text_field;
use function stripos;
use function strtolower;
use function trim;
use function wc_get_price_to_display;
use function wc_get_product;
use function wc_get_products;
use function wc_price;
use function wp_strip_all_tags;

final class BookableProductLookupService
{
    private const PRODUCT_TYPE = 'bookable_service';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query = '', int $limit = 12): array
    {
        if (! function_exists('wc_get_products')) {
            return array();
        }

        $query = trim($query);
        $limit = max(1, min(25, $limit));
        $args = array(
            'status' => 'publish',
            'limit' => $query !== '' ? 200 : $limit,
            'type' => self::PRODUCT_TYPE,
            'orderby' => 'title',
            'order' => 'ASC',
            'return' => 'objects',
        );

        $products = wc_get_products($args);
        if (! is_array($products)) {
            return array();
        }

        $results = array();
        foreach ($products as $product) {
            if (! $product instanceof WC_Product) {
                continue;
            }

             if ($query !== '') {
                $haystacks = array(
                    strtolower((string) $product->get_name()),
                    strtolower((string) $product->get_sku()),
                );
                $matched = false;
                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && stripos($haystack, strtolower($query)) !== false) {
                        $matched = true;
                        break;
                    }
                }

                if (! $matched) {
                    continue;
                }
            }

            $snapshot = $this->buildSnapshot($product);
            if ($snapshot === null) {
                continue;
            }

            $results[] = $snapshot;
            if (count($results) >= $limit) {
                break;
            }
        }

        return array_values($results);
    }

    /**
     * @param array<int, int> $productIds
     * @return array<int, array<string, mixed>>
     */
    public function getSnapshots(array $productIds): array
    {
        $snapshots = array();
        foreach (array_values(array_filter(array_map('intval', $productIds))) as $productId) {
            $snapshot = $this->getSnapshot($productId);
            if ($snapshot === null) {
                continue;
            }

            $snapshots[$productId] = $snapshot;
        }

        return $snapshots;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSnapshot(int $productId): ?array
    {
        if ($productId <= 0 || ! function_exists('wc_get_product')) {
            return null;
        }

        $product = wc_get_product($productId);
        if (! $product instanceof WC_Product) {
            return null;
        }

        return $this->buildSnapshot($product);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suggestForProduct(int $productId, int $limit = 6): array
    {
        $anchor = $this->getSnapshot($productId);
        if ($anchor === null || ! function_exists('get_post_meta') || ! function_exists('wc_get_products')) {
            return array();
        }

        $suggestedIds = array();
        $combi = get_post_meta($productId, '_sbdp_combi_deals', true);
        if (is_array($combi)) {
            foreach ($combi as $candidate) {
                $candidateId = (int) $candidate;
                if ($candidateId > 0 && $candidateId !== $productId) {
                    $suggestedIds[] = $candidateId;
                }
            }
        }

        $results = $this->getSnapshots(array_values(array_unique($suggestedIds)));
        if (count($results) >= $limit) {
            return array_values(array_slice($results, 0, $limit));
        }

        $anchorProduct = wc_get_product($productId);
        $fallbackQuery = $anchorProduct instanceof WC_Product ? (string) $anchorProduct->get_name() : '';
        $fallback = $this->search($fallbackQuery, max(6, $limit * 2));
        foreach ($fallback as $item) {
            if ((int) ($item['id'] ?? 0) === $productId) {
                continue;
            }
            $results[(int) $item['id']] = $item;
            if (count($results) >= $limit) {
                break;
            }
        }

        return array_values(array_slice($results, 0, $limit));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildSnapshot(WC_Product $product): ?array
    {
        if (! $product->exists()) {
            return null;
        }

        $type = (string) $product->get_type();
        if ($type !== self::PRODUCT_TYPE) {
            return null;
        }

        $productId = $product->get_id();
        $settings = $this->resolveBookableSettings($productId);
        $priceHtml = trim(html_entity_decode(wp_strip_all_tags((string) $product->get_price_html()), ENT_QUOTES, 'UTF-8'));
        if ($priceHtml === '' && function_exists('wc_price')) {
            $priceHtml = html_entity_decode(wp_strip_all_tags((string) wc_price((float) wc_get_price_to_display($product))), ENT_QUOTES, 'UTF-8');
        }

        $durationMinutes = $this->resolveDurationMinutes($settings, $productId);
        $minPeople = max(1, (int) ($settings['people_min'] ?? get_post_meta($productId, '_sbdp_people_min', true) ?: 1));
        $maxPeople = max($minPeople, (int) ($settings['people_max'] ?? get_post_meta($productId, '_sbdp_people_max', true) ?: $minPeople));
        $peopleEnabled = ! empty($settings['people_enabled']) || $maxPeople > 1;
        $taxStatus = (string) $product->get_tax_status();
        $taxClass = (string) $product->get_tax_class();
        $allowedDays = $this->resolveAllowedDays($settings, $productId);
        $minAdvance = max(0, (int) ($settings['booking_min_advance'] ?? get_post_meta($productId, '_sbdp_booking_min_advance', true) ?: 0));
        $maxAdvance = max(0, (int) ($settings['booking_max_advance'] ?? get_post_meta($productId, '_sbdp_booking_max_advance', true) ?: 365));
        $status = (string) get_post_status($productId);
        $stockStatus = method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : '';

        return array(
            'id' => $productId,
            'title' => sanitize_text_field($product->get_name()),
            'sku' => sanitize_text_field((string) $product->get_sku()),
            'type' => $type,
            'status' => $status,
            'stock_status' => $stockStatus,
            'price_label' => $priceHtml !== '' ? $priceHtml : (string) __('Geen prijs', 'sbdp'),
            'price_value' => (float) wc_get_price_to_display($product),
            'tax_label' => $this->formatTaxLabel($taxStatus, $taxClass),
            'duration_minutes' => $durationMinutes,
            'duration_label' => $durationMinutes > 0 ? sprintf(__('%d min', 'sbdp'), $durationMinutes) : (string) __('Duur niet ingesteld', 'sbdp'),
            'people_label' => $peopleEnabled
                ? sprintf(__('%1$d-%2$d personen', 'sbdp'), $minPeople, $maxPeople)
                : (string) __('Per stuk / geen groepslimiet', 'sbdp'),
            'availability_label' => $this->formatAvailabilityLabel($allowedDays, $minAdvance, $maxAdvance),
            'availability_days' => $allowedDays,
            'booking_window' => array(
                'min_advance' => $minAdvance,
                'max_advance' => $maxAdvance,
            ),
            'bookable' => $status === 'publish',
            'edit_url' => (string) get_edit_post_link($productId, 'raw'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveBookableSettings(int $productId): array
    {
        if (class_exists(SBDP_Admin_Bookable_Meta::class)) {
            $settings = SBDP_Admin_Bookable_Meta::prepare_meta_for_rest($productId);
            if (is_array($settings)) {
                return $settings;
            }
        }

        return array();
    }

    private function resolveDurationMinutes(array $settings, int $productId): int
    {
        $duration = (int) ($settings['booking_min_duration'] ?? get_post_meta($productId, '_sbdp_duration', true) ?: 0);
        $unit = strtolower((string) ($settings['booking_duration_type'] ?? get_post_meta($productId, '_sbdp_duration_unit', true) ?: 'minutes'));

        return match ($unit) {
            'days', 'day' => $duration * 1440,
            'hours', 'hour' => $duration * 60,
            default => $duration,
        };
    }

    /**
     * @return array<int, string>
     */
    private function resolveAllowedDays(array $settings, int $productId): array
    {
        $days = $settings['booking_allowed_start_days'] ?? get_post_meta($productId, '_sbdp_allowed_start_days', true);
        if (! is_array($days)) {
            return array();
        }

        return array_values(
            array_filter(
                array_map(
                    static fn ($day): string => sanitize_text_field((string) $day),
                    $days
                )
            )
        );
    }

    private function formatAvailabilityLabel(array $allowedDays, int $minAdvance, int $maxAdvance): string
    {
        $dayLabels = array(
            'mon' => 'ma',
            'tue' => 'di',
            'wed' => 'wo',
            'thu' => 'do',
            'fri' => 'vr',
            'sat' => 'za',
            'sun' => 'zo',
        );

        $days = array();
        foreach ($allowedDays as $day) {
            $days[] = $dayLabels[$day] ?? $day;
        }

        $parts = array();
        if ($days !== array()) {
            $parts[] = implode(', ', $days);
        }

        $parts[] = sprintf(__('%1$d-%2$d dagen vooruit', 'sbdp'), $minAdvance, $maxAdvance > 0 ? $maxAdvance : 365);

        return implode(' · ', $parts);
    }

    private function formatTaxLabel(string $taxStatus, string $taxClass): string
    {
        if ($taxStatus !== 'taxable') {
            return (string) __('Geen btw', 'sbdp');
        }

        $mode = function_exists('wc_prices_include_tax') && wc_prices_include_tax()
            ? (string) __('Prijs incl. btw', 'sbdp')
            : (string) __('Prijs excl. btw', 'sbdp');

        if ($taxClass === '') {
            return $mode;
        }

        return $mode . ' · ' . $taxClass;
    }
}
