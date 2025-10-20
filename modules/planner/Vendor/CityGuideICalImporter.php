<?php
declare(strict_types=1);

namespace BSP\Planner\Vendor;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class CityGuideICalImporter
{
    /**
     * @return array<int, array<string, string>>
     */
    public function import(string $icalContent): array
    {
        $normalized = $this->unfoldLines($icalContent);
        $lines      = preg_split('/\r\n|\n|\r/', $normalized) ?: [];

        $events  = [];
        $current = [];

        foreach ($lines as $line) {
            if ('' === trim($line)) {
                continue;
            }

            if (str_starts_with($line, 'BEGIN:VEVENT')) {
                $current = [];
                continue;
            }

            if (str_starts_with($line, 'END:VEVENT')) {
                if (isset($current['start'], $current['end'])) {
                    $events[] = $current;
                }
                $current = [];
                continue;
            }

            if (str_starts_with($line, 'DTSTART')) {
                $date = $this->parseDateProperty($line);
                if ($date instanceof DateTimeImmutable) {
                    $current['start'] = $date->format(DateTimeInterface::ATOM);
                }
                continue;
            }

            if (str_starts_with($line, 'DTEND')) {
                $date = $this->parseDateProperty($line);
                if ($date instanceof DateTimeImmutable) {
                    $current['end'] = $date->format(DateTimeInterface::ATOM);
                }
                continue;
            }

            if (str_starts_with($line, 'SUMMARY:')) {
                $current['summary'] = ltrim(substr($line, 8));
                continue;
            }

            if (str_starts_with($line, 'LOCATION:')) {
                $current['location'] = ltrim(substr($line, 9));
                continue;
            }
        }

        return array_values(array_filter($events, static function (array $event): bool {
            if (!isset($event['start'], $event['end'])) {
                return false;
            }

            return strtotime($event['end']) > strtotime($event['start']);
        }));
    }

    private function unfoldLines(string $icalContent): string
    {
        $lines    = preg_split('/\r\n|\n|\r/', $icalContent) ?: [];
        $unfolded = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
                $lastIndex = count($unfolded) - 1;
                if ($lastIndex >= 0) {
                    $unfolded[$lastIndex] .= ltrim($line);
                }
                continue;
            }

            $unfolded[] = $line;
        }

        return implode("\n", $unfolded);
    }

    private function parseDateProperty(string $line): ?DateTimeImmutable
    {
        [$meta, $value] = array_pad(explode(':', $line, 2), 2, null);
        if (null === $value) {
            return null;
        }

        $timezone = null;
        if (false !== stripos($meta, 'TZID=')) {
            [, $tz] = array_pad(explode('TZID=', $meta, 2), 2, '');
            $timezone = trim($tz, ';');
        }

        $value = trim($value);

        if (preg_match('/^\d{8}$/', $value)) {
            $value .= 'T000000';
        }

        $hasZulu = str_ends_with($value, 'Z');
        $format  = $hasZulu ? 'Ymd\THis\Z' : 'Ymd\THis';

        $tz = $timezone ? new DateTimeZone($timezone) : new DateTimeZone('UTC');

        $date = DateTimeImmutable::createFromFormat($format, $value, $tz);
        if ($date instanceof DateTimeImmutable) {
            return $timezone ? $date : $date->setTimezone(new DateTimeZone('UTC'));
        }

        if ($hasZulu) {
            $fallback = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
            if ($fallback instanceof DateTimeImmutable) {
                return $fallback;
            }
        }

        return null;
    }
}
