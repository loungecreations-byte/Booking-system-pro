<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Domain;

final class PhotoChallenge
{
    public const SCHEMA_VERSION = 1;

    private const VALIDATION_TYPES = array(
        'location',
        'building',
        'statue',
        'door',
        'window',
        'gargoyle',
        'bridge',
        'reflection',
        'composition',
        'perspective',
        'creative',
        'night',
        'food',
        'symbol',
        'historical_detail',
    );

    /** @return array<int,string> */
    public static function validationTypes(): array
    {
        return self::VALIDATION_TYPES;
    }

    /** @param mixed $value @return array<string,mixed> */
    public static function sanitize($value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $types = isset($value['validation_type']) ? (array) $value['validation_type'] : array();
        $types = array_values(array_unique(array_intersect(
            self::VALIDATION_TYPES,
            array_map('sanitize_key', $types)
        )));

        $hints = isset($value['hints']) && is_array($value['hints'])
            ? array_slice(array_map('sanitize_textarea_field', $value['hints']), 0, 3)
            : array();

        while (count($hints) < 3) {
            $hints[] = '';
        }

        $difficulty = sanitize_key((string) ($value['difficulty'] ?? 'medium'));
        if (! in_array($difficulty, array('easy', 'medium', 'hard', 'legendary'), true)) {
            $difficulty = 'medium';
        }

        $requiredObject = is_array($value['required_object'] ?? null)
            ? $value['required_object']
            : array('type' => (string) ($value['required_object'] ?? ''), 'label' => '');

        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'revision' => max(1, absint($value['revision'] ?? 1)),
            'title' => sanitize_text_field((string) ($value['title'] ?? '')),
            'subtitle' => sanitize_text_field((string) ($value['subtitle'] ?? '')),
            'voice_intro' => array(
                'attachment_id' => absint($value['voice_intro']['attachment_id'] ?? 0),
                'transcript' => sanitize_textarea_field((string) ($value['voice_intro']['transcript'] ?? '')),
            ),
            'historical_context' => wp_kses_post((string) ($value['historical_context'] ?? '')),
            'ai_prompt' => sanitize_textarea_field((string) ($value['ai_prompt'] ?? '')),
            'mission' => sanitize_textarea_field((string) ($value['mission'] ?? '')),
            'difficulty' => $difficulty,
            'required_object' => array(
                'type' => sanitize_key((string) ($requiredObject['type'] ?? '')),
                'label' => sanitize_text_field((string) ($requiredObject['label'] ?? '')),
            ),
            'validation_type' => $types,
            'hints' => $hints,
            'xp_reward' => min(500, max(0, absint($value['xp_reward'] ?? 0))),
            'badge_reward' => sanitize_key((string) ($value['badge_reward'] ?? '')),
            'hidden_collectible_id' => absint($value['hidden_collectible_id'] ?? 0),
            'reference_image_id' => absint($value['reference_image_id'] ?? 0),
            'interaction_type' => in_array(sanitize_key((string) ($value['interaction_type'] ?? 'photo')), array('photo', 'then_now', 'hidden_discovery', 'boss'), true)
                ? sanitize_key((string) ($value['interaction_type'] ?? 'photo'))
                : 'photo',
            'persona' => in_array(sanitize_key((string) ($value['persona'] ?? 'guide')), array('guide', 'bosch', 'frederik_hendrik', 'chef'), true)
                ? sanitize_key((string) ($value['persona'] ?? 'guide'))
                : 'guide',
            'historical_year' => sanitize_text_field((string) ($value['historical_year'] ?? '')),
            'boss_targets' => array_values(array_slice(array_filter(array_map(
                static fn ($target): array => array(
                    'label' => sanitize_text_field((string) (is_array($target) ? ($target['label'] ?? '') : '')),
                    'count' => min(20, max(1, absint(is_array($target) ? ($target['count'] ?? 1) : 1))),
                ),
                is_array($value['boss_targets'] ?? null) ? $value['boss_targets'] : array()
            ), static fn (array $target): bool => $target['label'] !== ''), 0, 12)),
            'next_unlock' => sanitize_key((string) ($value['next_unlock'] ?? 'next_chapter')),
            'pass_score' => min(100, max(1, absint($value['pass_score'] ?? 70))),
            'community_allowed' => ! empty($value['community_allowed']),
        );
    }

    /** @param array<string,mixed> $challenge @return array<int,string> */
    public static function validationErrors(array $challenge): array
    {
        $errors = array();

        if (trim((string) ($challenge['title'] ?? '')) === '') {
            $errors[] = 'missing_title';
        }
        if (trim((string) ($challenge['mission'] ?? '')) === '') {
            $errors[] = 'missing_mission';
        }
        if (trim((string) ($challenge['required_object']['type'] ?? '')) === '') {
            $errors[] = 'missing_required_object';
        }
        if ((array) ($challenge['validation_type'] ?? array()) === array()) {
            $errors[] = 'missing_validation_type';
        }

        return $errors;
    }
}
