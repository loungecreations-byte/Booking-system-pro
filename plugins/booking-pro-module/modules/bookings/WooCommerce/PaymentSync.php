<?php

declare(strict_types=1);

namespace BSP\Bookings\WooCommerce;

use BSP\Bookings\Service\BookingService;
use BSP\Bookings\WooCommerce\DietaryProductMeta;
use BSP\Core\CoreServiceProvider;
use InvalidArgumentException;
use Throwable;
use function get_post_meta;
use function function_exists;
use function sprintf;
use function wc_get_order;
use function wp_mail;
use function get_home_url;
use function __;

final class PaymentSync
{
    public static function handle(int $orderId): void
    {
        if ($orderId <= 0 || ! function_exists('get_post_meta')) {
            return;
        }

        $bookingId = (int) get_post_meta($orderId, '_sbdp_booking_id', true);
        if ($bookingId <= 0) {
            return;
        }

        try {
            BookingService::pay([
                'booking_id' => $bookingId,
                'method'     => 'woocommerce',
                'reference'  => (string) $orderId,
            ]);

            CoreServiceProvider::logger()->log(
                sprintf('Booking #%d marked as paid from WooCommerce order #%d', $bookingId, $orderId)
            );
        } catch (InvalidArgumentException|Throwable $exception) {
            CoreServiceProvider::logger()->log(
                sprintf('WooCommerce payment sync failed for order #%d: %s', $orderId, $exception->getMessage())
            );
        }
    }

    /**
     * Send the dietary intake email once the order is processing.
     */
    public static function sendDietaryIntakeEmail(int $orderId): void
    {
        if ($orderId <= 0 || ! function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($orderId);
        if (! $order) {
            return;
        }

        // Only send dietary email for products that explicitly require it.
        if (! DietaryProductMeta::orderRequiresDietary($order)) {
            return;
        }

        $email    = $order->get_billing_email();
        $orderKey = $order->get_order_key();
        if (! $email || ! $orderKey) {
            return;
        }

        $intakeUrl = sprintf('%s/dieet-opgave/?order_key=%s', get_home_url(), $orderKey);
        
        $subject = sprintf(__('Belangrijk: Uw dieetwensen voor uw boeking bij Dagje Den Bosch (#%s)', 'sbdp'), (string) $orderId);
        $message = sprintf(
            __("Beste klant,\n\nBedankt voor uw boeking bij Dagje Den Bosch. Om uw dag perfect te laten verlopen, vragen wij u om eventuele dieetwensen of allergieën van uw gezelschap door te geven via onderstaande link:\n\n%s\n\nMet vriendelijke groet,\nDagje Den Bosch", 'sbdp'),
            $intakeUrl
        );

        wp_mail($email, $subject, $message);
    }
}
