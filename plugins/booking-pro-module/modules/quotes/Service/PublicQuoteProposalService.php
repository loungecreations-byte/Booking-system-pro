<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class PublicQuoteProposalService
{
    private const VIEWABLE_STATUSES = ['sent', 'accepted', 'revision_requested', 'declined'];
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
        $verified = $this->tokens->verify($token);
        if ($verified === null) {
            throw new InvalidArgumentException('Voorstel niet gevonden.');
        }

        $quote = $this->repository->findQuote((int) $verified['quote_id']);
        if ($quote === null || (string) ($quote['quote_reference'] ?? '') !== (string) $verified['quote_reference']) {
            throw new InvalidArgumentException('Voorstel niet gevonden.');
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
            'token_id' => $this->tokens->tokenId($token),
        );
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    public function accept(string $token, array $client): array
    {
        $context = $this->resolveByToken($token);
        if (empty($context['actionable'])) {
            throw new InvalidArgumentException('Dit voorstel kan niet opnieuw worden geaccepteerd.');
        }

        $quote = $context['quote'];
        $version = $context['version'];
        $accepted = (new QuoteAcceptanceService($this->repository, $this->events))->acceptQuoteVersion(
            (int) ($quote['id'] ?? 0),
            (int) ($version['id'] ?? 0),
            null
        );

        $this->logCustomerAction('quote_public_proposal_accepted', $quote, $version, $context['token_id'], $client, 'Klant heeft online akkoord gegeven.');

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
        if ((string) ($quote['status'] ?? '') === 'accepted') {
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
