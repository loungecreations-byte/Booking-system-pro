<?php

declare(strict_types=1);

namespace BSP\Quotes;

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Repository\QuoteRepositoryInterface;
use BSP\Quotes\Service\PublicQuoteProposalService;
use BSP\Quotes\Service\PublicQuoteProposalTokenService;
use BSP\Quotes\Service\QuoteEventLogger;
use InvalidArgumentException;

final class CustomerWorkspaceController
{
    public static function register(): void
    {
        if (function_exists('add_action')) {
            add_action('template_redirect', array(self::class, 'maybeRender'));
        }
    }

    public static function maybeRender(): void
    {
        $token = self::requestText('ddb_customer_workspace');
        if ($token === '') {
            return;
        }

        $repository = new QuoteRepository();
        $service = self::service($repository);

        try {
            $context = $service->resolveByToken($token);
            $context['events'] = $repository->listQuoteEvents((int) ($context['quote']['id'] ?? 0));
            $html = self::renderPage($context);
        } catch (InvalidArgumentException $exception) {
            $html = self::renderUnavailable($exception->getMessage());
        }

        if (function_exists('status_header')) {
            status_header(200);
        }
        if (! headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }

        echo $html;
        exit;
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function renderPage(array $context): string
    {
        $quote = is_array($context['quote'] ?? null) ? $context['quote'] : array();
        $version = is_array($context['version'] ?? null) ? $context['version'] : array();
        $request = is_array($context['request'] ?? null) ? $context['request'] : array();
        $lines = is_array($context['lines'] ?? null) ? $context['lines'] : array();
        $events = is_array($context['events'] ?? null) ? $context['events'] : array();
        $order = self::orderSummary((int) ($quote['woo_order_id'] ?? 0));
        $title = trim((string) ($version['proposal_title'] ?? ''));
        $title = $title !== '' ? $title : 'Uw DagjeDenBosch status';

        ob_start();
        echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . self::e($title) . '</title><style>' . self::styles() . '</style></head><body><main class="customer-page">';
        echo '<header class="hero"><p>Dagje Den Bosch</p><h1>' . self::e($title) . '</h1><span>Referentie ' . self::e((string) ($quote['quote_reference'] ?? '')) . '</span></header>';
        echo '<section class="summary-grid">';
        self::metric('Status', self::quoteStatusLabel((string) ($quote['status'] ?? '')));
        self::metric('Offerte', self::sendStatusLabel((string) ($quote['send_status'] ?? '')));
        self::metric('Betaling', (string) ($order['status_label'] ?? 'Nog niet gekoppeld'));
        self::metric('Datum', self::dateLabel((string) ($request['preferred_date'] ?? '')));
        echo '</section>';
        echo '<section class="panel"><h2>Programma</h2>';
        if ($lines === array()) {
            echo '<p>Het programma wordt afgestemd. Zodra er een voorstel is, ziet u hier de onderdelen.</p>';
        } else {
            echo '<ol class="program">';
            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }
                echo '<li><time>' . self::e(self::lineTime($line)) . '</time><div><strong>' . self::e((string) ($line['title'] ?? 'Programmaonderdeel')) . '</strong>';
                echo '<p>' . self::e(self::linePublicStatus($line)) . '</p></div></li>';
            }
            echo '</ol>';
        }
        echo '</section>';
        echo '<section class="panel"><h2>Openstaande acties</h2><ul class="clean-list">';
        foreach (self::openActions($quote, $order) as $action) {
            echo '<li>' . self::e($action) . '</li>';
        }
        echo '</ul></section>';
        echo '<section class="panel"><h2>Tijdlijn</h2><ol class="customer-timeline">';
        foreach (self::customerEvents($events) as $event) {
            echo '<li><time>' . self::e((string) ($event['occurred_at'] ?? '')) . '</time><span>' . self::e((string) ($event['label'] ?? 'Update')) . '</span></li>';
        }
        echo '</ol></section>';
        echo '</main></body></html>';

        return (string) ob_get_clean();
    }

    public static function renderUnavailable(string $message): string
    {
        return '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Status niet beschikbaar</title><style>' . self::styles() . '</style></head><body><main class="customer-page"><section class="panel"><h1>Status niet beschikbaar</h1><p>' . self::e($message !== '' ? $message : 'Controleer de link of neem contact met ons op.') . '</p></section></main></body></html>';
    }

    private static function service(QuoteRepositoryInterface $repository): PublicQuoteProposalService
    {
        return new PublicQuoteProposalService(
            $repository,
            new QuoteEventLogger($repository),
            new PublicQuoteProposalTokenService()
        );
    }

    /**
     * @return array{status_label:string}
     */
    private static function orderSummary(int $orderId): array
    {
        if ($orderId <= 0 || ! function_exists('wc_get_order')) {
            return array('status_label' => 'Nog niet gekoppeld');
        }

        $order = wc_get_order($orderId);
        if (! is_object($order) || ! method_exists($order, 'get_status')) {
            return array('status_label' => 'Nog niet gekoppeld');
        }

        $status = (string) $order->get_status();
        return array('status_label' => match ($status) {
            'completed', 'processing' => 'Betaling ontvangen',
            'pending', 'on-hold' => 'Wacht op betaling',
            'cancelled', 'failed' => 'Betaling niet afgerond',
            default => 'Orderstatus: ' . $status,
        });
    }

    /**
     * @param array<string,mixed> $line
     */
    private static function linePublicStatus(array $line): string
    {
        $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
        $supplierStatus = (string) ($snapshot['supplierStatus'] ?? '');
        if ($supplierStatus === 'supplier_booking_confirmed' || (string) ($line['availability_confidence'] ?? '') === 'confirmed') {
            return 'Bevestigd of gecontroleerd';
        }
        if (in_array($supplierStatus, array('supplier_unavailable', 'supplier_declined'), true) || (string) ($line['line_status'] ?? '') === 'unavailable') {
            return 'Niet beschikbaar, we nemen contact op over een alternatief';
        }
        return 'In behandeling';
    }

    /**
     * @param array<string,mixed> $quote
     * @param array<string,string> $order
     * @return array<int,string>
     */
    private static function openActions(array $quote, array $order): array
    {
        if ((string) ($quote['status'] ?? '') === 'sent') {
            return array('Bekijk het voorstel en geef akkoord of vraag een wijziging aan.');
        }
        if ((string) ($quote['status'] ?? '') === 'accepted' && (string) ($order['status_label'] ?? '') !== 'Betaling ontvangen') {
            return array('We verwerken uw akkoord en sturen de betaal- of bevestigingsstap zodra die klaarstaat.');
        }
        if ((string) ($order['status_label'] ?? '') === 'Betaling ontvangen') {
            return array('Geen actie nodig. Uw betaling is ontvangen.');
        }
        return array('Geen actie nodig. Wij houden u op de hoogte.');
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @return array<int,array{occurred_at:string,label:string}>
     */
    private static function customerEvents(array $events): array
    {
        $labels = array(
            'quote_created' => 'Aanvraag ontvangen',
            'customer_request_submitted' => 'Aanvraag ontvangen',
            'supplier_invited' => 'Partnerbeschikbaarheid aangevraagd',
            'supplier_confirmed' => 'Partneronderdeel bevestigd',
            'supplier_declined' => 'Partneronderdeel niet beschikbaar',
            'supplier_alternative_proposed' => 'Partner stelde een alternatief voor',
            'quote_marked_sent_manual' => 'Voorstel verzonden',
            'quote_sent' => 'Voorstel verzonden',
            'quote_accepted' => 'Akkoord ontvangen',
            'woo_order_created' => 'Order klaargezet',
            'quote_woo_payment_completed' => 'Betaling ontvangen',
            'payment_completed' => 'Betaling ontvangen',
        );

        $items = array();
        foreach ($events as $event) {
            $type = (string) ($event['event_type'] ?? '');
            if (! isset($labels[$type])) {
                continue;
            }
            $items[] = array(
                'occurred_at' => (string) ($event['occurred_at'] ?? ''),
                'label' => $labels[$type],
            );
        }

        return $items !== array() ? $items : array(array('occurred_at' => '', 'label' => 'Statuspagina aangemaakt'));
    }

    private static function quoteStatusLabel(string $status): string
    {
        return match ($status) {
            'sent' => 'Wacht op uw reactie',
            'accepted' => 'Akkoord ontvangen',
            'revision_requested' => 'Wijziging ontvangen',
            'declined' => 'Afgewezen',
            default => 'In behandeling',
        };
    }

    private static function sendStatusLabel(string $status): string
    {
        return match ($status) {
            'ready_to_send' => 'Klaar om te verzenden',
            'sent_manual' => 'Verzonden',
            default => 'In voorbereiding',
        };
    }

    /**
     * @param array<string,mixed> $line
     */
    private static function lineTime(array $line): string
    {
        $start = trim((string) (($line['start_time'] ?? '') ?: ($line['proposed_start_time'] ?? '')));
        $end = trim((string) (($line['end_time'] ?? '') ?: ($line['proposed_end_time'] ?? '')));
        return $start !== '' && $end !== '' ? $start . ' - ' . $end : ($start !== '' ? $start : 'Tijd in overleg');
    }

    private static function metric(string $label, string $value): void
    {
        echo '<div class="metric"><span>' . self::e($label) . '</span><strong>' . self::e($value) . '</strong></div>';
    }

    private static function dateLabel(string $date): string
    {
        if ($date === '') {
            return 'In overleg';
        }
        $timestamp = strtotime($date);
        if (! $timestamp) {
            return $date;
        }
        return function_exists('date_i18n') ? (string) date_i18n('j F Y', $timestamp) : date('j F Y', $timestamp);
    }

    private static function requestText(string $key): string
    {
        $value = $_GET[$key] ?? null;
        if (function_exists('wp_unslash')) {
            $value = wp_unslash($value);
        }
        return $value !== null ? self::cleanText((string) $value) : '';
    }

    private static function cleanText(string $value): string
    {
        return function_exists('sanitize_text_field') ? sanitize_text_field($value) : trim(strip_tags($value));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function styles(): string
    {
        return 'body{margin:0;background:#f6f3ef;color:#211f1c;font-family:system-ui,-apple-system,Segoe UI,sans-serif;line-height:1.5}.customer-page{max-width:960px;margin:0 auto;padding:24px}.hero{padding:28px 0 18px}.hero p,.hero span{margin:0;color:#6f665d}.hero h1{margin:6px 0 8px;font-size:clamp(30px,5vw,52px);line-height:1.05}.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}.metric,.panel{background:#fff;border:1px solid #ddd5cc;border-radius:8px;padding:16px}.metric span{display:block;color:#6f665d;font-size:13px}.metric strong{display:block;margin-top:3px}.panel{margin-top:14px}.panel h2{margin:0 0 10px}.program,.customer-timeline{list-style:none;margin:0;padding:0;display:grid;gap:10px}.program li,.customer-timeline li{display:grid;grid-template-columns:minmax(120px,auto) minmax(0,1fr);gap:14px;padding:12px;border:1px solid #e7dfd6;border-radius:8px}.program time,.customer-timeline time{color:#6f665d}.program p{margin:4px 0 0;color:#6f665d}.clean-list{margin:0;padding-left:20px}@media(max-width:640px){.customer-page{padding:16px}.program li,.customer-timeline li{grid-template-columns:1fr}}';
    }
}
