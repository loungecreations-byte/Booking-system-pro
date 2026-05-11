<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use InvalidArgumentException;

final class HandoffValidationException extends InvalidArgumentException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $restCode,
        string $message,
        private int $status = 400,
        private array $context = array()
    ) {
        parent::__construct($message);
        $this->restCode = $restCode;
    }

    private string $restCode;

    public function restCode(): string
    {
        return $this->restCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
