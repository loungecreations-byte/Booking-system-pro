<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

use BSPModule\Core\Product\ProductMeta;
use BSPModule\Core\Rest\RestService;
use WP_REST_Request;

/**
 * Smart Activity Suggestion Service
 * 
 * Generates personalized Plan je dag itineraries based on Home Widget preferences:
 * - Duration (ochtend/middag/avond/hele-dag)
 * - Group type (familie/vrienden/bedrijf/school/romantisch/solo)
 * - Vibes/interests (cultuur/bourgondisch/food/actief/verrassing)
 * - Number of participants
 * - Selected date
 */
final class AiSuggestionService
{
    /**
     * Mapping of preference vibes to WooCommerce product category slugs.
     * Each vibe maps to multiple category slugs that are considered relevant.
     */
    private const VIBE_CATEGORY_MAP = [
        'cultuur'      => ['musea', 'cultuur', 'historie', 'bezienswaardigheden', 'rondleidingen', 'museum', 'kunst', 'erfgoed'],
        'bourgondisch' => ['restaurants', 'proeverijen', 'food', 'culinair', 'eten-drinken', 'wijn', 'bier', 'horeca', 'lunch', 'diner'],
        'food'         => ['restaurants', 'proeverijen', 'food', 'culinair', 'eten-drinken', 'lunch', 'foodtour', 'streekproducten'],
        'actief'       => ['activiteiten', 'outdoor', 'sport', 'actief', 'fietsen', 'wandelen', 'varen', 'boot', 'escape-room', 'games'],
        'kidsproof'    => ['kinderen', 'gezin', 'familie', 'kids', 'kidsproof', 'speeltuin', 'dierentuin'],
        'shoppen'      => ['winkelen', 'shoppen', 'markt', 'shops', 'winkelstraat'],
        'verrassing'   => [], // Empty means random selection from all categories
        'verras'       => [], // Alias for verrassing
        'relaxed'      => ['wellness', 'spa', 'relaxen', 'terras', 'park'],
        'romantisch'   => ['romantisch', 'bootje', 'diner', 'wijn', 'sunset'],
    ];

    /**
     * Time slots for different duration types.
     * Defines start time, end time, and recommended number of activities.
     */
    private const DURATION_SLOTS = [
        'ochtend'  => [
            'start'       => '09:30',
            'end'         => '12:30',
            'activities'  => 2,
            'include_food'=> false,
        ],
        'middag'   => [
            'start'       => '12:00',
            'end'         => '17:30',
            'activities'  => 3,
            'include_food'=> true,
        ],
        'avond'    => [
            'start'       => '17:00',
            'end'         => '22:00',
            'activities'  => 2,
            'include_food'=> true,
        ],
        'hele-dag' => [
            'start'       => '09:30',
            'end'         => '21:00',
            'activities'  => 5,
            'include_food'=> true,
        ],
        'weekend'  => [
            'start'       => '10:00',
            'end'         => '20:00',
            'activities'  => 6,
            'include_food'=> true,
        ],
    ];

    /**
     * Group type preferences for activity selection.
     * Defines which categories are preferred/excluded per group.
     */
    private const GROUP_PREFERENCES = [
        'familie'    => ['prefer' => ['kinderen', 'gezin', 'kidsproof'], 'exclude' => ['nachtclub', 'bar']],
        'gezin'      => ['prefer' => ['kinderen', 'gezin', 'kidsproof'], 'exclude' => ['nachtclub', 'bar']],
        'vrienden'   => ['prefer' => ['activiteiten', 'games', 'escape-room', 'proeverijen'], 'exclude' => []],
        'bedrijf'    => ['prefer' => ['teambuilding', 'rondleidingen', 'proeverijen'], 'exclude' => ['kinderen']],
        'collegas'   => ['prefer' => ['teambuilding', 'rondleidingen', 'proeverijen'], 'exclude' => ['kinderen']],
        'school'     => ['prefer' => ['educatief', 'musea', 'rondleidingen', 'activiteiten'], 'exclude' => ['bar', 'wijn']],
        'romantisch' => ['prefer' => ['romantisch', 'bootje', 'diner', 'wijn'], 'exclude' => ['kinderen', 'games']],
        'partner'    => ['prefer' => ['romantisch', 'bootje', 'diner', 'wijn'], 'exclude' => ['kinderen', 'games']],
        'solo'       => ['prefer' => ['musea', 'wandelen', 'cultuur'], 'exclude' => ['teambuilding']],
    ];

    /**
     * Default activity duration in minutes if product doesn't specify.
     */
    private const DEFAULT_DURATION_MINUTES = 90;

    /**
     * Buffer time between activities in minutes.
     */
    private const ACTIVITY_BUFFER_MINUTES = 15;

    private ActivityService $activityService;
    /** @var array<string, array<string, mixed>|null> */
    private array $availabilityCache = [];

    public function __construct(?ActivityService $activityService = null)
    {
        $this->activityService = $activityService ?? new ActivityService();
    }

    /**
     * Generate activity suggestions based on user preferences.
     *
     * @param array<string, mixed> $preferences {
     *     @type string $date       Date in Y-m-d format
     *     @type string $duration   ochtend|middag|avond|hele-dag
     *     @type int    $participants Canonical planner participants
     *     @type string $audience   familie|vrienden|bedrijf|school|romantisch|solo
     *     @type string $vibe       Space-separated vibes: cultuur bourgondisch food actief verrassing
     * }
     *
     * @return array<string, mixed> {
     *     @type string $summary       Human-readable description
     *     @type array  $activities    Array of suggested activities with times
     *     @type array  $meta          Metadata about the suggestion
     * }
     */
    public function suggest(array $preferences): array
    {
        // Extract and normalize preferences
        $date      = $this->extractDate($preferences);
        $duration  = $this->extractDuration($preferences);
        $people    = $this->extractPeople($preferences);
        $audience  = $this->extractAudience($preferences);
        $vibes     = $this->extractVibes($preferences);
        
        // Get duration slot configuration
        $slot = self::DURATION_SLOTS[$duration] ?? self::DURATION_SLOTS['middag'];
        $targetCount = $slot['activities'];
        
        // Build category filters from vibes
        $targetCategories = $this->buildCategoryFilters($vibes, $audience);
        
        // Fetch matching products
        $products = $this->fetchMatchingProducts($targetCategories, $people);
        if (empty($products)) {
            $products = $this->fetchMatchingProducts([], $people);
        }

        // Apply group preferences to filter/sort products
        $products = $this->applyGroupPreferences($products, $audience);

        // Build availability-aware plan with fallback ladder
        $plan = $this->buildAvailabilityPlan($products, $date, $slot, $people, $vibes, $targetCount, $audience);
        $activities = $plan['activities'];
        
        // Generate summary
        $summary = $this->generateSummary($activities, $duration, $audience, $vibes);
        
        if (function_exists('error_log') && ! empty($plan['fallbacks'])) {
            error_log('[SBDP][DayPlanner] availability fallbacks: ' . implode(', ', $plan['fallbacks']));
        }
        if (function_exists('error_log') && empty($activities)) {
            error_log('[SBDP][DayPlanner] no available activities for date ' . $date);
        }

        return [
            'summary'    => $summary,
            'activities' => $activities,
            'meta'       => [
                'preferences'  => $preferences,
                'duration'     => $duration,
                'audience'     => $audience,
                'vibes'        => $vibes,
                'categories'   => $targetCategories,
                'total_found'  => count($products),
                'selected'     => count($activities),
                'fallbacks'    => $plan['fallbacks'],
            ],
        ];
    }

    /**
     * Extract date from preferences, default to today.
     */
    private function extractDate(array $preferences): string
    {
        $date = $preferences['date'] ?? '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        return wp_date('Y-m-d') ?: date('Y-m-d');
    }

    /**
     * Extract and validate duration.
     */
    private function extractDuration(array $preferences): string
    {
        $duration = strtolower($preferences['duration'] ?? 'middag');
        return isset(self::DURATION_SLOTS[$duration]) ? $duration : 'middag';
    }

    /**
     * Extract number of people.
     */
    private function extractPeople(array $preferences): int
    {
        $participants = $preferences['participants'] ?? null;
        if (! is_numeric($participants)) {
            throw new \InvalidArgumentException(__('Vul eerst een geldig aantal deelnemers in.', 'sbdp'));
        }

        $participants = (int) $participants;
        if ($participants < 1 || $participants > 100) {
            throw new \InvalidArgumentException(__('Het aantal deelnemers moet tussen 1 en 100 liggen.', 'sbdp'));
        }

        return $participants;
    }

    /**
     * Extract and normalize audience/group type.
     */
    private function extractAudience(array $preferences): string
    {
        $audience = strtolower($preferences['audience'] ?? $preferences['group'] ?? '');
        
        // Normalize aliases
        $aliases = [
            'gezin'      => 'familie',
            'collegas'   => 'bedrijf',
            'partner'    => 'romantisch',
        ];
        
        return $aliases[$audience] ?? $audience;
    }

    /**
     * Extract vibes as array.
     */
    private function extractVibes(array $preferences): array
    {
        $vibe = $preferences['vibe'] ?? $preferences['vibes'] ?? $preferences['preferences'] ?? '';
        
        if (is_array($vibe)) {
            return array_map('strtolower', array_filter($vibe));
        }
        
        $tokens = preg_split('/[\s,]+/', strtolower((string) $vibe)) ?: [];
        return array_values(array_filter($tokens));
    }

    /**
     * Build category filter list from vibes and audience.
     *
     * @return string[]
     */
    private function buildCategoryFilters(array $vibes, string $audience): array
    {
        $categories = [];
        
        // Add categories from each vibe
        foreach ($vibes as $vibe) {
            $mapped = self::VIBE_CATEGORY_MAP[$vibe] ?? [];
            $categories = array_merge($categories, $mapped);
        }
        
        // Add preferred categories from audience
        if ($audience && isset(self::GROUP_PREFERENCES[$audience])) {
            $groupPrefs = self::GROUP_PREFERENCES[$audience];
            $categories = array_merge($categories, $groupPrefs['prefer'] ?? []);
        }
        
        return array_values(array_unique($categories));
    }

    /**
     * Fetch products matching the category filters.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchMatchingProducts(array $categories, int $people): array
    {
        $filters = [
            'limit' => 50,
        ];
        
        if (!empty($categories)) {
            $filters['categories'] = $categories;
        }
        
        try {
            $products = $this->activityService->listActivities($filters, false);
        } catch (\Throwable $e) {
            return [];
        }
        
        // Filter by capacity if specified
        return array_filter($products, function ($product) use ($people) {
            $minCapacity = (int) ($product['min_capacity'] ?? 1);
            $maxCapacity = (int) ($product['max_capacity'] ?? 100);
            return $people >= $minCapacity && $people <= $maxCapacity;
        });
    }

    /**
     * Apply group preferences to filter and sort products.
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyGroupPreferences(array $products, string $audience): array
    {
        if (!$audience || !isset(self::GROUP_PREFERENCES[$audience])) {
            return $products;
        }
        
        $prefs = self::GROUP_PREFERENCES[$audience];
        $preferred = $prefs['prefer'] ?? [];
        $excluded = $prefs['exclude'] ?? [];
        
        // Filter out excluded categories
        if (!empty($excluded)) {
            $products = array_filter($products, function ($product) use ($excluded) {
                $cats = $product['category_slugs'] ?? $product['categories'] ?? [];
                if (!is_array($cats)) {
                    return true;
                }
                foreach ($cats as $cat) {
                    if (in_array(strtolower($cat), $excluded, true)) {
                        return false;
                    }
                }
                return true;
            });
        }
        
        // Sort by preference score
        usort($products, function ($a, $b) use ($preferred) {
            $scoreA = $this->calculatePreferenceScore($a, $preferred);
            $scoreB = $this->calculatePreferenceScore($b, $preferred);
            return $scoreB <=> $scoreA;
        });
        
        return array_values($products);
    }

    /**
     * Calculate preference score for sorting.
     */
    private function calculatePreferenceScore(array $product, array $preferred): int
    {
        $cats = $product['category_slugs'] ?? $product['categories'] ?? [];
        if (!is_array($cats)) {
            return 0;
        }
        
        $score = 0;
        foreach ($cats as $cat) {
            if (in_array(strtolower($cat), $preferred, true)) {
                $score += 10;
            }
        }
        
        // Boost products with images
        if (!empty($product['image'])) {
            $score += 2;
        }
        
        // Boost products with pricing
        if (!empty($product['price']) && $product['price'] > 0) {
            $score += 1;
        }
        
        return $score;
    }

    /**
     * Select diverse activities avoiding duplicates in same category.
     *
     * @return array<int, array<string, mixed>>
     */
    private function selectDiverseActivities(array $products, int $targetCount, array $vibes, bool $includeFood): array
    {
        $selected = [];
        $usedCategories = [];
        $usedIds = [];
        
        // If includeFood is true, try to add a food/restaurant activity
        if ($includeFood && $targetCount >= 2) {
            $foodProduct = $this->findFoodActivity($products, $usedIds);
            if ($foodProduct) {
                $selected[] = $foodProduct;
                $usedIds[$foodProduct['id']] = true;
                $usedCategories = array_merge($usedCategories, $foodProduct['category_slugs'] ?? []);
            }
        }
        
        // Fill remaining slots with diverse activities
        foreach ($products as $product) {
            if (count($selected) >= $targetCount) {
                break;
            }
            
            $id = $product['id'] ?? 0;
            if (isset($usedIds[$id])) {
                continue;
            }
            
            // Check for category diversity
            $productCats = $product['category_slugs'] ?? $product['categories'] ?? [];
            $isDiverse = true;
            
            if (is_array($productCats)) {
                foreach ($productCats as $cat) {
                    if (in_array(strtolower($cat), $usedCategories, true)) {
                        $isDiverse = false;
                        break;
                    }
                }
            }
            
            // If we need more activities, allow less diversity
            $remainingSlots = $targetCount - count($selected);
            $remainingProducts = count($products) - array_search($product, $products);
            
            if ($isDiverse || $remainingProducts <= $remainingSlots) {
                $selected[] = $product;
                $usedIds[$id] = true;
                if (is_array($productCats)) {
                    $usedCategories = array_merge($usedCategories, array_map('strtolower', $productCats));
                }
            }
        }
        
        return $selected;
    }

    /**
     * Find a food/restaurant activity for the itinerary.
     */
    private function findFoodActivity(array $products, array $usedIds): ?array
    {
        $foodCategories = ['restaurants', 'food', 'lunch', 'diner', 'eten-drinken', 'proeverijen'];
        
        foreach ($products as $product) {
            $id = $product['id'] ?? 0;
            if (isset($usedIds[$id])) {
                continue;
            }
            
            $cats = $product['category_slugs'] ?? $product['categories'] ?? [];
            if (!is_array($cats)) {
                continue;
            }
            
            foreach ($cats as $cat) {
                if (in_array(strtolower($cat), $foodCategories, true)) {
                    return $product;
                }
            }
        }
        
        return null;
    }

    /**
     * Build a timed itinerary from selected activities.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTimedItinerary(array $selected, string $date, array $slot, int $people): array
    {
        $activities = [];
        $windowStart = $this->parseTime($slot['start']);
        $windowEnd = $this->parseTime($slot['end']);
        $currentTime = $windowStart;
        
        if (count($selected) === 0) {
            return [];
        }
        
        foreach ($selected as $index => $product) {
            $productId = $product['id'] ?? 0;
            $productName = $product['name'] ?? $product['title'] ?? 'Activiteit';
            
            // Use product duration if available, otherwise default
            $durationMinutes = $product['duration']['minutes']
                ?? $product['duration_minutes']
                ?? self::DEFAULT_DURATION_MINUTES;
            $durationMinutes = max(30, min(180, (int) $durationMinutes));

            // Resolve availability slots for this product on date
            $availability = $this->resolveAvailabilitySlots($productId, $date, $people, $product);
            if ($availability === null || empty($availability['slots'])) {
                continue;
            }

            $startOptions = $this->buildStartOptions($availability['slots'], $durationMinutes);
            if ($startOptions === []) {
                continue;
            }

            // Find first available start within window and after currentTime
            $targetStart = max($currentTime, $windowStart);
            $startMinutes = $this->findFirstStartAtOrAfter($startOptions, $targetStart, $windowEnd, $durationMinutes);
            if ($startMinutes === null) {
                continue;
            }
            
            $startIso = $date . 'T' . $this->formatTime($startMinutes) . ':00';
            $endMinutes = $startMinutes + $durationMinutes;
            $endIso = $date . 'T' . $this->formatTime($endMinutes) . ':00';
            
            $activities[] = [
                'uid'              => 'suggested-' . ($index + 1) . '-' . $productId,
                'product_id'       => $productId,
                'product_name'     => $productName,
                'start'            => $startIso,
                'end'              => $endIso,
                'duration_minutes' => $durationMinutes,
                'quantity'         => $people,
                'capacity'         => $people,
                'suggested'        => true,
                'image'            => $product['image'] ?? '',
                'price'            => $product['price'] ?? 0,
                'categories'       => $product['category_slugs'] ?? [],
                'resource_id'      => $availability['resource_id'] ?? null,
            ];
            
            // Move to next slot
            $currentTime = $endMinutes + self::ACTIVITY_BUFFER_MINUTES;
            
            // Don't exceed end time
            if ($currentTime >= $windowEnd) {
                break;
            }
        }
        
        return $activities;
    }

    /**
     * Build availability-aware plan with fallback strategy.
     *
     * @return array{activities: array<int, array<string, mixed>>, fallbacks: array<int, string>}
     */
    private function buildAvailabilityPlan(array $products, string $date, array $slot, int $people, array $vibes, int $targetCount, string $audience): array
    {
        $fallbacks = [];
        $activities = [];

        $availableProducts = $this->filterAvailableProducts($products, $date, $people, max(20, $targetCount * 6));
        $attempts = [
            ['count' => $targetCount, 'products' => $availableProducts, 'label' => 'primary'],
            ['count' => max(1, (int) floor($targetCount * 0.75)), 'products' => $availableProducts, 'label' => 'reduce_count'],
            ['count' => max(1, (int) floor($targetCount * 0.5)), 'products' => $availableProducts, 'label' => 'reduce_count_more'],
        ];

        foreach ($attempts as $attempt) {
            $selected = $this->selectDiverseActivities($attempt['products'], $attempt['count'], $vibes, $slot['include_food']);
            $activities = $this->buildTimedItinerary($selected, $date, $slot, $people);
            if (! empty($activities)) {
                if ($attempt['label'] !== 'primary') {
                    $fallbacks[] = $attempt['label'];
                }
                return ['activities' => $activities, 'fallbacks' => $fallbacks];
            }
        }

        // Broaden search: ignore vibe categories (all products)
        $broader = $this->fetchMatchingProducts([], $people);
        if (! empty($broader)) {
            $broader = $this->applyGroupPreferences($broader, $audience);
            $broader = $this->filterAvailableProducts($broader, $date, $people, max(20, $targetCount * 8));
            $fallbacks[] = 'broaden_categories';
            foreach ([max(1, (int) floor($targetCount * 0.6)), 1] as $count) {
                $selected = $this->selectDiverseActivities($broader, $count, [], $slot['include_food']);
                $activities = $this->buildTimedItinerary($selected, $date, $slot, $people);
                if (! empty($activities)) {
                    if ($count < $targetCount) {
                        $fallbacks[] = 'reduced_count_final';
                    }
                    return ['activities' => $activities, 'fallbacks' => $fallbacks];
                }
            }
        }

        $fallbacks[] = 'no_available_slots';
        return ['activities' => [], 'fallbacks' => $fallbacks];
    }

    /**
     * Resolve availability slots for a product/date/people combination.
     *
     * @return array<string, mixed>|null
     */
    private function resolveAvailabilitySlots(int $productId, string $date, int $people, array $product): ?array
    {
        if ($productId <= 0 || $date === '') {
            return null;
        }

        $cacheKey = $productId . '|' . $date . '|' . $people . '|' . (string) ($product['resource_id'] ?? 0);
        if (array_key_exists($cacheKey, $this->availabilityCache)) {
            return $this->availabilityCache[$cacheKey];
        }

        $resourceIds = ProductMeta::get_resource_ids($productId);
        $primaryResource = (int) ($product['resource_id'] ?? 0);
        if ($primaryResource > 0 && ! in_array($primaryResource, $resourceIds, true)) {
            array_unshift($resourceIds, $primaryResource);
        }
        if ($resourceIds === []) {
            $resourceIds = $primaryResource > 0 ? [$primaryResource] : [0];
        }

        foreach ($resourceIds as $resourceId) {
            $request = new WP_REST_Request('GET');
            $request->set_param('product_id', $productId);
            $request->set_param('resource_id', (int) $resourceId);
            $request->set_param('date', $date);

            $payload = RestService::availability_slots($request);
            if ($payload instanceof \WP_Error) {
                continue;
            }
            if ($payload instanceof \WP_REST_Response) {
                $payload = $payload->get_data();
            }
            if (! is_array($payload)) {
                continue;
            }

            $slots = isset($payload['slots']) && is_array($payload['slots']) ? $payload['slots'] : [];
            if ($slots !== []) {
                $result = [
                    'resource_id' => (int) ($payload['resource_id'] ?? $resourceId),
                    'slots'       => $slots,
                ];
                $this->availabilityCache[$cacheKey] = $result;
                return $result;
            }
        }

        $this->availabilityCache[$cacheKey] = null;
        return null;
    }

    /**
     * Filter products to those with at least one available slot.
     *
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private function filterAvailableProducts(array $products, string $date, int $people, int $limit): array
    {
        if ($products === array()) {
            return $products;
        }

        $out = [];
        $checked = 0;
        foreach ($products as $product) {
            if ($checked >= $limit) {
                break;
            }
            $productId = (int) ($product['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $checked++;
            $availability = $this->resolveAvailabilitySlots($productId, $date, $people, $product);
            if ($availability !== null && ! empty($availability['slots'])) {
                $out[] = $product;
            }
        }

        return $out;
    }

    /**
     * Build valid start times (in minutes) for the given duration.
     *
     * @param array<int, array<string, mixed>> $slots
     * @return int[]
     */
    private function buildStartOptions(array $slots, int $durationMinutes): array
    {
        if ($slots === []) {
            return [];
        }

        $first = $slots[0] ?? [];
        $slotLength = 0;
        if (isset($first['start'], $first['end'])) {
            $slotLength = $this->parseTime((string) $first['end']) - $this->parseTime((string) $first['start']);
        }
        if ($slotLength <= 0) {
            $slotLength = max(15, min(60, $durationMinutes));
        }

        $required = max(1, (int) ceil($durationMinutes / $slotLength));
        $starts = [];
        $startSet = [];

        foreach ($slots as $slot) {
            $start = isset($slot['start']) ? (string) $slot['start'] : '';
            if ($start === '') {
                continue;
            }
            $startMinutes = $this->parseTime($start);
            if ($startMinutes >= 0) {
                $startSet[$startMinutes] = true;
                $starts[] = $startMinutes;
            }
        }

        sort($starts);

        $valid = [];
        foreach ($starts as $startMinutes) {
            $ok = true;
            for ($i = 1; $i < $required; $i++) {
                $next = $startMinutes + ($i * $slotLength);
                if (! isset($startSet[$next])) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $valid[] = $startMinutes;
            }
        }

        return $valid;
    }

    /**
     * Find the first start option within bounds.
     */
    private function findFirstStartAtOrAfter(array $starts, int $minStart, int $windowEnd, int $durationMinutes): ?int
    {
        foreach ($starts as $start) {
            if ($start < $minStart) {
                continue;
            }
            if (($start + $durationMinutes) > $windowEnd) {
                continue;
            }
            return $start;
        }
        return null;
    }

    /**
     * Parse time string to minutes from midnight.
     */
    private function parseTime(string $time): int
    {
        $parts = explode(':', $time);
        $hours = (int) ($parts[0] ?? 0);
        $minutes = (int) ($parts[1] ?? 0);
        return $hours * 60 + $minutes;
    }

    /**
     * Format minutes from midnight to HH:MM.
     */
    private function formatTime(int $minutes): string
    {
        $hours = (int) floor($minutes / 60) % 24;
        $mins = $minutes % 60;
        return sprintf('%02d:%02d', $hours, $mins);
    }

    /**
     * Generate a human-readable summary.
     */
    private function generateSummary(array $activities, string $duration, string $audience, array $vibes): string
    {
        $count = count($activities);
        
        if ($count === 0) {
            return __('Geen activiteiten gevonden die aan je voorkeuren voldoen. Probeer andere criteria.', 'sbdp');
        }
        
        $durationLabels = [
            'ochtend'  => 'ochtend',
            'middag'   => 'middag',
            'avond'    => 'avond',
            'hele-dag' => 'hele dag',
            'weekend'  => 'weekend',
        ];
        $durationLabel = $durationLabels[$duration] ?? 'dag';
        
        $audienceLabels = [
            'familie'    => 'het gezin',
            'vrienden'   => 'je vriendengroep',
            'bedrijf'    => 'je team',
            'school'     => 'de klas',
            'romantisch' => 'jullie samen',
            'solo'       => 'jou',
        ];
        $audienceLabel = $audienceLabels[$audience] ?? 'jullie';
        
        $vibeLabel = '';
        if (!empty($vibes) && !in_array('verras', $vibes) && !in_array('verrassing', $vibes)) {
            $vibeLabel = ' met focus op ' . implode(' & ', array_slice($vibes, 0, 2));
        }
        
        return sprintf(
            __('We hebben %d activiteiten geselecteerd voor een perfecte %s voor %s%s.', 'sbdp'),
            $count,
            $durationLabel,
            $audienceLabel,
            $vibeLabel
        );
    }
}
