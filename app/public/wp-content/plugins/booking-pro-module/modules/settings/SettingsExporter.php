<?php

declare(strict_types=1);

namespace BSP\Settings;

use BSP\Core\Helpers\Logger;

/**
 * Validates and exports booking settings definitions to disk.
 */
final class SettingsExporter
{
    private Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
    }

    /**
     * @param array<int, string> $requiredKeys
     */
    public function export(
        SettingsRegistry $registry,
        array $requiredKeys,
        string $relativePath,
        bool $logResults = false
    ): void {
        $missing = $this->collectMissingKeys($registry, $requiredKeys);
        if (array() !== $missing) {
            if ($logResults) {
                $this->logger->log(
                    'Booking settings validation failed. Missing keys: ' . \implode(', ', $missing)
                );
            }

            return;
        }

        $definitions = $registry->all();
        \ksort($definitions);

        $payload = array(
            'generated_at' => \gmdate('c'),
            'settings'     => \array_values($definitions),
        );

        $json = $this->encodePayload($payload);
        if ('' === $json) {
            if ($logResults) {
                $this->logger->log('Booking settings export aborted: failed to encode payload.');
            }

            return;
        }

        $path     = $this->resolvePath($relativePath);
        $status   = $this->writeConfig($path, $json);
        if ($logResults && 'unchanged' !== $status) {
            $message  = '';
            switch ($status) {
                case 'updated':
                    $message = 'Booking settings configuration exported to ' . $relativePath;
                    break;
                case 'failed':
                default:
                    $message = 'Booking settings export failed while writing ' . $relativePath;
                    break;
            }

            $this->logger->log($message);
        }
    }

    /**
     * @param array<int, string> $requiredKeys
     *
     * @return array<int, string>
     */
    private function collectMissingKeys(SettingsRegistry $registry, array $requiredKeys): array
    {
        $missing = array();

        foreach ($requiredKeys as $key) {
            $normalized = (string) $key;
            if ('' === $normalized) {
                continue;
            }

            if (! $registry->has($normalized)) {
                $missing[] = $normalized;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): string
    {
        $json = null;

        if (\function_exists('wp_json_encode')) {
            $json = \wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if (! \is_string($json)) {
            $json = \json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if (! \is_string($json)) {
            return '';
        }

        return $json . \PHP_EOL;
    }

    private function resolvePath(string $relativePath): string
    {
        $cleaned   = \ltrim($relativePath, '\\/');
        $normalized = \str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $cleaned);
        $base      = \defined('SBDP_DIR')
            ? SBDP_DIR
            : __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;

        return $base . $normalized;
    }

    private function ensureDirectory(string $directory): void
    {
        if ('' === $directory || \is_dir($directory)) {
            return;
        }

        if (\function_exists('wp_mkdir_p')) {
            \wp_mkdir_p($directory);

            return;
        }

        if (! @\mkdir($directory, 0775, true) && ! \is_dir($directory)) {
            // Directory creation failed; rely on file_put_contents error handling.
        }
    }

    private function writeConfig(string $path, string $contents): string
    {
        $directory = \dirname($path);
        $this->ensureDirectory($directory);

        $existing = \is_readable($path) ? (string) \file_get_contents($path) : '';
        if ($existing === $contents) {
            return 'unchanged';
        }

        if (! $this->hasSettingsChanged($existing, $contents)) {
            return 'unchanged';
        }

        $result = @\file_put_contents($path, $contents);
        if (false === $result) {
            return 'failed';
        }

        return 'updated';
    }

    private function hasSettingsChanged(string $existing, string $incoming): bool
    {
        if ('' === $existing) {
            return true;
        }

        $existingPayload = \json_decode($existing, true);
        $incomingPayload = \json_decode($incoming, true);
        if (! \is_array($existingPayload) || ! \is_array($incomingPayload)) {
            return true;
        }

        $existingSettings = $existingPayload['settings'] ?? null;
        $incomingSettings = $incomingPayload['settings'] ?? null;

        return $existingSettings !== $incomingSettings;
    }
}
