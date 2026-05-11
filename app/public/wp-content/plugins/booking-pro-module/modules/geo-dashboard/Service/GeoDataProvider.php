<?php

declare(strict_types=1);

namespace BSP\GeoDashboard\Service;

use BSP\Bookings\Service\BookingService;
use BSP\Sales\Vendors\VendorService;
use DateTimeImmutable;
use Throwable;

final class GeoDataProvider
{
    private const CITY_COORDINATES = [
        'amsterdam'  => ['lat' => 52.370216, 'lng' => 4.895168],
        'rotterdam'  => ['lat' => 51.924419, 'lng' => 4.477733],
        'utrecht'    => ['lat' => 52.090737, 'lng' => 5.12142],
        'the hague'  => ['lat' => 52.070497, 'lng' => 4.3007],
        'den haag'   => ['lat' => 52.070497, 'lng' => 4.3007],
        'eindhoven'  => ['lat' => 51.441642, 'lng' => 5.469722],
        'groningen'  => ['lat' => 53.219383, 'lng' => 6.566502],
        'haarlem'    => ['lat' => 52.387387, 'lng' => 4.646219],
        'maastricht' => ['lat' => 50.851368, 'lng' => 5.690973],
    ];

    private bool $vendorServiceInitialised = false;

    /** @var array<int, array<string, mixed>|null> */
    private array $vendorCache = [];

    /** @var array<int, array<string, float|null>> */
    private array $vendorLocations = [];

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getGeoData(array $filters = []): array
    {
        $vendors  = $this->collectVendors($filters);
        $bookings = $this->collectBookings($filters);

        return [
            'vendors'  => $vendors,
            'bookings' => $bookings,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function collectVendors(array $filters): array
    {
        $args = [];
        if (! empty($filters['vendor_status']) && $filters['vendor_status'] !== 'all') {
            $args['status'] = $filters['vendor_status'];
        }

        if (! $this->ensureVendorService()) {
            return [];
        }

        $vendors = VendorService::list($args, true);

        return array_map(function (array $vendor): array {
            $vendorId = (int) ($vendor['id'] ?? 0);
            $location = $this->extractVendorLocation($vendor);
            $location = $this->resolveVendorLocation($vendorId, $location, $vendor);
            $this->vendorLocations[$vendorId] = $location;

            return [
                'id'          => $vendorId,
                'name'        => (string) ($vendor['name'] ?? ''),
                'status'      => (string) ($vendor['status'] ?? ''),
                'rating'      => isset($vendor['rating']) ? (float) $vendor['rating'] : null,
                'workload'    => isset($vendor['workload']) ? (int) $vendor['workload'] : null,
                'location'    => $location,
                'resources'   => $vendor['resource_ids'] ?? [],
                'product_ids' => $vendor['product_ids'] ?? [],
            ];
        }, $vendors);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function collectBookings(array $filters): array
    {
        $statusFilter = (string) ($filters['booking_status'] ?? '');
        $startDate    = $this->parseDate((string) ($filters['start_date'] ?? ''));
        $endDate      = $this->parseDate((string) ($filters['end_date'] ?? ''));

        $bookings = BookingService::getBookings();

        $bookings = array_filter($bookings, static function (array $booking) use ($statusFilter): bool {
            if ($statusFilter === '' || $statusFilter === 'all') {
                return true;
            }

            return ($booking['status'] ?? '') === $statusFilter;
        });

        $bookings = array_filter($bookings, static function (array $booking) use ($startDate, $endDate): bool {
            $date = (string) ($booking['date'] ?? '');
            if ($date === '') {
                return true;
            }

            $timestamp = strtotime($date);
            if ($timestamp === false) {
                return true;
            }

            if ($startDate !== null && $timestamp < $startDate->getTimestamp()) {
                return false;
            }

            if ($endDate !== null && $timestamp > $endDate->getTimestamp()) {
                return false;
            }

            return true;
        });

        return array_values(array_map(function (array $booking): array {
            $vendorId = (int) ($booking['vendor_id'] ?? ($booking['vendor']['id'] ?? 0));
            $location = $booking['location'] ?? [];
            $location = $this->resolveVendorLocation($vendorId, $location, $booking);

            return [
                'id'          => (int) ($booking['id'] ?? 0),
                'status'      => (string) ($booking['status'] ?? ''),
                'date'        => (string) ($booking['date'] ?? ''),
                'time'        => (string) ($booking['time'] ?? ''),
                'vendor_id'   => $vendorId,
                'total'       => (float) ($booking['total'] ?? 0.0),
                'currency'    => (string) ($booking['currency'] ?? 'EUR'),
                'customer'    => (string) ($booking['customer']['name'] ?? ''),
                'participants'=> (int) ($booking['participants'] ?? 0),
                'location'    => $location,
            ];
        }, $bookings));
    }

    /**
     * @param array<string, mixed> $vendor
     * @return array<string, float|null>
     */
    private function extractVendorLocation(array $vendor): array
    {
        $location = $vendor['metadata']['location'] ?? [];

        if (! is_array($location)) {
            $location = [];
        }

        return [
            'lat' => isset($location['lat']) ? (float) $location['lat'] : null,
            'lng' => isset($location['lng']) ? (float) $location['lng'] : null,
        ];
    }

    private function resolveVendorLocation(int $vendorId, ?array $current = null, ?array $context = null): array
    {
        $location = $current ?? ['lat' => null, 'lng' => null];

        if ($this->hasCoordinates($location)) {
            return $location;
        }

        if (isset($this->vendorLocations[$vendorId]) && $this->hasCoordinates($this->vendorLocations[$vendorId])) {
            return $this->vendorLocations[$vendorId];
        }

        $vendor = $context ?? $this->fetchVendor($vendorId);

        if (is_array($vendor)) {
            $location = $this->extractVendorLocation($vendor);
            if ($this->hasCoordinates($location)) {
                $this->vendorLocations[$vendorId] = $location;
                return $location;
            }

            $city = $this->extractCityFromVendor($vendor);
            if ($city !== null) {
                $mapped = $this->resolveCityLocation($city);
                if ($mapped !== null) {
                    $this->vendorLocations[$vendorId] = $mapped;
                    return $mapped;
                }
            }
        }

        return $location;
    }

    private function extractCityFromVendor(array $vendor): ?string
    {
        $metadata = $vendor['metadata'] ?? [];

        $candidates = [
            $metadata['address']['city'] ?? null,
            $metadata['city'] ?? null,
            $vendor['city'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function resolveCityLocation(string $city): ?array
    {
        $key = strtolower(trim($city));
        if (isset(self::CITY_COORDINATES[$key])) {
            return self::CITY_COORDINATES[$key];
        }

        return null;
    }

    private function hasCoordinates(?array $location): bool
    {
        if (! is_array($location)) {
            return false;
        }

        return isset($location['lat'], $location['lng']) && $location['lat'] !== null && $location['lng'] !== null;
    }

    private function ensureVendorService(): bool
    {
        if (! class_exists(VendorService::class)) {
            return false;
        }

        if (! $this->vendorServiceInitialised) {
            VendorService::init();
            $this->vendorServiceInitialised = true;
        }

        return true;
    }

    private function fetchVendor(int $vendorId): ?array
    {
        if ($vendorId <= 0 || ! $this->ensureVendorService()) {
            return null;
        }

        if (array_key_exists($vendorId, $this->vendorCache)) {
            return $this->vendorCache[$vendorId];
        }

        try {
            $vendor = VendorService::get($vendorId, true);
        } catch (Throwable $exception) {
            $vendor = null;
        }

        $this->vendorCache[$vendorId] = is_array($vendor) ? $vendor : null;

        return $this->vendorCache[$vendorId];
    }

    private function parseDate(string $date): ?DateTimeImmutable
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($date);
        } catch (Throwable $exception) {
            return null;
        }
    }
}
