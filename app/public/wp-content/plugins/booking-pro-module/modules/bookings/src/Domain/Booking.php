<?php

declare(strict_types=1);

namespace SBDP\Bookings\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Immutable domain representation of a booking, capturing its schedule,
 * financial details, customer information, and associated items.
 */
final class Booking
{
    /**
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $plannerPayload
     * @param list<BookingItem>    $items
     */
    private function __construct(
        private ?int $id,
        private string $status,
        private array $customer,
        private DateTimeImmutable $scheduleStart,
        private DateTimeImmutable $scheduleEnd,
        private ?int $vendorId,
        private array $items,
        private string $totalAmount,
        private string $currency,
        private array $meta,
        private array $plannerPayload,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $capturedAt
    ) {
        if ($this->status === '') {
            throw new InvalidArgumentException('Booking status may not be empty.');
        }

        if ($this->scheduleEnd <= $this->scheduleStart) {
            throw new InvalidArgumentException('Schedule end must be after schedule start.');
        }

        if ($this->vendorId !== null && $this->vendorId < 1) {
            throw new InvalidArgumentException('Vendor identifier must be greater than zero when provided.');
        }

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $this->totalAmount)) {
            throw new InvalidArgumentException('Total amount must be a numeric string with at most two decimals.');
        }

        if (! preg_match('/^[A-Z]{3}$/', $this->currency)) {
            throw new InvalidArgumentException('Currency must be a 3-letter ISO code.');
        }

        foreach ($this->items as $item) {
            if (! $item instanceof BookingItem) {
                throw new InvalidArgumentException('Booking items must be instances of BookingItem.');
            }
        }
    }

    /**
     * @param array<string, mixed> $customer
     * @param list<BookingItem>    $items
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $plannerPayload
     */
    public static function create(
        string $status,
        array $customer,
        DateTimeImmutable $scheduleStart,
        DateTimeImmutable $scheduleEnd,
        ?int $vendorId,
        array $items,
        string $totalAmount,
        string $currency,
        array $meta,
        array $plannerPayload,
        ?DateTimeImmutable $capturedAt = null,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            null,
            $status,
            $customer,
            $scheduleStart,
            $scheduleEnd,
            $vendorId,
            array_values($items),
            $totalAmount,
            strtoupper($currency),
            $meta,
            $plannerPayload,
            $createdAt ?? $now,
            $updatedAt ?? $now,
            $capturedAt
        );
    }

    public function withId(int $id): self
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Booking identifier must be greater than zero.');
        }

        return new self(
            $id,
            $this->status,
            $this->customer,
            $this->scheduleStart,
            $this->scheduleEnd,
            $this->vendorId,
            $this->items,
            $this->totalAmount,
            $this->currency,
            $this->meta,
            $this->plannerPayload,
            $this->createdAt,
            $this->updatedAt,
            $this->capturedAt
        );
    }

    public function withStatus(string $status, ?DateTimeImmutable $updatedAt = null): self
    {
        return new self(
            $this->id,
            $status,
            $this->customer,
            $this->scheduleStart,
            $this->scheduleEnd,
            $this->vendorId,
            $this->items,
            $this->totalAmount,
            $this->currency,
            $this->meta,
            $this->plannerPayload,
            $this->createdAt,
            $updatedAt ?? new DateTimeImmutable(),
            $this->capturedAt
        );
    }

    /**
     * @param list<BookingItem> $items
     */
    public function withItems(array $items, ?DateTimeImmutable $updatedAt = null): self
    {
        return new self(
            $this->id,
            $this->status,
            $this->customer,
            $this->scheduleStart,
            $this->scheduleEnd,
            $this->vendorId,
            $items,
            $this->totalAmount,
            $this->currency,
            $this->meta,
            $this->plannerPayload,
            $this->createdAt,
            $updatedAt ?? new DateTimeImmutable(),
            $this->capturedAt
        );
    }

    public function withTotals(string $totalAmount, string $currency, ?DateTimeImmutable $updatedAt = null): self
    {
        return new self(
            $this->id,
            $this->status,
            $this->customer,
            $this->scheduleStart,
            $this->scheduleEnd,
            $this->vendorId,
            $this->items,
            $totalAmount,
            strtoupper($currency),
            $this->meta,
            $this->plannerPayload,
            $this->createdAt,
            $updatedAt ?? new DateTimeImmutable(),
            $this->capturedAt
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta, ?DateTimeImmutable $updatedAt = null): self
    {
        return new self(
            $this->id,
            $this->status,
            $this->customer,
            $this->scheduleStart,
            $this->scheduleEnd,
            $this->vendorId,
            $this->items,
            $this->totalAmount,
            $this->currency,
            $meta,
            $this->plannerPayload,
            $this->createdAt,
            $updatedAt ?? new DateTimeImmutable(),
            $this->capturedAt
        );
    }

    /**
     * @param array<string, mixed> $plannerPayload
     */
    public function withPlannerPayload(array $plannerPayload, ?DateTimeImmutable $updatedAt = null): self
    {
        return new self(
            $this->id,
            $this->status,
            $this->customer,
            $this->scheduleStart,
            $this->scheduleEnd,
            $this->vendorId,
            $this->items,
            $this->totalAmount,
            $this->currency,
            $this->meta,
            $plannerPayload,
            $this->createdAt,
            $updatedAt ?? new DateTimeImmutable(),
            $this->capturedAt
        );
    }

    public function withSchedule(DateTimeImmutable $start, DateTimeImmutable $end, ?DateTimeImmutable $updatedAt = null): self
    {
        return new self(
            $this->id,
            $this->status,
            $this->customer,
            $start,
            $end,
            $this->vendorId,
            $this->items,
            $this->totalAmount,
            $this->currency,
            $this->meta,
            $this->plannerPayload,
            $this->createdAt,
            $updatedAt ?? new DateTimeImmutable(),
            $this->capturedAt
        );
    }

    public function withCapturedAt(?DateTimeImmutable $capturedAt): self
    {
        return new self(
            $this->id,
            $this->status,
            $this->customer,
            $this->scheduleStart,
            $this->scheduleEnd,
            $this->vendorId,
            $this->items,
            $this->totalAmount,
            $this->currency,
            $this->meta,
            $this->plannerPayload,
            $this->createdAt,
            $this->updatedAt,
            $capturedAt
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomer(): array
    {
        return $this->customer;
    }

    public function getScheduleStart(): DateTimeImmutable
    {
        return $this->scheduleStart;
    }

    public function getScheduleEnd(): DateTimeImmutable
    {
        return $this->scheduleEnd;
    }

    public function getVendorId(): ?int
    {
        return $this->vendorId;
    }

    /**
     * @return list<BookingItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlannerPayload(): array
    {
        return $this->plannerPayload;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCapturedAt(): ?DateTimeImmutable
    {
        return $this->capturedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'status'    => $this->status,
            'customer'  => $this->customer,
            'schedule'  => [
                'start' => $this->scheduleStart->format(DateTimeInterface::ATOM),
                'end'   => $this->scheduleEnd->format(DateTimeInterface::ATOM),
            ],
            'vendor_id' => $this->vendorId,
            'items'     => array_map(
                static fn (BookingItem $item): array => $item->toArray(),
                $this->items
            ),
            'total' => [
                'amount'   => $this->totalAmount,
                'currency' => $this->currency,
            ],
            'meta'      => $this->meta,
            'planner'   => $this->plannerPayload,
            'created_at' => $this->createdAt->format(DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(DateTimeInterface::ATOM),
            'captured_at' => $this->capturedAt?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $required = ['status', 'customer', 'schedule', 'items', 'total', 'meta', 'planner', 'created_at', 'updated_at'];

        foreach ($required as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new InvalidArgumentException(sprintf('Booking payload missing required key "%s".', $key));
            }
        }

        if (! is_array($payload['customer'])) {
            throw new InvalidArgumentException('Booking customer payload must be an array.');
        }

        if (! is_array($payload['schedule'])) {
            throw new InvalidArgumentException('Booking schedule payload must be an array.');
        }

        if (! isset($payload['schedule']['start'], $payload['schedule']['end'])) {
            throw new InvalidArgumentException('Booking schedule payload missing start or end values.');
        }

        if (! is_array($payload['items'])) {
            throw new InvalidArgumentException('Booking items payload must be an array.');
        }

        if (! is_array($payload['total']) || ! isset($payload['total']['amount'], $payload['total']['currency'])) {
            throw new InvalidArgumentException('Booking total payload is invalid.');
        }

        $items = array_map(
            static fn (array $item): BookingItem => BookingItem::fromArray($item),
            array_values($payload['items'])
        );

        $capturedAt = null;
        if (isset($payload['captured_at']) && $payload['captured_at'] !== null) {
            $capturedAt = new DateTimeImmutable((string) $payload['captured_at']);
        }

        return new self(
            isset($payload['id']) ? (int) $payload['id'] : null,
            (string) $payload['status'],
            $payload['customer'],
            new DateTimeImmutable((string) $payload['schedule']['start']),
            new DateTimeImmutable((string) $payload['schedule']['end']),
            isset($payload['vendor_id']) ? (int) $payload['vendor_id'] : null,
            $items,
            (string) $payload['total']['amount'],
            strtoupper((string) $payload['total']['currency']),
            is_array($payload['meta']) ? $payload['meta'] : [],
            is_array($payload['planner']) ? $payload['planner'] : [],
            new DateTimeImmutable((string) $payload['created_at']),
            new DateTimeImmutable((string) $payload['updated_at']),
            $capturedAt
        );
    }
}
