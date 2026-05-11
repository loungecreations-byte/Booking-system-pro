<?php

declare(strict_types=1);

namespace BSP\BookingBoard\Service;

/**
 * Read-only facade for Booking Board data operations.
 */
final class BoardQueryService
{
    private BoardService $service;

    public function __construct(?BoardService $service = null)
    {
        $this->service = $service ?? new BoardService();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        return $this->service->list($filters);
    }

    public function get(int $bookingId): array
    {
        return $this->service->get($bookingId);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function stats(array $filters = []): array
    {
        return $this->service->stats($filters);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function export(array $filters, string $format): array
    {
        return $this->service->export($filters, $format);
    }

    /**
     * @return array<string, mixed>
     */
    public function searchCustomers(string $term): array
    {
        return $this->service->searchCustomers($term);
    }
}
