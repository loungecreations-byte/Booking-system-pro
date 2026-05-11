<?php

declare(strict_types=1);

namespace SBDP\Modules\Planner\Services;

use BSPModule\Core\Rest\RestService as CoreRestService;
use BSPModule\Core\Resource\ResourceCalendar;
use DateInterval;
use DateTimeImmutable;
use WP_Post;
use WP_Query;
use SBDP\Pricing\PricingService as CorePricingService;

class PlannerService
{
    private const SCENARIOS_META_KEY = '_sbdp_planner_scenarios';
    private const LEGACY_STATE_META_KEY = '_sbdp_planner_state';
    private const SCENARIO_VERSION = 2;
    private const DEFAULT_DURATION_MINUTES = 90;
    private const DEFAULT_BUFFER_MINUTES = 15;

    /**
     * Produce an ordered schedule from booking entries.
     *
     * @param array<int, array<string, mixed>> $bookings
     * @return array<int, array<string, string>>
     */
    public function generateSchedule(array $bookings): array
    {
        return $this->buildSchedule($bookings)['timeline'];
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     * @return array<string, mixed>
     */
    public function buildSchedule(array $bookings): array
    {
        $windows   = $this->buildBookingWindows($bookings);
        $timeline  = array();

        foreach ($windows as $window) {
            $timeline[] = array(
                'slot'     => $window['slot'],
                'label'    => $window['label'],
                'resource' => $window['resource'],
            );
        }

        usort(
            $timeline,
            static fn(array $left, array $right): int => strcmp($left['slot'], $right['slot'])
        );

        $conflicts = $this->conflictsFromWindows($windows);

        return array(
            'timeline'  => $timeline,
            'windows'   => $windows,
            'conflicts' => $conflicts,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     * @return array<int, array<string, mixed>>
     */
    public function detectConflicts(array $bookings): array
    {
        $windows = $this->buildBookingWindows($bookings);

        return $this->conflictsFromWindows($windows);
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     * @return array<int, array<string, mixed>>
     */
    private function buildBookingWindows(array $bookings): array
    {
        $windows = array();

        foreach ($bookings as $index => $booking) {
            if (! is_array($booking)) {
                continue;
            }

            $window = $this->normalizeBookingWindow($booking, (int) $index);
            if ($window === null) {
                continue;
            }

            $windows[] = $window;
        }

        return $windows;
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function normalizeBookingWindow(array $booking, int $index): ?array
    {
        $label = $this->sanitizePlainText($booking['label'] ?? ($booking['name'] ?? ''));
        $resource = $this->sanitizePlainText($booking['resource'] ?? '');
        $resourceLabel = $this->sanitizePlainText($booking['resource_label'] ?? '');
        if ($resourceLabel === '' && $resource !== '' && function_exists('get_post')) {
            $resourcePost = get_post((int) $resource);
            if ($resourcePost instanceof WP_Post) {
                $resourceLabel = $resourcePost->post_title;
            }
        }

        $startRaw = (string) ($booking['start'] ?? ($booking['time'] ?? ''));
        $endRaw   = (string) ($booking['end'] ?? '');

        $start = $this->normaliseDateTimeForStorage($startRaw);
        $end   = $this->normaliseDateTimeForStorage($endRaw);

        $duration = $this->normalizeDurationFromItem($booking);
        $startTs  = $this->parsePlannerTime($start);
        if ($startTs === null) {
            return null;
        }

        $endTs = $this->parsePlannerTime($end);
        if ($endTs === null) {
            $endTs = $startTs + max(1, $duration) * 60;
            $end   = gmdate(DateTimeImmutable::ATOM, $endTs);
        }

        $bufferBefore = $this->normalizePositiveInt($booking['buffer_before'] ?? ($booking['buffer'] ?? 0), 0);
        $bufferAfter  = $this->normalizePositiveInt($booking['buffer_after'] ?? 0, 0);

        $quantity = $this->normalizePositiveInt($booking['quantity'] ?? ($booking['people'] ?? 1), 1);
        $capacity = $this->normalizePositiveInt($booking['capacity'] ?? ($booking['pax'] ?? 0), 0);
        if ($capacity <= 0) {
            $capacity = $quantity;
        }

        $productId = isset($booking['product_id']) ? (int) $booking['product_id'] : 0;
        $capacityLimit = $this->lookupCapacityLimit($resource, $productId, $booking);

        $bufferedStart = $startTs - max(0, $bufferBefore) * 60;
        $bufferedEnd   = $endTs + max(0, $bufferAfter) * 60;

        return array(
            'index'           => $index,
            'slot'            => $start,
            'label'           => $label !== '' ? $label : sprintf('#%d', $index + 1),
            'resource'        => $resource,
            'resource_label'  => $resourceLabel,
            'channel'         => $this->sanitizePlainText($booking['channel'] ?? ''),
            'product_id'      => $productId,
            'quantity'        => $quantity,
            'capacity'        => $capacity,
            'capacity_limit'  => $capacityLimit,
            'buffer_before'   => $bufferBefore,
            'buffer_after'    => $bufferAfter,
            'start'           => $start,
            'end'             => $end,
            'start_ts'        => $startTs,
            'end_ts'          => $endTs,
            'buffered_start'  => $bufferedStart,
            'buffered_end'    => $bufferedEnd,
            'notes'           => $this->sanitizeTextarea($booking['notes'] ?? ''),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $windows
     * @return array<int, array<string, mixed>>
     */
    private function conflictsFromWindows(array $windows): array
    {
        $conflicts   = array();
        $byResource  = array();

        foreach ($windows as $window) {
            $resourceKey = $window['resource'] !== '' ? $window['resource'] : '_unassigned';
            if (! isset($byResource[$resourceKey])) {
                $byResource[$resourceKey] = array();
            }
            $byResource[$resourceKey][] = $window;
        }

        foreach ($byResource as $resourceKey => $segments) {
            usort(
                $segments,
                static fn(array $left, array $right): int => $left['buffered_start'] <=> $right['buffered_start']
            );

            $active = array();
            foreach ($segments as $segment) {
                foreach ($active as $activeKey => $activeSegment) {
                    if ($activeSegment['buffered_end'] <= $segment['buffered_start']) {
                        unset($active[$activeKey]);
                    }
                }

                $overlapping = array();
                $capacityUsed = $segment['quantity'];

                foreach ($active as $activeSegment) {
                    if ($activeSegment['buffered_end'] > $segment['buffered_start']) {
                        $overlapping[] = $activeSegment;
                        $capacityUsed += $activeSegment['quantity'];
                    }
                }

                if ($overlapping !== array()) {
                    $conflicts[] = array(
                        'type'            => 'time_overlap',
                        'resource'        => $resourceKey,
                        'resource_label'  => $segment['resource_label'],
                        'channel'         => $segment['channel'],
                        'segment'         => $this->conflictSegmentPayload($segment),
                        'overlapping'     => array_map(
                            fn(array $item): array => $this->conflictSegmentPayload($item),
                            $overlapping
                        ),
                    );
                }

                $capacityLimit = $segment['capacity_limit'];
                if ($capacityLimit !== null && $capacityLimit > 0 && $capacityUsed > $capacityLimit) {
                    $conflicts[] = array(
                        'type'            => 'capacity',
                        'resource'        => $resourceKey,
                        'resource_label'  => $segment['resource_label'],
                        'capacity_limit'  => $capacityLimit,
                        'capacity_used'   => $capacityUsed,
                        'segment'         => $this->conflictSegmentPayload($segment),
                        'overlapping'     => array_map(
                            fn(array $item): array => $this->conflictSegmentPayload($item),
                            $overlapping
                        ),
                    );
                }

                $active[] = $segment;
            }
        }

        return array_values($conflicts);
    }

    /**
     * @param array<string, mixed> $segment
     */
    private function conflictSegmentPayload(array $segment): array
    {
        return array(
            'index'    => $segment['index'],
            'label'    => $segment['label'],
            'start'    => $segment['start'],
            'end'      => $segment['end'],
            'channel'  => $segment['channel'],
            'resource' => $segment['resource'],
            'quantity' => $segment['quantity'],
        );
    }

    private function lookupCapacityLimit(string $resourceId, int $productId, array $booking): ?int
    {
        if (isset($booking['capacity_limit']) && is_numeric($booking['capacity_limit'])) {
            $limit = (int) $booking['capacity_limit'];
            return $limit > 0 ? $limit : null;
        }

        if ($resourceId !== '') {
            $limit = (int) get_post_meta((int) $resourceId, '_sbdp_capacity', true);
            if ($limit > 0) {
                return $limit;
            }
        }

        if ($productId > 0) {
            $limit = (int) get_post_meta($productId, '_sbdp_capacity', true);
            if ($limit > 0) {
                return $limit;
            }
        }

        return null;
    }

    /**
     * Determine which time slots remain available after filtering out booked slots.
     *
     * @param array<int, string> $allSlots
     * @param array<int, string> $bookedSlots
     * @return array<int, string>
     */
    public function availableSlots(array $allSlots, array $bookedSlots): array
    {
        $remaining = array_values(array_diff($allSlots, $bookedSlots));
        sort($remaining);

        return $remaining;
    }

    /**
     * Validate the required fields on a booking payload.
     *
     * @param array<string, mixed> $booking
     * @return array<int, string>
     */
    public function validateBooking(array $booking): array
    {
        $errors = array();

        if (empty($booking['time'])) {
            $errors[] = 'time_required';
        }

        if (empty($booking['name'])) {
            $errors[] = 'name_required';
        }

        return $errors;
    }

    /**
     * Fetch product records for the planner UI.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listProducts(array $filters = array()): array
    {
        if (! function_exists('wc_get_products')) {
            return array();
        }

        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 20;
        $limit = $limit > 0 ? $limit : 20;

        $args = array(
            'status'   => 'publish',
            'limit'    => $limit,
            'return'   => 'ids',
            'paginate' => false,
            'orderby'  => 'title',
            'order'    => 'ASC',
        );

        if (! empty($filters['search'])) {
            $args['search'] = (string) $filters['search'];
        }

        if (! empty($filters['ids'])) {
            $ids = $this->normalizeFilterArray($filters['ids']);
            if ($ids !== array()) {
                $args['include'] = array_map('intval', $ids);
                $args['limit']   = count($args['include']);
            }
        }

		if (! empty($filters['type'])) {
            $types = $this->normalizeFilterArray($filters['type']);
            if ($types !== array()) {
                $args['type'] = array_map('sanitize_key', $types);
            }
        }

        if (! isset($args['type'])) {
            $defaultTypes = array('bookable_service');
            if (function_exists('apply_filters')) {
                $defaultTypes = (array) apply_filters('sbdp/planner/default_product_types', $defaultTypes, $filters);
            }

            $args['type'] = array_values(array_unique(array_map('sanitize_key', $defaultTypes)));
        }

        $taxQuery = array();
        if (! empty($filters['outlet']) && function_exists('taxonomy_exists') && taxonomy_exists('sbdp_outlet')) {
            $outlets = $this->normalizeFilterArray($filters['outlet']);
            if ($outlets !== array()) {
                $taxQuery[] = array(
                    'taxonomy' => 'sbdp_outlet',
                    'field'    => 'slug',
                    'terms'    => array_map('sanitize_title', $outlets),
                );
            }
        }

        if (! empty($filters['category'])) {
            $categories = $this->normalizeFilterArray($filters['category']);
            if ($categories !== array()) {
                $taxQuery[] = array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => array_map('sanitize_title', $categories),
                );
            }
        }

        if ($taxQuery !== array()) {
            if (count($taxQuery) > 1) {
                $taxQuery['relation'] = 'AND';
            }

            $args['tax_query'] = $taxQuery;
        }

        $metaQuery = array();

        if (! empty($filters['resource'])) {
            $resourceQuery = array('relation' => 'OR');
            foreach ($this->normalizeFilterArray($filters['resource']) as $resourceId) {
                $resourceId = trim((string) $resourceId);
                if ($resourceId === '') {
                    continue;
                }

                $resourceQuery[] = array(
                    'key'     => '_sbdp_resource_ids',
                    'value'   => '"' . $resourceId . '"',
                    'compare' => 'LIKE',
                );
            }

            if (count($resourceQuery) > 1) {
                $metaQuery[] = $resourceQuery;
            }
        }

        $capacityMin = isset($filters['capacity_min']) ? (int) $filters['capacity_min'] : null;
        if ($capacityMin !== null && $capacityMin > 0) {
            $metaQuery[] = array(
                'key'     => '_sbdp_capacity',
                'value'   => $capacityMin,
                'type'    => 'NUMERIC',
                'compare' => '>=',
            );
        }

        $capacityMax = isset($filters['capacity_max']) ? (int) $filters['capacity_max'] : null;
        if ($capacityMax !== null && $capacityMax > 0) {
            $metaQuery[] = array(
                'key'     => '_sbdp_capacity',
                'value'   => $capacityMax,
                'type'    => 'NUMERIC',
                'compare' => '<=',
            );
        }

        if (! empty($filters['channel'])) {
            $channelQuery = array('relation' => 'OR');
            foreach ($this->normalizeFilterArray($filters['channel']) as $channel) {
                $channel = trim((string) $channel);
                if ($channel === '') {
                    continue;
                }

                $channelQuery[] = array(
                    'key'     => '_sbdp_distribution_channels',
                    'value'   => '"' . $channel . '"',
                    'compare' => 'LIKE',
                );
            }

            if (count($channelQuery) > 1) {
                $metaQuery[] = $channelQuery;
            }
        }

        if ($this->shouldExcludeDemoProducts($filters)) {
            $metaQuery[] = $this->buildDemoProductExclusionClause();
        }

        if ($metaQuery !== array()) {
            $metaQuery['relation'] = 'AND';
            $args['meta_query']    = $metaQuery;
        }

        /**
         * Allow third parties to modify the product query for the planner.
         *
         * @param array<string, mixed> $args
         * @param array<string, mixed> $filters
         */
        $args = apply_filters('sbdp_planner_products_query_args', $args, $filters);

        $product_ids = wc_get_products($args);
        if (! is_array($product_ids)) {
            return array();
        }

        $currency = $this->getCurrency();

        $products = array();

        foreach ($product_ids as $product_id) {
            $product = wc_get_product((int) $product_id);
            if (! $product) {
                continue;
            }

            $product_id = $product->get_id();

            $pricingDetails    = CorePricingService::instance()->getProductPricing($product_id, array('price_mode' => 'gross'));
            $base_price        = (float) ($pricingDetails['base_price'] ?? 0.0);
            $per_person_price  = (float) ($pricingDetails['per_person'] ?? 0.0);
            $supports_persons  = ! empty($pricingDetails['supports_persons']);
            $fixed_fee         = (float) ($pricingDetails['fixed_fee'] ?? 0.0);
            $duration_raw      = get_post_meta($product_id, '_sbdp_duration', true);
            $duration_value    = $this->normaliseDurationValue($duration_raw);
            $duration_unit     = (string) get_post_meta($product_id, '_sbdp_duration_unit', true);
            if ($duration_value <= 0) {
                $duration_value = 90;
                $duration_unit  = 'minutes';
            }
            $duration_minutes  = $this->convertDurationToMinutes($duration_value, $duration_unit);
            if ($duration_minutes <= 0) {
                $duration_minutes = 90;
            }
            $default_start     = array(
                'date' => (string) get_post_meta($product_id, '_sbdp_default_start_date', true),
                'time' => (string) get_post_meta($product_id, '_sbdp_default_start_time', true),
            );
            $availability_default = get_post_meta($product_id, '_sbdp_default_hours', true);
            $availability_rules   = get_post_meta($product_id, '_sbdp_availability_rules', true);

            $availability = array(
                'default_hours' => $this->decode_meta_json($availability_default),
                'rules'         => $this->decode_meta_json($availability_rules),
                'summary'       => $this->summarizeAvailability($product_id),
            );
            $resource_ids = get_post_meta($product_id, '_sbdp_resource_ids', true);
            if (! is_array($resource_ids)) {
                $resource_ids = array();
            }
            $resource_ids = array_values(array_filter(array_map('intval', $resource_ids)));
            $resource_summary = $this->summarizeResources($product_id, $resource_ids);
            $primary_resource_id = $resource_ids !== array() ? (int) $resource_ids[0] : 0;
            $calendar_blocks = $primary_resource_id > 0 ? ResourceCalendar::get_calendar_blocks($primary_resource_id) : array();
            $calendar_last_sync = $primary_resource_id > 0 ? ResourceCalendar::get_last_sync($primary_resource_id) : null;
            $calendar_status = $primary_resource_id > 0 ? ResourceCalendar::get_status($primary_resource_id) : '';
            $primary_resource_id = isset($resource_ids[0]) ? (int) $resource_ids[0] : 0;

            $people = $this->resolvePeopleConfiguration($product_id, $supports_persons, $product);
            if (! $supports_persons) {
                $per_person_price = 0.0;
            }

            $dynamicQuote = $this->calculateDynamicPricing(
                $product_id,
                max(1, isset($people['default']) ? (int) $people['default'] : (int) $people['min']),
                $currency
            );

            $pricing = array(
                'base'             => $base_price,
                'per_person'       => $per_person_price,
                'fixed_fee'        => $fixed_fee,
                'currency'         => $currency,
                'supports_persons' => $supports_persons,
                'dynamic'          => $dynamicQuote,
                'analysis'         => $this->buildPricingAnalysis($product_id, $pricingDetails, $dynamicQuote),
            );

            $payload = array(
                'id'         => $product_id,
                'name'       => $product->get_name(),
                'slug'       => $product->get_slug(),
                'type'       => $product->get_type(),
                'permalink'  => $product->get_permalink(),
                'duration'   => array(
                    'value'   => $duration_value,
                    'unit'    => $duration_unit !== '' ? $duration_unit : 'minutes',
                    'minutes' => $duration_minutes,
                ),
                'default_start' => $default_start,
                'pricing'       => $pricing,
                'people'        => $people,
                'availability'  => $availability,
                'resources'     => array(
                    'ids'     => $resource_ids,
                    'summary' => $resource_summary,
                    'items'   => $resource_summary['items'] ?? array(),
                ),
                'resource_id'   => $primary_resource_id,
                'capacity'      => $this->resolveCapacity($product_id, $resource_summary),
                'outlets'       => $this->resolveOutlets($product_id),
                'channels'      => $this->resolveChannels($product_id),
                'combos'        => $this->resolveCombiDeals($product_id, $currency),
                'insights'      => $this->buildProductInsights($product_id, $pricingDetails, $dynamicQuote),
            'meta'          => array(
                'sync_google_calendar' => $this->bool_meta($product_id, '_sbdp_sync_google_calendar'),
            ),
            'calendar_blocks'    => $calendar_blocks,
            'calendar_last_sync' => $calendar_last_sync,
            'calendar_status'    => $calendar_status,
        );

            /**
             * Filter the payload returned for planner products.
             *
             * @param array<string, mixed> $payload
             * @param \WC_Product $product
             * @param array<string, mixed> $filters
             */
            $payload = apply_filters('sbdp_planner_product_payload', $payload, $product, $filters);

            $products[] = $payload;
        }

        return $products;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function shouldExcludeDemoProducts(array $filters): bool
    {
        if ($this->includeDemoProducts($filters)) {
            return false;
        }

        $exclude = true;
        if (function_exists('apply_filters')) {
            $exclude = (bool) apply_filters('sbdp/planner/exclude_demo_products', $exclude, $filters);
        }

        return $exclude;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function includeDemoProducts(array $filters): bool
    {
        if (isset($filters['include_demo'])) {
            $flag = $this->normalizeBooleanFlag($filters['include_demo']);
            if ($flag !== null) {
                return $flag;
            }
        }

        if (isset($filters['demo'])) {
            $value = strtolower(trim((string) $filters['demo']));
            if ($value === '') {
                return false;
            }

            if (in_array($value, array('include', 'all', 'yes', 'true', '1', 'show'), true)) {
                return true;
            }

            if (in_array($value, array('exclude', 'no', 'false', '0', 'hide'), true)) {
                return false;
            }
        }

        return false;
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

    /**
     * Persist planner state for the current user.
     *
     * @param array<string, mixed> $state
     */
    public function storePlannerState(int $user_id, array $state): array
    {
        $scenarioId = $this->normalizeScenarioId($state['scenario_id'] ?? null) ?? 'default';
        $scenarios  = $this->loadPlannerScenarios($user_id);

        $record = $this->buildScenarioRecord($scenarioId, $state, $user_id);
        $scenarios[$scenarioId] = $record;

        $scenarios = $this->normalizeScenarioCollection($scenarios);

        $this->persistPlannerScenarios($user_id, $scenarios);

        return $record;
    }

    /**
     * Retrieve planner state for the provided user.
     *
     * @return array<string, mixed>
     */
    public function getPlannerState(int $user_id, ?string $scenarioId = null): array
    {
        $scenarios = $this->loadPlannerScenarios($user_id);

        if ($scenarioId !== null) {
            $scenarioId = $this->normalizeScenarioId($scenarioId);
        }

        $activeId  = $this->resolveActiveScenarioId($scenarios, $scenarioId);
        $active    = $activeId !== null && isset($scenarios[$activeId]) ? $scenarios[$activeId] : null;
        $state     = $active !== null ? $active['itinerary'] : array();

        return array(
            'scenario_id' => $activeId,
            'scenario'    => $active,
            'scenarios'   => array_values($scenarios),
            'state'       => $state,
        );
    }

    public function getPlannerConfig(): array
    {
        $defaults = array(
            'time_step_minutes' => 30,
            'open_hours'        => array(
                'start' => '08:00',
                'end'   => '22:00',
            ),
            'allow_multi_day'   => true,
            'default_day_count' => 1,
            'autosave'          => true,
            'currency'          => $this->getCurrency(),
            'locale'            => $this->normalizeLocale(get_locale()),
        );

        $config = get_option('sbdp_day_planner_settings', array());
        if (! is_array($config)) {
            $config = array();
        }

        $config = wp_parse_args($config, $defaults);

        $config['time_step_minutes'] = max(5, (int) $config['time_step_minutes']);
        $config['allow_multi_day']   = (bool) $config['allow_multi_day'];
        $config['default_day_count'] = max(1, (int) $config['default_day_count']);
        $config['autosave']          = (bool) $config['autosave'];
        $config['currency']          = is_string($config['currency']) && $config['currency'] !== ''
            ? $config['currency']
            : $defaults['currency'];
        $config['locale']            = $this->normalizeLocale((string) ($config['locale'] ?? $defaults['locale']));

        $profiles = get_option('sbdp_planner_profiles', array());
        $config['profiles'] = $this->normalizePlannerProfiles($profiles, $defaults);

        $config['permissions'] = array(
            'can_manage' => function_exists('current_user_can')
                ? (current_user_can('manage_sbdp_planner') || current_user_can('manage_woocommerce'))
                : false,
        );

        $config['ui'] = array(
            'timezones'       => function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC',
            'currency_symbol' => function_exists('get_woocommerce_currency_symbol')
                ? get_woocommerce_currency_symbol($config['currency'])
                : $config['currency'],
        );
        $config['rest_endpoints'] = array(
            'pricing_preview'    => function_exists('rest_url')
                ? rest_url('sbdp/v1/pricing/preview')
                : '/wp-json/sbdp/v1/pricing/preview',
            'compose_booking'    => function_exists('rest_url')
                ? rest_url('sbdp/v1/compose_booking')
                : '/wp-json/sbdp/v1/compose_booking',
            'dispatch_payment'   => function_exists('rest_url')
                ? rest_url('sbdp/v1/booking/{id}/dispatch_payment')
                : '/wp-json/sbdp/v1/booking/{id}/dispatch_payment',
        );
        // booking_flow: 'pay' | 'request' | 'both'
        // Controls whether the planner shows "Boek nu", "Vraag offerte aan", or both CTAs.
        $config['booking_flow'] = function_exists('get_option')
            ? (string) get_option('sbdp_booking_flow', 'pay')
            : 'pay';
        $config['security'] = array(
            'nonce'         => function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '',
            'public_nonce'  => function_exists('wp_create_nonce') ? wp_create_nonce(CoreRestService::PUBLIC_NONCE_ACTION) : '',
        );

        /**
         * Filter the planner configuration delivered to clients.
         *
         * @param array<string, mixed> $config
         * @param array<string, mixed> $defaults
         */
        return apply_filters('sbdp_planner_config', $config, $defaults);
    }

    private function getCurrency(): string
    {
        $currency = get_option('woocommerce_currency');

        return is_string($currency) && $currency !== '' ? $currency : 'EUR';
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeFilterArray($value): array
    {
        if (is_string($value)) {
            $value = array_map('trim', explode(',', $value));
        }

        if (! is_array($value)) {
            return array();
        }

        $normalized = array();
        foreach ($value as $item) {
            if (is_array($item)) {
                if (isset($item['id'])) {
                    $item = (string) $item['id'];
                } elseif (isset($item['value'])) {
                    $item = (string) $item['value'];
                } else {
                    $item = (string) reset($item);
                }
            } else {
                $item = (string) $item;
            }

            $item = trim($item);
            if ($item === '') {
                continue;
            }

            $normalized[] = $item;
        }

        return array_values(array_unique($normalized));
    }

    private function summarizeAvailability(int $productId): array
    {
        $leadTime = (int) get_post_meta($productId, '_sbdp_lead_time', true);
        $daysAhead = (int) get_post_meta($productId, '_sbdp_planner_visible_days', true);
        $cutoff    = (string) get_post_meta($productId, '_sbdp_booking_cutoff', true);

        return array(
            'lead_time_hours' => $leadTime > 0 ? $leadTime : null,
            'days_ahead'      => $daysAhead > 0 ? $daysAhead : null,
            'cutoff'          => $cutoff !== '' ? $cutoff : null,
        );
    }

    /**
     * @param array<int, int> $resourceIds
     */
    private function summarizeResources(int $productId, array $resourceIds): array
    {
        $summary = array(
            'count'      => count($resourceIds),
            'items'      => array(),
            'capacities' => array(),
        );

        foreach ($resourceIds as $resourceId) {
            $item = array(
                'id'       => $resourceId,
                'label'    => '',
                'capacity' => null,
            );

            if (function_exists('get_post')) {
                $resourcePost = get_post($resourceId);
                if ($resourcePost instanceof WP_Post) {
                    $item['label'] = $resourcePost->post_title;
                }
            }

            $capacityKey = $resourceId > 0 ? '_sbdp_capacity_res_' . $resourceId : '_sbdp_capacity';
            $capacity    = (int) get_post_meta($productId, $capacityKey, true);

            if ($capacity <= 0) {
                $capacity = (int) get_post_meta($resourceId, '_sbdp_capacity', true);
            }

            if ($capacity > 0) {
                $item['capacity'] = $capacity;
                $summary['capacities'][] = $capacity;
            }

            $summary['items'][] = $item;
        }

        if ($summary['capacities'] !== array()) {
            $summary['max_capacity'] = max($summary['capacities']);
            $summary['min_capacity'] = min($summary['capacities']);
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $pricingDetails
     * @param array<string, mixed>|null $dynamicQuote
     */
    private function buildPricingAnalysis(int $productId, array $pricingDetails, ?array $dynamicQuote): array
    {
        $currency = $pricingDetails['currency'] ?? $this->getCurrency();
        $analysis = array(
            'margins'    => null,
            'dynamic'    => null,
            'suggestion' => null,
        );

        $cost = isset($pricingDetails['cost'])
            ? (float) $pricingDetails['cost']
            : (float) get_post_meta($productId, '_sbdp_cost_price', true);
        $base = isset($pricingDetails['base_price'])
            ? (float) $pricingDetails['base_price']
            : null;

        if ($base !== null && $cost > 0.0) {
            $margin = max(0.0, $base - $cost);
            $analysis['margins'] = array(
                'cost'           => $cost,
                'margin'         => $margin,
                'margin_percent' => $base > 0.0 ? round(($margin / $base) * 100, 2) : null,
            );
        }

        if (is_array($dynamicQuote)) {
            $total = isset($dynamicQuote['total']) ? (float) $dynamicQuote['total'] : null;
            if ($total !== null) {
                $analysis['dynamic'] = array(
                    'total'          => $total,
                    'currency'       => (string) ($dynamicQuote['currency'] ?? $currency),
                    'delta_from_base' => $base !== null ? $total - $base : null,
                );
            }
        }

        $suggested = (int) get_post_meta($productId, '_sbdp_planner_default_quantity', true);
        if ($suggested > 0) {
            $analysis['suggestion'] = array(
                'quantity' => $suggested,
                'label'    => function_exists('__')
                    ? sprintf(__('Aanbevolen aantal: %d', 'sbdp'), $suggested)
                    : 'Aanbevolen aantal: ' . $suggested,
            );
        }

        return $analysis;
    }

    /**
     * @param array<string, mixed> $pricingDetails
     * @param array<string, mixed>|null $dynamicQuote
     */
    private function buildProductInsights(int $productId, array $pricingDetails, ?array $dynamicQuote): array
    {
        $insights = array(
            'flags'      => array(),
            'highlights' => array(),
            'revenue'    => null,
        );

        if ($dynamicQuote !== null) {
            $insights['flags'][] = 'dynamic_pricing';
            $base  = isset($pricingDetails['base_price']) ? (float) $pricingDetails['base_price'] : null;
            $total = isset($dynamicQuote['total']) ? (float) $dynamicQuote['total'] : null;
            if ($base !== null && $total !== null) {
                $delta = $total - $base;
                $insights['revenue'] = array(
                    'base'     => $base,
                    'dynamic'  => $total,
                    'delta'    => $delta,
                    'currency' => (string) ($dynamicQuote['currency'] ?? $this->getCurrency()),
                );

                if ($delta > 0.0) {
                    $insights['highlights'][] = array(
                        'type'    => 'upsell',
                        'message' => sprintf(
                            function_exists('__') ? __('Verhoogde omzet: %s', 'sbdp') : 'Revenue uplift: %s',
                            $this->formatMoney($delta, $insights['revenue']['currency'])
                        ),
                    );
                }
            }
        }

        $rating = get_post_meta($productId, '_sbdp_rating', true);
        if (is_numeric($rating) && (float) $rating >= 4.5) {
            $insights['flags'][] = 'top_rated';
        }

        $priority = get_post_meta($productId, '_sbdp_planner_priority', true);
        if (is_numeric($priority) && (int) $priority > 0) {
            $insights['highlights'][] = array(
                'type'    => 'priority',
                'message' => sprintf(
                    function_exists('__') ? __('Prioriteit %d in planner', 'sbdp') : 'Planner priority %d',
                    (int) $priority
                ),
            );
        }

        return apply_filters('sbdp_planner_product_insights', $insights, $productId, $pricingDetails, $dynamicQuote);
    }

    private function resolveCapacity(int $productId, array $resourceSummary): array
    {
        $defaultCapacity = (int) get_post_meta($productId, '_sbdp_capacity', true);
        $buffer          = (int) get_post_meta($productId, '_sbdp_capacity_buffer', true);

        $perResource = array();
        foreach ($resourceSummary['items'] ?? array() as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }

            $capacity = isset($item['capacity']) ? (int) $item['capacity'] : 0;
            if ($capacity <= 0) {
                continue;
            }

            $perResource[] = array(
                'resource_id' => (int) $item['id'],
                'capacity'    => $capacity,
            );
        }

        $maxCapacity = $defaultCapacity;
        foreach ($perResource as $entry) {
            if ($entry['capacity'] > $maxCapacity) {
                $maxCapacity = $entry['capacity'];
            }
        }

        return array(
            'default'        => $defaultCapacity > 0 ? $defaultCapacity : null,
            'per_resource'   => $perResource,
            'buffer_minutes' => $buffer > 0 ? $buffer : null,
            'max'            => $maxCapacity > 0 ? $maxCapacity : null,
        );
    }

    private function resolveOutlets(int $productId): array
    {
        if (! function_exists('taxonomy_exists') || ! function_exists('wp_get_post_terms')) {
            return array();
        }

        $taxonomies = array();
        foreach (array('sbdp_outlet', 'bsp_outlet', 'product_outlet') as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $taxonomies[] = $taxonomy;
            }
        }

        if ($taxonomies === array()) {
            return array();
        }

        $outlets = array();
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($productId, $taxonomy);
            if (is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $outlets[] = array(
                    'id'       => (int) $term->term_id,
                    'slug'     => $term->slug,
                    'name'     => $term->name,
                    'taxonomy' => $taxonomy,
                );
            }
        }

        return $outlets;
    }

    private function resolveChannels(int $productId): array
    {
        $channelsRaw = get_post_meta($productId, '_sbdp_distribution_channels', true);
        $channels    = $this->decode_meta_json($channelsRaw);

        if ($channels === array() && is_string($channelsRaw) && $channelsRaw !== '') {
            $channels = $this->normalizeFilterArray($channelsRaw);
        }

        $normalized = array();

        foreach ($channels as $key => $channel) {
            if (is_array($channel)) {
                $channelKey = (string) ($channel['key'] ?? $key);
                $label      = $this->sanitizePlainText($channel['label'] ?? ($channel['name'] ?? $channelKey));
                $status     = $this->sanitizePlainText($channel['status'] ?? '');
            } else {
                $channelKey = (string) (is_string($key) ? $key : $channel);
                $label      = $this->sanitizePlainText((string) $channel);
                $status     = '';
            }

            if ($channelKey === '') {
                continue;
            }

            $normalized[] = array(
                'key'    => $channelKey,
                'label'  => $label !== '' ? $label : ucfirst(str_replace('_', ' ', $channelKey)),
                'status' => $status,
            );
        }

        return apply_filters('sbdp_planner_product_channels', $normalized, $productId, $channels);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveCombiDeals(int $productId, string $currency): array
    {
        $comboIdsRaw = get_post_meta($productId, '_sbdp_combi_deals', true);
        $comboIds    = array_values(array_filter(array_map('intval', is_array($comboIdsRaw) ? $comboIdsRaw : array())));

        if ($comboIds === array() || ! function_exists('wc_get_product')) {
            return array();
        }

        $combos = array();

        foreach ($comboIds as $comboId) {
            $comboProduct = wc_get_product($comboId);
            if (! $comboProduct) {
                continue;
            }

            $pricingDetails  = CorePricingService::instance()->getProductPricing($comboId, array('price_mode' => 'gross'));
            $supportsPersons = ! empty($pricingDetails['supports_persons']);
            $people          = $this->resolvePeopleConfiguration($comboId, $supportsPersons, $comboProduct);

            $basePrice      = (float) ($pricingDetails['base_price'] ?? 0.0);
            $perPersonPrice = (float) ($pricingDetails['per_person'] ?? 0.0);
            $fixedFee       = (float) ($pricingDetails['fixed_fee'] ?? 0.0);
            if (! $supportsPersons) {
                $perPersonPrice = 0.0;
            }

            $defaultQuantity = isset($people['default']) && (int) $people['default'] > 0
                ? (int) $people['default']
                : (int) $people['min'];

            $dynamicQuote = $this->calculateDynamicPricing($comboId, max(1, $defaultQuantity), $currency);

            $pricing = array(
                'base'             => $basePrice,
                'per_person'       => $perPersonPrice,
                'fixed_fee'        => $fixedFee,
                'currency'         => $currency,
                'supports_persons' => $supportsPersons,
                'dynamic'          => $dynamicQuote,
                'analysis'         => $this->buildPricingAnalysis($comboId, $pricingDetails, $dynamicQuote),
            );

            $durationRaw     = get_post_meta($comboId, '_sbdp_duration', true);
            $durationValue   = $this->normaliseDurationValue($durationRaw);
            $durationUnit    = (string) get_post_meta($comboId, '_sbdp_duration_unit', true);
            if ($durationValue <= 0) {
                $durationValue = 90;
                $durationUnit  = 'minutes';
            }
            $durationMinutes = $this->convertDurationToMinutes($durationValue, $durationUnit);
            if ($durationMinutes <= 0) {
                $durationMinutes = 90;
            }

            $combos[] = array(
                'id'        => $comboProduct->get_id(),
                'name'      => $comboProduct->get_name(),
                'slug'      => $comboProduct->get_slug(),
                'type'      => $comboProduct->get_type(),
                'permalink' => $comboProduct->get_permalink(),
                'duration'  => array(
                    'value'   => $durationValue,
                    'unit'    => $durationUnit !== '' ? $durationUnit : 'minutes',
                    'minutes' => $durationMinutes,
                ),
                'people'   => $people,
                'pricing'  => $pricing,
            );
        }

        return $combos;
    }

    /**
     * @param \WC_Product|null $product
     * @return array<string, mixed>
     */
    private function resolvePeopleConfiguration(int $productId, bool $supportsPersons, $product = null): array
    {
        if (! $supportsPersons) {
            return array(
                'enabled'            => false,
                'min'                => 1,
                'max'                => null,
                'default'            => 1,
                'count_as_bookings'  => false,
            );
        }

        $enabledMeta = $this->bool_meta($productId, '_sbdp_enable_people');
        $minMeta     = (int) get_post_meta($productId, '_sbdp_min_people', true);
        $maxMeta     = (int) get_post_meta($productId, '_sbdp_max_people', true);
        $defaultMeta = (int) get_post_meta($productId, '_sbdp_people_default', true);

        $bookingMin     = (int) get_post_meta($productId, '_wc_booking_min_persons', true);
        $bookingMax     = (int) get_post_meta($productId, '_wc_booking_max_persons', true);
        $bookingDefault = (int) get_post_meta($productId, '_wc_booking_persons_default', true);

        if ($minMeta <= 0 && $bookingMin > 0) {
            $minMeta = $bookingMin;
        }

        if ($maxMeta <= 0 && $bookingMax > 0) {
            $maxMeta = $bookingMax;
        }

        if ($defaultMeta <= 0 && $bookingDefault > 0) {
            $defaultMeta = $bookingDefault;
        }

        if ($minMeta <= 0) {
            $minMeta = 1;
        }

        if ($maxMeta > 0 && $maxMeta < $minMeta) {
            $maxMeta = $minMeta;
        }

        $default = $defaultMeta > 0 ? $defaultMeta : $minMeta;
        if ($default <= 0) {
            $default = $minMeta;
        }

        $max = $maxMeta > 0 ? $maxMeta : null;
        if ($max !== null && $default > $max) {
            $default = $max;
        }

        if (! $enabledMeta && ($minMeta > 1 || ($maxMeta !== null && $maxMeta > $minMeta))) {
            $enabledMeta = true;
        }

        return array(
            'enabled'            => $enabledMeta,
            'min'                => max(1, $minMeta),
            'max'                => $max,
            'default'            => max(1, $default),
            'count_as_bookings'  => $this->bool_meta($productId, '_sbdp_people_as_bookings'),
        );
    }

    /**
     * @param mixed $profiles
     * @param array<string, mixed> $defaults
     */
    private function normalizePlannerProfiles($profiles, array $defaults): array
    {
        if (! is_array($profiles)) {
            $profiles = array();
        }

        $normalized = array();
        $defaultId  = null;

        foreach ($profiles as $key => $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $id = $this->normalizeScenarioId($profile['id'] ?? $key);
            if ($id === null) {
                continue;
            }

            $label = $this->sanitizePlainText($profile['label'] ?? ($profile['name'] ?? ''));
            if ($label === '') {
                $label = ucfirst(str_replace(array('-', '_'), ' ', $id));
            }

            $normalized[] = array(
                'id'                => $id,
                'label'             => $label,
                'time_step_minutes' => isset($profile['time_step_minutes'])
                    ? max(5, (int) $profile['time_step_minutes'])
                    : $defaults['time_step_minutes'],
                'open_hours'        => array(
                    'start' => $this->sanitizePlainText($profile['open_hours']['start'] ?? $defaults['open_hours']['start']),
                    'end'   => $this->sanitizePlainText($profile['open_hours']['end'] ?? $defaults['open_hours']['end']),
                ),
                'allow_multi_day'   => isset($profile['allow_multi_day']) ? (bool) $profile['allow_multi_day'] : $defaults['allow_multi_day'],
                'default_day_count' => isset($profile['default_day_count']) ? max(1, (int) $profile['default_day_count']) : $defaults['default_day_count'],
                'autosave'          => isset($profile['autosave']) ? (bool) $profile['autosave'] : $defaults['autosave'],
                'outlets'           => $this->normalizeFilterArray($profile['outlets'] ?? array()),
            );

            if (! empty($profile['is_default']) && $defaultId === null) {
                $defaultId = $id;
            }
        }

        if ($normalized === array()) {
            $normalized[] = array(
                'id'                => 'default',
                'label'             => function_exists('__') ? __('Standaard', 'sbdp') : 'Standaard',
                'time_step_minutes' => $defaults['time_step_minutes'],
                'open_hours'        => $defaults['open_hours'],
                'allow_multi_day'   => $defaults['allow_multi_day'],
                'default_day_count' => $defaults['default_day_count'],
                'autosave'          => $defaults['autosave'],
                'outlets'           => array(),
            );
            $defaultId = 'default';
        }

        return array(
            'items'   => $normalized,
            'default' => $defaultId ?? ($normalized[0]['id'] ?? 'default'),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $scenarios
     */
    private function persistPlannerScenarios(int $userId, array $scenarios): void
    {
        $encoded = wp_json_encode(array_values($scenarios));
        if (! is_string($encoded)) {
            return;
        }

        update_user_meta($userId, self::SCENARIOS_META_KEY, $encoded);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadPlannerScenarios(int $userId): array
    {
        $raw = get_user_meta($userId, self::SCENARIOS_META_KEY, true);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = array();
        }

        if (! is_array($decoded)) {
            $decoded = array();
        }

        $scenarios = array();
        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $this->normalizeScenarioId($entry['scenario_id'] ?? null);
            if ($id === null) {
                continue;
            }

            $entry['scenario_id'] = $id;
            $scenarios[$id] = $this->normalizeScenarioRecord($entry, isset($entry['owner']) ? (int) $entry['owner'] : $userId);
        }

        if ($scenarios === array()) {
            $legacy = $this->maybeMigrateLegacyState($userId);
            if ($legacy !== null) {
                $scenarios = $legacy;
            }
        }

        return $this->normalizeScenarioCollection($scenarios, $userId);
    }

    /**
     * @param array<string, array<string, mixed>> $scenarios
     */
    private function normalizeScenarioCollection(array $scenarios, int $userId = 0): array
    {
        $normalized = array();
        foreach ($scenarios as $scenarioId => $scenario) {
            if (! is_array($scenario)) {
                continue;
            }

            if (! isset($scenario['scenario_id'])) {
                $scenario['scenario_id'] = $scenarioId;
            }

            $id = $this->normalizeScenarioId($scenario['scenario_id']);
            if ($id === null) {
                continue;
            }

            $scenario['scenario_id'] = $id;
            $normalized[$id] = $this->normalizeScenarioRecord($scenario, isset($scenario['owner']) ? (int) $scenario['owner'] : $userId);
        }

        uasort(
            $normalized,
            static function (array $left, array $right): int {
                return strcmp($right['updated_at'], $left['updated_at']);
            }
        );

        return $normalized;
    }

    private function normalizeScenarioId($value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^a-z0-9\-_.:+]/', '-', $value);
        if ($value === null) {
            return null;
        }

        $value = trim($value, '-_.:+');

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function buildScenarioRecord(string $scenarioId, array $state, int $userId): array
    {
        $label = $this->sanitizePlainText($state['label'] ?? ($state['meta']['label'] ?? ''));
        if ($label === '') {
            $label = ucfirst(str_replace(array('-', '_'), ' ', $scenarioId));
        }

        $record = array(
            'version'     => self::SCENARIO_VERSION,
            'scenario_id' => $scenarioId,
            'label'       => $label,
            'status'      => $this->sanitizePlainText($state['status'] ?? ($state['meta']['status'] ?? 'draft')),
            'owner'       => $userId,
            'itinerary'   => $this->normalizeItinerary($state),
            'meta'        => array(
                'customer' => $this->normalizeCustomerMeta($state['customer'] ?? ($state['meta']['customer'] ?? array())),
                'window'   => $this->normalizeWindowMeta($state['window'] ?? ($state['meta']['window'] ?? array())),
                'notes'    => $this->sanitizeTextarea($state['notes'] ?? ($state['meta']['notes'] ?? '')),
            ),
            'shared_with' => $this->normalizeShareList($state['shared_with'] ?? ($state['meta']['shared_with'] ?? array())),
            'updated_at'  => gmdate(DateTimeImmutable::ATOM),
            'color'       => $this->sanitizeColor($state['color'] ?? ($state['meta']['color'] ?? '')),
            'is_primary'  => (bool) ($state['is_primary'] ?? $state['meta']['is_primary'] ?? false),
        );

        return $this->normalizeScenarioRecord($record, $userId);
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private function normalizeScenarioRecord(array $scenario, int $userId): array
    {
        $scenarioId = $this->normalizeScenarioId($scenario['scenario_id'] ?? null) ?? 'default';

        $updatedAt = $this->normaliseDateTimeForStorage((string) ($scenario['updated_at'] ?? ''));
        if ($updatedAt === '') {
            $updatedAt = gmdate(DateTimeImmutable::ATOM);
        }

        $scenario['scenario_id'] = $scenarioId;
        $scenario['version']     = isset($scenario['version']) ? (int) $scenario['version'] : self::SCENARIO_VERSION;
        $scenario['label']       = $this->sanitizePlainText($scenario['label'] ?? ucfirst(str_replace(array('-', '_'), ' ', $scenarioId)));
        $scenario['status']      = $this->sanitizePlainText($scenario['status'] ?? 'draft');
        $scenario['owner']       = isset($scenario['owner']) ? (int) $scenario['owner'] : $userId;
        $scenario['updated_at']  = $updatedAt;
        $scenario['color']       = $this->sanitizeColor($scenario['color'] ?? '');
        $scenario['is_primary']  = (bool) ($scenario['is_primary'] ?? false);
        $scenario['shared_with'] = $this->normalizeShareList($scenario['shared_with'] ?? array());

        $scenario['meta'] = array(
            'customer' => $this->normalizeCustomerMeta($scenario['meta']['customer'] ?? ($scenario['customer'] ?? array())),
            'window'   => $this->normalizeWindowMeta($scenario['meta']['window'] ?? ($scenario['window'] ?? array())),
            'notes'    => $this->sanitizeTextarea($scenario['meta']['notes'] ?? ($scenario['notes'] ?? '')),
        );

        $scenario['itinerary'] = $this->normalizeItinerary($scenario);

        return $scenario;
    }

    private function resolveActiveScenarioId(array $scenarios, ?string $requested): ?string
    {
        if ($requested !== null && isset($scenarios[$requested])) {
            return $requested;
        }

        foreach ($scenarios as $scenario) {
            if (! empty($scenario['is_primary'])) {
                return (string) $scenario['scenario_id'];
            }
        }

        $keys = array_keys($scenarios);

        return $keys === array() ? null : (string) $keys[0];
    }

    private function maybeMigrateLegacyState(int $userId): ?array
    {
        $raw = get_user_meta($userId, self::LEGACY_STATE_META_KEY, true);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $label  = function_exists('__') ? __('Standaard plan', 'sbdp') : 'Standaard plan';
        $record = $this->buildScenarioRecord('default', array(
            'label'      => $label,
            'itinerary'  => $decoded,
            'is_primary' => true,
        ), $userId);

        $scenarios = array($record['scenario_id'] => $record);

        $this->persistPlannerScenarios($userId, $scenarios);
        delete_user_meta($userId, self::LEGACY_STATE_META_KEY);

        return $scenarios;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItinerary(array $state): array
    {
        $source = null;
        foreach (array('itinerary', 'items', 'state', 'bookings') as $key) {
            if (isset($state[$key]) && is_array($state[$key])) {
                $source = $state[$key];
                break;
            }
        }

        if ($source === null && isset($state['meta']['itinerary']) && is_array($state['meta']['itinerary'])) {
            $source = $state['meta']['itinerary'];
        }

        if (! is_array($source)) {
            $source = array();
        }

        $normalized = array();
        $index      = 0;
        foreach ($source as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized[] = $this->normalizeItineraryItem($item, $index++);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function normalizeItineraryItem(array $item, int $index): array
    {
        $uid = isset($item['uid']) ? (string) $item['uid'] : '';
        if ($uid === '' && isset($item['id'])) {
            $uid = (string) $item['id'];
        }
        if ($uid === '') {
            $uid = sprintf('planner_%s_%d', uniqid('', false), $index);
        }

        $startRaw = (string) ($item['start'] ?? ($item['time'] ?? ''));
        $endRaw   = (string) ($item['end'] ?? '');

        $start = $this->normaliseDateTimeForStorage($startRaw);
        $duration = $this->normalizeDurationFromItem($item);

        $end = $this->normaliseDateTimeForStorage($endRaw);
        if ($end === '' && $start !== '') {
            $startTs = $this->parsePlannerTime($start);
            if ($startTs !== null) {
                $end = gmdate(DateTimeImmutable::ATOM, $startTs + ($duration > 0 ? $duration : self::DEFAULT_DURATION_MINUTES) * 60);
            }
        }

        $resource = $this->sanitizePlainText($item['resource'] ?? '');
        $resourceLabel = $this->sanitizePlainText($item['resource_label'] ?? '');
        if ($resourceLabel === '' && $resource !== '' && function_exists('get_post')) {
            $resourcePost = get_post((int) $resource);
            if ($resourcePost instanceof WP_Post) {
                $resourceLabel = $resourcePost->post_title;
            }
        }

        return array(
            'uid'            => $uid,
            'product_id'     => isset($item['product_id']) ? (int) $item['product_id'] : (int) ($item['id'] ?? 0),
            'product_name'   => $this->sanitizePlainText($item['product_name'] ?? ($item['name'] ?? '')),
            'resource'       => $resource,
            'resource_label' => $resourceLabel,
            'channel'        => $this->sanitizePlainText($item['channel'] ?? ''),
            'start'          => $start,
            'end'            => $end,
            'duration'       => $duration,
            'quantity'       => $this->normalizePositiveInt($item['quantity'] ?? ($item['people'] ?? 1), 1),
            'capacity'       => $this->normalizePositiveInt($item['capacity'] ?? ($item['pax'] ?? 0), 0),
            'buffer_before'  => $this->normalizePositiveInt($item['buffer_before'] ?? ($item['buffer'] ?? 0), 0),
            'buffer_after'   => $this->normalizePositiveInt($item['buffer_after'] ?? 0, 0),
            'status'         => $this->sanitizePlainText($item['status'] ?? 'draft'),
            'notes'          => $this->sanitizeTextarea($item['notes'] ?? ''),
            'price'          => array(
                'total'    => isset($item['price_total']) ? (float) $item['price_total'] : null,
                'currency' => $this->sanitizePlainText($item['price_currency'] ?? ''),
            ),
        );
    }

    private function sanitizePlainText($value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        return trim(strip_tags($value));
    }

    private function sanitizeTextarea($value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('wp_strip_all_tags')) {
            return trim(wp_strip_all_tags($value, true));
        }

        return trim(strip_tags($value));
    }

    private function sanitizeEmail($value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('sanitize_email')) {
            $value = sanitize_email($value);
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? strtolower($value) : '';
    }

    private function sanitizeColor($value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = strtoupper(trim($value));
        if ($value === '') {
            return '';
        }

        if (! preg_match('/^#([A-F0-9]{3}|[A-F0-9]{6})$/', $value)) {
            return '';
        }

        if (strlen($value) === 4) {
            return sprintf('#%1$s%1$s%2$s%2$s%3$s%3$s', $value[1], $value[2], $value[3]);
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private function normalizePositiveInt($value, int $fallback): int
    {
        if (is_numeric($value)) {
            $int = (int) $value;
            if ($int > 0) {
                return $int;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function normalizeDurationFromItem(array $item): int
    {
        $duration = $item['duration_minutes'] ?? ($item['duration'] ?? null);
        if (is_numeric($duration)) {
            $duration = (int) $duration;
            if ($duration > 0) {
                return $duration;
            }
        }

        if (isset($item['start'], $item['end'])) {
            $startTs = $this->parsePlannerTime($this->normaliseDateTimeForStorage((string) $item['start']));
            $endTs   = $this->parsePlannerTime($this->normaliseDateTimeForStorage((string) $item['end']));
            if ($startTs !== null && $endTs !== null && $endTs > $startTs) {
                return (int) round(($endTs - $startTs) / 60);
            }
        }

        return self::DEFAULT_DURATION_MINUTES;
    }

    private function normalizeCustomerMeta($customer): array
    {
        if (! is_array($customer)) {
            return array();
        }

        $normalized = array(
            'name'    => $this->sanitizePlainText($customer['name'] ?? ''),
            'email'   => $this->sanitizeEmail($customer['email'] ?? ''),
            'phone'   => $this->sanitizePlainText($customer['phone'] ?? ''),
            'company' => $this->sanitizePlainText($customer['company'] ?? ''),
        );

        $billing = $this->normalizeCustomerAddress($customer['billing'] ?? array());
        if ($billing !== array()) {
            $normalized['billing'] = $billing;
        }

        $shipping = $this->normalizeCustomerAddress($customer['shipping'] ?? array());
        if ($shipping !== array()) {
            $normalized['shipping'] = $shipping;
        }

        return $normalized;
    }

    /**
     * @param mixed $address
     * @return array<string, string>
     */
    private function normalizeCustomerAddress($address): array
    {
        if (! is_array($address)) {
            return array();
        }

        return array_filter(array(
            'company'   => $this->sanitizePlainText($address['company'] ?? ''),
            'address_1' => $this->sanitizePlainText($address['address_1'] ?? ''),
            'address_2' => $this->sanitizePlainText($address['address_2'] ?? ''),
            'postcode'  => $this->sanitizePlainText($address['postcode'] ?? ''),
            'city'      => $this->sanitizePlainText($address['city'] ?? ''),
            'state'     => $this->sanitizePlainText($address['state'] ?? ''),
            'country'   => $this->sanitizePlainText($address['country'] ?? ''),
        ), static fn (string $value): bool => $value !== '');
    }

    private function normalizeWindowMeta($window): array
    {
        if (! is_array($window)) {
            $window = array();
        }

        return array(
            'start' => $this->normaliseDateTimeForStorage((string) ($window['start'] ?? '')),
            'end'   => $this->normaliseDateTimeForStorage((string) ($window['end'] ?? '')),
        );
    }

    private function normalizeShareList($share): array
    {
        $items = $this->normalizeFilterArray($share);
        $normalized = array();

        foreach ($items as $item) {
            if (is_numeric($item)) {
                $normalized[] = (int) $item;
                continue;
            }

            if (filter_var($item, FILTER_VALIDATE_EMAIL)) {
                $normalized[] = strtolower($item);
            }
        }

        return array_values(array_unique($normalized, SORT_REGULAR));
    }

    private function normaliseDateTimeForStorage(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }

        return gmdate(DateTimeImmutable::ATOM, $timestamp);
    }

    private function parsePlannerTime(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            $base = gmdate('Y-m-d');
            $value = $base . 'T' . $value . ':00Z';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private function formatMoney(float $amount, string $currency): string
    {
        $formatted = number_format($amount, 2, ',', '.');

        return $currency . ' ' . $formatted;
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
     * @param mixed $value Possibly JSON encoded array.
     * @return array<mixed>
     */
    private function decode_meta_json($value): array
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

    private function float_meta(int $product_id, string $key): float
    {
        $value = get_post_meta($product_id, $key, true);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function bool_meta(int $product_id, string $key): bool
    {
        $value = get_post_meta($product_id, $key, true);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), array('1', 'yes', 'true'), true);
        }

        return (bool) $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function calculateDynamicPricing(int $product_id, int $quantity, string $currency): ?array
    {
        try {
            $quote = CorePricingService::instance()->quote(
                $product_id,
                max(1, $quantity),
                array(
                    'channel'  => 'planner_ui',
                    'currency' => $currency,
                )
            );
        } catch (\Throwable $exception) {
            return null;
        }

        return is_array($quote) ? $quote : null;
    }

    /**
     * @param mixed $locale
     */
    private function normalizeLocale($locale): string
    {
        if (! is_string($locale) || $locale === '') {
            return 'nl-NL';
        }

        $normalized = str_replace('_', '-', $locale);
        $segments = explode('-', $normalized);
        if ($segments === array()) {
            return 'nl-NL';
        }

        $segments[0] = strtolower($segments[0]);
        for ($index = 1, $count = count($segments); $index < $count; $index++) {
            $segment = $segments[$index];
            if (strlen($segment) === 2) {
                $segments[$index] = strtoupper($segment);
            } elseif (strlen($segment) === 4) {
                $segments[$index] = ucfirst(strtolower($segment));
            } else {
                $segments[$index] = strtolower($segment);
            }
        }

        $result = implode('-', $segments);

        return $result !== '' ? $result : 'nl-NL';
    }
}
