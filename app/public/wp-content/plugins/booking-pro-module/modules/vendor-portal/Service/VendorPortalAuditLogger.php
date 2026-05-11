<?php

declare(strict_types=1);

namespace BSP\VendorPortal\Service;

use BSP\Core\CoreServiceProvider;
use BSP\Core\Helpers\Logger;

/**
 * Centralises audit logging for Vendor Portal actions.
 */
final class VendorPortalAuditLogger
{
    /**
     * @var callable
     */
    private $writer;

    public function __construct(?Logger $logger = null, ?callable $writer = null)
    {
        if ($writer !== null) {
            $this->writer = $writer;
            return;
        }

        $logger = $logger ?? CoreServiceProvider::logger();
        $this->writer = static function (string $message) use ($logger): void {
            $logger->log($message);
        };
    }

    /**
     * Log an audit event and dispatch WordPress hook for observers.
     *
     * @param array<string, mixed> $context
     */
    public function log(string $event, array $context = array()): void
    {
        $event   = trim($event);
        $context = $this->normaliseContext($context);

        $payload = array(
            'event'   => $event,
            'context' => $context,
        );

        $encoded = $this->encode($payload);
        ($this->writer)('Vendor Portal: ' . $encoded);

        if (function_exists('do_action')) {
            do_action('sbdp/vendor_portal/audit_event', $event, $context);
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function normaliseContext(array $context): array
    {
        $normalised = array();

        foreach ($context as $key => $value) {
            if (! is_string($key)) {
                $key = (string) $key;
            }

            if ($value === null || is_scalar($value)) {
                $normalised[$key] = $this->maybeMaskValue($key, (string) $value);
                continue;
            }

            if (is_array($value)) {
                $normalised[$key] = $this->normaliseContext($value);
                continue;
            }

            $normalised[$key] = $this->maybeMaskValue($key, (string) json_encode($value));
        }

        return $normalised;
    }

    private function maybeMaskValue(string $key, string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        $lowerKey = strtolower($key);
        $sensitive = strpos($lowerKey, 'token') !== false
            || strpos($lowerKey, 'secret') !== false
            || strpos($lowerKey, 'key') !== false
            || strpos($lowerKey, 'password') !== false;

        if (! $sensitive) {
            return $value;
        }

        return $this->maskSensitive($value);
    }

    private function maskSensitive(string $value): string
    {
        $length = strlen($value);
        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        if ($length <= 6) {
            $prefix = substr($value, 0, 1);
            $suffix = substr($value, -1);
            return $prefix . str_repeat('*', $length - 2) . $suffix;
        }

        $prefix = substr($value, 0, 4);
        $suffix = substr($value, -4);
        return $prefix . str_repeat('*', max($length - 8, 0)) . $suffix;
    }

    /**
     * Encode payload with WordPress helper when available.
     *
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        if (function_exists('wp_json_encode')) {
            $encoded = wp_json_encode($payload);
            if (is_string($encoded)) {
                return $encoded;
            }
        }

        return (string) json_encode($payload);
    }
}
