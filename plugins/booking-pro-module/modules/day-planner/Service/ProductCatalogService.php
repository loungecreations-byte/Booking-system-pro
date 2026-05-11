<?php
declare(strict_types=1);
namespace BSP\DayPlanner\Service;
use BPM\Core\ProductSettings;
use InvalidArgumentException;
use function is_array;
use function array_values;
use function array_filter;
use function array_map;
use function array_merge;
use function class_exists;
use function strtolower;
use function trim;
use function preg_match;
use function substr;
use function max;
/**
 * Provide planner-ready product payloads derived from the activity catalogue.
 *
 * This service wraps the activity service to avoid leaking example entries and
 * enriches the payload with helper fields consumed by the modern planner UI.
 */
final class ProductCatalogService
{
    private ActivityService $activities;
    private const FALLBACK_DURATION_MINUTES = 90;
    private const FALLBACK_CAPACITY = 10;
    private const FALLBACK_START_TIME = '09:00';
    private const FALLBACK_END_TIME = '21:00';
    private const FALLBACK_RESOURCE_ID = 0;
    private const FALLBACK_RESOURCE_TITLE = 'General availability';
    /**
     * Days accepted by planner (lowercase).
     *
     * @var string[]
     */
    private const FALLBACK_AVAILABLE_DAYS = array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun');
    public function __construct(?ActivityService $activities = null)
    {
        $this->activities = $activities ?? new ActivityService();
    }
    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function listProducts(array $filters = []): array
    {
        $products = $this->activities->listActivities($filters, false);

        if (($filters['include_arrangements'] ?? true) !== false) {
            $products = array_merge($products, $this->loadArrangementProducts($filters));
        }

        $indexed = array();
        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $normalized = $this->normaliseProduct($product);
            $identity = $this->resolveProductIdentity($normalized);
            if ($identity !== null) {
                $indexed[$identity] = $normalized;
                continue;
            }

            $indexed[] = $normalized;
        }

        return array_values($indexed);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadArrangementProducts(array $filters): array
    {
        if (! class_exists('\SBDP\Modules\Arrangements\Domain\ArrangementPlannerService')) {
            return array();
        }

        $service = new \SBDP\Modules\Arrangements\Domain\ArrangementPlannerService();
        $items = $service->listPlannerProducts($filters);

        return is_array($items) ? $items : array();
    }

    /**
     * @param array<string, mixed> $product
     */
    private function resolveProductIdentity(array $product): ?string
    {
        $id = isset($product['id']) ? (int) $product['id'] : 0;
        if ($id > 0) {
            return 'id:' . $id;
        }

        $productId = isset($product['product_id']) ? (int) $product['product_id'] : 0;
        if ($productId > 0) {
            return 'product:' . $productId;
        }

        $slug = isset($product['slug']) ? trim((string) $product['slug']) : '';
        return $slug !== '' ? 'slug:' . $slug : null;
    }
    /**
     * @param array<string, mixed> $product
     *
     * @return array<string, mixed>
     */
    private function normaliseProduct(array $product): array
    {
        $product = $this->applyProductSettings($product);
        $product['kind']       = $product['kind'] ?? 'product';
        $product['type']       = $product['type'] ?? 'product';
        $product['name']       = $product['name'] ?? ($product['title'] ?? '');
        $product['price_pp']   = isset($product['price_pp'])
            ? (float) $product['price_pp']
            : (float) ($product['pricing']['per_person'] ?? 0.0);
        $product['pricing']    = $this->ensurePricingCurrency($product);
        if (! isset($product['resource_id']) && isset($product['resources']) && is_array($product['resources'])) {
            $product['resource_id'] = $this->resolvePrimaryResourceId($product['resources']);
        }
        $availability = is_array($product['availability'] ?? null) ? $product['availability'] : array();
        $product['availability_windows'] = $this->buildAvailabilityWindows($availability);
        if (empty($product['resources']) || ! is_array($product['resources'])) {
            $product['resources'] = array(
                array(
                    'id'       => self::FALLBACK_RESOURCE_ID,
                    'title'    => self::FALLBACK_RESOURCE_TITLE,
                    'capacity' => $product['people']['max'] ?? self::FALLBACK_CAPACITY,
                    'primary'  => true,
                ),
            );
        }
        if (! isset($product['resource_id'])) {
            $product['resource_id'] = $this->resolvePrimaryResourceId($product['resources']);
        }
        return $product;
    }
    /**
     * @param array<string, mixed> $product
     *
     * @return array<string, mixed>
     */
    private function ensurePricingCurrency(array $product): array
    {
        $pricing = is_array($product['pricing'] ?? null) ? $product['pricing'] : array();
        if (! isset($pricing['currency']) && isset($product['currency'])) {
            $pricing['currency'] = (string) $product['currency'];
        }
        return $pricing;
    }
    /**
     * @param array<int, array<string, mixed>> $resources
     */
    private function resolvePrimaryResourceId(array $resources): ?int
    {
        foreach ($resources as $resource) {
            if (! is_array($resource)) {
                continue;
            }
            if (isset($resource['primary']) && $resource['primary']) {
                return isset($resource['id']) ? (int) $resource['id'] : null;
            }
        }
        foreach ($resources as $resource) {
            if (is_array($resource) && isset($resource['id'])) {
                return (int) $resource['id'];
            }
        }
        return null;
    }
    /**
     * @param array<string, mixed> $availability
     *
     * @return array<string, array<int, array<string, string>>>
     */
    private function buildAvailabilityWindows(array $availability): array
    {
        $windows = array();
        $defaultHours = $availability['default_hours'] ?? array();
        if (is_array($defaultHours)) {
            foreach ($defaultHours as $day => $slots) {
                if (! is_array($slots)) {
                    continue;
                }
                foreach ($slots as $slot) {
                    if (! is_array($slot)) {
                        continue;
                    }
                    if (! isset($slot['start'], $slot['end'])) {
                        continue;
                    }
                    $windows[] = array(
                        'day'   => (string) $day,
                        'start' => (string) $slot['start'],
                        'end'   => (string) $slot['end'],
                    );
                }
            }
        }
        return array(
            'default' => $windows,
            'rules'   => is_array($availability['rules'] ?? null) ? $availability['rules'] : array(),
        );
    }
    /**
     * @param array<string, mixed> $product
     *
     * @return array<string, mixed>
     */
    private function applyProductSettings(array $product): array
    {
        $productId = isset($product['product_id']) ? (int) $product['product_id'] : (int) ($product['id'] ?? 0);
        if ($productId <= 0) {
            return $product;
        }
        $settings = $this->loadProductSettings($productId);
        $settings = $settings ?? $this->fallbackSettings();
        $duration = (int) $settings['duration_minutes'];
        $product['duration_minutes'] = $duration;
        $product['duration'] = array(
            'value'   => $duration,
            'unit'    => 'minutes',
            'minutes' => $duration,
        );
        $capacity = (int) $settings['capacity'];
        $existingPeople = is_array($product['people'] ?? null) ? $product['people'] : array();
        $product['people'] = array(
            'enabled'           => true,
            'min'               => max(1, (int) ($existingPeople['min'] ?? 1)),
            'max'               => $capacity,
            'count_as_bookings' => true,
        );
        $segmentsMeta = get_post_meta($productId, '_sbdp_segments', true);
        $segments     = array_values(
            array_filter(
                array_map(
                    static fn ($value) => \sanitize_key((string) $value),
                    is_array($segmentsMeta) ? $segmentsMeta : (array) $segmentsMeta
                ),
                static fn ($value) => $value !== ''
            )
        );
        $product['segments'] = $segments;
        $availability = is_array($product['availability'] ?? null) ? $product['availability'] : array();
        if (empty($availability['default_hours'])) {
            $defaultHours = $this->buildDefaultHoursFromSettings($settings);
            if ($defaultHours !== array()) {
                $availability['default_hours'] = $defaultHours;
            }
        }
        if (! isset($availability['rules'])) {
            $availability['rules'] = array();
        }
        if ($availability['rules'] === array() && ! empty($settings['rules'])) {
            $availability['rules'] = $settings['rules'];
        }
        if ($availability !== array()) {
            $product['availability'] = $availability;
        }
        if (! isset($product['availability_windows']) || empty($product['availability_windows']['default'])) {
            $windows = $this->buildAvailabilityWindows($availability);
            if (! empty($windows['default'])) {
                $product['availability_windows'] = $windows;
            }
        }
        $defaultStartTime = $this->resolveDefaultStartTime($product, $settings);
        if ($defaultStartTime !== '') {
            $product['default_start_time'] = $defaultStartTime;
            $defaultStart = is_array($product['default_start'] ?? null) ? $product['default_start'] : array();
            $defaultStart['time'] = $defaultStartTime;
            $defaultStart['date'] = $defaultStart['date'] ?? '';
            $product['default_start'] = $defaultStart;
        }
        return $product;
    }
    /**
     * @return array<string, mixed>
     */
    private function normaliseSettings(?array $settings): array
    {
        $settings = is_array($settings) ? $settings : array();
        $duration = (int) ($settings['duration_minutes'] ?? 0);
        if ($duration <= 0) {
            $duration = self::FALLBACK_DURATION_MINUTES;
        }
        $capacity = (int) ($settings['capacity'] ?? 0);
        if ($capacity <= 0) {
            $capacity = self::FALLBACK_CAPACITY;
        }
        $daysRaw = is_array($settings['available_days'] ?? null) ? $settings['available_days'] : array();
        $days = array_values(
            array_filter(
                array_map(
                    static function ($day) {
                        $value = strtolower(trim((string) $day));
                        return $value !== '' ? $value : null;
                    },
                    $daysRaw
                )
            )
        );
        if ($days === array()) {
            $days = self::FALLBACK_AVAILABLE_DAYS;
        }
        $slotsRaw = is_array($settings['time_slots'] ?? null) ? $settings['time_slots'] : array();
        $slots = array();
        foreach ($slotsRaw as $slot) {
            if (! is_array($slot)) {
                continue;
            }
            $start = isset($slot['start']) ? $this->sanitiseTime((string) $slot['start']) : '';
            $end   = isset($slot['end']) ? $this->sanitiseTime((string) $slot['end']) : '';
            if ($start === '' || $end === '') {
                continue;
            }
            $slots[] = array(
                'start' => $start,
                'end'   => $end,
            );
        }
        if ($slots === array()) {
            $slots[] = array(
                'start' => self::FALLBACK_START_TIME,
                'end'   => self::FALLBACK_END_TIME,
            );
        }
        $rules = array();
        if (isset($settings['rules']) && is_array($settings['rules'])) {
            $rules = $settings['rules'];
        } elseif (isset($settings['availability_rules']) && is_array($settings['availability_rules'])) {
            $rules = $settings['availability_rules'];
        }
        return array(
            'duration_minutes' => $duration,
            'capacity'         => $capacity,
            'available_days'   => $days,
            'time_slots'       => $slots,
            'rules'            => $rules,
        );
    }
    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, array<int, array<string, string>>>
     */
    private function buildDefaultHoursFromSettings(array $settings): array
    {
        $days  = is_array($settings['available_days'] ?? null) ? $settings['available_days'] : array();
        $slots = is_array($settings['time_slots'] ?? null) ? $settings['time_slots'] : array();
        $defaultHours = array();
        foreach ($days as $day) {
            $defaultHours[$day] = array();
            foreach ($slots as $slot) {
                $defaultHours[$day][] = array(
                    'start' => (string) $slot['start'],
                    'end'   => (string) $slot['end'],
                );
            }
        }
        return $defaultHours;
    }
    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $settings
     */
    private function resolveDefaultStartTime(array $product, array $settings): string
    {
        $existing = isset($product['default_start_time']) ? (string) $product['default_start_time'] : '';
        if ($existing !== '') {
            return $existing;
        }
        $slots = is_array($settings['time_slots'] ?? null) ? $settings['time_slots'] : array();
        $first = $slots[0]['start'] ?? '';
        return $this->sanitiseTime((string) $first);
    }
    private function sanitiseTime(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if (preg_match('/^\d{2}:\d{2}$/', $trimmed)) {
            return $trimmed;
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $trimmed)) {
            return substr($trimmed, 0, 5);
        }
        return '';
    }
    private function loadProductSettings(int $productId): ?array
    {
        try {
            return ProductSettings::get($productId);
        } catch (InvalidArgumentException $exception) {
            return null;
        }
    }
}
