<?php

declare(strict_types=1);

namespace BSP\Support;

use BSP\Core\Helpers\Logger;

/**
 * Tracks activated third-party integrations (payment, CRM, analytics).
 */
final class IntegrationManager
{
    private const OPTION_KEY = 'sbdp_integrations';

    private Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Persist the provided integration map.
     *
     * @param array<string, array<int, string>> $integrations
     *
     * @return array<string, array<int, string>>
     */
    public function activate(array $integrations): array
    {
        $sanitised = [];

        foreach ($integrations as $area => $services) {
            if (! \is_array($services)) {
                continue;
            }

            $sanitised[$this->sanitizeKey($area)] = $this->sanitizeList($services);
        }

        if (\function_exists('update_option')) {
            \update_option(self::OPTION_KEY, $sanitised);
        }

        $this->logger->log('[Integration] Activated integrations for keys: ' . \implode(', ', \array_keys($sanitised)));

        return $sanitised;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getActive(): array
    {
        if (! \function_exists('get_option')) {
            return [];
        }

        $stored = \get_option(self::OPTION_KEY, []);

        return \is_array($stored) ? $stored : [];
    }

    /**
     * @return array<int, string>
     */
    private function sanitizeList(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! \is_scalar($item)) {
                continue;
            }

            $slug = $this->sanitizeKey((string) $item);
            if ('' === $slug) {
                continue;
            }

            $out[] = $slug;
        }

        return $out;
    }

    private function sanitizeKey(string $value): string
    {
        if (\function_exists('sanitize_key')) {
            return \sanitize_key($value);
        }

        $value = \strtolower($value);

        return \preg_replace('/[^a-z0-9_\-]/', '', $value) ?? '';
    }
}
