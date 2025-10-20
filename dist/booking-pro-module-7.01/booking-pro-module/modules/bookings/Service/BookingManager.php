<?php

declare(strict_types=1);

namespace BSP\Bookings\Service;

use BSP\Commerce\Module as CommerceModule;
use BSP\Core\CoreServiceProvider;
use BSP\Planner\Module as PlannerModule;
use BSP\Planner\Vendor\CityGuideProfile;
use BSP\Planner\Vendor\CityGuideProfileStore;
use BSP\Sales\Vendors\VendorService;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final class BookingManager
{
    private const DEFAULT_CURRENCY = 'EUR';

    public function __construct(
        private BookingRepository $repository,
        private CommerceModule $commerce,
        private PlannerModule $planner,
        private CityGuideProfileStore $profiles
    ) {
    }

    public static function createDefault(?BookingRepository $repository = null): self
    {
        $repository ??= new BookingRepository();
        $profiles    = new CityGuideProfileStore();
        $planner     = new PlannerModule($profiles);
        $commerce    = new CommerceModule();

        return new self($repository, $commerce, $planner, $profiles);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function createBooking(array $data): array
    {
        $payload   = $this->validatePayload($data);
        $existing  = $this->repository->all();
        $record    = $this->buildBookingRecord($payload, 'created');
        $record    = $this->assignPlannerDetails($record, $payload);
        $record    = $this->enrichWithVendor($record, $payload);
        $record    = $this->enforceAvailability($record, $existing);
        $stored    = $this->repository->create($record);

        CoreServiceProvider::logger()->log(
            sprintf('Booking #%d created with status %s', $stored['id'], $stored['status'])
        );

        return $stored;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function requestBooking(array $data): array
    {
        $created = $this->createBooking($data);

        $updated = $this->repository->update($created['id'], [
            'status' => 'requested',
        ]);

        $this->notifyAdmin($updated);
        CoreServiceProvider::logger()->log(sprintf('Booking #%d requested', $updated['id']));

        return $updated;
    }

    public function payBooking(int $bookingId, string $method): array
    {
        return $this->payBookingWithReference($bookingId, $method, null);
    }

    public function payBookingWithReference(int $bookingId, string $method, ?string $reference): array
    {
        $booking = $this->repository->find($bookingId);
        if ($booking === null) {
            throw new InvalidArgumentException('Unknown booking identifier.');
        }

        $method = trim($method);
        if ($method === '') {
            throw new InvalidArgumentException('Payment method is required.');
        }

        $orderMessage = $this->commerce->processOrder($bookingId);
        $timestamp    = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);

        $updated = $this->repository->update($bookingId, [
            'status'  => 'paid',
            'paid_at' => $timestamp,
            'payment' => [
                'method'    => $method,
                'reference' => $reference ?? '',
            ],
            'order'   => [
                'status_message' => $orderMessage,
                'processed_at'   => $timestamp,
            ],
        ]);

        $this->triggerWooCommerceOrder($updated);
        CoreServiceProvider::logger()->log(
            sprintf('Booking #%d paid via %s', $bookingId, $method)
        );

        return $updated;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBookings(): array
    {
        return $this->repository->all();
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function assignPlannerDetails(array $record, array $payload): array
    {
        $resources = $this->collectPlannerResources();
        $allocation = $this->planner->assignResource(
            [
                'resource' => $record['resource'],
                'time'     => $payload['time'],
                'name'     => $record['customer']['name'],
            ],
            $resources
        );

        $record['resource'] = $allocation['resource'] ?? 'unassigned';
        $record['planner']  = [
            'resource' => $record['resource'],
            'slot'     => $payload['time'],
            'timeline' => $this->planner->generateSchedule([
                [
                    'time'     => $payload['time'],
                    'name'     => $record['customer']['name'],
                    'resource' => $record['resource'],
                ],
            ]),
        ];

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function enrichWithVendor(array $record, array $payload): array
    {
        $vendorId = $payload['vendor_id'];
        if ($vendorId === null || $vendorId <= 0) {
            return $record;
        }

        $record['vendor'] = [
            'id'           => $vendorId,
            'name'         => null,
            'status'       => null,
            'availability' => [],
        ];

        if (! class_exists(VendorService::class)) {
            return $record;
        }

        if (! function_exists('get_post') || ! isset($GLOBALS['wpdb'])) {
            return $record;
        }

        try {
            VendorService::init();
            $data = VendorService::get($vendorId, true);
            if (is_array($data)) {
                $record['vendor']['name']   = isset($data['name']) ? (string) $data['name'] : null;
                $record['vendor']['status'] = isset($data['status']) ? (string) $data['status'] : null;
                $record['vendor']['availability'] = $this->extractVendorAvailability($vendorId);
            }
        } catch (Throwable $exception) {
            CoreServiceProvider::logger()->log(
                sprintf('Vendor lookup failed for #%d: %s', $vendorId, $exception->getMessage())
            );
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, array<string, mixed>> $existing
     *
     * @return array<string, mixed>
     */
    private function enforceAvailability(array $record, array $existing): array
    {
        $plannerPayload = [];
        foreach ($existing as $booking) {
            $plannerPayload[] = [
                'time'     => (string) ($booking['planner']['slot'] ?? $booking['time'] ?? ''),
                'resource' => (string) ($booking['planner']['resource'] ?? $booking['resource'] ?? ''),
                'name'     => (string) ($booking['customer']['name'] ?? ''),
            ];
        }

        $plannerPayload[] = [
            'time'     => (string) ($record['planner']['slot'] ?? $record['time']),
            'resource' => (string) ($record['planner']['resource'] ?? $record['resource']),
            'name'     => (string) $record['customer']['name'],
        ];

        $hasConflict = $this->planner->hasOverlap($plannerPayload);
        $record['conflict'] = $hasConflict;
        if ($hasConflict) {
            $record['status'] = 'conflict';
        }

        return $record;
    }

    private function notifyAdmin(array $booking): void
    {
        if (! function_exists('get_option') || ! function_exists('wp_mail')) {
            return;
        }

        $adminEmail = (string) get_option('admin_email');
        if ($adminEmail === '') {
            return;
        }

        $subject = function_exists('__') ? __('Nieuwe boekingsaanvraag', 'sbdp') : 'Nieuwe boekingsaanvraag';
        $message = sprintf(
            "Nieuwe boekingsaanvraag #%d\nNaam: %s\nDatum: %s\nTijd: %s",
            (int) ($booking['id'] ?? 0),
            (string) ($booking['customer']['name'] ?? ''),
            (string) ($booking['date'] ?? ''),
            (string) ($booking['time'] ?? '')
        );

        wp_mail($adminEmail, $subject, $message);
    }

    private function triggerWooCommerceOrder(array $booking): void
    {
        if (function_exists('do_action')) {
            do_action('sbdp/booking/woocommerce_order_stub', $booking);
        }

        if (function_exists('wc_create_order')) {
            try {
                $order = wc_create_order();
                $order->add_meta_data('_sbdp_booking_id', $booking['id'] ?? 0, true);
                $order->save();
            } catch (Throwable $exception) {
                CoreServiceProvider::logger()->log(
                    sprintf('WooCommerce order stub failed for booking #%d: %s', $booking['id'] ?? 0, $exception->getMessage())
                );
            }

            return;
        }

        CoreServiceProvider::logger()->log(
            sprintf('WooCommerce order stub triggered for booking #%d', $booking['id'] ?? 0)
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function buildBookingRecord(array $payload, string $status): array
    {
        $baseAmount = 0.0;
        foreach ($payload['items'] as $item) {
            $baseAmount += $item['unit_price'] * $item['quantity'];
        }

        $total     = $this->commerce->calculatePrice($baseAmount, $payload['pricing_rules']);
        $reserved  = $this->commerce->reserveInventory($payload['items']);
        $timestamp = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);

        return [
            'status'             => $status,
            'customer'           => $payload['customer'],
            'date'               => $payload['date'],
            'time'               => $payload['time'],
            'participants'       => $payload['participants'],
            'items'              => $payload['items'],
            'notes'              => $payload['notes'],
            'currency'           => $payload['currency'],
            'total'              => $total,
            'created_at'         => $timestamp,
            'pricing_rules'      => $payload['pricing_rules'],
            'inventory_reserved' => $reserved,
            'channel'            => $payload['channel'],
            'vendor'             => null,
            'resource'           => 'unassigned',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload): array
    {
        $customer = $payload['customer'] ?? [];
        if (! is_array($customer)) {
            throw new InvalidArgumentException('Customer details are required.');
        }

        $name  = trim((string) ($customer['name'] ?? ''));
        $email = trim((string) ($customer['email'] ?? ''));
        if ($name === '' || $email === '') {
            throw new InvalidArgumentException('Customer name and email are required.');
        }

        $date = (string) ($payload['date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Booking date must be in YYYY-MM-DD format.');
        }

        $time = trim((string) ($payload['time'] ?? '09:00'));
        if ($time === '') {
            $time = '09:00';
        }

        $participants = (int) ($payload['participants'] ?? 0);
        if ($participants <= 0) {
            throw new InvalidArgumentException('Participants must be greater than zero.');
        }

        $items = $payload['items'] ?? [];
        if (! is_array($items) || $items === []) {
            throw new InvalidArgumentException('At least one booking item is required.');
        }

        $pricingRules = $payload['pricing_rules'] ?? [];
        if (! is_array($pricingRules)) {
            $pricingRules = [];
        }

        $notes = isset($payload['notes']) ? (string) $payload['notes'] : null;
        $currency = (string) ($payload['currency'] ?? self::DEFAULT_CURRENCY);
        if ($currency === '') {
            $currency = self::DEFAULT_CURRENCY;
        }

        $channel = isset($payload['channel']) ? (string) $payload['channel'] : null;
        if ($channel !== null && $channel === '') {
            $channel = null;
        }

        $vendorId = isset($payload['vendor_id']) ? (int) $payload['vendor_id'] : null;
        if ($vendorId !== null && $vendorId <= 0) {
            $vendorId = null;
        }

        return [
            'customer'      => [
                'name'  => $name,
                'email' => $email,
                'phone' => trim((string) ($customer['phone'] ?? '')),
            ],
            'date'          => $date,
            'time'          => $time,
            'participants'  => $participants,
            'items'         => $this->normalizeItems($items),
            'pricing_rules' => $pricingRules,
            'notes'         => $notes,
            'currency'      => $currency,
            'channel'       => $channel,
            'vendor_id'     => $vendorId,
        ];
    }

    /**
     * @param array<int, mixed> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            $quantity  = (int) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0.0);

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $normalized[] = [
                'product_id' => $productId,
                'quantity'   => $quantity,
                'unit_price' => $unitPrice,
                'label'      => isset($item['label']) ? (string) $item['label'] : '',
            ];
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('Valid booking items are required.');
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectPlannerResources(): array
    {
        if (function_exists('get_posts')) {
            try {
                return array_map(
                    static fn (CityGuideProfile $profile): array => [
                        'id'   => $profile->id,
                        'name' => $profile->name,
                    ],
                    $this->profiles->all()
                );
            } catch (Throwable) {
                return [];
            }
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractVendorAvailability(int $vendorId): array
    {
        if (! class_exists(VendorService::class)) {
            return [];
        }

        try {
            $resources = VendorService::getResources($vendorId);
            if (! is_array($resources)) {
                return [];
            }

            return array_map(
                static fn (array $resource): array => [
                    'id'           => (int) ($resource['id'] ?? 0),
                    'title'        => (string) ($resource['title'] ?? ''),
                    'availability' => $resource['availability'] ?? [],
                ],
                $resources
            );
        } catch (Throwable $exception) {
            CoreServiceProvider::logger()->log(
                sprintf('Vendor availability lookup failed for #%d: %s', $vendorId, $exception->getMessage())
            );
        }

        return [];
    }
}

