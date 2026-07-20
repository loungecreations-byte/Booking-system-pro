<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

final class QuoteAcceptanceSnapshotService
{
    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     * @param array<string, mixed> $request
     * @param array<int, array<string, mixed>> $lines
     * @return array{quote_version_hash:string,proposal_snapshot_hash:string,snapshot:array<string,mixed>}
     */
    public function build(array $quote, array $version, array $request, array $lines, string $termsVersion): array
    {
        $snapshot = array(
            'quote' => array(
                'id' => (int) ($quote['id'] ?? 0),
                'quote_reference' => (string) ($quote['quote_reference'] ?? ''),
                'current_version_id' => (int) ($quote['current_version_id'] ?? 0),
            ),
            'version' => array(
                'id' => (int) ($version['id'] ?? 0),
                'version_number' => (int) ($version['version_number'] ?? 0),
                'proposal_title' => (string) ($version['proposal_title'] ?? ''),
                'pricing_snapshot_json' => $version['pricing_snapshot_json'] ?? null,
                'availability_snapshot_json' => $version['availability_snapshot_json'] ?? null,
                'handoff_payload_json' => $version['handoff_payload_json'] ?? null,
                'render_payload_json' => $version['render_payload_json'] ?? null,
            ),
            'request' => array(
                'request_reference' => (string) ($request['request_reference'] ?? ''),
                'requester_name' => (string) ($request['requester_name'] ?? ''),
                'requester_email' => (string) ($request['requester_email'] ?? ''),
                'requester_company' => (string) ($request['requester_company'] ?? ''),
                'group_size' => (int) ($request['group_size'] ?? 0),
                'preferred_date' => (string) ($request['preferred_date'] ?? ''),
                'preferred_start_time' => (string) ($request['preferred_start_time'] ?? ''),
                'preferred_end_time' => (string) ($request['preferred_end_time'] ?? ''),
            ),
            'lines' => array_map(array($this, 'lineSnapshot'), $lines),
            'totals' => $this->totals($lines),
            'terms_version' => $termsVersion,
        );
        $this->ksortRecursive($snapshot);

        return array(
            'quote_version_hash' => $this->hash($snapshot['version']),
            'proposal_snapshot_hash' => $this->hash($snapshot),
            'snapshot' => $snapshot,
        );
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function lineSnapshot(array $line): array
    {
        return array(
            'line_number' => (int) ($line['line_number'] ?? 0),
            'sort_order' => (int) ($line['sort_order'] ?? 0),
            'line_type' => (string) ($line['line_type'] ?? ''),
            'title' => (string) ($line['title'] ?? ''),
            'product_id' => (int) ($line['product_id'] ?? 0),
            'quantity' => (int) ($line['quantity'] ?? 0),
            'participants' => (int) ($line['participants'] ?? 0),
            'service_date' => (string) ($line['service_date'] ?? ''),
            'proposed_start_time' => (string) ($line['proposed_start_time'] ?? ''),
            'proposed_end_time' => (string) ($line['proposed_end_time'] ?? ''),
            'unit_amount_snapshot' => $this->moneyOrNull($line['unit_amount_snapshot'] ?? null),
            'line_total_snapshot' => $this->moneyOrNull($line['line_total_snapshot'] ?? null),
            'currency' => (string) (($line['currency'] ?? '') ?: 'EUR'),
            'tax_class' => (string) ($line['tax_class'] ?? ''),
            'pricing_snapshot_json' => $line['pricing_snapshot_json'] ?? null,
            'availability_snapshot_json' => $line['availability_snapshot_json'] ?? null,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array{currency:string,total:float|null}
     */
    private function totals(array $lines): array
    {
        $total = 0.0;
        $priced = 0;
        $currency = 'EUR';
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $currency = (string) (($line['currency'] ?? '') ?: $currency);
            if (isset($line['line_total_snapshot']) && is_numeric($line['line_total_snapshot'])) {
                $total += (float) $line['line_total_snapshot'];
                $priced++;
            }
        }

        return array('currency' => $currency, 'total' => $priced > 0 ? round($total, 2) : null);
    }

    private function moneyOrNull($value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    /**
     * @param mixed $value
     */
    private function hash($value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param mixed $value
     */
    private function ksortRecursive(&$value): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as &$child) {
            $this->ksortRecursive($child);
        }
        unset($child);
        if (! array_is_list($value)) {
            ksort($value);
        }
    }
}
