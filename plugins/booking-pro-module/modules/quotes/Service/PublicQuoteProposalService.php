<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\PublicProposalController;
use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class PublicQuoteProposalService
{
    private const VIEWABLE_STATUSES = ['sent', 'accepted', 'confirmed', 'operations_ready', 'revision_requested', 'declined'];
    private const APPROVED_VERSION_STATUSES = ['accepted', 'confirmed', 'operations_ready'];
    private const ACTIONABLE_STATUS = 'sent';

    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events,
        private PublicQuoteProposalTokenService $tokens
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveByToken(string $token): array
    {
        $verification = $this->tokens->verifyDetailed($token);
        if (empty($verification['ok'])) {
            if ((string) ($verification['reason'] ?? '') === 'expired') {
                throw new InvalidArgumentException('Deze voorstel-link is verlopen. Vraag een nieuwe link aan.');
            }
            throw new InvalidArgumentException('Voorstel niet gevonden.');
        }
        $verified = is_array($verification['payload'] ?? null) ? $verification['payload'] : array();

        $quote = $this->repository->findQuote((int) $verified['quote_id']);
        if ($quote === null || (string) ($quote['quote_reference'] ?? '') !== (string) $verified['quote_reference']) {
            throw new InvalidArgumentException('Voorstel niet gevonden.');
        }

        $tokenId = $this->tokens->tokenId($token);
        if ($this->isTokenRevoked((int) ($quote['id'] ?? 0), $tokenId)) {
            throw new InvalidArgumentException('Deze voorstel-link is ingetrokken. Vraag een nieuwe link aan.');
        }

        $version = $this->repository->findQuoteVersion((int) $verified['version_id']);
        if ($version === null || (int) ($version['quote_id'] ?? 0) !== (int) ($quote['id'] ?? 0)) {
            throw new InvalidArgumentException('Voorstel niet gevonden.');
        }

        $status = (string) ($quote['status'] ?? '');
        if (! in_array($status, self::VIEWABLE_STATUSES, true)) {
            throw new InvalidArgumentException('Dit voorstel is niet beschikbaar.');
        }

        if (! $this->isExpectedPublicVersion($quote, $version)) {
            throw new InvalidArgumentException('Dit voorstel is niet beschikbaar.');
        }

        $request = null;
        $requestId = (int) ($quote['quote_request_id'] ?? 0);
        if ($requestId > 0) {
            $request = $this->repository->findQuoteRequest($requestId);
        }

        return array(
            'quote' => $quote,
            'version' => $version,
            'request' => is_array($request) ? $request : array(),
            'lines' => $this->repository->listQuoteLines((int) ($version['id'] ?? 0)),
            'actionable' => $status === self::ACTIONABLE_STATUS,
            'token_id' => $tokenId,
            'expires_at' => (int) ($verified['expires_at'] ?? 0),
        );
    }

    /**
     * Public proposal links are stateless signed URLs. Quote-scoped revocation
     * records an event that invalidates every existing proposal token for the
     * quote without mutating quote/version/order/payment truth.
     *
     * @return array{quote_id:int,revoked:bool,scope:string}
     */
    public function revokeQuotePublicTokens(int $quoteId, ?int $actorId = null, string $reason = ''): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote niet gevonden.');
        }

        $this->events->log(
            'quote_public_proposal_token_revoked',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            (int) ($quote['id'] ?? 0),
            (int) ($quote['current_version_id'] ?? 0) ?: null,
            $actorId,
            'Publieke voorstel-link(s) ingetrokken.',
            array(
                'scope' => 'quote',
                'reason' => trim($reason),
                'revoked_at' => $this->now(),
            )
        );

        return array('quote_id' => (int) ($quote['id'] ?? 0), 'revoked' => true, 'scope' => 'quote');
    }

    /**
     * @param array<string, mixed> $client
     * @param array<string, mixed> $acceptance
     * @return array<string, mixed>
     */
    public function accept(string $token, array $client, array $acceptance = array()): array
    {
        $context = $this->resolveByToken($token);
        if (empty($context['actionable'])) {
            throw new InvalidArgumentException('Dit voorstel kan niet opnieuw worden geaccepteerd.');
        }

        $quote = $context['quote'];
        $version = $context['version'];
        $legalPayload = (new QuoteLegalAcceptancePayloadService())->build(
            $acceptance,
            is_array($quote) ? $quote : array(),
            is_array($version) ? $version : array(),
            is_array($context['request'] ?? null) ? $context['request'] : array(),
            is_array($context['lines'] ?? null) ? $context['lines'] : array(),
            $client,
            (string) ($context['token_id'] ?? '')
        );
        $accepted = (new QuoteAcceptanceService($this->repository, $this->events))->acceptQuoteVersion(
            (int) ($quote['id'] ?? 0),
            (int) ($version['id'] ?? 0),
            null,
            $legalPayload
        );

        $this->logCustomerAction('quote_public_proposal_accepted', $quote, $version, $context['token_id'], $client, 'Klant heeft online akkoord gegeven.', $legalPayload);
        $this->createAcceptanceConfirmationDraft($accepted, $version, $legalPayload, $token);

        return $accepted;
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    public function requestRevision(string $token, string $message, array $client): array
    {
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('Beschrijf kort welke wijziging u wilt aanvragen.');
        }

        $context = $this->resolveByToken($token);
        if (empty($context['actionable'])) {
            throw new InvalidArgumentException('Voor dit voorstel kan geen wijziging meer worden aangevraagd.');
        }

        $quote = $context['quote'];
        $version = $context['version'];
        $updated = $this->repository->updateQuote((int) ($quote['id'] ?? 0), array(
            'status' => 'revision_requested',
            'send_status' => 'sent_manual',
            'closed_reason' => 'customer_revision_requested',
        ));

        $this->repository->createQuoteMessage(array(
            'quote_id' => (int) ($quote['id'] ?? 0),
            'quote_version_id' => (int) ($version['id'] ?? 0),
            'direction' => 'inbound',
            'message_type' => 'customer_revision_request',
            'channel' => 'public_proposal',
            'status' => 'received',
            'subject' => 'Wijziging aangevraagd',
            'body' => $message,
            'thread_token' => (string) ($quote['quote_reference'] ?? ''),
            'received_at' => $this->now(),
        ));

        $this->logCustomerAction('quote_public_proposal_revision_requested', $quote, $version, $context['token_id'], $client, 'Klant heeft online een wijziging aangevraagd.', array(
            'message_length' => strlen($message),
        ));

        return $updated;
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    public function decline(string $token, string $message, array $client): array
    {
        $context = $this->resolveByToken($token);
        if (empty($context['actionable'])) {
            throw new InvalidArgumentException('Dit voorstel kan niet meer worden afgewezen.');
        }

        $message = trim($message);
        $quote = $context['quote'];
        $version = $context['version'];
        $updated = $this->repository->updateQuote((int) ($quote['id'] ?? 0), array(
            'status' => 'declined',
            'send_status' => 'sent_manual',
            'closed_reason' => 'customer_declined',
        ));

        if ($message !== '') {
            $this->repository->createQuoteMessage(array(
                'quote_id' => (int) ($quote['id'] ?? 0),
                'quote_version_id' => (int) ($version['id'] ?? 0),
                'direction' => 'inbound',
                'message_type' => 'customer_decline',
                'channel' => 'public_proposal',
                'status' => 'received',
                'subject' => 'Voorstel afgewezen',
                'body' => $message,
                'thread_token' => (string) ($quote['quote_reference'] ?? ''),
                'received_at' => $this->now(),
            ));
        }

        $this->logCustomerAction('quote_public_proposal_declined', $quote, $version, $context['token_id'], $client, 'Klant heeft online afgewezen.', array(
            'message_length' => strlen($message),
        ));

        return $updated;
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     */
    private function isExpectedPublicVersion(array $quote, array $version): bool
    {
        $versionId = (int) ($version['id'] ?? 0);
        if (in_array((string) ($quote['status'] ?? ''), self::APPROVED_VERSION_STATUSES, true)) {
            return (int) ($quote['approved_version_id'] ?? 0) === $versionId;
        }

        return $this->latestSentProposalVersionId((int) ($quote['id'] ?? 0)) === $versionId;
    }

    private function latestSentProposalVersionId(int $quoteId): int
    {
        $versionId = 0;
        foreach ($this->repository->listQuoteMessages($quoteId) as $message) {
            if ((string) ($message['direction'] ?? '') !== 'outbound') {
                continue;
            }
            if ((string) ($message['message_type'] ?? '') !== 'proposal') {
                continue;
            }
            if ((string) ($message['status'] ?? '') !== 'sent') {
                continue;
            }
            $candidate = (int) ($message['quote_version_id'] ?? 0);
            if ($candidate > 0) {
                $versionId = $candidate;
            }
        }

        return $versionId;
    }

    private function isTokenRevoked(int $quoteId, string $tokenId): bool
    {
        foreach ($this->repository->listQuoteEvents($quoteId) as $event) {
            if ((string) ($event['event_type'] ?? '') !== 'quote_public_proposal_token_revoked') {
                continue;
            }

            $payload = is_array($event['payload_json'] ?? null) ? $event['payload_json'] : array();
            if ((string) ($payload['scope'] ?? '') === 'quote') {
                return true;
            }
            if ($tokenId !== '' && (string) ($payload['token_id'] ?? '') === $tokenId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Creates an operator-reviewed confirmation draft only. Real mail delivery
     * stays behind the existing QuoteCommunicationService send action.
     *
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     * @param array<string, mixed> $legalPayload
     */
    private function createAcceptanceConfirmationDraft(array $quote, array $version, array $legalPayload, string $token): void
    {
        $quoteId = (int) ($quote['id'] ?? 0);
        if ($quoteId <= 0) {
            return;
        }

        $toEmail = trim((string) ($legalPayload['acceptance_email'] ?? ''));
        if ($toEmail === '') {
            return;
        }

        $toName = trim((string) ($legalPayload['acceptance_name'] ?? ''));
        $pdfUrl = PublicProposalController::pdfUrl($token);
        $reference = (string) ($quote['quote_reference'] ?? '');
        $body = $this->acceptanceConfirmationBody($toName, $reference, $pdfUrl);
        $messagePayload = array(
            'quote_id' => $quoteId,
            'quote_version_id' => (int) ($version['id'] ?? 0),
            'direction' => 'outbound',
            'message_type' => 'acceptance_confirmation',
            'channel' => 'email',
            'status' => 'draft',
            'subject' => 'Bevestiging akkoord offerte ' . $reference,
            'body' => $body,
            'to_name' => $toName,
            'to_email' => $toEmail,
            'thread_token' => $reference,
            'created_by' => null,
        );

        foreach ($this->repository->listQuoteMessages($quoteId) as $existingMessage) {
            if ((string) ($existingMessage['message_type'] ?? '') !== 'acceptance_confirmation' || (string) ($existingMessage['status'] ?? '') !== 'draft') {
                continue;
            }

            if ($this->acceptanceConfirmationDraftNeedsRefresh($existingMessage, $toEmail, $body)) {
                $message = $this->repository->updateQuoteMessage((int) ($existingMessage['id'] ?? 0), $messagePayload);
                $this->events->log(
                    'quote_acceptance_confirmation_draft_refreshed',
                    isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
                    $quoteId,
                    (int) ($version['id'] ?? 0),
                    null,
                    'Bevestigingsmail na akkoord als draft ververst.',
                    array(
                        'message_id' => (int) ($message['id'] ?? 0),
                        'to_email' => $toEmail,
                        'pdf_url_present' => $pdfUrl !== '',
                        'mail_sent' => false,
                    )
                );
            }

            return;
        }

        $message = $this->repository->createQuoteMessage($messagePayload);

        $this->events->log(
            'quote_acceptance_confirmation_draft',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            (int) ($version['id'] ?? 0),
            null,
            'Bevestigingsmail na akkoord als draft klaargezet.',
            array(
                'message_id' => (int) ($message['id'] ?? 0),
                'to_email' => $toEmail,
                'pdf_url_present' => $pdfUrl !== '',
                'mail_sent' => false,
            )
        );
    }

    /**
     * @param array<string, mixed> $message
     */
    private function acceptanceConfirmationDraftNeedsRefresh(array $message, string $toEmail, string $expectedBody): bool
    {
        $body = (string) ($message['body'] ?? '');
        if ($body === '' || $body !== $expectedBody) {
            return true;
        }

        if (stripos($body, '/wp-admin/') !== false || stripos($body, 'admin.php') !== false) {
            return true;
        }

        if (strpos($body, 'ddb_quote_proposal_pdf=1') === false) {
            return true;
        }

        return $toEmail !== '' && trim((string) ($message['to_email'] ?? '')) !== $toEmail;
    }

    private function acceptanceConfirmationBody(string $name, string $reference, string $pdfUrl): string
    {
        $salutation = $name !== '' ? 'Beste ' . $name . ',' : 'Beste klant,';

        return $salutation . "\n\n"
            . "Dank voor uw akkoord op het voorstel van Dagje Den Bosch.\n\n"
            . "Referentie: " . $reference . "\n"
            . "De geaccepteerde offerte kunt u hier downloaden:\n"
            . $pdfUrl . "\n\n"
            . "Wij ronden nu de betaling, bevestiging en operationele voorbereiding verder af.\n\n"
            . "Met vriendelijke groet,\nDagje Den Bosch";
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     * @param array<string, mixed> $client
     * @param array<string, mixed> $extra
     */
    private function logCustomerAction(string $eventType, array $quote, array $version, string $tokenId, array $client, string $message, array $extra = array()): void
    {
        $this->events->log(
            $eventType,
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            (int) ($quote['id'] ?? 0),
            (int) ($version['id'] ?? 0),
            null,
            $message,
            array_merge(array(
                'action' => $eventType,
                'ip' => (string) ($client['ip'] ?? ''),
                'user_agent' => (string) ($client['user_agent'] ?? ''),
                'token_id' => $tokenId,
            ), $extra)
        );
    }

    private function now(): string
    {
        return function_exists('current_time')
            ? (string) current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
