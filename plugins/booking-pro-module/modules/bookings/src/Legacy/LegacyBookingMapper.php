<?php

declare(strict_types=1);

namespace SBDP\Bookings\Legacy;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use SBDP\Bookings\Domain\Booking;
use SBDP\Bookings\Domain\BookingItem;
use Throwable;

/**
 * Converts between legacy booking board array payloads and the new Booking domain objects.
 */
final class LegacyBookingMapper
{
    /**
     * @param array<string, mixed> $record
     */
    public static function toBooking(array $record): Booking
    {
        $status = (string) ($record['status'] ?? 'created');

        $customer = [];
        if (isset($record['customer']) && is_array($record['customer'])) {
            $customer = $record['customer'];
        }

        $start = self::createDateTime(
            (string) ($record['date'] ?? '1970-01-01'),
            (string) ($record['time'] ?? '00:00')
        );

        $end = self::createDateTime(
            (string) ($record['date_end'] ?? $record['date'] ?? '1970-01-01'),
            (string) ($record['time_end'] ?? $record['time'] ?? '00:00')
        );

        if ($end <= $start) {
            $end = $start->modify('+1 hour');
        }

        $vendorId = null;
        if (isset($record['vendor']['id'])) {
            $vendorId = (int) $record['vendor']['id'];
        } elseif (isset($record['vendor_id'])) {
            $vendorId = (int) $record['vendor_id'];
        }
        if ($vendorId !== null && $vendorId <= 0) {
            $vendorId = null;
        }

        $items = [];
        if (isset($record['items']) && is_array($record['items'])) {
            foreach ($record['items'] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $productId = (int) ($item['product_id'] ?? 0);
                $quantity  = (int) ($item['quantity'] ?? 0);
                $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0.0;

                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }

                $items[] = new BookingItem(
                    $productId,
                    (string) ($item['label'] ?? ''),
                    $quantity,
                    self::formatAmount($unitPrice),
                    isset($item['meta']) && is_array($item['meta']) ? $item['meta'] : []
                );
            }
        }

        if ($items === []) {
            throw new InvalidArgumentException('Legacy booking record requires at least one valid item.');
        }

        $meta = [
            'notes'              => $record['notes'] ?? null,
            'participants'       => isset($record['participants']) ? (int) $record['participants'] : null,
            'duration_minutes'   => $record['duration_minutes'] ?? ($record['duration'] ?? null),
            'pricing_rules'      => isset($record['pricing_rules']) && is_array($record['pricing_rules'])
                ? $record['pricing_rules']
                : [],
            'inventory_reserved' => isset($record['inventory_reserved']) && is_array($record['inventory_reserved'])
                ? $record['inventory_reserved']
                : [],
            'resource'           => $record['resource'] ?? 'unassigned',
            'order'              => $record['order'] ?? null,
            'payment'            => $record['payment'] ?? null,
            'payment_request'    => $record['payment_request'] ?? null,
            'channel'            => $record['channel'] ?? null,
            'vendor'             => $record['vendor'] ?? null,
            'planner'            => $record['planner'] ?? null,
            'conflict'           => $record['conflict'] ?? null,
            'paid_at'            => $record['paid_at'] ?? null,
        ];

        $plannerPayload = [];
        if (isset($record['planner']) && is_array($record['planner'])) {
            $plannerPayload['planner'] = $record['planner'];
        }

        $createdAt = self::parseDateTimeOrNow($record['created_at'] ?? null);
        $updatedAt = self::parseDateTimeOrNow($record['updated_at'] ?? null);
        $capturedAt = self::parseNullableDateTime($record['captured_at'] ?? null);

        $booking = Booking::create(
            status: $status,
            customer: $customer,
            scheduleStart: $start,
            scheduleEnd: $end,
            vendorId: $vendorId,
            items: $items,
            totalAmount: self::formatAmount((float) ($record['total'] ?? 0)),
            currency: (string) ($record['currency'] ?? 'EUR'),
            meta: $meta,
            plannerPayload: $plannerPayload,
            capturedAt: $capturedAt,
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );

        if (isset($record['id'])) {
            $booking = $booking->withId((int) $record['id']);
        }

        return $booking;
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromBooking(Booking $booking): array
    {
        $meta = $booking->getMeta();
        $plannerPayload = $booking->getPlannerPayload();

        $participants = isset($meta['participants']) ? (int) $meta['participants'] : 0;
        $duration = isset($meta['duration_minutes']) ? $meta['duration_minutes'] : null;

        $order = isset($meta['order']) && is_array($meta['order']) ? $meta['order'] : null;
        $payment = isset($meta['payment']) && is_array($meta['payment']) ? $meta['payment'] : null;
        $paymentRequest = isset($meta['payment_request']) && is_array($meta['payment_request'])
            ? $meta['payment_request']
            : null;

        $record = [
            'id'               => $booking->getId(),
            'status'           => $booking->getStatus(),
            'customer'         => $booking->getCustomer(),
            'date'             => $booking->getScheduleStart()->format('Y-m-d'),
            'time'             => $booking->getScheduleStart()->format('H:i'),
            'date_end'         => $booking->getScheduleEnd()->format('Y-m-d'),
            'time_end'         => $booking->getScheduleEnd()->format('H:i'),
            'duration_minutes' => $duration,
            'participants'     => $participants,
            'items'            => array_map(
                static fn (BookingItem $item): array => [
                    'product_id' => $item->getProductId(),
                    'label'      => $item->getLabel(),
                    'quantity'   => $item->getQuantity(),
                    'unit_price' => (float) $item->getUnitPrice(),
                    'meta'       => $item->getMeta(),
                ],
                $booking->getItems()
            ),
            'notes'            => $meta['notes'] ?? null,
            'currency'         => $booking->getCurrency(),
            'total'            => (float) $booking->getTotalAmount(),
            'created_at'       => $booking->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at'       => $booking->getUpdatedAt()->format(DateTimeInterface::ATOM),
            'captured_at'      => $booking->getCapturedAt()?->format(DateTimeInterface::ATOM),
            'pricing_rules'    => isset($meta['pricing_rules']) && is_array($meta['pricing_rules'])
                ? $meta['pricing_rules']
                : [],
            'inventory_reserved' => isset($meta['inventory_reserved']) && is_array($meta['inventory_reserved'])
                ? $meta['inventory_reserved']
                : [],
            'channel'          => $meta['channel'] ?? null,
            'vendor'           => isset($meta['vendor']) && is_array($meta['vendor']) ? $meta['vendor'] : null,
            'resource'         => $meta['resource'] ?? 'unassigned',
            'planner'          => isset($plannerPayload['planner']) && is_array($plannerPayload['planner'])
                ? $plannerPayload['planner']
                : (isset($meta['planner']) && is_array($meta['planner']) ? $meta['planner'] : null),
            'order'            => $order,
            'payment'          => $payment,
            'payment_request'  => $paymentRequest,
            'conflict'         => $meta['conflict'] ?? null,
            'paid_at'          => $meta['paid_at'] ?? null,
        ];

        if (! isset($record['vendor']) && $booking->getVendorId() !== null) {
            $record['vendor'] = ['id' => $booking->getVendorId()];
        }

        return $record;
    }

    private static function createDateTime(string $date, string $time): DateTimeImmutable
    {
        $string = trim($date) . ' ' . trim($time);
        $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i', $string);
        if ($dateTime === false) {
            $dateTime = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $string);
        }

        if ($dateTime === false) {
            $dateTime = new DateTimeImmutable($date . ' ' . $time);
        }

        return $dateTime;
    }

    private static function parseDateTimeOrNow(mixed $value): DateTimeImmutable
    {
        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Throwable) {
                // Fall back to now.
            }
        }

        return new DateTimeImmutable();
    }

    private static function parseNullableDateTime(mixed $value): ?DateTimeImmutable
    {
        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
