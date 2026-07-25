<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Provider;

interface VisionProvider
{
    /** @param array<string,mixed> $challenge @return array<string,mixed> */
    public function analyze(array $challenge, string $imagePath): array;
}
