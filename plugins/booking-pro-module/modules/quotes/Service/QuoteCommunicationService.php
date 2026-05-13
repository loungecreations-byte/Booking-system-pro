<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\PublicProposalController;
use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteCommunicationService
{
    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function generateProposalDraft(int $quoteId, ?int $actorId = null): array
    {
        $context = $this->buildContext($quoteId);
        $quote = $context['quote'];
        $request = $context['request'];
        $version = $context['version'];
        $lines = $context['lines'];

        $recipientName = trim((string) ($context['requester']['name'] ?? ''));
        $greetingName = $recipientName !== '' ? $recipientName : $this->t('klant');
        $proposalTitle = trim((string) ($version['proposal_title'] ?? ''));
        $dateLabel = trim((string) ($request['preferred_date'] ?? ''));
        $groupSize = (int) ($request['group_size'] ?? 0);
        $lineLabels = $this->buildProposalLineLabels($lines);

        $draft = array(
            'subject' => trim(sprintf(
                '%s [%s]',
                $proposalTitle !== '' ? $proposalTitle : $this->t('Voorstel Dagje Den Bosch'),
                (string) ($quote['quote_reference'] ?? '')
            )),
            'body'    => $this->buildProposalDraftBody(
                $greetingName,
                (string) ($quote['quote_reference'] ?? ''),
                $proposalTitle,
                $dateLabel,
                $groupSize,
                $lineLabels,
                $version,
                $lines
            ),
            'source'  => 'template',
        );

        $draft = (array) apply_filters('bsp/quotes/ai/draft_proposal_email', $draft, $context);

        $message = $this->storeDraft((int) $quote['id'], 'proposal', array(
            'quote_version_id'    => (int) ($version['id'] ?? 0) ?: null,
            'direction'           => 'outbound',
            'message_type'        => 'proposal',
            'channel'             => 'email',
            'status'              => 'draft',
            'subject'             => trim((string) ($draft['subject'] ?? '')),
            'body'                => trim((string) ($draft['body'] ?? '')),
            'to_name'             => $recipientName,
            'to_email'            => trim((string) ($context['requester']['email'] ?? '')),
            'thread_token'        => (string) ($quote['quote_reference'] ?? ''),
            'created_by'          => $actorId,
        ));

        $this->events->log(
            'quote_message_draft_generated',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            (int) $quote['id'],
            isset($version['id']) ? (int) $version['id'] : null,
            $actorId,
            'Voorstelmail draft gegenereerd.',
            array(
                'message_id'   => $message['id'] ?? null,
                'message_type' => 'proposal',
                'source'       => (string) ($draft['source'] ?? 'template'),
            )
        );

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function generateResponseDraft(int $quoteId, ?int $messageId = null, ?int $actorId = null): array
    {
        $context = $this->buildContext($quoteId);
        $quote = $context['quote'];
        $version = $context['version'];
        $inboundMessage = $this->resolveInboundMessage($context['messages'], $messageId);
        if ($inboundMessage === null) {
            throw new InvalidArgumentException($this->t('Er is nog geen klantreply beschikbaar om een antwoord op te baseren.'));
        }

        $draft = array(
            'subject' => $this->buildResponseSubject((string) ($inboundMessage['subject'] ?? ''), (string) ($quote['quote_reference'] ?? '')),
            'body'    => $this->buildResponseDraftBody($context, $inboundMessage),
            'source'  => 'template',
        );

        $draft = (array) apply_filters('bsp/quotes/ai/draft_response', $draft, array(
            'quote'           => $quote,
            'request'         => $context['request'],
            'version'         => $version,
            'lines'           => $context['lines'],
            'assumptions'     => $context['assumptions'],
            'messages'        => $context['messages'],
            'inbound_message' => $inboundMessage,
        ));

        $message = $this->storeDraft((int) $quote['id'], 'reply', array(
            'quote_version_id'       => (int) ($version['id'] ?? 0) ?: null,
            'direction'              => 'outbound',
            'message_type'           => 'reply',
            'channel'                => 'email',
            'status'                 => 'draft',
            'subject'                => trim((string) ($draft['subject'] ?? '')),
            'body'                   => trim((string) ($draft['body'] ?? '')),
            'to_name'                => trim((string) ($inboundMessage['from_name'] ?? '')),
            'to_email'               => trim((string) ($inboundMessage['from_email'] ?? '')),
            'in_reply_to_message_id' => trim((string) ($inboundMessage['provider_message_id'] ?? '')),
            'references_json'        => $this->buildReplyReferences($inboundMessage),
            'thread_token'           => (string) ($quote['quote_reference'] ?? ''),
            'created_by'             => $actorId,
        ));

        $this->events->log(
            'quote_message_draft_generated',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            (int) $quote['id'],
            isset($version['id']) ? (int) $version['id'] : null,
            $actorId,
            'Antwoorddraft gegenereerd.',
            array(
                'message_id'        => $message['id'] ?? null,
                'message_type'      => 'reply',
                'source'            => (string) ($draft['source'] ?? 'template'),
                'inbound_message_id'=> $inboundMessage['id'] ?? null,
            )
        );

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function summarizeInboundMessage(int $quoteId, int $messageId, ?int $actorId = null): array
    {
        $context = $this->buildContext($quoteId);
        $quote = $context['quote'];
        $version = $context['version'];
        $message = $this->repository->findQuoteMessage($messageId);
        if ($message === null || (int) ($message['quote_id'] ?? 0) !== $quoteId) {
            throw new InvalidArgumentException($this->t('Bericht niet gevonden voor deze quote.'));
        }
        if ((string) ($message['direction'] ?? '') !== 'inbound') {
            throw new InvalidArgumentException($this->t('Alleen inbound klantberichten kunnen worden samengevat.'));
        }

        $summary = array(
            'summary' => $this->buildInboundSummary($message),
            'source'  => 'template',
        );

        $summary = (array) apply_filters('bsp/quotes/ai/summarize_reply', $summary, array(
            'quote'          => $quote,
            'request'        => $context['request'],
            'version'        => $version,
            'message'        => $message,
            'all_messages'   => $context['messages'],
            'assumptions'    => $context['assumptions'],
        ));

        $updated = $this->repository->updateQuoteMessage($messageId, array(
            'body_summary' => trim((string) ($summary['summary'] ?? '')),
        ));

        $this->events->log(
            'quote_inbound_message_summarized',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            (int) $quote['id'],
            isset($version['id']) ? (int) $version['id'] : null,
            $actorId,
            'Inbound klantreply samengevat.',
            array(
                'message_id' => $messageId,
                'source'     => (string) ($summary['source'] ?? 'template'),
            )
        );

        return $updated;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendEmail(int $quoteId, array $payload, ?int $actorId = null): array
    {
        $context = $this->buildContext($quoteId);
        $quote = $context['quote'];
        $version = $context['version'];
        $messageType = $this->normalizeMessageType($payload['message_type'] ?? 'proposal');
        $toEmail = trim((string) ($payload['to_email'] ?? ''));
        $subject = trim((string) ($payload['subject'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        $toName = trim((string) ($payload['to_name'] ?? ''));
        $draftId = (int) ($payload['draft_id'] ?? 0);

        if (! filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException($this->t('Er is een geldig ontvanger-e-mailadres nodig.'));
        }
        if ($subject === '' || $body === '') {
            throw new InvalidArgumentException($this->t('Onderwerp en berichttekst zijn verplicht voordat e-mail kan worden verstuurd.'));
        }

        if ($messageType === 'proposal') {
            $this->assertProposalSendAllowed($quoteId, $quote);
        } elseif (! $this->hasSentProposal($context['messages'])) {
            throw new InvalidArgumentException($this->t('Een reply kan pas worden verstuurd nadat eerst een voorstelmail is verzonden.'));
        }

        $fromEmail = $this->resolveFromEmail();
        $fromName = $this->resolveFromName();
        $providerMessageId = $this->buildProviderMessageId($quoteId, (int) ($version['id'] ?? 0), $messageType);
        $replyReference = $this->resolveReplyReference($context['messages'], $payload['reply_to_message_id'] ?? null);
        $references = $this->buildReplyReferenceChain($replyReference);
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . $fromName . ' <' . $fromEmail . '>',
            'Message-ID: <' . $providerMessageId . '>',
            'X-BSP-Quote-Reference: ' . (string) ($quote['quote_reference'] ?? ''),
        );

        if ($replyReference !== '') {
            $headers[] = 'In-Reply-To: <' . $replyReference . '>';
            if ($references !== array()) {
                $headers[] = 'References: ' . implode(' ', array_map(static fn (string $item): string => '<' . $item . '>', $references));
            }
        }

        $sent = function_exists('wp_mail')
            ? (bool) wp_mail($toEmail, $subject, $body, $headers)
            : false;
        if (! $sent) {
            throw new InvalidArgumentException($this->t('De voorstelmail kon niet worden verstuurd.'));
        }

        $draftMessage = $this->resolveDraftForSend($quoteId, $draftId, $messageType);
        $sendPayload = array(
            'quote_id'               => (int) $quote['id'],
            'quote_version_id'       => (int) ($version['id'] ?? 0) ?: null,
            'direction'              => 'outbound',
            'message_type'           => $messageType,
            'channel'                => 'email',
            'status'                 => 'sent',
            'subject'                => $subject,
            'body'                   => $body,
            'from_name'              => $fromName,
            'from_email'             => $fromEmail,
            'to_name'                => $toName,
            'to_email'               => $toEmail,
            'provider_message_id'    => $providerMessageId,
            'in_reply_to_message_id' => $replyReference !== '' ? $replyReference : null,
            'references_json'        => $references,
            'thread_token'           => (string) ($quote['quote_reference'] ?? ''),
            'sent_at'                => $this->now(),
            'created_by'             => $actorId,
        );
        $message = $draftMessage !== null
            ? $this->repository->updateQuoteMessage((int) $draftMessage['id'], $sendPayload)
            : $this->repository->createQuoteMessage($sendPayload);

        $this->events->log(
            'quote_message_sent',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            (int) $quote['id'],
            isset($version['id']) ? (int) $version['id'] : null,
            $actorId,
            $messageType === 'proposal' ? 'Voorstelmail verstuurd.' : 'Quote-reply verstuurd.',
            array(
                'message_id'         => $message['id'] ?? null,
                'message_type'       => $messageType,
                'provider_message_id'=> $providerMessageId,
                'to_email'           => $toEmail,
            )
        );

        if ($messageType === 'proposal' && (string) ($quote['send_status'] ?? 'not_ready') === 'ready_to_send') {
            $sendService = new QuoteSendService($this->repository, $this->events);
            $sendService->markSentManual(
                $quoteId,
                'email',
                sprintf('Voorstelmail verstuurd naar %s', $toEmail),
                $actorId
            );
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function ingestInboundMessage(array $payload, ?int $actorId = null): array
    {
        $normalized = $this->normalizeInboundPayload($payload);
        if ($normalized['from_email'] === '' || ! filter_var($normalized['from_email'], FILTER_VALIDATE_EMAIL)) {
            $this->recordInboundFailure($normalized, 'invalid_sender', $payload, null, $actorId);
            throw new InvalidArgumentException($this->t('Inbound reply vereist een geldig afzender-e-mailadres.'));
        }
        if ($normalized['subject'] === '' && $normalized['body'] === '') {
            $this->recordInboundFailure($normalized, 'missing_content', $payload, null, $actorId);
            throw new InvalidArgumentException($this->t('Inbound reply vereist onderwerp of berichttekst.'));
        }

        if ($normalized['provider_message_id'] !== '') {
            $existing = $this->repository->findQuoteMessageByProviderMessageId($normalized['provider_message_id']);
            if ($existing !== null) {
                return $existing;
            }
        }

        $matched = $this->matchInboundQuote($normalized);
        if ($matched === null) {
            $this->recordInboundFailure($normalized, 'unmatched_quote', $payload, null, $actorId);
            throw new InvalidArgumentException($this->t('Inbound reply kon niet aan een quote worden gekoppeld via message-id, references of quote-token.'));
        }

        $message = $this->repository->createQuoteMessage(array(
            'quote_id'               => (int) ($matched['quote']['id'] ?? 0),
            'quote_version_id'       => isset($matched['quote_version_id']) ? (int) $matched['quote_version_id'] : null,
            'direction'              => 'inbound',
            'message_type'           => 'customer_reply',
            'channel'                => 'email',
            'status'                 => 'received',
            'subject'                => $normalized['subject'],
            'body'                   => $normalized['body'],
            'from_name'              => $normalized['from_name'],
            'from_email'             => $normalized['from_email'],
            'to_email'               => $normalized['to_email'],
            'provider_message_id'    => $normalized['provider_message_id'] !== '' ? $normalized['provider_message_id'] : null,
            'in_reply_to_message_id' => $normalized['in_reply_to'] !== '' ? $normalized['in_reply_to'] : null,
            'references_json'        => $normalized['references'],
            'thread_token'           => (string) ($matched['quote']['quote_reference'] ?? ''),
            'received_at'            => $normalized['received_at'] !== '' ? $normalized['received_at'] : $this->now(),
            'created_by'             => $actorId,
        ));

        $this->events->log(
            'quote_message_received',
            isset($matched['quote']['quote_request_id']) ? (int) $matched['quote']['quote_request_id'] : null,
            (int) ($matched['quote']['id'] ?? 0),
            isset($matched['quote_version_id']) ? (int) $matched['quote_version_id'] : null,
            $actorId,
            'Inbound klantreply gekoppeld aan quote.',
            array(
                'message_id'         => $message['id'] ?? null,
                'provider_message_id'=> $normalized['provider_message_id'],
                'matched_by'         => (string) ($matched['matched_by'] ?? 'unknown'),
                'from_email'         => $normalized['from_email'],
            )
        );

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveInboundFailure(int $failureId, int $quoteId, ?int $actorId = null): array
    {
        $failure = $this->repository->findQuoteMessageFailure($failureId);
        if ($failure === null) {
            throw new InvalidArgumentException($this->t('Inbound failure niet gevonden.'));
        }

        if ((string) ($failure['status'] ?? 'open') !== 'open') {
            throw new InvalidArgumentException($this->t('Inbound failure is al verwerkt.'));
        }

        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException($this->t('Quote niet gevonden.'));
        }

        $payload = isset($failure['payload_json']) && is_array($failure['payload_json'])
            ? $failure['payload_json']
            : array();

        if ($payload === array()) {
            $payload = array(
                'message_id'  => (string) ($failure['provider_message_id'] ?? ''),
                'in_reply_to' => (string) ($failure['in_reply_to_message_id'] ?? ''),
                'references'  => $failure['references_json'] ?? array(),
                'subject'     => (string) ($failure['subject'] ?? ''),
                'body'        => (string) ($failure['body'] ?? ''),
                'from_name'   => (string) ($failure['from_name'] ?? ''),
                'from_email'  => (string) ($failure['from_email'] ?? ''),
                'to_email'    => (string) ($failure['to_email'] ?? ''),
            );
        }

        $normalized = $this->normalizeInboundPayload($payload);
        $providerMessageId = trim((string) ($normalized['provider_message_id'] ?? ''));
        if ($providerMessageId !== '') {
            $existingMessage = $this->repository->findQuoteMessageByProviderMessageId($providerMessageId);
            if ($existingMessage !== null) {
                $existingQuoteId = (int) ($existingMessage['quote_id'] ?? 0);
                if ($existingQuoteId !== $quoteId) {
                    throw new InvalidArgumentException($this->t('Inbound reply is al gekoppeld aan een andere quote en kan niet opnieuw worden verwerkt.'));
                }

                $resolvedFailure = $this->repository->updateQuoteMessageFailure($failureId, array(
                    'status'          => 'resolved',
                    'linked_quote_id' => $quoteId,
                    'resolved_at'     => $this->now(),
                    'resolved_by'     => $actorId,
                ));

                $this->events->log(
                    'quote_message_failure_resolved',
                    isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
                    $quoteId,
                    isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
                    $actorId,
                    'Inbound klantreply gekoppeld via bestaand bericht in dezelfde thread.',
                    array(
                        'failure_id' => $failureId,
                        'message_id' => $existingMessage['id'] ?? null,
                    )
                );

                return array(
                    'failure' => $resolvedFailure,
                    'message' => $existingMessage,
                );
            }
        }

        $message = $this->repository->createQuoteMessage(array(
            'quote_id'               => $quoteId,
            'quote_version_id'       => isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
            'direction'              => 'inbound',
            'message_type'           => 'customer_reply',
            'channel'                => 'email',
            'status'                 => 'received',
            'subject'                => $normalized['subject'],
            'body'                   => $normalized['body'],
            'from_name'              => $normalized['from_name'],
            'from_email'             => $normalized['from_email'],
            'to_email'               => $normalized['to_email'],
            'provider_message_id'    => $normalized['provider_message_id'] !== '' ? $normalized['provider_message_id'] : null,
            'in_reply_to_message_id' => $normalized['in_reply_to'] !== '' ? $normalized['in_reply_to'] : null,
            'references_json'        => $normalized['references'],
            'thread_token'           => (string) ($quote['quote_reference'] ?? ''),
            'received_at'            => $normalized['received_at'] !== '' ? $normalized['received_at'] : $this->now(),
            'created_by'             => $actorId,
        ));

        $resolvedFailure = $this->repository->updateQuoteMessageFailure($failureId, array(
            'status'         => 'resolved',
            'linked_quote_id'=> $quoteId,
            'resolved_at'    => $this->now(),
            'resolved_by'    => $actorId,
        ));

        $this->events->log(
            'quote_message_failure_resolved',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
            $actorId,
            'Inbound klantreply handmatig gekoppeld aan quote.',
            array(
                'failure_id' => $failureId,
                'message_id' => $message['id'] ?? null,
            )
        );

        return array(
            'failure' => $resolvedFailure,
            'message' => $message,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(int $quoteId): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException($this->t('Quote not found.'));
        }

        $request = isset($quote['quote_request_id']) ? $this->repository->findQuoteRequest((int) $quote['quote_request_id']) : null;
        $version = isset($quote['current_version_id']) ? $this->repository->findQuoteVersion((int) $quote['current_version_id']) : null;
        if ($version === null) {
            throw new InvalidArgumentException($this->t('Quote heeft nog geen actieve versie.'));
        }

        return array(
            'quote'       => $quote,
            'request'     => is_array($request) ? $request : array(),
            'requester'   => $this->extractRequesterContext(is_array($request) ? $request : array()),
            'version'     => $version,
            'lines'       => $this->repository->listQuoteLines((int) $version['id']),
            'assumptions' => $this->repository->listQuoteAssumptions($quoteId),
            'messages'    => $this->repository->listQuoteMessages($quoteId),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<string, mixed>|null
     */
    private function resolveInboundMessage(array $messages, ?int $messageId): ?array
    {
        if ($messageId !== null && $messageId > 0) {
            foreach ($messages as $message) {
                if ((int) ($message['id'] ?? 0) === $messageId && (string) ($message['direction'] ?? '') === 'inbound') {
                    return $message;
                }
            }

            return null;
        }

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if ((string) ($messages[$index]['direction'] ?? '') === 'inbound') {
                return $messages[$index];
            }
        }

        return null;
    }

    private function assertProposalSendAllowed(int $quoteId, array $quote): void
    {
        if ((string) ($quote['review_status'] ?? 'not_started') !== 'approved') {
            throw new InvalidArgumentException($this->t('Een voorstelmail kan pas worden verstuurd na goedgekeurde review.'));
        }

        if ((string) ($quote['send_status'] ?? 'not_ready') !== 'ready_to_send') {
            throw new InvalidArgumentException($this->t('Deze quote is nog niet verzendklaar voor een voorstelmail.'));
        }

        (new QuoteSendReadinessValidator($this->repository))->assertReadyToSend($quoteId);

        foreach ($this->repository->listQuoteAssumptions($quoteId) as $assumption) {
            if ((string) ($assumption['status'] ?? 'open') !== 'open') {
                continue;
            }

            if (! empty($assumption['blocks_send'])) {
                throw new InvalidArgumentException($this->t('Open send-blockers moeten eerst expliciet worden opgelost voordat een voorstelmail wordt verstuurd.'));
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function hasSentProposal(array $messages): bool
    {
        foreach ($messages as $message) {
            if ((string) ($message['direction'] ?? '') !== 'outbound') {
                continue;
            }
            if ((string) ($message['message_type'] ?? '') !== 'proposal') {
                continue;
            }
            if ((string) ($message['status'] ?? '') === 'sent') {
                return true;
            }
        }

        return false;
    }

    private function normalizeMessageType($value): string
    {
        $messageType = trim((string) $value);
        return in_array($messageType, array('proposal', 'reply'), true) ? $messageType : 'proposal';
    }

    private function buildProviderMessageId(int $quoteId, int $versionId, string $messageType): string
    {
        return sprintf(
            'quote-%d-v%d-%s-%s@local.quote',
            $quoteId,
            max(1, $versionId),
            $messageType,
            uniqid('', true)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function resolveReplyReference(array $messages, $requestedReference): string
    {
        $reference = trim((string) $requestedReference);
        if ($reference !== '') {
            return $reference;
        }

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $messageId = trim((string) ($messages[$index]['provider_message_id'] ?? ''));
            if ($messageId !== '') {
                return $messageId;
            }
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function buildReplyReferenceChain(string $replyReference): array
    {
        if ($replyReference === '') {
            return array();
        }

        return array($replyReference);
    }

    /**
     * @param array<string, mixed> $inboundMessage
     * @return array<int, string>
     */
    private function buildReplyReferences(array $inboundMessage): array
    {
        $references = isset($inboundMessage['references_json']) && is_array($inboundMessage['references_json'])
            ? array_values(array_filter(array_map('strval', $inboundMessage['references_json'])))
            : array();
        $providerMessageId = trim((string) ($inboundMessage['provider_message_id'] ?? ''));
        if ($providerMessageId !== '') {
            $references[] = $providerMessageId;
        }

        return array_values(array_unique(array_filter($references)));
    }

    /**
     * @param array<string, mixed> $message
     */
    private function buildInboundSummary(array $message): string
    {
        $body = trim(preg_replace('/\s+/', ' ', (string) ($message['body'] ?? '')) ?? '');
        if ($body === '') {
            return $this->t('Klantreply ontvangen zonder leesbare inhoud.');
        }

        $snippet = function_exists('mb_substr')
            ? mb_substr($body, 0, 240)
            : substr($body, 0, 240);

        return sprintf(
            $this->t('Klant vraagt of meldt: %s'),
            rtrim($snippet, " \t\n\r\0\x0B") . (strlen($body) > strlen($snippet) ? '…' : '')
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $inboundMessage
     */
    private function buildResponseDraftBody(array $context, array $inboundMessage): string
    {
        $name = trim((string) ($inboundMessage['from_name'] ?? $context['requester']['name'] ?? ''));
        $greetingName = $name !== '' ? $name : $this->t('klant');
        $summary = trim((string) ($inboundMessage['body_summary'] ?? ''));
        $quoteReference = (string) ($context['quote']['quote_reference'] ?? '');
        $caveats = $this->buildCommercialCaveatLines($context['version'], $context['lines']);

        $lines = array(
            sprintf($this->t('Hallo %s,'), $greetingName),
            '',
            sprintf($this->t('Bedankt voor uw reactie op quote %s.'), $quoteReference),
        );

        if ($summary !== '') {
            $lines[] = sprintf($this->t('We hebben uw bericht als volgt samengevat: %s'), $summary);
            $lines[] = '';
        }

        $lines[] = $this->t('We pakken uw vraag intern op en komen zo snel mogelijk bij u terug met een concreet antwoord of bijgewerkt voorstel.');

        foreach ($caveats as $caveat) {
            $lines[] = $caveat;
        }

        $lines[] = '';
        $lines[] = $this->t('Met vriendelijke groet,');
        $lines[] = $this->resolveFromName();

        return implode("\n", $lines);
    }

    /**
     * @param array<int, string> $lineLabels
     * @param array<string, mixed> $version
     * @param array<int, array<string, mixed>> $lines
     */
    private function buildProposalDraftBody(
        string $greetingName,
        string $quoteReference,
        string $proposalTitle,
        string $dateLabel,
        int $groupSize,
        array $lineLabels,
        array $version,
        array $lines
    ): string {
        $bodyLines = array(
            sprintf($this->t('Hallo %s,'), $greetingName),
            '',
            $proposalTitle !== ''
                ? sprintf($this->t('Hierbij ontvangt u ons voorstel: %s.'), $proposalTitle)
                : $this->t('Hierbij ontvangt u ons actuele voorstel.'),
        );

        if ($dateLabel !== '') {
            $bodyLines[] = sprintf($this->t('Voorkeursdatum in deze versie: %s.'), $dateLabel);
        }
        if ($groupSize > 0) {
            $bodyLines[] = sprintf($this->t('Uitgangspunt groepsgrootte: %d personen.'), $groupSize);
        }
        if ($lineLabels !== array()) {
            $bodyLines[] = $this->t('Deze versie bevat onder meer:');
            foreach (array_slice($lineLabels, 0, 6) as $lineLabel) {
                $bodyLines[] = '- ' . $lineLabel;
            }
        }

        $proposalUrl = PublicProposalController::publicUrl(
            (new PublicQuoteProposalTokenService())->create(
                (int) ($context['quote']['id'] ?? 0),
                (int) ($version['id'] ?? 0),
                $quoteReference
            )
        );
        if ($proposalUrl !== '') {
            $bodyLines[] = '';
            $bodyLines[] = sprintf($this->t('Bekijk en beantwoord uw voorstel: %s'), $proposalUrl);
        }

        foreach ($this->buildCommercialCaveatLines($version, $lines) as $caveat) {
            $bodyLines[] = $caveat;
        }

        $bodyLines[] = '';
        $bodyLines[] = sprintf($this->t('Referentie voor uw reactie: %s'), $quoteReference);
        $bodyLines[] = $this->t('Laat het gerust weten als u akkoord wilt geven of eerst nog iets wilt aanpassen.');
        $bodyLines[] = '';
        $bodyLines[] = $this->t('Met vriendelijke groet,');
        $bodyLines[] = $this->resolveFromName();

        return implode("\n", $bodyLines);
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, string>
     */
    private function buildProposalLineLabels(array $lines): array
    {
        $labels = array();
        foreach ($lines as $line) {
            $title = trim((string) ($line['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $bits = array($title);
            $timing = $this->buildLineTimingLabel($line);
            if ($timing !== '') {
                $bits[] = $timing;
            }

            $optionLabels = isset($line['selected_option_labels_json']) && is_array($line['selected_option_labels_json'])
                ? array_values(array_filter(array_map(static fn ($value): string => trim((string) $value), $line['selected_option_labels_json'])))
                : array();
            if ($optionLabels !== array()) {
                $bits[] = implode(' | ', $optionLabels);
            }

            $participants = max(0, (int) ($line['participants'] ?? 0));
            if ($participants > 0) {
                $bits[] = sprintf($this->t('%d deelnemers'), $participants);
            }

            $labels[] = implode(' - ', $bits);
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function buildLineTimingLabel(array $line): string
    {
        $date = trim((string) ($line['service_date'] ?? ''));
        $startTime = trim((string) ($line['proposed_start_time'] ?? ($line['start_time'] ?? '')));
        $endTime = trim((string) ($line['proposed_end_time'] ?? ($line['end_time'] ?? '')));
        $slotLabel = trim((string) ($line['validated_slot_label'] ?? ''));

        $timeLabel = '';
        if ($startTime !== '' && $endTime !== '') {
            $timeLabel = sprintf('%s-%s', $startTime, $endTime);
        } elseif ($startTime !== '') {
            $timeLabel = sprintf($this->t('start %s'), $startTime);
        } elseif ($endTime !== '') {
            $timeLabel = sprintf($this->t('tot %s'), $endTime);
        } elseif ($slotLabel !== '') {
            $timeLabel = $slotLabel;
        }

        if ($date !== '' && $timeLabel !== '') {
            return sprintf('%s %s', $date, $timeLabel);
        }

        return $timeLabel !== '' ? $timeLabel : $date;
    }

    /**
     * @param array<string, mixed> $version
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, string>
     */
    private function buildCommercialCaveatLines(array $version, array $lines): array
    {
        $projectedPricing = 0;
        $projectedAvailability = 0;

        foreach ($lines as $line) {
            if ((string) ($line['pricing_confidence'] ?? 'unknown') !== 'execution_verified') {
                $projectedPricing++;
            }
            if ((string) ($line['availability_confidence'] ?? 'unknown') !== 'confirmed') {
                $projectedAvailability++;
            }
        }

        $caveats = array();
        if ((string) ($version['pricing_confidence'] ?? 'unknown') !== 'execution_verified' || $projectedPricing > 0) {
            $caveats[] = $this->t('Prijs in deze versie blijft onder voorbehoud totdat deze definitief is bevestigd.');
        }
        if ((string) ($version['availability_confidence'] ?? 'unknown') !== 'confirmed' || $projectedAvailability > 0) {
            $caveats[] = $this->t('Beschikbaarheid blijft expliciet onder voorbehoud totdat bevestiging is ontvangen.');
        }

        return $caveats;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeInboundPayload(array $payload): array
    {
        $headers = $this->normalizeInboundHeaders($payload);
        $from = $this->parseMailbox($this->firstNonEmptyString(
            $payload['from'] ?? null,
            $payload['sender'] ?? null,
            $payload['from_email'] ?? null,
            $payload['From'] ?? null,
            $payload['FromFull']['Email'] ?? null
        ));
        $to = $this->parseMailbox($this->firstNonEmptyString(
            $payload['to'] ?? null,
            $payload['recipient'] ?? null,
            $payload['to_email'] ?? null,
            $payload['To'] ?? null,
            $payload['OriginalRecipient'] ?? null
        ));

        $references = $this->normalizeReferences(
            $payload['references'] ?? $payload['References'] ?? ($headers['references'] ?? array())
        );

        $providerMessageId = $this->extractMessageId($this->firstNonEmptyString(
            $payload['message_id'] ?? null,
            $payload['provider_message_id'] ?? null,
            $payload['Message-Id'] ?? null,
            $payload['Message-ID'] ?? null,
            $payload['MessageID'] ?? null,
            $headers['message-id'] ?? null
        ));

        $inReplyTo = $this->extractMessageId($this->firstNonEmptyString(
            $payload['in_reply_to'] ?? null,
            $payload['In-Reply-To'] ?? null,
            $payload['stripped-signature'] ?? null,
            $headers['in-reply-to'] ?? null
        ));

        $subject = trim((string) $this->firstNonEmptyString(
            $payload['subject'] ?? null,
            $payload['Subject'] ?? null,
            $headers['subject'] ?? null
        ));

        $body = trim((string) $this->firstNonEmptyString(
            $payload['body'] ?? null,
            $payload['body-plain'] ?? null,
            $payload['stripped-text'] ?? null,
            $payload['TextBody'] ?? null,
            $payload['text'] ?? null,
            $payload['html'] ?? null,
            $payload['HtmlBody'] ?? null
        ));

        return array(
            'provider_message_id' => $providerMessageId,
            'in_reply_to'         => $inReplyTo,
            'references'          => $references,
            'subject'             => $subject,
            'body'                => $body,
            'from_name'           => trim((string) $this->firstNonEmptyString(
                $payload['from_name'] ?? null,
                $payload['FromFull']['Name'] ?? null,
                $from['name'] ?? ''
            )),
            'from_email'          => trim((string) $this->firstNonEmptyString(
                $payload['from_email'] ?? null,
                $payload['FromFull']['Email'] ?? null,
                $from['email'] ?? ''
            )),
            'to_email'            => trim((string) $this->firstNonEmptyString(
                $payload['to_email'] ?? null,
                $to['email'] ?? ''
            )),
            'received_at'         => trim((string) $this->firstNonEmptyString(
                $payload['received_at'] ?? null,
                $payload['Date'] ?? null,
                $headers['date'] ?? null
            )),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function matchInboundQuote(array $payload): ?array
    {
        $candidates = array_filter(array_merge(
            $payload['in_reply_to'] !== '' ? array($payload['in_reply_to']) : array(),
            (array) $payload['references']
        ));

        foreach ($candidates as $candidate) {
            $matchedMessage = $this->repository->findQuoteMessageByProviderMessageId((string) $candidate);
            if ($matchedMessage === null) {
                continue;
            }

            $quote = $this->repository->findQuote((int) ($matchedMessage['quote_id'] ?? 0));
            if ($quote === null) {
                continue;
            }

            return array(
                'quote'            => $quote,
                'quote_version_id' => isset($matchedMessage['quote_version_id']) ? (int) $matchedMessage['quote_version_id'] : null,
                'matched_by'       => 'message_reference',
            );
        }

        $token = $this->extractQuoteToken($payload['subject'] . "\n" . $payload['body']);
        if ($token === '') {
            return null;
        }

        foreach ($this->repository->listQuotes() as $quote) {
            if ((string) ($quote['quote_reference'] ?? '') !== $token) {
                continue;
            }

            return array(
                'quote'            => $quote,
                'quote_version_id' => isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
                'matched_by'       => 'quote_token',
            );
        }

        return null;
    }

    private function extractQuoteToken(string $text): string
    {
        // Support legacy tokens (Q-YYYYMMDDHHMMSS) and current tokens (Q-YYYYMMDDHHMMSS-XXXXXXXX).
        if (preg_match('/\b(Q-\d{14}(?:-[A-Z0-9]{8})?)\b/i', $text, $matches) === 1) {
            return strtoupper((string) ($matches[1] ?? ''));
        }

        return '';
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function extractRequesterContext(array $request): array
    {
        $requester = isset($request['normalized_payload']['requester']) && is_array($request['normalized_payload']['requester'])
            ? $request['normalized_payload']['requester']
            : array();

        if (! isset($requester['name'])) {
            $requester['name'] = (string) ($request['requester_name'] ?? '');
        }
        if (! isset($requester['email'])) {
            $requester['email'] = (string) ($request['requester_email'] ?? '');
        }

        return $requester;
    }

    private function buildResponseSubject(string $subject, string $quoteReference): string
    {
        $subject = trim($subject);
        if ($subject === '') {
            return sprintf($this->t('Re: quote %s'), $quoteReference);
        }

        if (stripos($subject, 're:') === 0) {
            return $subject;
        }

        return 'Re: ' . $subject;
    }

    private function resolveFromEmail(): string
    {
        $email = function_exists('get_option') ? (string) get_option('admin_email', '') : '';
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'quotes@example.test';
    }

    private function resolveFromName(): string
    {
        if (function_exists('get_bloginfo')) {
            $name = trim((string) get_bloginfo('name'));
            if ($name !== '') {
                return $name;
            }
        }

        return 'Dagje Den Bosch';
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function storeDraft(int $quoteId, string $messageType, array $payload): array
    {
        $existingDraft = $this->findLatestDraft($quoteId, $messageType);

        if ($existingDraft !== null) {
            return $this->repository->updateQuoteMessage((int) $existingDraft['id'], $payload);
        }

        return $this->repository->createQuoteMessage(array_merge(array(
            'quote_id' => $quoteId,
        ), $payload));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLatestDraft(int $quoteId, string $messageType): ?array
    {
        $messages = $this->repository->listQuoteMessages($quoteId);
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            if ((string) ($message['status'] ?? '') !== 'draft') {
                continue;
            }
            if ((string) ($message['message_type'] ?? '') !== $messageType) {
                continue;
            }
            if ((string) ($message['direction'] ?? '') !== 'outbound') {
                continue;
            }

            return $message;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveDraftForSend(int $quoteId, int $draftId, string $messageType): ?array
    {
        if ($draftId > 0) {
            $draft = $this->repository->findQuoteMessage($draftId);
            if ($draft !== null
                && (int) ($draft['quote_id'] ?? 0) === $quoteId
                && (string) ($draft['status'] ?? '') === 'draft'
                && (string) ($draft['message_type'] ?? '') === $messageType
                && (string) ($draft['direction'] ?? '') === 'outbound') {
                return $draft;
            }
        }

        return $this->findLatestDraft($quoteId, $messageType);
    }

    private function t(string $text): string
    {
        return function_exists('__')
            ? (string) __($text, 'sbdp')
            : $text;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string|array<int, string>>
     */
    private function normalizeInboundHeaders(array $payload): array
    {
        $normalized = array();

        $headerCandidates = array(
            $payload['headers'] ?? null,
            $payload['Headers'] ?? null,
            $payload['message-headers'] ?? null,
        );

        foreach ($headerCandidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                foreach (preg_split("/\r\n|\n|\r/", trim($candidate)) ?: array() as $line) {
                    if (! is_string($line) || strpos($line, ':') === false) {
                        continue;
                    }
                    [$name, $value] = array_map('trim', explode(':', $line, 2));
                    if ($name === '') {
                        continue;
                    }
                    $normalized[strtolower($name)] = $value;
                }
            }

            if (is_array($candidate)) {
                foreach ($candidate as $key => $value) {
                    if (is_array($value) && isset($value['Name'], $value['Value'])) {
                        $normalized[strtolower((string) $value['Name'])] = (string) $value['Value'];
                        continue;
                    }

                    if (is_string($key)) {
                        $normalized[strtolower($key)] = is_scalar($value) ? trim((string) $value) : '';
                    }
                }
            }
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeReferences($value): array
    {
        $items = array();

        if (is_string($value)) {
            preg_match_all('/<?([^<>\s,]+@[^<>\s,]+)>?/', $value, $matches);
            if (! empty($matches[1]) && is_array($matches[1])) {
                $items = $matches[1];
            } else {
                $items = preg_split('/[\s,]+/', trim($value)) ?: array();
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $normalized = $this->extractMessageId((string) $item);
                if ($normalized !== '') {
                    $items[] = $normalized;
                }
            }
        }

        $items = array_values(array_unique(array_filter(array_map(
            fn ($item): string => $this->extractMessageId((string) $item),
            $items
        ))));

        return $items;
    }

    private function extractMessageId(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/<([^<>]+)>/', $value, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return trim($value, " \t\n\r\0\x0B<>");
    }

    /**
     * @return array{name:string,email:string}
     */
    private function parseMailbox(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return array('name' => '', 'email' => '');
        }

        if (preg_match('/^(.*)<([^<>]+)>$/', $raw, $matches) === 1) {
            return array(
                'name'  => trim(trim((string) ($matches[1] ?? '')), '" '),
                'email' => trim((string) ($matches[2] ?? '')),
            );
        }

        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return array('name' => '', 'email' => $raw);
        }

        return array('name' => $raw, 'email' => '');
    }

    private function firstNonEmptyString(...$values): string
    {
        foreach ($values as $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $string = trim((string) $value);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $rawPayload
     */
    private function recordInboundFailure(array $normalized, string $reason, array $rawPayload, ?string $guessedQuoteReference = null, ?int $actorId = null): array
    {
        $providerMessageId = trim((string) ($normalized['provider_message_id'] ?? ''));
        if ($providerMessageId !== '') {
            foreach ($this->repository->listQuoteMessageFailures() as $failure) {
                if ((string) ($failure['provider_message_id'] ?? '') === $providerMessageId) {
                    return $failure;
                }
            }
        }

        $reference = $guessedQuoteReference !== null && $guessedQuoteReference !== ''
            ? $guessedQuoteReference
            : $this->extractQuoteToken(((string) ($normalized['subject'] ?? '')) . "\n" . ((string) ($normalized['body'] ?? '')));

        $failure = $this->repository->createQuoteMessageFailure(array(
            'direction'              => 'inbound',
            'channel'                => 'email',
            'failure_reason'         => $reason,
            'subject'                => (string) ($normalized['subject'] ?? ''),
            'from_name'              => (string) ($normalized['from_name'] ?? ''),
            'from_email'             => (string) ($normalized['from_email'] ?? ''),
            'to_email'               => (string) ($normalized['to_email'] ?? ''),
            'provider_message_id'    => $providerMessageId !== '' ? $providerMessageId : null,
            'in_reply_to_message_id' => ($normalized['in_reply_to'] ?? '') !== '' ? (string) $normalized['in_reply_to'] : null,
            'references_json'        => $normalized['references'] ?? array(),
            'body'                   => (string) ($normalized['body'] ?? ''),
            'payload_json'           => $rawPayload,
            'guessed_quote_reference'=> $reference !== '' ? $reference : null,
            'status'                 => 'open',
        ));

        $this->events->log(
            'quote_message_ingest_failed',
            null,
            null,
            null,
            $actorId,
            'Inbound klantreply kon niet automatisch aan een quote worden gekoppeld.',
            array(
                'failure_id'             => $failure['id'] ?? null,
                'failure_reason'         => $reason,
                'provider_message_id'    => $providerMessageId,
                'guessed_quote_reference'=> $reference,
            )
        );

        return $failure;
    }
}
