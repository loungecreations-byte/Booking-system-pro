<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

final class PlannerEventLogger
{
    private const KNOWN_EVENTS = [
        'session_started',
        'intent_detected',
        'primary_selected',
        'primary_changed',
        'plan_built',
        'cta_rendered',
        'cta_clicked',
        'handoff_requested',
        'error_occurred',
    ];

    /**
     * @param array<string, mixed> $payload
     */
    public function log(string $eventName, array $payload = [], string $severity = 'info'): void
    {
        $eventName = trim($eventName);
        if ($eventName === '') {
            $eventName = 'unknown_event';
        }
        if (! in_array($eventName, self::KNOWN_EVENTS, true)) {
            $eventName = 'custom_' . $eventName;
        }

        $entry = [
            'event'     => $eventName,
            'severity'  => $severity,
            'timestamp' => gmdate('c'),
            'payload'   => $payload,
        ];

        if (function_exists('do_action')) {
            do_action('sbdp/audit/log', $eventName, ['scope' => 'day_planner_decision'], $payload, $severity);
        }

        if (function_exists('error_log')) {
            $encoded = wp_json_encode($entry);
            if (is_string($encoded) && $encoded !== '') {
                error_log('[SBDP][DecisionEngine] ' . $encoded);
            }
        }
    }
}
