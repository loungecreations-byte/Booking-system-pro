<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

use BSPModule\Core\Product\ProductMeta;

final class ActivityService
{
    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function listActivities(array $filters = []): array
    {
        $filters = $this->normaliseFilters($filters);

        if (\function_exists('wc_get_products')) {
            $products = \wc_get_products(
                $this->buildWooCommerceQueryArgs($filters)
            );

            return array_map(
                function ($product): array {
                    $productId = $product->get_id();
                    $price = \function_exists('wc_get_price_to_display')
                        ? (float) \wc_get_price_to_display($product)
                        : (float) $product->get_price();

                    return [
                        'id'                 => $productId,
                        'product_id'         => $productId,
                        'title'              => $product->get_name(),
                        'price_pp'           => $price,
                        'permalink'          => $product->get_permalink(),
                        'categories'         => wp_get_post_terms($productId, 'product_cat', ['fields' => 'names']),
                        'image'              => wp_get_attachment_url($product->get_image_id()),
                        'duration_minutes'   => $this->resolveDurationMinutes($productId),
                        'default_start_time' => $this->resolveDefaultStartTime($productId),
                        'resource_id'        => ProductMeta::get_primary_resource_id($productId),
                        'resources'          => ProductMeta::get_resources_payload($productId),
                        'currency'           => $this->resolveCurrency(),
                    ];
                },
                $products
            );
        }

        if (! \function_exists('get_posts')) {
            return [];
        }

        $posts = \get_posts(
            $this->buildPostQueryArgs($filters)
        );

        $activities = array_map(
            static function (\WP_Post $post): array {
                $price = (float) \get_post_meta($post->ID, '_price', true);

                return [
                    'id'         => $post->ID,
                    'product_id' => $post->ID,
                    'title'      => $post->post_title,
                    'price_pp'   => $price > 0 ? $price : 0.0,
                    'permalink'  => get_permalink($post),
                    'categories' => wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'names']),
                    'duration_minutes' => $this->resolveDurationMinutes($post->ID),
                    'default_start_time' => $this->resolveDefaultStartTime($post->ID),
                    'resource_id' => ProductMeta::get_primary_resource_id($post->ID),
                    'resources' => ProductMeta::get_resources_payload($post->ID),
                    'currency' => $this->resolveCurrency(),
                    'image'      => \function_exists('get_the_post_thumbnail_url')
                        ? (get_the_post_thumbnail_url($post) ?: '')
                        : '',
                ];
            },
            $posts
        );

        if ($filters['price_min'] !== null || $filters['price_max'] !== null) {
            $activities = array_filter(
                $activities,
                static function (array $activity) use ($filters): bool {
                    $price = (float) ($activity['price_pp'] ?? 0.0);

                    if ($filters['price_min'] !== null && $price < $filters['price_min']) {
                        return false;
                    }

                    if ($filters['price_max'] !== null && $price > $filters['price_max']) {
                        return false;
                    }

                    return true;
                }
            );
        }

        return array_values($activities);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    private function buildWooCommerceQueryArgs(array $filters): array
    {
        $args = [
            'limit'   => 30,
            'status'  => 'publish',
            'orderby' => 'date',
            'order'   => 'DESC',
        ];

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

        return $args;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    private function buildPostQueryArgs(array $filters): array
    {
        $args = [
            'post_type'        => 'product',
            'post_status'      => 'publish',
            'numberposts'      => 20,
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

        return [
            'search'         => $search,
            'categories'     => $categories,
            'price_min'      => $priceMin,
            'price_max'      => $priceMax,
            'only_available' => $onlyAvailable,
        ];
    }

    private function resolveDurationMinutes(int $productId): int
    {
        $duration = (int) \get_post_meta($productId, '_sbdp_duration', true);
        return $duration > 0 ? $duration : 60;
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
}
