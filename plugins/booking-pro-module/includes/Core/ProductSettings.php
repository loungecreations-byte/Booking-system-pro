<?php

declare(strict_types=1);

namespace SBDP\Core;

use InvalidArgumentException;
use WC_Product;

final class ProductSettings
{
    private const DEFAULT_DURATION_MINUTES = 90;
    private const DEFAULT_CAPACITY = 0;
    private const DEFAULT_TIME = '09:00';
    private const DEFAULT_SLOT_STEP_MINUTES = 60;

    /**
     * Retrieve normalized planner-friendly settings for a WooCommerce product.
     *
     * @return array<string, mixed>
     */
    public static function get(int $productId): array
    {
        if ($productId <= 0) {
            throw new InvalidArgumentException('Invalid product id provided to ProductSettings::get().');
        }

        $settings = array(
            'id'                => $productId,
            'duration_minutes'  => self::resolveDurationMinutes($productId),
            'price_pp'          => self::resolvePricePerPerson($productId),
            'capacity'          => self::resolveCapacity($productId),
            'available_days'    => self::resolveAvailableDays($productId),
            'time_slots'        => self::resolveTimeSlots($productId),
            'default_start'     => self::resolveDefaultStart($productId),
        );

        return $settings;
    }

    private static function resolveDurationMinutes(int $productId): int
    {
        $value = get_post_meta($productId, '_sbdp_booking_min_duration', true);
        $unit  = get_post_meta($productId, '_sbdp_booking_duration_type', true);

        if ((! is_numeric($value) || (float) $value <= 0) && (! is_string($unit) || trim($unit) === '')) {
            $value = get_post_meta($productId, '_sbdp_duration', true);
            $unit  = get_post_meta($productId, '_sbdp_duration_unit', true);
        }

        if (is_numeric($value) && (float) $value > 0) {
            return self::convertDurationToMinutes((float) $value, is_string($unit) ? $unit : 'minutes');
        }

        $minutes = get_post_meta($productId, '_sbdp_duration_minutes', true);
        if (is_numeric($minutes) && (int) $minutes > 0) {
            return (int) $minutes;
        }

        return self::DEFAULT_DURATION_MINUTES;
    }

    private static function convertDurationToMinutes(float $value, string $unit): int
    {
        $unit = strtolower($unit);
        $value = $value > 0 ? $value : 1;

        switch ($unit) {
            case 'hour':
            case 'hours':
                return (int) round($value * 60);
            case 'day':
            case 'days':
                return (int) round($value * 60 * 24);
            case 'minutes':
            case 'minute':
            default:
                return (int) round($value);
        }
    }

    private static function resolvePricePerPerson(int $productId): float
    {
        $meta = get_post_meta($productId, '_sbdp_price_per_person', true);
        if (is_numeric($meta) && (float) $meta > 0) {
            return round((float) $meta, 2);
        }

        if (function_exists('wc_get_product')) {
            $product = wc_get_product($productId);
            if ($product instanceof WC_Product) {
                $price = wc_get_price_to_display($product);
                if ($price > 0) {
                    return round((float) $price, 2);
                }
            }
        }

        $price = get_post_meta($productId, '_price', true);
        if (is_numeric($price) && (float) $price > 0) {
            return round((float) $price, 2);
        }

        return 0.0;
    }

    private static function resolveCapacity(int $productId): int
    {
        $capacity = get_post_meta($productId, '_sbdp_capacity', true);
        if (is_numeric($capacity) && (int) $capacity >= 0) {
            return (int) $capacity;
        }

        $defaultCapacity = get_post_meta($productId, '_sbdp_capacity_default', true);
        if (is_numeric($defaultCapacity) && (int) $defaultCapacity >= 0) {
            return (int) $defaultCapacity;
        }

        return self::DEFAULT_CAPACITY;
    }

    private static function resolveAvailableDays(int $productId): array
    {
        $stored = get_post_meta($productId, '_sbdp_booking_allowed_start_days', true);
        if (is_array($stored) && $stored !== array()) {
            return self::sanitizeDaySlugs($stored);
        }

        $stored = get_post_meta($productId, '_sbdp_available_days', true);
        if (empty($stored)) {
            return array();
        }

        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            } else {
                $stored = array_map('trim', explode(',', $stored));
            }
        }

        if (! is_array($stored)) {
            return array();
        }

        return self::sanitizeDaySlugs($stored);
    }

    private static function resolveTimeSlots(int $productId): array
    {
        $stored = get_post_meta($productId, '_sbdp_time_slots', true);
        $slots = self::normalizeStoredSlots($stored);
        if ($slots !== array()) {
            return $slots;
        }

        $defaultAvailability = self::resolveDefaultAvailability($productId);
        $availabilitySlots = self::flattenAvailabilitySlots($defaultAvailability);
        if ($availabilitySlots !== array()) {
            return $availabilitySlots;
        }

        $derived = self::deriveSlotsFromBookingWindow($productId);
        if ($derived !== array()) {
            return $derived;
        }

        return array();
    }

    private static function resolveDefaultStart(int $productId): array
    {
        $date = get_post_meta($productId, '_sbdp_booking_default_start_date', true);
        $time = get_post_meta($productId, '_sbdp_booking_default_start_time', true);

        if ((! is_string($date) || trim($date) === '') && (! is_string($time) || trim($time) === '')) {
            $date = get_post_meta($productId, '_sbdp_default_start_date', true);
            $time = get_post_meta($productId, '_sbdp_default_start_time', true);
        }

        return array(
            'date' => is_string($date) ? trim($date) : '',
            'time' => self::sanitizeTime($time) ?? self::DEFAULT_TIME,
        );
    }

    /**
     * @param mixed $stored
     * @return array<int, array{start:string,end:string}>
     */
    private static function normalizeStoredSlots($stored): array
    {
        if (empty($stored)) {
            return array();
        }

        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }

        if (! is_array($stored)) {
            return array();
        }

        $slots = array();
        foreach ($stored as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $start = isset($slot['start']) ? self::sanitizeTime($slot['start']) : null;
            $end   = isset($slot['end']) ? self::sanitizeTime($slot['end']) : null;
            if ($start === null) {
                continue;
            }

            $slots[] = array(
                'start' => $start,
                'end'   => $end ?? '',
            );
        }

        return self::uniqueSlots($slots);
    }

    /**
     * @return array<string, array<int, array{start:string,end:string}>>
     */
    public static function resolveDefaultAvailability(int $productId): array
    {
        $candidates = array(
            get_post_meta($productId, '_sbdp_default_availability', true),
            get_post_meta($productId, '_sbdp_default_hours', true),
        );

        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $decoded = json_decode($candidate, true);
                if (is_array($decoded)) {
                    $candidate = $decoded;
                }
            }

            if (! is_array($candidate)) {
                continue;
            }

            $normalized = array();
            foreach (array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun') as $day) {
                $normalized[$day] = array();
                $entries = $candidate[$day] ?? array();
                if (! is_array($entries)) {
                    continue;
                }
                foreach ($entries as $slot) {
                    if (! is_array($slot)) {
                        continue;
                    }
                    $start = isset($slot['start']) ? self::sanitizeTime($slot['start']) : null;
                    $end   = isset($slot['end']) ? self::sanitizeTime($slot['end']) : null;
                    if ($start === null || $end === null) {
                        continue;
                    }
                    $normalized[$day][] = array('start' => $start, 'end' => $end);
                }
            }

            return $normalized;
        }

        return array();
    }

    /**
     * @param array<string, array<int, array{start:string,end:string}>> $availability
     * @return array<int, array{start:string,end:string}>
     */
    public static function flattenAvailabilitySlots(array $availability): array
    {
        if ($availability === array()) {
            return array();
        }

        $slots = array();
        foreach ($availability as $daySlots) {
            if (! is_array($daySlots)) {
                continue;
            }
            foreach ($daySlots as $slot) {
                if (! is_array($slot)) {
                    continue;
                }
                $start = self::sanitizeTime($slot['start'] ?? null);
                $end = self::sanitizeTime($slot['end'] ?? null);
                if ($start === null || $end === null) {
                    continue;
                }
                $slots[] = array('start' => $start, 'end' => $end);
            }
        }

        return self::uniqueSlots($slots);
    }

    /**
     * @return array<int, array{start:string,end:string}>
     */
    public static function slotsForDate(int $productId, string $date): array
    {
        $availability = self::resolveDefaultAvailability($productId);
        $weekday = self::weekdaySlugForDate($date);
        if ($weekday !== null && isset($availability[$weekday]) && is_array($availability[$weekday]) && $availability[$weekday] !== array()) {
            return self::uniqueSlots($availability[$weekday]);
        }

        $allowedDays = self::resolveAvailableDays($productId);
        if ($allowedDays !== array() && $weekday !== null && ! in_array($weekday, $allowedDays, true)) {
            return array();
        }

        return self::resolveTimeSlots($productId);
    }

    /**
     * @return array<int, array{start:string,end:string}>
     */
    private static function deriveSlotsFromBookingWindow(int $productId): array
    {
        $start = self::sanitizeTime(get_post_meta($productId, '_sbdp_booking_checkin', true));
        $end   = self::sanitizeTime(get_post_meta($productId, '_sbdp_booking_checkout', true));

        if ($start === null || $end === null) {
            return array();
        }

        $startMinutes = self::timeToMinutes($start);
        $endMinutes = self::timeToMinutes($end);
        if ($startMinutes === null || $endMinutes === null || $endMinutes <= $startMinutes) {
            return array();
        }

        $duration = self::resolveDurationMinutes($productId);
        $incrementBased = self::isTruthy(get_post_meta($productId, '_sbdp_booking_time_increment_based', true));
        $step = $incrementBased && $duration > 0 ? $duration : self::DEFAULT_SLOT_STEP_MINUTES;
        if ($step <= 0) {
            $step = self::DEFAULT_SLOT_STEP_MINUTES;
        }

        $slots = array();
        for ($cursor = $startMinutes; $cursor + $duration <= $endMinutes; $cursor += $step) {
            $slots[] = array(
                'start' => self::minutesToTime($cursor),
                'end'   => self::minutesToTime($cursor + $duration),
            );
        }

        return self::uniqueSlots($slots);
    }

    /**
     * @param array<int, string> $stored
     * @return array<int, string>
     */
    private static function sanitizeDaySlugs(array $stored): array
    {
        $days = array();
        foreach ($stored as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $slug = strtolower(trim($entry));
            if ($slug !== '') {
                $days[] = $slug;
            }
        }

        return array_values(array_unique($days));
    }

    /**
     * @param array<int, array{start:string,end:string}> $slots
     * @return array<int, array{start:string,end:string}>
     */
    private static function uniqueSlots(array $slots): array
    {
        $seen = array();
        $unique = array();
        foreach ($slots as $slot) {
            $start = self::sanitizeTime($slot['start'] ?? null);
            $end   = self::sanitizeTime($slot['end'] ?? null);
            if ($start === null) {
                continue;
            }

            $key = $start . '|' . ($end ?? '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = array(
                'start' => $start,
                'end'   => $end ?? '',
            );
        }

        usort($unique, static function (array $left, array $right): int {
            return strcmp($left['start'], $right['start']);
        });

        return $unique;
    }

    private static function weekdaySlugForDate(string $date): ?string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return null;
        }

        return array('sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat')[(int) date('w', $timestamp)] ?? null;
    }

    private static function timeToMinutes(?string $time): ?int
    {
        $normalized = self::sanitizeTime($time);
        if ($normalized === null) {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $normalized));
        return ($hours * 60) + $minutes;
    }

    private static function minutesToTime(int $minutes): string
    {
        $hours = (int) floor($minutes / 60);
        $mins = $minutes % 60;

        return sprintf('%02d:%02d', $hours, $mins);
    }

    private static function isTruthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), array('1', 'yes', 'true', 'on'), true);
        }

        return (bool) $value;
    }

    private static function sanitizeTime($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $trimmed)) {
            return $trimmed;
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $trimmed)) {
            return substr($trimmed, 0, 5);
        }

        return null;
    }
}

if (! class_exists('\BPM\Core\ProductSettings', false)) {
    class_alias(ProductSettings::class, 'BPM\Core\ProductSettings');
}
