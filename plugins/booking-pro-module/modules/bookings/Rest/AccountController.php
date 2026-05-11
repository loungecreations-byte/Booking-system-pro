<?php

declare(strict_types=1);

namespace BSP\Bookings\Rest;

use BSP\Bookings\Service\BookingService;
use WP_REST_Request;
use function function_exists;
use function register_rest_route;
use function is_user_logged_in;
use function get_current_user_id;
use function add_shortcode;
use function rest_url;
use function esc_attr;
use function esc_js;
use function esc_html;
use function ob_start;
use function ob_get_clean;

final class AccountController
{
    public static function registerShortcode(): void
    {
        if (function_exists('add_shortcode')) {
            add_shortcode('bsp_account_bookings', array(__CLASS__, 'renderShortcode'));
        }
    }

    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route('bsp/v1', '/account/bookings', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'getMyBookings'),
            'permission_callback' => array(__CLASS__, 'authorizeUser'),
        ));
    }

    public static function authorizeUser(): bool
    {
        return is_user_logged_in();
    }

    public static function renderShortcode(): string
    {
        if (! is_user_logged_in()) {
            return '<div class="sbdp-account-bookings__gate"><p>' . esc_html('Log in om uw boekingen te bekijken.') . '</p></div>';
        }

        $apiUrl = function_exists('rest_url') ? esc_js(rest_url('bsp/v1/account/bookings')) : '';
        $nonce  = function_exists('wp_create_nonce') ? esc_attr(wp_create_nonce('wp_rest')) : '';

        ob_start();
        ?>
        <div id="sbdp-account-bookings-root" class="sbdp-account-bookings"
             data-api-url="<?php echo $apiUrl; ?>"
             data-nonce="<?php echo $nonce; ?>">
            <div class="sbdp-account-bookings__loading">Boekingen laden&hellip;</div>
        </div>
        <script>
        (function () {
            const root = document.getElementById('sbdp-account-bookings-root');
            if (!root) return;

            const apiUrl = root.dataset.apiUrl;
            const nonce  = root.dataset.nonce;

            function esc(str) {
                const d = document.createElement('div');
                d.textContent = String(str || '');
                return d.innerHTML;
            }

            function statusLabel(status) {
                const labels = { paid: 'Betaald', pending: 'In behandeling', cancelled: 'Geannuleerd', completed: 'Voltooid', draft: 'Concept' };
                return labels[status] || esc(status);
            }

            function render(bookings) {
                if (!bookings.length) {
                    root.innerHTML = '<div class="sbdp-account-bookings__empty"><p>Geen boekingen gevonden.</p></div>';
                    return;
                }

                let rows = bookings.map(function (b) {
                    return '<tr>'
                        + '<td>' + esc(b.date || '&mdash;') + '</td>'
                        + '<td>' + esc(b.time || '&mdash;') + '</td>'
                        + '<td>' + esc(b.resource || b.notes || '&mdash;') + '</td>'
                        + '<td>' + esc(b.participants || '&mdash;') + '</td>'
                        + '<td><span class="sbdp-status sbdp-status--' + esc(b.status) + '">' + statusLabel(b.status) + '</span></td>'
                        + '</tr>';
                }).join('');

                root.innerHTML = '<div class="sbdp-account-bookings__table-wrapper">'
                    + '<h2>Mijn Boekingen</h2>'
                    + '<table class="sbdp-account-bookings__table"><thead><tr>'
                    + '<th>Datum</th><th>Tijd</th><th>Activiteit</th><th>Deelnemers</th><th>Status</th>'
                    + '</tr></thead><tbody>' + rows + '</tbody></table>'
                    + '</div>';
            }

            fetch(apiUrl, {
                headers: { 'X-WP-Nonce': nonce }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    render(Array.isArray(data.bookings) ? data.bookings : []);
                })
                .catch(function () {
                    root.innerHTML = '<div class="sbdp-account-bookings__error">Er is een fout opgetreden. Probeer het opnieuw.</div>';
                });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    public static function getMyBookings(WP_REST_Request $request)
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return new \WP_Error('unauthorized', 'User not logged in', array('status' => 401));
        }

        $user  = get_userdata($userId);
        $email = $user ? $user->user_email : '';

        $bookings = BookingService::getBookings(array('customer_email' => $email));

        return rest_ensure_response(array(
            'bookings' => array_values($bookings),
            'success'  => true,
        ));
    }
}
