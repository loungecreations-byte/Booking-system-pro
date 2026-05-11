<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

interface WooCartLaunchGatewayInterface
{
    /**
     * @param array<string, mixed> $launchPayload
     * @return array<string, mixed>
     */
    public function hydrate(array $launchPayload): array;
}
