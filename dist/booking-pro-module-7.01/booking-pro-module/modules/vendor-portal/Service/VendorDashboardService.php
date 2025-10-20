<?php

declare(strict_types=1);

namespace BSP\VendorPortal\Service;

use BSP\Bookings\Service\BookingService;
use DateTimeImmutable;
use InvalidArgumentException;

final class VendorDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function buildDashboard(int $vendorId): array
    {
        if ($vendorId <= 0) {
            throw new InvalidArgumentException(__('Vendor ID ontbreekt.', 'sbdp'));
        }

        $bookings = BookingService::getBookings();
        $filtered = array_values(array_filter($bookings, static function (array $booking) use ($vendorId): bool {
            $bookingVendorId = 0;

            if (isset($booking['vendor_id'])) {
                $bookingVendorId = (int) $booking['vendor_id'];
            } elseif (isset($booking['vendor']['id'])) {
                $bookingVendorId = (int) $booking['vendor']['id'];
            }

            return $bookingVendorId === $vendorId;
        }));

        $enriched = array_map([$this, 'formatBooking'], $filtered);

        usort($enriched, static function (array $left, array $right): int {
            return $left['timestamp'] <=> $right['timestamp'];
        });

        $now      = time();
        $upcoming = array_values(array_filter($enriched, static function (array $booking) use ($now): bool {
            return $booking['timestamp'] >= $now;
        }));

        $financial = $this->buildFinancialSummary($enriched);

        return array(
            'upcoming'  => array_slice($upcoming, 0, 10),
            'bookings'  => $enriched,
            'financial' => $financial,
        );
    }

    /**
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    private function formatBooking(array $booking): array
    {
        $date       = (string) ($booking['date'] ?? '');
        $timeSlot   = (string) ($booking['time'] ?? '');
        $status     = (string) ($booking['status'] ?? '');
        $currency   = (string) ($booking['currency'] ?? 'EUR');
        $total      = (float) ($booking['total'] ?? 0.0);
        $resource   = (string) ($booking['planner']['resource'] ?? ($booking['resource'] ?? ''));
        $customer   = (array) ($booking['customer'] ?? array());
        $customerNm = trim((string) ($customer['name'] ?? ''));

        $timestamp = strtotime(trim($date . ' ' . $timeSlot)) ?: time();

        $iso = (new DateTimeImmutable('@' . $timestamp))->format(DateTimeImmutable::ATOM);

        return array(
            'id'          => (int) ($booking['id'] ?? 0),
            'status'      => $status,
            'date'        => $date,
            'time'        => $timeSlot,
            'slot_iso'    => $iso,
            'timestamp'   => $timestamp,
            'customer'    => $customerNm,
            'participants'=> (int) ($booking['participants'] ?? 0),
            'resource'    => $resource,
            'notes'       => (string) ($booking['notes'] ?? ''),
            'currency'    => $currency,
            'total'       => round($total, 2),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     * @return array<string, mixed>
     */
    private function buildFinancialSummary(array $bookings): array
    {
        $currency       = 'EUR';
        $totalRevenue   = 0.0;
        $paidRevenue    = 0.0;
        $pendingRevenue = 0.0;
        $counts         = array();

        foreach ($bookings as $booking) {
            $currency = (string) ($booking['currency'] ?? $currency);
            $total    = (float) ($booking['total'] ?? 0.0);
            $status   = (string) ($booking['status'] ?? '');

            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $totalRevenue   += $total;

            if ($status === 'paid') {
                $paidRevenue += $total;
            } else {
                $pendingRevenue += $total;
            }
        }

        return array(
            'currency'         => $currency,
            'total_revenue'    => round($totalRevenue, 2),
            'paid_revenue'     => round($paidRevenue, 2),
            'pending_revenue'  => round($pendingRevenue, 2),
            'booking_counts'   => $counts,
            'total_bookings'   => array_sum($counts),
        );
    }
}
