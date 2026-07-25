<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Content;

use BSP\DiscoveryCamera\Domain\PhotoChallenge;

final class PhotoChallengeMeta
{
    public const KEY = '_sbdp_photo_challenge_v1';

    public static function register(): void
    {
        register_post_meta(
            'sbdp_tour_step',
            self::KEY,
            array(
                'type' => 'object',
                'single' => true,
                'default' => array(),
                'show_in_rest' => array(
                    'schema' => array(
                        'type' => 'object',
                        'additionalProperties' => true,
                    ),
                ),
                'sanitize_callback' => array(PhotoChallenge::class, 'sanitize'),
                'auth_callback' => static fn (): bool => current_user_can('edit_posts'),
            )
        );
    }

    /** @return array<string,mixed> */
    public static function forStep(int $stepId): array
    {
        $value = get_post_meta($stepId, self::KEY, true);

        return PhotoChallenge::sanitize(is_array($value) ? $value : array());
    }
}
