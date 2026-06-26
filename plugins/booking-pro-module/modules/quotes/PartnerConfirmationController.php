<?php

declare(strict_types=1);

namespace BSP\Quotes;

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Repository\QuoteRepositoryInterface;
use BSP\Quotes\Service\PartnerConfirmationService;
use BSP\Quotes\Service\PartnerConfirmationTokenService;
use BSP\Quotes\Service\QuoteTimelineService;
use InvalidArgumentException;

final class PartnerConfirmationController
{
    public static function register(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('template_redirect', array(self::class, 'maybeRender'));
        add_action('admin_post_nopriv_sbdp_partner_confirmation', array(self::class, 'handleAction'));
        add_action('admin_post_sbdp_partner_confirmation', array(self::class, 'handleAction'));
    }

    public static function maybeRender(): void
    {
        $token = self::requestText('ddb_partner_confirmation');
        if ($token === '') {
            return;
        }

        $repository = new QuoteRepository();
        $service = self::service($repository);
        $notice = self::requestText('partner_notice');
        $error = self::requestText('partner_error');

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
        $token = self::postText('partner_token');
        $action = self::postText('partner_action');
        $tokenId = (new PartnerConfirmationTokenService())->tokenId($token);

        if (! self::verifyNonce('sbdp_partner_confirmation_' . $tokenId)) {
            self::redirectWith($token, array('partner_error' => 'De sessie is verlopen. Probeer het opnieuw.'));
        }

        $repository = new QuoteRepository();
        $service = self::service($repository);
        try {
            $service->respond($token, $action, self::postTextarea('message'), self::clientContext());
            self::redirectWith($token, array('partner_notice' => 'Dank u wel. Uw reactie is opgeslagen.'));
        } catch (\Throwable $exception) {
            self::redirectWith($token, array('partner_error' => $exception->getMessage()));
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function renderPage(string $token, array $context, string $notice = '', string $error = ''): string
    {
        $quote = is_array($context['quote'] ?? null) ? $context['quote'] : array();
        $line = is_array($context['line'] ?? null) ? $context['line'] : array();
        $request = is_array($context['request'] ?? null) ? $context['request'] : array();
        $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
        $tokenId = (string) ($context['token_id'] ?? (new PartnerConfirmationTokenService())->tokenId($token));
        $title = trim((string) ($line['title'] ?? 'Aanvraag'));
        $status = trim((string) ($snapshot['supplierStatus'] ?? 'supplier_confirmation_required'));

        ob_start();
        echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . self::e($title !== '' ? $title : 'Partnerbevestiging') . '</title><style>' . self::styles() . '</style></head><body><main class="partner-page">';
        echo '<header class="hero"><p>Dagje Den Bosch</p><h1>Partnerbevestiging</h1><span>Referentie ' . self::e((string) ($quote['quote_reference'] ?? '')) . '</span></header>';
        if ($notice !== '') {
            echo '<div class="notice is-success">' . self::e($notice) . '</div>';
        }
        if ($error !== '') {
            echo '<div class="notice is-error">' . self::e($error) . '</div>';
        }
        echo '<section class="panel"><h2>' . self::e($title !== '' ? $title : 'Activiteit') . '</h2><dl class="facts">';
        self::fact('Datum', (string) (($line['service_date'] ?? '') ?: ($snapshot['date'] ?? '') ?: ($request['preferred_date'] ?? 'In overleg')));
        self::fact('Tijd', self::lineTime($line));
        self::fact('Personen', ((int) ($line['participants'] ?? ($snapshot['participants'] ?? 0))) > 0 ? (string) ((int) ($line['participants'] ?? ($snapshot['participants'] ?? 0))) : 'In overleg');
        self::fact('Status', self::statusLabel($status));
        echo '</dl></section>';
        if (! empty($snapshot['supplierExposeInternalNote']) && trim((string) ($snapshot['supplierInternalNote'] ?? '')) !== '') {
            echo '<section class="panel"><h2>Notitie</h2><p>' . self::e((string) $snapshot['supplierInternalNote']) . '</p></section>';
        }
        echo '<section class="panel actions"><h2>Uw reactie</h2>';
        echo self::actionForm($token, $tokenId, 'confirm', 'Bevestigen', '', true);
        echo self::actionForm($token, $tokenId, 'decline', 'Niet beschikbaar', 'Optionele toelichting');
        echo self::actionForm($token, $tokenId, 'alternative', 'Alternatief voorstellen', 'Beschrijf datum/tijd of voorwaarden voor het alternatief', false, true);
        echo '</section></main></body></html>';

        return (string) ob_get_clean();
    }

    public static function renderUnavailable(string $message): string
    {
        return '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Partnerlink niet beschikbaar</title><style>' . self::styles() . '</style></head><body><main class="partner-page"><section class="panel"><h1>Partnerlink niet beschikbaar</h1><p>' . self::e($message !== '' ? $message : 'Controleer de link of neem contact met ons op.') . '</p></section></main></body></html>';
    }

    private static function service(QuoteRepositoryInterface $repository): PartnerConfirmationService
    {
        return new PartnerConfirmationService(
            $repository,
            new QuoteTimelineService($repository),
            new PartnerConfirmationTokenService()
        );
    }

    private static function actionForm(string $token, string $tokenId, string $action, string $label, string $messageLabel = '', bool $primary = false, bool $requiredMessage = false): string
    {
        $html = '<form method="post" action="' . self::e(self::adminPostUrl()) . '" class="action-form">';
        $html .= self::nonceField('sbdp_partner_confirmation_' . $tokenId);
        $html .= '<input type="hidden" name="action" value="sbdp_partner_confirmation">';
        $html .= '<input type="hidden" name="partner_action" value="' . self::e($action) . '">';
        $html .= '<input type="hidden" name="partner_token" value="' . self::e($token) . '">';
        if ($messageLabel !== '') {
            $html .= '<label>' . self::e($messageLabel) . '<textarea name="message" rows="3"' . ($requiredMessage ? ' required' : '') . '></textarea></label>';
        }
        $html .= '<button class="' . ($primary ? 'primary' : 'secondary') . '" type="submit">' . self::e($label) . '</button></form>';
        return $html;
    }

    private static function fact(string $label, string $value): void
    {
        echo '<div><dt>' . self::e($label) . '</dt><dd>' . self::e($value !== '' ? $value : 'In overleg') . '</dd></div>';
    }

    /**
     * @param array<string,mixed> $line
     */
    private static function lineTime(array $line): string
    {
        $start = trim((string) (($line['start_time'] ?? '') ?: ($line['proposed_start_time'] ?? '')));
        $end = trim((string) (($line['end_time'] ?? '') ?: ($line['proposed_end_time'] ?? '')));
        return $start !== '' && $end !== '' ? $start . ' - ' . $end : ($start !== '' ? $start : 'In overleg');
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'supplier_booking_confirmed' => 'Bevestigd',
            'supplier_unavailable', 'supplier_declined' => 'Niet beschikbaar',
            'supplier_alternative_proposed' => 'Alternatief voorgesteld',
            'supplier_option_requested' => 'Optie aangevraagd',
            default => 'Bevestiging gevraagd',
        };
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
        $url = self::addQueryArg(array_merge(array('ddb_partner_confirmation' => $token), $args), function_exists('home_url') ? (string) home_url('/') : '/');
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

    private static function addQueryArg(array $args, string $url): string
    {
        if (function_exists('add_query_arg')) {
            return (string) add_query_arg($args, $url);
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function styles(): string
    {
        return 'body{margin:0;background:#f7f4ef;color:#211f1c;font-family:system-ui,-apple-system,Segoe UI,sans-serif;line-height:1.5}.partner-page{max-width:760px;margin:0 auto;padding:24px}.hero{padding:28px 0 18px}.hero p,.hero span{margin:0;color:#6f665d}.hero h1{margin:6px 0 8px;font-size:clamp(30px,5vw,46px);line-height:1.05}.panel,.notice{background:#fff;border:1px solid #ddd5cc;border-radius:8px;padding:16px;margin-top:14px}.panel h2{margin:0 0 10px}.facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:0}.facts div{border:1px solid #eee7df;border-radius:8px;padding:10px}.facts dt{font-size:13px;color:#6f665d}.facts dd{margin:2px 0 0;font-weight:700}.actions{display:grid;gap:12px}.action-form{display:grid;gap:8px;padding-top:10px;border-top:1px solid #eee7df}.action-form label{display:grid;gap:6px}.action-form textarea{width:100%;box-sizing:border-box;border:1px solid #c8bfb5;border-radius:6px;padding:10px;font:inherit}button{width:max-content;max-width:100%;border:1px solid #3b342d;border-radius:6px;padding:10px 14px;font-weight:700;cursor:pointer}button.primary{background:#3b342d;color:#fff}button.secondary{background:#fff;color:#3b342d}.notice.is-success{border-color:#6aa56a}.notice.is-error{border-color:#c65f52}@media(max-width:640px){.partner-page{padding:16px}button{width:100%}}';
    }
}
