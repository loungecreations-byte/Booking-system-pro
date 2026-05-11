<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

final class AvailabilityService
{
    /**
     * @param array<int, array<string, mixed>> $days
     * @param array<string, mixed>             $rules
     *
     * @return array<int, array<string, mixed>>
     */
    public function detectConflicts(array $days, array $rules = []): array
    {
        $conflicts = [];

        foreach ($days as $dayIndex => $day) {
            if (! isset($day['slots']) || ! is_array($day['slots'])) {
                continue;
            }

            $slots = $day['slots'];
            usort(
                $slots,
                static fn (array $left, array $right): int => strcmp(
                    (string) ($left['start'] ?? ''),
                    (string) ($right['start'] ?? '')
                )
            );

            $previousEnd = null;
            foreach ($slots as $slot) {
                $start = (string) ($slot['start'] ?? '');
                $end   = (string) ($slot['end'] ?? '');

                if ($previousEnd !== null && $start < $previousEnd) {
                    $conflicts[] = [
                        'day'       => $day['date'] ?? '',
                        'slot'      => $slot,
                        'reason'    => 'overlap',
                        'rule'      => 'time_overlap',
                        'day_index' => $dayIndex,
                    ];
                }

                $previousEnd = $end !== '' ? $end : $previousEnd;
            }
        }

        return $conflicts;
    }
}
