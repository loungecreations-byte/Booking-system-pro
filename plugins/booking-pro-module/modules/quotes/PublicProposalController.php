<?php

declare(strict_types=1);

namespace BSP\Quotes;

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Repository\QuoteRepositoryInterface;
use BSP\Quotes\Service\PublicQuoteProposalService;
use BSP\Quotes\Service\PublicQuoteProposalTokenService;
use BSP\Quotes\Service\QuoteEventLogger;
use InvalidArgumentException;

final class PublicProposalController
{
    public static function register(): void
    {
        if (function_exists('add_action')) {
            add_action('template_redirect', array(self::class, 'maybeRender'));
            add_action('admin_post_nopriv_sbdp_public_quote_proposal', array(self::class, 'handleAction'));
            add_action('admin_post_sbdp_public_quote_proposal', array(self::class, 'handleAction'));
        }
    }

    public static function maybeRender(): void
    {
        $token = self::requestToken();
        if ($token === '') {
            return;
        }

        $repository = new QuoteRepository();
        $service = self::service($repository);
        $notice = self::requestText('proposal_notice');
        $error = self::requestText('proposal_error');

        try {
            $context = $service->resolveByToken($token);
            $html = self::renderPage($token, $context, $notice, $error);
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

    public static function handleAction(): void
    {
        $token = self::postText('proposal_token');
        $action = self::postText('proposal_action');
        $tokenId = (new PublicQuoteProposalTokenService())->tokenId($token);

        if (! self::verifyNonce('sbdp_public_quote_proposal_' . $tokenId)) {
            self::redirectWith($token, array('proposal_error' => 'De sessie is verlopen. Probeer het opnieuw.'));
        }

        $repository = new QuoteRepository();
        $service = self::service($repository);
        $client = self::clientContext();

        try {
            if ($action === 'accept') {
                $service->accept($token, $client);
                self::redirectWith($token, array('proposal_notice' => 'Dank u wel. Uw akkoord is vastgelegd.'));
            }
            if ($action === 'revision') {
                $service->requestRevision($token, self::postTextarea('message'), $client);
                self::redirectWith($token, array('proposal_notice' => 'Dank u wel. Uw wijzigingsverzoek is ontvangen.'));
            }
            if ($action === 'decline') {
                $service->decline($token, self::postTextarea('message'), $client);
                self::redirectWith($token, array('proposal_notice' => 'Uw afwijzing is vastgelegd.'));
            }

            throw new InvalidArgumentException('Onbekende actie.');
        } catch (\Throwable $exception) {
            self::redirectWith($token, array('proposal_error' => $exception->getMessage()));
        }
    }

    public static function publicUrl(string $token): string
    {
        $base = function_exists('home_url') ? (string) home_url('/') : '/';
        return self::addQueryArg(array('ddb_quote_proposal' => $token), $base);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function renderPage(string $token, array $context, string $notice = '', string $error = ''): string
    {
        $quote = is_array($context['quote'] ?? null) ? $context['quote'] : array();
        $version = is_array($context['version'] ?? null) ? $context['version'] : array();
        $request = is_array($context['request'] ?? null) ? $context['request'] : array();
        $lines = is_array($context['lines'] ?? null) ? $context['lines'] : array();
        $summary = self::summarizeLines($lines);
        $title = trim((string) ($version['proposal_title'] ?? ''));
        $title = $title !== '' ? $title : 'Voorstel Dagje Den Bosch';
        $tokenId = (new PublicQuoteProposalTokenService())->tokenId($token);
        $actionable = ! empty($context['actionable']);

        ob_start();
        echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . self::e($title) . '</title>';
        echo '<style>' . self::styles() . '</style></head><body><main class="quote-page">';
        echo '<header class="quote-hero"><p>Dagje Den Bosch</p><h1>' . self::e($title) . '</h1>';
        echo '<span>Referentie ' . self::e((string) ($quote['quote_reference'] ?? '')) . '</span></header>';
        if ($notice !== '') {
            echo '<div class="notice is-success">' . self::e($notice) . '</div>';
        }
        if ($error !== '') {
            echo '<div class="notice is-error">' . self::e($error) . '</div>';
        }
        echo '<section class="summary-grid">';
        self::metric('Datum', self::dateLabel((string) ($request['preferred_date'] ?? '')));
        self::metric('Groepsgrootte', ((int) ($request['group_size'] ?? 0)) > 0 ? sprintf('%d personen', (int) $request['group_size']) : 'In overleg');
        self::metric('Prijs', $summary['total_label']);
        self::metric('Status', self::publicStatusLabel((string) ($quote['status'] ?? '')));
        echo '</section>';
        echo '<section class="panel"><h2>Samenvatting</h2><p>' . self::e(self::summaryText($version, $request)) . '</p></section>';
        echo '<section class="panel"><h2>Programma</h2>';
        if ($lines === array()) {
            echo '<p>Het programma wordt persoonlijk afgestemd.</p>';
        } else {
            echo '<ol class="timeline">';
            foreach ($lines as $line) {
                echo '<li><time>' . self::e(self::lineTime($line)) . '</time><div><strong>' . self::e((string) ($line['title'] ?? 'Programmaonderdeel')) . '</strong>';
                $details = array_filter(array(
                    (string) ($line['external_label'] ?? ''),
                    (string) ($line['validated_slot_label'] ?? ''),
                    self::lineOptions($line),
                ));
                if ($details !== array()) {
                    echo '<p>' . self::e(implode(' · ', $details)) . '</p>';
                }
                echo '</div></li>';
            }
            echo '</ol>';
        }
        echo '</section>';
        echo '<section class="panel"><h2>Prijs</h2><div class="price">' . self::e($summary['total_label']) . '</div><p>Dit is het voorstelbedrag voor deze aanvraag. Definitieve betaling en eventuele orderafhandeling lopen niet via deze pagina.</p></section>';
        echo '<section class="panel"><h2>Inbegrepen</h2><ul class="clean-list">';
        foreach (self::includedItems($lines) as $item) {
            echo '<li>' . self::e($item) . '</li>';
        }
        echo '</ul></section>';
        echo '<section class="panel"><h2>Voorwaarden en geldigheid</h2><p>Dit voorstel is gebaseerd op de besproken datum, groepsgrootte en programmaonderdelen. Beschikbaarheid blijft onder voorbehoud totdat alle onderdelen definitief bevestigd zijn.</p></section>';
        echo '<section class="panel actions"><h2>Uw reactie</h2>';
        if ($actionable) {
            echo self::actionForm($token, $tokenId, 'accept', 'Akkoord geven', '', true);
            echo self::actionForm($token, $tokenId, 'revision', 'Wijziging aanvragen', 'Beschrijf welke wijziging u wilt aanvragen.', false, true);
            echo self::actionForm($token, $tokenId, 'decline', 'Afwijzen', 'U kunt eventueel aangeven waarom dit voorstel niet past.');
        } else {
            echo '<p>Dit voorstel is al verwerkt. Neem contact met ons op als u nog iets wilt bespreken.</p>';
        }
        echo '</section>';
        echo '</main></body></html>';

        return (string) ob_get_clean();
    }

    public static function renderUnavailable(string $message): string
    {
        return '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Voorstel niet beschikbaar</title><style>' . self::styles() . '</style></head><body><main class="quote-page"><section class="panel"><h1>Voorstel niet beschikbaar</h1><p>' . self::e($message !== '' ? $message : 'Controleer de link of neem contact met ons op.') . '</p></section></main></body></html>';
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
     * @param array<int, array<string, mixed>> $lines
     * @return array{total_label:string}
     */
    private static function summarizeLines(array $lines): array
    {
        $total = 0.0;
        $priced = 0;
        $currency = 'EUR';
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $currency = (string) (($line['currency'] ?? '') ?: $currency);
            if (isset($line['line_total_snapshot']) && $line['line_total_snapshot'] !== '' && is_numeric($line['line_total_snapshot'])) {
                $total += (float) $line['line_total_snapshot'];
                $priced++;
            }
        }

        return array(
            'total_label' => $priced > 0 ? self::money($total, $currency) : 'Op aanvraag',
        );
    }

    /**
     * @param array<string, mixed> $version
     * @param array<string, mixed> $request
     */
    private static function summaryText(array $version, array $request): string
    {
        $summary = trim((string) ($version['proposal_summary'] ?? ''));
        if ($summary !== '') {
            return $summary;
        }

        $summary = trim((string) ($request['request_summary'] ?? ''));
        return $summary !== '' ? $summary : 'Een zorgvuldig samengesteld dagprogramma in Den Bosch.';
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function lineTime(array $line): string
    {
        $start = trim((string) (($line['proposed_start_time'] ?? '') ?: ($line['start_time'] ?? '')));
        $end = trim((string) (($line['proposed_end_time'] ?? '') ?: ($line['end_time'] ?? '')));
        if ($start !== '' && $end !== '') {
            return $start . ' - ' . $end;
        }
        if ($start !== '') {
            return $start;
        }

        return 'Tijd in overleg';
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function lineOptions(array $line): string
    {
        $options = $line['selected_option_labels_json'] ?? array();
        if (! is_array($options)) {
            return '';
        }

        $labels = array_values(array_filter(array_map(static fn ($value): string => trim((string) $value), $options)));
        return implode(', ', array_slice($labels, 0, 4));
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, string>
     */
    private static function includedItems(array $lines): array
    {
        $items = array();
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $title = trim((string) ($line['title'] ?? ''));
            if ($title !== '') {
                $items[] = $title;
            }
        }

        return $items !== array() ? array_values(array_unique($items)) : array('Persoonlijke afstemming van het programma');
    }

    private static function actionForm(string $token, string $tokenId, string $action, string $label, string $messageLabel = '', bool $primary = false, bool $requiredMessage = false): string
    {
        $html = '<form method="post" action="' . self::e(self::adminPostUrl()) . '" class="action-form">';
        $html .= self::nonceField('sbdp_public_quote_proposal_' . $tokenId);
        $html .= '<input type="hidden" name="action" value="sbdp_public_quote_proposal">';
        $html .= '<input type="hidden" name="proposal_action" value="' . self::e($action) . '">';
        $html .= '<input type="hidden" name="proposal_token" value="' . self::e($token) . '">';
        if ($messageLabel !== '') {
            $html .= '<label>' . self::e($messageLabel) . '<textarea name="message" rows="4"' . ($requiredMessage ? ' required' : '') . '></textarea></label>';
        }
        $html .= '<button class="' . ($primary ? 'primary' : 'secondary') . '" type="submit">' . self::e($label) . '</button></form>';

        return $html;
    }

    private static function publicStatusLabel(string $status): string
    {
        return match ($status) {
            'sent' => 'Wacht op uw reactie',
            'accepted' => 'Akkoord ontvangen',
            'revision_requested' => 'Wijziging ontvangen',
            'declined' => 'Afgewezen',
            default => 'Niet beschikbaar',
        };
    }

    private static function metric(string $label, string $value): void
    {
        echo '<div class="metric"><span>' . self::e($label) . '</span><strong>' . self::e($value) . '</strong></div>';
    }

    private static function requestToken(): string
    {
        return self::requestText('ddb_quote_proposal');
    }

    private static function requestText(string $key): string
    {
        $value = $_GET[$key] ?? null;
        if (function_exists('wp_unslash')) {
            $value = wp_unslash($value);
        }

        return $value !== null ? self::cleanText((string) $value) : '';
    }

    private static function postText(string $key): string
    {
        $value = $_POST[$key] ?? null;
        if (function_exists('wp_unslash')) {
            $value = wp_unslash($value);
        }

        return $value !== null ? self::cleanText((string) $value) : '';
    }

    private static function postTextarea(string $key): string
    {
        $value = $_POST[$key] ?? '';
        if (function_exists('wp_unslash')) {
            $value = wp_unslash($value);
        }

        $value = (string) $value;
        return function_exists('sanitize_textarea_field') ? sanitize_textarea_field($value) : trim(strip_tags($value));
    }

    private static function cleanText(string $value): string
    {
        return function_exists('sanitize_text_field') ? sanitize_text_field($value) : trim(strip_tags($value));
    }

    private static function verifyNonce(string $action): bool
    {
        if (! function_exists('check_admin_referer')) {
            return true;
        }

        return check_admin_referer($action, '_wpnonce', false) !== false;
    }

    private static function nonceField(string $action): string
    {
        return function_exists('wp_nonce_field')
            ? (string) wp_nonce_field($action, '_wpnonce', true, false)
            : '';
    }

    private static function redirectWith(string $token, array $args): void
    {
        $url = self::addQueryArg(array_merge(array('ddb_quote_proposal' => $token), $args), function_exists('home_url') ? (string) home_url('/') : '/');
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($url);
            exit;
        }

        header('Location: ' . $url);
        exit;
    }

    /**
     * @return array{ip:string,user_agent:string}
     */
    private static function clientContext(): array
    {
        return array(
            'ip' => self::cleanText((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
            'user_agent' => substr(self::cleanText((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500),
        );
    }

    private static function adminPostUrl(): string
    {
        return function_exists('admin_url') ? (string) admin_url('admin-post.php') : '/wp-admin/admin-post.php';
    }

    /**
     * @param array<string, string> $args
     */
    private static function addQueryArg(array $args, string $url): string
    {
        if (function_exists('add_query_arg')) {
            return (string) add_query_arg($args, $url);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }

    private static function money(float $amount, string $currency): string
    {
        return strtoupper($currency ?: 'EUR') . ' ' . number_format($amount, 2, ',', '.');
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

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function styles(): string
    {
        return 'body{margin:0;background:#f6f3ef;color:#211f1c;font-family:system-ui,-apple-system,Segoe UI,sans-serif;line-height:1.5}.quote-page{max-width:960px;margin:0 auto;padding:24px}.quote-hero{padding:28px 0 18px}.quote-hero p,.quote-hero span{margin:0;color:#6f665d}.quote-hero h1{margin:6px 0 8px;font-size:clamp(30px,5vw,52px);line-height:1.05}.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:0 0 14px}.metric,.panel,.notice{background:#fff;border:1px solid #ddd5cc;border-radius:8px;padding:16px}.metric span{display:block;color:#6f665d;font-size:13px}.metric strong{display:block;margin-top:3px}.panel{margin-top:14px}.panel h2{margin:0 0 10px;font-size:22px}.timeline{list-style:none;margin:0;padding:0;display:grid;gap:10px}.timeline li{display:grid;grid-template-columns:minmax(96px,auto) minmax(0,1fr);gap:14px;padding:12px;border:1px solid #e7dfd6;border-radius:8px}.timeline time{color:#6f665d}.timeline p{margin:4px 0 0;color:#6f665d}.price{font-size:28px;font-weight:700}.clean-list{margin:0;padding-left:20px}.actions{display:grid;gap:12px}.action-form{display:grid;gap:8px;padding-top:10px;border-top:1px solid #eee7df}.action-form label{display:grid;gap:6px}.action-form textarea{width:100%;box-sizing:border-box;border:1px solid #c8bfb5;border-radius:6px;padding:10px;font:inherit}button{width:max-content;max-width:100%;border:1px solid #3b342d;border-radius:6px;padding:10px 14px;font-weight:700;cursor:pointer}button.primary{background:#3b342d;color:#fff}button.secondary{background:#fff;color:#3b342d}.notice.is-success{border-color:#6aa56a}.notice.is-error{border-color:#c65f52}@media(max-width:640px){.quote-page{padding:16px}.timeline li{grid-template-columns:1fr}button{width:100%}}';
    }
}
