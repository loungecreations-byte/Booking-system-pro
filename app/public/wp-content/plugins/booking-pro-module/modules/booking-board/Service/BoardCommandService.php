<?php

declare(strict_types=1);

namespace BSP\BookingBoard\Service;

/**
 * Write-focused facade for Booking Board command operations.
 */
final class BoardCommandService
{
    private BoardService $service;

    public function __construct(?BoardService $service = null)
    {
        $this->service = $service ?? new BoardService();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function reschedule(array $payload): array
    {
        return $this->service->reschedule($payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateDetails(array $payload): array
    {
        return $this->service->updateDetails($payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createManual(array $payload): array
    {
        return $this->service->createManual($payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function issueInvoice(array $payload): array
    {
        return $this->service->issueInvoice($payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function invoicePdf(array $payload): array
    {
        return $this->service->invoicePdf($payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function replacePartnerFallback(array $payload): array
    {
        return $this->service->replacePartnerFallback($payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateDietaryProfiles(array $payload): array
    {
        return $this->service->updateDietaryProfiles($payload);
    }
}
