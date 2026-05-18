<?php

declare(strict_types=1);

namespace BSP\Bookings\Rest;

use BSP\Bookings\Service\BookingService;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;

final class Controller
{
    private const RATE_LIMIT_WINDOW = 60;
    private const RATE_LIMIT_MAX = 20;
    private const RATE_LIMIT_PREFIX = 'bsp_booking_rest_';
    private const INTENT_TTL = 900;
    private const INTENT_PREFIX = 'bsp_booking_intent_';
    private const INTENT_HEADER = 'X-BSP-Booking-Intent';
    private const MAX_ITEMS = 20;
    private const MIN_PARTICIPANTS = 1;
    private const MAX_PARTICIPANTS = 100;
    private const MAX_ADVANCE_DAYS = 548;
    private const PUBLIC_NONCE_ACTION = 'sbdp_public_rest';

    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route('bsp/v1', '/booking/create', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'create'],
            'permission_callback' => [__CLASS__, 'authorizePublicBooking'],
        ]);

        register_rest_route('bsp/v1', '/booking/request', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'request'],
            'permission_callback' => [__CLASS__, 'authorizePublicBooking'],
        ]);

        register_rest_route('bsp/v1', '/booking/pay', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'pay'],
            'permission_callback' => [__CLASS__, 'canMutatePaymentState'],
        ]);

        register_rest_route('bsp/v1', '/booking/list', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'list'],
            'permission_callback' => [__CLASS__, 'canListBookings'],
        ]);
    }

    public static function create(WP_REST_Request $request)
    {
        $authorization = self::ensurePublicBookingAuthorized($request);
        if ($authorization instanceof WP_Error) {
            return self::respond($authorization);
        }

        $payload = self::parseJson($request);
        $validation = self::validatePublicPayload($payload);
        if ($validation instanceof WP_Error) {
            return self::respond($validation);
        }

        return self::wrap(static fn () => BookingService::createPublicBooking($payload));
    }

    public static function request(WP_REST_Request $request)
    {
        $authorization = self::ensurePublicBookingAuthorized($request);
        if ($authorization instanceof WP_Error) {
            return self::respond($authorization);
        }

        $payload = self::parseJson($request);
        $validation = self::validatePublicPayload($payload);
        if ($validation instanceof WP_Error) {
            return self::respond($validation);
        }

        return self::wrap(static fn () => BookingService::requestPublicBooking($payload));
    }

    public static function pay(WP_REST_Request $request)
    {
        $authorization = self::canMutatePaymentState($request);
        if ($authorization instanceof WP_Error) {
            return self::respond($authorization);
        }

        return self::wrap(static fn () => BookingService::pay(self::parseJson($request)));
    }

    public static function list(WP_REST_Request $request)
    {
        unset($request); // parameter kept for signature compatibility.
        return self::respond(BookingService::getBookings());
    }

    public static function canListBookings(WP_REST_Request $request): bool
    {
        unset($request); // parameter kept for signature compatibility.

        return function_exists('current_user_can') && (current_user_can('manage_options') || current_user_can('manage_woocommerce'));
    }

    /**
     * @return true|WP_Error
     */
    public static function canMutatePaymentState(WP_REST_Request $request)
    {
        unset($request); // parameter kept for signature compatibility.

        if (function_exists('current_user_can') && (current_user_can('manage_options') || current_user_can('manage_woocommerce'))) {
            return true;
        }

        return new WP_Error(
            'bsp_booking_payment_forbidden',
            'Payment state can only be changed by an authorised server-side flow.',
            ['status' => 403]
        );
    }

    /**
     * @return true|WP_Error
     */
    public static function authorizePublicBooking(WP_REST_Request $request)
    {
        $bucket = self::RATE_LIMIT_PREFIX . md5(self::requestIp());
        $state = get_transient($bucket);
        $state = is_array($state) ? $state : [];
        $attempts = (int) ($state['attempts'] ?? 0) + 1;

        if ($attempts > self::RATE_LIMIT_MAX) {
            return self::userSafeError('bsp_booking_rate_limited', __('Te veel aanvragen. Probeer het zo opnieuw.', 'sbdp'), 429);
        }

        set_transient($bucket, ['attempts' => $attempts], self::RATE_LIMIT_WINDOW);

        $nonceCheck = self::validatePublicNonce($request);
        if ($nonceCheck instanceof WP_Error) {
            return $nonceCheck;
        }

        $intentCheck = self::validateBookingIntent($request);
        if ($intentCheck instanceof WP_Error) {
            return $intentCheck;
        }

        $request->set_param('__bsp_public_booking_authorized', true);

        return true;
    }

    /**
     * Create a short-lived token for public booking create/request calls.
     *
     * @param array<string, mixed> $context
     * @return array{token:string,expires_at:int,ttl:int}
     */
    public static function createBookingIntent(array $context = []): array
    {
        $token = self::randomToken();
        $now = self::now();
        $payload = [
            'hash'       => self::hashToken($token),
            'created_at' => $now,
            'expires_at' => $now + self::INTENT_TTL,
            'ip'         => self::requestIp(),
            'session_id' => self::sessionId(),
            'context'    => self::sanitizeIntentContext($context),
        ];

        set_transient(self::INTENT_PREFIX . self::tokenId($token), $payload, self::INTENT_TTL);

        return [
            'token'      => $token,
            'expires_at' => $payload['expires_at'],
            'ttl'        => self::INTENT_TTL,
        ];
    }

    private static function wrap(callable $callback)
    {
        try {
            $result = $callback();
            return self::respond($result);
        } catch (InvalidArgumentException $exception) {
            return self::respond(self::userSafeError('bsp_booking_invalid', $exception->getMessage(), 400));
        }
    }

    private static function parseJson(WP_REST_Request $request): array
    {
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }

        unset($params['__bsp_public_booking_authorized']);

        return $params;
    }

    private static function respond($data)
    {
        if (function_exists('rest_ensure_response')) {
            return rest_ensure_response($data);
        }

        return $data;
    }

    private static function requestIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $parts = explode(',', $candidate);
            $ip = trim((string) ($parts[0] ?? ''));
            if ($ip !== '') {
                return $ip;
            }
        }

        return 'unknown';
    }

    /**
     * @return true|WP_Error
     */
    private static function ensurePublicBookingAuthorized(WP_REST_Request $request)
    {
        if ((bool) $request->get_param('__bsp_public_booking_authorized')) {
            return true;
        }

        return self::authorizePublicBooking($request);
    }

    /**
     * @return true|WP_Error
     */
    private static function validatePublicNonce(WP_REST_Request $request)
    {
        $nonce = trim((string) $request->get_header('x-sbdp-nonce'));
        if ($nonce === '') {
            $nonce = trim((string) $request->get_header('X-WP-Nonce'));
        }
        if ($nonce === '') {
            $nonce = trim((string) $request->get_param('nonce'));
        }

        if ($nonce === '' || ! function_exists('wp_verify_nonce')) {
            return self::userSafeError('bsp_booking_nonce_required', __('De beveiligingscontrole is verlopen. Vernieuw de pagina en probeer opnieuw.', 'sbdp'), 403);
        }

        $publicAction = defined('\BSPModule\Core\Rest\RestService::PUBLIC_NONCE_ACTION')
            ? (string) constant('\BSPModule\Core\Rest\RestService::PUBLIC_NONCE_ACTION')
            : self::PUBLIC_NONCE_ACTION;

        if (wp_verify_nonce($nonce, $publicAction) || wp_verify_nonce($nonce, 'wp_rest')) {
            return true;
        }

        return self::userSafeError('bsp_booking_nonce_invalid', __('De beveiligingscontrole is ongeldig. Vernieuw de pagina en probeer opnieuw.', 'sbdp'), 403);
    }

    /**
     * @return true|WP_Error
     */
    private static function validateBookingIntent(WP_REST_Request $request)
    {
        $token = trim((string) $request->get_header(self::INTENT_HEADER));
        if ($token === '') {
            $token = trim((string) $request->get_header('x-bsp-booking-intent'));
        }
        if ($token === '') {
            $token = trim((string) $request->get_param('booking_intent'));
        }

        if ($token === '') {
            return self::userSafeError('bsp_booking_intent_required', __('Deze aanvraag mist een geldige sessie. Vernieuw de pagina en probeer opnieuw.', 'sbdp'), 403);
        }

        $record = get_transient(self::INTENT_PREFIX . self::tokenId($token));
        if (! is_array($record) || empty($record['hash']) || ! hash_equals((string) $record['hash'], self::hashToken($token))) {
            return self::userSafeError('bsp_booking_intent_invalid', __('Deze aanvraag kan niet worden gevalideerd. Vernieuw de pagina en probeer opnieuw.', 'sbdp'), 403);
        }

        if ((int) ($record['expires_at'] ?? 0) < self::now()) {
            if (function_exists('delete_transient')) {
                delete_transient(self::INTENT_PREFIX . self::tokenId($token));
            }

            return self::userSafeError('bsp_booking_intent_expired', __('Deze aanvraag is verlopen. Vernieuw de pagina en probeer opnieuw.', 'sbdp'), 403);
        }

        $boundIp = (string) ($record['ip'] ?? '');
        if ($boundIp !== '' && $boundIp !== 'unknown' && $boundIp !== self::requestIp()) {
            return self::userSafeError('bsp_booking_intent_context_mismatch', __('Deze aanvraag hoort bij een andere sessie. Vernieuw de pagina en probeer opnieuw.', 'sbdp'), 403);
        }

        $boundSession = (string) ($record['session_id'] ?? '');
        $currentSession = self::sessionId();
        if ($boundSession !== '' && $currentSession !== '' && ! hash_equals($boundSession, $currentSession)) {
            return self::userSafeError('bsp_booking_intent_context_mismatch', __('Deze aanvraag hoort bij een andere sessie. Vernieuw de pagina en probeer opnieuw.', 'sbdp'), 403);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @return true|WP_Error
     */
    private static function validatePublicPayload(array $payload)
    {
        $blocked = self::findBlockedCommerceFields($payload);
        if ($blocked !== []) {
            return self::userSafeError(
                'bsp_booking_payload_commerce_fields',
                __('De aanvraag bevat velden die niet door de browser mogen worden bepaald.', 'sbdp'),
                400,
                ['fields' => $blocked]
            );
        }

        $participants = (int) ($payload['participants'] ?? 0);
        if ($participants < self::MIN_PARTICIPANTS || $participants > self::MAX_PARTICIPANTS) {
            return self::userSafeError('bsp_booking_participants_invalid', __('Kies een geldig aantal deelnemers.', 'sbdp'), 400);
        }

        $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
        if ($items === [] || count($items) > self::MAX_ITEMS) {
            return self::userSafeError('bsp_booking_items_invalid', __('De aanvraag bevat te veel of geen activiteiten.', 'sbdp'), 400);
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                return self::userSafeError('bsp_booking_item_invalid', __('Een activiteit in de aanvraag is ongeldig.', 'sbdp'), 400);
            }

            $productId = (int) ($item['product_id'] ?? 0);
            if (! self::isAllowedProductId($productId)) {
                return self::userSafeError('bsp_booking_product_invalid', __('Een activiteit in de aanvraag is niet beschikbaar.', 'sbdp'), 400);
            }

            $date = self::extractItemDate($item);
            if ($date instanceof WP_Error) {
                return $date;
            }

            $dateValidation = self::validateDateBounds($date);
            if ($dateValidation instanceof WP_Error) {
                return $dateValidation;
            }

            if (! isset($item['start'], $item['end'])) {
                return self::userSafeError('bsp_booking_time_required', __('Kies een geldige start- en eindtijd.', 'sbdp'), 400);
            }

            unset($index);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private static function findBlockedCommerceFields(array $payload, string $prefix = ''): array
    {
        $blockedNames = [
            'price',
            'unit_price',
            'display_unit_price',
            'display_total',
            'line_total',
            'subtotal',
            'total',
            'tax',
            'tax_total',
            'currency',
            'pricing',
            'pricing_rules',
            'payment',
            'payment_status',
            'paymentStatus',
            'order',
            'order_id',
            'booking_truth',
            'availabilityStatus',
        ];
        $found = [];

        foreach ($payload as $key => $value) {
            $keyString = (string) $key;
            $path = $prefix === '' ? $keyString : $prefix . '.' . $keyString;
            if (in_array($keyString, $blockedNames, true)) {
                $found[] = $path;
                continue;
            }
            if (is_array($value)) {
                $found = array_merge($found, self::findBlockedCommerceFields($value, $path));
            }
        }

        return array_values(array_unique($found));
    }

    private static function isAllowedProductId(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }

        $allowed = function_exists('apply_filters')
            ? apply_filters('bsp_public_booking_allowed_product_ids', [], $productId)
            : [];
        if (is_array($allowed) && $allowed !== []) {
            return in_array($productId, array_map('intval', $allowed), true);
        }

        return function_exists('wc_get_product') && (bool) wc_get_product($productId);
    }

    /**
     * @param array<string, mixed> $item
     * @return string|WP_Error
     */
    private static function extractItemDate(array $item)
    {
        $date = trim((string) ($item['date'] ?? ''));
        if ($date === '' && isset($item['start']) && is_string($item['start']) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $item['start']) === 1) {
            $date = substr($item['start'], 0, 10);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return self::userSafeError('bsp_booking_date_required', __('Kies een geldige datum.', 'sbdp'), 400);
        }

        return $date;
    }

    /**
     * @return true|WP_Error
     */
    private static function validateDateBounds(string $date)
    {
        $timestamp = strtotime($date . ' 00:00:00 UTC');
        if ($timestamp === false) {
            return self::userSafeError('bsp_booking_date_invalid', __('Kies een geldige datum.', 'sbdp'), 400);
        }

        $today = strtotime(gmdate('Y-m-d', self::now()) . ' 00:00:00 UTC');
        $latest = strtotime('+' . self::MAX_ADVANCE_DAYS . ' days', $today);
        if ($today === false || $latest === false || $timestamp < $today || $timestamp > $latest) {
            return self::userSafeError('bsp_booking_date_out_of_bounds', __('Kies een datum binnen de toegestane periode.', 'sbdp'), 400);
        }

        return true;
    }

    private static function userSafeError(string $code, string $message, int $status, array $extra = []): WP_Error
    {
        return new WP_Error($code, $message, array_merge(['status' => $status], $extra));
    }

    private static function randomToken(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Throwable) {
            return hash('sha256', uniqid('bsp_booking_intent_', true) . '|' . microtime(true));
        }
    }

    private static function tokenId(string $token): string
    {
        return hash_hmac('sha256', $token, self::secret());
    }

    private static function hashToken(string $token): string
    {
        return hash_hmac('sha256', 'booking-intent|' . $token, self::secret());
    }

    private static function secret(): string
    {
        if (function_exists('wp_salt')) {
            return (string) wp_salt('auth');
        }

        return defined('AUTH_KEY') ? (string) AUTH_KEY : 'bsp-public-booking-intent';
    }

    private static function now(): int
    {
        if (function_exists('current_time')) {
            $value = current_time('timestamp', true);
            if (is_numeric($value)) {
                return (int) $value;
            }
            $parsed = strtotime((string) $value);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return time();
    }

    private static function sessionId(): string
    {
        if (function_exists('WC') && WC() && isset(WC()->session) && is_object(WC()->session) && method_exists(WC()->session, 'get_customer_id')) {
            return (string) WC()->session->get_customer_id();
        }

        return '';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, scalar|null>
     */
    private static function sanitizeIntentContext(array $context): array
    {
        $allowed = [];
        foreach (['source', 'page', 'product_id', 'plan_id'] as $key) {
            if (array_key_exists($key, $context) && (is_scalar($context[$key]) || $context[$key] === null)) {
                $allowed[$key] = $context[$key];
            }
        }

        return $allowed;
    }
}

