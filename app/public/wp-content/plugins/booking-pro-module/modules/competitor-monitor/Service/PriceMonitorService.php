<?php

declare(strict_types=1);

namespace BSP\CompetitorMonitor\Service;

/**
 * Stores daily price snapshots and detects changes between runs.
 *
 * Snapshots are stored in wp_options as JSON. On each run the new
 * snapshot is compared to the previous one and any price/product
 * changes are returned so an email can be sent.
 */
final class PriceMonitorService
{
    private const OPTION_SNAPSHOT   = 'bsp_competitor_snapshot';
    private const OPTION_HISTORY    = 'bsp_competitor_history';
    private const OPTION_EMAIL      = 'bsp_competitor_notify_email';
    private const MAX_HISTORY_ITEMS = 30;

    private EliioApiClient $client;

    public function __construct(EliioApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * Run the monitor: fetch fresh data, diff against snapshot, notify if changed.
     */
    public function run(): void
    {
        $fresh = $this->client->fetchAllProducts();

        /** @var array<string, list<array<string, mixed>>> $previous */
        $previous = $this->getSnapshot();

        $changes = $this->detectChanges($previous, $fresh);

        // Always store the latest snapshot
        $this->saveSnapshot($fresh);
        $this->appendHistory($fresh, $changes);

        if (! empty($changes)) {
            $this->sendNotification($changes);
        }
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function getSnapshot(): array
    {
        $raw = \get_option(self::OPTION_SNAPSHOT, '');
        if (! \is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);
        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getHistory(): array
    {
        $raw = \get_option(self::OPTION_HISTORY, '');
        if (! \is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);
        return \is_array($decoded) ? $decoded : [];
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Detect price or product changes between two snapshots.
     *
     * @param array<string, list<array<string, mixed>>> $old
     * @param array<string, list<array<string, mixed>>> $new
     * @return list<array<string, mixed>>
     */
    private function detectChanges(array $old, array $new): array
    {
        if (empty($old)) {
            // First run — no diff yet
            return [];
        }

        $changes = [];

        foreach ($new as $tenantLabel => $products) {
            $oldProducts = $old[$tenantLabel] ?? [];
            $oldMap      = $this->indexById($oldProducts);

            foreach ($products as $product) {
                $id   = (string) ($product['id'] ?? '');
                $name = (string) ($product['name'] ?? '');

                if (! isset($oldMap[$id])) {
                    // New product added
                    $changes[] = [
                        'type'    => 'new_product',
                        'tenant'  => $tenantLabel,
                        'name'    => $name,
                        'id'      => $id,
                        'price'   => $product['price'] ?? null,
                    ];
                    continue;
                }

                $oldProduct = $oldMap[$id];

                // Price change
                foreach (['price', 'lv2Price', 'lv3Price', 'combiPrice'] as $field) {
                    $newVal = $product[$field] ?? null;
                    $oldVal = $oldProduct[$field] ?? null;

                    if ($newVal !== $oldVal) {
                        $changes[] = [
                            'type'      => 'price_change',
                            'tenant'    => $tenantLabel,
                            'name'      => $name,
                            'id'        => $id,
                            'field'     => $field,
                            'old_value' => $oldVal,
                            'new_value' => $newVal,
                        ];
                    }
                }
            }

            // Detect removed products
            $newMap = $this->indexById($products);
            foreach ($oldProducts as $oldProduct) {
                $id = (string) ($oldProduct['id'] ?? '');
                if (! isset($newMap[$id])) {
                    $changes[] = [
                        'type'   => 'removed_product',
                        'tenant' => $tenantLabel,
                        'name'   => (string) ($oldProduct['name'] ?? ''),
                        'id'     => $id,
                    ];
                }
            }
        }

        return $changes;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return array<string, array<string, mixed>>
     */
    private function indexById(array $products): array
    {
        $map = [];
        foreach ($products as $product) {
            $id = (string) ($product['id'] ?? '');
            if ($id !== '') {
                $map[$id] = $product;
            }
        }
        return $map;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $snapshot
     */
    private function saveSnapshot(array $snapshot): void
    {
        \update_option(self::OPTION_SNAPSHOT, \wp_json_encode($snapshot), false);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $snapshot
     * @param list<array<string, mixed>> $changes
     */
    private function appendHistory(array $snapshot, array $changes): void
    {
        $history = $this->getHistory();

        \array_unshift($history, [
            'date'    => \current_time('Y-m-d H:i:s'),
            'changes' => $changes,
            'counts'  => \array_map('count', $snapshot),
        ]);

        // Keep only the last N entries
        $history = \array_slice($history, 0, self::MAX_HISTORY_ITEMS);

        \update_option(self::OPTION_HISTORY, \wp_json_encode($history), false);
    }

    /**
     * @param list<array<string, mixed>> $changes
     */
    private function sendNotification(array $changes): void
    {
        $email = (string) \get_option(self::OPTION_EMAIL, \get_option('admin_email', ''));
        if ($email === '') {
            return;
        }

        $subject = \sprintf(
            '[DagjeDenBosch] Concurrent prijswijziging gedetecteerd (%d wijzigingen)',
            \count($changes)
        );

        $lines = [];
        foreach ($changes as $change) {
            $type   = (string) ($change['type'] ?? '');
            $tenant = (string) ($change['tenant'] ?? '');
            $name   = (string) ($change['name'] ?? '');

            if ($type === 'price_change') {
                $field = (string) ($change['field'] ?? 'price');
                $old   = $change['old_value'] !== null ? '€ ' . \number_format((float) $change['old_value'], 2, ',', '.') : '—';
                $newv  = $change['new_value'] !== null ? '€ ' . \number_format((float) $change['new_value'], 2, ',', '.') : '—';
                $lines[] = \sprintf('• PRIJSWIJZIGING [%s] %s (%s): %s → %s', $tenant, $name, $field, $old, $newv);
            } elseif ($type === 'new_product') {
                $price = $change['price'] !== null ? '€ ' . \number_format((float) $change['price'], 2, ',', '.') : '—';
                $lines[] = \sprintf('• NIEUW PRODUCT [%s] %s — prijs: %s', $tenant, $name, $price);
            } elseif ($type === 'removed_product') {
                $lines[] = \sprintf('• VERWIJDERD [%s] %s', $tenant, $name);
            }
        }

        $body  = "Concurrentie-monitor heeft wijzigingen gedetecteerd bij Eropuitje.nl:\n\n";
        $body .= \implode("\n", $lines);
        $body .= "\n\nBekijk het dashboard: " . \admin_url('admin.php?page=bsp-competitor-monitor');

        \wp_mail($email, $subject, $body);
    }
}
