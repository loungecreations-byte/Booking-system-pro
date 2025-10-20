<?php

declare(strict_types=1);

namespace BSP\Intelligence;

use BSP\Core\Helpers\Logger;

/**
 * Stores analytics configuration and generates lightweight report payloads.
 */
final class ReportsService
{
    private const OPTION_KEY = 'sbdp_reports_config';

    private Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        if (! \function_exists('get_option')) {
            return $this->defaults();
        }

        $stored = \get_option(self::OPTION_KEY, []);
        if (! \is_array($stored)) {
            $stored = [];
        }

        return \array_merge($this->defaults(), $stored);
    }

    /**
     * Persist the reporting configuration.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function enable(array $payload): array
    {
        $config = $this->defaults();

        if (\array_key_exists('widgets', $payload)) {
            $config['widgets'] = $this->stringList($payload['widgets']);
        }

        if (\array_key_exists('export_formats', $payload)) {
            $config['export_formats'] = $this->stringList($payload['export_formats'], ['csv', 'pdf', 'xlsx']);
        }

        if (\array_key_exists('schedule', $payload)) {
            $config['schedule'] = $this->normalizeSchedule((string) $payload['schedule']);
        }

        if (\array_key_exists('recipients', $payload)) {
            $config['recipients'] = $this->sanitizeEmails($payload['recipients']);
        }

        $config['include_ai_summary'] = (bool) ($payload['include_ai_summary'] ?? false);

        if (\function_exists('update_option')) {
            \update_option(self::OPTION_KEY, $config);
        }

        $this->logger->log('[Reports] Configuration updated (widgets: ' . \implode(',', $config['widgets']) . ').');

        return $config;
    }

    /**
     * Generate a lightweight metrics payload for dashboard widgets.
     *
     * @return array<string, mixed>
     */
    public function generateSnapshot(): array
    {
        $orders   = $this->collectOrders();
        $revenue  = 0.0;
        $bookings = 0;
        $channels = [];

        foreach ($orders as $order) {
            $total   = (float) ($order['total'] ?? 0.0);
            $channel = (string) ($order['channel'] ?? 'direct');

            $revenue  += $total;
            $bookings += 1;

            $channels[$channel] = ($channels[$channel] ?? 0.0) + $total;
        }

        \arsort($channels);

        return [
            'bookings'      => $bookings,
            'revenue'       => \round($revenue, 2),
            'channels'      => $channels,
            'top_products'  => \array_slice($this->collectTopProducts(), 0, 5, true),
            'generated_at'  => \gmdate('c'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectOrders(): array
    {
        if (\function_exists('wc_get_orders')) {
            try {
                return \array_map(
                    static function ($order): array {
                        if (! $order instanceof \WC_Order) {
                            return [];
                        }

                        return [
                            'id'      => $order->get_id(),
                            'total'   => (float) $order->get_total(),
                            'channel' => (string) $order->get_meta('_sbdp_channel', true) ?: 'direct',
                        ];
                    },
                    \wc_get_orders(
                        [
                            'limit'        => 50,
                            'status'       => ['completed', 'processing'],
                            'orderby'      => 'date',
                            'order'        => 'DESC',
                            'return'       => 'objects',
                        ]
                    )
                );
            } catch (\Throwable $exception) {
                $this->logger->log('[Reports] Failed to read WooCommerce orders: ' . $exception->getMessage());
            }
        }

        return [];
    }

    /**
     * @return array<string, float>
     */
    private function collectTopProducts(): array
    {
        if (! \function_exists('wc_get_product')) {
            return [];
        }

        $counts = [];

        if (\function_exists('wc_get_orders')) {
            try {
                $orders = \wc_get_orders(
                    [
                        'limit'  => 20,
                        'status' => ['completed', 'processing'],
                        'return' => 'objects',
                    ]
                );

                foreach ($orders as $order) {
                    if (! $order instanceof \WC_Order) {
                        continue;
                    }

                    foreach ($order->get_items() as $item) {
                        $productId = $item->get_product_id();
                        if ($productId <= 0) {
                            continue;
                        }

                        $counts[$productId] = ($counts[$productId] ?? 0.0) + (float) $item->get_total();
                    }
                }
            } catch (\Throwable $exception) {
                $this->logger->log('[Reports] Failed to enumerate order items: ' . $exception->getMessage());
            }
        }

        $result = [];
        foreach ($counts as $productId => $total) {
            $product = \wc_get_product($productId);
            if (! $product instanceof \WC_Product) {
                continue;
            }

            $result[(string) $product->get_name()] = \round($total, 2);
        }

        \arsort($result);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'widgets'            => ['total_bookings', 'revenue_chart', 'occupancy_heatmap', 'top_products'],
            'export_formats'     => ['csv'],
            'schedule'           => 'weekly',
            'recipients'         => [],
            'include_ai_summary' => false,
        ];
    }

    /**
     * @param mixed $value
     *
     * @return array<int, string>
     */
    private function stringList($value, ?array $allowed = null): array
    {
        if (! \is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (! \is_scalar($entry)) {
                continue;
            }

            $slug = $this->sanitizeKey((string) $entry);
            if ('' === $slug) {
                continue;
            }

            if (null !== $allowed && ! \in_array($slug, $allowed, true)) {
                continue;
            }

            $out[$slug] = true;
        }

        return \array_keys($out);
    }

    private function normalizeSchedule(string $schedule): string
    {
        $schedule = $this->sanitizeKey($schedule);

        return \in_array($schedule, ['daily', 'weekly', 'monthly'], true) ? $schedule : 'weekly';
    }

    /**
     * @param mixed $value
     *
     * @return array<int, string>
     */
    private function sanitizeEmails($value): array
    {
        if (! \is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (! \is_scalar($entry)) {
                continue;
            }

            $email = \trim((string) $entry);
            if (! $this->isEmail($email)) {
                continue;
            }

            $out[\strtolower($email)] = true;
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

    private function isEmail(string $value): bool
    {
        if (\function_exists('is_email')) {
            return (bool) \is_email($value);
        }

        return false !== \filter_var($value, FILTER_VALIDATE_EMAIL);
    }
}
