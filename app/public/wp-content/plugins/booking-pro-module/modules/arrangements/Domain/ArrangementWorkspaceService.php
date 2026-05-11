<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Domain;

use function __;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function sanitize_key;
use function sanitize_text_field;
use function sprintf;
use function wc_get_product;

final class ArrangementWorkspaceService
{
    private ?BookableProductLookupService $productLookup = null;

    /**
     * @param array<string, mixed> $arrangement
     * @return array<string, mixed>
     */
    public function build(array $arrangement): array
    {
        $segments = $this->projectSegments(is_array($arrangement['segments'] ?? null) ? $arrangement['segments'] : array());
        $validation = $this->validate($arrangement, $segments);

        return array(
            'aggregate' => array(
                'identity' => array(
                    'id' => (int) ($arrangement['id'] ?? 0),
                    'title' => (string) ($arrangement['title'] ?? ''),
                    'status' => (string) ($arrangement['status'] ?? 'draft'),
                    'visibility' => (string) ($arrangement['visibility'] ?? 'public'),
                    'arrangement_type' => (string) ($arrangement['arrangement_type'] ?? 'fixed'),
                    'creation_mode' => (string) ($arrangement['creation_mode'] ?? 'fixed'),
                ),
                'commercial' => array(
                    'sales_product_id' => (int) ($arrangement['sales_product_id'] ?? 0),
                    'template_id' => (int) ($arrangement['template_id'] ?? 0),
                    'price_strategy' => (string) ($arrangement['price_strategy'] ?? 'sum_children'),
                    'base_price' => (float) ($arrangement['base_price'] ?? 0.0),
                    'currency' => (string) ($arrangement['currency'] ?? 'EUR'),
                ),
                'planning' => array(
                    'duration_total' => (int) ($arrangement['duration_total'] ?? 0),
                    'daypart' => (string) ($arrangement['daypart'] ?? ''),
                    'sort_order' => (int) ($arrangement['sort_order'] ?? 0),
                ),
                'presentation' => array(
                    'featured' => ! empty($arrangement['featured']),
                    'excerpt' => (string) ($arrangement['excerpt'] ?? ''),
                    'categories' => is_array($arrangement['categories'] ?? null) ? array_values($arrangement['categories']) : array(),
                    'tags' => is_array($arrangement['tags'] ?? null) ? array_values($arrangement['tags']) : array(),
                ),
                'segments' => $segments,
                'rules' => is_array($arrangement['rules'] ?? null) ? array_values($arrangement['rules']) : array(),
            ),
            'validation' => $validation,
            'summary' => $this->buildSummary($arrangement, $segments, $validation),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     * @return array<int, array<string, mixed>>
     */
    public function projectSegments(array $segments): array
    {
        $projected = array();

        foreach (array_values($segments) as $index => $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $sequence = max(0, (int) ($segment['sequence'] ?? $index));
            $linkedProductId = (int) ($segment['linked_product_id'] ?? 0);
            $role = $this->normalizeRole((string) ($segment['role'] ?? ''), $index, $segment);
            $timing = $this->mapRoleToTiming($role);
            $duration = max(0, (int) ($segment['max_duration'] ?? $segment['min_duration'] ?? 0));
            $productLabel = $this->resolveProductLabel($linkedProductId);
            $productSnapshot = $linkedProductId > 0 ? $this->getProductLookup()->getSnapshot($linkedProductId) : null;
            $title = sanitize_text_field((string) ($segment['title_override'] ?? $segment['ui_label'] ?? ''));
            if ($title === '') {
                $title = $productLabel !== '' ? $productLabel : sprintf(__('Onderdeel %d', 'sbdp'), $index + 1);
            }

            $projected[] = array(
                'id' => (string) ($segment['id'] ?? ''),
                'sequence' => $sequence,
                'title' => $title,
                'role' => $role,
                'timing' => $timing,
                'segment_type' => (string) ($segment['segment_type'] ?? 'activity'),
                'linked_product_id' => $linkedProductId,
                'linked_resource_id' => (int) ($segment['linked_resource_id'] ?? 0),
                'product_label' => $productLabel,
                'product_snapshot' => $productSnapshot,
                'duration' => $duration,
                'fixed_start_time' => (string) ($segment['fixed_start_time'] ?? ''),
                'buffer_before' => (int) ($segment['buffer_before'] ?? 0),
                'buffer_after' => (int) ($segment['buffer_after'] ?? 0),
                'travel_buffer' => (int) ($segment['travel_buffer'] ?? 0),
                'required' => ! empty($segment['required']),
                'is_optional' => ! empty($segment['is_optional']),
                'is_hidden' => ! empty($segment['is_hidden']),
                'is_replaceable' => ! empty($segment['is_replaceable']),
                'availability_source' => (string) ($segment['availability_source'] ?? 'derived'),
                'pricing_source' => (string) ($segment['pricing_source'] ?? 'derived'),
                'notes' => is_string($segment['notes'] ?? null) ? (string) $segment['notes'] : '',
            );
        }

        usort(
            $projected,
            static fn (array $left, array $right): int => ((int) ($left['sequence'] ?? 0)) <=> ((int) ($right['sequence'] ?? 0))
        );

        return array_values($projected);
    }

    /**
     * @param array<string, mixed> $arrangement
     * @param array<int, array<string, mixed>> $segments
     * @return array<string, array<int, string>>
     */
    public function validate(array $arrangement, array $segments): array
    {
        $errors = array();
        $warnings = array();

        if (trim((string) ($arrangement['title'] ?? '')) === '') {
            $errors[] = (string) __('Titel ontbreekt.', 'sbdp');
        }

        if ($segments === array()) {
            $errors[] = (string) __('Voeg minimaal één arrangementonderdeel toe.', 'sbdp');
        }

        $anchorCount = 0;
        $visibleSegments = 0;
        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }
            if (! empty($segment['is_hidden'])) {
                continue;
            }

            $visibleSegments++;
            if (($segment['role'] ?? '') === 'anchor') {
                $anchorCount++;
            }
            if ((int) ($segment['linked_product_id'] ?? 0) <= 0) {
                $errors[] = sprintf(
                    __('Onderdeel "%s" heeft geen gekoppeld product.', 'sbdp'),
                    (string) ($segment['title'] ?? __('Onbenoemd onderdeel', 'sbdp'))
                );
            } elseif (! is_array($segment['product_snapshot'] ?? null)) {
                $errors[] = sprintf(
                    __('Onderdeel "%s" verwijst naar een ongeldig of niet-boekbaar Woo product.', 'sbdp'),
                    (string) ($segment['title'] ?? __('Onbenoemd onderdeel', 'sbdp'))
                );
            }
            if (($segment['fixed_start_time'] ?? '') !== '' && (int) ($segment['duration'] ?? 0) <= 0) {
                $warnings[] = sprintf(
                    __('Onderdeel "%s" heeft wel een vaste starttijd maar geen duur.', 'sbdp'),
                    (string) ($segment['title'] ?? __('Onbenoemd onderdeel', 'sbdp'))
                );
            }
        }

        if ($visibleSegments === 0) {
            $errors[] = (string) __('Alle onderdelen staan verborgen; er blijft niets over om te plannen of te tonen.', 'sbdp');
        }
        if ($anchorCount !== 1) {
            $errors[] = (string) __('Er moet exact één hoofdactiviteit (anchor) zijn.', 'sbdp');
        }

        $visibility = (string) ($arrangement['visibility'] ?? 'public');
        $salesProductId = (int) ($arrangement['sales_product_id'] ?? 0);
        if (in_array($visibility, array('public', 'internal'), true) && $salesProductId <= 0) {
            $errors[] = (string) __('Publieke of interne arrangementen hebben een Woo sales product ID nodig.', 'sbdp');
        }

        $durationTotal = (int) ($arrangement['duration_total'] ?? 0);
        $segmentDuration = array_sum(array_map(static fn (array $segment): int => (int) ($segment['duration'] ?? 0), $segments));
        if ($durationTotal > 0 && $segmentDuration > 0 && $durationTotal < $segmentDuration) {
            $warnings[] = (string) __('Totale duur is korter dan de som van de onderdeel-duren.', 'sbdp');
        }

        return array(
            'errors' => array_values(array_filter($errors)),
            'warnings' => array_values(array_filter($warnings)),
        );
    }

    /**
     * @param array<string, mixed> $arrangement
     * @param array<int, array<string, mixed>> $segments
     * @param array<string, array<int, string>> $validation
     * @return array<string, mixed>
     */
    private function buildSummary(array $arrangement, array $segments, array $validation): array
    {
        $roles = array(
            'anchor' => array(),
            'pre' => array(),
            'post' => array(),
        );
        foreach ($segments as $segment) {
            if (! is_array($segment) || ! empty($segment['is_hidden'])) {
                continue;
            }

            $role = (string) ($segment['role'] ?? 'post');
            if (! isset($roles[$role])) {
                $roles[$role] = array();
            }
            $roles[$role][] = $segment;
        }

        $programRows = array();
        foreach (array('pre', 'anchor', 'post') as $role) {
            foreach ($roles[$role] as $segment) {
                $programRows[] = array(
                    'label' => (string) ($segment['title'] ?? ''),
                    'role' => $this->roleLabel((string) ($segment['role'] ?? 'post')),
                    'timing' => (string) ($segment['fixed_start_time'] ?? ''),
                    'duration' => (int) ($segment['duration'] ?? 0),
                    'product' => (string) ($segment['product_label'] ?? ''),
                );
            }
        }

        return array(
            'headline' => (string) ($arrangement['title'] ?? ''),
            'status' => ((count($validation['errors']) ?? 0) > 0) ? 'invalid' : (((count($validation['warnings']) ?? 0) > 0) ? 'warning' : 'valid'),
            'segment_count' => count($segments),
            'visible_segment_count' => count(array_filter($segments, static fn (array $segment): bool => empty($segment['is_hidden']))),
            'anchor_title' => isset($roles['anchor'][0]['title']) ? (string) $roles['anchor'][0]['title'] : '',
            'program_rows' => $programRows,
            'planner_window' => $this->plannerWindowLabel($segments, (int) ($arrangement['duration_total'] ?? 0)),
            'commerce' => array(
                'sales_product_id' => (int) ($arrangement['sales_product_id'] ?? 0),
                'price_strategy' => (string) ($arrangement['price_strategy'] ?? 'sum_children'),
                'base_price' => (float) ($arrangement['base_price'] ?? 0.0),
                'currency' => (string) ($arrangement['currency'] ?? 'EUR'),
            ),
            'notes' => implode(' ', array_values(array_filter($validation['warnings'] ?? array()))),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     */
    private function plannerWindowLabel(array $segments, int $durationTotal): string
    {
        $firstFixed = '';
        foreach ($segments as $segment) {
            if (! is_array($segment) || ! empty($segment['is_hidden'])) {
                continue;
            }
            $fixed = trim((string) ($segment['fixed_start_time'] ?? ''));
            if ($fixed !== '') {
                $firstFixed = $fixed;
                break;
            }
        }

        if ($firstFixed !== '' && $durationTotal > 0) {
            return sprintf(__('Start %1$s · %2$d min totaal', 'sbdp'), $firstFixed, $durationTotal);
        }
        if ($durationTotal > 0) {
            return sprintf(__('%d min totaal', 'sbdp'), $durationTotal);
        }

        return (string) __('Nog geen plannerprojectie beschikbaar.', 'sbdp');
    }

    /**
     * @param array<string, mixed> $segment
     */
    private function normalizeRole(string $role, int $index, array $segment): string
    {
        $role = sanitize_key($role);
        if (in_array($role, array('anchor', 'pre', 'post'), true)) {
            return $role;
        }

        $timingMode = sanitize_key((string) ($segment['timing_mode'] ?? ''));
        if ($index === 0 || $timingMode === 'fixed_start') {
            return 'anchor';
        }
        if ($timingMode === 'before_next') {
            return 'pre';
        }

        return 'post';
    }

    private function mapRoleToTiming(string $role): string
    {
        if ($role === 'pre') {
            return 'before';
        }
        if ($role === 'anchor') {
            return 'anchor';
        }

        return 'after';
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'pre' => (string) __('Vooraf', 'sbdp'),
            'anchor' => (string) __('Hoofdactiviteit', 'sbdp'),
            default => (string) __('Achteraf', 'sbdp'),
        };
    }

    private function resolveProductLabel(int $productId): string
    {
        if ($productId <= 0 || ! function_exists('wc_get_product')) {
            return '';
        }

        $product = wc_get_product($productId);
        if (! $product instanceof \WC_Product) {
            return '';
        }

        return sanitize_text_field((string) $product->get_name());
    }

    private function getProductLookup(): BookableProductLookupService
    {
        if ($this->productLookup === null) {
            $this->productLookup = new BookableProductLookupService();
        }

        return $this->productLookup;
    }
}
