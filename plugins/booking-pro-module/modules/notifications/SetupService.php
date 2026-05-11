<?php

declare(strict_types=1);

namespace BSP\Notifications;

use BSP\Core\Helpers\Logger;

/**
 * Handles notification templates, delivery methods, and scheduling windows.
 */
final class SetupService
{
    private const OPTION_KEY = 'sbdp_notifications_config';

    private Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Retrieve the stored notification configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        if (! \function_exists('get_option')) {
            return $this->defaults();
        }

        $stored = \get_option(self::OPTION_KEY, []);
        if (! \is_array($stored)) {
            return $this->defaults();
        }

        $merged = \array_merge($this->defaults(), $stored);

        $merged['templates'] = $this->sanitizeTemplates($merged['templates'] ?? []);
        $merged['methods']   = $this->sanitizeMethods($merged['methods'] ?? []);
        $merged['timing']    = $this->sanitizeTiming($merged['timing'] ?? []);
        $merged['variables'] = $this->sanitizeVariables($merged['variables'] ?? []);

        return $merged;
    }

    /**
     * Persist a configuration update.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> Sanitised configuration.
     */
    public function saveConfiguration(array $payload): array
    {
        $config = $this->defaults();

        if (\array_key_exists('templates', $payload)) {
            $config['templates'] = $this->sanitizeTemplates($payload['templates']);
        }

        if (\array_key_exists('methods', $payload)) {
            $config['methods'] = $this->sanitizeMethods($payload['methods']);
        }

        if (\array_key_exists('timing', $payload)) {
            $config['timing'] = $this->sanitizeTiming($payload['timing']);
        }

        if (\array_key_exists('variables', $payload)) {
            $config['variables'] = $this->sanitizeVariables($payload['variables']);
        }

        if (\function_exists('update_option')) {
            \update_option(self::OPTION_KEY, $config);
        }

        $this->logger->log('[Notifications] Configuration updated with ' . \count($config['templates']) . ' templates.');

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'templates' => [
                [
                    'key'     => 'confirmation',
                    'label'   => 'Booking confirmation',
                    'enabled' => true,
                ],
                [
                    'key'     => 'reminder',
                    'label'   => 'Booking reminder',
                    'enabled' => true,
                ],
                [
                    'key'     => 'cancellation',
                    'label'   => 'Booking cancelled',
                    'enabled' => true,
                ],
                [
                    'key'     => 'review',
                    'label'   => 'Review request',
                    'enabled' => true,
                ],
                [
                    'key'     => 'ops_vendor_portal_audit',
                    'label'   => 'Ops: Vendor portal audit events',
                    'enabled' => true,
                ],
            ],
            'methods'   => ['email'],
            'timing'    => [
                'on_booking'    => 'immediate',
                'before_start'  => '24h',
                'after_complete'=> '24h',
            ],
            'variables' => [
                'customer_name',
                'product_name',
                'date',
                'price',
                'link',
            ],
        ];
    }

    /**
     * @param mixed $templates
     *
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeTemplates($templates): array
    {
        if (! \is_array($templates)) {
            return $this->defaults()['templates'];
        }

        $sanitised = [];
        foreach ($templates as $template) {
            if (! \is_array($template)) {
                continue;
            }

            $key = isset($template['key']) ? $this->sanitizeKey((string) $template['key']) : '';
            if ('' === $key) {
                continue;
            }

            $sanitised[] = [
                'key'     => $key,
                'label'   => isset($template['label']) ? $this->sanitizeText((string) $template['label']) : $key,
                'enabled' => isset($template['enabled']) ? (bool) $template['enabled'] : true,
            ];
        }

        return array_values($sanitised);
    }

    /**
     * @param mixed $methods
     *
     * @return array<int, string>
     */
    private function sanitizeMethods($methods): array
    {
        if (! \is_array($methods)) {
            return ['email'];
        }

        $out = [];
        foreach ($methods as $method) {
            if (! \is_scalar($method)) {
                continue;
            }

            $slug = $this->sanitizeKey((string) $method);
            if ('' === $slug) {
                continue;
            }

            $out[$slug] = true;
        }

        return \array_keys($out);
    }

    /**
     * @param mixed $timing
     *
     * @return array<string, string>
     */
    private function sanitizeTiming($timing): array
    {
        if (! \is_array($timing)) {
            return $this->defaults()['timing'];
        }

        $out = [];
        foreach ($timing as $key => $value) {
            if (! \is_scalar($value)) {
                continue;
            }

            $slug = $this->sanitizeKey((string) $key);
            if ('' === $slug) {
                continue;
            }

            $out[$slug] = $this->sanitizeText((string) $value);
        }

        return $out;
    }

    /**
     * @param mixed $variables
     *
     * @return array<int, string>
     */
    private function sanitizeVariables($variables): array
    {
        if (! \is_array($variables)) {
            return $this->defaults()['variables'];
        }

        $out = [];
        foreach ($variables as $variable) {
            if (! \is_scalar($variable)) {
                continue;
            }

            $key = $this->sanitizeKey((string) $variable);
            if ('' === $key) {
                continue;
            }

            $out[$key] = true;
        }

        return \array_keys($out);
    }

    private function sanitizeKey(string $value): string
    {
        if (\function_exists('sanitize_key')) {
            return \sanitize_key($value);
        }

        $value = \strtolower($value);

        return \preg_replace('/[^a-z0-9_\-]/', '', $value) ?? '';
    }

    private function sanitizeText(string $value): string
    {
        if (\function_exists('sanitize_text_field')) {
            return \sanitize_text_field($value);
        }

        $clean = \strip_tags($value);
        $clean = \preg_replace('/[\r\n\t]+/', ' ', $clean) ?? $clean;

        return \trim($clean);
    }
}
