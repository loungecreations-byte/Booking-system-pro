<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

use BSPModule\Core\Rest\RestService;
use BSPModule\Core\Services\AvailabilityExecutionService;
use BSPModule\Core\Services\BookingTruthRuntimeService;
use InvalidArgumentException;
use RuntimeException;
use SBDP\BookingBoard\BookingBoardSchema;
use SBDP\BookingBoard\BookingBoardQuery;
use SBDP\Pricing\SelectionPricing;

final class PlanService
{
    private const BOOKING_CAPABILITY_DIRECT = 'DIRECT_ELIGIBLE';
    private const BOOKING_CAPABILITY_REQUEST = 'REQUEST_ONLY';
    private const CAPABILITY_STATUS_DIRECT = 'DIRECT';
    private const CAPABILITY_STATUS_DIRECT_LIMITED = 'DIRECT_LIMITED';
    private const CAPABILITY_STATUS_REQUEST = 'REQUEST';
    private const CAPABILITY_STATUS_UNAVAILABLE = 'UNAVAILABLE';
    private const ROUTE_INTENT_CHECKOUT = 'checkout';
    private const ROUTE_INTENT_QUOTE = 'quote';
    private const ROUTE_INTENT_BLOCKED = 'blocked';

    private PlanRepository $repository;

    private PriceEngine $pricing;

    private AvailabilityService $availability;

    private AiSuggestionService $ai;

    private ActivityService $activities;

    private ProductCatalogService $catalog;

    private SessionContextStore $sessionStore;

    private DeterministicDecisionEngine $decisionEngine;

    private FeasibleDayPlanEngine $planEngine;

    private PlannerEventLogger $eventLogger;

    private DBSpotsClient $spotsClient;

    private AssistantResponseValidator $responseValidator;

    private ?BookingTruthRuntimeService $bookingTruthRuntime = null;

    public function __construct(
        ?PlanRepository $repository = null,
        ?PriceEngine $pricing = null,
        ?AvailabilityService $availability = null,
        ?AiSuggestionService $ai = null,
        ?ActivityService $activities = null,
        ?ProductCatalogService $catalog = null,
        ?SessionContextStore $sessionStore = null,
        ?DeterministicDecisionEngine $decisionEngine = null,
        ?FeasibleDayPlanEngine $planEngine = null,
        ?PlannerEventLogger $eventLogger = null,
        ?DBSpotsClient $spotsClient = null,
        ?AssistantResponseValidator $responseValidator = null
    ) {
        $this->repository   = $repository ?? new PlanRepository();
        $this->pricing      = $pricing ?? new PriceEngine();
        $this->availability = $availability ?? new AvailabilityService();
        $this->ai           = $ai ?? new AiSuggestionService();
        $this->activities   = $activities ?? new ActivityService();
        $this->catalog      = $catalog ?? new ProductCatalogService($this->activities);
        $this->sessionStore = $sessionStore ?? new SessionContextStore();
        $this->decisionEngine = $decisionEngine ?? new DeterministicDecisionEngine();
        $this->planEngine = $planEngine ?? new FeasibleDayPlanEngine();
        $this->eventLogger = $eventLogger ?? new PlannerEventLogger();
        $this->spotsClient = $spotsClient ?? new DBSpotsClient();
        $this->responseValidator = $responseValidator ?? new AssistantResponseValidator();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createPlan(array $payload, int $ownerId): array
    {
        $plan = $this->normalisePlan($payload);
        $plan = $this->ensureEditToken($plan);

        return $this->enrichPlan(
            $this->repository->create($plan, $ownerId)
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updatePlan(int $planId, array $payload): array
    {
        $plan = $this->normalisePlan($payload);
        $plan = $this->ensureEditToken($plan, $planId);

        return $this->enrichPlan(
            $this->repository->update($planId, $plan)
        );
    }

    public function getPlan(int $planId): array
    {
        return $this->enrichPlan($this->repository->get($planId));
    }

    /**
     * Retrieve the raw persisted plan payload without additional calculations.
     *
     * @return array<string, mixed>
     */
    public function getPlanMeta(int $planId): array
    {
        return $this->repository->get($planId);
    }

    /**
     * Retrieve planner configuration settings.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        if (class_exists('\SBDP\Modules\Planner\Services\PlannerService')) {
            $legacy = new \SBDP\Modules\Planner\Services\PlannerService();

            return $legacy->getPlannerConfig();
        }

        $stored = get_option('sbdp_day_planner_settings', array());

        $settings = self::sanitizeSettings($stored);

        try {
            $schema = new BookingBoardSchema();
            $settings['booking_board_schema'] = $schema->export();
            $settings['booking_board_status_labels'] = $schema->statusLabelMap();
            $settings['booking_board_snapshot'] = (new BookingBoardQuery())->get_board_snapshot();
        } catch (\Throwable $exception) {
            unset($exception);
            $settings['booking_board_schema'] = [
                'lanes'    => [],
                'statuses' => [],
                'filters'  => [],
            ];
            $settings['booking_board_status_labels'] = [];
            $settings['booking_board_snapshot'] = [];
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function listActivities(array $filters = []): array
    {
        return $this->activities->listActivities($filters);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function listProducts(array $filters = []): array
    {
        return $this->catalog->listProducts($filters);
    }

    public function sharePlan(int $planId): array
    {
        $plan = $this->repository->get($planId);
        $shareKey = substr(
            wp_hash((string) $plan['id'] . microtime(true)),
            0,
            12
        );

        $plan['shared_key'] = $shareKey;
        $plan['shared_at']  = gmdate('c');

        $updated = $this->repository->update($planId, $plan);

        return [
            'shared_key'  => $shareKey,
            'share_url'   => add_query_arg(
                [
                    'planner_plan' => $planId,
                    'key'          => $shareKey,
                ],
                home_url('/')
            ),
            'plan'        => $this->enrichPlan($updated),
        ];
    }

    public function queueBooking(int $planId): array
    {
        $plan = $this->enrichPlan($this->repository->get($planId));

        $items = $this->extractCartItems($plan);
        if ($items === array()) {
            throw new RuntimeException(__('Geen boekbare activiteiten in dit plan gevonden.', 'sbdp'));
        }

        $this->assertDirectCheckoutEligible($items);

        if (! function_exists('WC')) {
            throw new RuntimeException(__('WooCommerce niet beschikbaar.', 'sbdp'));
        }

        $this->ensureCartSession();
        if (! \WC()->cart) {
            throw new RuntimeException(__('Winkelwagen kon niet worden geopend.', 'sbdp'));
        }

        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }

        \WC()->cart->empty_cart();

        $added = 0;
        foreach ($items as $item) {
            $product = \wc_get_product($item['product_id']);
            if (! $product) {
                throw new RuntimeException(__('Een geselecteerde activiteit is niet meer beschikbaar.', 'sbdp'));
            }

            $this->assertItemAvailability($item);
            $itemCapability = $this->resolveItemBookingCapabilityProfile($item);

            $quantity = max(1, (int) $item['participants']);
            $planItemOptions = isset($item['plan_item']['options']) && is_array($item['plan_item']['options'])
                ? $item['plan_item']['options']
                : array();
            $combiItems = SelectionPricing::normaliseCombiItems($planItemOptions['combiItems'] ?? []);
            // CSOT guard: validate combi IDs against the product's registered _sbdp_combi_deals.
            if ($combiItems !== [] && class_exists('\SBDP_Planner_Domain_Service')) {
                $combiItems = \SBDP_Planner_Domain_Service::validate_combi_items($item['product_id'], $combiItems);
            }
            $pricing = SelectionPricing::quote(
                $item['product_id'],
                $quantity,
                (string) $item['start'],
                (int) $item['resource_id'],
                $combiItems,
                [
                    'channel' => 'planner_checkout',
                    'source'  => 'day_planner_cart',
                    'plan_id' => $planId,
                    'date'    => $item['date'],
                ]
            );

            // Centralized PricingService should be the only source for unit prices
            $unitPrice = isset($pricing['display_unit_price'])
                ? (float) $pricing['display_unit_price']
                : (isset($pricing['display_per_person'])
                    ? (float) $pricing['display_per_person']
                    : (isset($pricing['unit_price']) ? (float) $pricing['unit_price'] : 0.0));
            $pricingSource = 'selection_pricing_quote';
            if ($unitPrice <= 0) {
                // Calculation fallback is now inside PricingService/RestService
                $unitPrice = function_exists('wc_get_price_including_tax')
                    ? (float) wc_get_price_including_tax($product, array('qty' => 1))
                    : (float) $product->get_price();
                $pricingSource = 'woocommerce_taxed_fallback';
            }

            $cartItemData = array(
                'sbdp_meta' => array(
                    'sbdp_plan_id'       => $planId,
                    'sbdp_plan_day'      => $item['day_index'],
                    'sbdp_plan_slot'     => $item['slot_index'],
                    'sbdp_plan_date'     => $item['date'],
                    'sbdp_start'         => $item['start'],
                    'sbdp_end'           => $item['end'],
                    'sbdp_canonical_participants' => $quantity,
                    'sbdp_participants'  => $quantity,
                    'sbdp_resource_id'   => $item['resource_id'],
                    'sbdp_resource_label'=> $this->getResourceLabel($item['resource_id']),
                    'sbdp_route_intent'  => (string) ($itemCapability['route_intent'] ?? self::ROUTE_INTENT_BLOCKED),
                    'sbdp_booking_capability' => (string) ($itemCapability['status'] ?? self::CAPABILITY_STATUS_UNAVAILABLE),
                    'sbdp_pricing_source'=> $pricingSource,
                ),
            );

            $summaryTime = $this->extractTimeComponent((string) $item['start']);
            $summaryEnd = $this->extractTimeComponent((string) $item['end']);

            if (class_exists('\SBDP_Planner_Domain_Service')) {
                $plannerInput = isset($item['plan_item']['plannerInput']) && is_array($item['plan_item']['plannerInput'])
                    ? $item['plan_item']['plannerInput']
                    : \SBDP_Planner_Domain_Service::normalize_input([
                        'product_id'   => $item['product_id'],
                        'date'         => $item['date'],
                        'time'         => substr((string) ($item['start'] ?? ''), 11, 5),
                        'participants' => $quantity,
                        'resource_id'  => $item['resource_id'],
                        'source'       => 'day-planner-booking',
                    ]);
                $planItem = isset($item['plan_item']) && is_array($item['plan_item'])
                    ? $item['plan_item']
                    : null;
                if (! is_array($planItem) || $planItem === array()) {
                    $evaluation = \SBDP_Planner_Domain_Service::evaluate_input($plannerInput);
                    $planItem = isset($evaluation['planItem']) && is_array($evaluation['planItem'])
                        ? $evaluation['planItem']
                        : null;
                }
                if (is_array($planItem) && $planItem !== array()) {
                    $planItem['status'] = 'in-cart';
                    $planItem['dayIndex'] = $item['day_index'];
                    $cartItemData = array_merge(
                        $cartItemData,
                        \SBDP_Planner_Domain_Service::build_cart_payload_from_plan_item($planItem, $plannerInput)
                    );
                }
            }

            $cartItemData['sbdp_summary'] = array(
                'date'         => $item['date'],
                'time'         => $summaryTime,
                'participants' => $quantity,
                'resource_id'  => (int) $item['resource_id'],
                'start'        => (string) $item['start'],
                'end'          => $summaryEnd,
                'pricing'      => $pricing,
                'route_intent' => (string) ($itemCapability['route_intent'] ?? self::ROUTE_INTENT_BLOCKED),
                'booking_capability' => (string) ($itemCapability['status'] ?? self::CAPABILITY_STATUS_UNAVAILABLE),
                'combi_multi'  => $combiItems,
            );
            $cartItemData['sbdp_pricing'] = $pricing;
            $cartItemData['sbdp_date'] = $item['date'];
            $cartItemData['sbdp_time'] = $summaryTime;
            $cartItemData['sbdp_canonical_participants'] = $quantity;
            $cartItemData['sbdp_participants'] = $quantity;
            $cartItemData['sbdp_quantity'] = $quantity;
            $cartItemData['sbdp_start'] = (string) $item['start'];
            $cartItemData['sbdp_end'] = (string) $item['end'];
            $cartItemData['sbdp_resource_id'] = (int) $item['resource_id'];
            $cartItemData['sbdp_route_intent'] = (string) ($itemCapability['route_intent'] ?? self::ROUTE_INTENT_BLOCKED);
            $cartItemData['sbdp_booking_capability'] = (string) ($itemCapability['status'] ?? self::CAPABILITY_STATUS_UNAVAILABLE);
            $cartItemData['sbdp_pricing_source'] = $pricingSource;

            $cartKey = \WC()->cart->add_to_cart(
                $item['product_id'],
                $quantity,
                0,
                array(),
                $cartItemData
            );

            if (! $cartKey) {
                $message = $this->firstCartErrorNotice();
                \WC()->cart->empty_cart();
                throw new RuntimeException(
                    $message !== '' ? $message : __('Kon geen items aan de winkelwagen toevoegen.', 'sbdp')
                );
            }

            $cartItem = \WC()->cart->cart_contents[ $cartKey ] ?? null;
            if (is_array($cartItem)) {
                if (isset($cartItem['data']) && $cartItem['data'] instanceof \WC_Product) {
                    $cartItem['data']->set_price($unitPrice);
                }
                $cartItem['sbdp_pricing'] = $pricing;
                if (isset($cartItem['sbdp_summary']) && is_array($cartItem['sbdp_summary'])) {
                    $cartItem['sbdp_summary']['pricing'] = $pricing;
                    $cartItem['sbdp_summary']['participants'] = $quantity;
                    $cartItem['sbdp_summary']['combi_multi'] = $combiItems;
                }
                \WC()->cart->cart_contents[ $cartKey ] = $cartItem;
            }

            $added++;
        }

        if ($added === 0) {
            \WC()->cart->empty_cart();
            throw new RuntimeException(__('Geen activiteiten toegevoegd aan de winkelwagen.', 'sbdp'));
        }

        $participantsForSnapshot = $this->resolveSnapshotParticipants($items);

        if (function_exists('WC') && \WC()->session) {
            \WC()->session->set('sbdp_mode', 'planner');
            \WC()->session->set('sbdp_itinerary', $this->snapshotItinerary($items, $participantsForSnapshot));
            \WC()->session->set('sbdp_plan_id', $planId);
        }

        if (\WC()->cart) {
            \WC()->cart->calculate_totals();
            if (method_exists(\WC()->cart, 'set_session')) {
                \WC()->cart->set_session();
            }
            if (method_exists(\WC()->cart, 'maybe_set_cart_cookies')) {
                \WC()->cart->maybe_set_cart_cookies();
            }
        }

        $cartUrl = function_exists('wc_get_cart_url') ? \wc_get_cart_url() : '';
        $checkoutUrl = $this->hasAvailablePaymentGateways() && function_exists('wc_get_checkout_url')
            ? \wc_get_checkout_url()
            : '';

        return array(
            'plan_id'      => $planId,
            'status'       => 'cart_updated',
            'items_added'  => $added,
            'redirect_url' => $cartUrl !== '' ? $cartUrl : $checkoutUrl,
            'cart_url'     => $cartUrl,
            'checkout_url' => $checkoutUrl,
            'message'      => __('Plan toegevoegd aan de winkelwagen.', 'sbdp'),
        );
    }

    public function requestQuote(int $planId): array
    {
        $plan = $this->enrichPlan($this->repository->get($planId));
        $quoteUrl = add_query_arg(
            [
                'planner_plan' => $planId,
            ],
            home_url('/offerte/')
        );

        $editToken = is_array($plan['meta'] ?? null) && isset($plan['meta']['edit_token']) && is_string($plan['meta']['edit_token'])
            ? trim($plan['meta']['edit_token'])
            : '';
        if ($editToken !== '') {
            $quoteUrl = add_query_arg(
                [
                    'edit_token' => $editToken,
                ],
                $quoteUrl
            );
        }

        return [
            'plan_id' => $planId,
            'status'  => 'quote_intake_ready',
            'plan'    => $plan,
            'quote_url' => $quoteUrl,
            'message' => __('Offerte-intake geopend. Definitieve beschikbaarheid en prijs blijven onderdeel van de bevestigde booking-flow.', 'sbdp'),
        ];
    }

    public function scheduleExport(int $planId, string $type): array
    {
        return [
            'plan_id' => $planId,
            'status'  => 'scheduled',
            'type'    => $type,
        ];
    }

    private function hasAvailablePaymentGateways(): bool
    {
        if (! function_exists('WC')) {
            return false;
        }

        try {
            $paymentGateways = \WC()->payment_gateways();
            if (! $paymentGateways || ! method_exists($paymentGateways, 'get_available_payment_gateways')) {
                return false;
            }

            $availableGateways = $paymentGateways->get_available_payment_gateways();
            return is_array($availableGateways) && $availableGateways !== array();
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $plan
     *
     * @return array<string, mixed>
     */
    private function enrichPlan(array $plan): array
    {
        $pricing = $this->pricing->calculateTotals($plan);
        $plan['totals']    = $pricing['summary'] ?? [];
        $plan['conflicts'] = $this->availability->detectConflicts($plan['days'] ?? []);
        $capabilityItems = $this->extractCartItems($plan);
        $planCapability = $this->resolvePlanBookingCapability($capabilityItems);
        $plan['booking_capability'] = $planCapability;
        $plan['planCheckoutCapability'] = array(
            'status'            => $planCapability['legacy_status'],
            'normalized_status' => $planCapability['status'],
            'route_intent'      => $planCapability['route_intent'],
            'reason_code'       => $planCapability['reason_code'],
        );

        if (! empty($pricing['planner_items']) && is_array($pricing['planner_items'])) {
            $plan['meta'] = isset($plan['meta']) && is_array($plan['meta']) ? $plan['meta'] : [];
            $plan['meta']['planner_items'] = $pricing['planner_items'];
        }

        if (! empty($pricing['slots']) && isset($plan['days'])) {
            foreach ($plan['days'] as $dayIndex => &$day) {
                if (! isset($day['slots']) || ! is_array($day['slots'])) {
                    continue;
                }

                foreach ($day['slots'] as $slotIndex => &$slot) {
                    if (isset($pricing['slots'][$dayIndex][$slotIndex])) {
                        $slot['pricing'] = $pricing['slots'][$dayIndex][$slotIndex];
                    }
                }
                unset($slot);
            }
            unset($day);
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $plan
     *
     * @return array<string, mixed>
     */
    private function normalisePlan(array $plan): array
    {
        $title = isset($plan['title']) ? (string) $plan['title'] : '';
        if ($title === '') {
            $plan['title'] = __('Nieuwe dagplanning', 'sbdp');
        }

        $plan['days'] = array_values(
            array_map(
                function ($day): array {
                    if (! is_array($day)) {
                        return [
                            'date'  => '',
                            'slots' => [],
                        ];
                    }

                    $day['date']  = isset($day['date']) ? (string) $day['date'] : '';
                    $day['slots'] = array_values(
                        array_map(
                            function ($slot): array {
                                if (! is_array($slot)) {
                                    return [];
                                }

                                $slot['start'] = isset($slot['start']) ? (string) $slot['start'] : '';
                                $slot['end']   = isset($slot['end']) ? (string) $slot['end'] : '';
                                $slot['people'] = isset($slot['people']) ? max(1, (int) $slot['people']) : 0;
                                $slot['product_id'] = isset($slot['product_id'])
                                    ? (int) $slot['product_id']
                                    : (isset($slot['activity_id']) ? (int) $slot['activity_id'] : 0);
                                $slot['resource_id'] = isset($slot['resource_id']) ? (int) $slot['resource_id'] : 0;
                                $slot['currency'] = isset($slot['currency']) ? (string) $slot['currency'] : 'EUR';
                                $slot['duration_minutes'] = isset($slot['duration_minutes'])
                                    ? max(1, (int) $slot['duration_minutes'])
                                    : null;

                                $slot = $this->normaliseSlotTiming($slot);

                                return $slot;
                            },
                            $day['slots'] ?? []
                        )
                    );

                    return $day;
                },
                $plan['days'] ?? []
            )
        );

        $plan['participants'] = array_values(
            array_filter(
                array_map(
                    static function ($participant): ?array {
                        if (! is_array($participant)) {
                            return null;
                        }

                        $name = trim((string) ($participant['name'] ?? ''));
                        $email = trim((string) ($participant['email'] ?? ''));

                        if ($name === '' && $email === '') {
                            return null;
                        }

                        return [
                            'name'  => $name,
                            'email' => $email,
                            'role'  => isset($participant['role']) ? (string) $participant['role'] : 'guest',
                        ];
                    },
                    $plan['participants'] ?? []
                )
            )
        );

        $plan['meta'] = $this->normalisePlanMeta(isset($plan['meta']) && is_array($plan['meta']) ? $plan['meta'] : []);

        return $plan;
    }

    /**
     * @param array<string, mixed> $slot
     *
     * @return array<string, mixed>
     */
    private function normaliseSlotTiming(array $slot): array
    {
        if (isset($slot['pricing'])) {
            unset($slot['pricing']);
        }

        $start = $this->extractTimeComponent($slot['start'] ?? '');
        $end   = $this->extractTimeComponent($slot['end'] ?? '');

        $slot['start'] = $start;
        $slot['end']   = $end;

        $duration = isset($slot['duration_minutes']) ? (int) $slot['duration_minutes'] : null;

        if ($start !== '' && $end !== '') {
            $calculated = $this->calculateDurationMinutes($start, $end);
            if ($calculated !== null) {
                $slot['duration_minutes'] = $calculated;
            }
        } elseif ($start !== '' && $duration !== null) {
            $slot['end'] = $this->adjustTimeByMinutes($start, $duration);
        } elseif ($end !== '' && $duration !== null) {
            $slot['start'] = $this->adjustTimeByMinutes($end, -$duration);
        }

        if (! isset($slot['duration_minutes']) || $slot['duration_minutes'] <= 0) {
            if ($start !== '' && $end !== '') {
                $calculated = $this->calculateDurationMinutes($start, $end);
                if ($calculated !== null && $calculated > 0) {
                    $slot['duration_minutes'] = $calculated;
                } else {
                    unset($slot['duration_minutes']);
                }
            } elseif ($duration !== null && $duration > 0) {
                $slot['duration_minutes'] = $duration;
            } else {
                unset($slot['duration_minutes']);
            }
        }

        return $slot;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function normalisePlanMeta(array $meta): array
    {
        $normalised = array();

        if (isset($meta['form']) && is_array($meta['form'])) {
            $normalised['form'] = array();
            if (isset($meta['form']['participants'])) {
                $normalised['form']['participants'] = max(1, (int) $meta['form']['participants']);
            }
            if ($normalised['form'] === array()) {
                unset($normalised['form']);
            }
        }

        if (isset($meta['participant_count'])) {
            $normalised['participant_count'] = max(1, (int) $meta['participant_count']);
        }

        if (! isset($normalised['participant_count']) && isset($normalised['form']['participants'])) {
            $normalised['participant_count'] = (int) $normalised['form']['participants'];
        }

        foreach (array('edit_token', 'shared_key', 'shared_at') as $key) {
            if (isset($meta[$key]) && is_string($meta[$key]) && trim($meta[$key]) !== '') {
                $normalised[$key] = sanitize_text_field($meta[$key]);
            }
        }

        if (isset($meta['planner_items']) && is_array($meta['planner_items'])) {
            $normalised['planner_items'] = array_values(
                array_filter(
                    array_map([$this, 'normalisePlannerItemMeta'], $meta['planner_items'])
                )
            );
        }

        return $normalised;
    }

    /**
     * @param mixed $item
     * @return array<string, mixed>|null
     */
    private function normalisePlannerItemMeta($item): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $productId = isset($item['productId'])
            ? (int) $item['productId']
            : (isset($item['product_id']) ? (int) $item['product_id'] : 0);
        if ($productId <= 0) {
            return null;
        }

        $resourceId = isset($item['resourceId'])
            ? (int) $item['resourceId']
            : (isset($item['resource_id']) ? (int) $item['resource_id'] : 0);
        $dayIndex = isset($item['dayIndex']) ? max(0, (int) $item['dayIndex']) : 0;
        $participants = isset($item['participants']) ? max(1, (int) $item['participants']) : 0;
        $date = isset($item['date']) ? trim((string) $item['date']) : '';
        $startTime = $this->extractTimeComponent((string) ($item['startTime'] ?? ''));
        $endTime = $this->extractTimeComponent((string) ($item['endTime'] ?? ''));
        $durationMinutes = null;
        if (isset($item['durationMinutes'])) {
            $candidateDuration = (int) $item['durationMinutes'];
            if ($candidateDuration > 0) {
                $durationMinutes = $candidateDuration;
            }
        }
        if (($durationMinutes === null || $durationMinutes <= 0) && $startTime !== '' && $endTime !== '') {
            $calculated = $this->calculateDurationMinutes($startTime, $endTime);
            if ($calculated !== null && $calculated > 0) {
                $durationMinutes = $calculated;
            }
        }
        if ($durationMinutes === null && isset($item['bookingResolution']) && is_array($item['bookingResolution'])) {
            $segments = isset($item['bookingResolution']['segments']) && is_array($item['bookingResolution']['segments'])
                ? $item['bookingResolution']['segments']
                : array();
            foreach ($segments as $segment) {
                if (! is_array($segment) || ($segment['role'] ?? '') !== 'anchor') {
                    continue;
                }
                if (isset($segment['duration_minutes']) && (int) $segment['duration_minutes'] > 0) {
                    $durationMinutes = (int) $segment['duration_minutes'];
                    break;
                }
                if (isset($segment['startMinutes'], $segment['endMinutes'])) {
                    $calculated = (int) $segment['endMinutes'] - (int) $segment['startMinutes'];
                    if ($calculated > 0) {
                        $durationMinutes = $calculated;
                        break;
                    }
                }
            }
        }
        $combiItems = SelectionPricing::normaliseCombiItems(
            isset($item['options']) && is_array($item['options']) ? ($item['options']['combiItems'] ?? []) : []
        );

        $normalised = $item;
        $normalised['productId'] = $productId;
        $normalised['product_id'] = $productId;
        $normalised['resourceId'] = $resourceId;
        $normalised['resource_id'] = $resourceId;
        $normalised['dayIndex'] = $dayIndex;
        $normalised['participants'] = $participants;
        $normalised['date'] = $date;
        $normalised['startTime'] = $startTime;
        $normalised['endTime'] = $endTime;
        $normalised['durationMinutes'] = $durationMinutes;
        $resolvedTitle = $this->resolvePlannerItemTitle($normalised, $productId);
        if ($resolvedTitle !== '') {
            $normalised['title'] = $resolvedTitle;
        }
        $normalised['options'] = isset($normalised['options']) && is_array($normalised['options'])
            ? $normalised['options']
            : array();
        $normalised['options']['combiItems'] = $combiItems;
        if (isset($normalised['groupId']) && is_string($normalised['groupId'])) {
            $normalised['groupId'] = sanitize_text_field($normalised['groupId']);
        }
        if (isset($normalised['aggregateId']) && is_string($normalised['aggregateId'])) {
            $normalised['aggregateId'] = sanitize_text_field($normalised['aggregateId']);
        }
        if (isset($normalised['bookingResolution']) && is_array($normalised['bookingResolution'])) {
            $normalised['bookingResolution'] = $normalised['bookingResolution'];
            if (! isset($normalised['groupId']) && isset($normalised['bookingResolution']['groupId']) && is_string($normalised['bookingResolution']['groupId'])) {
                $normalised['groupId'] = sanitize_text_field($normalised['bookingResolution']['groupId']);
            }
            if (! isset($normalised['aggregateId']) && isset($normalised['bookingResolution']['aggregateId']) && is_string($normalised['bookingResolution']['aggregateId'])) {
                $normalised['aggregateId'] = sanitize_text_field($normalised['bookingResolution']['aggregateId']);
            }
            if (isset($normalised['bookingResolution']['status']) && is_string($normalised['bookingResolution']['status'])) {
                $resolutionStatus = strtolower(trim($normalised['bookingResolution']['status']));
                if ($resolutionStatus !== '') {
                    $normalised['status'] = $resolutionStatus;
                }
            }
        }
        $hasStructuredSegments = false;
        if (isset($normalised['bookingResolution']['segments']) && is_array($normalised['bookingResolution']['segments'])) {
            foreach ($normalised['bookingResolution']['segments'] as $segment) {
                if (! is_array($segment)) {
                    continue;
                }
                if (isset($segment['role']) && is_string($segment['role'])) {
                    $segmentRole = strtolower(trim($segment['role']));
                    if ($segmentRole === 'pre' || $segmentRole === 'post') {
                        $hasStructuredSegments = true;
                        break;
                    }
                }
            }
        }
        if (isset($normalised['role']) && is_string($normalised['role'])) {
            $role = strtolower(trim($normalised['role']));
            if (in_array($role, array('pre', 'post', 'anchor'), true)) {
                $normalised['role'] = $role;
            }
        }
        if (isset($normalised['type']) && is_string($normalised['type'])) {
            $type = strtolower(trim($normalised['type']));
            if (in_array($type, array('arrangement', 'arrangement-part', 'single'), true)) {
                $normalised['type'] = $type;
            }
        }
        $normalised['isArrangement'] = ! empty($normalised['isArrangement'])
            || ! empty($normalised['groupId'])
            || ! empty($normalised['role'])
            || (isset($normalised['type']) && in_array((string) $normalised['type'], array('arrangement', 'arrangement-part'), true))
            || $combiItems !== array()
            || $hasStructuredSegments;

        if (isset($normalised['plannerInput']) && is_array($normalised['plannerInput'])) {
            $normalised['plannerInput'] = array(
                'productId'   => $productId,
                'product_id'  => $productId,
                'participants'=> $participants,
                'people'      => $participants,
                'date'        => $date,
                'time'        => $startTime,
                'resourceId'  => $resourceId,
                'resource_id' => $resourceId,
                'source'      => isset($normalised['plannerInput']['source']) ? sanitize_text_field((string) $normalised['plannerInput']['source']) : 'day-planner',
                'options'     => array(
                    'combiItems' => $combiItems,
                ),
            );
        }

        if (isset($normalised['aggregate']) && is_array($normalised['aggregate'])) {
            if (isset($normalised['aggregate']['pricing'])) {
                unset($normalised['aggregate']['pricing']);
            }
        }

        unset(
            $normalised['pricing'],
            $normalised['price_pp'],
            $normalised['fixedCost'],
            $normalised['totalCost'],
            $normalised['pricing_source'],
            $normalised['pricingSource'],
            $normalised['serverQuoted'],
            $normalised['combiItems']
        );

        return $normalised;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolvePlannerItemTitle(array $item, int $productId): string
    {
        $candidates = array(
            $item['title'] ?? null,
            $item['product_name'] ?? null,
            $item['name'] ?? null,
            isset($item['product']) && is_array($item['product']) ? ($item['product']['name'] ?? null) : null,
            isset($item['bookingResolution']) && is_array($item['bookingResolution'])
                ? ($item['bookingResolution']['source_title'] ?? (($item['bookingResolution']['summary']['title'] ?? null)))
                : null,
            isset($item['aggregate']) && is_array($item['aggregate']) ? ($item['aggregate']['title'] ?? null) : null,
            $productId > 0 ? \get_the_title($productId) : null,
        );

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $title = trim($candidate);
            if ($title !== '') {
                return $title;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<int, array<string, mixed>>
     */
    private function extractCartItems(array $plan): array
    {
        $items      = array();
        $plannerItems = isset($plan['meta']['planner_items']) && is_array($plan['meta']['planner_items'])
            ? $plan['meta']['planner_items']
            : array();
        $days       = isset($plan['days']) && is_array($plan['days']) ? $plan['days'] : array();
        $planParticipants = $this->resolveCanonicalParticipantsContext($plan);
        $fallback   = (int) ($planParticipants['participants'] ?? 0);

        if ($plannerItems !== array()) {
            foreach ($plannerItems as $index => $plannerItem) {
                if (! is_array($plannerItem)) {
                    continue;
                }

                if (
                    isset($plannerItem['bookingResolution']) &&
                    is_array($plannerItem['bookingResolution']) &&
                    isset($plannerItem['bookingResolution']['status']) &&
                    is_string($plannerItem['bookingResolution']['status'])
                ) {
                    $resolutionStatus = strtolower(trim($plannerItem['bookingResolution']['status']));
                    if ($resolutionStatus !== '' && ! in_array($resolutionStatus, array('valid', 'confirmed'), true)) {
                        continue;
                    }
                }

                $productId = isset($plannerItem['productId'])
                    ? (int) $plannerItem['productId']
                    : (isset($plannerItem['product_id']) ? (int) $plannerItem['product_id'] : 0);
                if ($productId <= 0) {
                    continue;
                }

                $aggregate = isset($plannerItem['aggregate']) && is_array($plannerItem['aggregate'])
                    ? $plannerItem['aggregate']
                    : array();
                $timeline = isset($aggregate['timeline']) && is_array($aggregate['timeline'])
                    ? $aggregate['timeline']
                    : array();
                if (isset($aggregate['pricing'])) {
                    unset($aggregate['pricing']);
                }
                $participantsContext = $this->resolveCanonicalParticipantsContext($plan, $plannerItem);
                $participants = (int) ($participantsContext['participants'] ?? $fallback);

                $dayIndex = isset($plannerItem['dayIndex']) ? (int) $plannerItem['dayIndex'] : 0;
                $date = isset($plannerItem['date']) ? (string) $plannerItem['date'] : '';
                if ($date === '' && isset($days[$dayIndex]['date'])) {
                    $date = (string) $days[$dayIndex]['date'];
                }

                $start = $this->composeStartIso((string) ($plannerItem['startTime'] ?? ''), $date);
                $end = $this->composeStartIso((string) ($plannerItem['endTime'] ?? ''), $date);
                if ($start === '' && isset($timeline['start'])) {
                    $start = (string) $timeline['start'];
                }
                if ($end === '' && isset($timeline['end'])) {
                    $end = (string) $timeline['end'];
                }

                $resourceId = isset($plannerItem['resourceId'])
                    ? (int) $plannerItem['resourceId']
                    : (isset($plannerItem['resource_id']) ? (int) $plannerItem['resource_id'] : 0);

                $canonicalSlot = $this->resolveCanonicalDaySlot($days, $dayIndex, $index, $productId);
                if ($canonicalSlot !== null) {
                    $date = (string) ($canonicalSlot['date'] ?? $date);
                    $slotStart = (string) ($canonicalSlot['start'] ?? '');
                    $slotEnd = (string) ($canonicalSlot['end'] ?? '');
                    $slotResourceId = isset($canonicalSlot['resource_id']) ? (int) $canonicalSlot['resource_id'] : 0;

                    $canonicalStart = $this->composeStartIso($slotStart, $date);
                    $canonicalEnd = $this->composeStartIso($slotEnd, $date);

                    if ($canonicalStart !== '') {
                        $start = $canonicalStart;
                    }
                    if ($canonicalEnd !== '') {
                        $end = $canonicalEnd;
                    }
                    if ($slotResourceId > 0 || $resourceId <= 0) {
                        $resourceId = $slotResourceId;
                    }

                    $plannerItem['date'] = $date;
                    $plannerItem['participants'] = $participants;
                    $plannerItem['resourceId'] = $resourceId;
                    $plannerItem['resource_id'] = $resourceId;
                    $plannerItem['startTime'] = $slotStart !== '' ? $slotStart : ($plannerItem['startTime'] ?? '');
                    $plannerItem['endTime'] = $slotEnd !== '' ? $slotEnd : ($plannerItem['endTime'] ?? '');
                    $plannerItem['start'] = $start;
                    $plannerItem['end'] = $end;
                }

                $pricing = isset($plannerItem['pricing']) && is_array($plannerItem['pricing'])
                    ? $plannerItem['pricing']
                    : array();
                $combiItems = SelectionPricing::normaliseCombiItems(
                    isset($plannerItem['options']) && is_array($plannerItem['options'])
                        ? ($plannerItem['options']['combiItems'] ?? array())
                        : (isset($plannerItem['combiItems']) && is_array($plannerItem['combiItems'])
                            ? $plannerItem['combiItems']
                            : array())
                );
                $groupId = isset($plannerItem['groupId']) && is_string($plannerItem['groupId']) && trim($plannerItem['groupId']) !== ''
                    ? sanitize_text_field((string) $plannerItem['groupId'])
                    : '';
                $role = isset($plannerItem['role']) && is_string($plannerItem['role']) && trim($plannerItem['role']) !== ''
                    ? strtolower(trim((string) $plannerItem['role']))
                    : '';
                $type = isset($plannerItem['type']) && is_string($plannerItem['type']) && trim($plannerItem['type']) !== ''
                    ? strtolower(trim((string) $plannerItem['type']))
                    : '';
                $hasArrangementMeta = $groupId !== ''
                    || $role !== ''
                    || ! empty($plannerItem['isArrangement'])
                    || in_array($type, array('arrangement', 'arrangement-part'), true)
                    || $combiItems !== array();
                $generatedGroupId = $groupId !== ''
                    ? $groupId
                    : ($hasArrangementMeta ? sprintf('grp-%d-%d-%d', $productId, $dayIndex, $index) : '');

                $items[] = array(
                    'product_id'    => $productId,
                    'resource_id'   => $resourceId,
                    'start'         => $start,
                    'end'           => $end,
                    'participants'  => $participants,
                    'pricing'       => $pricing,
                    'day_index'     => $dayIndex,
                    'slot_index'    => (int) $index,
                    'date'          => $date,
                    'plan_item'     => $plannerItem,
                    'aggregate'     => $aggregate,
                );

                if ($hasArrangementMeta) {
                    $lastIndex = array_key_last($items);
                    if ($lastIndex !== null) {
                        $items[$lastIndex]['plan_item']['groupId'] = $generatedGroupId;
                        $items[$lastIndex]['plan_item']['role'] = $role !== '' ? $role : 'anchor';
                        $items[$lastIndex]['plan_item']['type'] = $type !== '' ? $type : 'arrangement';
                        $items[$lastIndex]['plan_item']['isArrangement'] = true;
                        $items[$lastIndex]['plan_item']['options'] = isset($items[$lastIndex]['plan_item']['options']) && is_array($items[$lastIndex]['plan_item']['options'])
                            ? $items[$lastIndex]['plan_item']['options']
                            : array();
                        $items[$lastIndex]['plan_item']['options']['combiItems'] = $combiItems;
                        $items[$lastIndex]['plan_item']['combiItems'] = $combiItems;
                    }
                }
            }

            if ($items !== array()) {
                return $items;
            }
        }

        foreach ($days as $dayIndex => $day) {
            if (! is_array($day)) {
                continue;
            }

            $date  = isset($day['date']) ? trim((string) $day['date']) : '';
            $slots = isset($day['slots']) && is_array($day['slots']) ? $day['slots'] : array();

            foreach ($slots as $slotIndex => $slot) {
                if (! is_array($slot)) {
                    continue;
                }

                $productId = isset($slot['product_id'])
                    ? (int) $slot['product_id']
                    : (isset($slot['activity_id']) ? (int) $slot['activity_id'] : 0);

                if ($productId <= 0) {
                    continue;
                }

                $startRaw = isset($slot['start']) ? (string) $slot['start'] : '';
                $endRaw   = isset($slot['end']) ? (string) $slot['end'] : '';
                $startIso = $this->composeStartIso($startRaw, $date);
                $endIso   = $this->composeStartIso($endRaw, $date);

                $participantsContext = $this->resolveCanonicalParticipantsContext(
                    $plan,
                    array(),
                    array(
                        'participants' => $slot['participants'] ?? ($slot['people'] ?? null),
                    )
                );
                $participants = (int) ($participantsContext['participants'] ?? $fallback);

                $items[] = array(
                    'product_id'   => $productId,
                    'resource_id'  => isset($slot['resource_id']) ? (int) $slot['resource_id'] : 0,
                    'start'        => $startIso !== '' ? $startIso : $startRaw,
                    'end'          => $endIso !== '' ? $endIso : $endRaw,
                    'participants' => $participants,
                    'pricing'      => isset($slot['pricing']) && is_array($slot['pricing']) ? $slot['pricing'] : array(),
                    'day_index'    => (int) $dayIndex,
                    'slot_index'   => (int) $slotIndex,
                    'date'         => $date,
                );
            }
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $days
     * @return array<string, mixed>|null
     */
    private function resolveCanonicalDaySlot(array $days, int $dayIndex, int $slotIndex, int $productId): ?array
    {
        if (! isset($days[$dayIndex]) || ! is_array($days[$dayIndex])) {
            return null;
        }

        $day = $days[$dayIndex];
        $date = isset($day['date']) ? trim((string) $day['date']) : '';
        $slots = isset($day['slots']) && is_array($day['slots']) ? array_values($day['slots']) : array();
        if ($slots === array()) {
            return null;
        }

        if (isset($slots[$slotIndex]) && is_array($slots[$slotIndex])) {
            $slotProductId = isset($slots[$slotIndex]['product_id']) ? (int) $slots[$slotIndex]['product_id'] : 0;
            if ($slotProductId === $productId) {
                $slot = $slots[$slotIndex];
                $slot['date'] = $date;
                return $slot;
            }
        }

        foreach ($slots as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $slotProductId = isset($slot['product_id']) ? (int) $slot['product_id'] : 0;
            if ($slotProductId !== $productId) {
                continue;
            }

            $slot['date'] = $date;
            return $slot;
        }

        return null;
    }

    /**
     * Ensure the selected slot is still available for the product/resource/date.
     *
     * @param array<string, mixed> $item
     */
    private function assertItemAvailability(array $item): void
    {
        $productId = (int) ($item['product_id'] ?? 0);
        $resourceId = (int) ($item['resource_id'] ?? 0);
        $participants = (int) ($item['participants'] ?? 0);
        $date = (string) ($item['date'] ?? '');
        $startIso = (string) ($item['start'] ?? '');
        $endIso = (string) ($item['end'] ?? '');

        if ($productId <= 0) {
            return;
        }

        if ($participants <= 0) {
            throw new RuntimeException(__('Geen canonieke deelnemers gevonden voor een geselecteerde activiteit.', 'sbdp'));
        }

        if ($date === '' && strlen($startIso) >= 10) {
            $date = substr($startIso, 0, 10);
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException(__('Geen geldige datum gevonden voor een geselecteerde activiteit.', 'sbdp'));
        }

        if ($this->extractTimePart($startIso) === '' || $this->extractTimePart($endIso) === '') {
            throw new RuntimeException(__('Geen geldige tijd gevonden voor een geselecteerde activiteit.', 'sbdp'));
        }

        $slotAvailability = $this->resolveSlotAvailability($productId, $date, $participants, $resourceId, $startIso, $endIso);
        $reasonCode = isset($slotAvailability['reason_code']) ? (string) $slotAvailability['reason_code'] : '';

        if (! empty($slotAvailability['lookup_error'])) {
            throw new RuntimeException(__('Beschikbaarheid kon niet worden gecontroleerd.', 'sbdp'));
        }

        if (isset($slotAvailability['resource_valid']) && $slotAvailability['resource_valid'] === false) {
            throw new RuntimeException(__('De geselecteerde resource is niet meer geldig voor deze activiteit.', 'sbdp'));
        }

        if ($reasonCode === 'capacity_exceeded') {
            throw new RuntimeException(__('Voor een activiteit is niet genoeg capaciteit beschikbaar.', 'sbdp'));
        }

        if (! empty($slotAvailability['selected_time_valid']) && ! empty($slotAvailability['execution_ok'])) {
            return;
        }

        if ($reasonCode === 'missing_time_window') {
            throw new RuntimeException(__('Ongeldig tijdslot voor een activiteit.', 'sbdp'));
        }

        throw new RuntimeException(__('Het gekozen tijdslot is niet meer beschikbaar.', 'sbdp'));
    }

    /**
     * Resolve participant-aware slot availability for planner/cart/checkout runtime.
     *
     * @return array{
     *   product_id:int,
     *   resource_id:int,
     *   date:string,
     *   participants:int,
     *   slots:array<int, array<string, mixed>>,
     *   capacity:int,
     *   resource_valid:bool,
     *   selected_time_valid:bool,
     *   execution_ok:bool,
     *   lookup_error:bool,
     *   reason_code:?string,
     *   execution_error_code:?string
     * }
     */
    private function resolveSlotAvailability(
        int $productId,
        string $date,
        int $participants,
        int $resourceId,
        string $startIso = '',
        string $endIso = ''
    ): array {
        return $this->bookingTruthRuntime()->resolveSlotAvailability(
            $productId,
            $date,
            $participants,
            $resourceId,
            $startIso,
            $endIso
        );
    }

    private function extractTimePart(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/\d{2}:\d{2}/', $value, $matches)) {
            return $matches[0];
        }
        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     */
    private function resolveSlotLengthMinutes(array $slots): int
    {
        $first = $slots[0] ?? array();
        $start = isset($first['start']) ? $this->timeToMinutes((string) $first['start']) : null;
        $end = isset($first['end']) ? $this->timeToMinutes((string) $first['end']) : null;
        if ($start === null || $end === null) {
            return 0;
        }
        return max(0, $end - $start);
    }

    private function timeToMinutes(string $time): ?int
    {
        if (! preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $matches)) {
            return null;
        }

        $h = (int) $matches[1];
        $m = (int) $matches[2];

        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return null;
        }

        return $h * 60 + $m;
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     */
    private function isSelectionCoveredByExplicitSlot(array $slots, int $startMinutes, int $endMinutes): bool
    {
        foreach ($slots as $slot) {
            if (! isset($slot['start'], $slot['end'])) {
                continue;
            }

            $slotStart = $this->timeToMinutes((string) $slot['start']);
            $slotEnd = $this->timeToMinutes((string) $slot['end']);

            if ($slotStart === null || $slotEnd === null || $slotEnd <= $slotStart) {
                continue;
            }

            if ($startMinutes >= $slotStart && $endMinutes <= $slotEnd) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function resolveDefaultParticipants(array $plan): int
    {
        $context = $this->resolveCanonicalParticipantsContext($plan);

        return (int) ($context['participants'] ?? 0);
    }

    /**
     * Resolve the canonical participants count for planner/cart/checkout handoff.
     *
     * Compatibility shapes may be accepted as input, but the returned value is the
     * only participants truth that should continue through runtime decisions.
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $item
     * @param array<string, mixed> $handoff
     * @return array{participants:int,source:string,fallback_warning:?string}
     */
    private function resolveCanonicalParticipantsContext(array $plan, array $item = array(), array $handoff = array()): array
    {
        $planMeta = isset($plan['meta']) && is_array($plan['meta']) ? $plan['meta'] : array();
        $sources = array(
            'handoff.participants'      => $handoff['participants'] ?? null,
            'handoff.people'            => $handoff['people'] ?? null,
            'planner_item.participants' => $item['participants'] ?? null,
            'planner.form.participants' => isset($planMeta['form']) && is_array($planMeta['form']) ? ($planMeta['form']['participants'] ?? null) : null,
            'meta.participant_count'    => $planMeta['participant_count'] ?? null,
        );

        foreach ($sources as $source => $candidate) {
            $count = (int) $candidate;
            if ($count <= 0) {
                continue;
            }

            return array(
                'participants'     => $count,
                'source'           => $source,
                'fallback_warning' => $source === 'planner.form.participants' || $source === 'handoff.participants' || $source === 'planner_item.participants'
                    ? null
                    : 'compatibility_source',
            );
        }

        if (isset($plan['participants']) && is_array($plan['participants']) && $plan['participants'] !== array()) {
            return array(
                'participants'     => count($plan['participants']),
                'source'           => 'participants_array',
                'fallback_warning' => 'compatibility_source',
            );
        }

        return array(
            'participants'     => 0,
            'source'           => 'unresolved',
            'fallback_warning' => 'missing_canonical_participants',
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function assertDirectCheckoutEligible(array $items): void
    {
        foreach ($items as $item) {
            $capability = $this->resolveItemBookingCapabilityProfile($item);
            if (($capability['status'] ?? '') !== self::CAPABILITY_STATUS_DIRECT) {
                throw new RuntimeException(
                    __('Deze planning bevat activiteiten die alleen via offerte verwerkt kunnen worden.', 'sbdp')
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveItemBookingCapability(array $item): string
    {
        $profile = $this->resolveItemBookingCapabilityProfile($item);

        return (string) ($profile['legacy_status'] ?? self::BOOKING_CAPABILITY_REQUEST);
    }

    /**
     * @param array<string, mixed> $item
     * @return array{status:string,route_intent:string,reason_code:?string,legacy_status:string}
     */
    private function resolveItemBookingCapabilityProfile(array $item): array
    {
        $participants = (int) ($item['participants'] ?? 0);
        if ($participants <= 0) {
            return $this->buildCapabilityProfile(self::CAPABILITY_STATUS_UNAVAILABLE, 'missing_canonical_participants');
        }

        $planItem = isset($item['plan_item']) && is_array($item['plan_item']) ? $item['plan_item'] : array();
        $bookingResolution = isset($planItem['bookingResolution']) && is_array($planItem['bookingResolution'])
            ? $planItem['bookingResolution']
            : array();
        $requiresConfirmation = $bookingResolution['requires_confirmation'] ?? ($bookingResolution['requiresConfirmation'] ?? null);

        return $this->bookingTruthRuntime()->resolveBookingCapabilityProfile(
            $item,
            array(
                'explicit_capability'      => $planItem['bookingCapability'] ?? $planItem['booking_capability'] ?? null,
                'booking_resolution_status'=> $bookingResolution['status'] ?? null,
                'requires_confirmation'    => $requiresConfirmation,
            )
        );
    }

    private function bookingTruthRuntime(): BookingTruthRuntimeService
    {
        if (! $this->bookingTruthRuntime instanceof BookingTruthRuntimeService) {
            $this->bookingTruthRuntime = new BookingTruthRuntimeService();
        }

        return $this->bookingTruthRuntime;
    }

    /**
     * @param mixed $value
     */
    private function normalizeBookingCapability($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, array('direct', 'direct_eligible', 'direct-eligible', 'book', 'checkout'), true)) {
            return self::CAPABILITY_STATUS_DIRECT;
        }

        if (in_array($normalized, array('direct_limited', 'direct-limited', 'limited_direct', 'limited-direct'), true)) {
            return self::CAPABILITY_STATUS_DIRECT_LIMITED;
        }

        if (in_array($normalized, array('request', 'request_only', 'request-only', 'quote', 'quote_only', 'quote-only'), true)) {
            return self::CAPABILITY_STATUS_REQUEST;
        }

        if (in_array($normalized, array('unavailable', 'blocked', 'closed', 'none'), true)) {
            return self::CAPABILITY_STATUS_UNAVAILABLE;
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{status:string,route_intent:string,reason_code:?string,legacy_status:string}
     */
    private function resolvePlanBookingCapability(array $items): array
    {
        if ($items === array()) {
            return $this->buildCapabilityProfile(self::CAPABILITY_STATUS_UNAVAILABLE, 'empty_plan');
        }

        $hasLimitedDirect = false;
        $requestReason = null;

        foreach ($items as $item) {
            $profile = $this->resolveItemBookingCapabilityProfile($item);
            $status = (string) ($profile['status'] ?? '');
            $reason = isset($profile['reason_code']) && is_string($profile['reason_code'])
                ? $profile['reason_code']
                : null;

            if ($status === self::CAPABILITY_STATUS_UNAVAILABLE) {
                return $this->buildCapabilityProfile(self::CAPABILITY_STATUS_UNAVAILABLE, $reason ?? 'item_unavailable');
            }

            if ($status === self::CAPABILITY_STATUS_REQUEST && $requestReason === null) {
                $requestReason = $reason ?? 'request_only_item_present';
            }

            if ($status === self::CAPABILITY_STATUS_DIRECT_LIMITED) {
                $hasLimitedDirect = true;
            }
        }

        if ($requestReason !== null) {
            return $this->buildCapabilityProfile(self::CAPABILITY_STATUS_REQUEST, $requestReason);
        }

        if ($hasLimitedDirect) {
            return $this->buildCapabilityProfile(self::CAPABILITY_STATUS_DIRECT_LIMITED, 'direct_with_limits');
        }

        return $this->buildCapabilityProfile(self::CAPABILITY_STATUS_DIRECT);
    }

    /**
     * @return array{status:string,route_intent:string,reason_code:?string,legacy_status:string}
     */
    private function buildCapabilityProfile(string $status, ?string $reasonCode = null): array
    {
        return array(
            'status'        => $status,
            'route_intent'  => $this->mapCapabilityStatusToRouteIntent($status),
            'reason_code'   => $reasonCode,
            'legacy_status' => $this->mapCapabilityStatusToLegacy($status),
        );
    }

    private function mapCapabilityStatusToRouteIntent(string $status): string
    {
        if ($status === self::CAPABILITY_STATUS_DIRECT || $status === self::CAPABILITY_STATUS_DIRECT_LIMITED) {
            return self::ROUTE_INTENT_CHECKOUT;
        }

        if ($status === self::CAPABILITY_STATUS_REQUEST) {
            return self::ROUTE_INTENT_QUOTE;
        }

        return self::ROUTE_INTENT_BLOCKED;
    }

    private function mapCapabilityStatusToLegacy(string $status): string
    {
        if ($status === self::CAPABILITY_STATUS_DIRECT || $status === self::CAPABILITY_STATUS_DIRECT_LIMITED) {
            return self::BOOKING_CAPABILITY_DIRECT;
        }

        return self::BOOKING_CAPABILITY_REQUEST;
    }

    private function productRequiresConfirmation(int $productId): bool
    {
        $wcFlag = get_post_meta($productId, '_wc_booking_requires_confirmation', true);
        if ($this->toBoolean($wcFlag)) {
            return true;
        }

        $bookable = get_post_meta($productId, '_sbdp_bookable', true);
        if (is_array($bookable)) {
            $flag = $bookable['booking_requires_confirmation'] ?? null;
            if ($this->toBoolean($flag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private function toBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), array('1', 'true', 'yes', 'on'), true);
    }

    private function ensureCartSession(): void
    {
        if (! function_exists('WC')) {
            return;
        }

        if (null === \WC()->session && method_exists(\WC(), 'initialize_session')) {
            \WC()->initialize_session();
        }

        if (function_exists('wc_load_cart')) {
            if (null === \WC()->cart || ! \WC()->cart) {
                \wc_load_cart();
            }
        } elseif (null === \WC()->cart && class_exists('\WC_Cart')) {
            \WC()->cart = new \WC_Cart();
        }
    }

    private function firstCartErrorNotice(): string
    {
        if (! function_exists('wc_get_notices')) {
            return '';
        }

        $notices = \wc_get_notices('error');
        if (empty($notices) || ! is_array($notices)) {
            return '';
        }

        $first = reset($notices);

        if (function_exists('wc_clear_notices')) {
            \wc_clear_notices();
        }

        if (is_array($first) && isset($first['notice'])) {
            return wp_strip_all_tags((string) $first['notice']);
        }

        if (is_string($first)) {
            return wp_strip_all_tags($first);
        }

        return '';
    }

    private function composeStartIso(string $timeValue, ?string $dayDate): string
    {
        $timeValue = trim($timeValue);
        if ($timeValue === '') {
            return '';
        }

        if ($dayDate && preg_match('/^\d{2}:\d{2}$/', $timeValue) === 1) {
            return $dayDate . 'T' . $timeValue . ':00';
        }

        if ($dayDate && preg_match('/^\d{2}:\d{2}:\d{2}$/', $timeValue) === 1) {
            return $dayDate . 'T' . $timeValue;
        }

        return $timeValue;
    }

    private function getResourceLabel(int $resourceId): string
    {
        if ($resourceId <= 0) {
            return '';
        }

        $label = get_the_title($resourceId);
        if (! $label) {
            return '';
        }

        return sanitize_text_field((string) $label);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function snapshotItinerary(array $items, int $participants): array
    {
        $snapshot = array(
            'participants' => max(0, $participants),
            'items'        => array(),
        );

        foreach ($items as $entry) {
            $snapshot['items'][] = array(
                'product_id'  => (int) ($entry['product_id'] ?? 0),
                'resource_id' => (int) ($entry['resource_id'] ?? 0),
                'start'       => $this->sanitizeSnapshotValue($entry['start'] ?? ''),
                'end'         => $this->sanitizeSnapshotValue($entry['end'] ?? ''),
            );
        }

        return $snapshot;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function resolveSnapshotParticipants(array $items): int
    {
        $maximum = 0;
        foreach ($items as $item) {
            $count = isset($item['participants']) ? (int) $item['participants'] : 0;
            if ($count > $maximum) {
                $maximum = $count;
            }
        }

        return $maximum > 0 ? $maximum : 0;
    }

    /**
     * @param mixed $value
     */
    private function sanitizeSnapshotValue($value): string
    {
        if (! is_string($value)) {
            $value = (string) $value;
        }

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function ensureEditToken(array $plan, ?int $planId = null): array
    {
        $meta = isset($plan['meta']) && is_array($plan['meta']) ? $plan['meta'] : [];
        $token = '';

        if (isset($meta['edit_token']) && is_string($meta['edit_token'])) {
            $token = trim($meta['edit_token']);
        }

        if ($token === '' && $planId !== null) {
            try {
                $existing = $this->repository->get($planId);
                if (isset($existing['meta']) && is_array($existing['meta'])) {
                    $existingToken = $existing['meta']['edit_token'] ?? null;
                    if (is_string($existingToken)) {
                        $token = trim($existingToken);
                    }
                }
            } catch (\Throwable $exception) {
                // Ignore failures and fall back to generating a new token.
                unset($exception);
            }
        }

        if ($token === '') {
            $token = $this->generateEditToken();
        }

        $meta['edit_token'] = $token;
        $plan['meta']       = $meta;

        return $plan;
    }

    private function generateEditToken(): string
    {
        try {
            return substr(bin2hex(random_bytes(24)), 0, 48);
        } catch (\Throwable $exception) {
            unset($exception);

            if (\function_exists('wp_generate_password')) {
                return strtolower(\wp_generate_password(32, false, false));
            }

            return md5(uniqid('sbdp_plan', true));
        }
    }

    private function extractTimeComponent(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^\d{2}:\d{2}$/', $trimmed) === 1) {
            return $trimmed;
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $trimmed) === 1) {
            return substr($trimmed, 0, 5);
        }

        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}T(\d{2}:\d{2})(:\d{2})?/',
                $trimmed,
                $matches
            ) === 1
        ) {
            return $matches[1];
        }

        return $trimmed;
    }

    private function adjustTimeByMinutes(string $time, int $minutes): string
    {
        $base = \date_create('1970-01-01 ' . $time . ':00');
        if (! $base) {
            return $time;
        }

        $interval = \DateInterval::createFromDateString(($minutes >= 0 ? '+' : '') . $minutes . ' minutes');
        if (! $interval) {
            return $time;
        }

        $base = $base->add($interval);

        return $base->format('H:i');
    }

    private function calculateDurationMinutes(string $start, string $end): ?int
    {
        $startDt = \date_create('1970-01-01 ' . $start . ':00');
        $endDt   = \date_create('1970-01-01 ' . $end . ':00');

        if (! $startDt || ! $endDt) {
            return null;
        }

        $diff = $startDt->diff($endDt);
        $minutes = ($diff->h * 60) + $diff->i;

        if ($minutes <= 0) {
            return null;
        }

        return $minutes;
    }

    /**
     * @param mixed $settings
     *
     * @return array<string, mixed>
     */
    public static function sanitizeSettings($settings): array
    {
        if (! is_array($settings)) {
            $settings = [];
        }

        $default = [
            'time_step_minutes' => 15,
            'open_hours'        => [
                'start' => '08:00',
                'end'   => '22:00',
            ],
            'allow_multi_day'   => true,
            'default_day_count' => 1,
            'default_participants' => 10,
            'autosave'          => true,
            'currency'          => 'EUR',
            'locale'            => 'nl-NL',
            'theme'             => 'light',
        ];

        $sanitised = array_merge($default, $settings);

        $sanitised['time_step_minutes'] = max(5, (int) $sanitised['time_step_minutes']);
        $sanitised['allow_multi_day']   = (bool) $sanitised['allow_multi_day'];
        $sanitised['default_day_count'] = max(1, (int) $sanitised['default_day_count']);
        $sanitised['default_participants'] = max(1, (int) $sanitised['default_participants']);
        $sanitised['autosave']          = (bool) $sanitised['autosave'];
        $sanitised['currency']          = strtoupper((string) $sanitised['currency']);
        $sanitised['locale']            = (string) $sanitised['locale'];

        if (! isset($sanitised['open_hours']['start'], $sanitised['open_hours']['end'])) {
            $sanitised['open_hours'] = $default['open_hours'];
        }

        return $sanitised;
    }

    /**
     * @param array<string, mixed> $preferences
     *
     * @return array<string, mixed>
     */
    public function suggestActivities(array $preferences): array
    {
        $sessionId = $this->resolveSessionId($preferences);
        $traceId = $this->resolveTraceId($sessionId);
        $context = $sessionId !== '' ? $this->sessionStore->load($sessionId) : [];
        $turnCount = max(0, (int) ($context['turn_count'] ?? 0)) + 1;
        $sessionStartedAt = isset($context['session_started_at']) && is_string($context['session_started_at']) && $context['session_started_at'] !== ''
            ? (string) $context['session_started_at']
            : gmdate('c');
        $experimentVariant = $this->resolveExperimentVariant($sessionId, $preferences);

        if (! isset($context['trace_id']) || ! is_string($context['trace_id']) || $context['trace_id'] === '') {
            $context['trace_id'] = $traceId;
        } else {
            $traceId = $context['trace_id'];
        }
        $context['turn_count'] = $turnCount;
        $context['session_started_at'] = $sessionStartedAt;
        $context['experiment_variant'] = $experimentVariant;

        $this->eventLogger->log('session_started', [
            'trace_id'   => $traceId,
            'session_id' => $sessionId,
            'route'      => '/planner/v1/plan/ai/suggest',
            'turn_count' => $turnCount,
            'experiment_variant' => $experimentVariant,
        ]);

        $offers = $this->catalog->listProducts($preferences);
        if ($offers === []) {
            if ($sessionId !== '') {
                $this->sessionStore->save($sessionId, is_array($context) ? $context : []);
            }
            $fallback = $this->ai->suggest($preferences);
            $fallback['meta'] = is_array($fallback['meta'] ?? null) ? $fallback['meta'] : [];
            $fallback['meta']['trace_id'] = $traceId;
            $fallback['meta']['session_id'] = $sessionId;
            $fallback['meta']['turn_count'] = $turnCount;
            $fallback['meta']['experiment_variant'] = $experimentVariant;
            $fallback['meta']['decision_mode'] = 'ai_fallback_no_offers';
            return $fallback;
        }

        $decisionInput = [
            'message'     => (string) ($preferences['message'] ?? $preferences['query'] ?? ''),
            'constraints' => $this->extractConstraints($preferences),
            'date'        => $preferences['date'] ?? null,
            'duration'    => $preferences['duration'] ?? null,
            'participants'=> $preferences['participants'] ?? null,
            'vibe'        => $preferences['vibe'] ?? null,
        ];
        $spots = $this->spotsClient->listSpots($this->buildSpotFilters($preferences));

        $decision = $this->decisionEngine->decide($decisionInput, is_array($context) ? $context : [], $offers, $spots);
        $primary = is_array($decision['primary'] ?? null) ? $decision['primary'] : null;
        $alternatives = is_array($decision['alternatives'] ?? null) ? $decision['alternatives'] : [];
        $questions = is_array($decision['questions'] ?? null) ? $decision['questions'] : [];

        $activeContext = is_array($decision['context'] ?? null) ? $decision['context'] : [];
        $activeContext['turn_count'] = $turnCount;
        $activeContext['session_started_at'] = $sessionStartedAt;
        $activeContext['experiment_variant'] = $experimentVariant;
        $timeToPrimarySeconds = null;
        if ($primary !== null) {
            $firstPrimaryAt = isset($context['first_primary_at']) && is_string($context['first_primary_at']) && $context['first_primary_at'] !== ''
                ? (string) $context['first_primary_at']
                : gmdate('c');
            $activeContext['first_primary_at'] = $firstPrimaryAt;
            $sessionStartedTs = strtotime($sessionStartedAt);
            $firstPrimaryTs = strtotime($firstPrimaryAt);
            if ($sessionStartedTs !== false && $firstPrimaryTs !== false) {
                $timeToPrimarySeconds = max(0, $firstPrimaryTs - $sessionStartedTs);
                $activeContext['time_to_primary_seconds'] = $timeToPrimarySeconds;
            }
        }
        if ($sessionId !== '') {
            $this->sessionStore->save($sessionId, $activeContext);
        }

        $plan = ['timeline' => [], 'buffers' => [], 'feasible' => false, 'notes' => []];
        if ($primary !== null) {
            $constraints = is_array($activeContext['constraints'] ?? null) ? $activeContext['constraints'] : [];
            $plan = $this->planEngine->build($primary, $constraints, $alternatives);
        }

        $decisionTrace = is_array($decision['decision_trace'] ?? null) ? $decision['decision_trace'] : [];
        $decisionTrace['turn_count'] = $turnCount;
        $decisionTrace['experiment_variant'] = $experimentVariant;
        if ($timeToPrimarySeconds !== null) {
            $decisionTrace['time_to_primary_seconds'] = $timeToPrimarySeconds;
        }

        $assistantResponse = $this->buildAssistantResponse(
            $traceId,
            (string) ($decision['action'] ?? 'BUILD_PLAN'),
            $primary,
            $alternatives,
            $plan,
            $questions,
            $decisionTrace,
            $experimentVariant
        );
        $assistantResponse = $this->responseValidator->enforce($assistantResponse);

        $this->eventLogger->log('intent_detected', [
            'trace_id' => $traceId,
            'session_id' => $sessionId,
            'intent' => (string) ($decision['intent'] ?? 'DISCOVERY_INTENT'),
            'turn_count' => $turnCount,
            'experiment_variant' => $experimentVariant,
        ]);
        if ((string) ($decision['intent'] ?? '') === 'CHANGE_INTENT') {
            $this->eventLogger->log('primary_changed', [
                'trace_id' => $traceId,
                'session_id' => $sessionId,
                'turn_count' => $turnCount,
            ]);
        }

        if ($primary !== null) {
            $this->eventLogger->log('primary_selected', [
                'trace_id' => $traceId,
                'session_id' => $sessionId,
                'primary_kind' => (string) ($primary['kind'] ?? ''),
                'primary_id' => $primary['id'] ?? null,
                'turn_count' => $turnCount,
                'time_to_primary_seconds' => $timeToPrimarySeconds,
                'experiment_variant' => $experimentVariant,
            ]);
        }

        $this->eventLogger->log('plan_built', [
            'trace_id' => $traceId,
            'session_id' => $sessionId,
            'timeline_count' => count($plan['timeline'] ?? []),
            'feasible' => (bool) ($plan['feasible'] ?? false),
            'turn_count' => $turnCount,
            'experiment_variant' => $experimentVariant,
        ]);
        $this->eventLogger->log('cta_rendered', [
            'trace_id' => $traceId,
            'session_id' => $sessionId,
            'cta_count' => count($assistantResponse['primary']['cta'] ?? [])
                + count(($assistantResponse['alternatives'][0]['cta'] ?? []))
                + count(($assistantResponse['alternatives'][1]['cta'] ?? []))
                + count(($assistantResponse['alternatives'][2]['cta'] ?? [])),
            'turn_count' => $turnCount,
            'experiment_variant' => $experimentVariant,
        ]);
        if ((string) ($decision['action'] ?? '') === 'SUPPORT_ONLY') {
            $this->eventLogger->log('handoff_requested', [
                'trace_id' => $traceId,
                'session_id' => $sessionId,
                'reason' => 'support_only_no_commercial_path',
                'turn_count' => $turnCount,
            ]);
        }

        $timeline = is_array($plan['timeline'] ?? null) ? $plan['timeline'] : [];
        $activities = array_map(
            static function (array $item): array {
                return [
                    'title' => (string) ($item['title'] ?? ''),
                    'start' => (string) ($item['start'] ?? ''),
                    'end'   => (string) ($item['end'] ?? ''),
                    'location' => (string) ($item['location_hint'] ?? ''),
                    'notes' => (string) ($item['notes'] ?? ''),
                ];
            },
            $timeline
        );

        $summary = $primary !== null
            ? sprintf('Aanbeveling: %s. Plan bevat %d blokken.', (string) ($primary['title'] ?? 'activiteit'), count($timeline))
            : 'Geen primaire aanbeveling gevonden; verfijn je voorkeuren.';

        return [
            'summary' => $summary,
            'activities' => $activities,
            'itinerary' => $timeline,
            'meta' => [
                'trace_id' => $traceId,
                'session_id' => $sessionId,
                'intent' => (string) ($decision['intent'] ?? 'DISCOVERY_INTENT'),
                'action' => (string) ($decision['action'] ?? 'BUILD_PLAN'),
                'turn_count' => $turnCount,
                'time_to_primary_seconds' => $timeToPrimarySeconds,
                'experiment_variant' => $experimentVariant,
                'offers_count' => count($offers),
                'spots_count' => count($spots),
                'decision_trace' => $decisionTrace,
                'fallbacks' => [],
            ],
            'assistant_response' => $assistantResponse,
        ];
    }

    /**
     * @param array<string, mixed> $preferences
     *
     * @return array<string, mixed>
     */
    private function buildSpotFilters(array $preferences): array
    {
        $filters = [];
        if (isset($preferences['area']) && is_string($preferences['area'])) {
            $filters['area'] = trim($preferences['area']);
        }
        if (isset($preferences['query']) && is_string($preferences['query']) && trim($preferences['query']) !== '') {
            $filters['q'] = trim($preferences['query']);
        } elseif (isset($preferences['message']) && is_string($preferences['message']) && trim($preferences['message']) !== '') {
            $filters['q'] = trim($preferences['message']);
        }
        if (isset($preferences['type']) && is_string($preferences['type'])) {
            $filters['type'] = trim($preferences['type']);
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $preferences
     *
     * @return array<string, mixed>
     */
    private function extractConstraints(array $preferences): array
    {
        $constraints = [];
        if (isset($preferences['date'])) {
            $constraints['date'] = (string) $preferences['date'];
        }
        if (isset($preferences['start_time'])) {
            $constraints['start_time'] = (string) $preferences['start_time'];
        }
        if (isset($preferences['duration_minutes'])) {
            $constraints['duration_minutes'] = max(0, (int) $preferences['duration_minutes']);
        } elseif (isset($preferences['duration'])) {
            $constraints['duration_minutes'] = match (strtolower((string) $preferences['duration'])) {
                'ochtend'  => 180,
                'middag'   => 300,
                'avond'    => 300,
                'hele-dag' => 600,
                default    => 240,
            };
        }
        if (isset($preferences['participants'])) {
            $constraints['pax'] = max(1, (int) $preferences['participants']);
        }
        if (isset($preferences['pax'])) {
            $constraints['pax'] = max(1, (int) $preferences['pax']);
        }
        if (isset($preferences['vibe'])) {
            $tokens = preg_split('/[\s,]+/', strtolower((string) $preferences['vibe'])) ?: [];
            $constraints['vibe_tags'] = array_values(array_filter($tokens));
        }
        if (isset($preferences['rainy_day'])) {
            $constraints['rainy_day'] = (bool) $preferences['rainy_day'];
        }

        return $constraints;
    }

    /**
     * @param array<string, mixed>|null $primary
     * @param array<int, array<string, mixed>> $alternatives
     * @param array<string, mixed> $plan
     * @param array<int, string> $questions
     * @param array<string, mixed> $decisionTrace
     * @param string $experimentVariant
     *
     * @return array<string, mixed>
     */
    private function buildAssistantResponse(
        string $traceId,
        string $action,
        ?array $primary,
        array $alternatives,
        array $plan,
        array $questions,
        array $decisionTrace,
        string $experimentVariant
    ): array {
        $primaryPayload = [
            'kind' => $primary !== null ? (string) ($primary['kind'] ?? 'offer') : 'offer',
            'id'   => $primary['id'] ?? 'n/a',
            'title'=> $primary !== null ? (string) ($primary['title'] ?? 'Aanbeveling') : 'Geen aanbeveling',
            'reason_bullets' => $this->reasonBullets($primary),
            'cta'  => $this->buildEntityCtas($primary, $experimentVariant, true),
        ];

        $alternativesPayload = [];
        foreach (array_slice($alternatives, 0, 3) as $alternative) {
            $alternativesPayload[] = [
                'kind'  => (string) ($alternative['kind'] ?? 'offer'),
                'id'    => $alternative['id'] ?? 'n/a',
                'title' => (string) ($alternative['title'] ?? 'Alternatief'),
                'cta'   => $this->buildEntityCtas(is_array($alternative) ? $alternative : null, $experimentVariant, false),
            ];
        }

        while (count($alternativesPayload) < 3) {
            $alternativesPayload[] = [
                'kind' => 'offer',
                'id' => 'n/a',
                'title' => 'Nog een alternatief',
                'cta' => $this->buildEntityCtas(null, $experimentVariant, false),
            ];
        }

        return [
            'type' => 'assistant_response',
            'schema_version' => '1.0.0',
            'trace_id' => $traceId,
            'action' => $action,
            'primary' => $primaryPayload,
            'alternatives' => $alternativesPayload,
            'plan' => [
                'timeline' => is_array($plan['timeline'] ?? null) ? $plan['timeline'] : [],
            ],
            'questions' => $questions,
            'decision_trace' => $decisionTrace,
        ];
    }

    /**
     * @param array<string, mixed>|null $primary
     * @param array<int, array<string, mixed>> $alternatives
     * @param string $experimentVariant
     *
     * @return array<int, array<string, string>>
     */
    private function buildEntityCtas(?array $entity, string $experimentVariant, bool $isPrimary): array
    {
        $routeIntent = $this->resolveEntityRouteIntent($entity);
        $detailsUrl = $this->resolveEntityTargetUrl(
            $entity,
            array('url', 'details_url', 'detailsUrl'),
            home_url('/plan-je-dag/')
        );
        $refineUrl = home_url('/plan-je-dag/');
        $quoteUrl = $this->resolveEntityTargetUrl(
            $entity,
            array('quote_url', 'quoteUrl'),
            home_url('/offerte/')
        );
        $bookUrl = $this->resolveEntityTargetUrl(
            $entity,
            array('checkout_url', 'checkoutUrl', 'book_url', 'bookUrl'),
            ''
        );
        $humanUrl = home_url('/contact/');

        $pool = array();
        if ($experimentVariant === 'B') {
            $pool[] = array('label' => 'Bekijk details', 'url' => $detailsUrl, 'kind' => 'refine');
        }

        if ($routeIntent === self::ROUTE_INTENT_CHECKOUT && $bookUrl !== '') {
            $pool[] = array('label' => 'Boek nu', 'url' => $bookUrl, 'kind' => 'book');
        } elseif ($routeIntent === self::ROUTE_INTENT_QUOTE && $quoteUrl !== '') {
            $pool[] = array('label' => 'Vraag offerte aan', 'url' => $quoteUrl, 'kind' => 'quote');
        }

        $pool[] = array('label' => 'Bekijk details', 'url' => $detailsUrl, 'kind' => 'refine');
        $pool[] = array('label' => 'Verfijn planning', 'url' => $refineUrl, 'kind' => 'refine');
        $pool[] = array('label' => 'Praat met expert', 'url' => $humanUrl, 'kind' => 'human');

        if (! $isPrimary) {
            return array_slice($this->uniqueCtas($pool), 0, 2);
        }

        return array_slice($this->uniqueCtas($pool), 0, 4);
    }

    /**
     * @param array<string, mixed>|null $entity
     */
    private function resolveEntityRouteIntent(?array $entity): string
    {
        if (! is_array($entity)) {
            return self::ROUTE_INTENT_BLOCKED;
        }

        $routeIntent = $entity['route_intent'] ?? $entity['routeIntent'] ?? null;
        if (is_string($routeIntent)) {
            $normalized = strtolower(trim($routeIntent));
            if (in_array($normalized, array(self::ROUTE_INTENT_CHECKOUT, self::ROUTE_INTENT_QUOTE, self::ROUTE_INTENT_BLOCKED), true)) {
                return $normalized;
            }
        }

        $capability = $this->normalizeBookingCapability(
            $entity['booking_capability']
                ?? $entity['bookingCapability']
                ?? $entity['explicit_capability']
                ?? null
        );
        if ($capability !== null) {
            return $this->mapCapabilityStatusToRouteIntent($capability);
        }

        return self::ROUTE_INTENT_BLOCKED;
    }

    /**
     * @param array<string, mixed>|null $entity
     * @param array<int, string> $keys
     */
    private function resolveEntityTargetUrl(?array $entity, array $keys, string $fallback): string
    {
        if (is_array($entity)) {
            foreach ($keys as $key) {
                if (! isset($entity[$key]) || ! is_string($entity[$key])) {
                    continue;
                }

                $url = trim((string) $entity[$key]);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return $fallback;
    }

    /**
     * @param array<int, array<string, string>> $pool
     * @return array<int, array<string, string>>
     */
    private function uniqueCtas(array $pool): array
    {
        $unique = array();
        $seen = array();

        foreach ($pool as $cta) {
            $label = isset($cta['label']) ? trim((string) $cta['label']) : '';
            $url = isset($cta['url']) ? trim((string) $cta['url']) : '';
            $kind = isset($cta['kind']) ? trim((string) $cta['kind']) : '';
            if ($label === '' || $url === '' || $kind === '') {
                continue;
            }

            $key = strtolower($kind) . '|' . $url . '|' . strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = array(
                'label' => $label,
                'url' => $url,
                'kind' => $kind,
            );
        }

        return $unique;
    }

    /**
     * @param array<string, mixed>|null $primary
     *
     * @return array<int, string>
     */
    private function reasonBullets(?array $primary): array
    {
        if ($primary === null) {
            return ['Geen primaire keuze beschikbaar', 'Verfijn je voorkeuren voor betere match'];
        }

        $breakdown = is_array($primary['breakdown'] ?? null) ? $primary['breakdown'] : [];
        arsort($breakdown);
        $topFactors = array_slice(array_keys($breakdown), 0, 2);
        if ($topFactors === []) {
            $topFactors = ['score'];
        }

        return array_map(
            static fn(string $factor): string => 'Sterke factor: ' . str_replace('_', ' ', $factor),
            $topFactors
        );
    }

    /**
     * @param array<string, mixed> $preferences
     */
    private function resolveSessionId(array $preferences): string
    {
        $raw = (string) ($preferences['session_id'] ?? $preferences['sessionId'] ?? '');
        $raw = trim($raw);
        if ($raw !== '') {
            return substr(preg_replace('/[^A-Za-z0-9\-_]/', '', $raw) ?: '', 0, 64);
        }

        $seed = (string) ($preferences['date'] ?? '') . '|' . (string) ($preferences['participants'] ?? '') . '|' . (string) ($preferences['vibe'] ?? '');
        return substr(md5($seed !== '||' ? $seed : uniqid('sess_', true)), 0, 16);
    }

    private function resolveTraceId(string $sessionId): string
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return 'trace_' . substr(md5((string) microtime(true)), 0, 12);
        }

        return 'trace_' . substr(md5($sessionId . '|' . microtime(true)), 0, 12);
    }

    /**
     * @param array<string, mixed> $preferences
     */
    private function resolveExperimentVariant(string $sessionId, array $preferences): string
    {
        $hint = strtoupper(trim((string) ($preferences['experiment_variant'] ?? '')));
        if ($hint === 'A' || $hint === 'B') {
            return $hint;
        }

        $seed = $sessionId !== '' ? $sessionId : (string) ($preferences['message'] ?? gmdate('c'));
        return (crc32($seed) % 2 === 0) ? 'A' : 'B';
    }

    /**
     * @param array<string, mixed> $plan
     *
     * @return array<int, array<string, mixed>>
     */
    public function detectConflicts(array $plan): array
    {
        $days = $plan['days'] ?? [];
        if (! is_array($days)) {
            throw new InvalidArgumentException('Plan payload must contain days array.');
        }

        return $this->availability->detectConflicts($days);
    }
}
