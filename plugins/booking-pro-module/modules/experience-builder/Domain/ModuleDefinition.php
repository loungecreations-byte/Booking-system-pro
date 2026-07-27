<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\Domain;

final class ModuleDefinition
{
    /** @param array<string,mixed> $definition @return array<string,mixed> */
    public static function normalize(array $definition): array
    {
        $completionModes = array_values(array_unique(array_filter(array_map(
            'sanitize_key',
            (array) ($definition['completion_modes'] ?? array('automatic'))
        ))));

        return array(
            'type' => sanitize_key((string) ($definition['type'] ?? '')),
            'label' => sanitize_text_field((string) ($definition['label'] ?? '')),
            'icon' => sanitize_key((string) ($definition['icon'] ?? 'block-default')),
            'category' => sanitize_key((string) ($definition['category'] ?? 'content')),
            'schema_version' => max(1, absint($definition['schema_version'] ?? 1)),
            'defaults' => is_array($definition['defaults'] ?? null) ? $definition['defaults'] : array(),
            'events' => array_values(array_unique(array_filter(array_map(
                'sanitize_key',
                (array) ($definition['events'] ?? array())
            )))),
            'completion_modes' => $completionModes ?: array('automatic'),
            'capability' => sanitize_key((string) ($definition['capability'] ?? 'edit_posts')),
        );
    }
}
