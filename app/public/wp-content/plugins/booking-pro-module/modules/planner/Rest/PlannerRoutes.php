<?php

declare(strict_types=1);

namespace SBDP\Modules\Planner\Rest;

use SBDP\Modules\Planner\Services\PlannerService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class PlannerRoutes
{
    public function __construct(private PlannerService $service)
    {
    }

    public function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            'booking/v1',
            '/planner/products',
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'get_products'),
                'permission_callback' => array($this, 'can_view_products'),
            )
        );

        register_rest_route(
            'booking/v1',
            '/planner/config',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_config'),
                'permission_callback' => array($this, 'allow_public_read'),
            )
        );

        register_rest_route(
            'booking/v1',
            '/planner/state',
            array(
                'methods'             => array('GET', 'POST'),
                'callback'            => array($this, 'planner_state'),
                'permission_callback' => array($this, 'can_manage_state'),
            )
        );

        register_rest_route(
            'booking/v1',
            '/planner/schedule',
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'generate_schedule'),
                'permission_callback' => array($this, 'can_manage'),
            )
        );

        register_rest_route(
            'booking/v1',
            '/planner/availability',
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'get_availability'),
                'permission_callback' => array($this, 'can_manage'),
            )
        );

        register_rest_route(
            'booking/v1',
            '/planner/guide-availability',
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'guide_availability'),
                'permission_callback' => array($this, 'can_manage'),
            )
        );
    }

    public function get_config(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return new WP_REST_Response(
            array(
                'config' => $this->service->getPlannerConfig(),
            )
        );
    }

    public function get_products(WP_REST_Request $request): WP_REST_Response
    {
        $filters = $request->get_params();
        $products = $this->service->listProducts(is_array($filters) ? $filters : array());
        $products = array_values(
            array_map(
                fn(array $product): array => $this->buildPublicProductDto($product),
                array_filter($products, 'is_array')
            )
        );

        return new WP_REST_Response(
            array(
                'products' => $products,
            )
        );
    }

    public function planner_state(WP_REST_Request $request): WP_REST_Response
    {
        $user_id = get_current_user_id();

        if (0 === $user_id) {
            return new WP_REST_Response(
                array(
                    'scenario_id' => null,
                    'scenario'    => null,
                    'scenarios'   => array(),
                    'state'       => array(),
                    'status'      => 'guest',
                )
            );
        }

        if ('POST' === $request->get_method()) {
            $state = $request->get_json_params();
            $state = is_array($state) ? $state : array();
            $stored = $this->service->storePlannerState($user_id, $state);
            $overview = $this->service->getPlannerState($user_id, (string) ($stored['scenario_id'] ?? ''));

            return new WP_REST_Response(
                array(
                    'scenario_id' => $overview['scenario_id'],
                    'scenario'    => $stored,
                    'scenarios'   => $overview['scenarios'],
                    'state'       => $stored['itinerary'] ?? array(),
                    'status'      => 'saved',
                )
            );
        }

        $scenarioIdParam = $request->get_param('scenario_id');
        $scenarioId = is_string($scenarioIdParam) && $scenarioIdParam !== '' ? $scenarioIdParam : null;
        $overview = $this->service->getPlannerState($user_id, $scenarioId);

        return new WP_REST_Response(
            array(
                'scenario_id' => $overview['scenario_id'],
                'scenario'    => $overview['scenario'],
                'scenarios'   => $overview['scenarios'],
                'state'       => $overview['state'],
                'status'      => 'loaded',
            )
        );
    }

    public function generate_schedule(WP_REST_Request $request): WP_REST_Response
    {
        $bookings = $request->get_json_params();
        $bookings = is_array($bookings['bookings'] ?? null) ? $bookings['bookings'] : array();

        return new WP_REST_Response(
            $this->service->buildSchedule($bookings)
        );
    }

    public function get_availability(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        $payload = is_array($payload) ? $payload : array();

        $allSlots = is_array($payload['all'] ?? null) ? $payload['all'] : array();
        $booked   = is_array($payload['booked'] ?? null) ? $payload['booked'] : array();

        return new WP_REST_Response(
            array(
                'available' => $this->service->availableSlots($allSlots, $booked),
            )
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function guide_availability(WP_REST_Request $request)
    {
        $guide_id = (int) ($request->get_param('guide_id') ?? 0);

        $ical = $request->get_param('ical');
        $ical = is_string($ical) ? trim($ical) : '';

        if ('' === $ical) {
            $url = $request->get_param('ical_url');
            if (is_string($url) && '' !== trim($url)) {
                $ical = $this->fetch_ical(trim($url)) ?? '';
            }
        } elseif (filter_var($ical, FILTER_VALIDATE_URL)) {
            $ical = $this->fetch_ical($ical) ?? '';
        }

        if ('' === $ical) {
            if ($guide_id > 0) {
                update_post_meta($guide_id, '_sbdp_cityguide_status', 'error');
                update_post_meta($guide_id, '_sbdp_cityguide_last_error', 'missing_ical');
            }

            do_action(
                'sbdp/planner/guide_sync_error',
                array(
                    'guide_id' => $guide_id,
                    'reason'   => 'missing_ical',
                )
            );

            return new WP_Error(
                'sbdp_planner_missing_ical',
                __('No iCal content provided.', 'sbdp'),
                array('status' => 400)
            );
        }

        $windows = $this->import_ical($ical);

        if (! empty($windows) && 0 !== $guide_id) {
            update_post_meta($guide_id, '_sbdp_cityguide_last_sync', gmdate(DATE_ATOM));
            update_post_meta($guide_id, '_sbdp_cityguide_status', 'synced');
            delete_post_meta($guide_id, '_sbdp_cityguide_last_error');

            do_action(
                'sbdp/planner/guide_synced',
                array(
                    'guide_id' => $guide_id,
                    'windows'  => $windows,
                )
            );
        } elseif (0 !== $guide_id) {
            update_post_meta($guide_id, '_sbdp_cityguide_last_sync', gmdate(DATE_ATOM));
            update_post_meta($guide_id, '_sbdp_cityguide_status', 'stale');
            update_post_meta($guide_id, '_sbdp_cityguide_last_error', 'empty_availability');

            do_action(
                'sbdp/planner/guide_sync_warning',
                array(
                    'guide_id' => $guide_id,
                    'reason'   => 'empty_availability',
                )
            );
        }

        return new WP_REST_Response(
            array(
                'guide_id' => $guide_id,
                'windows'  => $windows,
                'status'   => $windows !== array() ? 'synced' : 'stale',
            )
        );
    }

    private function fetch_ical(string $url): ?string
    {
        if ('' === $url) {
            return null;
        }

        if (function_exists('wp_remote_get')) {
            $response = wp_remote_get($url, array('timeout' => 15));
            if (is_wp_error($response)) {
                return null;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                return null;
            }

            return (string) wp_remote_retrieve_body($response);
        }

        $context  = stream_context_create(array('http' => array('timeout' => 15)));
        $contents = @file_get_contents($url, false, $context);

        return false === $contents ? null : $contents;
    }

    /**
     * Import iCal content using the legacy CityGuide importer when available.
     *
     * @return array<int, array<string, mixed>>
     */
    private function import_ical(string $ical): array
    {
        if (! class_exists('\BSP\Planner\Vendor\CityGuideICalImporter')) {
            return array();
        }

        $importer = new \BSP\Planner\Vendor\CityGuideICalImporter();
        $windows  = $importer->import($ical);

        return is_array($windows) ? $windows : array();
    }

    public function can_manage(): bool
    {
        if (! function_exists('current_user_can')) {
            return false;
        }

        return current_user_can('manage_sbdp_planner') || current_user_can('manage_woocommerce');
    }

    public function can_view_products(): bool
    {
        if (function_exists('current_user_can') && (current_user_can('manage_sbdp_planner') || current_user_can('manage_woocommerce'))) {
            return true;
        }

        $allowPublic = true;

        if (function_exists('apply_filters')) {
            $allowPublic = (bool) apply_filters('sbdp/planner/products/public_access', $allowPublic);
        }

        if ($allowPublic) {
            return true;
        }

        return function_exists('is_user_logged_in') ? is_user_logged_in() : false;
    }

    public function can_manage_state(): bool
    {
        if (! function_exists('is_user_logged_in') || ! is_user_logged_in()) {
            return false;
        }

        return current_user_can('manage_sbdp_planner') || current_user_can('manage_woocommerce') || current_user_can('read');
    }

    public function allow_public_read(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function buildPublicProductDto(array $product): array
    {
        $productId = isset($product['id']) ? (int) $product['id'] : 0;
        $currency = isset($product['pricing']['currency']) && is_string($product['pricing']['currency'])
            ? $product['pricing']['currency']
            : '';

        return array(
            'id' => $productId,
            'slug' => isset($product['slug']) ? sanitize_title((string) $product['slug']) : '',
            'title' => $this->sanitizeText($product['name'] ?? ($product['title'] ?? '')),
            'excerpt' => $this->resolveExcerpt($productId),
            'image' => $this->resolveImage($productId),
            'duration' => $this->sanitizeDuration($product['duration'] ?? null),
            'booking_capability' => $this->resolveBookingCapability($productId, $product),
            'availability_label' => $this->resolveAvailabilityLabel($productId, $product),
            'display_price' => $this->resolveDisplayPrice($product),
            'currency' => $currency,
            'location_label' => $this->resolveLocationLabel($product),
            'categories_public' => $this->resolvePublicCategories($productId),
            'permalink' => isset($product['permalink']) ? esc_url_raw((string) $product['permalink']) : '',
        );
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function sanitizeDuration($value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $minutes = isset($value['minutes']) ? (int) $value['minutes'] : 0;
        $sanitized = array(
            'value' => isset($value['value']) ? (int) $value['value'] : ($minutes > 0 ? $minutes : null),
            'unit' => isset($value['unit']) ? sanitize_key((string) $value['unit']) : 'minutes',
        );

        if ($minutes > 0) {
            $sanitized['minutes'] = $minutes;
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function resolveDisplayPrice(array $product): ?float
    {
        $pricing = is_array($product['pricing'] ?? null) ? $product['pricing'] : array();
        $dynamic = is_array($pricing['dynamic'] ?? null) ? $pricing['dynamic'] : array();

        foreach (array($dynamic['total'] ?? null, $pricing['base'] ?? null, $pricing['per_person'] ?? null) as $candidate) {
            if (is_numeric($candidate)) {
                return round((float) $candidate, 2);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function resolveLocationLabel(array $product): string
    {
        $outlets = $product['outlets'] ?? array();
        if (! is_array($outlets)) {
            return '';
        }

        foreach ($outlets as $outlet) {
            if (! is_array($outlet)) {
                continue;
            }

            $label = $this->sanitizeText($outlet['name'] ?? ($outlet['label'] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $product
     * @return array<int, array<string, string>>
     */
    private function resolvePublicCategories(int $productId): array
    {
        if (! function_exists('wp_get_post_terms')) {
            return array();
        }

        $terms = wp_get_post_terms($productId, 'product_cat');
        if (is_wp_error($terms) || ! is_array($terms)) {
            return array();
        }

        $categories = array();
        foreach ($terms as $term) {
            if (! isset($term->slug, $term->name)) {
                continue;
            }

            $categories[] = array(
                'slug' => sanitize_title((string) $term->slug),
                'label' => $this->sanitizeText((string) $term->name),
            );
        }

        return $categories;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function resolveBookingCapability(int $productId, array $product): string
    {
        $candidate = $this->normalizeCapability($product['booking_capability'] ?? null);
        if ($candidate !== null) {
            return $candidate;
        }

        if ($this->productRequiresConfirmation($productId)) {
            return 'REQUEST';
        }

        return 'DIRECT_LIMITED';
    }

    /**
     * @param array<string, mixed> $product
     */
    private function resolveAvailabilityLabel(int $productId, array $product): string
    {
        $capability = $this->resolveBookingCapability($productId, $product);

        if ($capability === 'REQUEST') {
            return 'Op aanvraag';
        }

        if ($capability === 'UNAVAILABLE') {
            return 'Momenteel niet beschikbaar';
        }

        return 'Beschikbaarheid wordt bevestigd bij selectie';
    }

    private function resolveExcerpt(int $productId): string
    {
        if (! function_exists('get_the_excerpt')) {
            return '';
        }

        return $this->sanitizeText(wp_strip_all_tags((string) get_the_excerpt($productId)));
    }

    private function resolveImage(int $productId): string
    {
        if (! function_exists('get_the_post_thumbnail_url')) {
            return '';
        }

        return esc_url_raw((string) get_the_post_thumbnail_url($productId, 'medium'));
    }

    /**
     * @param mixed $value
     */
    private function sanitizeText($value): string
    {
        return sanitize_text_field((string) $value);
    }

    /**
     * @param mixed $value
     */
    private function normalizeCapability($value): ?string
    {
        $normalized = strtoupper(trim((string) $value));
        if (in_array($normalized, array('DIRECT', 'DIRECT_LIMITED', 'REQUEST', 'UNAVAILABLE'), true)) {
            return $normalized;
        }

        return null;
    }

    private function productRequiresConfirmation(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }

        $wcFlag = get_post_meta($productId, '_wc_booking_requires_confirmation', true);
        if ($wcFlag === 'yes' || $wcFlag === '1' || $wcFlag === 1 || $wcFlag === true) {
            return true;
        }

        $bookable = get_post_meta($productId, '_sbdp_bookable', true);
        if (is_array($bookable)) {
            $flag = $bookable['booking_requires_confirmation'] ?? null;
            return $flag === 'yes' || $flag === '1' || $flag === 1 || $flag === true;
        }

        return false;
    }
}

class_alias(PlannerRoutes::class, 'BSP\Planner\Rest\Controller');
