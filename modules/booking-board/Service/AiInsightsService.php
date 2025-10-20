<?php

declare(strict_types=1);

namespace BSP\BookingBoard\Service;

use DateTimeImmutable;

final class AiInsightsService
{
    /**
     * @param array<int, array<string, mixed>> $bookings
     *
     * @return array<string, mixed>
     */
    public function summarize(array $bookings): array
    {
        return [
            'peak_day'  => $this->findPeakDay($bookings),
            'best_slot' => $this->findBestSlot($bookings),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     *
     * @return array<string, mixed>|null
     */
    private function findPeakDay(array $bookings): ?array
    {
        $counter = [];

        foreach ($bookings as $booking) {
            $date = (string) ($booking['date'] ?? '');
            if ($date === '') {
                continue;
            }

            $counter[$date] = ($counter[$date] ?? 0) + 1;
        }

        if ($counter === []) {
            return null;
        }

        arsort($counter);
        $peakDate = array_key_first($counter);

        return [
            'date'  => $peakDate,
            'count' => $counter[$peakDate],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     *
     * @return array<string, mixed>|null
     */
    private function findBestSlot(array $bookings): ?array
    {
        $slots = [];

        foreach ($bookings as $booking) {
            $date = (string) ($booking['date'] ?? '');
            $time = (string) ($booking['time'] ?? '');
            if ($date === '' || $time === '') {
                continue;
            }

            $key = $date . ' ' . substr($time, 0, 2) . ':00';
            $slots[$key] = ($slots[$key] ?? 0) + 1;
        }

        if ($slots === []) {
            return null;
        }

        arsort($slots);
        $slotKey = array_key_first($slots);

        try {
            $dateTime = new DateTimeImmutable($slotKey);
        } catch (\Exception $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            return [
                'slot'  => $slotKey,
                'count' => $slots[$slotKey],
            ];
        }

        return [
            'slot'  => $dateTime->format(DateTimeImmutable::ATOM),
            'count' => $slots[$slotKey],
        ];
    }
}
