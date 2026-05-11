<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

final class FeasibleDayPlanEngine
{
    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $constraints
     * @param array<int, array<string, mixed>> $alternatives
     *
     * @return array<string, mixed>
     */
    public function build(array $primary, array $constraints = [], array $alternatives = []): array
    {
        $timeline = [];
        $buffers = [];
        $notes = [];

        $isFixedSlot = isset($primary['default_start_time']) && is_string($primary['default_start_time']) && $primary['default_start_time'] !== '';
        $startTime = $this->resolveStartTime($primary, $constraints);
        $start = $this->toMinutes($startTime);

        $primaryDuration = max(30, (int) ($primary['duration_minutes'] ?? 90));
        $bufferMinutes = $this->resolveBufferMinutes($constraints);

        if ($start >= 11 * 60) {
            $preEnd = max(8 * 60, $start - $bufferMinutes);
            $preStart = max(8 * 60, $preEnd - 60);
            if ($preStart < $preEnd) {
                $timeline[] = $this->block($preStart, $preEnd, 'Koffie of lunch', 'Centrum', 'Rustige start voor de hoofdactiviteit');
                $buffers[] = $bufferMinutes;
            }
        }

        $primaryEnd = $start + $primaryDuration;
        $timeline[] = $this->block(
            $start,
            $primaryEnd,
            (string) ($primary['title'] ?? 'Hoofdactiviteit'),
            (string) ($primary['location'] ?? $primary['location_hint'] ?? 'Den Bosch'),
            $isFixedSlot ? 'Vast tijdslot uit aanbod' : 'Gepland op basis van voorkeuren'
        );

        $cursor = $primaryEnd;
        if ($alternatives !== []) {
            $firstAlternative = $alternatives[0];
            $altDuration = max(45, (int) ($firstAlternative['duration_minutes'] ?? 60));
            $altStart = $cursor + $bufferMinutes;
            $altEnd = $altStart + $altDuration;
            if ($altEnd <= 21 * 60 + 30) {
                $timeline[] = $this->block(
                    $altStart,
                    $altEnd,
                    (string) ($firstAlternative['title'] ?? 'Alternatief'),
                    (string) ($firstAlternative['location'] ?? $firstAlternative['location_hint'] ?? 'Den Bosch'),
                    'Aanvullend blok op basis van alternatief'
                );
                $buffers[] = $bufferMinutes;
                $cursor = $altEnd;
            }
        }

        $postStart = $cursor + $bufferMinutes;
        $postEnd = $postStart + 75;
        if ($postEnd <= 22 * 60 + 30) {
            $timeline[] = $this->block($postStart, $postEnd, 'Borrel of diner', 'Centrum', 'Afsluitend blok');
            $buffers[] = $bufferMinutes;
        }

        if ($timeline === []) {
            $notes[] = 'Geen haalbare tijdlijn opgebouwd.';
        }

        return [
            'timeline' => $timeline,
            'buffers'  => $buffers,
            'feasible' => $timeline !== [],
            'notes'    => $notes,
        ];
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $constraints
     */
    private function resolveStartTime(array $primary, array $constraints): string
    {
        $fromPrimary = isset($primary['default_start_time']) ? (string) $primary['default_start_time'] : '';
        if ($this->isValidTime($fromPrimary)) {
            return $fromPrimary;
        }

        $fromConstraint = isset($constraints['start_time']) ? (string) $constraints['start_time'] : '';
        if ($this->isValidTime($fromConstraint)) {
            return $fromConstraint;
        }

        return '10:00';
    }

    /**
     * @param array<string, mixed> $constraints
     */
    private function resolveBufferMinutes(array $constraints): int
    {
        $rainyDay = isset($constraints['rainy_day']) ? (bool) $constraints['rainy_day'] : false;
        return $rainyDay ? 30 : 15;
    }

    private function isValidTime(string $time): bool
    {
        return (bool) preg_match('/^\d{2}:\d{2}$/', $time);
    }

    private function toMinutes(string $time): int
    {
        if (! $this->isValidTime($time)) {
            return 10 * 60;
        }

        [$h, $m] = explode(':', $time);
        return ((int) $h * 60) + (int) $m;
    }

    private function fromMinutes(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hour = (int) floor($minutes / 60);
        $minute = $minutes % 60;

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * @return array<string, string>
     */
    private function block(int $start, int $end, string $title, string $locationHint, string $notes): array
    {
        return [
            'start'         => $this->fromMinutes($start),
            'end'           => $this->fromMinutes($end),
            'title'         => $title,
            'location_hint' => $locationHint,
            'notes'         => $notes,
        ];
    }
}
