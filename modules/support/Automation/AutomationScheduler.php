<?php

declare(strict_types=1);

namespace BSP\Support\Automation;

use BSP\Core\Helpers\Logger;

use function add_action;
use function time;

/**
 * Handles registration and execution of Booking Pro automation tasks.
 */
final class AutomationScheduler
{
    private const OPTION_KEY = 'sbdp_automation_tasks';

    private Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Ensure default tasks exist, hook them into cron, and schedule missing events.
     */
    public function bootstrap(): void
    {
        $tasks = $this->getTasks();
        if ([] === $tasks) {
            $tasks = $this->registerTasks($this->defaultTasks(), true);
        }

        $this->registerHooks($tasks);
        $this->scheduleTasks($tasks);
    }

    /**
     * Register automation tasks and persist them.
     *
     * @param array<int, array<string, mixed>> $tasks
     *
     * @return array<int, array<string, mixed>>
     */
    public function registerTasks(array $tasks, bool $logging): array
    {
        $sanitised = [];

        foreach ($tasks as $task) {
            if (! \is_array($task)) {
                continue;
            }

            $id = $this->sanitizeKey((string) ($task['id'] ?? ''));
            if ('' === $id) {
                continue;
            }

            $schedule = $this->normalizeSchedule((string) ($task['schedule'] ?? 'daily'));

            $sanitised[] = [
                'id'       => $id,
                'schedule' => $schedule,
                'logic'    => $this->sanitizeText((string) ($task['logic'] ?? '')),
            ];
        }

        if (\function_exists('update_option')) {
            \update_option(self::OPTION_KEY, $sanitised);
        }

        if ($logging) {
            $this->logger->log('[Automation] Registered tasks: ' . \implode(', ', \array_column($sanitised, 'id')));
        }

        return $sanitised;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTasks(): array
    {
        if (! \function_exists('get_option')) {
            return [];
        }

        $stored = \get_option(self::OPTION_KEY, []);

        return \is_array($stored) ? $stored : [];
    }

    /**
     * Attach WordPress actions for scheduled tasks.
     *
     * @param array<int, array<string, mixed>> $tasks
     */
    private function registerHooks(array $tasks): void
    {
        if (! \function_exists('add_action')) {
            return;
        }

        foreach ($tasks as $task) {
            $hook = $this->hookName((string) ($task['id'] ?? ''));
            if ('' === $hook) {
                continue;
            }

            add_action(
                $hook,
                function () use ($task) {
                    $this->dispatch((string) ($task['id'] ?? ''), (string) ($task['logic'] ?? ''));
                }
            );
        }
    }

    /**
     * Schedule events for the supplied tasks if missing.
     *
     * @param array<int, array<string, mixed>> $tasks
     */
    private function scheduleTasks(array $tasks): void
    {
        if (! \function_exists('wp_schedule_event') || ! \function_exists('wp_next_scheduled')) {
            return;
        }

        foreach ($tasks as $task) {
            $id       = (string) ($task['id'] ?? '');
            $schedule = (string) ($task['schedule'] ?? '');
            $hook     = $this->hookName($id);

            if ('' === $hook || '' === $schedule) {
                continue;
            }

            if (! \wp_next_scheduled($hook)) {
                \wp_schedule_event(time() + 60, $schedule, $hook);
            }
        }
    }

    /**
     * Register custom cron intervals for weekly tasks.
     *
     * @param array<string, array<string, int|string>> $schedules
     *
     * @return array<string, array<string, int|string>>
     */
    public function registerCronSchedules(array $schedules): array
    {
        $day = \defined('DAY_IN_SECONDS') ? \DAY_IN_SECONDS : 86400;

        if (! isset($schedules['sbdp_automation_weekly'])) {
            $schedules['sbdp_automation_weekly'] = [
                'interval' => 7 * $day,
                'display'  => $this->translate('SBDP Automation – Weekly'),
            ];
        }

        return $schedules;
    }

    private function dispatch(string $taskId, string $description): void
    {
        $message = '[Automation] Executed task ' . $taskId;
        if ('' !== $description) {
            $message .= ' (' . $description . ')';
        }

        $this->logger->log($message);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultTasks(): array
    {
        return [
            [
                'id'       => 'auto-complete',
                'schedule' => 'hourly',
                'logic'    => 'complete expired bookings',
            ],
            [
                'id'       => 'weekly-report',
                'schedule' => 'weekly',
                'logic'    => 'email weekly summary',
            ],
            [
                'id'       => 'review-request',
                'schedule' => 'daily',
                'logic'    => 'send review emails',
            ],
        ];
    }

    private function hookName(string $id): string
    {
        $id = $this->sanitizeKey($id);
        if ('' === $id) {
            return '';
        }

        return 'sbdp_automation_' . $id;
    }

    private function sanitizeKey(string $value): string
    {
        if (\function_exists('sanitize_key')) {
            return \sanitize_key($value);
        }

        $value = \strtolower($value);

        return \preg_replace('/[^a-z0-9_\-]/', '', $value) ?? '';
    }

    private function sanitizeText(string $value): string
    {
        $value = \strip_tags($value);
        $value = \preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value;

        return \trim($value);
    }

    private function normalizeSchedule(string $schedule): string
    {
        $schedule = $this->sanitizeKey($schedule);

        if (\in_array($schedule, ['hourly', 'daily', 'twicedaily'], true)) {
            return $schedule;
        }

        if ('weekly' === $schedule) {
            return 'sbdp_automation_weekly';
        }

        return 'daily';
    }

    private function translate(string $text): string
    {
        return \function_exists('__') ? \__($text, 'sbdp') : $text;
    }
}
