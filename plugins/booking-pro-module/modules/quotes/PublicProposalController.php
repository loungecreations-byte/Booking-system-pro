<?php

declare(strict_types=1);

namespace BSP\Quotes;

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Repository\QuoteRepositoryInterface;
use BSP\Quotes\Service\PublicQuoteProposalService;
use BSP\Quotes\Service\PublicQuoteProposalTokenService;
use BSP\Quotes\Service\QuoteAcceptedDocumentService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteLegalAcceptancePayloadService;
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
            if (self::requestText('ddb_quote_proposal_pdf') === '1') {
                self::downloadAcceptedPdf($repository, $context);
            }
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
                $service->accept($token, $client, self::acceptanceInput());
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

    public static function pdfUrl(string $token): string
    {
        $base = function_exists('home_url') ? (string) home_url('/') : '/';
        return self::addQueryArg(array('ddb_quote_proposal' => $token, 'ddb_quote_proposal_pdf' => '1'), $base);
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
        $statusLabel = self::publicStatusLabel((string) ($quote['status'] ?? ''));
        $dateLabel = self::dateLabel((string) ($request['preferred_date'] ?? ''));
        $groupLabel = ((int) ($request['group_size'] ?? 0)) > 0 ? sprintf('%d personen', (int) $request['group_size']) : 'In overleg';

        ob_start();
        echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . self::e($title) . '</title>';
        echo '<style>' . self::styles() . '</style></head><body><main class="quote-page">';
        echo '<header class="quote-hero"><nav class="quote-brand" aria-label="Voorstel"><span>Dagje Den Bosch</span><strong>Persoonlijk voorstel</strong></nav>';
        echo '<div class="quote-hero__grid"><div><p class="eyebrow">Voorstel ' . self::e((string) ($quote['quote_reference'] ?? '')) . '</p><h1>' . self::e($title) . '</h1>';
        echo '<p class="hero-copy">' . self::e(self::summaryText($version, $request)) . '</p></div>';
        echo '<aside class="hero-card" aria-label="Samenvatting"><span class="status-pill ' . self::e(self::statusTone((string) ($quote['status'] ?? ''))) . '">' . self::e($statusLabel) . '</span>';
        echo '<dl><div><dt>Datum</dt><dd>' . self::e($dateLabel) . '</dd></div><div><dt>Groep</dt><dd>' . self::e($groupLabel) . '</dd></div><div><dt>Voorstelbedrag</dt><dd>' . self::e($summary['total_label']) . '</dd></div></dl></aside></div></header>';
        if ($notice !== '') {
            echo '<div class="notice is-success">' . self::e($notice) . '</div>';
        }
        if ($error !== '') {
            echo '<div class="notice is-error">' . self::e($error) . '</div>';
        }
        echo '<div class="proposal-shell"><section class="proposal-main">';
        echo '<section class="panel panel--intro"><div><p class="section-kicker">Overzicht</p><h2>Uw dag in het kort</h2></div><div class="summary-grid">';
        self::metric('Datum', $dateLabel);
        self::metric('Groepsgrootte', $groupLabel);
        self::metric('Prijs', $summary['total_label']);
        self::metric('Status', $statusLabel);
        echo '</div></section>';
        echo '<section class="panel"><div class="section-heading"><p class="section-kicker">Programma</p><h2>Voorgestelde planning</h2></div>';
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
                echo '<span class="line-status">' . self::e(self::lineCustomerStatus($line)) . '</span>';
                echo '</div></li>';
            }
            echo '</ol>';
        }
        echo '</section>';
        echo '<section class="panel"><div class="section-heading"><p class="section-kicker">Kosten</p><h2>Kostenoverzicht</h2></div>';
        echo '<div class="cost-list">';
        if ($lines === array()) {
            echo '<div class="cost-row"><span>Programma op maat</span><strong>Op aanvraag</strong></div>';
        } else {
            foreach ($lines as $line) {
                echo '<div class="cost-row"><span>' . self::e((string) ($line['title'] ?? 'Programmaonderdeel')) . '</span><strong>' . self::e(self::linePriceLabel($line)) . '</strong></div>';
            }
        }
        echo '<div class="cost-row cost-row--total"><span>Totaal voorstel</span><strong>' . self::e($summary['total_label']) . '</strong></div></div>';
        echo '<p class="muted">Dit voorstelbedrag is gebaseerd op de vastgelegde programmaregels en snapshots in deze offerteversie. Eventuele betaling en definitieve orderafhandeling volgen pas na akkoord.</p></section>';
        echo '<section class="panel two-column"><div><div class="section-heading"><p class="section-kicker">Inclusief</p><h2>Inbegrepen</h2></div><ul class="clean-list">';
        foreach (self::includedItems($lines) as $item) {
            echo '<li>' . self::e($item) . '</li>';
        }
        echo '</ul></div><div><div class="section-heading"><p class="section-kicker">Niet inclusief</p><h2>Goed om te weten</h2></div><ul class="clean-list">';
        foreach (self::notIncludedItems() as $item) {
            echo '<li>' . self::e($item) . '</li>';
        }
        echo '</ul></div></section>';
        echo '<section class="panel"><div class="section-heading"><p class="section-kicker">Voorwaarden</p><h2>Geldigheid en bevestiging</h2></div><p>Dit voorstel is gebaseerd op de besproken datum, groepsgrootte en programmaonderdelen. Beschikbaarheid blijft onder voorbehoud totdat alle onderdelen definitief bevestigd zijn.</p></section>';
        echo '</section><aside class="decision-card"><div class="decision-card__inner"><span class="status-pill ' . self::e(self::statusTone((string) ($quote['status'] ?? ''))) . '">' . self::e($statusLabel) . '</span><h2>Uw reactie</h2><p>Controleer het voorstel en geef aan hoe u verder wilt.</p>';
        if ($actionable) {
            echo self::actionForm($token, $tokenId, 'accept', 'Akkoord geven', '', true, false, $request);
            echo self::actionForm($token, $tokenId, 'revision', 'Wijziging aanvragen', 'Beschrijf welke wijziging u wilt aanvragen.', false, true);
            echo self::actionForm($token, $tokenId, 'decline', 'Afwijzen', 'U kunt eventueel aangeven waarom dit voorstel niet past.');
        } else {
            echo '<p>Dit voorstel is al verwerkt. Neem contact met ons op als u nog iets wilt bespreken.</p>';
            if (in_array((string) ($quote['status'] ?? ''), array('accepted', 'confirmed', 'operations_ready'), true)) {
                echo '<p><a class="secondary download-link" href="' . self::e(self::pdfUrl($token)) . '">Download geaccepteerde offerte als PDF</a></p>';
            }
        }
        echo '</div></aside></div><footer class="quote-footer">Dagje Den Bosch · Persoonlijk samengesteld voorstel</footer>';
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
     * @param array<string, mixed> $context
     */
    private static function downloadAcceptedPdf(QuoteRepositoryInterface $repository, array $context): void
    {
        $quote = is_array($context['quote'] ?? null) ? $context['quote'] : array();
        $quoteId = (int) ($quote['id'] ?? 0);
        if ($quoteId <= 0 || ! in_array((string) ($quote['status'] ?? ''), array('accepted', 'confirmed', 'operations_ready'), true)) {
            throw new InvalidArgumentException('Er is nog geen geaccepteerde offerte beschikbaar.');
        }

        $documents = new QuoteAcceptedDocumentService($repository);
        $pdf = $documents->renderPdf($quoteId);
        if (! headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $documents->filename($quoteId) . '"');
            header('Content-Length: ' . strlen($pdf));
        }

        echo $pdf;
        exit;
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

    /**
     * @return array<int, string>
     */
    private static function notIncludedItems(): array
    {
        return array(
            'Extra consumpties of uitbreidingen buiten dit voorstel',
            'Wijzigingen in datum, groepsgrootte of programma na akkoord',
            'Onderdelen die nog expliciet door leverancier of locatie bevestigd moeten worden',
        );
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function linePriceLabel(array $line): string
    {
        if (isset($line['line_total_snapshot']) && $line['line_total_snapshot'] !== '' && is_numeric($line['line_total_snapshot'])) {
            return self::money((float) $line['line_total_snapshot'], (string) (($line['currency'] ?? '') ?: 'EUR'));
        }

        return 'Op aanvraag';
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function lineCustomerStatus(array $line): string
    {
        $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
        $supplierStatus = (string) ($snapshot['supplierStatus'] ?? '');
        if (in_array($supplierStatus, array('supplier_booking_confirmed', 'supplier_confirmed'), true)) {
            return 'Bevestigd onderdeel';
        }
        if (in_array($supplierStatus, array('supplier_confirmation_required', 'supplier_option_requested', 'supplier_option_held'), true)) {
            return 'Onder voorbehoud';
        }
        if (in_array($supplierStatus, array('supplier_declined', 'supplier_unavailable'), true) || (string) ($line['line_status'] ?? '') === 'unavailable') {
            return 'Niet beschikbaar';
        }

        return 'In voorstel opgenomen';
    }

    private static function statusTone(string $status): string
    {
        return match ($status) {
            'accepted', 'confirmed', 'operations_ready' => 'is-good',
            'revision_requested' => 'is-attention',
            'declined' => 'is-muted',
            default => 'is-open',
        };
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function actionForm(string $token, string $tokenId, string $action, string $label, string $messageLabel = '', bool $primary = false, bool $requiredMessage = false, array $request = array()): string
    {
        $html = '<form method="post" action="' . self::e(self::adminPostUrl()) . '" class="action-form">';
        $html .= self::nonceField('sbdp_public_quote_proposal_' . $tokenId);
        $html .= '<input type="hidden" name="action" value="sbdp_public_quote_proposal">';
        $html .= '<input type="hidden" name="proposal_action" value="' . self::e($action) . '">';
        $html .= '<input type="hidden" name="proposal_token" value="' . self::e($token) . '">';
        if ($action === 'accept') {
            $html .= '<input type="hidden" name="terms_version" value="' . self::e(QuoteLegalAcceptancePayloadService::TERMS_VERSION) . '">';
            $html .= '<input type="hidden" name="terms_url" value="' . self::e(self::termsUrl()) . '">';
            $html .= '<div class="acceptance-fields" aria-label="Akkoordgegevens">';
            $html .= '<label>Naam akkoordgever<input type="text" name="acceptance_name" value="' . self::e((string) ($request['requester_name'] ?? '')) . '" required autocomplete="name"></label>';
            $html .= '<label>E-mailadres akkoordgever<input type="email" name="acceptance_email" value="' . self::e((string) ($request['requester_email'] ?? '')) . '" required autocomplete="email"></label>';
            $html .= '<label>Bedrijfsnaam<input type="text" name="acceptance_company" value="' . self::e((string) ($request['requester_company'] ?? '')) . '" autocomplete="organization"></label>';
            $html .= '<label>Functie / rol<input type="text" name="acceptance_role" value="" autocomplete="organization-title"></label>';
            $html .= '<label class="acceptance-checkbox"><input type="checkbox" name="acceptance_terms_checked" value="1" required> <span>Ik ga akkoord met het programma, de prijsopbouw en de geldende voorwaarden.</span></label>';
            $html .= '</div>';
        }
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
            'confirmed', 'operations_ready' => 'Bevestigd',
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

    /**
     * @return array<string, string>
     */
    private static function acceptanceInput(): array
    {
        return array(
            'acceptance_name' => self::postText('acceptance_name'),
            'acceptance_email' => self::postText('acceptance_email'),
            'acceptance_company' => self::postText('acceptance_company'),
            'acceptance_role' => self::postText('acceptance_role'),
            'acceptance_terms_checked' => isset($_POST['acceptance_terms_checked']) ? self::postText('acceptance_terms_checked') : '',
            'terms_version' => self::postText('terms_version'),
            'terms_url' => self::postText('terms_url'),
        );
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

    private static function termsUrl(): string
    {
        return function_exists('home_url') ? (string) home_url('/voorwaarden/') : '/voorwaarden/';
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
        return '*,*::before,*::after{box-sizing:border-box}html{background:#000}body{margin:0;background:radial-gradient(circle at 20% -10%,rgba(199,167,109,.14),transparent 34%),linear-gradient(180deg,#070604 0,#000 42%,#000 100%);color:#f5efe6;font-family:Quattrocento Sans,Inter,system-ui,-apple-system,Segoe UI,sans-serif;line-height:1.45;-webkit-font-smoothing:antialiased}.quote-page{max-width:1200px;margin:0 auto;padding:24px}.quote-brand{display:flex;justify-content:space-between;gap:16px;align-items:center;color:#d6c2a2;font-size:12px}.quote-brand span{letter-spacing:.16em;text-transform:uppercase}.quote-brand strong{font-weight:700;color:#f4efe7}.quote-hero{padding:10px 0 24px}.quote-hero__grid{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:24px;align-items:end;margin-top:40px}.eyebrow,.section-kicker{margin:0 0 8px;color:#c7a76d;font-size:11px;font-weight:800;letter-spacing:.15em;text-transform:uppercase}.quote-hero h1{margin:0;font-family:Quattrocento,Georgia,serif;font-size:clamp(36px,5vw,68px);line-height:1.01;max-width:780px;letter-spacing:0}.hero-copy{margin:16px 0 0;max-width:700px;color:#d2c7b8;font-size:18px}.hero-card,.panel,.decision-card__inner,.notice{border:1px solid rgba(214,194,162,.24);border-radius:8px;background:linear-gradient(180deg,rgba(24,22,18,.96),rgba(12,12,11,.98));box-shadow:0 22px 70px rgba(0,0,0,.42)}.hero-card{padding:18px}.hero-card dl{display:grid;gap:0;margin:14px 0 0}.hero-card div{display:flex;justify-content:space-between;gap:14px;border-top:1px solid rgba(255,255,255,.08);padding:11px 0}.hero-card div:last-child{padding-bottom:0}.hero-card dt{color:#9f968b}.hero-card dd{margin:0;text-align:right;font-weight:850}.proposal-shell{display:grid;grid-template-columns:minmax(0,1fr) 350px;gap:18px;align-items:start}.proposal-main{display:grid;gap:12px}.panel,.notice{padding:18px}.panel--intro{display:grid;gap:14px}.section-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:12px}.panel h2,.decision-card h2{margin:0;font-family:Quattrocento,Georgia,serif;font-size:24px;line-height:1.12}.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.metric{border:1px solid rgba(255,255,255,.08);border-radius:8px;background:#11100e;padding:12px}.metric span{display:block;color:#9f968b;font-size:11px;text-transform:uppercase;letter-spacing:.06em}.metric strong{display:block;margin-top:4px}.timeline{list-style:none;margin:0;padding:0;display:grid;gap:8px;counter-reset:stop}.timeline li{counter-increment:stop;display:grid;grid-template-columns:104px minmax(0,1fr);gap:14px;padding:13px;border:1px solid rgba(255,255,255,.09);border-radius:8px;background:#111;position:relative}.timeline li::before{content:counter(stop);position:absolute;left:-7px;top:13px;width:20px;height:20px;border-radius:999px;background:#c7a76d;color:#090806;display:grid;place-items:center;font-size:11px;font-weight:900}.timeline time{color:#d6c2a2;font-weight:850}.timeline p{margin:4px 0 7px;color:#aaa197}.line-status{display:inline-flex;border:1px solid rgba(199,167,109,.36);border-radius:999px;padding:3px 9px;color:#dcc89f;font-size:12px}.cost-list{display:grid;gap:0}.cost-row{display:flex;justify-content:space-between;gap:16px;padding:11px 0;border-bottom:1px solid rgba(255,255,255,.08)}.cost-row span{color:#ddd5cb}.cost-row strong{white-space:nowrap}.cost-row--total{border-bottom:0;margin-top:2px;padding-top:15px;color:#f7d790;font-size:21px}.muted{color:#aaa197}.two-column{display:grid;grid-template-columns:1fr 1fr;gap:16px}.clean-list{margin:0;padding-left:18px;color:#ddd5cb}.clean-list li+li{margin-top:7px}.decision-card{position:sticky;top:18px}.decision-card__inner{padding:18px;display:grid;gap:12px;background:linear-gradient(180deg,rgba(31,27,20,.98),rgba(8,8,8,.99));border-color:rgba(247,215,144,.34)}.decision-card__inner::after{content:"";height:1px;background:linear-gradient(90deg,transparent,rgba(247,215,144,.45),transparent);order:-1}.decision-card p{margin:0;color:#aaa197}.status-pill{display:inline-flex;width:max-content;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:850}.status-pill.is-open{background:#271d0e;color:#f0c979}.status-pill.is-good{background:#0f241b;color:#82dfaa}.status-pill.is-attention{background:#241d12;color:#f0c979}.status-pill.is-muted{background:#1c1c1c;color:#aaa}.action-form{display:grid;gap:9px;padding-top:10px;border-top:1px solid rgba(255,255,255,.08)}.action-form label{display:grid;gap:6px;color:#ddd5cb;font-size:13px}.action-form input,.action-form textarea{width:100%;border:1px solid rgba(214,194,162,.28);border-radius:8px;background:#050505;color:#f4efe7;padding:10px;font:inherit}.action-form input:focus,.action-form textarea:focus{outline:2px solid rgba(247,215,144,.32);border-color:#d6c2a2}.action-form textarea{min-height:92px}.acceptance-fields{display:grid;gap:8px}.acceptance-checkbox{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:start}.acceptance-checkbox input{width:auto;margin-top:3px}button,.download-link{width:100%;border:1px solid #d6c2a2;border-radius:8px;padding:11px 14px;font-weight:850;cursor:pointer;text-decoration:none;text-align:center;display:inline-flex;justify-content:center;align-items:center;min-height:42px}button.primary{background:#d6c2a2;color:#111}button.primary:hover{background:#f0dcae}button.secondary,.download-link{background:transparent;color:#f4efe7}button.secondary:hover,.download-link:hover{background:rgba(214,194,162,.08)}.notice{margin:0 0 14px}.notice.is-success{border-color:#75d6a2}.notice.is-error{border-color:#d87568}.quote-footer{padding:28px 0 8px;color:#8f877d;text-align:center;font-size:13px}@media(max-width:900px){.quote-hero__grid,.proposal-shell,.two-column{grid-template-columns:1fr}.decision-card{position:static;order:-1}.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.proposal-shell{display:flex;flex-direction:column}.proposal-main,.decision-card{width:100%}}@media(max-width:640px){.quote-page{padding:14px}.quote-brand{align-items:flex-start;flex-direction:column}.quote-hero{padding-bottom:14px}.quote-hero__grid{margin-top:24px}.quote-hero h1{font-size:34px}.hero-copy{font-size:16px}.panel,.hero-card,.decision-card__inner{padding:15px}.timeline li{grid-template-columns:1fr;padding-left:18px}.summary-grid{grid-template-columns:1fr}.section-heading{display:block}.hero-card div,.cost-row{align-items:flex-start;flex-direction:column;gap:4px}.hero-card dd{text-align:left}.cost-row strong{white-space:normal}.decision-card__inner{gap:10px}.action-form{gap:8px}}';
    }
}
