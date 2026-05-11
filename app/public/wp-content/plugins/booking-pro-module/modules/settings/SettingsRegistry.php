<?php

declare(strict_types=1);

namespace BSP\Settings;

/**
 * Collects booking setting definitions for export and runtime usage.
 */
final class SettingsRegistry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $definitions = [];

    /**
     * Register a booking setting definition.
     *
     * @param array<string, mixed> $definition
     */
    public function register(array $definition): void
    {
        $key = isset($definition['key']) ? (string) $definition['key'] : '';
        if ('' === $key) {
            return;
        }

        $normalized                 = $this->normalizeDefinition($definition);
        $this->definitions[$key] = $normalized;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->definitions);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * Retrieve a definition by key.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        if (! $this->has($key)) {
            return null;
        }

        return $this->definitions[$key];
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function normalizeDefinition(array $definition): array
    {
        $key         = (string) ($definition['key'] ?? '');
        $type        = \strtolower((string) ($definition['type'] ?? 'text'));
        $label       = (string) ($definition['label'] ?? '');
        $description = (string) ($definition['description'] ?? '');

        $normalized = array(
            'key'         => $key,
            'type'        => $type,
            'label'       => $label,
            'description' => $description,
        );

        if (\array_key_exists('default', $definition)) {
            $normalized['default'] = $this->castDefault($type, $definition['default']);
        }

        $options = $definition['options'] ?? array();
        if (\is_array($options) && array() !== $options) {
            $normalizedOptions = array();

            foreach ($options as $option) {
                if (! \is_scalar($option)) {
                    continue;
                }

                $optionString = \trim((string) $option);
                if ('' === $optionString) {
                    continue;
                }

                $normalizedOptions[] = $optionString;
            }

            if (array() !== $normalizedOptions) {
                $normalized['options'] = $normalizedOptions;
            }
        }

        foreach (array('min', 'max', 'step') as $bound) {
            if (\array_key_exists($bound, $definition) && \is_numeric($definition[$bound])) {
                $normalized[$bound] = $this->normalizeNumeric($definition[$bound]);
            }
        }

        if (\array_key_exists('unit', $definition) && '' !== (string) $definition['unit']) {
            $normalized['unit'] = (string) $definition['unit'];
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function castDefault(string $type, $value)
    {
        switch ($type) {
            case 'toggle':
                return (bool) $value;
            case 'number':
            case 'slider':
                return $this->normalizeNumeric($value);
            default:
                if (\is_scalar($value)) {
                    return $value;
                }

                return '';
        }
    }

    /**
     * @param mixed $value
     * @return float|int
     */
    private function normalizeNumeric($value)
    {
        if (\is_int($value) || \is_float($value)) {
            return $value + 0;
        }

        if (\is_string($value) && '' !== $value && \is_numeric($value)) {
            return false !== \strpos($value, '.') ? (float) $value : (int) $value;
        }

        if (\is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
