<?php

declare(strict_types=1);

namespace BSP\BookingBoard\Service;

use BSP\Bookings\Service\BookingManager;
use BSP\Core\CoreServiceProvider;
use DateTimeImmutable;
use InvalidArgumentException;

final class BoardService
{
    private BookingManager $manager;

    private AccessControl $access;

    private NotificationBridge $notifications;

    private AiInsightsService $insights;

    private CustomerDirectory $customers;

    public function __construct(
        ?BookingManager $manager = null,
        ?AccessControl $access = null,
        ?NotificationBridge $notifications = null,
        ?AiInsightsService $insights = null,
        ?CustomerDirectory $customers = null
    ) {
        $this->manager       = $manager ?? BookingManager::createDefault();
        $this->access        = $access ?? new AccessControl();
        $this->notifications = $notifications ?? new NotificationBridge();
        $this->insights      = $insights ?? new AiInsightsService();
        $this->customers     = $customers ?? new CustomerDirectory();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        $bookings = $this->access->filter($this->manager->getBookings());
        $bookings = $this->applyFilters($bookings, $filters);
        $items    = array_map([$this, 'transformBooking'], $bookings);

        return [
            'items' => $items,
            'meta'  => [
                'total'            => count($items),
                'filters_applied'  => $filters,
            ],
            'stats' => $this->computeStats($bookings),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function reschedule(array $payload): array
    {
        $this->access->enforceManage();

        $bookingId = (int) ($payload['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new InvalidArgumentException('Booking identifier is required.');
        }

        [$dateStart, $timeStart] = $this->extractDateTime($payload, 'date_start', 'time_start', 'start');
        [$dateEnd, $timeEnd]     = $this->extractDateTime($payload, 'date_end', 'time_end', 'end');

        $booking = $this->manager->rescheduleBooking($bookingId, $dateStart, $timeStart, $dateEnd, $timeEnd);
        $this->notifications->bookingRescheduled($booking);

        return $this->transformBooking($booking);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateDetails(array $payload): array
    {
        $this->access->enforceManage();

        $bookingId = (int) ($payload['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new InvalidArgumentException('Booking identifier is required.');
        }

        $mutations = array_intersect_key(
            $payload,
            array_flip([
                'notes',
                'status',
                'participants',
                'currency',
                'total',
                'date',
                'time',
                'date_end',
                'time_end',
            ])
        );

        if ($mutations === []) {
            throw new InvalidArgumentException('No changes supplied.');
        }

        $booking = $this->manager->updateBookingDetails($bookingId, $mutations);
        $this->notifications->bookingUpdated($booking);

        return $this->transformBooking($booking);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createManual(array $payload): array
    {
        $this->access->enforceManage();

        [$dateStart, $timeStart] = $this->extractDateTime($payload, 'date_start', 'time_start', 'start');
        [$dateEnd, $timeEnd]     = $this->extractDateTime($payload, 'date_end', 'time_end', 'end');

        $productId = (int) ($payload['product'] ?? 0);
        if ($productId <= 0) {
            throw new InvalidArgumentException('A bookable product is required.');
        }

        $participants = (int) ($payload['persons'] ?? 1);
        if ($participants <= 0) {
            $participants = 1;
        }

        $price = isset($payload['price']) ? (float) $payload['price'] : 0.0;
        if ($price < 0) {
            $price = 0.0;
        }

        $customer = $this->resolveCustomerProfile($payload);

        $bookingPayload = [
            'customer'     => $customer,
            'date'         => $dateStart,
            'time'         => $timeStart,
            'date_end'     => $dateEnd,
            'time_end'     => $timeEnd,
            'participants' => $participants,
            'items'        => [
                [
                    'product_id' => $productId,
                    'quantity'   => 1,
                    'unit_price' => $price,
                    'label'      => (string) ($payload['product_label'] ?? sprintf(
                        __('Product #%d', 'sbdp'),
                        $productId
                    )),
                ],
            ],
            'pricing_rules' => [],
            'notes'         => isset($payload['notes']) ? (string) $payload['notes'] : null,
            'currency'      => (string) ($payload['currency'] ?? 'EUR'),
            'channel'       => 'manual',
            'vendor_id'     => isset($payload['vendor_id']) ? (int) $payload['vendor_id'] : null,
            'duration'      => isset($payload['duration']) ? (int) $payload['duration'] : null,
        ];

        if (isset($payload['status'])) {
            $bookingPayload['status'] = $payload['status'];
        }

        $booking = $this->manager->createBooking($bookingPayload);
        $this->notifications->bookingCreated($booking);

        if (! empty($payload['send_invoice'])) {
            $booking = $this->manager->dispatchInvoice((int) ($booking['id'] ?? 0), ! empty($payload['force_invoice']));
        }

        return $this->transformBooking($booking);
    }

    /**
     * @return array<string, mixed>
     */
    public function issueInvoice(array $payload): array
    {
        $this->access->enforceManage();

        $bookingId = (int) ($payload['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new InvalidArgumentException('Booking identifier is required.');
        }

        $force = ! empty($payload['force']);
        $booking = $this->manager->dispatchInvoice($bookingId, $force);

        return [
            'booking' => $this->transformBooking($booking),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function invoicePdf(array $payload): array
    {
        $this->access->enforceManage();

        $bookingId = (int) ($payload['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new InvalidArgumentException('Booking identifier is required.');
        }

        $this->ensurePdfInvoicesPlugin();

        if (! function_exists('wc_get_order')) {
            throw new InvalidArgumentException('WooCommerce is required to generate invoices.');
        }

        $booking = $this->manager->prepareInvoiceDocument($bookingId);
        $orderId = isset($booking['order']['id']) ? (int) $booking['order']['id'] : 0;
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Unable to locate the WooCommerce order for this booking.');
        }

        $order = wc_get_order($orderId);
        if (! is_object($order)) {
            throw new InvalidArgumentException('WooCommerce order not found.');
        }

        $document = $this->resolveInvoiceDocument($order);
        $artifacts = $this->extractInvoiceArtifacts($document);

        if ($artifacts['url'] === null && $artifacts['base64'] === null && $artifacts['path'] === null) {
            throw new InvalidArgumentException('Unable to generate the invoice PDF.');
        }

        return [
            'booking'    => $this->transformBooking($booking),
            'pdf_url'    => $artifacts['url'],
            'pdf_base64' => $artifacts['base64'],
            'pdf_path'   => $artifacts['path'],
            'file_name'  => $artifacts['file_name'],
            'order_id'   => $orderId,
            'generated'  => gmdate('c'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function searchCustomers(string $term): array
    {
        $this->access->enforceManage();

        $results = $this->customers->search($term, 15);
        $normalized = array_map(function (array $customer): array {
            $customer['billing']  = $this->normalizeAddress(isset($customer['billing']) && is_array($customer['billing']) ? $customer['billing'] : null);
            $customer['shipping'] = $this->normalizeAddress(isset($customer['shipping']) && is_array($customer['shipping']) ? $customer['shipping'] : null);
            if (! isset($customer['company']) || $customer['company'] === '') {
                $customer['company'] = $customer['billing']['company'];
            }

            return $customer;
        }, $results);

        if (function_exists('apply_filters')) {
            /** @var array<int, array<string, mixed>> $normalized */
            $normalized = (array) apply_filters(
                'sbdp/booking_board/customers/results',
                $normalized,
                $term,
                $this
            );
        }

        return [
            'items' => $normalized,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function stats(array $filters = []): array
    {
        $bookings = $this->applyFilters($this->access->filter($this->manager->getBookings()), $filters);

        return $this->computeStats($bookings);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function export(array $filters, string $format): array
    {
        $result = $this->list($filters);

        return [
            'format'       => strtolower($format),
            'generated_at' => gmdate('c'),
            'rows'         => $result['items'],
            'file_name'    => 'booking_board_export_' . gmdate('Ymd_His') . '.' . strtolower($format),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $bookings, array $filters): array
    {
        if (isset($filters['status'])) {
            $statuses = (array) $filters['status'];
            $statuses = array_map(static fn ($status) => strtolower((string) $status), $statuses);

            $bookings = array_filter(
                $bookings,
                static function (array $booking) use ($statuses): bool {
                    $status = strtolower((string) ($booking['status'] ?? ''));

                    return $status !== '' && in_array($status, $statuses, true);
                }
            );
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $needle   = strtolower((string) $filters['search']);
            $bookings = array_filter(
                $bookings,
                static function (array $booking) use ($needle): bool {
                    $haystacks = [
                        $booking['customer']['name'] ?? '',
                        $booking['customer']['email'] ?? '',
                        $booking['notes'] ?? '',
                    ];

                    if (isset($booking['items']) && is_array($booking['items'])) {
                        foreach ($booking['items'] as $item) {
                            if (isset($item['label'])) {
                                $haystacks[] = (string) $item['label'];
                            }
                        }
                    }

                    foreach ($haystacks as $haystack) {
                        if ($haystack !== '' && strpos(strtolower((string) $haystack), $needle) !== false) {
                            return true;
                        }
                    }

                    return false;
                }
            );
        }

        if (isset($filters['date_from']) || isset($filters['date_to'])) {
            $from = isset($filters['date_from']) && $filters['date_from'] !== ''
                ? new DateTimeImmutable((string) $filters['date_from'])
                : null;
            $to   = isset($filters['date_to']) && $filters['date_to'] !== ''
                ? new DateTimeImmutable((string) $filters['date_to'])
                : null;

            $bookings = array_filter(
                $bookings,
                static function (array $booking) use ($from, $to): bool {
                    $date = isset($booking['date']) ? new DateTimeImmutable((string) $booking['date']) : null;
                    if ($date === null) {
                        return false;
                    }

                    if ($from !== null && $date < $from) {
                        return false;
                    }

                    if ($to !== null && $date > $to) {
                        return false;
                    }

                    return true;
                }
            );
        }

        return array_values($bookings);
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function transformBooking(array $booking): array
    {
        $start = $this->combineDateTime(
            (string) ($booking['date'] ?? ''),
            (string) ($booking['time'] ?? '')
        );
        $end   = $this->combineDateTime(
            (string) ($booking['date_end'] ?? ($booking['date'] ?? '')),
            (string) ($booking['time_end'] ?? ($booking['time'] ?? ''))
        );

        $customerDetails = $this->buildCustomerDetails(isset($booking['customer']) && is_array($booking['customer']) ? $booking['customer'] : []);

        return [
            'booking_id'     => $booking['id'] ?? null,
            'product'        => $this->resolveProductLabel($booking),
            'customer'       => $customerDetails['name'],
            'customer_email' => $customerDetails['email'],
            'customer_phone' => $customerDetails['phone'],
            'customer_company' => $customerDetails['company'],
            'from'           => $start,
            'to'             => $end,
            'duration'       => $this->calculateDuration($booking, $start, $end),
            'people'         => $booking['participants'] ?? 0,
            'status'         => $booking['status'] ?? '',
            'price'          => [
                'amount'   => (float) ($booking['total'] ?? 0.0),
                'currency' => $booking['currency'] ?? 'EUR',
            ],
            'channel'       => $booking['channel'] ?? null,
            'vendor'        => $booking['vendor'] ?? null,
            'notes'         => $booking['notes'] ?? '',
            'customer_details' => $customerDetails,
            'order'         => isset($booking['order']) && is_array($booking['order']) ? $booking['order'] : null,
            'payment_request'=> isset($booking['payment_request']) && is_array($booking['payment_request']) ? $booking['payment_request'] : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     */
    private function computeStats(array $bookings): array
    {
        $totals = [
            'total'     => count($bookings),
            'paid'      => 0,
            'pending'   => 0,
            'cancelled' => 0,
            'revenue_today' => 0.0,
        ];

        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        foreach ($bookings as $booking) {
            $status = strtolower((string) ($booking['status'] ?? ''));
            if (isset($totals[$status])) {
                $totals[$status]++;
            } elseif ($status === 'paid') {
                $totals['paid']++;
            } elseif ($status === 'pending') {
                $totals['pending']++;
            } elseif ($status === 'cancelled') {
                $totals['cancelled']++;
            }

            if (($booking['date'] ?? '') === $today) {
                $totals['revenue_today'] += (float) ($booking['total'] ?? 0.0);
            }
        }

        return array_merge(
            $totals,
            [
                'ai' => $this->insights->summarize($bookings),
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0: string, 1: string}
     */
    private function extractDateTime(array $payload, string $dateKey, string $timeKey, string $fallbackKey): array
    {
        if (isset($payload[$dateKey]) || isset($payload[$timeKey])) {
            $date = isset($payload[$dateKey]) ? (string) $payload[$dateKey] : '';
            $time = isset($payload[$timeKey]) ? (string) $payload[$timeKey] : '';
            if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new InvalidArgumentException(sprintf('Field %s must be a date string (YYYY-MM-DD).', $dateKey));
            }

            if ($time === '') {
                $time = '09:00';
            }

            return [$date, $time];
        }

        if (isset($payload[$fallbackKey])) {
            $raw = (string) $payload[$fallbackKey];
            $dateTime = new DateTimeImmutable($raw);

            return [
                $dateTime->format('Y-m-d'),
                $dateTime->format('H:i'),
            ];
        }

        throw new InvalidArgumentException(sprintf('Unable to determine %s datetime.', $fallbackKey));
    }

    private function combineDateTime(string $date, string $time): string
    {
        if ($date === '') {
            return '';
        }

        $value = $date . ' ' . ($time !== '' ? $time : '00:00');

        try {
            return (new DateTimeImmutable($value))->format(DateTimeImmutable::ATOM);
        } catch (\Exception $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $customer
     *
     * @return array<string, mixed>
     */
    private function buildCustomerDetails(array $customer): array
    {
        $billing  = $this->normalizeAddress(isset($customer['billing']) && is_array($customer['billing']) ? $customer['billing'] : null);
        $shipping = $this->normalizeAddress(isset($customer['shipping']) && is_array($customer['shipping']) ? $customer['shipping'] : null);

        $company = (string) ($customer['company'] ?? '');
        if ($company === '') {
            $company = $billing['company'];
        }

        return [
            'id'       => isset($customer['id']) ? (int) $customer['id'] : null,
            'name'     => (string) ($customer['name'] ?? ''),
            'email'    => (string) ($customer['email'] ?? ''),
            'phone'    => (string) ($customer['phone'] ?? ''),
            'company'  => $company,
            'billing'  => $billing,
            'shipping' => $shipping,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function resolveCustomerProfile(array $payload): array
    {
        $profile = [
            'id'       => isset($payload['customer_id']) ? (int) $payload['customer_id'] : null,
            'name'     => trim((string) ($payload['customer_name'] ?? '')),
            'email'    => trim((string) ($payload['customer_email'] ?? '')),
            'phone'    => trim((string) ($payload['customer_phone'] ?? '')),
            'company'  => trim((string) ($payload['customer_company'] ?? '')),
            'billing'  => $this->normalizeAddress(isset($payload['customer_billing']) && is_array($payload['customer_billing']) ? $payload['customer_billing'] : null),
            'shipping' => $this->normalizeAddress(isset($payload['customer_shipping']) && is_array($payload['customer_shipping']) ? $payload['customer_shipping'] : null),
        ];

        $resolved = null;

        if ($profile['id'] !== null && $profile['id'] > 0) {
            $resolved = $this->customers->findById($profile['id']);
        } elseif ($profile['email'] !== '') {
            $resolved = $this->customers->findByEmail($profile['email']);
        }

        $profile = $this->mergeCustomerProfile($profile, is_array($resolved) ? $resolved : null);

        if ($profile['name'] === '') {
            $profile['name'] = 'Manual booking';
        }

        if ($profile['email'] === '') {
            $profile['email'] = 'manual@example.com';
        }

        return $profile;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed>|null $profile
     *
     * @return array<string, mixed>
     */
    private function mergeCustomerProfile(array $base, ?array $profile): array
    {
        if (! is_array($profile)) {
            return $base;
        }

        if (isset($profile['id']) && (int) $profile['id'] > 0) {
            $base['id'] = (int) $profile['id'];
        }

        foreach (['name', 'email', 'phone', 'company'] as $field) {
            if (($base[$field] ?? '') === '' && isset($profile[$field])) {
                $base[$field] = trim((string) $profile[$field]);
            }
        }

        $base['billing']  = $this->mergeAddress($base['billing'], isset($profile['billing']) && is_array($profile['billing']) ? $profile['billing'] : []);
        $base['shipping'] = $this->mergeAddress($base['shipping'], isset($profile['shipping']) && is_array($profile['shipping']) ? $profile['shipping'] : []);

        return $base;
    }

    /**
     * @param array<string, mixed> $subject
     * @param array<string, mixed> $candidate
     *
     * @return array<string, mixed>
     */
    private function mergeAddress(array $subject, array $candidate): array
    {
        $candidate = $this->normalizeAddress($candidate);

        foreach ($candidate as $key => $value) {
            if ($key === 'formatted') {
                continue;
            }

            if ($subject[$key] === '' && $value !== '') {
                $subject[$key] = $value;
            }
        }

        $subject['formatted'] = $this->formatAddress($subject);

        return $subject;
    }

    private function ensurePdfInvoicesPlugin(): void
    {
        if (
            ! function_exists('wpo_wcpdf_get_document')
            && ! class_exists('\WPO\WC\PDF_Invoices\Documents\Invoice')
        ) {
            throw new InvalidArgumentException('PDF Invoices & Packing Slips for WooCommerce is not active.');
        }
    }

    /**
     * @param mixed $order
     *
     * @return object
     */
    private function resolveInvoiceDocument($order)
    {
        if (function_exists('wpo_wcpdf_get_document')) {
            $document = wpo_wcpdf_get_document('invoice', $order);
            if (is_object($document)) {
                return $document;
            }
        }

        if (class_exists('\WPO\WC\PDF_Invoices\Documents\Invoice')) {
            $document = new \WPO\WC\PDF_Invoices\Documents\Invoice();
            if (method_exists($document, 'set_order')) {
                $document->set_order($order);
            }

            return $document;
        }

        throw new InvalidArgumentException('Unable to initialize invoice document.');
    }

    /**
     * @param object $document
     *
     * @return array{url:?string,path:?string,base64:?string,file_name:?string}
     */
    private function extractInvoiceArtifacts($document): array
    {
        $url      = null;
        $path     = null;
        $fileName = null;

        if (method_exists($document, 'get_pdf_filename')) {
            $fileName = (string) $document->get_pdf_filename();
        } elseif (method_exists($document, 'get_pdf_file_name')) {
            $fileName = (string) $document->get_pdf_file_name();
        }

        if (method_exists($document, 'get_pdf_url')) {
            $candidate = (string) $document->get_pdf_url();
            if ($candidate !== '') {
                $url = $candidate;
            }
        }

        if (($url === null || $url === '') && method_exists($document, 'get_pdf')) {
            try {
                $document->get_pdf();
                if (method_exists($document, 'get_pdf_url')) {
                    $candidate = (string) $document->get_pdf_url();
                    if ($candidate !== '') {
                        $url = $candidate;
                    }
                }
            } catch (\Throwable $exception) {
                CoreServiceProvider::logger()->log(
                    sprintf('Invoice PDF render failed: %s', $exception->getMessage())
                );
            }
        }

        if (method_exists($document, 'get_pdf_file_path')) {
            $candidatePath = $document->get_pdf_file_path();
            if (is_string($candidatePath) && $candidatePath !== '') {
                $path = $candidatePath;
                if ($fileName === null) {
                    $fileName = basename($candidatePath);
                }

                if ($url === null) {
                    $url = $this->convertPathToUrl($candidatePath);
                }
            }
        }

        if ($path === null && method_exists($document, 'get_pdf_path')) {
            $candidatePath = $document->get_pdf_path();
            if (is_string($candidatePath) && $candidatePath !== '') {
                $path = $candidatePath;
                if ($fileName === null) {
                    $fileName = basename($candidatePath);
                }
                if ($url === null) {
                    $url = $this->convertPathToUrl($candidatePath);
                }
            }
        }

        $base64 = null;
        if ($path !== null && is_readable($path)) {
            $contents = file_get_contents($path);
            if ($contents !== false) {
                $base64 = base64_encode($contents);
            }
        }

        return [
            'url'       => $url,
            'path'      => $path,
            'base64'    => $base64,
            'file_name' => $fileName,
        ];
    }

    private function convertPathToUrl(string $path): ?string
    {
        if (! function_exists('wp_upload_dir')) {
            return null;
        }

        $uploads = wp_upload_dir();
        if (
            ! is_array($uploads)
            || empty($uploads['basedir'])
            || empty($uploads['baseurl'])
        ) {
            return null;
        }

        $basedir = rtrim((string) $uploads['basedir'], DIRECTORY_SEPARATOR);
        $baseurl = rtrim((string) $uploads['baseurl'], '/');

        if (strpos($path, $basedir) !== 0) {
            return null;
        }

        $relative = ltrim(substr($path, strlen($basedir)), DIRECTORY_SEPARATOR);
        if ($relative === '') {
            return null;
        }

        return $baseurl . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    /**
     * @param array<string, mixed>|null $source
     *
     * @return array<string, string>
     */
    private function normalizeAddress(?array $source): array
    {
        $fields = [
            'company'   => '',
            'address_1' => '',
            'address_2' => '',
            'postcode'  => '',
            'city'      => '',
            'state'     => '',
            'country'   => '',
        ];

        if (is_array($source)) {
            foreach ($fields as $key => $default) {
                if (isset($source[$key])) {
                    $fields[$key] = trim((string) $source[$key]);
                } else {
                    $fields[$key] = $default;
                }
            }
        }

        $fields['formatted'] = $this->formatAddress($fields);

        return $fields;
    }

    /**
     * @param array<string, string> $address
     */
    private function formatAddress(array $address): string
    {
        $parts = [];
        if (($address['company'] ?? '') !== '') {
            $parts[] = $address['company'];
        }

        if (($address['address_1'] ?? '') !== '') {
            $parts[] = $address['address_1'];
        }

        if (($address['address_2'] ?? '') !== '') {
            $parts[] = $address['address_2'];
        }

        $cityLine = trim(sprintf(
            '%s %s',
            $address['postcode'] ?? '',
            $address['city'] ?? ''
        ));

        if ($cityLine !== '') {
            $parts[] = $cityLine;
        }

        if (($address['state'] ?? '') !== '') {
            $parts[] = $address['state'];
        }

        if (($address['country'] ?? '') !== '') {
            $parts[] = $address['country'];
        }

        return implode(', ', array_map('trim', array_filter($parts, static fn (string $part): bool => $part !== '')));
    }

    private function calculateDuration(array $booking, string $start, string $end): ?int
    {
        if (isset($booking['duration_minutes']) && is_int($booking['duration_minutes'])) {
            return $booking['duration_minutes'];
        }

        try {
            $startDate = new DateTimeImmutable($start);
            $endDate   = new DateTimeImmutable($end);
            $diff      = $endDate->getTimestamp() - $startDate->getTimestamp();
            if ($diff <= 0) {
                return null;
            }

            return (int) round($diff / 60);
        } catch (\Exception $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        }

        return null;
    }

    private function resolveProductLabel(array $booking): string
    {
        if (! isset($booking['items']) || ! is_array($booking['items'])) {
            return '';
        }

        $first = reset($booking['items']);

        return isset($first['label']) ? (string) $first['label'] : '';
    }
}
