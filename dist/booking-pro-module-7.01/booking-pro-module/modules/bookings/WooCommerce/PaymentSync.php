<?php

declare(strict_types=1);

namespace BSP\Bookings\WooCommerce;

use BSP\Bookings\Service\BookingService;
use BSP\Core\CoreServiceProvider;
use InvalidArgumentException;
use Throwable;

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
}
