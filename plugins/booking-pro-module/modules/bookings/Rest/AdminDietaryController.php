<?php

declare(strict_types=1);

namespace BSP\Bookings\Rest;

use BSP\Bookings\Service\DietaryProfileService;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use function function_exists;
use function register_rest_route;
use function current_user_can;
use function sanitize_text_field;
use function rest_ensure_response;
use function get_option;
use function wp_mail;

/**
 * Admin-facing endpoint for managing dietary/allergen profiles.
 * Partners respond via vendor-portal; this controller handles admin overrides.
 */
final class AdminDietaryController
{
    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route('bsp/v1', '/admin/dietary/respond', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handleResponse'),
            'permission_callback' => array(__CLASS__, 'authorizeAdmin'),
        ));

        register_rest_route('bsp/v1', '/admin/dietary/list', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'listPending'),
            'permission_callback' => array(__CLASS__, 'authorizeAdmin'),
        ));
    }

    public static function authorizeAdmin(): bool
    {
        return function_exists('current_user_can')
            && (current_user_can('manage_options') || current_user_can('manage_woocommerce'));
    }

    /**
     * Admin override: respond to a dietary profile on behalf of a vendor.
     * Body: { vendor_id: int, leg_key: string, action: "accept"|"reject", note: string }
     */
    public static function handleResponse(WP_REST_Request $request)
    {
        $params   = $request->get_json_params();
        $vendorId = (int) ($params['vendor_id'] ?? 0);
        $legKey   = sanitize_text_field($params['leg_key'] ?? '');
        $action   = sanitize_text_field($params['action'] ?? '');
        $note     = sanitize_text_field($params['note'] ?? '');

        if ($vendorId <= 0 || $legKey === '' || $action === '') {
            return new WP_Error('invalid_params', 'vendor_id, leg_key en action zijn verplicht.', array('status' => 400));
        }

        if (! in_array($action, ['accept', 'reject'], true)) {
            return new WP_Error('invalid_action', 'action moet "accept" of "reject" zijn.', array('status' => 400));
        }

        try {
            $result = (new DietaryProfileService())->respondToAllergen($vendorId, $legKey, $action, $note);
        } catch (InvalidArgumentException $exception) {
            return new WP_Error('dietary_respond_failed', $exception->getMessage(), array('status' => 400));
        }

        return rest_ensure_response(array('success' => true, 'data' => $result));
    }

    /**
     * List all dietary profiles with pending_review partner_status for admin triage.
     */
    public static function listPending(WP_REST_Request $request)
    {
        global $wpdb;
        if (! $wpdb instanceof \wpdb) {
            return new WP_Error('db_unavailable', 'Database niet beschikbaar.', array('status' => 503));
        }

        $table = $wpdb->prefix . 'bsp_guest_dietary_profiles';
        $rows  = $wpdb->get_results(
            "SELECT booking_reference, guest_name, allergen_flags, severity, notes, partner_status, created_at
             FROM {$table}
             WHERE partner_status = 'pending_review'
             ORDER BY severity DESC, created_at ASC",
            ARRAY_A
        );

        if (! is_array($rows)) {
            $rows = [];
        }

        return rest_ensure_response(array('profiles' => $rows));
    }

    public static function notifyAdminOfRejection(int $bookingId, string $reason): void
    {
        if (! function_exists('get_option') || ! function_exists('wp_mail')) {
            return;
        }

        $adminEmail = (string) get_option('admin_email', '');
        if ($adminEmail === '') {
            return;
        }

        $subject = sprintf('[ALARM] Dieetwens AFGEWEZEN voor Boeking #%d', $bookingId);
        $message = sprintf(
            "Partner heeft een dieetwens/allergie verzoek afgewezen voor boeking #%d.\n\nReden: %s\n\nOnderneem direct actie om de veiligheid van de gast te waarborgen.",
            $bookingId,
            $reason !== '' ? $reason : 'Geen reden opgegeven'
        );

        wp_mail($adminEmail, $subject, $message);
    }
}

