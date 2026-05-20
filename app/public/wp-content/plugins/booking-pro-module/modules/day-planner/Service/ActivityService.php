<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

use BSPModule\Core\Product\ProductMeta;
use BSPModule\Core\Services\BookingTruthRuntimeService;
use SBDP\Pricing\PricingService as CorePricingService;
use SBDP\Services\DemoDataSeeder;
use SBDP\BookingEngine;

final class ActivityService
{
    private const EMPTY_NOTICE_TRANSIENT = 'sbdp_planner_catalog_empty';
    private const FALLBACK_QUERY_LIMIT = 25;

    /**
     * @param array<string, mixed> $filters
     * @param bool                 $includeExample
     *
     * @return array<int, array<string, mixed>>
     */
    public function listActivities(array $filters = [], bool $includeExample = true): array
    {
        $filters = $this->normaliseFilters($filters);

        $currency = $this->resolveCurrency();
        $items    = $this->buildCatalog($filters, $currency);
        $items    = array_values(
            array_map(
                fn(array $item): array => $this->applyDiscoveryEnvelope($item, $filters, $currency),
                $items
            )
        );

        if (! empty($filters['exclude_unavailable'])) {
            $items = array_values(
                array_filter(
                    $items,
                    static fn(array $item): bool => (string) ($item['booking_capability'] ?? '') !== BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE
                )
            );
        }

        if (! empty($filters['route_intent'])) {
            $items = array_values(
                array_filter(
                    $items,
                    static fn(array $item): bool => (string) ($item['route_intent'] ?? '') === (string) $filters['route_intent']
                )
            );
        }

        if ($items === array()) {
            $this->logCatalogEvent('primary_empty');
            $this->toggleEmptyNotice(true);
        } else {
            $this->toggleEmptyNotice(false);
            $this->clearAutoSeedLock();
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductCollection(array $filters, string $currency): array
    {
        $items = array();

        if (\function_exists('wc_get_products')) {
            $products = \wc_get_products($this->buildWooCommerceQueryArgs($filters));

            foreach ($products as $product) {
                if (! is_object($product) || ! method_exists($product, 'get_id')) {
                    continue;
                }

                $payload = $this->formatWooCommerceProduct($product, $currency);
                if ($payload !== null) {
                    $items[] = $payload;
                }
            }

            return $items;
        }

        if (! \function_exists('get_posts')) {
            return $items;
        }

        $posts = \get_posts($this->buildPostQueryArgs($filters));
        foreach ($posts as $post) {
            if ($post instanceof \WP_Post) {
                $items[] = $this->formatPostProduct($post, $currency);
            }
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param int[]                            $includeIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function ensureIncludedProducts(array $items, array $includeIds, string $currency): array
    {
        if ($includeIds === array()) {
            return $items;
        }

        $existing = array();
        foreach ($items as $item) {
            $id = isset($item['id']) ? (int) $item['id'] : 0;
            if ($id > 0) {
                $existing[$id] = true;
            }
        }

        foreach ($includeIds as $includeId) {
            $includeId = (int) $includeId;
            if ($includeId <= 0 || isset($existing[$includeId])) {
                continue;
            }

            $payload = null;

            if (\function_exists('wc_get_product')) {
                $product = \wc_get_product($includeId);
                if ($product) {
                    $payload = $this->formatWooCommerceProduct($product, $currency);
                }
            }

            if ($payload === null && \function_exists('get_post')) {
                $post = \get_post($includeId);
                if ($post instanceof \WP_Post) {
                    $payload = $this->formatPostProduct($post, $currency);
                }
            }

            if ($payload !== null) {
                $items[] = $payload;
                $existing[$includeId] = true;
            }
        }

        return $this->reorderByInclude($items, $includeIds);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param int[]                            $includeIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function reorderByInclude(array $items, array $includeIds): array
    {
        if ($includeIds === array()) {
            return $items;
        }

        $priorityMap = array();
        foreach ($includeIds as $position => $id) {
            $priorityMap[(int) $id] = (int) $position;
        }

        $withIndex = array();
        foreach ($items as $index => $item) {
            $id = isset($item['id']) ? (int) $item['id'] : 0;
            $priority = $priorityMap[$id] ?? PHP_INT_MAX;
            $withIndex[] = array(
                'priority' => $priority,
                'index'    => $index,
                'item'     => $item,
            );
        }

        usort(
            $withIndex,
            static function (array $left, array $right): int {
                if ($left['priority'] === $right['priority']) {
                    return $left['index'] <=> $right['index'];
                }

                return $left['priority'] <=> $right['priority'];
            }
        );

        return array_values(array_map(static fn(array $entry) => $entry['item'], $withIndex));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePrimaryContext(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $location = $this->resolveLocation($productId);

        $categories = array();
        if (\function_exists('wp_get_post_terms')) {
            $terms = \wp_get_post_terms($productId, 'product_cat', array('fields' => 'slugs'));
            if (is_array($terms)) {
                $categories = array_values(
                    array_filter(
                        array_map(
                            static fn($value) => is_string($value) ? strtolower($value) : '',
                            $terms
                        )
                    )
                );
            }
        }

        return array(
            'product_id' => $productId,
            'location'   => $location !== null ? strtolower($location) : '',
            'categories' => $categories,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed>             $filters
     * @param array<string, mixed>|null        $primaryContext
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyMatchFilters(array $items, array $filters, ?array $primaryContext): array
    {
        if ($primaryContext === null) {
            return $items;
        }

        $matchBy = $filters['match_by'] ?? array();
        if ($matchBy === array()) {
            return $items;
        }

        $includeLookup = array_flip($filters['include_ids'] ?? array());
        $targetLocation = isset($primaryContext['location']) ? (string) $primaryContext['location'] : '';
        $targetCategories = isset($primaryContext['categories']) && is_array($primaryContext['categories'])
            ? $primaryContext['categories']
            : array();

        return array_values(
            array_filter(
                $items,
                static function (array $item) use ($matchBy, $includeLookup, $targetLocation, $targetCategories): bool {
                    $id = isset($item['id']) ? (int) $item['id'] : 0;
                    if (isset($includeLookup[$id])) {
                        return true;
                    }

                    if (in_array('location', $matchBy, true) && $targetLocation !== '') {
                        $itemLocation = '';
                        if (isset($item['location'])) {
                            $itemLocation = strtolower((string) $item['location']);
                        }

                        if ($itemLocation !== $targetLocation) {
                            return false;
                        }
                    }

                    if (in_array('category', $matchBy, true) && $targetCategories !== array()) {
                        $itemCategories = array();

                        if (isset($item['category_slugs']) && is_array($item['category_slugs'])) {
                            foreach ($item['category_slugs'] as $slug) {
                                if (! is_string($slug) || $slug === '') {
                                    continue;
                                }
                                $itemCategories[] = strtolower($slug);
                            }
                        } elseif (isset($item['categories']) && is_array($item['categories'])) {
                            foreach ($item['categories'] as $name) {
                                if (! is_string($name) || $name === '') {
                                    continue;
                                }
                                $itemCategories[] = strtolower($name);
                            }
                        }

                        if (! array_intersect($targetCategories, array_unique($itemCategories))) {
                            return false;
                        }
                    }

                    return true;
                }
            )
        );
    }

    private function resolveLocation(int $productId): ?string
    {
        $raw = get_post_meta($productId, '_sbdp_booking_location', true);

        if (is_array($raw)) {
            if (isset($raw['address'])) {
                $raw = $raw['address'];
            } elseif (isset($raw['label'])) {
                $raw = $raw['label'];
            } else {
                $raw = reset($raw);
            }
        }

        if (! is_string($raw)) {
            return null;
        }

        $location = trim($raw);

        return $location !== '' ? $location : null;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function appendExampleActivity(array $items, string $currency): array
    {
        return $items;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    private function buildWooCommerceQueryArgs(array $filters): array
    {
        $limit = 30;
        if (! empty($filters['include_ids'])) {
            $limit = max($limit, count($filters['include_ids']) + 5);
        }

        $args = [
            'limit'   => $limit,
            'status'  => 'publish',
            'orderby' => 'date',
            'order'   => 'DESC',
        ];

        $productTypes = [
            'bookable_service',
            'bookable_product',
            'private_tour',
            'simple',
            'variable',
            'composite',
            'bundle',
        ];

        /**
         * Allow third parties to extend the list of product types exposed to the planner.
         *
         * @param array<int, string> $productTypes
         * @param array<string, mixed> $filters
         */
        $productTypes = apply_filters('sbdp/day_planner/product_types', $productTypes, $filters);

        if (! empty($productTypes)) {
            $args['type'] = array_values(array_unique(array_map('strval', $productTypes)));
        }

        if ($filters['only_available']) {
            $args['stock_status'] = 'instock';
        }

        if ($filters['search'] !== '') {
            $args['search'] = '*' . $filters['search'] . '*';
        }

        if (! empty($filters['categories'])) {
            $args['category'] = $filters['categories'];
        }

        if ($filters['price_min'] !== null) {
            $args['min_price'] = $filters['price_min'];
        }

        if ($filters['price_max'] !== null) {
            $args['max_price'] = $filters['price_max'];
        }

        if ($this->shouldExcludeDemoProducts($filters)) {
            $clause = $this->buildDemoProductExclusionClause();
            $args['meta_query'] = isset($args['meta_query']) && is_array($args['meta_query'])
                ? $args['meta_query']
                : array();

            $args['meta_query'][] = $clause;
            if (! isset($args['meta_query']['relation'])) {
                $args['meta_query']['relation'] = 'AND';
            }
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    private function buildPostQueryArgs(array $filters): array
    {
        $numberposts = 20;
        if (! empty($filters['include_ids'])) {
            $numberposts = max($numberposts, count($filters['include_ids']) + 5);
        }

        $args = [
            'post_type'        => array('bookable_product', 'private_tour', 'product'),
            'post_status'      => 'publish',
            'numberposts'      => $numberposts,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'suppress_filters' => false,
        ];

        if ($filters['search'] !== '') {
            $args['s'] = $filters['search'];
        }

        if (! empty($filters['categories'])) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => $filters['categories'],
                ],
            ];
        }

        $metaQuery = array();
        if ($this->shouldExcludeDemoProducts($filters)) {
            $metaQuery[] = $this->buildDemoProductExclusionClause();
        }

        if ($metaQuery !== array()) {
            if (count($metaQuery) > 1) {
                $metaQuery['relation'] = 'AND';
            }

            $args['meta_query'] = $metaQuery;
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{
     *     search:string,
     *     categories:array<int,string>,
     *     price_min:float|null,
     *     price_max:float|null,
     *     only_available:bool
     * }
     */
    /**
     * @param mixed $value
     * @return int[]
     */
    private function normaliseIntegerList($value): array
    {
        if ($value === null || $value === '') {
            return array();
        }

        $list = is_array($value) ? $value : array($value);
        $ids  = array();

        foreach ($list as $entry) {
            if (is_array($entry)) {
                foreach ($entry as $nested) {
                    $ids[] = (int) $nested;
                }
                continue;
            }

            $ids[] = (int) $entry;
        }

        $ids = array_filter(
            array_map('intval', $ids),
            static function ($id) {
                return $id > 0;
            }
        );

        return array_values(array_unique($ids));
    }

    private function normaliseFilters(array $filters): array
    {
        $search = '';
        if (isset($filters['search'])) {
            $search = (string) $filters['search'];
            if (\function_exists('wp_unslash')) {
                $search = wp_unslash($search);
            }

            if (\function_exists('sanitize_text_field')) {
                $search = sanitize_text_field($search);
            }
        }

        $categories = [];
        if (isset($filters['category'])) {
            $rawCategories = is_array($filters['category']) ? $filters['category'] : explode(',', (string) $filters['category']);
            foreach ($rawCategories as $category) {
                $category = trim((string) $category);
                if ($category === '') {
                    continue;
                }

                if (\function_exists('wp_unslash')) {
                    $category = wp_unslash($category);
                }

                if (\function_exists('sanitize_title')) {
                    $category = sanitize_title($category);
                }

                if ($category !== '') {
                    $categories[] = $category;
                }
            }
        }

        $priceMin = null;
        if (isset($filters['price_min'])) {
            $priceMin = (float) $filters['price_min'];
        }

        $priceMax = null;
        if (isset($filters['price_max'])) {
            $priceMax = (float) $filters['price_max'];
        }

        $onlyAvailable = ! empty($filters['only_available']);
        $excludeUnavailable = ! empty($filters['exclude_unavailable']);

        $includeIds = array();
        if (isset($filters['include'])) {
            $includeIds = $this->normaliseIntegerList($filters['include']);
        }
        if (isset($filters['include_ids'])) {
            $includeIds = array_values(
                array_unique(
                    array_merge($includeIds, $this->normaliseIntegerList($filters['include_ids']))
                )
            );
        }

        $primaryProductId = 0;
        if (isset($filters['primary_product'])) {
            $primaryProductId = (int) $filters['primary_product'];
        }
        if (isset($filters['primary_product_id'])) {
            $primaryProductId = (int) $filters['primary_product_id'];
        }
        if ($primaryProductId <= 0 && $includeIds !== array()) {
            $primaryProductId = (int) $includeIds[0];
        }

        $matchBy = array();
        if (isset($filters['match_by'])) {
            $rawMatch = is_array($filters['match_by'])
                ? $filters['match_by']
                : explode(',', (string) $filters['match_by']);

            foreach ($rawMatch as $entry) {
                if (! is_scalar($entry)) {
                    continue;
                }
                $value = strtolower(trim((string) $entry));
                if ($value === '' || ! in_array($value, array('location', 'category'), true)) {
                    continue;
                }
                $matchBy[] = $value;
            }

            $matchBy = array_values(array_unique($matchBy));
        }

        $includeDemo = false;
        if (array_key_exists('include_demo', $filters)) {
            $flag = $this->normalizeBooleanFlag($filters['include_demo']);
            if ($flag !== null) {
                $includeDemo = $flag;
            }
        } elseif (array_key_exists('demo', $filters)) {
            $value = strtolower(trim((string) $filters['demo']));
            if (in_array($value, array('include', 'all', 'true', 'yes', '1', 'show'), true)) {
                $includeDemo = true;
            }
        }

        $date = '';
        if (isset($filters['date']) && is_string($filters['date'])) {
            $candidate = trim($filters['date']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate) === 1) {
                $date = $candidate;
            }
        }

        $participants = 0;
        if (isset($filters['participants'])) {
            $participants = (int) $filters['participants'];
        } elseif (isset($filters['people'])) {
            $participants = (int) $filters['people'];
        }

        $routeIntent = '';
        if (isset($filters['route_intent']) && is_string($filters['route_intent'])) {
            $candidate = strtolower(trim($filters['route_intent']));
            if (in_array($candidate, array(
                BookingTruthRuntimeService::ROUTE_INTENT_CHECKOUT,
                BookingTruthRuntimeService::ROUTE_INTENT_QUOTE,
                BookingTruthRuntimeService::ROUTE_INTENT_BLOCKED,
            ), true)) {
                $routeIntent = $candidate;
            }
        }

        return [
            'search'         => $search,
            'categories'     => $categories,
            'price_min'      => $priceMin,
            'price_max'      => $priceMax,
            'only_available' => $onlyAvailable,
            'exclude_unavailable' => $excludeUnavailable,
            'include_ids'    => $includeIds,
            'primary_product_id' => $primaryProductId > 0 ? $primaryProductId : null,
            'match_by'       => $matchBy,
            'include_demo'   => $includeDemo,
            'date'           => $date,
            'participants'   => $participants > 0 ? $participants : null,
            'route_intent'   => $routeIntent,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function shouldExcludeDemoProducts(array $filters): bool
    {
        if (! empty($filters['include_demo'])) {
            return false;
        }

        $exclude = true;
        if (\function_exists('apply_filters')) {
            $exclude = (bool) \apply_filters('sbdp/day_planner/exclude_demo_products', $exclude, $filters);
        }

        return $exclude;
    }

    private function buildDemoProductExclusionClause(): array
    {
        return array(
            'relation' => 'OR',
            array(
                'key'     => '_sbdp_demo_seed',
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'     => '_sbdp_demo_seed',
                'value'   => '1',
                'compare' => '!=',
                'type'    => 'CHAR',
            ),
        );
    }

    /**
     * @param mixed $value
     */
    private function normalizeBooleanFlag($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return null;
            }

            if (in_array($normalized, array('1', 'true', 'yes', 'on', 'include', 'all'), true)) {
                return true;
            }

            if (in_array($normalized, array('0', 'false', 'no', 'off', 'exclude'), true)) {
                return false;
            }
        }

        return null;
    }

    private function resolveDurationMinutes(int $productId): int
    {
        $rawValue      = \get_post_meta($productId, '_sbdp_duration', true);
        $durationValue = $this->normaliseDurationValue($rawValue);
        $durationUnit  = (string) \get_post_meta($productId, '_sbdp_duration_unit', true);

        if ($durationValue <= 0) {
            return 90;
        }

        $minutes = $this->convertDurationToMinutes($durationValue, $durationUnit);

        return $minutes > 0 ? $minutes : 90;
    }

    private function resolveDefaultStartTime(int $productId): string
    {
        $raw = (string) \get_post_meta($productId, '_sbdp_default_start_time', true);
        return $this->normaliseTime($raw);
    }

    private function resolveCurrency(): string
    {
        if (\function_exists('get_woocommerce_currency')) {
            $currency = (string) \get_woocommerce_currency();
            if ($currency !== '') {
                return $currency;
            }
        }

        return 'EUR';
    }

    private function normaliseTime(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^\d{2}:\d{2}$/', $trimmed)) {
            return $trimmed;
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $trimmed)) {
            return substr($trimmed, 0, 5);
        }

        return '';
    }

    private function formatWooCommerceProduct($product, string $currency): ?array
    {
        if (! is_object($product) || ! method_exists($product, 'get_id')) {
            return null;
        }

        $productId        = $product->get_id();
        $durationRaw      = get_post_meta($productId, '_sbdp_duration', true);
        $durationValue    = $this->normaliseDurationValue($durationRaw);
        $durationUnit     = (string) get_post_meta($productId, '_sbdp_duration_unit', true);
        $durationMinutes  = $this->convertDurationToMinutes($durationValue, $durationUnit);
        $defaultStartDate = (string) get_post_meta($productId, '_sbdp_default_start_date', true);
        $defaultStartTime = $this->normaliseTime((string) get_post_meta($productId, '_sbdp_default_start_time', true));

        $people  = $this->resolvePeople($productId);
        $pricing = $this->resolvePricing($productId, $people['min'], $currency, $product);

        $imageId = method_exists($product, 'get_image_id') ? $product->get_image_id() : 0;
        if (! $imageId && method_exists($product, 'get_gallery_image_ids')) {
            $galleryIds = $product->get_gallery_image_ids();
            $imageId = is_array($galleryIds) && ! empty($galleryIds) ? current($galleryIds) : 0;
        }
        $image = $imageId ? wp_get_attachment_url($imageId) : '';
        if (! is_string($image) || $image === '') {
            $image = false;
        }

        return array(
            'id'                 => $productId,
            'product_id'         => $productId,
            'name'               => $product->get_name(),
            'title'              => $product->get_name(),
            'slug'               => $product->get_slug(),
            'type'               => $product->get_type(),
            'permalink'          => $product->get_permalink(),
            'image'              => $image,
            'categories'         => wp_get_post_terms($productId, 'product_cat', array('fields' => 'names')),
            'category_slugs'     => wp_get_post_terms($productId, 'product_cat', array('fields' => 'slugs')),
            'duration'           => array(
                'value'   => $durationValue,
                'unit'    => $durationUnit !== '' ? $durationUnit : 'minutes',
                'minutes' => $durationMinutes,
            ),
            'duration_minutes'   => $durationMinutes,
            'default_start'      => array(
                'date' => $defaultStartDate,
                'time' => $defaultStartTime,
            ),
            'default_start_time' => $defaultStartTime,
            'pricing'            => $pricing,
            'price_pp'           => $pricing['supports_persons'] ?? false
                ? (float) $pricing['per_person']
                : (float) (($pricing['base'] ?? 0.0) > 0
                    ? $pricing['base']
                    : (function_exists('wc_get_price_including_tax') && method_exists($product, 'get_price')
                        ? wc_get_price_including_tax($product, array('qty' => 1))
                        : (method_exists($product, 'get_price') ? $product->get_price() : 0))),
            'people'             => $people,
            'resources'          => ProductMeta::get_resources_payload($productId),
            'resource_id'        => ProductMeta::get_primary_resource_id($productId),
            'availability'       => $this->resolveAvailability($productId),
            'currency'           => $currency,
            'stock_status'       => method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : '',
            'location'           => $this->resolveLocation($productId),
        );
    }

    private function formatPostProduct(\WP_Post $post, string $currency): array
    {
        $productId        = (int) $post->ID;
        $durationMinutes  = $this->resolveDurationMinutes($productId);
        $defaultStartTime = $this->resolveDefaultStartTime($productId);
        
        // Read people limits from product meta - try multiple possible meta keys
        // Priority: SBDP custom > WooCommerce Bookings > fallback
        $minPeople = (int) get_post_meta($productId, '_sbdp_min_people', true);
        if ($minPeople <= 0) {
            $minPeople = (int) get_post_meta($productId, '_sbdp_people_min', true);
        }
        if ($minPeople <= 0) {
            $minPeople = (int) get_post_meta($productId, '_wc_booking_min_persons', true);
        }
        if ($minPeople <= 0) {
            $minPeople = 1; // Default fallback
        }
        
        $maxPeople = (int) get_post_meta($productId, '_sbdp_max_people', true);
        if ($maxPeople <= 0) {
            $maxPeople = (int) get_post_meta($productId, '_sbdp_people_max', true);
        }
        if ($maxPeople <= 0) {
            $maxPeople = (int) get_post_meta($productId, '_wc_booking_max_persons', true);
        }
        if ($maxPeople <= 0) {
            $maxPeople = 50; // Default fallback - generous limit for group activities
        }
        
        $peopleEnabled = get_post_meta($productId, '_sbdp_enable_people', true);
        if (!$peopleEnabled) {
            $peopleEnabled = get_post_meta($productId, '_wc_booking_has_persons', true);
        }
        $peopleEnabled = in_array($peopleEnabled, array('1', 'yes', 'true', true), true);
        
        // Capacity validation only fires for products with a real group capacity (max > 1).
        // Products with max=1 are per-person items (e.g. food/drink orders); their quantity
        // scales with participant count — they should never block a multi-person plan.
        $people = array(
            'enabled'           => $maxPeople > 1,
            'min'               => $minPeople,
            'max'               => max($minPeople, $maxPeople),
            'count_as_bookings' => (bool) get_post_meta($productId, '_sbdp_count_as_bookings', true),
        );

        $pricing = $this->resolvePricing($productId, $people['min'], $currency);

        $image = function_exists('get_the_post_thumbnail_url') ? (get_the_post_thumbnail_url($post) ?: '') : '';
        if ($image === '') {
            $gallery = get_post_meta($productId, '_product_image_gallery', true);
            if (is_string($gallery)) {
                $gallery = array_filter(array_map('trim', explode(',', $gallery)));
            }
            if (! empty($gallery)) {
                $image = get_the_post_thumbnail_url((int) $gallery[0]) ?: '';
            }
        }
        if ($image === '') {
            $image = false;
        }

        return array(
            'id'                 => $productId,
            'product_id'         => $productId,
            'name'               => $post->post_title,
            'title'              => $post->post_title,
            'slug'               => $post->post_name,
            'type'               => $post->post_type,
            'permalink'          => get_permalink($post),
            'image'              => $image,
            'categories'         => wp_get_post_terms($productId, 'product_cat', array('fields' => 'names')),
            'category_slugs'     => wp_get_post_terms($productId, 'product_cat', array('fields' => 'slugs')),
            'duration'           => array(
                'value'   => $durationMinutes,
                'unit'    => 'minutes',
                'minutes' => $durationMinutes,
            ),
            'duration_minutes'   => $durationMinutes,
            'default_start'      => array(
                'date' => '',
                'time' => $defaultStartTime,
            ),
            'default_start_time' => $defaultStartTime,
            'pricing'            => $pricing,
            'price_pp'           => $pricing['supports_persons'] ?? false
                ? (float) $pricing['per_person']
                : (float) (($pricing['base'] ?? 0.0) > 0
                    ? $pricing['base']
                    : (($pricing['fixed_fee'] ?? 0.0) > 0 ? (float) $pricing['fixed_fee'] : 0.0)),
            'people'             => $people,
            'resources'          => ProductMeta::get_resources_payload($productId),
            'resource_id'        => ProductMeta::get_primary_resource_id($productId),
            'availability'       => array(),
            'currency'           => $currency,
            'stock_status'       => '',
            'location'           => $this->resolveLocation($productId),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed>             $filters
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterByPrice(array $items, array $filters): array
    {
        $min = $filters['price_min'];
        $max = $filters['price_max'];

        if ($min === null && $max === null) {
            return array_values($items);
        }

        return array_values(
            array_filter(
                $items,
                static function (array $item) use ($min, $max): bool {
                    $price = 0.0;
                    if (isset($item['pricing']['per_person'])) {
                        $price = (float) $item['pricing']['per_person'];
                    } elseif (isset($item['price_pp'])) {
                        $price = (float) $item['price_pp'];
                    }

                    if ($min !== null && $price < $min) {
                        return false;
                    }

                    if ($max !== null && $price > $max) {
                        return false;
                    }

                    return true;
                }
            )
        );
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>> 
     */
    private function buildCatalog(array $filters, string $currency): array
    {
        $items = $this->fetchProductCollection($filters, $currency);

        $items = $this->ensureIncludedProducts($items, $filters['include_ids'], $currency);

        $primaryContext = null;
        if (! empty($filters['primary_product_id'])) {
            $primaryContext = $this->resolvePrimaryContext((int) $filters['primary_product_id']);
        }

        if (! empty($filters['match_by']) && $primaryContext !== null) {
            $items = $this->applyMatchFilters($items, $filters, $primaryContext);
        }

        return $this->filterByPrice($items, $filters);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function applyDiscoveryEnvelope(array $item, array $filters, string $currency): array
    {
        $productId = isset($item['product_id']) ? (int) $item['product_id'] : 0;
        if ($productId <= 0 && isset($item['id'])) {
            $productId = (int) $item['id'];
        }

        $priceRaw = $this->resolveDiscoveryPriceRaw($item);
        $durationMinutes = isset($item['duration_minutes']) ? (int) $item['duration_minutes'] : 0;
        $primaryType = $this->resolvePrimaryTypeMeta($item);
        $profile = $this->resolveDiscoveryCapabilityProfile($productId, $item, $filters);
        $participants = isset($filters['participants']) ? (int) $filters['participants'] : 0;
        $date = isset($filters['date']) ? (string) $filters['date'] : '';

        $item['type_label'] = $primaryType['label'];
        $item['type_slug'] = $primaryType['slug'];
        $item['type_meta'] = $primaryType;
        $item['price'] = array(
            'raw'       => $priceRaw,
            'formatted' => $this->formatCurrency($priceRaw, $currency),
            'currency'  => $currency,
        );
        $item['duration']['formatted'] = $this->formatDuration(max(0, $durationMinutes));
        $item['excerpt'] = $this->resolveExcerpt($productId);
        $item['coordinates'] = $this->resolveCoordinates($productId);
        $item['area'] = $this->resolveArea($item);
        $item['booking_capability'] = (string) ($profile['status'] ?? BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE);
        $item['bookingCapability'] = $item['booking_capability'];
        $item['route_intent'] = (string) ($profile['route_intent'] ?? BookingTruthRuntimeService::ROUTE_INTENT_BLOCKED);
        $item['reason_code'] = $profile['reason_code'] ?? null;
        $item['requestOnly'] = $item['route_intent'] === BookingTruthRuntimeService::ROUTE_INTENT_QUOTE;
        $item['requiresConfirmation'] = $item['requestOnly'];
        $item['is_bookable'] = $item['route_intent'] === BookingTruthRuntimeService::ROUTE_INTENT_CHECKOUT;
        $item['can_add_to_cart'] = $item['is_bookable'];
        $item['can_add_to_planner'] = in_array(
            $item['route_intent'],
            array(
                BookingTruthRuntimeService::ROUTE_INTENT_CHECKOUT,
                BookingTruthRuntimeService::ROUTE_INTENT_QUOTE,
            ),
            true
        );
        $item['discovery'] = array(
            'status'          => $item['booking_capability'],
            'route_intent'    => $item['route_intent'],
            'reason_code'     => $profile['reason_code'] ?? null,
            'legacy_status'   => $profile['legacy_status'] ?? BookingTruthRuntimeService::BOOKING_CAPABILITY_REQUEST,
            'date'            => $date !== '' ? $date : null,
            'participants'    => $participants > 0 ? $participants : null,
            'context_applied' => ($date !== '' && $participants > 0),
        );
        $item['planner_prefill'] = array(
            'product_id'   => $productId,
            'date'         => $date !== '' ? $date : null,
            'participants' => $participants > 0 ? $participants : null,
            'people'       => $participants > 0 ? $participants : null,
            'source'       => 'discovery',
            'route_intent' => $item['route_intent'],
            'booking_capability' => $item['booking_capability'],
        );

        return $item;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $filters
     * @return array{status:string,route_intent:string,reason_code:?string,legacy_status:string}
     */
    private function resolveDiscoveryCapabilityProfile(int $productId, array $item, array $filters): array
    {
        $runtime = new BookingTruthRuntimeService();
        if ($productId <= 0) {
            return $runtime->buildCapabilityProfile(BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE, 'invalid_product');
        }

        $explicitCapability = $this->resolveExplicitDiscoveryCapability($item);
        if ($explicitCapability !== null) {
            return $runtime->buildCapabilityProfile($explicitCapability, 'explicit_capability');
        }

        if ($this->productRequiresConfirmation($productId)) {
            return $runtime->buildCapabilityProfile(BookingTruthRuntimeService::CAPABILITY_STATUS_REQUEST, 'requires_confirmation');
        }

        $date = isset($filters['date']) && is_string($filters['date']) ? trim($filters['date']) : '';
        $participants = isset($filters['participants']) ? (int) $filters['participants'] : 0;

        if ($date === '' || $participants <= 0) {
            return $runtime->buildCapabilityProfile(BookingTruthRuntimeService::CAPABILITY_STATUS_DIRECT_LIMITED, 'missing_discovery_context');
        }

        $isAvailable = $this->checkDiscoveryAvailability($productId, $date, $participants);
        if ($isAvailable === null) {
            return $runtime->buildCapabilityProfile(BookingTruthRuntimeService::CAPABILITY_STATUS_DIRECT_LIMITED, 'availability_unverified');
        }

        if ($isAvailable === false) {
            return $runtime->buildCapabilityProfile(BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE, 'date_participants_unavailable');
        }

        return $runtime->buildCapabilityProfile(BookingTruthRuntimeService::CAPABILITY_STATUS_DIRECT, 'date_participants_available');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveExplicitDiscoveryCapability(array $item): ?string
    {
        $candidates = array(
            $item['booking_capability'] ?? null,
            $item['bookingCapability'] ?? null,
            $item['checkout_mode'] ?? null,
            $item['checkoutMode'] ?? null,
        );

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = strtolower(trim($candidate));
            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, array('direct', 'direct_eligible', 'direct-eligible', 'checkout', 'book'), true)) {
                return BookingTruthRuntimeService::CAPABILITY_STATUS_DIRECT;
            }
            if (in_array($normalized, array('direct_limited', 'direct-limited', 'limited_direct', 'limited-direct'), true)) {
                return BookingTruthRuntimeService::CAPABILITY_STATUS_DIRECT_LIMITED;
            }
            if (in_array($normalized, array('request', 'request_only', 'request-only', 'quote', 'quote_only', 'quote-only'), true)) {
                return BookingTruthRuntimeService::CAPABILITY_STATUS_REQUEST;
            }
            if (in_array($normalized, array('unavailable', 'blocked', 'closed', 'none'), true)) {
                return BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE;
            }
        }

        if (! empty($item['requestOnly']) || ! empty($item['quoteOnly']) || ! empty($item['requiresConfirmation']) || ! empty($item['requires_confirmation'])) {
            return BookingTruthRuntimeService::CAPABILITY_STATUS_REQUEST;
        }

        return null;
    }

    private function checkDiscoveryAvailability(int $productId, string $date, int $participants): ?bool
    {
        if (! class_exists(BookingEngine::class)) {
            return null;
        }

        try {
            $engine = new BookingEngine();
            return $engine->checkAvailability($productId, $date, null, null, $participants) === true;
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function productRequiresConfirmation(int $productId): bool
    {
        $wcFlag = get_post_meta($productId, '_wc_booking_requires_confirmation', true);
        if ($wcFlag === 'yes' || $wcFlag === '1' || $wcFlag === 1 || $wcFlag === true) {
            return true;
        }

        $bookable = get_post_meta($productId, '_sbdp_bookable', true);
        if (is_array($bookable)) {
            $flag = $bookable['booking_requires_confirmation'] ?? null;
            if ($flag === 'yes' || $flag === '1' || $flag === 1 || $flag === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{label:string,slug:string}
     */
    private function resolvePrimaryTypeMeta(array $item): array
    {
        if (isset($item['type_meta']) && is_array($item['type_meta'])) {
            return array(
                'label' => isset($item['type_meta']['label']) ? (string) $item['type_meta']['label'] : 'Activiteit',
                'slug'  => isset($item['type_meta']['slug']) ? (string) $item['type_meta']['slug'] : '',
            );
        }

        $categories = isset($item['categories']) && is_array($item['categories']) ? $item['categories'] : array();
        $categorySlugs = isset($item['category_slugs']) && is_array($item['category_slugs']) ? $item['category_slugs'] : array();

        return array(
            'label' => isset($categories[0]) && is_string($categories[0]) && $categories[0] !== '' ? $categories[0] : 'Activiteit',
            'slug'  => isset($categorySlugs[0]) && is_string($categorySlugs[0]) ? $categorySlugs[0] : '',
        );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveDiscoveryPriceRaw(array $item): float
    {
        if (isset($item['price']['raw']) && is_numeric($item['price']['raw'])) {
            return (float) $item['price']['raw'];
        }

        if (isset($item['pricing']['per_person']) && is_numeric($item['pricing']['per_person']) && (float) $item['pricing']['per_person'] > 0) {
            return (float) $item['pricing']['per_person'];
        }

        if (isset($item['price_pp']) && is_numeric($item['price_pp'])) {
            return (float) $item['price_pp'];
        }

        if (isset($item['pricing']['base']) && is_numeric($item['pricing']['base'])) {
            return (float) $item['pricing']['base'];
        }

        return 0.0;
    }

    private function formatCurrency(float $value, string $currency): string
    {
        if (function_exists('html_entity_decode') && function_exists('wc_price')) {
            return trim(wp_strip_all_tags(html_entity_decode(wc_price($value, array('currency' => $currency)))));
        }

        return sprintf('%s %.2f', $currency, $value);
    }

    private function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'Flexibel';
        }

        if ($minutes >= 60) {
            $hours = $minutes / 60;
            if ((int) $hours === $hours) {
                return sprintf('%d uur', (int) $hours);
            }

            return sprintf('%.1f uur', $hours);
        }

        return sprintf('%d min', $minutes);
    }

    private function resolveExcerpt(int $productId): string
    {
        if (! function_exists('get_post')) {
            return '';
        }

        $post = get_post($productId);
        if (! $post instanceof \WP_Post) {
            return '';
        }

        if (function_exists('has_excerpt') && has_excerpt($post)) {
            return (string) get_the_excerpt($post);
        }

        if (function_exists('wp_trim_words')) {
            return (string) wp_trim_words(wp_strip_all_tags((string) $post->post_content), 28);
        }

        return (string) substr(wp_strip_all_tags((string) $post->post_content), 0, 160);
    }

    /**
     * @return array{lat:float|null,lng:float|null}
     */
    private function resolveCoordinates(int $productId): array
    {
        $lat = get_post_meta($productId, '_sbdp_location_lat', true);
        $lng = get_post_meta($productId, '_sbdp_location_lng', true);

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            $lat = get_post_meta($productId, 'latitude', true);
            $lng = get_post_meta($productId, 'longitude', true);
        }

        if (is_numeric($lat) && is_numeric($lng)) {
            return array(
                'lat' => (float) $lat,
                'lng' => (float) $lng,
            );
        }

        return array(
            'lat' => null,
            'lng' => null,
        );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveArea(array $item): string
    {
        if (isset($item['location']) && is_string($item['location']) && trim($item['location']) !== '') {
            return trim((string) $item['location']);
        }

        return '';
    }

    private function resolvePeople(int $productId): array
    {
        $enabled = $this->boolMeta($productId, '_sbdp_enable_people');
        $min     = (int) get_post_meta($productId, '_sbdp_min_people', true);
        $max     = (int) get_post_meta($productId, '_sbdp_max_people', true);

        if ($min <= 0) {
            $min = 1;
        }
        if ($max <= 0) {
            $max = $enabled ? $min : 1;
        }

        // Group capacity validation only makes sense when max > 1.
        // A product with max=1 is per-person (1 unit per attendee), not a solo-only
        // session. Enabling capacity validation at max=1 would incorrectly block
        // any multi-person plan from adding a per-person item like a food/drink order.
        return array(
            'enabled'           => $enabled && $max > 1,
            'min'               => $min,
            'max'               => max($min, $max),
            'count_as_bookings' => $this->boolMeta($productId, '_sbdp_people_as_bookings'),
        );
    }

    private function resolvePricing(int $productId, int $participantsMin, string $currency, $product = null): array
    {
        $pricingDetails = CorePricingService::instance()->getProductPricing(
            $productId,
            array(
                'price_mode' => 'gross',
                'currency'   => $currency,
                'channel'    => 'planner_ui',
            )
        );

        $base      = (float) ($pricingDetails['base_price'] ?? 0.0);
        $perPerson = (float) ($pricingDetails['per_person'] ?? 0.0);
        $supportsPersons = ! empty($pricingDetails['supports_persons']);
        $fixedFee  = (float) ($pricingDetails['fixed_fee'] ?? 0.0);

        if ($supportsPersons && $perPerson <= 0 && $product && method_exists($product, 'get_price')) {
            $perPerson = function_exists('wc_get_price_including_tax')
                ? (float) wc_get_price_including_tax($product, array('qty' => 1))
                : (float) $product->get_price();
        }

        if (! $supportsPersons) {
            $perPerson = 0.0;
        }

        $dynamic = $this->calculateDynamicPricing($productId, max(1, $participantsMin), $currency);
        if ($supportsPersons && is_array($dynamic) && isset($dynamic['unit_price'])) {
            $unitPrice = (float) $dynamic['unit_price'];
            if ($unitPrice > 0.0) {
                $dynamic['total_adjusted'] = $unitPrice;
            }
        }

        return array(
            'base'             => $base,
            'per_person'       => $perPerson,
            'fixed_fee'        => $fixedFee,
            'currency'         => $currency,
            'supports_persons' => $supportsPersons,
            'dynamic'          => $dynamic,
        );
    }

    private function resolveAvailability(int $productId): array
    {
        $default = get_post_meta($productId, '_sbdp_default_hours', true);
        $rules   = get_post_meta($productId, '_sbdp_availability_rules', true);

        return array(
            'default_hours' => $this->decodeMetaJson($default),
            'rules'         => $this->decodeMetaJson($rules),
        );
    }

    private function floatMeta(int $productId, string $key): float
    {
        $value = get_post_meta($productId, $key, true);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function boolMeta(int $productId, string $key): bool
    {
        $value = get_post_meta($productId, $key, true);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), array('1', 'yes', 'true'), true);
        }

        return (bool) $value;
    }

    private function decodeMetaJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return array();
    }

    private function calculateDynamicPricing(int $productId, int $quantity, string $currency): ?array
    {
        try {
            $quote = CorePricingService::instance()->quote(
                $productId,
                max(1, $quantity),
                array(
                    'channel'    => 'planner_ui',
                    'currency'   => $currency,
                    'price_mode' => 'gross',
                )
            );
        } catch (\Throwable $exception) {
            return null;
        }

        return is_array($quote) ? $quote : null;
    }

    /**
     * @param mixed $value
     */
    private function normaliseDurationValue($value): float
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null) {
            return 0.0;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        if (! is_numeric($value)) {
            return 0.0;
        }

        $numeric = (float) $value;

        return $numeric > 0 ? $numeric : 0.0;
    }

    private function convertDurationToMinutes(float $value, string $unit): int
    {
        $numeric = $value > 0 ? $value : 1.0;

        $unit = strtolower($unit);

        switch ($unit) {
            case 'hours':
            case 'hour':
                return max(1, (int) ceil($numeric * 60));
            case 'days':
            case 'day':
                return max(1, (int) ceil($numeric * 60 * 24));
            case 'months':
            case 'month':
                return max(1, (int) ceil($numeric * 43200));
            case 'minutes':
            case 'minute':
            default:
                return max(1, (int) ceil($numeric));
        }
    }

    /**
     * Provide a minimal catalog when the configured list is empty.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildFallbackItems(string $currency): array
    {
        if (! \function_exists('get_posts')) {
            return array();
        }

        $posts = \get_posts(
            array(
                'post_type'      => array('bookable_product', 'private_tour', 'product'),
                'post_status'    => 'publish',
                'posts_per_page' => self::FALLBACK_QUERY_LIMIT,
                'orderby'        => 'modified',
                'order'          => 'DESC',
            )
        );

        $items = array();

        foreach ($posts as $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }

            $item = $this->formatPostProduct($post, $currency);

            // formatPostProduct now correctly reads people limits from meta,
            // so we only need to ensure the structure exists as a safety net
            if (! isset($item['people']) || ! is_array($item['people'])) {
                $item['people'] = array(
                    'enabled'           => true,
                    'min'               => 1,
                    'max'               => 50,
                    'count_as_bookings' => true,
                );
            }
            // Don't override the values from formatPostProduct - they come from the database

            if (
                ! isset($item['availability']) ||
                ! is_array($item['availability']) ||
                empty($item['availability']['default_hours'])
            ) {
                $item['availability'] = array(
                    'default_hours' => array(
                        'mon' => array(array('start' => '09:00', 'end' => '17:00')),
                        'tue' => array(array('start' => '09:00', 'end' => '17:00')),
                        'wed' => array(array('start' => '09:00', 'end' => '17:00')),
                        'thu' => array(array('start' => '09:00', 'end' => '17:00')),
                        'fri' => array(array('start' => '09:00', 'end' => '17:00')),
                        'sat' => array(array('start' => '09:00', 'end' => '17:00')),
                        'sun' => array(array('start' => '09:00', 'end' => '17:00')),
                    ),
                    'rules' => array(),
                );
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function maybeAutoSeed(array $filters): bool
    {
        // Hard disable auto seeding of demo data.
        $this->logCatalogEvent('auto_seed_disabled');
        return false;
    }

    private function toggleEmptyNotice(bool $empty): void
    {
        if ($empty) {
            set_transient(self::EMPTY_NOTICE_TRANSIENT, '1', HOUR_IN_SECONDS);
        } else {
            delete_transient(self::EMPTY_NOTICE_TRANSIENT);
        }
    }

    private function logCatalogEvent(string $context): void
    {
        if (! \function_exists('error_log')) {
            return;
        }

        error_log(sprintf('[SBDP][DayPlanner] Catalog status: %s', $context));
    }

    private function clearAutoSeedLock(): void
    {
        \delete_transient('sbdp_day_planner_auto_seed_lock');
    }
}
