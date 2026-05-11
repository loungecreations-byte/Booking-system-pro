<?php

declare(strict_types=1);

namespace BSP\Planner\Services\Planboard;

use DateTimeImmutable;
use WP_Error;

final class PlanboardValidator
{
    public static function validateSnapshot(array $params)
    {
        $range = self::normalizeRange($params['start'] ?? null, $params['end'] ?? null);
        if ($range instanceof WP_Error) {
            return $range;
        }

        return array_merge($params, $range);
    }

    public static function validateMove(array $payload)
    {
        $bookingId = isset($payload['booking_id']) ? (int) $payload['booking_id'] : 0;
        if ($bookingId <= 0) {
            return new WP_Error('sbdp_planboard_invalid_booking', __('Booking identifier is required.', 'sbdp'), array('status' => 400));
        }

        $start = self::normalizeDateTime($payload['start'] ?? null);
        if ($start === null) {
            return new WP_Error('sbdp_planboard_invalid_start', __('Start time is required.', 'sbdp'), array('status' => 400));
        }

        $end = self::normalizeDateTime($payload['end'] ?? null);
        if ($end === null) {
            return new WP_Error('sbdp_planboard_invalid_end', __('End time is required.', 'sbdp'), array('status' => 400));
        }

        if ($end <= $start) {
            return new WP_Error('sbdp_planboard_invalid_range', __('End must be after start.', 'sbdp'), array('status' => 400));
        }

        $resourceId = array_key_exists('resource_id', $payload) ? (int) $payload['resource_id'] : null;

        return array(
            'booking_id'  => $bookingId,
            'start'       => $start->format(DateTimeImmutable::ATOM),
            'end'         => $end->format(DateTimeImmutable::ATOM),
            'resource_id' => $resourceId,
            'version'     => isset($payload['version']) ? (string) $payload['version'] : null,
            'notes'       => isset($payload['notes']) ? self::sanitizeText($payload['notes']) : '',
        );
    }

    public static function validateCheckin(array $payload)
    {
        $bookingId = isset($payload['booking_id']) ? (int) $payload['booking_id'] : 0;
        if ($bookingId <= 0) {
            return new WP_Error('sbdp_planboard_invalid_booking', __('Booking identifier is required.', 'sbdp'), array('status' => 400));
        }

        $timestamp = self::normalizeDateTime($payload['checked_in_at'] ?? null);
        if ($timestamp === null) {
            $timestamp = new DateTimeImmutable('now');
        }

        return array(
            'booking_id'     => $bookingId,
            'checked_in_at'  => $timestamp->format(DateTimeImmutable::ATOM),
            'notes'          => isset($payload['notes']) ? self::sanitizeText($payload['notes']) : '',
            'version'        => isset($payload['version']) ? (string) $payload['version'] : null,
        );
    }

    public static function validatePayment(array $payload)
    {
        $bookingId = isset($payload['booking_id']) ? (int) $payload['booking_id'] : 0;
        if ($bookingId <= 0) {
            return new WP_Error('sbdp_planboard_invalid_booking', __('Booking identifier is required.', 'sbdp'), array('status' => 400));
        }

        $amount = isset($payload['amount']) ? (float) $payload['amount'] : 0.0;
        if ($amount <= 0) {
            return new WP_Error('sbdp_planboard_invalid_amount', __('Payment amount must be greater than zero.', 'sbdp'), array('status' => 400));
        }

        $currency = isset($payload['currency']) ? strtoupper(preg_replace('/[^A-Z]/', '', (string) $payload['currency'])) : 'EUR';
        if ($currency === '') {
            $currency = 'EUR';
        }

        $capturedAt = null;
        if (isset($payload['captured_at'])) {
            $captured = self::normalizeDateTime($payload['captured_at']);
            if ($captured !== null) {
                $capturedAt = $captured->format(DateTimeImmutable::ATOM);
            }
        }

        return array(
            'booking_id' => $bookingId,
            'amount'     => $amount,
            'currency'   => $currency,
            'method'     => isset($payload['method']) ? self::sanitizeText($payload['method']) : 'manual',
            'reference'  => isset($payload['reference']) ? self::sanitizeText($payload['reference']) : '',
            'captured_at'=> $capturedAt,
            'version'    => isset($payload['version']) ? (string) $payload['version'] : null,
            'notes'      => isset($payload['notes']) ? self::sanitizeText($payload['notes']) : '',
        );
    }

    public static function validateCreate(array $payload)
    {
        $date = isset($payload['date']) ? (string) $payload['date'] : '';
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error('sbdp_planboard_invalid_date', __('Date must be in YYYY-MM-DD format.', 'sbdp'), array('status' => 400));
        }

        $time = isset($payload['time']) ? (string) $payload['time'] : '09:00';
        if ($time === '') {
            $time = '09:00';
        }

        $participants = isset($payload['participants']) ? (int) $payload['participants'] : 1;
        if ($participants <= 0) {
            return new WP_Error('sbdp_planboard_invalid_participants', __('Participants must be greater than zero.', 'sbdp'), array('status' => 400));
        }

        $customer = isset($payload['customer']) && is_array($payload['customer']) ? $payload['customer'] : array();
        $name  = isset($customer['name']) ? trim((string) $customer['name']) : '';
        $email = isset($customer['email']) ? trim((string) $customer['email']) : '';
        if ($name === '' || $email === '') {
            return new WP_Error('sbdp_planboard_invalid_customer', __('Customer name and email are required.', 'sbdp'), array('status' => 400));
        }

        $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : array();
        if ($items === array()) {
            return new WP_Error('sbdp_planboard_invalid_items', __('At least one item is required.', 'sbdp'), array('status' => 400));
        }

        return array(
            'date'         => $date,
            'time'         => $time,
            'date_end'     => isset($payload['date_end']) ? (string) $payload['date_end'] : null,
            'time_end'     => isset($payload['time_end']) ? (string) $payload['time_end'] : null,
            'participants' => $participants,
            'customer'     => $customer,
            'items'        => $items,
            'notes'        => isset($payload['notes']) ? self::sanitizeText($payload['notes']) : null,
            'currency'     => isset($payload['currency']) ? self::sanitizeText($payload['currency']) : 'EUR',
            'channel'      => isset($payload['channel']) ? self::sanitizeText($payload['channel']) : 'manual',
            'resource_id'  => isset($payload['resource_id']) ? (int) $payload['resource_id'] : null,
        );
    }

    public static function validateRule(array $payload, bool $requireId = false)
    {
        $type = isset($payload['type']) ? self::sanitizeKey((string) $payload['type']) : '';
        if (! in_array($type, array('one_off', 'recurring'), true)) {
            return new WP_Error('sbdp_planboard_invalid_rule_type', __('Rule type must be one_off or recurring.', 'sbdp'), array('status' => 400));
        }

        $rule = array(
            'id'          => isset($payload['id']) ? self::sanitizeText($payload['id']) : null,
            'type'        => $type,
            'resource_id' => isset($payload['resource_id']) ? (int) $payload['resource_id'] : null,
            'notes'       => isset($payload['notes']) ? self::sanitizeText($payload['notes']) : '',
        );

        if ($type === 'one_off') {
            $start = self::normalizeDateTime($payload['start'] ?? null);
            $end   = self::normalizeDateTime($payload['end'] ?? null);

            if ($start === null || $end === null || $end <= $start) {
                return new WP_Error('sbdp_planboard_invalid_rule_range', __('One-off rule requires valid start/end.', 'sbdp'), array('status' => 400));
            }

            $rule['start'] = $start->format(DateTimeImmutable::ATOM);
            $rule['end']   = $end->format(DateTimeImmutable::ATOM);
        } else {
            $weekday = isset($payload['weekday']) ? (int) $payload['weekday'] : -1;
            if ($weekday < 0 || $weekday > 6) {
                return new WP_Error('sbdp_planboard_invalid_rule_weekday', __('Recurring rule requires weekday 0-6.', 'sbdp'), array('status' => 400));
            }

            $startTime = isset($payload['start_time']) ? (string) $payload['start_time'] : '';
            $endTime   = isset($payload['end_time']) ? (string) $payload['end_time'] : '';

            if (! preg_match('/^\d{2}:\d{2}$/', $startTime) || ! preg_match('/^\d{2}:\d{2}$/', $endTime)) {
                return new WP_Error('sbdp_planboard_invalid_rule_time', __('Recurring rule requires start_time and end_time (HH:MM).', 'sbdp'), array('status' => 400));
            }

            $rule['weekday'] = $weekday;
            $rule['start_time'] = $startTime;
            $rule['end_time'] = $endTime;
            $rule['rrule'] = isset($payload['rrule']) ? self::sanitizeText($payload['rrule']) : null;
        }

        if ($requireId && empty($rule['id'])) {
            return new WP_Error('sbdp_planboard_invalid_rule_id', __('Rule identifier is required.', 'sbdp'), array('status' => 400));
        }

        return $rule;
    }

    private static function normalizeRange($start, $end)
    {
        $startValue = self::normalizeDateTime($start);
        $endValue   = self::normalizeDateTime($end);

        if ($startValue === null || $endValue === null) {
            return new WP_Error('sbdp_planboard_invalid_range', __('Start and end are required.', 'sbdp'), array('status' => 400));
        }

        if ($endValue < $startValue) {
            return new WP_Error('sbdp_planboard_invalid_range', __('End must be after start.', 'sbdp'), array('status' => 400));
        }

        return array(
            'start' => $startValue->format(DateTimeImmutable::ATOM),
            'end'   => $endValue->format(DateTimeImmutable::ATOM),
        );
    }

    private static function normalizeDateTime($value): ?DateTimeImmutable
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private static function sanitizeText($value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        return trim(strip_tags($value));
    }

    private static function sanitizeKey(string $value): string
    {
        if (function_exists('sanitize_key')) {
            return sanitize_key($value);
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_]/', '', $value);

        return $value !== null ? $value : '';
    }
}
