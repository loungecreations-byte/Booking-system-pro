<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Domain;

use WP_Post;

use function absint;
use function array_filter;
use function array_map;
use function array_values;
use function get_post;
use function get_post_meta;
use function get_posts;
use function get_the_terms;
use function is_array;
use function is_string;
use function is_wp_error;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function sanitize_title;
use function wp_insert_post;
use function wp_json_encode;
use function wp_parse_args;
use function wp_set_object_terms;
use function wp_strip_all_tags;
use function update_post_meta;

final class ArrangementRepository
{
    public function register(): void
    {
        if (function_exists('register_post_status')) {
            register_post_status(
                'sbdp_archived',
                array(
                    'label' => __('Archived', 'sbdp'),
                    'public' => false,
                    'internal' => true,
                    'exclude_from_search' => true,
                    'show_in_admin_all_list' => true,
                    'show_in_admin_status_list' => true,
                )
            );
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function query(array $filters = array()): array
    {
        if (! function_exists('get_posts')) {
            return array();
        }

        $args = wp_parse_args(
            array(
                'post_type' => ArrangementSchema::POST_TYPE,
                'post_status' => array('publish', 'draft', 'pending', 'private', 'sbdp_archived'),
                'posts_per_page' => 100,
                'orderby' => 'menu_order title',
                'order' => 'ASC',
            ),
            array()
        );

        $arrangementType = isset($filters['arrangement_type']) && is_string($filters['arrangement_type'])
            ? sanitize_key($filters['arrangement_type'])
            : '';
        if ($arrangementType !== '') {
            $args['meta_query'] = array(
                array(
                    'key' => ArrangementSchema::META_ARRANGEMENT_TYPE,
                    'value' => $arrangementType,
                ),
            );
        }

        $posts = get_posts($args);
        if (! is_array($posts)) {
            return array();
        }

        $items = array();
        foreach ($posts as $post) {
            if (! $post instanceof WP_Post) {
                continue;
            }

            $normalized = $this->normalize($post);
            if ($normalized === array()) {
                continue;
            }

            $items[] = $normalized;
        }

        return $items;
    }

    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $post = get_post($id);
        if (! $post instanceof WP_Post || ArrangementSchema::POST_TYPE !== $post->post_type) {
            return null;
        }

        $normalized = $this->normalize($post);

        return $normalized !== array() ? $normalized : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function save(array $payload): int
    {
        $normalized = $this->normalizePayload($payload);
        $postId = absint((int) ($normalized['id'] ?? 0));

        $postarr = array(
            'post_type' => ArrangementSchema::POST_TYPE,
            'post_title' => (string) $normalized['title'],
            'post_name' => (string) $normalized['slug'],
            'post_content' => (string) $normalized['description'],
            'post_excerpt' => (string) $normalized['excerpt'],
            'post_status' => $this->mapStatus((string) $normalized['status']),
            'menu_order' => (int) $normalized['sort_order'],
        );

        if ($postId > 0) {
            $postarr['ID'] = $postId;
        }

        $result = wp_insert_post($postarr, true);
        if (is_wp_error($result)) {
            return 0;
        }
        $postId = (int) $result;

        update_post_meta($postId, ArrangementSchema::META_SPEC, wp_json_encode($normalized));
        update_post_meta($postId, ArrangementSchema::META_SEGMENTS, wp_json_encode($normalized['segments']));
        update_post_meta($postId, ArrangementSchema::META_RULES, wp_json_encode($normalized['rules']));
        update_post_meta($postId, ArrangementSchema::META_TEMPLATE_ID, (int) $normalized['template_id']);
        update_post_meta($postId, ArrangementSchema::META_SALES_PRODUCT_ID, (int) $normalized['sales_product_id']);
        update_post_meta($postId, ArrangementSchema::META_PRICE_STRATEGY, (string) $normalized['price_strategy']);
        update_post_meta($postId, ArrangementSchema::META_BASE_PRICE, (float) $normalized['base_price']);
        update_post_meta($postId, ArrangementSchema::META_CURRENCY, (string) $normalized['currency']);
        update_post_meta($postId, ArrangementSchema::META_DURATION_TOTAL, (int) $normalized['duration_total']);
        update_post_meta($postId, ArrangementSchema::META_DAYPART, (string) $normalized['daypart']);
        update_post_meta($postId, ArrangementSchema::META_CREATION_MODE, (string) $normalized['creation_mode']);
        update_post_meta($postId, ArrangementSchema::META_ARRANGEMENT_TYPE, (string) $normalized['arrangement_type']);
        update_post_meta($postId, ArrangementSchema::META_VISIBILITY, (string) $normalized['visibility']);
        update_post_meta($postId, ArrangementSchema::META_FEATURED, ! empty($normalized['featured']) ? '1' : '0');
        update_post_meta($postId, ArrangementSchema::META_SORT_ORDER, (int) $normalized['sort_order']);
        update_post_meta($postId, ArrangementSchema::META_IMAGE_ID, (int) $normalized['image_id']);
        update_post_meta($postId, ArrangementSchema::META_GALLERY_IDS, wp_json_encode(array_values(array_map('absint', $normalized['gallery_ids']))));
        update_post_meta($postId, ArrangementSchema::META_LEGACY_SOURCE, (string) $normalized['legacy_source']);
        update_post_meta($postId, ArrangementSchema::META_LEGACY_BUNDLE_ID, (string) $normalized['legacy_bundle_id']);

        if (is_array($normalized['categories'])) {
            wp_set_object_terms($postId, $normalized['categories'], ArrangementSchema::TAXONOMY_CATEGORY, false);
        }
        if (is_array($normalized['tags'])) {
            wp_set_object_terms($postId, $normalized['tags'], ArrangementSchema::TAXONOMY_TAG, false);
        }

        return $postId;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalizePayload(array $payload): array
    {
        $id = absint((int) ($payload['id'] ?? 0));
        $title = sanitize_text_field((string) ($payload['title'] ?? ''));
        $slug = sanitize_title((string) ($payload['slug'] ?? $title));
        $description = (string) ($payload['description'] ?? '');
        $excerpt = sanitize_text_field((string) ($payload['excerpt'] ?? ''));
        $statusValue = (string) ($payload['status'] ?? 'draft');
        $status = in_array($statusValue, array('publish', 'draft', 'pending', 'private', 'archived', 'sbdp_archived'), true)
            ? $statusValue
            : 'draft';
        $arrangementTypeValue = (string) ($payload['arrangement_type'] ?? 'fixed');
        $arrangementType = in_array($arrangementTypeValue, array('fixed', 'dynamic', 'customized'), true)
            ? $arrangementTypeValue
            : 'fixed';
        $creationModeValue = (string) ($payload['creation_mode'] ?? 'fixed');
        $creationMode = in_array($creationModeValue, array('template', 'fixed', 'dynamic', 'customized'), true)
            ? $creationModeValue
            : 'fixed';
        $visibilityValue = (string) ($payload['visibility'] ?? 'public');
        $visibility = in_array($visibilityValue, ArrangementSchema::VISIBILITIES, true)
            ? $visibilityValue
            : 'public';
        $priceStrategyValue = (string) ($payload['price_strategy'] ?? 'sum_children');
        $priceStrategy = in_array($priceStrategyValue, ArrangementSchema::PRICE_STRATEGIES, true)
            ? $priceStrategyValue
            : 'sum_children';

        $segments = array_values(array_filter(array_map(array($this, 'normalizeSegment'), is_array($payload['segments'] ?? null) ? (array) $payload['segments'] : array())));
        $rules = is_array($payload['rules'] ?? null) ? array_values($payload['rules']) : array();
        $categories = $this->normalizeStringList($payload['categories'] ?? array());
        $tags = $this->normalizeStringList($payload['tags'] ?? array());
        $galleryIds = $this->normalizeIntList($payload['gallery_ids'] ?? array());

        return array(
            'id' => $id,
            'title' => $title !== '' ? $title : __('Nieuw arrangement', 'sbdp'),
            'slug' => $slug !== '' ? $slug : sanitize_title($title),
            'description' => $description,
            'excerpt' => $excerpt,
            'status' => $status,
            'arrangement_type' => $arrangementType,
            'creation_mode' => $creationMode,
            'visibility' => $visibility,
            'categories' => $categories,
            'tags' => $tags,
            'duration_total' => max(0, (int) ($payload['duration_total'] ?? 0)),
            'daypart' => sanitize_key((string) ($payload['daypart'] ?? '')),
            'price_strategy' => $priceStrategy,
            'base_price' => (float) ($payload['base_price'] ?? 0.0),
            'currency' => strtoupper((string) ($payload['currency'] ?? 'EUR')),
            'derived_price' => is_array($payload['derived_price'] ?? null) ? (array) $payload['derived_price'] : array(),
            'image_id' => absint((int) ($payload['image_id'] ?? 0)),
            'gallery_ids' => $galleryIds,
            'featured' => ! empty($payload['featured']),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'template_id' => absint((int) ($payload['template_id'] ?? 0)),
            'sales_product_id' => absint((int) ($payload['sales_product_id'] ?? 0)),
            'legacy_source' => sanitize_text_field((string) ($payload['legacy_source'] ?? '')),
            'legacy_bundle_id' => sanitize_text_field((string) ($payload['legacy_bundle_id'] ?? '')),
            'segments' => $segments,
            'rules' => $rules,
        );
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    public function normalizeStringList($value): array
    {
        $value = is_array($value) ? $value : array($value);
        $items = array();
        foreach ($value as $item) {
            $item = is_string($item) ? trim($item) : '';
            if ($item === '') {
                continue;
            }
            $items[] = sanitize_text_field($item);
        }

        return array_values(array_unique($items));
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    public function normalizeIntList($value): array
    {
        $value = is_array($value) ? $value : array($value);
        $items = array();
        foreach ($value as $item) {
            $item = absint((int) $item);
            if ($item > 0) {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    public function normalizeSegment($raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $segmentType = sanitize_key((string) ($raw['segment_type'] ?? 'activity'));
        if (! in_array($segmentType, ArrangementSchema::SEGMENT_TYPES, true)) {
            $segmentType = 'activity';
        }

        $timingMode = sanitize_key((string) ($raw['timing_mode'] ?? 'after_previous'));
        if (! in_array($timingMode, ArrangementSchema::TIMING_MODES, true)) {
            $timingMode = 'after_previous';
        }

        return array(
            'id' => sanitize_text_field((string) ($raw['id'] ?? uniqid('segment-', true))),
            'arrangement_id' => absint((int) ($raw['arrangement_id'] ?? 0)),
            'role' => $this->normalizeSegmentRole((string) ($raw['role'] ?? '')),
            'linked_product_id' => absint((int) ($raw['linked_product_id'] ?? ($raw['product_id'] ?? 0))),
            'linked_resource_id' => absint((int) ($raw['linked_resource_id'] ?? ($raw['resource_id'] ?? 0))),
            'linked_vendor_id' => absint((int) ($raw['linked_vendor_id'] ?? ($raw['vendor_id'] ?? 0))),
            'title_override' => sanitize_text_field((string) ($raw['title_override'] ?? '')),
            'segment_type' => $segmentType,
            'sequence' => max(0, (int) ($raw['sequence'] ?? 0)),
            'required' => ! empty($raw['required']),
            'timing_mode' => $timingMode,
            'fixed_start_time' => sanitize_text_field((string) ($raw['fixed_start_time'] ?? '')),
            'fixed_end_time' => sanitize_text_field((string) ($raw['fixed_end_time'] ?? '')),
            'earliest_start' => sanitize_text_field((string) ($raw['earliest_start'] ?? '')),
            'latest_start' => sanitize_text_field((string) ($raw['latest_start'] ?? '')),
            'min_duration' => max(0, (int) ($raw['min_duration'] ?? 0)),
            'max_duration' => max(0, (int) ($raw['max_duration'] ?? 0)),
            'buffer_before' => max(0, (int) ($raw['buffer_before'] ?? 0)),
            'buffer_after' => max(0, (int) ($raw['buffer_after'] ?? 0)),
            'travel_buffer' => max(0, (int) ($raw['travel_buffer'] ?? 0)),
            'availability_source' => sanitize_key((string) ($raw['availability_source'] ?? 'derived')),
            'pricing_source' => sanitize_key((string) ($raw['pricing_source'] ?? 'derived')),
            'notes' => sanitize_textarea_field((string) ($raw['notes'] ?? '')),
            'ui_label' => sanitize_text_field((string) ($raw['ui_label'] ?? '')),
            'is_hidden' => ! empty($raw['is_hidden']),
            'is_optional' => ! empty($raw['is_optional']),
            'is_replaceable' => ! empty($raw['is_replaceable']),
            'pricing' => is_array($raw['pricing'] ?? null) ? (array) $raw['pricing'] : array(),
            'rules' => is_array($raw['rules'] ?? null) ? (array) $raw['rules'] : array(),
        );
    }

    private function normalizeSegmentRole(string $role): string
    {
        $role = sanitize_key($role);

        return in_array($role, ArrangementSchema::SEGMENT_ROLES, true) ? $role : '';
    }

    /**
     * @param WP_Post|int $post
     * @return array<string, mixed>
     */
    public function normalize($post): array
    {
        $post = $post instanceof WP_Post ? $post : get_post((int) $post);
        if (! $post instanceof WP_Post || ArrangementSchema::POST_TYPE !== $post->post_type) {
            return array();
        }

        $spec = get_post_meta($post->ID, ArrangementSchema::META_SPEC, true);
        $spec = is_string($spec) ? json_decode($spec, true) : (is_array($spec) ? $spec : array());
        $segments = get_post_meta($post->ID, ArrangementSchema::META_SEGMENTS, true);
        $segments = is_string($segments) ? json_decode($segments, true) : (is_array($segments) ? $segments : array());
        $rules = get_post_meta($post->ID, ArrangementSchema::META_RULES, true);
        $rules = is_string($rules) ? json_decode($rules, true) : (is_array($rules) ? $rules : array());

        $categories = array();
        $terms = get_the_terms($post->ID, ArrangementSchema::TAXONOMY_CATEGORY);
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if (isset($term->slug)) {
                    $categories[] = sanitize_title((string) $term->slug);
                }
            }
        }

        $tags = array();
        $tagTerms = get_the_terms($post->ID, ArrangementSchema::TAXONOMY_TAG);
        if (is_array($tagTerms)) {
            foreach ($tagTerms as $term) {
                if (isset($term->slug)) {
                    $tags[] = sanitize_title((string) $term->slug);
                }
            }
        }

        $normalized = array_merge(
            array(
                'id' => $post->ID,
                'title' => sanitize_text_field($post->post_title),
                'slug' => sanitize_title($post->post_name),
                'description' => (string) $post->post_content,
                'excerpt' => sanitize_text_field(wp_strip_all_tags((string) $post->post_excerpt)),
                'status' => (string) $post->post_status,
                'arrangement_type' => sanitize_key((string) get_post_meta($post->ID, ArrangementSchema::META_ARRANGEMENT_TYPE, true)),
                'creation_mode' => sanitize_key((string) get_post_meta($post->ID, ArrangementSchema::META_CREATION_MODE, true)),
                'visibility' => sanitize_key((string) get_post_meta($post->ID, ArrangementSchema::META_VISIBILITY, true)),
                'categories' => array_values(array_filter($categories)),
                'tags' => array_values(array_filter($tags)),
                'duration_total' => absint((int) get_post_meta($post->ID, ArrangementSchema::META_DURATION_TOTAL, true)),
                'daypart' => sanitize_key((string) get_post_meta($post->ID, ArrangementSchema::META_DAYPART, true)),
                'price_strategy' => sanitize_key((string) get_post_meta($post->ID, ArrangementSchema::META_PRICE_STRATEGY, true)),
                'base_price' => (float) get_post_meta($post->ID, ArrangementSchema::META_BASE_PRICE, true),
                'currency' => strtoupper((string) get_post_meta($post->ID, ArrangementSchema::META_CURRENCY, true)),
                'derived_price' => array(),
                'image_id' => absint((int) get_post_meta($post->ID, ArrangementSchema::META_IMAGE_ID, true)),
                'gallery_ids' => $this->normalizeIntList(get_post_meta($post->ID, ArrangementSchema::META_GALLERY_IDS, true)),
                'featured' => (string) get_post_meta($post->ID, ArrangementSchema::META_FEATURED, true) === '1',
                'sort_order' => (int) get_post_meta($post->ID, ArrangementSchema::META_SORT_ORDER, true),
                'template_id' => absint((int) get_post_meta($post->ID, ArrangementSchema::META_TEMPLATE_ID, true)),
                'sales_product_id' => absint((int) get_post_meta($post->ID, ArrangementSchema::META_SALES_PRODUCT_ID, true)),
                'legacy_source' => sanitize_text_field((string) get_post_meta($post->ID, ArrangementSchema::META_LEGACY_SOURCE, true)),
                'legacy_bundle_id' => sanitize_text_field((string) get_post_meta($post->ID, ArrangementSchema::META_LEGACY_BUNDLE_ID, true)),
                'rules' => is_array($rules) ? array_values($rules) : array(),
                'segments' => array_values(array_filter(array_map(array($this, 'normalizeSegment'), is_array($segments) ? $segments : array()))),
            ),
            is_array($spec) ? $spec : array()
        );

        $normalized['categories'] = array_values(array_unique(array_filter(array_map('sanitize_title', $normalized['categories']))));
        $normalized['tags'] = array_values(array_unique(array_filter(array_map('sanitize_title', $normalized['tags']))));

        return $normalized;
    }

    private function mapStatus(string $status): string
    {
        if (in_array($status, array('archived', 'sbdp_archived'), true)) {
            return 'sbdp_archived';
        }

        return in_array($status, array('publish', 'draft', 'pending', 'private'), true) ? $status : 'draft';
    }
}
