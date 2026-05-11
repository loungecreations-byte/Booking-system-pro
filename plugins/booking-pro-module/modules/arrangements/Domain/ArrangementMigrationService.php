<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Domain;

use WP_Post;

use function absint;
use function array_sum;
use function array_values;
use function get_post_meta;
use function get_posts;
use function is_array;
use function json_decode;
use function sanitize_text_field;
use function sanitize_title;

final class ArrangementMigrationService
{
    public function __construct(private ArrangementRepository $repository = new ArrangementRepository())
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function migrateLegacyBundles(): array
    {
        $spots = get_posts(array(
            'post_type' => 'ddb_spot',
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => -1,
        ));

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($spots as $spot) {
            if (! $spot instanceof WP_Post) {
                continue;
            }

            $bundles = get_post_meta($spot->ID, '_ddb_bundles_json', true);
            $bundles = is_string($bundles) ? json_decode($bundles, true) : (is_array($bundles) ? $bundles : array());
            if (! is_array($bundles) || $bundles === array()) {
                $skipped++;
                continue;
            }

            foreach ($bundles as $index => $bundle) {
                if (! is_array($bundle)) {
                    continue;
                }

                $payload = $this->convertLegacyBundle($spot->ID, $bundle, (int) $index);
                if ($payload === array()) {
                    continue;
                }

                $payload['legacy_source'] = 'ddb_spot';
                $payload['legacy_bundle_id'] = (string) ($bundle['id'] ?? ($spot->ID . '-' . $index));
                $payload['creation_mode'] = 'fixed';
                $payload['arrangement_type'] = 'fixed';
                $payload['visibility'] = 'public';

                $existing = $this->findLegacyArrangement((string) $payload['legacy_bundle_id']);
                if ($existing > 0) {
                    $payload['id'] = $existing;
                    $this->repository->save($payload);
                    $updated++;
                } else {
                    $this->repository->save($payload);
                    $created++;
                }
            }
        }

        return array(
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        );
    }

    /**
     * @param int $spotId
     * @param array<string, mixed> $bundle
     * @param int $index
     * @return array<string, mixed>
     */
    public function convertLegacyBundle(int $spotId, array $bundle, int $index = 0): array
    {
        $title = sanitize_text_field((string) ($bundle['label'] ?? $bundle['title'] ?? 'Arrangement'));
        $items = is_array($bundle['items'] ?? null) ? $bundle['items'] : array();
        $segments = array();

        foreach ($items as $itemIndex => $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = absint((int) ($item['product_id'] ?? $item['id'] ?? 0));
            if ($productId <= 0) {
                continue;
            }

            $duration = absint((int) ($item['duration'] ?? $item['durationMinutes'] ?? 0));
            $segments[] = array(
                'id' => sanitize_text_field((string) ($item['id'] ?? ('legacy-' . $spotId . '-' . $index . '-' . $itemIndex))),
                'linked_product_id' => $productId,
                'segment_type' => (string) ($item['segment_type'] ?? 'activity'),
                'sequence' => $itemIndex,
                'required' => true,
                'timing_mode' => $this->mapTiming((string) ($item['timing'] ?? 'before')),
                'fixed_start_time' => '',
                'fixed_end_time' => '',
                'min_duration' => $duration,
                'max_duration' => $duration,
                'buffer_before' => 0,
                'buffer_after' => 0,
                'travel_buffer' => 0,
                'availability_source' => 'derived',
                'pricing_source' => 'derived',
                'ui_label' => sanitize_text_field((string) ($item['label'] ?? $item['title'] ?? '')),
                'is_optional' => false,
                'is_replaceable' => false,
            );
        }

        if ($segments === array()) {
            return array();
        }

        return array(
            'title' => $title,
            'slug' => sanitize_title((string) ($bundle['slug'] ?? $title)),
            'description' => sanitize_text_field((string) ($bundle['meta']['description'] ?? '')),
            'excerpt' => sanitize_text_field((string) ($bundle['meta']['description'] ?? '')),
            'status' => 'publish',
            'arrangement_type' => 'fixed',
            'creation_mode' => 'fixed',
            'visibility' => 'public',
            'categories' => array_values(array_filter(array_map('sanitize_title', is_array($bundle['meta']['tags'] ?? null) ? $bundle['meta']['tags'] : array()))),
            'tags' => array('legacy', 'combi'),
            'duration_total' => array_sum(array_map(static fn (array $segment): int => (int) ($segment['max_duration'] ?? 0), $segments)),
            'daypart' => sanitize_text_field((string) ($bundle['meta']['daypart'] ?? '')),
            'price_strategy' => 'sum_children',
            'base_price' => (float) ($bundle['meta']['base_price'] ?? 0.0),
            'currency' => 'EUR',
            'image_id' => absint((int) ($bundle['meta']['image_id'] ?? 0)),
            'gallery_ids' => array(),
            'featured' => ! empty($bundle['meta']['featured']),
            'sort_order' => $index,
            'template_id' => 0,
            'sales_product_id' => absint((int) ($bundle['sales_product_id'] ?? $bundle['product_id'] ?? 0)),
            'legacy_source' => 'ddb_spot',
            'legacy_bundle_id' => (string) ($bundle['id'] ?? $index),
            'segments' => $segments,
            'rules' => array(),
        );
    }

    private function mapTiming(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, array('after', 'post'), true) ? 'after_previous' : 'before_next';
    }

    private function findLegacyArrangement(string $legacyBundleId): int
    {
        $items = $this->repository->query(array('arrangement_type' => 'fixed'));
        foreach ($items as $item) {
            if ((string) ($item['legacy_source'] ?? '') !== 'ddb_spot') {
                continue;
            }
            if ((string) ($item['legacy_bundle_id'] ?? '') !== $legacyBundleId) {
                continue;
            }

            return (int) ($item['id'] ?? 0);
        }

        return 0;
    }
}
