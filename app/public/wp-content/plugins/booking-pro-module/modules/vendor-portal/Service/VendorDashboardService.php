<?php

declare(strict_types=1);

namespace BSP\VendorPortal\Service;

use BPM\Modules\Vendor\GoogleCalendarSync;
use BSP\Bookings\Service\BookingService;
use BSP\Sales\Vendors\VendorService;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;
use wpdb;

use function is_array;

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
        $calendar  = $this->getCalendarStatus($vendorId);
        $confirmations = $this->getPartnerConfirmations($vendorId);
        $dietarySummary = $this->getDietarySummaryForVendor($vendorId);

        return array(
            'upcoming'      => array_slice($upcoming, 0, 10),
            'bookings'      => $enriched,
            'financial'     => $financial,
            'calendar'      => $calendar,
            'confirmations' => $confirmations,
            'dietary'       => $dietarySummary,
            'vendor'        => $this->getVendorProfile($vendorId),
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

    /**
     * @return array<string, mixed>
     */
    private function getCalendarStatus(int $vendorId): array
    {
        if (! class_exists(GoogleCalendarSync::class)) {
            return array(
                'connected' => false,
            );
        }

        try {
            $sync = GoogleCalendarSync::boot();

            return $sync->getStatus($vendorId);
        } catch (Throwable $exception) {
            return array(
                'connected'  => false,
                'last_error' => array(
                    'message'     => $exception->getMessage(),
                    'occurred_at' => gmdate('c'),
                ),
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getVendorProfile(int $vendorId): ?array
    {
        if (! class_exists(VendorService::class)) {
            return null;
        }

        try {
            $vendor = VendorService::get($vendorId, true);
        } catch (Throwable $exception) {
            return null;
        }

        if (! is_array($vendor)) {
            return null;
        }

        $profile = array(
            'id'            => (int) ($vendor['id'] ?? $vendorId),
            'name'          => (string) ($vendor['name'] ?? ''),
            'status'        => (string) ($vendor['status'] ?? ''),
            'contact_name'  => (string) ($vendor['contact_name'] ?? ''),
            'contact_email' => (string) ($vendor['contact_email'] ?? ''),
            'contact_phone' => (string) ($vendor['contact_phone'] ?? ''),
        );

        if (isset($vendor['resource_ids']) && is_array($vendor['resource_ids'])) {
            $profile['resource_ids'] = array_values($vendor['resource_ids']);
        }

        if (isset($vendor['product_ids']) && is_array($vendor['product_ids'])) {
            $profile['product_ids'] = array_values($vendor['product_ids']);
        }

        if (isset($vendor['metadata']) && is_array($vendor['metadata'])) {
            $profile['metadata'] = $vendor['metadata'];
        }

        return $profile;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getPartnerConfirmations(int $vendorId): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb || $vendorId <= 0) {
            return [];
        }

        $table = $wpdb->prefix . 'bsp_partner_confirmations';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT booking_reference, leg_key, status, scheduled_date, scheduled_time, scheduled_end_time, participants, payload
                 FROM {$table}
                 WHERE supplier_id = %d
                 ORDER BY scheduled_date ASC, scheduled_time ASC",
                $vendorId
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        $confirmations = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            $leg = is_array($payload) && isset($payload['leg']) && is_array($payload['leg']) ? $payload['leg'] : [];

            $confirmations[] = [
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'leg_key'           => (string) ($row['leg_key'] ?? ''),
                'status'            => (string) ($row['status'] ?? ''),
                'scheduled_date'    => (string) ($row['scheduled_date'] ?? ''),
                'scheduled_time'    => (string) ($row['scheduled_time'] ?? ''),
                'scheduled_end_time'=> (string) ($row['scheduled_end_time'] ?? ''),
                'participants'      => (int) ($row['participants'] ?? 0),
                'responded_at'      => (string) ($row['responded_at'] ?? ''),
                'confirmed_at'      => (string) ($row['confirmed_at'] ?? ''),
                'title'             => is_array($payload) ? (string) ($payload['title'] ?? '') : '',
                'customer_name'     => is_array($payload) ? (string) ($payload['customer_name'] ?? '') : '',
                'customer_email'    => is_array($payload) ? (string) ($payload['customer_email'] ?? '') : '',
                'partner_note'      => is_array($payload) && isset($payload['partner_response']['note'])
                    ? (string) $payload['partner_response']['note']
                    : '',
                'partner_card'      => is_array($payload) && isset($payload['partner_card']) && is_array($payload['partner_card'])
                    ? $payload['partner_card']
                    : [],
                'leg_type'          => isset($leg['leg_type']) ? (string) $leg['leg_type'] : '',
            ];
        }

        return $confirmations;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getDietarySummaryForVendor(int $vendorId): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb || $vendorId <= 0) {
            return [];
        }

        $confirmTable = $wpdb->prefix . 'bsp_partner_confirmations';
        $dietaryTable = $wpdb->prefix . 'bsp_guest_dietary_profiles';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT d.booking_reference, d.guest_name, d.menu_choice, d.allergen_flags, d.severity, d.notes, d.partner_status, c.leg_key
                 FROM {$dietaryTable} d
                 JOIN {$confirmTable} c ON d.master_id = c.master_id
                 WHERE c.supplier_id = %d
                 ORDER BY d.master_id DESC, d.guest_index ASC",
                $vendorId
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        $summary = [];
        foreach ($rows as $row) {
            $ref = (string) $row['booking_reference'];
            if (! isset($summary[$ref])) {
                $summary[$ref] = [
                    'booking_reference' => $ref,
                    'profiles' => [],
                    'leg_key' => (string) $row['leg_key'],
                ];
            }

            $summary[$ref]['profiles'][] = [
                'guest_name'     => (string) $row['guest_name'],
                'menu_choice'    => (string) $row['menu_choice'],
                'allergen_flags' => json_decode((string) ($row['allergen_flags'] ?? '[]'), true),
                'severity'       => (string) $row['severity'],
                'notes'          => (string) $row['notes'],
                'partner_status' => (string) $row['partner_status'],
            ];
        }

        return $summary;
    }
}
