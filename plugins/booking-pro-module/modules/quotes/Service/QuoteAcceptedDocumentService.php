<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use InvalidArgumentException;

final class QuoteAcceptedDocumentService
{
    public function __construct(private QuoteRepositoryInterface $repository)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function acceptedPayload(int $quoteId): array
    {
        $event = $this->latestAcceptanceEvent($quoteId);
        if ($event === null) {
            throw new InvalidArgumentException('Er is nog geen geaccepteerde offerteversie beschikbaar.');
        }

        $payload = is_array($event['payload_json'] ?? null) ? $event['payload_json'] : array();
        if (is_array($payload['legal_acceptance'] ?? null)) {
            $payload = $payload['legal_acceptance'];
        }

        if (! is_array($payload['snapshot_json'] ?? null)) {
            throw new InvalidArgumentException('De geaccepteerde offerte mist een bevroren snapshot.');
        }

        return array_merge($payload, array(
            '_event_id' => (int) ($event['id'] ?? 0),
            '_event_type' => (string) ($event['event_type'] ?? ''),
        ));
    }

    public function filename(int $quoteId): string
    {
        $quote = $this->repository->findQuote($quoteId);
        $reference = trim((string) ($quote['quote_reference'] ?? ('quote-' . $quoteId)));
        $reference = preg_replace('/[^A-Za-z0-9_-]+/', '-', $reference) ?: ('quote-' . $quoteId);

        return 'DagjeDenBosch-geaccepteerde-offerte-' . $reference . '.pdf';
    }

    public function renderHtml(int $quoteId): string
    {
        $payload = $this->acceptedPayload($quoteId);
        $snapshot = is_array($payload['snapshot_json'] ?? null) ? $payload['snapshot_json'] : array();
        $quote = is_array($snapshot['quote'] ?? null) ? $snapshot['quote'] : array();
        $version = is_array($snapshot['version'] ?? null) ? $snapshot['version'] : array();
        $request = is_array($snapshot['request'] ?? null) ? $snapshot['request'] : array();
        $lines = is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : array();
        $totals = is_array($snapshot['totals'] ?? null) ? $snapshot['totals'] : array();

        $title = trim((string) ($version['proposal_title'] ?? ''));
        $title = $title !== '' ? $title : 'Geaccepteerde offerte Dagje Den Bosch';

        $html = '<!doctype html><html lang="nl"><head><meta charset="utf-8"><style>' . $this->styles() . '</style></head><body>';
        $html .= '<header><p class="eyebrow">Dagje Den Bosch</p><h1>' . $this->e($title) . '</h1><p>Geaccepteerde offerteversie voor referentie <strong>' . $this->e((string) ($quote['quote_reference'] ?? '')) . '</strong>.</p></header>';
        $html .= '<section class="meta"><div><span>Datum</span><strong>' . $this->e((string) ($request['preferred_date'] ?? 'In overleg')) . '</strong></div><div><span>Groep</span><strong>' . $this->e($this->peopleLabel((int) ($request['group_size'] ?? 0))) . '</strong></div><div><span>Totaal</span><strong>' . $this->e($this->moneyLabel($totals)) . '</strong></div></section>';
        $html .= '<section><h2>Akkoordgegevens</h2><table><tbody>';
        foreach (array(
            'Naam' => (string) ($payload['acceptance_name'] ?? ''),
            'E-mail' => (string) ($payload['acceptance_email'] ?? ''),
            'Bedrijf' => (string) ($payload['acceptance_company'] ?? ''),
            'Rol' => (string) ($payload['acceptance_role'] ?? ''),
            'Akkoorddatum' => (string) ($payload['accepted_at'] ?? ''),
            'Approved version' => (string) ($payload['approved_version_id'] ?? ''),
            'Voorwaardenversie' => (string) ($payload['terms_version'] ?? ''),
            'IP-adres' => (string) ($payload['ip_address'] ?? ''),
        ) as $label => $value) {
            if (trim($value) !== '') {
                $html .= '<tr><th>' . $this->e($label) . '</th><td>' . $this->e($value) . '</td></tr>';
            }
        }
        $html .= '</tbody></table></section>';

        $html .= '<section><h2>Programma</h2>';
        if ($lines === array()) {
            $html .= '<p>Programma op maat.</p>';
        } else {
            $html .= '<table><thead><tr><th>Tijd</th><th>Onderdeel</th><th>Prijs</th></tr></thead><tbody>';
            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $html .= '<tr><td>' . $this->e($this->lineTime($line)) . '</td><td>' . $this->e((string) ($line['title'] ?? 'Programmaonderdeel')) . '</td><td>' . $this->e($this->linePrice($line)) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '</section>';

        $html .= '<section><h2>Juridische snapshot</h2><table><tbody>';
        foreach (array(
            'Quote version hash' => (string) ($payload['quote_version_hash'] ?? ''),
            'Proposal snapshot hash' => (string) ($payload['proposal_snapshot_hash'] ?? ''),
            'Terms snapshot hash' => (string) ($payload['terms_snapshot_hash'] ?? ''),
            'Public token id' => (string) ($payload['public_token_id'] ?? ''),
            'Event id' => (string) ($payload['_event_id'] ?? ''),
        ) as $label => $value) {
            if (trim($value) !== '') {
                $html .= '<tr><th>' . $this->e($label) . '</th><td class="hash">' . $this->e($value) . '</td></tr>';
            }
        }
        $html .= '</tbody></table><p class="small">Deze PDF is gegenereerd uit het vastgelegde Quote OS acceptance-event en de daarin opgeslagen snapshot. WooCommerce blijft leidend voor betaling, factuur, orderstatus en btw-afhandeling.</p></section>';
        $html .= '</body></html>';

        return $html;
    }

    public function renderPdf(int $quoteId): string
    {
        if (! class_exists(Dompdf::class)) {
            throw new InvalidArgumentException('PDF-generator is niet beschikbaar.');
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->renderHtml($quoteId), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestAcceptanceEvent(int $quoteId): ?array
    {
        $matches = array_values(array_filter(
            $this->repository->listQuoteEvents($quoteId),
            static fn (array $event): bool => in_array((string) ($event['event_type'] ?? ''), array('quote_public_proposal_accepted', 'quote_accepted'), true)
        ));

        return $matches !== array() ? end($matches) : null;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function lineTime(array $line): string
    {
        $start = trim((string) ($line['proposed_start_time'] ?? ''));
        $end = trim((string) ($line['proposed_end_time'] ?? ''));
        return $start !== '' && $end !== '' ? $start . ' - ' . $end : ($start !== '' ? $start : 'In overleg');
    }

    /**
     * @param array<string, mixed> $line
     */
    private function linePrice(array $line): string
    {
        if (isset($line['line_total_snapshot']) && is_numeric($line['line_total_snapshot'])) {
            return $this->money((float) $line['line_total_snapshot'], (string) (($line['currency'] ?? '') ?: 'EUR'));
        }

        return 'Op aanvraag';
    }

    /**
     * @param array<string, mixed> $totals
     */
    private function moneyLabel(array $totals): string
    {
        return isset($totals['total']) && is_numeric($totals['total'])
            ? $this->money((float) $totals['total'], (string) (($totals['currency'] ?? '') ?: 'EUR'))
            : 'Op aanvraag';
    }

    private function peopleLabel(int $people): string
    {
        return $people > 0 ? $people . ' personen' : 'In overleg';
    }

    private function money(float $amount, string $currency): string
    {
        return strtoupper($currency ?: 'EUR') . ' ' . number_format($amount, 2, ',', '.');
    }

    private function styles(): string
    {
        return 'body{font-family:DejaVu Sans,Arial,sans-serif;color:#15110b;font-size:12px;line-height:1.45}header{border-bottom:2px solid #c7a45a;margin-bottom:22px;padding-bottom:18px}.eyebrow{text-transform:uppercase;letter-spacing:.12em;color:#8b6f2f;font-size:10px}h1{font-size:24px;margin:0 0 8px}h2{font-size:15px;margin:22px 0 8px}.meta{display:table;width:100%;margin:0 0 18px}.meta div{display:table-cell;border:1px solid #e3dccf;padding:10px}.meta span{display:block;color:#70665a;font-size:10px;text-transform:uppercase}.meta strong{font-size:13px}table{width:100%;border-collapse:collapse;margin:8px 0 12px}th,td{border:1px solid #e3dccf;padding:7px;text-align:left;vertical-align:top}th{background:#f8f4ec;width:34%}.hash{font-family:DejaVu Sans Mono,monospace;font-size:9px;word-break:break-all}.small{font-size:10px;color:#70665a}';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
