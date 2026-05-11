<?php

declare(strict_types=1);

namespace BSP\Planner\Services\Planboard;

use DateTimeImmutable;
use WP_Error;

final class PlanboardRulesService
{
    private const OPTION_KEY = 'sbdp_planboard_closure_rules';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $rules = $this->load();
        return array_values($rules);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $rules = $this->load();

        return $rules[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|WP_Error
     */
    public function create(array $payload)
    {
        $validated = PlanboardValidator::validateRule($payload);
        if ($validated instanceof WP_Error) {
            return $validated;
        }

        $id = $validated['id'];
        if (! is_string($id) || $id === '') {
            $id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('closure_', true);
            $validated['id'] = $id;
        }

        $rules = $this->load();
        $rules[$id] = $validated;
        $this->persist($rules);

        $this->emitRulesChanged('created', $validated);

        return $validated;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|WP_Error
     */
    public function update(string $id, array $payload)
    {
        $rules = $this->load();
        if (! isset($rules[$id])) {
            return new WP_Error('sbdp_planboard_rule_not_found', __('Rule not found.', 'sbdp'), array('status' => 404));
        }

        $payload['id'] = $id;
        $validated = PlanboardValidator::validateRule($payload, true);
        if ($validated instanceof WP_Error) {
            return $validated;
        }

        $rules[$id] = $validated;
        $this->persist($rules);

        $this->emitRulesChanged('updated', $validated);

        return $validated;
    }

    public function delete(string $id): bool
    {
        $rules = $this->load();
        if (! isset($rules[$id])) {
            return false;
        }

        $removed = $rules[$id];
        unset($rules[$id]);
        $this->persist($rules);

        $this->emitRulesChanged('deleted', $removed);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(string $start, string $end): array
    {
        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);

        $days = array();
        $cursor = $startDate->setTime(0, 0, 0);
        $last   = $endDate->setTime(23, 59, 59);

        $rules = $this->all();
        while ($cursor <= $last) {
            $dayKey = $cursor->format('Y-m-d');
            $days[$dayKey] = array(
                'date'   => $dayKey,
                'closed' => $this->isClosedDay($cursor, $rules),
            );
            $cursor = $cursor->modify('+1 day');
        }

        return array(
            'rules' => $rules,
            'days'  => array_values($days),
        );
    }

    public function isClosed(string $start, string $end, ?int $resourceId = null): bool
    {
        $rules = $this->all();
        $startAt = new DateTimeImmutable($start);
        $endAt = new DateTimeImmutable($end);

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            if ($resourceId !== null && isset($rule['resource_id']) && (int) $rule['resource_id'] > 0) {
                if ((int) $rule['resource_id'] !== $resourceId) {
                    continue;
                }
            }

            if (($rule['type'] ?? '') === 'one_off') {
                $ruleStart = isset($rule['start']) ? new DateTimeImmutable((string) $rule['start']) : null;
                $ruleEnd   = isset($rule['end']) ? new DateTimeImmutable((string) $rule['end']) : null;
                if ($ruleStart && $ruleEnd && $ruleEnd > $startAt && $ruleStart < $endAt) {
                    return true;
                }
                continue;
            }

            if (($rule['type'] ?? '') === 'recurring') {
                $weekday = isset($rule['weekday']) ? (int) $rule['weekday'] : -1;
                if ($weekday < 0 || $weekday > 6) {
                    continue;
                }

                if ((int) $startAt->format('w') !== $weekday) {
                    continue;
                }

                $startTime = (string) ($rule['start_time'] ?? '');
                $endTime   = (string) ($rule['end_time'] ?? '');
                if ($startTime === '' || $endTime === '') {
                    continue;
                }

                $windowStart = new DateTimeImmutable($startAt->format('Y-m-d') . ' ' . $startTime);
                $windowEnd   = new DateTimeImmutable($startAt->format('Y-m-d') . ' ' . $endTime);

                if ($windowEnd > $startAt && $windowStart < $endAt) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function isClosedDay(DateTimeImmutable $date, array $rules): bool
    {
        $start = $date->setTime(0, 0, 0);
        $end   = $date->setTime(23, 59, 59);

        foreach ($rules as $rule) {
            if (($rule['type'] ?? '') === 'one_off') {
                $ruleStart = isset($rule['start']) ? new DateTimeImmutable((string) $rule['start']) : null;
                $ruleEnd   = isset($rule['end']) ? new DateTimeImmutable((string) $rule['end']) : null;
                if ($ruleStart && $ruleEnd && $ruleEnd > $start && $ruleStart < $end) {
                    return true;
                }
                continue;
            }

            if (($rule['type'] ?? '') === 'recurring') {
                $weekday = isset($rule['weekday']) ? (int) $rule['weekday'] : -1;
                if ($weekday < 0 || $weekday > 6) {
                    continue;
                }

                if ((int) $date->format('w') !== $weekday) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        if (! function_exists('get_option')) {
            return array();
        }

        $rules = get_option(self::OPTION_KEY, array());
        if (! is_array($rules)) {
            return array();
        }

        $normalized = array();
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $id = isset($rule['id']) ? (string) $rule['id'] : '';
            if ($id === '') {
                continue;
            }

            $normalized[$id] = $rule;
        }

        return $normalized;
    }

    /**
     * @param array<string, array<string, mixed>> $rules
     */
    private function persist(array $rules): void
    {
        if (function_exists('update_option')) {
            update_option(self::OPTION_KEY, array_values($rules), false);
        }
    }

    private function emitRulesChanged(string $action, array $rule): void
    {
        if (function_exists('do_action')) {
            do_action(
                'sbdp/planboard/rules/changed',
                array(
                    'action' => $action,
                    'rule'   => $rule,
                    'site_id'=> function_exists('get_current_blog_id') ? get_current_blog_id() : 0,
                )
            );
        }
    }
}
