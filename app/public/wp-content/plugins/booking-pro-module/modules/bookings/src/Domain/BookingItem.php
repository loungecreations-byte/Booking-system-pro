<?php

declare(strict_types=1);

namespace SBDP\Bookings\Domain;

use InvalidArgumentException;

/**
 * Value object representing a single line item within a booking.
 *
 * The object remains immutable; use {@see withQuantity()} or {@see withUnitPrice()}
 * clones when adjustments are required.
 */
final class BookingItem
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private int $productId,
        private string $label,
        private int $quantity,
        private string $unitPrice,
        private array $meta = []
    ) {
        if ($productId < 1) {
            throw new InvalidArgumentException('Product identifier must be greater than zero.');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        if ($unitPrice === '' || ! preg_match('/^-?\d+(\.\d{1,2})?$/', $unitPrice)) {
            throw new InvalidArgumentException('Unit price must be a numeric string with at most two decimals.');
        }
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    public function withQuantity(int $quantity): self
    {
        return new self(
            $this->productId,
            $this->label,
            $quantity,
            $this->unitPrice,
            $this->meta
        );
    }

    public function withUnitPrice(string $unitPrice): self
    {
        return new self(
            $this->productId,
            $this->label,
            $this->quantity,
            $unitPrice,
            $this->meta
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): self
    {
        return new self(
            $this->productId,
            $this->label,
            $this->quantity,
            $this->unitPrice,
            $meta
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'label'      => $this->label,
            'quantity'   => $this->quantity,
            'unit_price' => $this->unitPrice,
            'meta'       => $this->meta,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        if (! isset($payload['product_id'], $payload['label'], $payload['quantity'], $payload['unit_price'])) {
            throw new InvalidArgumentException('Booking item payload missing required keys.');
        }

        $meta = [];
        if (isset($payload['meta']) && is_array($payload['meta'])) {
            $meta = $payload['meta'];
        }

        return new self(
            (int) $payload['product_id'],
            (string) $payload['label'],
            (int) $payload['quantity'],
            (string) $payload['unit_price'],
            $meta
        );
    }
}

