<?php

declare(strict_types=1);

namespace BSP\VendorPortal\Rest;

use BPM\Modules\Vendor\GoogleCalendarSync;
use BSP\Bookings\Service\DietaryProfileService;
use BSP\Bookings\Service\PartnerConfirmationService;
use BSP\VendorPortal\Service\VendorPortalAuditLogger;
use BSP\VendorPortal\Service\VendorAuthService;
use BSP\VendorPortal\Service\VendorDashboardService;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use Throwable;
use function __;

final class PortalController
{
    private const LOGIN_RATE_LIMIT_WINDOW = 300;
    private const LOGIN_RATE_LIMIT_MAX    = 5;
    private const LOGIN_RATE_LIMIT_PREFIX = 'sbdp_vendor_portal_login_';

    private static ?VendorPortalAuditLogger $auditLogger = null;

    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route('bsp/v1', '/vendor-portal/login', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'login'),
            'permission_callback' => array(__CLASS__, 'authorizeLogin'),
        ));

        register_rest_route('bsp/v1', '/vendor-portal/dashboard', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'dashboard'),
            'permission_callback' => array(__CLASS__, 'authorizeSessionQuery'),
        ));

        register_rest_route('bsp/v1', '/vendor-portal/logout', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'logout'),
            'permission_callback' => array(__CLASS__, 'authorizeLogout'),
        ));

        register_rest_route('bsp/v1', '/vendor-portal/google-status', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'googleStatus'),
            'permission_callback' => array(__CLASS__, 'authorizeSessionQuery'),
        ));

        register_rest_route('bsp/v1', '/vendor-portal/google-sync', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'googleSync'),
            'permission_callback' => array(__CLASS__, 'authorizeSessionBody'),
        ));

        register_rest_route('bsp/v1', '/vendor-portal/confirmations/respond', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'respondToConfirmation'),
            'permission_callback' => array(__CLASS__, 'authorizeSessionBody'),
        ));

        register_rest_route('bsp/v1', '/vendor-portal/dietary/respond', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'respondToDietary'),
            'permission_callback' => array(__CLASS__, 'authorizeSessionBody'),
        ));
    }

    /**
     * @return true|WP_Error
     */
    public static function authorizeLogin(WP_REST_Request $request)
    {
        return self::checkLoginRateLimit($request);
    }

    /**
     * @return true|WP_Error
     */
    public static function authorizeSessionQuery(WP_REST_Request $request)
    {
        try {
            self::requireSession($request);
            return true;
        } catch (InvalidArgumentException $exception) {
            return new WP_Error('sbdp_vendor_portal_unauthorised', $exception->getMessage(), array('status' => 403));
        }
    }

    /**
     * @return true|WP_Error
     */
    public static function authorizeSessionBody(WP_REST_Request $request)
    {
        try {
            self::requireSession($request, true);
            return true;
        } catch (InvalidArgumentException $exception) {
            return new WP_Error('sbdp_vendor_portal_unauthorised', $exception->getMessage(), array('status' => 403));
        }
    }

    /**
     * @return true|WP_Error
     */
    public static function authorizeLogout(WP_REST_Request $request)
    {
        $token = self::extractToken($request, true);
        if ($token === '') {
            return true;
        }

        return self::authorizeSessionBody($request);
    }

    public static function login(WP_REST_Request $request)
    {
        $payload = self::getJson($request);

        try {
            $vendorId  = isset($payload['vendor_id']) ? (int) $payload['vendor_id'] : 0;
            $accessKey = isset($payload['access_key']) ? (string) $payload['access_key'] : '';

            $auth  = new VendorAuthService();
            $login = $auth->login($vendorId, $accessKey);
            self::resetLoginRateLimit($request);

            return self::respond($login);
        } catch (InvalidArgumentException $exception) {
            self::audit()->log('login_failure', array(
                'vendor_id' => isset($payload['vendor_id']) ? (string) $payload['vendor_id'] : '',
                'error'     => $exception->getMessage(),
            ));
            return self::respond(new WP_Error('sbdp_vendor_portal_login_failed', $exception->getMessage(), array('status' => 400)));
        }
    }

    public static function dashboard(WP_REST_Request $request)
    {
        try {
            $session   = self::requireSession($request);
            $dashboard = (new VendorDashboardService())->buildDashboard((int) $session['vendor_id']);

            self::audit()->log('dashboard_access', array(
                'vendor_id'       => (string) $session['vendor_id'],
                'upcoming_count'  => (string) count($dashboard['upcoming'] ?? array()),
                'total_bookings'  => (string) count($dashboard['bookings'] ?? array()),
            ));

            return self::respond(array(
                'dashboard' => $dashboard,
                'session'   => $session,
            ));
        } catch (InvalidArgumentException $exception) {
            self::audit()->log('dashboard_denied', array(
                'error' => $exception->getMessage(),
            ));
            return self::respond(new WP_Error('sbdp_vendor_portal_unauthorised', $exception->getMessage(), array('status' => 403)));
        }
    }

    public static function logout(WP_REST_Request $request)
    {
        $payload = self::getJson($request);
        $token   = isset($payload['token']) ? (string) $payload['token'] : '';

        if ($token === '') {
            self::audit()->log('logout_missing_token');
            return self::respond(array('success' => true));
        }

        $auth = new VendorAuthService();
        $vendorId = '';

        try {
            $session  = $auth->validateToken($token);
            $vendorId = isset($session['vendor_id']) ? (string) $session['vendor_id'] : '';
        } catch (InvalidArgumentException $exception) {
            self::audit()->log('logout_invalid_token', array(
                'token' => $token,
                'error' => $exception->getMessage(),
            ));
        }

        $auth->destroyToken($token);

        if ($vendorId !== '') {
            self::audit()->log('logout_success', array(
                'vendor_id' => $vendorId,
                'token'     => $token,
            ));
        }

        return self::respond(array('success' => true));
    }

    public static function googleStatus(WP_REST_Request $request)
    {
        try {
            $session = self::requireSession($request);
        } catch (InvalidArgumentException $exception) {
            self::audit()->log('google_status_denied', array(
                'error' => $exception->getMessage(),
            ));
            return self::respond(new WP_Error('sbdp_vendor_portal_unauthorised', $exception->getMessage(), array('status' => 403)));
        }

        if (! class_exists(GoogleCalendarSync::class)) {
            self::audit()->log('google_status_unavailable', array(
                'vendor_id' => (string) $session['vendor_id'],
            ));
            return self::respond(array(
                'status'  => array(
                    'connected' => false,
                    'message'   => __('Google Calendar synchronisatie is niet geconfigureerd.', 'sbdp'),
                ),
                'session' => $session,
            ));
        }

        try {
            $sync   = GoogleCalendarSync::boot();
            $status = $sync->getStatus((int) $session['vendor_id']);
        } catch (Throwable $exception) {
            self::audit()->log('google_status_error', array(
                'vendor_id' => (string) $session['vendor_id'],
                'error'     => $exception->getMessage(),
            ));
            return self::respond(new WP_Error('sbdp_vendor_portal_google_status', $exception->getMessage(), array('status' => 500)));
        }

        self::audit()->log('google_status_ok', array(
            'vendor_id' => (string) $session['vendor_id'],
        ));

        return self::respond(array(
            'status'  => $status,
            'session' => $session,
        ));
    }

    public static function googleSync(WP_REST_Request $request)
    {
        try {
            $session = self::requireSession($request, true);
        } catch (InvalidArgumentException $exception) {
            self::audit()->log('google_sync_denied', array(
                'error' => $exception->getMessage(),
            ));
            return self::respond(new WP_Error('sbdp_vendor_portal_unauthorised', $exception->getMessage(), array('status' => 403)));
        }

        if (! class_exists(GoogleCalendarSync::class)) {
            self::audit()->log('google_sync_unavailable', array(
                'vendor_id' => (string) $session['vendor_id'],
            ));
            return self::respond(new WP_Error(
                'sbdp_vendor_portal_google_unavailable',
                __('Google Calendar synchronisatie is niet beschikbaar.', 'sbdp'),
                array('status' => 501)
            ));
        }

        $payload = self::getJson($request);
        $date    = (string) ($payload['date'] ?? $request->get_param('date') ?? '');
        $date    = trim($date);
        if ($date === '') {
            $date = null;
        }

        try {
            $sync    = GoogleCalendarSync::boot();
            $result  = $sync->syncVendorAvailability((int) $session['vendor_id'], $date);
            $status  = $sync->getStatus((int) $session['vendor_id']);
        } catch (Throwable $exception) {
            self::audit()->log('google_sync_failed', array(
                'vendor_id' => (string) $session['vendor_id'],
                'error'     => $exception->getMessage(),
            ));
            return self::respond(new WP_Error('sbdp_vendor_portal_google_sync_failed', $exception->getMessage(), array('status' => 500)));
        }

        self::audit()->log('google_sync_success', array(
            'vendor_id' => (string) $session['vendor_id'],
            'date'      => (string) $date,
        ));

        return self::respond(array(
            'result'  => $result,
            'status'  => $status,
            'session' => $session,
        ));
    }

    public static function respondToConfirmation(WP_REST_Request $request)
    {
        try {
            $session = self::requireSession($request, true);
        } catch (InvalidArgumentException $exception) {
            self::audit()->log('partner_confirmation_denied', array(
                'error' => $exception->getMessage(),
            ));
            return self::respond(new WP_Error('sbdp_vendor_portal_unauthorised', $exception->getMessage(), array('status' => 403)));
        }

        $payload = self::getJson($request);
        $legKey = isset($payload['leg_key']) ? (string) $payload['leg_key'] : '';
        $action = isset($payload['action']) ? (string) $payload['action'] : '';
        $note = isset($payload['note']) ? (string) $payload['note'] : '';

        try {
            $confirmation = (new PartnerConfirmationService())->respond(
                (int) $session['vendor_id'],
                $legKey,
                $action,
                $note
            );
        } catch (InvalidArgumentException $exception) {
            self::audit()->log('partner_confirmation_failed', array(
                'vendor_id' => (string) $session['vendor_id'],
                'leg_key'   => $legKey,
                'action'    => $action,
                'error'     => $exception->getMessage(),
            ));
            return self::respond(new WP_Error('sbdp_vendor_portal_partner_confirmation_failed', $exception->getMessage(), array('status' => 400)));
        } catch (Throwable $exception) {
            self::audit()->log('partner_confirmation_error', array(
                'vendor_id' => (string) $session['vendor_id'],
                'leg_key'   => $legKey,
                'action'    => $action,
                'error'     => $exception->getMessage(),
            ));
            return self::respond(new WP_Error('sbdp_vendor_portal_partner_confirmation_error', $exception->getMessage(), array('status' => 500)));
        }

        self::audit()->log('partner_confirmation_success', array(
            'vendor_id' => (string) $session['vendor_id'],
            'leg_key'   => $legKey,
            'action'    => $action,
            'status'    => (string) ($confirmation['status'] ?? ''),
        ));

        return self::respond(array(
            'confirmation' => $confirmation,
            'session'      => $session,
        ));
    }

    public static function respondToDietary(WP_REST_Request $request)
    {
        try {
            $session = self::requireSession($request, true);
        } catch (InvalidArgumentException $exception) {
            self::audit()->log('partner_dietary_denied', array(
                'error' => $exception->getMessage(),
            ));
            return self::respond(new WP_Error('sbdp_vendor_portal_unauthorised', $exception->getMessage(), array('status' => 403)));
        }

        $payload = self::getJson($request);
        $legKey  = isset($payload['leg_key']) ? (string) $payload['leg_key'] : '';
        $action  = isset($payload['action']) ? (string) $payload['action'] : '';
        $note    = isset($payload['note']) ? (string) $payload['note'] : '';

        try {
            $result = (new DietaryProfileService())->respondToAllergen(
                (int) $session['vendor_id'],
                $legKey,
                $action,
                $note
            );
        } catch (InvalidArgumentException $exception) {
            self::audit()->log('partner_dietary_failed', array(
                'vendor_id' => (string) $session['vendor_id'],
                'leg_key'   => $legKey,
                'action'    => $action,
                'error'     => $exception->getMessage(),
            ));
            return self::respond(new WP_Error('sbdp_vendor_portal_dietary_failed', $exception->getMessage(), array('status' => 400)));
        } catch (Throwable $exception) {
            self::audit()->log('partner_dietary_error', array(
                'vendor_id' => (string) $session['vendor_id'],
                'leg_key'   => $legKey,
                'action'    => $action,
                'error'     => $exception->getMessage(),
            ));
            return self::respond(new WP_Error('sbdp_vendor_portal_dietary_error', $exception->getMessage(), array('status' => 500)));
        }

        self::audit()->log('partner_dietary_success', array(
            'vendor_id'      => (string) $session['vendor_id'],
            'leg_key'        => $legKey,
            'action'         => $action,
            'partner_status' => (string) ($result['partner_status'] ?? ''),
        ));

        return self::respond(array(
            'dietary' => $result,
            'session' => $session,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function requireSession(WP_REST_Request $request, bool $useBody = false): array
    {
        $token = self::extractToken($request, $useBody);

        $auth = new VendorAuthService();

        return $auth->validateToken($token);
    }

    private static function extractToken(WP_REST_Request $request, bool $useBody = false): string
    {
        $token = (string) $request->get_param('token');

        if ($token === '' && $useBody) {
            $payload = self::getJson($request);
            if (isset($payload['token'])) {
                $token = (string) $payload['token'];
            }
        }

        if ($token === '') {
            $token = (string) $request->get_header('X-SBDP-Vendor-Token');
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private static function getJson(WP_REST_Request $request): array
    {
        $params = $request->get_json_params();
        if (! is_array($params)) {
            return array();
        }

        return $params;
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    private static function respond($data)
    {
        if (function_exists('rest_ensure_response')) {
            return rest_ensure_response($data);
        }

        return $data;
    }

    private static function audit(): VendorPortalAuditLogger
    {
        if (self::$auditLogger === null) {
            self::$auditLogger = new VendorPortalAuditLogger();
        }

        return self::$auditLogger;
    }

    /**
     * @return true|WP_Error
     */
    private static function checkLoginRateLimit(WP_REST_Request $request)
    {
        $bucketKey = self::loginRateLimitKey($request);
        $attempts  = self::getRateLimitAttempts($bucketKey);

        if ($attempts >= self::LOGIN_RATE_LIMIT_MAX) {
            self::audit()->log('login_rate_limited', array(
                'ip' => self::getRequestIp($request),
            ));

            return new WP_Error(
                'sbdp_vendor_portal_rate_limited',
                __('Te veel inlogpogingen. Probeer het over enkele minuten opnieuw.', 'sbdp'),
                array('status' => 429)
            );
        }

        self::storeRateLimitAttempts($bucketKey, $attempts + 1);

        return true;
    }

    private static function resetLoginRateLimit(WP_REST_Request $request): void
    {
        $bucketKey = self::loginRateLimitKey($request);

        if (function_exists('delete_transient')) {
            delete_transient($bucketKey);
        }
    }

    private static function loginRateLimitKey(WP_REST_Request $request): string
    {
        $payload   = self::getJson($request);
        $vendorId  = isset($payload['vendor_id']) ? (int) $payload['vendor_id'] : 0;
        $identity  = self::getRequestIp($request) . '|' . $vendorId;

        return self::LOGIN_RATE_LIMIT_PREFIX . md5($identity);
    }

    private static function getRateLimitAttempts(string $bucketKey): int
    {
        if (function_exists('get_transient')) {
            $attempts = get_transient($bucketKey);
            if (is_numeric($attempts)) {
                return max(0, (int) $attempts);
            }
        }

        return 0;
    }

    private static function storeRateLimitAttempts(string $bucketKey, int $attempts): void
    {
        if (function_exists('set_transient')) {
            set_transient($bucketKey, max(1, $attempts), self::LOGIN_RATE_LIMIT_WINDOW);
        }
    }

    private static function getRequestIp(WP_REST_Request $request): string
    {
        $forwarded = (string) $request->get_header('X-Forwarded-For');
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            $ip    = trim((string) ($parts[0] ?? ''));
            if ($ip !== '') {
                return $ip;
            }
        }

        $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        return $remoteAddr !== '' ? $remoteAddr : 'unknown';
    }
}
