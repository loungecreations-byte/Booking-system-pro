<?php
/**
 * Plugin Name: SBDP Planner Domain
 * Description: Shared planner contracts, evaluation and cart status bridge for product planner and Plan je Dag.
 * Version: 1.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('SBDP_Planner_Domain_Service')) {
    final class SBDP_Planner_Domain_Service
    {
        public const REST_NAMESPACE = 'sbdp-planner/v1';
        public const SCHEMA_VERSION = '1.0.0';
        private const SCRIPT_HANDLE = 'sbdp-planner-domain';
        private const RATE_LIMIT_WINDOW = 300;
        private const EVALUATE_RATE_LIMIT = 300;
        private const CART_STATE_RATE_LIMIT = 500;

        public static function bootstrap(): void
        {
            add_action('rest_api_init', [self::class, 'register_rest_routes']);
            add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets'], 25);
            add_action('woocommerce_checkout_create_order_line_item', [self::class, 'persist_order_plan_item'], 15, 4);
        }

        public static function register_rest_routes(): void
        {
            if (! function_exists('register_rest_route')) {
                return;
            }

            register_rest_route(
                self::REST_NAMESPACE,
                '/evaluate',
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [self::class, 'handle_evaluate_request'],
                    'permission_callback' => [self::class, 'allow_evaluate_request'],
                    'args'                => self::get_evaluate_route_args(),
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/cart-state',
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [self::class, 'handle_cart_state_request'],
                    'permission_callback' => [self::class, 'allow_cart_state_request'],
                ]
            );
        }

        public static function allow_evaluate_request(\WP_REST_Request $request)
        {
            return self::authorize_public_route('evaluate', self::EVALUATE_RATE_LIMIT, $request);
        }

        public static function allow_cart_state_request(\WP_REST_Request $request)
        {
            return self::authorize_public_route('cart-state', self::CART_STATE_RATE_LIMIT, $request);
        }

        public static function sanitize_rest_positive_int($value)
        {
            return self::to_positive_int($value);
        }

        public static function sanitize_rest_date($value): ?string
        {
            return self::sanitize_date($value);
        }

        public static function sanitize_rest_time($value): ?string
        {
            return self::sanitize_time($value);
        }

        public static function sanitize_rest_text($value): string
        {
            return self::sanitize_text($value);
        }

        public static function enqueue_assets(): void
        {
            if (! self::should_enqueue_assets()) {
                return;
            }

            $script_path = SBDP_DIR . 'assets/js/sbdp-planner-domain.js';
            $script_url  = SBDP_URL . 'assets/js/sbdp-planner-domain.js';
            $version     = is_readable($script_path) ? (string) filemtime($script_path) : self::SCHEMA_VERSION;

            wp_register_script(self::SCRIPT_HANDLE, $script_url, [], $version, true);

            $config = [
                'schemaVersion' => self::SCHEMA_VERSION,
                'restBase'      => trailingslashit(rest_url(self::REST_NAMESPACE)),
                'evaluateUrl'   => rest_url(self::REST_NAMESPACE . '/evaluate'),
                'cartStateUrl'  => rest_url(self::REST_NAMESPACE . '/cart-state'),
                'nonce'         => wp_create_nonce('wp_rest'),
                'plannerUrl'    => self::resolve_planner_url(),
                'storage'       => [
                    'draftKey'    => 'sbdpPlannerDraftV1',
                    'queueKey'    => 'sbdpPlannerPrefillQueue',
                    'settingsKey' => 'sbdpPlannerSettings',
                ],
            ];

            wp_add_inline_script(
                self::SCRIPT_HANDLE,
                'window.SBDP_PLANNER_DOMAIN_CONFIG = ' . wp_json_encode($config) . ';',
                'before'
            );

            wp_enqueue_script(self::SCRIPT_HANDLE);
        }

        public static function handle_evaluate_request(\WP_REST_Request $request)
        {
            $payload = $request->get_json_params();
            $payload = is_array($payload) ? $payload : [];

            $input = self::normalize_input($payload);
            if (($input['productId'] ?? 0) <= 0) {
                return new \WP_Error(
                    'sbdp_planner_domain_invalid_product',
                    __('Ongeldig planner product.', 'sbdp'),
                    ['status' => 400]
                );
            }

            return rest_ensure_response(self::evaluate_input($input));
        }

        public static function handle_cart_state_request(\WP_REST_Request $request)
        {
            unset($request);

            return rest_ensure_response([
                'items' => self::collect_cart_state_items(),
            ]);
        }

        public static function normalize_input(array $raw): array
        {
            $product_id   = self::to_positive_int($raw['productId'] ?? ($raw['product_id'] ?? 0)) ?? 0;
            $resource_id  = self::to_positive_int($raw['resourceId'] ?? ($raw['resource_id'] ?? 0));
            $participants = self::to_positive_int($raw['participants'] ?? ($raw['people'] ?? 1)) ?? 1;
            $date         = self::sanitize_date($raw['date'] ?? ($raw['start_date'] ?? null)) ?? '';
            $time         = self::sanitize_time(
                $raw['time'] ?? ($raw['start_time'] ?? ($raw['timeslot']['start'] ?? null))
            ) ?? '';
            $source       = self::sanitize_text($raw['source'] ?? 'planner');

            $product_type = '';
            if (function_exists('wc_get_product') && $product_id > 0) {
                $product = wc_get_product($product_id);
                if ($product && method_exists($product, 'get_type')) {
                    $product_type = (string) $product->get_type();
                }
            }

            $options = [
                'combi'      => null,
                'combiLabel' => '',
                'combiItems' => self::normalize_combi_items($raw),
                'extras'     => is_array($raw['extras'] ?? null) ? array_values($raw['extras']) : [],
            ];

            if ($options['combiItems'] !== []) {
                $options['combi'] = $options['combiItems'][0]['id'];
                $options['combiLabel'] = (string) ($options['combiItems'][0]['label'] ?? '');
            }

            return [
                'schemaVersion'   => self::SCHEMA_VERSION,
                'productId'       => $product_id,
                'productType'     => $product_type,
                'date'            => $date,
                'participants'    => max(1, $participants),
                'timeslot'        => [
                    'start'  => $time,
                    'end'    => '',
                    'slotId' => null,
                ],
                'resourceId'      => $resource_id ?? 0,
                'options'         => $options,
                'source'          => $source !== '' ? $source : 'planner',
                'locationContext' => [
                    'resourceId'    => $resource_id ?? 0,
                    'resourceLabel' => self::resolve_resource_label($product_id, $resource_id ?? 0),
                ],
            ];
        }

        public static function evaluate_input(array $input): array
        {
            $product_id   = (int) ($input['productId'] ?? 0);
            $product      = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
            $constraints  = self::build_constraints($product_id);

            if (! $product || ! is_object($product)) {
                return [
                    'status'          => 'invalid',
                    'normalizedInput' => $input,
                    'availability'    => ['available' => false, 'selectedSlotAvailable' => false, 'slots' => []],
                    'pricing'         => ['currency' => self::get_currency(), 'total' => 0.0, 'unitPrice' => 0.0],
                    'summary'         => [],
                    'constraints'     => $constraints,
                    'warnings'        => [__('Product niet gevonden.', 'sbdp')],
                    'conflicts'       => [],
                    'planItem'        => null,
                ];
            }

            $date         = (string) ($input['date'] ?? '');
            $start_time   = (string) ($input['timeslot']['start'] ?? '');
            $participants = max(1, (int) ($input['participants'] ?? 1));
            $resource_id  = (int) ($input['resourceId'] ?? 0);
            $duration     = self::get_duration_minutes($product_id);
            $end_time     = self::compose_end_time($date, $start_time, $duration);
            $start_iso    = self::compose_start_iso($date, $start_time);

            $normalized = $input;
            $normalized['timeslot']['end'] = $end_time;

            $availability = self::resolve_availability($product_id, $resource_id, $date, $start_time, $participants);
            $pricing      = self::resolve_pricing($product, $resource_id, $start_iso, $participants);
            $combi_items  = is_array($normalized['options']['combiItems'] ?? null) ? $normalized['options']['combiItems'] : [];
            // CSOT guard: validate combi IDs against product's _sbdp_combi_deals and normalize durations.
            if ($combi_items !== []) {
                $combi_items = self::validate_combi_items($product_id, $combi_items);
                $normalized['options']['combiItems'] = $combi_items;
            }
            $pricing      = self::merge_combi_pricing($pricing, self::resolve_combi_breakdown($combi_items, $participants));
            $plan_item    = self::build_plan_item($normalized, $product, $pricing, $availability, $constraints);
            $aggregate    = isset($plan_item['aggregate']) && is_array($plan_item['aggregate']) ? $plan_item['aggregate'] : null;

            $warnings = [];
            if ($date === '') {
                $warnings[] = __('Datum ontbreekt.', 'sbdp');
            }
            if ($start_time === '') {
                $warnings[] = __('Starttijd ontbreekt.', 'sbdp');
            }
            if (! $availability['selectedSlotAvailable']) {
                $warnings[] = __('Geselecteerd tijdslot is niet bevestigd als beschikbaar.', 'sbdp');
            }

            return [
                'status'          => ($date !== '' && $start_time !== '' && $availability['selectedSlotAvailable']) ? 'ready' : 'partial',
                'normalizedInput' => $normalized,
                'availability'    => $availability,
                'pricing'         => $pricing,
                'summary'         => self::build_summary($product, $normalized, $pricing, $plan_item),
                'constraints'     => $constraints,
                'warnings'        => $warnings,
                'conflicts'       => [],
                'planItem'        => $plan_item,
                'aggregate'       => $aggregate,
            ];
        }

        public static function build_cart_payload_from_plan_item(array $plan_item, array $planner_input = []): array
        {
            $product_id    = (int) ($plan_item['productId'] ?? ($plan_item['product_id'] ?? 0));
            $participants  = max(1, (int) ($plan_item['participants'] ?? 1));
            $resource_id   = self::to_positive_int($plan_item['resourceId'] ?? ($plan_item['resource_id'] ?? 0)) ?? 0;
            $date          = self::sanitize_date($plan_item['date'] ?? null) ?? '';
            $start_time    = self::sanitize_time($plan_item['startTime'] ?? ($plan_item['plannerInput']['timeslot']['start'] ?? null)) ?? '';
            $end_time      = self::sanitize_time($plan_item['endTime'] ?? ($plan_item['plannerInput']['timeslot']['end'] ?? null)) ?? '';
            $duration      = self::to_positive_int($plan_item['durationMinutes'] ?? 0) ?? self::get_duration_minutes($product_id);
            $pricing       = is_array($plan_item['pricing'] ?? null) ? $plan_item['pricing'] : [];
            $combi_items   = is_array($plan_item['options']['combiItems'] ?? null) ? $plan_item['options']['combiItems'] : [];
            $aggregate     = is_array($plan_item['aggregate'] ?? null) ? $plan_item['aggregate'] : [];
            $timeline      = is_array($aggregate['timeline'] ?? null) ? $aggregate['timeline'] : [];
            $aggregate_pricing = is_array($aggregate['pricing'] ?? null) ? $aggregate['pricing'] : [];

            if ($date === '' && isset($aggregate['date'])) {
                $date = self::sanitize_date($aggregate['date']) ?? '';
            }
            if ($start_time === '' && isset($timeline['startTime'])) {
                $start_time = self::sanitize_time($timeline['startTime']) ?? '';
            }
            if ($end_time === '' && isset($timeline['endTime'])) {
                $end_time = self::sanitize_time($timeline['endTime']) ?? '';
            }
            if ($duration <= 0 && isset($timeline['durationMinutes'])) {
                $duration = self::to_positive_int($timeline['durationMinutes'] ?? 0) ?? 0;
            }
            if ($pricing === [] && $aggregate_pricing !== []) {
                $pricing = self::build_plan_item_pricing_from_aggregate(
                    $aggregate_pricing,
                    $participants,
                    $combi_items
                );
            }

            $summary = [
                'date'         => $date,
                'time'         => $start_time,
                'participants' => $participants,
                'resource_id'  => $resource_id,
                'start'        => isset($timeline['start']) && is_string($timeline['start']) ? $timeline['start'] : self::compose_start_iso($date, $start_time),
                'duration'     => $duration,
                'end'          => $end_time,
                'pricing'      => $pricing,
                'combi'        => $combi_items[0]['id'] ?? ($plan_item['options']['combi'] ?? 0),
                'combi_label'  => $combi_items[0]['label'] ?? ($plan_item['options']['combiLabel'] ?? ''),
                'combi_timing' => $combi_items[0]['timing'] ?? 'before',
                'combi_multi'  => $combi_items,
                'aggregate_id' => isset($aggregate['aggregateId']) ? (string) $aggregate['aggregateId'] : '',
            ];

            return [
                'sbdp_plan_item'       => $plan_item,
                'sbdp_plan_aggregate'  => $aggregate,
                'sbdp_planner_input'   => $planner_input !== [] ? $planner_input : ($plan_item['plannerInput'] ?? []),
                'sbdp_plan_item_key'   => (string) ($plan_item['plannerKey'] ?? self::build_plan_item_key($plan_item)),
                'sbdp_date'            => $date,
                'sbdp_time'            => $start_time,
                'sbdp_participants'    => $participants,
                'sbdp_quantity'        => $participants,
                'sbdp_calculated_price'=> isset($pricing['total']) ? (float) $pricing['total'] : 0.0,
                'sbdp_summary'         => $summary,
                'sbdp_pricing'         => $pricing,
            ];
        }

        public static function persist_order_plan_item($item, $cart_item_key, $values, $order): void
        {
            unset($cart_item_key, $order);

            if (isset($values['sbdp_summary']) && is_array($values['sbdp_summary'])) {
                // Yield to Module.php which handles combined data persistence.
                return;
            }

            if (! isset($values['sbdp_plan_item']) || ! is_array($values['sbdp_plan_item'])) {
                return;
            }

            $item->add_meta_data('_sbdp_plan_item', wp_json_encode($values['sbdp_plan_item']));
            if (isset($values['sbdp_plan_aggregate']) && is_array($values['sbdp_plan_aggregate'])) {
                $item->add_meta_data('_sbdp_plan_aggregate', wp_json_encode($values['sbdp_plan_aggregate']));
            }

            if (isset($values['sbdp_planner_input']) && is_array($values['sbdp_planner_input'])) {
                $item->add_meta_data('_sbdp_planner_input', wp_json_encode($values['sbdp_planner_input']));
            }

            if (! empty($values['sbdp_plan_item_key'])) {
                $item->add_meta_data('_sbdp_plan_item_key', (string) $values['sbdp_plan_item_key']);
            }
        }

        public static function decode_json_array($raw): array
        {
            if (! is_string($raw) || trim($raw) === '') {
                return [];
            }

            $decoded = json_decode(wp_unslash($raw), true);
            return is_array($decoded) ? $decoded : [];
        }

        public static function rebuild_plan_item_from_cart_item(array $cart_item): ?array
        {
            if (isset($cart_item['sbdp_plan_item']) && is_array($cart_item['sbdp_plan_item'])) {
                $plan_item = $cart_item['sbdp_plan_item'];
                $plan_item['status'] = 'in-cart';
                if ((! isset($plan_item['aggregate']) || ! is_array($plan_item['aggregate'])) && isset($cart_item['sbdp_plan_aggregate']) && is_array($cart_item['sbdp_plan_aggregate'])) {
                    $plan_item['aggregate'] = $cart_item['sbdp_plan_aggregate'];
                }
                if (isset($cart_item['key'])) {
                    $plan_item['cartKey'] = (string) $cart_item['key'];
                }
                return $plan_item;
            }

            $summary = isset($cart_item['sbdp_summary']) && is_array($cart_item['sbdp_summary'])
                ? $cart_item['sbdp_summary']
                : [];
            if ($summary === []) {
                return null;
            }

            $product = isset($cart_item['data']) && is_object($cart_item['data']) ? $cart_item['data'] : null;
            $product_id = $product && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
            if ($product_id <= 0) {
                return null;
            }

            $planner_input = self::normalize_input([
                'product_id'   => $product_id,
                'date'         => $summary['date'] ?? '',
                'time'         => $summary['time'] ?? '',
                'participants' => $summary['participants'] ?? 1,
                'resource_id'  => $summary['resource_id'] ?? 0,
                'source'       => 'cart_restore',
                'combi_multi'  => $summary['combi_multi'] ?? [],
                'combi'        => $summary['combi'] ?? 0,
                'combi_label'  => $summary['combi_label'] ?? '',
            ]);

            $pricing = isset($cart_item['sbdp_pricing']) && is_array($cart_item['sbdp_pricing'])
                ? $cart_item['sbdp_pricing']
                : (is_array($summary['pricing'] ?? null) ? $summary['pricing'] : []);
            $aggregate = isset($cart_item['sbdp_plan_aggregate']) && is_array($cart_item['sbdp_plan_aggregate'])
                ? $cart_item['sbdp_plan_aggregate']
                : [];

            $availability = [
                'available'             => false,
                'selectedSlotAvailable' => false,
                'slots'                 => [],
                'resourceId'            => (int) ($summary['resource_id'] ?? 0),
                'capacity'              => max(1, (int) ($summary['participants'] ?? 1)),
                'reason'                => 'cart_restore_unverified',
            ];

            $plan_item = self::build_plan_item($planner_input, $product, $pricing, $availability, self::build_constraints($product_id));
            if ($aggregate !== []) {
                $plan_item['aggregate'] = $aggregate;
                $plan_item['aggregateId'] = (string) ($aggregate['aggregateId'] ?? ($plan_item['aggregateId'] ?? ''));
                $plan_item['groupId'] = (string) ($aggregate['groupId'] ?? ($plan_item['groupId'] ?? ''));
                $plan_item['segments'] = is_array($aggregate['segments'] ?? null) ? $aggregate['segments'] : ($plan_item['segments'] ?? []);
                $aggregate_pricing = is_array($aggregate['pricing'] ?? null) ? $aggregate['pricing'] : [];
                if ($aggregate_pricing !== []) {
                    $plan_item['pricing'] = self::build_plan_item_pricing_from_aggregate(
                        $aggregate_pricing,
                        (int) ($plan_item['participants'] ?? 1),
                        is_array($plan_item['options']['combiItems'] ?? null) ? $plan_item['options']['combiItems'] : []
                    );
                    $plan_item['totalCost'] = (float) ($aggregate_pricing['total'] ?? ($plan_item['totalCost'] ?? 0.0));
                }
                $timeline = is_array($aggregate['timeline'] ?? null) ? $aggregate['timeline'] : [];
                if ($timeline !== []) {
                    $plan_item['startTime'] = (string) ($timeline['startTime'] ?? ($plan_item['startTime'] ?? ''));
                    $plan_item['endTime'] = (string) ($timeline['endTime'] ?? ($plan_item['endTime'] ?? ''));
                    $plan_item['durationMinutes'] = (int) ($timeline['durationMinutes'] ?? ($plan_item['durationMinutes'] ?? 0));
                    $plan_item['startMinutes'] = self::time_to_minutes($plan_item['startTime']);
                    $plan_item['endMinutes'] = self::time_to_minutes($plan_item['endTime']);
                    $plan_item['date'] = (string) ($aggregate['date'] ?? ($plan_item['date'] ?? ''));
                }
            }
            $plan_item['status'] = 'in-cart';
            if (isset($cart_item['key'])) {
                $plan_item['cartKey'] = (string) $cart_item['key'];
            }

            return $plan_item;
        }

        public static function build_plan_item_key(array $plan_item): string
        {
            $product_id   = (int) ($plan_item['productId'] ?? ($plan_item['product_id'] ?? 0));
            $date         = (string) ($plan_item['date'] ?? '');
            $start_time   = (string) ($plan_item['startTime'] ?? '');
            $resource_id  = (int) ($plan_item['resourceId'] ?? ($plan_item['resource_id'] ?? 0));
            $participants = (int) ($plan_item['participants'] ?? 1);
            $combi_ids    = [];

            if (! empty($plan_item['options']['combiItems']) && is_array($plan_item['options']['combiItems'])) {
                foreach ($plan_item['options']['combiItems'] as $entry) {
                    $entry_id = self::to_positive_int($entry['id'] ?? 0);
                    if ($entry_id !== null) {
                        $combi_ids[] = $entry_id;
                    }
                }
            }

            return implode('|', [
                $product_id,
                $date,
                $start_time,
                $resource_id,
                $participants,
                implode(',', $combi_ids),
            ]);
        }

        private static function build_plan_item(array $normalized, $product, array $pricing, array $availability, array $constraints): array
        {
            $product_id    = (int) ($normalized['productId'] ?? 0);
            $date          = (string) ($normalized['date'] ?? '');
            $start_time    = (string) ($normalized['timeslot']['start'] ?? '');
            $end_time      = (string) ($normalized['timeslot']['end'] ?? '');
            $participants  = max(1, (int) ($normalized['participants'] ?? 1));
            $resource_id   = (int) ($normalized['resourceId'] ?? 0);
            $duration      = self::get_duration_minutes($product_id);
            $start_minutes = self::time_to_minutes($start_time);
            $end_minutes   = self::time_to_minutes($end_time);
            $product_name  = is_object($product) && method_exists($product, 'get_name') ? (string) $product->get_name() : sprintf(__('Activiteit %d', 'sbdp'), $product_id);
            $aggregate     = self::build_plan_aggregate($normalized, $product, $pricing);
            $aggregate_pricing = is_array($aggregate['pricing'] ?? null) ? $aggregate['pricing'] : [];
            $timeline      = is_array($aggregate['timeline'] ?? null) ? $aggregate['timeline'] : [];
            $currency      = (string) ($aggregate_pricing['currency'] ?? ($pricing['currency'] ?? self::get_currency()));
            $unit_price    = isset($aggregate_pricing['unit_price']) ? (float) $aggregate_pricing['unit_price'] : (isset($pricing['unitPrice']) ? (float) $pricing['unitPrice'] : (float) ($pricing['unit_price'] ?? 0.0));
            $total         = isset($aggregate_pricing['total']) ? (float) $aggregate_pricing['total'] : (isset($pricing['total']) ? (float) $pricing['total'] : 0.0);

            $item = [
                'schemaVersion'   => self::SCHEMA_VERSION,
                'id'              => 'domain-' . md5(wp_json_encode([$product_id, $date, $start_time, $participants, $resource_id])),
                'plannerKey'      => '',
                'status'          => 'planned',
                'source'          => (string) ($normalized['source'] ?? 'planner'),
                'aggregateId'     => (string) ($aggregate['aggregateId'] ?? ''),
                'groupId'         => (string) ($aggregate['groupId'] ?? ''),
                'productId'       => $product_id,
                'product_id'      => $product_id,
                'productType'     => (string) ($normalized['productType'] ?? ''),
                'title'           => $product_name,
                'date'            => (string) ($aggregate['date'] ?? $date),
                'dayIndex'        => 0,
                'participants'    => $participants,
                'durationMinutes' => isset($timeline['durationMinutes']) ? (int) $timeline['durationMinutes'] : $duration,
                'startMinutes'    => isset($timeline['startTime']) ? self::time_to_minutes((string) $timeline['startTime']) : $start_minutes,
                'endMinutes'      => isset($timeline['endTime']) ? self::time_to_minutes((string) $timeline['endTime']) : $end_minutes,
                'startTime'       => isset($timeline['startTime']) ? (string) $timeline['startTime'] : $start_time,
                'endTime'         => isset($timeline['endTime']) ? (string) $timeline['endTime'] : $end_time,
                'resourceId'      => $resource_id,
                'resource_id'     => $resource_id,
                'locationContext' => $normalized['locationContext'] ?? ['resourceId' => $resource_id],
                'pricing'         => self::build_plan_item_pricing_from_aggregate(
                    $aggregate_pricing !== [] ? $aggregate_pricing : $pricing,
                    $participants,
                    is_array($normalized['options']['combiItems'] ?? null) ? $normalized['options']['combiItems'] : []
                ),
                'price_pp'        => $unit_price,
                'fixedCost'       => isset($aggregate_pricing['adjustments_total']) ? (float) $aggregate_pricing['adjustments_total'] : (isset($pricing['bookingAdjustment']) ? (float) $pricing['bookingAdjustment'] : (float) ($pricing['booking_adjustment'] ?? 0.0)),
                'totalCost'       => $total,
                'locked'          => true,
                'options'         => $normalized['options'] ?? ['combiItems' => []],
                'segments'        => is_array($aggregate['segments'] ?? null) ? $aggregate['segments'] : [],
                'aggregate'       => $aggregate,
                'plannerInput'    => $normalized,
                'evaluation'      => [
                    'availability' => $availability,
                    'constraints'  => $constraints,
                ],
                'cartMapping'     => [
                    'product_id' => $product_id,
                    'quantity'   => $participants,
                    'line_hash'  => '',
                ],
            ];

            $item['plannerKey'] = self::build_plan_item_key($item);
            $item['cartMapping']['line_hash'] = $item['plannerKey'];

            return $item;
        }

        private static function build_summary($product, array $normalized, array $pricing, array $plan_item): array
        {
            $participants = max(1, (int) ($normalized['participants'] ?? 1));
            $aggregate     = is_array($plan_item['aggregate'] ?? null) ? $plan_item['aggregate'] : [];
            $aggregate_pricing = is_array($aggregate['pricing'] ?? null) ? $aggregate['pricing'] : [];
            $timeline      = is_array($aggregate['timeline'] ?? null) ? $aggregate['timeline'] : [];
            $date         = (string) ($aggregate['date'] ?? ($normalized['date'] ?? ''));
            $time         = (string) ($timeline['startTime'] ?? ($normalized['timeslot']['start'] ?? ''));
            $title        = is_object($product) && method_exists($product, 'get_name') ? (string) $product->get_name() : '';
            $total        = isset($aggregate_pricing['total']) ? (float) $aggregate_pricing['total'] : (isset($pricing['total']) ? (float) $pricing['total'] : 0.0);

            return [
                'title'            => $title,
                'date'             => $date,
                'startTime'        => $time,
                'participants'     => $participants,
                'participantsText' => sprintf(_n('%d deelnemer', '%d deelnemers', $participants, 'sbdp'), $participants),
                'total'            => $total,
                'totalFormatted'   => self::format_price($total, (string) ($aggregate_pricing['currency'] ?? ($pricing['currency'] ?? self::get_currency()))),
                'planItemKey'      => (string) ($plan_item['plannerKey'] ?? ''),
            ];
        }

        private static function build_plan_aggregate(array $normalized, $product, array $pricing): array
        {
            $product_id      = (int) ($normalized['productId'] ?? 0);
            $date            = (string) ($normalized['date'] ?? '');
            $participants    = max(1, (int) ($normalized['participants'] ?? 1));
            $resource_id     = (int) ($normalized['resourceId'] ?? 0);
            $resource_label  = (string) ($normalized['locationContext']['resourceLabel'] ?? '');
            $anchor_title    = is_object($product) && method_exists($product, 'get_name')
                ? (string) $product->get_name()
                : sprintf(__('Activiteit %d', 'sbdp'), $product_id);
            $anchor_start    = self::sanitize_time($normalized['timeslot']['start'] ?? null) ?? '';
            $anchor_end      = self::sanitize_time($normalized['timeslot']['end'] ?? null) ?? '';
            $anchor_duration = self::to_positive_int(self::get_duration_minutes($product_id)) ?? 0;
            $currency        = (string) ($pricing['currency'] ?? self::get_currency());
            $anchor_total    = isset($pricing['anchor_total']) ? (float) $pricing['anchor_total'] : (float) ($pricing['total'] ?? 0.0);
            $anchor_unit     = isset($pricing['anchor_unit_price']) ? (float) $pricing['anchor_unit_price'] : (isset($pricing['unitPrice']) ? (float) $pricing['unitPrice'] : (float) ($pricing['unit_price'] ?? 0.0));
            $anchor_subtotal = isset($pricing['anchor_line_subtotal']) ? (float) $pricing['anchor_line_subtotal'] : (isset($pricing['lineSubtotal']) ? (float) $pricing['lineSubtotal'] : $anchor_total);

            $segments = [
                [
                    'segmentId'       => 'anchor-' . $product_id,
                    'productId'       => $product_id,
                    'title'           => $anchor_title,
                    'kind'            => 'anchor',
                    'timing'          => 'anchor',
                    'required'        => true,
                    'participants'    => $participants,
                    'resourceId'      => $resource_id,
                    'resourceLabel'   => $resource_label,
                    'durationMinutes' => $anchor_duration,
                    'startTime'       => $anchor_start,
                    'endTime'         => $anchor_end,
                    'start'           => self::compose_start_iso($date, $anchor_start),
                    'end'             => self::compose_start_iso($date, $anchor_end),
                    'pricing'         => [
                        'currency'    => $currency,
                        'subtotal'    => round($anchor_subtotal, 2),
                        'tax'         => round(self::sum_money_rows($pricing['taxes'] ?? []), 2),
                        'unit_price'  => round($anchor_unit, 2),
                        'total'       => round($anchor_total, 2),
                        'adjustments' => is_array($pricing['adjustments'] ?? null) ? $pricing['adjustments'] : [],
                        'discounts'   => is_array($pricing['discounts'] ?? null) ? $pricing['discounts'] : [],
                        'taxes'       => is_array($pricing['taxes'] ?? null) ? $pricing['taxes'] : [],
                    ],
                ],
            ];

            [$before_segments, $after_segments] = self::partition_combi_segments(
                is_array($pricing['combi_multi'] ?? null) ? $pricing['combi_multi'] : []
            );

            $before_cursor = self::time_to_minutes($anchor_start);
            $before_built = [];
            for ($index = count($before_segments) - 1; $index >= 0; $index--) {
                $segment = self::build_combi_segment($before_segments[$index], $date, $participants, $before_cursor, 'before');
                if ($segment !== null) {
                    $before_cursor = self::time_to_minutes((string) $segment['startTime']);
                    array_unshift($before_built, $segment);
                }
            }

            $after_cursor = self::time_to_minutes($anchor_end);
            $after_built = [];
            foreach ($after_segments as $entry) {
                $segment = self::build_combi_segment($entry, $date, $participants, $after_cursor, 'after');
                if ($segment !== null) {
                    $after_cursor = self::time_to_minutes((string) $segment['endTime']);
                    $after_built[] = $segment;
                }
            }

            $segments = array_merge($before_built, $segments, $after_built);
            $pricing_items = [];
            foreach ($segments as $segment) {
                $segment_pricing = is_array($segment['pricing'] ?? null) ? $segment['pricing'] : [];
                $pricing_items[] = [
                    'segmentId'       => (string) ($segment['segmentId'] ?? ''),
                    'productId'       => (int) ($segment['productId'] ?? 0),
                    'title'           => (string) ($segment['title'] ?? ''),
                    'kind'            => (string) ($segment['kind'] ?? 'segment'),
                    'timing'          => (string) ($segment['timing'] ?? 'before'),
                    'participants'    => $participants,
                    'durationMinutes' => (int) ($segment['durationMinutes'] ?? 0),
                    'unit_price'      => round((float) ($segment_pricing['unit_price'] ?? 0.0), 2),
                    'subtotal'        => round((float) ($segment_pricing['subtotal'] ?? ($segment_pricing['total'] ?? 0.0)), 2),
                    'tax'             => round((float) ($segment_pricing['tax'] ?? 0.0), 2),
                    'total'           => round((float) ($segment_pricing['total'] ?? 0.0), 2),
                ];
            }

            $timeline_start = $segments !== [] ? (string) ($segments[0]['startTime'] ?? $anchor_start) : $anchor_start;
            $timeline_end = $segments !== [] ? (string) ($segments[count($segments) - 1]['endTime'] ?? $anchor_end) : $anchor_end;
            $timeline_duration = max(0, self::time_to_minutes($timeline_end) - self::time_to_minutes($timeline_start));
            $subtotal = round(array_reduce($pricing_items, static function (float $carry, array $item): float {
                return $carry + (float) ($item['subtotal'] ?? 0.0);
            }, 0.0), 2);
            $tax_total = round(array_reduce($pricing_items, static function (float $carry, array $item): float {
                return $carry + (float) ($item['tax'] ?? 0.0);
            }, 0.0), 2);
            $total = round(array_reduce($pricing_items, static function (float $carry, array $item): float {
                return $carry + (float) ($item['total'] ?? 0.0);
            }, 0.0), 2);
            $adjustments_total = round(self::sum_money_rows($pricing['adjustments'] ?? []), 2);
            $discount_total = round(self::sum_money_rows($pricing['discounts'] ?? []), 2);

            $aggregate_id = 'agg-' . md5(wp_json_encode([
                $product_id,
                $date,
                $anchor_start,
                $resource_id,
                $participants,
                $pricing_items,
            ]));

            return [
                'schemaVersion' => self::SCHEMA_VERSION,
                'aggregateId'   => $aggregate_id,
                'groupId'       => $aggregate_id,
                'productId'     => $product_id,
                'date'          => $date,
                'participants'  => $participants,
                'resourceId'    => $resource_id,
                'resourceLabel' => $resource_label,
                'anchor'        => [
                    'productId'       => $product_id,
                    'title'           => $anchor_title,
                    'startTime'       => $anchor_start,
                    'endTime'         => $anchor_end,
                    'start'           => self::compose_start_iso($date, $anchor_start),
                    'end'             => self::compose_start_iso($date, $anchor_end),
                    'durationMinutes' => $anchor_duration,
                ],
                'segments'      => $segments,
                'timeline'      => [
                    'startTime'       => $timeline_start,
                    'endTime'         => $timeline_end,
                    'start'           => self::compose_start_iso($date, $timeline_start),
                    'end'             => self::compose_start_iso($date, $timeline_end),
                    'durationMinutes' => $timeline_duration,
                ],
                'pricing'       => [
                    'currency'          => $currency,
                    'items'             => $pricing_items,
                    'items_count'       => count($pricing_items),
                    'subtotal'          => $subtotal,
                    'adjustments_total' => $adjustments_total,
                    'discount_total'    => $discount_total,
                    'tax'               => $tax_total,
                    'tax_total'         => $tax_total,
                    'total'             => $total,
                    'unit_price'        => $participants > 0 ? round($total / $participants, 2) : round($total, 2),
                    'adjustments'       => is_array($pricing['adjustments'] ?? null) ? $pricing['adjustments'] : [],
                    'discounts'         => is_array($pricing['discounts'] ?? null) ? $pricing['discounts'] : [],
                    'taxes'             => is_array($pricing['taxes'] ?? null) ? $pricing['taxes'] : [],
                ],
            ];
        }

        private static function build_plan_item_pricing_from_aggregate(array $pricing, int $participants, array $combi_items): array
        {
            $total = (float) ($pricing['total'] ?? 0.0);
            $unit_price = isset($pricing['unit_price']) ? (float) $pricing['unit_price'] : ($participants > 0 ? round($total / $participants, 2) : $total);

            return [
                'currency'          => (string) ($pricing['currency'] ?? self::get_currency()),
                'subtotal'          => (float) ($pricing['subtotal'] ?? $total),
                'tax'               => (float) ($pricing['tax'] ?? ($pricing['tax_total'] ?? 0.0)),
                'tax_total'         => (float) ($pricing['tax_total'] ?? ($pricing['tax'] ?? 0.0)),
                'total'             => $total,
                'unitPrice'         => $unit_price,
                'unit_price'        => $unit_price,
                'per_person'        => $unit_price,
                'bookingAdjustment' => (float) ($pricing['adjustments_total'] ?? 0.0),
                'fixed_fee'         => (float) ($pricing['adjustments_total'] ?? 0.0),
                'adjustments'       => is_array($pricing['adjustments'] ?? null) ? $pricing['adjustments'] : [],
                'discounts'         => is_array($pricing['discounts'] ?? null) ? $pricing['discounts'] : [],
                'taxes'             => is_array($pricing['taxes'] ?? null) ? $pricing['taxes'] : [],
                'segments'          => is_array($pricing['items'] ?? null) ? $pricing['items'] : [],
                'dynamic'           => ['total' => $total],
                'combi_multi'       => $combi_items,
            ];
        }

        private static function partition_combi_segments(array $combi_items): array
        {
            $before = [];
            $after = [];

            foreach ($combi_items as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $timing = self::sanitize_combi_timing($entry['timing'] ?? null);
                if ($timing === 'after') {
                    $after[] = $entry;
                    continue;
                }

                $before[] = $entry;
            }

            return [$before, $after];
        }

        private static function build_combi_segment(array $entry, string $date, int $participants, int $cursor_minutes, string $timing): ?array
        {
            $product_id = self::to_positive_int($entry['id'] ?? 0);
            if ($product_id === null) {
                return null;
            }

            $duration = self::to_positive_int($entry['duration'] ?? 0) ?? self::get_duration_minutes($product_id);
            if ($duration <= 0) {
                $duration = 60;
            }

            if ($timing === 'after') {
                $start_minutes = $cursor_minutes;
                $end_minutes = $cursor_minutes + $duration;
            } else {
                $start_minutes = $cursor_minutes - $duration;
                $end_minutes = $cursor_minutes;
            }

            $start_time = self::minutes_to_time($start_minutes);
            $end_time = self::minutes_to_time($end_minutes);

            return [
                'segmentId'       => 'segment-' . $product_id . '-' . md5(wp_json_encode([$product_id, $start_time, $end_time, $timing])),
                'productId'       => $product_id,
                'title'           => (string) ($entry['label'] ?? ''),
                'kind'            => 'segment',
                'timing'          => $timing,
                'required'        => true,
                'participants'    => $participants,
                'resourceId'      => 0,
                'resourceLabel'   => '',
                'durationMinutes' => $duration,
                'startTime'       => $start_time,
                'endTime'         => $end_time,
                'start'           => self::compose_start_iso($date, $start_time),
                'end'             => self::compose_start_iso($date, $end_time),
                'pricing'         => [
                    'currency'   => self::get_currency(),
                    'subtotal'   => round((float) ($entry['total'] ?? 0.0), 2),
                    'tax'        => 0.0,
                    'unit_price' => round((float) ($entry['unit_price'] ?? 0.0), 2),
                    'total'      => round((float) ($entry['total'] ?? 0.0), 2),
                ],
            ];
        }

        private static function sum_money_rows($rows): float
        {
            if (! is_array($rows)) {
                return 0.0;
            }

            return array_reduce(
                $rows,
                static function (float $carry, $row): float {
                    if (! is_array($row)) {
                        return $carry;
                    }

                    return $carry + (float) ($row['amount'] ?? 0.0);
                },
                0.0
            );
        }

        private static function minutes_to_time(int $minutes): string
        {
            $safe_minutes = max(0, min((23 * 60) + 59, $minutes));
            $hours = (int) floor($safe_minutes / 60);
            $mins = $safe_minutes % 60;

            return sprintf('%02d:%02d', $hours, $mins);
        }

        private static function resolve_availability(int $product_id, int $resource_id, string $date, string $time, int $participants): array
        {
            $slots = [];
            $capacity = 0;

            if (class_exists('\BSPModule\Core\Rest\RestService') && $product_id > 0 && $date !== '') {
                $request = new \WP_REST_Request('GET');
                $request->set_param('product_id', $product_id);
                $request->set_param('resource_id', $resource_id);
                $request->set_param('date', $date);

                $response = \BSPModule\Core\Rest\RestService::availability_slots($request);
                $payload  = $response instanceof \WP_REST_Response ? $response->get_data() : (is_array($response) ? $response : []);
                $slots    = isset($payload['slots']) && is_array($payload['slots']) ? $payload['slots'] : [];
                $capacity = (int) ($payload['capacity'] ?? 0);
            }

            $selected_slot_available = false;
            foreach ($slots as $slot) {
                $slot_start = self::sanitize_time($slot['start'] ?? null);
                if ($slot_start !== null && $slot_start === $time) {
                    $selected_slot_available = true;
                    break;
                }
            }

            if ($time !== '' && $slots === []) {
                $selected_slot_available = true;
            }

            return [
                'available'             => $slots !== [] || $time !== '',
                'selectedSlotAvailable' => $selected_slot_available,
                'slots'                 => $slots,
                'capacity'              => $capacity,
                'resourceId'            => $resource_id,
                'participants'          => $participants,
            ];
        }

        private static function resolve_pricing($product, int $resource_id, string $start_iso, int $participants): array
        {
            $pricing = [
                'currency'          => self::get_currency(),
                'basePrice'         => 0.0,
                'unitPrice'         => 0.0,
                'bookingAdjustment' => 0.0,
                'lineSubtotal'      => 0.0,
                'total'             => 0.0,
                'adjustments'       => [],
                'discounts'         => [],
                'taxes'             => [],
            ];

            if (class_exists('\SBDP\Pricing\PricingService') && is_object($product)) {
                $product_id = method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
                if ($product_id > 0) {
                    $quote = \SBDP\Pricing\PricingService::instance()->quote(
                        $product_id,
                        $participants,
                        [
                            'channel'     => 'shared_planner_domain',
                            'source'      => 'planner_domain',
                            'start'       => $start_iso,
                            'resource_id' => $resource_id,
                            'price_mode'  => 'gross',
                        ]
                    );

                    if (is_array($quote)) {
                        $pricing['currency']          = (string) ($quote['currency'] ?? self::get_currency());
                        $pricing['basePrice']         = (float) ($quote['line_item']['pricing']['base_price'] ?? 0.0);
                        $pricing['unitPrice']         = (float) ($quote['unit_price'] ?? 0.0);
                        $pricing['bookingAdjustment'] = (float) (\SBDP\Pricing\PricingService::instance()->sumRows($quote['adjustments'] ?? []));
                        $pricing['lineSubtotal']      = (float) ($quote['line_item']['line_subtotal'] ?? 0.0);
                        $pricing['total']             = (float) ($quote['total'] ?? 0.0);
                        $pricing['adjustments']       = is_array($quote['adjustments'] ?? null) ? $quote['adjustments'] : [];
                        $pricing['discounts']         = is_array($quote['discounts'] ?? null) ? $quote['discounts'] : [];
                        $pricing['taxes']             = is_array($quote['taxes'] ?? null) ? $quote['taxes'] : [];
                    }
                }
            }

            $pricing['anchor_total']         = $pricing['total'];
            $pricing['anchor_unit_price']    = $pricing['unitPrice'];
            $pricing['anchor_line_subtotal'] = $pricing['lineSubtotal'] > 0.0 ? $pricing['lineSubtotal'] : $pricing['total'];

            return $pricing;
        }

        private static function resolve_combi_breakdown(array $combi_items, int $participants): array
        {
            $breakdown = [];

            foreach ($combi_items as $entry) {
                $combi_id = self::to_positive_int($entry['id'] ?? 0);
                if ($combi_id === null || ! function_exists('wc_get_product')) {
                    continue;
                }

                $product = wc_get_product($combi_id);
                if (! $product) {
                    continue;
                }

                $quote = self::resolve_pricing($product, 0, '', $participants);
                $unit  = (float) ($quote['unitPrice'] ?? 0.0);
                $total = (float) ($quote['total'] ?? 0.0);

                if ($unit <= 0.0 && $total > 0.0 && $participants > 0) {
                    $unit = round($total / $participants, 2);
                }
                if ($unit <= 0.0) {
                    continue;
                }

                $breakdown[] = [
                    'id'        => $combi_id,
                    'label'     => (string) ($entry['label'] ?? (method_exists($product, 'get_name') ? $product->get_name() : '')),
                    'timing'    => self::sanitize_combi_timing($entry['timing'] ?? null),
                    'duration'  => self::to_positive_int($entry['duration'] ?? 0) ?? self::get_duration_minutes($combi_id),
                    'start'     => self::sanitize_time($entry['start'] ?? null) ?? '',
                    'end'       => self::sanitize_time($entry['end'] ?? null) ?? '',
                    'unit_price'=> $unit,
                    'total'     => $total > 0.0 ? $total : round($unit * $participants, 2),
                ];
            }

            return $breakdown;
        }

        private static function merge_combi_pricing(array $pricing, array $combi_breakdown): array
        {
            if ($combi_breakdown === []) {
                return $pricing;
            }

            $combi_total = 0.0;
            $combi_unit  = 0.0;
            foreach ($combi_breakdown as $entry) {
                $combi_total += (float) ($entry['total'] ?? 0.0);
                $combi_unit += (float) ($entry['unit_price'] ?? 0.0);
            }

            $pricing['combi_multi'] = $combi_breakdown;
            $pricing['unitPrice'] = round(((float) ($pricing['unitPrice'] ?? 0.0)) + $combi_unit, 2);
            $pricing['total'] = round(((float) ($pricing['total'] ?? 0.0)) + $combi_total, 2);

            return $pricing;
        }

        private static function build_constraints(int $product_id): array
        {
            $min = self::to_positive_int(get_post_meta($product_id, '_sbdp_min_people', true)) ?? 1;
            $max = self::to_positive_int(get_post_meta($product_id, '_sbdp_max_people', true));

            return [
                'participants' => [
                    'min' => max(1, $min),
                    'max' => $max ?: 999,
                ],
                'durationMinutes' => self::get_duration_minutes($product_id),
            ];
        }

        private static function normalize_combi_items(array $raw): array
        {
            $items = [];

            if (isset($raw['options']['combiItems']) && is_array($raw['options']['combiItems'])) {
                $source_items = $raw['options']['combiItems'];
            } elseif (isset($raw['combi_multi']) && is_array($raw['combi_multi'])) {
                $source_items = $raw['combi_multi'];
            } elseif (isset($raw['sbdp_active_combis']) && is_array($raw['sbdp_active_combis'])) {
                $source_items = $raw['sbdp_active_combis'];
            } else {
                $source_items = [];
                $combi_ids = isset($raw['combi_ids']) && is_array($raw['combi_ids']) ? $raw['combi_ids'] : [];
                foreach ($combi_ids as $combi_id) {
                    $source_items[] = ['id' => $combi_id];
                }
            }

            foreach ($source_items as $entry) {
                $entry_id = self::to_positive_int($entry['id'] ?? 0);
                if ($entry_id === null) {
                    continue;
                }

                $timing = self::sanitize_combi_timing($entry['timing'] ?? ($entry['moment'] ?? ($entry['role'] ?? null)));
                $duration = self::to_positive_int($entry['durationMinutes'] ?? ($entry['duration'] ?? 0)) ?? 0;
                $label = self::sanitize_text($entry['label'] ?? '');
                if ($label === '' && function_exists('wc_get_product')) {
                    $product = wc_get_product($entry_id);
                    if ($product instanceof \WC_Product) {
                        $label = (string) $product->get_name();
                    }
                }

                $items[] = [
                    'id'       => $entry_id,
                    'label'    => $label,
                    'timing'   => $timing,
                    'role'     => $timing === 'after' ? 'post' : 'pre',
                    'order'    => self::to_positive_int($entry['order'] ?? 0) ?? count($items),
                    'duration' => $duration,
                    'durationMinutes' => $duration,
                    'start'    => self::sanitize_time($entry['start'] ?? null) ?? '',
                    'end'      => self::sanitize_time($entry['end'] ?? null) ?? '',
                ];
            }

            usort(
                $items,
                static function (array $left, array $right): int {
                    return ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0));
                }
            );

            return $items;
        }

        private static function collect_cart_state_items(): array
        {
            if (! function_exists('WC') || ! WC() || ! WC()->cart) {
                return [];
            }

            $items = [];
            foreach (WC()->cart->get_cart() as $cart_key => $cart_item) {
                if (! is_array($cart_item)) {
                    continue;
                }

                $cart_item['key'] = $cart_key;
                $plan_item = self::rebuild_plan_item_from_cart_item($cart_item);
                if (! $plan_item) {
                    continue;
                }

                $items[] = [
                    'plannerKey'   => (string) ($plan_item['plannerKey'] ?? self::build_plan_item_key($plan_item)),
                    'status'       => 'in-cart',
                    'cartKey'      => $cart_key,
                    'productId'    => (int) ($plan_item['productId'] ?? 0),
                    'participants' => (int) ($plan_item['participants'] ?? 1),
                    'planItem'     => $plan_item,
                ];
            }

            return $items;
        }

        private static function get_evaluate_route_args(): array
        {
            return [
                'productId' => [
                    'sanitize_callback' => [self::class, 'sanitize_rest_positive_int'],
                ],
                'product_id' => [
                    'sanitize_callback' => [self::class, 'sanitize_rest_positive_int'],
                ],
                'resourceId' => [
                    'sanitize_callback' => [self::class, 'sanitize_rest_positive_int'],
                ],
                'resource_id' => [
                    'sanitize_callback' => [self::class, 'sanitize_rest_positive_int'],
                ],
                'participants' => [
                    'sanitize_callback' => [self::class, 'sanitize_rest_positive_int'],
                ],
                'people' => [
                    'sanitize_callback' => [self::class, 'sanitize_rest_positive_int'],
                ],
                'date' => [
                    'sanitize_callback' => [self::class, 'sanitize_rest_date'],
                ],
                'start_date' => [
                    'sanitize_callback' => [self::class, 'sanitize_rest_date'],
                ],
                'time' => [
                    'sanitize_callback' => [self::class, 'sanitize_rest_time'],
                ],
                'start_time' => [
                    'sanitize_callback' => [self::class, 'sanitize_rest_time'],
                ],
                'source' => [
                    'sanitize_callback' => [self::class, 'sanitize_rest_text'],
                ],
            ];
        }

        private static function should_enqueue_assets(): bool
        {
            if (is_admin()) {
                return false;
            }

            if (function_exists('is_product') && is_product()) {
                return true;
            }

            if (function_exists('is_page') && is_page('plan-je-dag')) {
                return true;
            }

            if (function_exists('has_shortcode') && function_exists('is_singular') && is_singular()) {
                $post = get_post();
                if ($post && has_shortcode((string) $post->post_content, 'sbdp_dayplanner')) {
                    return true;
                }
            }

            return false;
        }

        private static function resolve_planner_url(): string
        {
            $page = get_page_by_path('plan-je-dag');
            if ($page instanceof \WP_Post) {
                $permalink = get_permalink($page);
                if ($permalink) {
                    return $permalink;
                }
            }

            return home_url('/plan-je-dag/');
        }

        private static function resolve_resource_label(int $product_id, int $resource_id): string
        {
            if ($resource_id <= 0 || ! class_exists('\BSPModule\Core\Product\ProductMeta')) {
                return '';
            }

            $resources = \BSPModule\Core\Product\ProductMeta::get_resources_payload($product_id);
            if (! is_array($resources)) {
                return '';
            }

            foreach ($resources as $resource) {
                if ((int) ($resource['id'] ?? 0) !== $resource_id) {
                    continue;
                }

                return self::sanitize_text($resource['title'] ?? ($resource['name'] ?? ''));
            }

            return '';
        }

        private static function get_duration_minutes(int $product_id): int
        {
            $duration = (int) get_post_meta($product_id, '_sbdp_duration', true);
            if ($duration <= 0) {
                $duration = 90;
            }

            $unit = strtolower((string) get_post_meta($product_id, '_sbdp_duration_unit', true));
            if (in_array($unit, ['hour', 'hours', 'uur', 'uren'], true)) {
                return $duration * 60;
            }
            if (in_array($unit, ['day', 'days', 'dag', 'dagen'], true)) {
                return $duration * 1440;
            }

            return $duration;
        }

        private static function compose_end_time(string $date, string $time, int $duration_minutes): string
        {
            if ($date === '' || $time === '' || $duration_minutes <= 0) {
                return '';
            }

            try {
                $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
                $start = new \DateTimeImmutable($date . ' ' . $time, $timezone);
                return $start->add(new \DateInterval('PT' . $duration_minutes . 'M'))->format('H:i');
            } catch (\Throwable $exception) {
                return '';
            }
        }

        private static function compose_start_iso(string $date, string $time): string
        {
            if ($date === '' || $time === '') {
                return '';
            }

            return sprintf('%sT%s:00', $date, $time);
        }

        private static function sanitize_date($value): ?string
        {
            if (! is_string($value)) {
                return null;
            }

            $value = trim($value);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }

             $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
             if (! $date || $date->format('Y-m-d') !== $value) {
                 return null;
             }

            return $value;
        }

        private static function sanitize_time($value): ?string
        {
            if (! is_string($value)) {
                return null;
            }

            $value = trim($value);
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
                return $value;
            }

            if (preg_match('/\b([01]\d|2[0-3]):([0-5]\d)\b/', $value, $matches)) {
                return $matches[1];
            }

            return null;
        }

        private static function sanitize_text($value): string
        {
            if (! is_scalar($value)) {
                return '';
            }

            return sanitize_text_field((string) $value);
        }

        /**
         * CSOT guard: validate that submitted combi item IDs are registered on the
         * product's _sbdp_combi_deals meta (prevents unauthorized injection).
         * Also normalizes duration from product meta when the submitted value is 0
         * to guarantee correct timeline calculations.
         *
         * If the product has no _sbdp_combi_deals meta at all, all items pass through
         * (backwards-compatible, prevents breakage on products that predate the meta).
         *
         * @param array<int, array<string, mixed>> $combi_items
         * @return array<int, array<string, mixed>>
         */
        public static function validate_combi_items(int $product_id, array $combi_items): array
        {
            if ($product_id <= 0 || $combi_items === [] || ! function_exists('get_post_meta')) {
                return $combi_items;
            }

            // Build the authorized ID list from _sbdp_combi_deals.
            $raw = get_post_meta($product_id, '_sbdp_combi_deals', true);
            if (is_string($raw)) {
                $trimmed = trim($raw);
                if ($trimmed !== '' && $trimmed[0] === '[') {
                    $decoded = json_decode($trimmed, true);
                    $raw = is_array($decoded) ? $decoded : [];
                } else {
                    $raw = array_filter(array_map('trim', explode(',', $trimmed)));
                }
            }

            if (! is_array($raw) || $raw === []) {
                // No deal list defined — allow all items (backwards-compatible).
                return $combi_items;
            }

            $allowed_ids = array_values(array_filter(array_map('intval', $raw)));
            if ($allowed_ids === []) {
                return $combi_items;
            }

            $validated = [];
            foreach ($combi_items as $entry) {
                $id = isset($entry['id']) ? (int) $entry['id'] : 0;
                if ($id <= 0 || ! in_array($id, $allowed_ids, true)) {
                    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        error_log(sprintf(
                            'SBDP CSOT WARNING: combi item id=%d is not in _sbdp_combi_deals of product %d — rejected.',
                            $id,
                            $product_id
                        ));
                    }
                    continue;
                }

                // Normalize duration: if not provided, read canonical duration from product meta.
                if (empty($entry['duration']) && empty($entry['durationMinutes'])) {
                    $canonical = self::get_duration_minutes($id);
                    if ($canonical > 0) {
                        $entry['duration'] = $canonical;
                        $entry['durationMinutes'] = $canonical;
                    }
                }

                $validated[] = $entry;
            }

            return $validated;
        }

        private static function sanitize_combi_timing($value): string
        {
            $timing = self::sanitize_text($value);
            return $timing === 'after' ? 'after' : 'before';
        }

        private static function to_positive_int($value): ?int
        {
            if ($value === null || $value === '') {
                return null;
            }

            $parsed = (int) $value;
            return $parsed > 0 ? $parsed : null;
        }

        private static function time_to_minutes(string $time): int
        {
            if ($time === '') {
                return 0;
            }

            [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);
            return ($hours * 60) + $minutes;
        }

        private static function get_currency(): string
        {
            return function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'EUR';
        }

        private static function authorize_public_route(string $bucket, int $limit, \WP_REST_Request $request)
        {
            unset($request);

            if ($limit <= 0 || ! function_exists('get_transient') || ! function_exists('set_transient')) {
                return true;
            }

            $subject = self::resolve_rate_limit_subject();
            if ($subject === '') {
                return true;
            }

            $cache_key = 'sbdp_planner_rl_' . md5($bucket . '|' . $subject);
            $state = get_transient($cache_key);
            if (! is_array($state)) {
                $state = [
                    'count' => 0,
                ];
            }

            $count = (int) ($state['count'] ?? 0);
            if ($count >= $limit) {
                return new \WP_Error(
                    'sbdp_planner_rate_limited',
                    __('Te veel planner-verzoeken. Probeer het zo opnieuw.', 'sbdp'),
                    ['status' => 429]
                );
            }

            $state['count'] = $count + 1;
            set_transient($cache_key, $state, self::RATE_LIMIT_WINDOW);

            return true;
        }

        private static function resolve_rate_limit_subject(): string
        {
            $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            if ($user_id > 0) {
                return 'user:' . $user_id;
            }

            foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP'] as $server_key) {
                if (empty($_SERVER[$server_key]) || ! is_string($_SERVER[$server_key])) {
                    continue;
                }

                $candidate = trim((string) explode(',', $_SERVER[$server_key])[0]);
                $candidate = preg_replace('/[^0-9a-fA-F:\.]/', '', $candidate);
                if ($candidate !== '') {
                    return 'ip:' . $candidate;
                }
            }

            return '';
        }

        private static function format_price(float $amount, string $currency): string
        {
            if (function_exists('wc_price')) {
                return wp_strip_all_tags((string) wc_price($amount, ['currency' => $currency]));
            }

            return number_format_i18n($amount, 2) . ' ' . $currency;
        }
    }

    SBDP_Planner_Domain_Service::bootstrap();
}
