<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Provider;

use BSP\DiscoveryCamera\Support\FeatureFlags;

final class ProviderFactory
{
    public static function make(): VisionProvider
    {
        return FeatureFlags::providerMode() === 'fake'
            ? new FakeVisionProvider()
            : new OpenAiVisionProvider();
    }
}
