<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

use InvalidArgumentException;

final class PlanService
{
    private PlanRepository $repository;

    private PriceEngine $pricing;

    private AvailabilityService $availability;

    private AiSuggestionService $ai;

    private ActivityService $activities;

    public function __construct(
        ?PlanRepository $repository = null,
        ?PriceEngine $pricing = null,
        ?AvailabilityService $availability = null,
        ?AiSuggestionService $ai = null,
        ?ActivityService $activities = null
    ) {
        $this->repository   = $repository ?? new PlanRepository();
        $this->pricing      = $pricing ?? new PriceEngine();
        $this->availability = $availability ?? new AvailabilityService();
        $this->ai           = $ai ?? new AiSuggestionService();
        $this->activities   = $activities ?? new ActivityService();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createPlan(array $payload, int $ownerId): array
    {
        $plan = $this->normalisePlan($payload);

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

        return $this->enrichPlan(
            $this->repository->update($planId, $plan)
        );
    }

    public function getPlan(int $planId): array
    {
        return $this->enrichPlan($this->repository->get($planId));
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
        // Placeholder for batch booking service integration.
        return [
            'plan_id' => $planId,
            'status'  => 'queued',
            'message' => __('Batch booking is queued for processing.', 'sbdp'),
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
                                $slot['people'] = isset($slot['people']) ? max(1, (int) $slot['people']) : 1;
                                $slot['product_id'] = isset($slot['product_id'])
                                    ? (int) $slot['product_id']
                                    : (isset($slot['activity_id']) ? (int) $slot['activity_id'] : 0);
                                $slot['resource_id'] = isset($slot['resource_id']) ? (int) $slot['resource_id'] : 0;
                                $slot['price_pp'] = isset($slot['price_pp']) ? (float) $slot['price_pp'] : 0.0;
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
            $slot['duration_minutes'] = $duration !== null && $duration > 0 ? $duration : 60;
        }

        return $slot;
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

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?/', $trimmed) === 1) {
            $dt = \date_create($trimmed);
            if ($dt instanceof \DateTimeInterface) {
                return $dt->format('H:i');
            }
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
            'autosave'          => true,
            'currency'          => 'EUR',
            'locale'            => 'nl-NL',
            'theme'             => 'light',
        ];

        $sanitised = array_merge($default, $settings);

        $sanitised['time_step_minutes'] = max(5, (int) $sanitised['time_step_minutes']);
        $sanitised['allow_multi_day']   = (bool) $sanitised['allow_multi_day'];
        $sanitised['default_day_count'] = max(1, (int) $sanitised['default_day_count']);
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
        return $this->ai->suggest($preferences);
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
