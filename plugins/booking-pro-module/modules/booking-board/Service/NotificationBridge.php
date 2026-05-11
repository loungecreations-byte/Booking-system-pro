<?php

declare(strict_types=1);

namespace BSP\BookingBoard\Service;

use BSP\Core\CoreServiceProvider;

final class NotificationBridge
{
    public function bookingCreated(array $booking): void
    {
        $this->dispatch('booking_created', $booking);
        $this->invalidateCache();
    }

    public function bookingRescheduled(array $booking): void
    {
        $this->dispatch('booking_rescheduled', $booking);
        $this->invalidateCache();
    }

    public function bookingUpdated(array $booking): void
    {
        $this->dispatch('booking_updated', $booking);
        $this->invalidateCache();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatch(string $event, array $payload): void
    {
        CoreServiceProvider::logger()->log(
            sprintf('Notification event %s dispatched for booking #%d', $event, $payload['id'] ?? 0)
        );

        if (function_exists('do_action')) {
            do_action('sbdp/notifications/trigger', $event, $payload);
        }
    }

    private function invalidateCache(): void
    {
        if (class_exists('\SBDP\BookingBoard\BookingBoardQuery')) {
            \SBDP\BookingBoard\BookingBoardQuery::invalidate_cache();
        }
    }
}
