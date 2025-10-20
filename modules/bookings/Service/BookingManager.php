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

    private PaymentRequestDispatcher $paymentDispatcher;

    public function __construct(
        private BookingRepository $repository,
        private CommerceModule $commerce,
        private PlannerModule $planner,
        private CityGuideProfileStore $profiles,
        ?PaymentRequestDispatcher $paymentDispatcher = null
    ) {
        $this->paymentDispatcher = $paymentDispatcher ?? new PaymentRequestDispatcher();
    }

    public static function createDefault(?BookingRepository $repository = null): self
    {
        $repository ??= new BookingRepository();
        $profiles    = new CityGuideProfileStore();
        $planner     = new PlannerModule($profiles);
        $commerce    = new CommerceModule();
        $dispatcher  = new PaymentRequestDispatcher();

        return new self($repository, $commerce, $planner, $profiles, $dispatcher);
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
        $status    = $payload['status'];
        unset($payload['status']);
        $record    = $this->buildBookingRecord($payload, $status);
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

        $updated = $this->repository->update(
            $created['id'],
            [
                'status'     => $this->normalizeStatus('requested'),
                'updated_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            ]
        );

        $this->notifyAdmin($updated);
        CoreServiceProvider::logger()->log(sprintf('Booking #%d requested', $updated['id']));

        $paymentMeta = $this->paymentDispatcher->prepare($updated);
        if (is_array($paymentMeta)) {
            $updated = $this->applyCapturedState($updated, $paymentMeta);
            $this->notifyCaptured($updated);
        }

        return $updated;
    }

    public function dispatchInvoice(int $bookingId, bool $force = false): array
    {
        return $this->processInvoice($bookingId, true, $force);
    }

    public function prepareInvoiceDocument(int $bookingId): array
    {
        return $this->processInvoice($bookingId, false, false);
    }

    private function processInvoice(int $bookingId, bool $sendEmail, bool $refreshTimestamp): array
    {
        $booking = $this->repository->find($bookingId);
        if ($booking === null) {
            throw new InvalidArgumentException('Unknown booking identifier.');
        }

        $payload = $this->paymentDispatcher->prepare($booking, $sendEmail);
        if (! is_array($payload)) {
            return $booking;
        }

        $updated = $this->applyCapturedState(
            $booking,
            $payload,
            $refreshTimestamp,
            $sendEmail
        );

        if ($sendEmail) {
            $this->notifyCaptured($updated);
        }

        $this->notifyInvoiceIssued(
            $updated,
            $payload['order']['id'] ?? null,
            $sendEmail
        );

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

        $orderDetails = $booking['order'] ?? [];
        if (! is_array($orderDetails)) {
            $orderDetails = [];
        }

        $orderDetails['status_message'] = $orderMessage;
        $orderDetails['processed_at']   = $timestamp;
        $orderDetails['status']         = 'processing';

        $updatePayload = [
            'status'     => $this->normalizeStatus('paid'),
            'paid_at'    => $timestamp,
            'updated_at' => $timestamp,
            'payment'    => [
                'method'    => $method,
                'reference' => $reference ?? '',
            ],
            'order'      => $orderDetails,
        ];

        $paymentRequest = $booking['payment_request'] ?? null;
        if (is_array($paymentRequest)) {
            $paymentRequest['status']       = 'completed';
            $paymentRequest['completed_at'] = $timestamp;
            if ($reference !== null && $reference !== '') {
                $paymentRequest['reference'] = $reference;
            }
            $updatePayload['payment_request'] = $paymentRequest;
        }

        $updated = $this->repository->update($bookingId, $updatePayload);

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

    public function rescheduleBooking(
        int $bookingId,
        string $date,
        string $time,
        ?string $dateEnd = null,
        ?string $timeEnd = null
    ): array {
        $booking = $this->repository->find($bookingId);
        if ($booking === null) {
            throw new InvalidArgumentException('Unknown booking identifier.');
        }

        $date = $this->sanitizeDate($date);
        $time = $this->sanitizeTime($time);
        $dateEnd = $dateEnd !== null ? $this->sanitizeDate($dateEnd) : $date;
        $timeEnd = $timeEnd !== null ? $this->sanitizeTime($timeEnd) : $time;

        $payload = [
            'time'     => $time,
            'customer' => $booking['customer'],
        ];

        $recordWithPlanner = $this->assignPlannerDetails(
            array_merge($booking, ['date' => $date, 'time' => $time]),
            $payload
        );

        $timestamp = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);

        $updated = $this->repository->update(
            $bookingId,
            [
                'date'       => $date,
                'time'       => $time,
                'date_end'   => $dateEnd,
                'time_end'   => $timeEnd,
                'updated_at' => $timestamp,
                'planner'    => $recordWithPlanner['planner'] ?? ($booking['planner'] ?? null),
            ]
        );

        $this->notifyReschedule($updated);

        return $updated;
    }

    /**
     * @param array<string, mixed> $mutations
     */
    public function updateBookingDetails(int $bookingId, array $mutations): array
    {
        $booking = $this->repository->find($bookingId);
        if ($booking === null) {
            throw new InvalidArgumentException('Unknown booking identifier.');
        }

        $needsReschedule = isset($mutations['date'])
            || isset($mutations['time'])
            || isset($mutations['date_end'])
            || isset($mutations['time_end']);
        if ($needsReschedule) {
            $nextDateStart = isset($mutations['date'])
                ? (string) $mutations['date']
                : (string) $booking['date'];
            $nextTimeStart = isset($mutations['time'])
                ? (string) $mutations['time']
                : (string) $booking['time'];
            $nextDateEnd = isset($mutations['date_end'])
                ? (string) $mutations['date_end']
                : (string) ($booking['date_end'] ?? $booking['date']);
            $nextTimeEnd = isset($mutations['time_end'])
                ? (string) $mutations['time_end']
                : (string) ($booking['time_end'] ?? $booking['time']);

            $booking = $this->rescheduleBooking(
                $bookingId,
                $nextDateStart,
                $nextTimeStart,
                $nextDateEnd,
                $nextTimeEnd
            );
        }

        $changes = [];

        if (array_key_exists('notes', $mutations)) {
            $changes['notes'] = isset($mutations['notes']) ? (string) $mutations['notes'] : null;
        }

        if (array_key_exists('status', $mutations)) {
            $changes['status'] = $this->normalizeStatus((string) $mutations['status']);
        }

        if (array_key_exists('participants', $mutations)) {
            $participants = (int) $mutations['participants'];
            if ($participants <= 0) {
                throw new InvalidArgumentException('Participants must be greater than zero.');
            }

            $changes['participants'] = $participants;
        }

        if (array_key_exists('currency', $mutations)) {
            $currency = (string) $mutations['currency'];
            if ($currency === '') {
                $currency = self::DEFAULT_CURRENCY;
            }

            $changes['currency'] = $currency;
        }

        if (array_key_exists('total', $mutations)) {
            $changes['total'] = (float) $mutations['total'];
        }

        if ($changes === []) {
            return $booking;
        }

        $changes['updated_at'] = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);

        $updated = $this->repository->update($bookingId, $changes);
        $this->notifyUpdated($updated);

        return $updated;
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

        $orderId    = (int) ($booking['order']['id'] ?? 0);
        $reference  = (string) ($booking['payment']['reference'] ?? '');
        $methodName = (string) ($booking['payment']['method'] ?? 'manual');

        if ($orderId > 0 && function_exists('wc_get_order')) {
            try {
                $order = wc_get_order($orderId);
                if ($order) {
                    if (method_exists($order, 'payment_complete')) {
                        $transactionId = $reference !== '' ? $reference : null;
                        $order->payment_complete($transactionId);
                    } elseif (method_exists($order, 'update_status')) {
                        $order->update_status('processing');
                    }

                    if (method_exists($order, 'add_order_note')) {
                        $order->add_order_note(
                            sprintf(
                                'Planner booking #%d marked paid via %s.',
                                $booking['id'] ?? 0,
                                $methodName
                            )
                        );
                    }

                    if (method_exists($order, 'save')) {
                        $order->save();
                    }

                    return;
                }
            } catch (Throwable $exception) {
                CoreServiceProvider::logger()->log(
                    sprintf(
                        'WooCommerce order update failed for booking #%d: %s',
                        $booking['id'] ?? 0,
                        $exception->getMessage()
                    )
                );
            }
        }

        if (function_exists('wc_create_order')) {
            try {
                $order = wc_create_order();
                if (is_object($order) && method_exists($order, 'add_meta_data')) {
                    $order->add_meta_data('_sbdp_booking_id', $booking['id'] ?? 0, true);
                }
                if (is_object($order) && method_exists($order, 'add_order_note')) {
                    $order->add_order_note('Auto-generated planner payment placeholder order.');
                }
                if (is_object($order) && method_exists($order, 'save')) {
                    $order->save();
                }
            } catch (Throwable $exception) {
                CoreServiceProvider::logger()->log(
                    sprintf(
                        'WooCommerce order stub failed for booking #%d: %s',
                        $booking['id'] ?? 0,
                        $exception->getMessage()
                    )
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
        $dateEnd   = $payload['date_end'] ?? $payload['date'];
        $timeEnd   = $payload['time_end'] ?? $payload['time'];
        $duration  = $payload['duration'] ?? null;

        return [
            'status'             => $status,
            'customer'           => $payload['customer'],
            'date'               => $payload['date'],
            'time'               => $payload['time'],
            'date_end'           => $dateEnd,
            'time_end'           => $timeEnd,
            'duration_minutes'   => $duration,
            'participants'       => $payload['participants'],
            'items'              => $payload['items'],
            'notes'              => $payload['notes'],
            'currency'           => $payload['currency'],
            'total'              => $total,
            'created_at'         => $timestamp,
            'updated_at'         => $timestamp,
            'pricing_rules'      => $payload['pricing_rules'],
            'inventory_reserved' => $reserved,
            'channel'            => $payload['channel'],
            'vendor'             => null,
            'resource'           => 'unassigned',
            'order'              => null,
            'payment'            => null,
            'payment_request'    => null,
            'captured_at'        => null,
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

        $status = isset($payload['status']) ? $this->normalizeStatus((string) $payload['status']) : 'created';

        $dateEnd = (string) ($payload['date_end'] ?? $date);
        if ($dateEnd === '') {
            $dateEnd = $date;
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd)) {
            throw new InvalidArgumentException('Booking end date must be in YYYY-MM-DD format.');
        }

        $timeEnd = trim((string) ($payload['time_end'] ?? ''));
        if ($timeEnd === '') {
            $timeEnd = $time;
        }

        $durationMinutes = null;
        if (isset($payload['duration'])) {
            $durationMinutes = (int) $payload['duration'];
            if ($durationMinutes < 0) {
                $durationMinutes = null;
            }
        }

        $customerId = isset($customer['id']) ? (int) $customer['id'] : null;
        if ($customerId !== null && $customerId <= 0) {
            $customerId = null;
        }

        $company  = trim((string) ($customer['company'] ?? ''));
        $phone    = trim((string) ($customer['phone'] ?? ''));
        $billing  = $this->sanitizeAddress(isset($customer['billing']) ? $customer['billing'] : null);
        $shipping = $this->sanitizeAddress(isset($customer['shipping']) ? $customer['shipping'] : null);

        if ($company === '') {
            $company = $billing['company'];
        }

        return [
            'customer'      => [
                'id'       => $customerId,
                'name'     => $name,
                'email'    => $email,
                'phone'    => $phone,
                'company'  => $company,
                'billing'  => $billing,
                'shipping' => $shipping,
            ],
            'date'          => $date,
            'time'          => $time,
            'date_end'      => $dateEnd,
            'time_end'      => $timeEnd,
            'duration'      => $durationMinutes,
            'participants'  => $participants,
            'items'         => $this->normalizeItems($items),
            'pricing_rules' => $pricingRules,
            'notes'         => $notes,
            'currency'      => $currency,
            'channel'       => $channel,
            'vendor_id'     => $vendorId,
            'status'        => $status,
        ];
    }

    private function sanitizeDate(string $date): string
    {
        $date = trim($date);
        if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Date must be in YYYY-MM-DD format.');
        }

        return $date;
    }

    private function sanitizeTime(string $time): string
    {
        $time = trim($time);
        if ($time === '') {
            throw new InvalidArgumentException('Time is required.');
        }

        return $time;
    }

    /**
     * @param array<string, mixed>|null $address
     *
     * @return array<string, string>
     */
    private function sanitizeAddress($address): array
    {
        $fields = [
            'company',
            'address_1',
            'address_2',
            'postcode',
            'city',
            'state',
            'country',
        ];

        $sanitized = [];
        foreach ($fields as $field) {
            $value = '';
            if (is_array($address) && isset($address[$field])) {
                $value = trim((string) $address[$field]);
            }

            $sanitized[$field] = $value;
        }

        $sanitized['formatted'] = '';

        return $sanitized;
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '') {
            throw new InvalidArgumentException('Status cannot be empty.');
        }

        return $status;
    }

    private function notifyReschedule(array $booking): void
    {
        CoreServiceProvider::logger()->log(
            sprintf(
                'Booking #%d rescheduled to %s %s',
                $booking['id'] ?? 0,
                $booking['date'] ?? '',
                $booking['time'] ?? ''
            )
        );

        if (function_exists('do_action')) {
            do_action('sbdp/booking/rescheduled', $booking);
        }
    }

    private function notifyUpdated(array $booking): void
    {
        CoreServiceProvider::logger()->log(
            sprintf('Booking #%d details updated', $booking['id'] ?? 0)
        );

        if (function_exists('do_action')) {
            do_action('sbdp/booking/updated', $booking);
        }
    }

    private function notifyInvoiceIssued(array $booking, $orderId, bool $emailSent): void
    {
        $message = sprintf(
            $emailSent
                ? 'Invoice dispatched for booking #%d'
                : 'Invoice prepared for booking #%d',
            $booking['id'] ?? 0
        );

        if (is_numeric($orderId) && (int) $orderId > 0) {
            $message .= sprintf(' (order #%d)', (int) $orderId);
        }

        CoreServiceProvider::logger()->log($message);

        if (function_exists('do_action')) {
            do_action(
                'sbdp/booking/invoice/issued',
                $booking,
                is_numeric($orderId) ? (int) $orderId : null,
                $emailSent
            );
        }
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $meta
     */
    private function applyCapturedState(
        array $booking,
        array $meta,
        bool $refreshTimestamp = false,
        bool $updateStatus = true
    ): array
    {
        if (! isset($booking['id'])) {
            return $booking;
        }

        $timestamp = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
        $updatePayload = $meta;
        $updatePayload['updated_at'] = $timestamp;

        if (
            $updateStatus
            && (
                $refreshTimestamp
                || ! isset($booking['captured_at'])
                || $booking['captured_at'] === null
                || $booking['captured_at'] === ''
            )
        ) {
            $updatePayload['captured_at'] = $timestamp;
        }

        if ($updateStatus) {
            $currentStatus = strtolower((string) ($booking['status'] ?? ''));
            if (! in_array($currentStatus, ['paid', 'completed', 'cancelled'], true)) {
                $updatePayload['status'] = $this->normalizeStatus('captured');
            }
        }

        return $this->repository->update((int) $booking['id'], $updatePayload);
    }

    private function notifyCaptured(array $booking): void
    {
        CoreServiceProvider::logger()->log(
            sprintf('Booking #%d captured - payment request dispatched', $booking['id'] ?? 0)
        );

        if (function_exists('do_action')) {
            do_action('sbdp/booking/captured', $booking);
        }
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
