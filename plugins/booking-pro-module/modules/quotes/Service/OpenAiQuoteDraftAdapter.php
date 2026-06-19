<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepository;

final class OpenAiQuoteDraftAdapter
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    private const STATUS_OPTION = 'bsp_quotes_openai_last_status';

    private const SYSTEM_PROMPT_TEMPLATE = <<<'PROMPT'
Je bent de digitale Sales Assistent van Dagje Den Bosch.

## REGELS — NOOIT OVERTREDEN
- Verzin GEEN activiteiten, prijzen of data die niet in de meegeleverde JSON staan.
- Als een waarde ontbreekt in de context, laat je het veld leeg of stel je een korte vervolgvraag.
- Schrijf altijd in foutloos Nederlands (tenzij de klant in het Engels schrijft).
- Toon: Brabants gastvrij, direct, geen overbodige formuleringen.
- Gebruik ALTIJD de quote_reference in het onderwerp: bijv. [Q-ABC123].

## TOEGESTANE MAIL-ADRESSEN
Antwoorden gaan altijd VAN een van deze adressen:
  - aanvragen@dagjedenbosch.nl  → voor offertes
  - info@dagjedenbosch.nl       → voor algemene vragen
  - inkoop@dagjedenbosch.nl     → voor leverancierscommunicatie

## TAAK
[TAAK]

## OUTPUT
Geef uitsluitend een JSON-object terug met deze twee velden:
  { "subject": "...", "body": "..." }
De body mag HTML-paragrafen bevatten (<p>, <strong>, <br>). Geen andere tags.
PROMPT;

    public function __construct()
    {
        add_filter('bsp/quotes/ai/draft_proposal_email', array($this, 'draftProposal'), 10, 2);
        add_filter('bsp/quotes/ai/draft_response', array($this, 'draftResponse'), 10, 2);
        add_filter('bsp/quotes/ai/summarize_reply', array($this, 'summarizeReply'), 10, 2);
        add_action('bsp/quotes/ai/inbound_received', array($this, 'onInboundReceived'), 10, 3);
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function draftProposal(array $draft, array $context): array
    {
        $result = $this->requestJson(
            'Schrijf een aantrekkelijke voorstelmail op basis van de offerte-context.',
            $context
        );

        if (! is_array($result)) {
            return $draft;
        }

        if (! isset($result['subject'], $result['body'])) {
            return $draft;
        }

        $draft['subject'] = trim((string) $result['subject']);
        $draft['body'] = trim((string) $result['body']);
        $draft['source'] = 'openai';

        return $draft;
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function draftResponse(array $draft, array $context): array
    {
        $result = $this->requestJson(
            'Schrijf een antwoord op de klantreply. Verwerk eventuele vragen.',
            $context
        );

        if (! is_array($result)) {
            return $draft;
        }

        if (! isset($result['subject'], $result['body'])) {
            return $draft;
        }

        $draft['subject'] = trim((string) $result['subject']);
        $draft['body'] = trim((string) $result['body']);
        $draft['source'] = 'openai';

        return $draft;
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function summarizeReply(array $draft, array $context): array
    {
        $result = $this->requestJson(
            'Geef als output alleen: { "summary": "max 3 zinnen samenvatting van de klantreply" }',
            $context
        );

        if (! is_array($result) || ! isset($result['summary'])) {
            return $draft;
        }

        $draft['summary'] = trim((string) $result['summary']);
        $draft['source'] = 'openai';

        return $draft;
    }

    public function onInboundReceived(int $quoteId, int $messageId, string $_toEmail): void
    {
        try {
            $repository = new QuoteRepository();
            $events = new QuoteEventLogger($repository);
            $service = new QuoteCommunicationService($repository, $events);
            $service->generateResponseDraft($quoteId, $messageId, null);
        } catch (\Throwable $throwable) {
            $this->logDebug('Failed to auto-draft inbound response: ' . $throwable->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function requestJson(string $task, array $context): ?array
    {
        $apiKey = trim((string) get_option('bsp_openai_api_key', ''));
        if ($apiKey === '') {
            $this->recordStatus('disabled', null, 'API key ontbreekt.');
            return null;
        }

        $model = trim((string) get_option('bsp_openai_model', 'gpt-4o'));
        if ($model === '') {
            $model = 'gpt-4o';
        }

        $body = array(
            'model'    => $model,
            'messages' => array(
                array(
                    'role'    => 'system',
                    'content' => str_replace('[TAAK]', $task, self::SYSTEM_PROMPT_TEMPLATE),
                ),
                array(
                    'role'    => 'user',
                    'content' => (string) wp_json_encode($context),
                ),
            ),
            'temperature' => 0.2,
        );

        $response = wp_remote_post(self::ENDPOINT, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            $this->recordStatus('error', null, 'OpenAI request failed before response.');
            $this->logDebug('OpenAI request failed.');
            return null;
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            $this->recordStatus('error', $statusCode, 'OpenAI request failed with status ' . $statusCode . '.');
            $this->logDebug('OpenAI request failed with status ' . $statusCode);
            return null;
        }

        $responseBody = wp_remote_retrieve_body($response);
        $decoded = json_decode($responseBody, true);
        if (! is_array($decoded)) {
            $this->recordStatus('error', 200, 'OpenAI response kon niet als JSON worden gelezen.');
            return null;
        }

        $content = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            $this->recordStatus('error', 200, 'OpenAI response bevatte geen content.');
            return null;
        }

        $json = json_decode($content, true);
        if (! is_array($json)) {
            $this->recordStatus('error', 200, 'OpenAI content was geen geldig JSON-object.');
            return null;
        }

        $this->recordStatus('ok', 200, 'OpenAI draft succesvol opgebouwd.');

        return $json;
    }

    private function recordStatus(string $state, ?int $httpStatus, string $message): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        update_option(self::STATUS_OPTION, array(
            'state'       => $state,
            'http_status' => $httpStatus,
            'message'     => $message,
            'model'       => (string) get_option('bsp_openai_model', 'gpt-4o'),
            'updated_at'  => function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
        ), false);
    }

    private function logDebug(string $message): void
    {
        if (! defined('WP_DEBUG') || ! WP_DEBUG || ! function_exists('error_log')) {
            return;
        }

        error_log('[BSP Quotes] ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}
