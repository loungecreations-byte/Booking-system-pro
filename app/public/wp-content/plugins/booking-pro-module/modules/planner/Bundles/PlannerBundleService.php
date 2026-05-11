<?php

declare(strict_types=1);

namespace BSP\Planner\Bundles;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function __;
use function add_action;
use function add_filter;
use function apply_filters;
use function array_flip;
use function array_intersect_key;
use function array_slice;
use function array_values;
use function do_action;
use function is_array;
use function register_rest_route;

final class PlannerBundleService
{
    private BundleRegistry $registry;

    private bool $seeded = false;

    public function __construct(?BundleRegistry $registry = null)
    {
        $this->registry = $registry ?? new BundleRegistry();
    }

    public function init(): void
    {
        $this->maybeSeedDefaultBundles();

        add_action('bsp/planner/register_bundle', array($this, 'registerBundleFromAction'), 10, 1);
        add_action('rest_api_init', array($this, 'registerRestRoutes'));
        add_filter('sbdp_planner_bundles', array($this, 'exposeFrontendBundles'), 10, 1);
    }

    /**
     * @param BundleDefinition|array<string, mixed> $bundle
     */
    public function registerBundleFromAction($bundle): void
    {
        if ($bundle instanceof BundleDefinition) {
            $this->registry->register($bundle);

            return;
        }

        if (is_array($bundle)) {
            $this->registry->registerFromArray($bundle);
        }
    }

    /**
     * @param array<int, array<string, mixed>>|mixed $bundles
     *
     * @return array<int, array<string, mixed>>
     */
    public function exposeFrontendBundles($bundles): array
    {
        $normalized = array();

        if (is_array($bundles)) {
            foreach ($bundles as $bundle) {
                $snapshot = $this->toFrontendSnapshot($bundle);
                if (! $snapshot) {
                    continue;
                }

                $normalized[(string) $snapshot['id']] = $snapshot;
            }
        }

        foreach ($this->registry->all() as $bundle) {
            $snapshot = $this->toFrontendSnapshot($bundle);
            if (! $snapshot) {
                continue;
            }

            $normalized[(string) $snapshot['id']] = $snapshot;
        }

        return array_values($normalized);
    }

    public function registerRestRoutes(): void
    {
        register_rest_route(
            'sbdp/v1',
            '/planner/bundles',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'getBundles'),
                    'permission_callback' => array($this, 'allowPublicBundleAccess'),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'applyBundle'),
                    'permission_callback' => array($this, 'allowPublicBundleAccess'),
                    'args'                => array(
                        'bundle_id' => array(
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                    ),
                ),
            )
        );
    }

    public function getBundles(WP_REST_Request $request): WP_REST_Response
    {
        $bundles = array();

        foreach ($this->registry->all() as $bundle) {
            $snapshot = $this->toFrontendSnapshot($bundle);
            if (! $snapshot) {
                continue;
            }

            $bundles[] = $snapshot;
        }

        return new WP_REST_Response(
            array(
                'bundles' => $bundles,
            )
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function applyBundle(WP_REST_Request $request)
    {
        $bundleId = (string) $request->get_param('bundle_id');
        $bundle   = $this->registry->find($bundleId);

        if (! $bundle) {
            return new WP_Error(
                'sbdp_planner_bundle_not_found',
                __('Requested planner bundle could not be found.', 'sbdp'),
                array('status' => 404)
            );
        }

        do_action('sbdp/planner/bundle/applied', $bundle, $request);

        return new WP_REST_Response(
            array(
                'bundle'          => $bundle->toArray(),
                'compose_payload' => $bundle->toPayload(),
            )
        );
    }

    public function registry(): BundleRegistry
    {
        return $this->registry;
    }

    public function allowPublicBundleAccess(): bool
    {
        return true;
    }

    private function maybeSeedDefaultBundles(): void
    {
        if ($this->seeded) {
            return;
        }

        $defaultBundles = array(
            array(
                'id'    => 'DEMO-123',
                'label' => __('Demo Bundle', 'sbdp'),
                'items' => array(),
                'meta'  => array(
                    'description' => __('Sample bundle used for smoke tests.', 'sbdp'),
                ),
            ),
        );

        $defaultBundles = (array) apply_filters('sbdp/planner/default_bundles', $defaultBundles);

        foreach ($defaultBundles as $bundle) {
            if (! is_array($bundle)) {
                continue;
            }

            $this->registry->registerFromArray($bundle);
        }

        $this->seeded = true;
    }

    /**
     * @param array<string, mixed>|BundleDefinition $bundle
     *
     * @return array<string, mixed>|null
     */
    private function toFrontendSnapshot($bundle): ?array
    {
        if ($bundle instanceof BundleDefinition) {
            $snapshot = $bundle->toPreviewArray();
        } elseif (is_array($bundle)) {
            $id = (string) ($bundle['id'] ?? '');
            if ('' === $id) {
                return null;
            }

            $snapshot = array(
                'id'         => $id,
                'label'      => (string) ($bundle['label'] ?? $id),
                'item_count' => isset($bundle['item_count'])
                    ? (int) $bundle['item_count']
                    : $this->countItems($bundle),
            );

            $itemsSource = $this->resolveBundleItems($bundle);
            if ($itemsSource !== array()) {
                $items = $this->trimItemPreview($itemsSource);
                if ($items !== array()) {
                    $snapshot['items'] = $items;
                }
            }

            if (isset($bundle['meta']) && is_array($bundle['meta'])) {
                $meta = $this->filterPreviewMeta($bundle['meta']);
                if ($meta !== array()) {
                    $snapshot['meta'] = $meta;
                }
            }

            $payloadPreview = $this->filterPreviewPayload($bundle);
            if ($payloadPreview !== null) {
                $snapshot['payload'] = $payloadPreview;
            }
        } else {
            return null;
        }

        if (! isset($snapshot['id']) || '' === (string) $snapshot['id']) {
            return null;
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return array<int, mixed>
     */
    private function resolveBundleItems(array $bundle): array
    {
        if (isset($bundle['items']) && is_array($bundle['items'])) {
            return $bundle['items'];
        }

        foreach (array('payload', 'compose_payload', 'payload_overrides') as $payloadKey) {
            if (! isset($bundle[$payloadKey]) || ! is_array($bundle[$payloadKey])) {
                continue;
            }

            $payload = (array) $bundle[$payloadKey];
            if (isset($payload['items']) && is_array($payload['items'])) {
                return $payload['items'];
            }
        }

        return array();
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function countItems(array $bundle): int
    {
        $items = $this->resolveBundleItems($bundle);

        return $items === array() ? 0 : count($items);
    }

    /**
     * @param array<int, mixed> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function trimItemPreview(array $items): array
    {
        $limit = (int) apply_filters('sbdp/planner/bundle_preview_item_limit', 3);
        if ($limit <= 0) {
            return array();
        }

        $items = array_slice($items, 0, $limit);

        $allowedKeys = array_flip((array) apply_filters(
            'sbdp/planner/bundle_preview_item_keys',
            array('product_id', 'id', 'title', 'name', 'duration', 'channel', 'vendor')
        ));

        $preview = array();

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized = array_intersect_key($item, $allowedKeys);
            if ($normalized === array()) {
                continue;
            }

            $preview[] = $normalized;
        }

        return $preview;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function filterPreviewMeta(array $meta): array
    {
        if ($meta === array()) {
            return array();
        }

        $allowedKeys = array_flip((array) apply_filters(
            'sbdp/planner/bundle_preview_meta_keys',
            array('description', 'channel', 'vendor', 'image', 'tags', 'slug')
        ));

        $filtered = array_intersect_key($meta, $allowedKeys);

        return $filtered;
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function filterPreviewPayload(array $bundle): ?array
    {
        $payloadKeys = array('payload', 'compose_payload', 'payload_overrides');
        foreach ($payloadKeys as $payloadKey) {
            if (! isset($bundle[$payloadKey]) || ! is_array($bundle[$payloadKey])) {
                continue;
            }

            $payload = $bundle[$payloadKey];
            $allowed = array_flip((array) apply_filters(
            'sbdp/planner/bundle_preview_payload_keys',
            array('mode', 'items', 'bundle_id', 'window', 'participants', 'notes')
        ));

            $filtered = array_intersect_key($payload, $allowed);

            return $filtered !== array() ? $filtered : $payload;
        }

        return null;
    }
}
