<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Provider;

final class FakeVisionProvider implements VisionProvider
{
    public function analyze(array $challenge, string $uploadHash): array
    {
        unset($uploadHash);

        return array(
            'provider' => 'fake',
            'status' => 'review',
            'scores' => array(),
            'total_score' => null,
            'passed' => false,
            'feedback_codes' => array('STAGING_FAKE_PROVIDER'),
            'required_object' => (string) ($challenge['required_object']['type'] ?? ''),
        );
    }
}
