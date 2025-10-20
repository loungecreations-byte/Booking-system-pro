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
                if (! is_array($bundle)) {
                    continue;
                }

                $id = (string) ($bundle['id'] ?? '');
                if ('' === $id) {
                    continue;
                }

                $normalized[$id] = $bundle;
            }
        }

        foreach ($this->registry->all() as $bundle) {
            $normalized[$bundle->getId()] = $bundle->toArray();
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
                    'permission_callback' => '__return_true',
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'applyBundle'),
                    'permission_callback' => '__return_true',
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
        $bundles = array_map(
            static function (BundleDefinition $bundle): array {
                return $bundle->toArray();
            },
            $this->registry->all()
        );

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
}