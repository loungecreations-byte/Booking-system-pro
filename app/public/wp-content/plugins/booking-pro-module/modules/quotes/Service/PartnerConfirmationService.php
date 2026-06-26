<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class PartnerConfirmationService
{
    public const STATUS_CONFIRMED = 'supplier_booking_confirmed';
    public const STATUS_DECLINED = 'supplier_unavailable';
    public const STATUS_ALTERNATIVE = 'supplier_alternative_proposed';
    private const FOLLOWUP_TYPE = 'supplier_confirmation';

    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteTimelineService $timeline,
        private PartnerConfirmationTokenService $tokens
    ) {
    }

    /**
     * @return array{token:string,url:string,token_id:string,line:array<string,mixed>}
     */
    public function invite(int $quoteId, int $lineId, ?int $actorId = null): array
    {
        [$quote, $version, $line] = $this->resolveLine($quoteId, $lineId);
        $token = $this->tokens->create(
            (int) $quote['id'],
            (int) $version['id'],
            (int) $line['id'],
            (string) $quote['quote_reference']
        );

        $snapshot = $this->snapshot($line);
        $snapshot['partnerConfirmation'] = array(
            'tokenHash' => $token['token_hash'],
            'tokenId' => $token['token_id'],
            'revoked' => false,
            'invitedAt' => $this->now(),
            'respondedAt' => '',
        );
        $line = $this->repository->updateQuoteLine((int) $line['id'], array(
            'availability_snapshot_json' => $snapshot,
        ));

        $this->timeline->logOnce(
            'supplier_invited',
            'supplier_invited:line:' . (int) $line['id'] . ':token:' . $token['token_id'],
            (int) ($quote['quote_request_id'] ?? 0) ?: null,
            (int) $quote['id'],
            (int) $version['id'],
            $actorId,
            'Partnerbevestigingslink aangemaakt.',
            array(
                'line_id' => (int) $line['id'],
                'token_id' => $token['token_id'],
            )
        );

        return array(
            'token' => $token['token'],
            'url' => self::publicUrl($token['token']),
            'token_id' => $token['token_id'],
            'line' => $line,
        );
    }

    /**
     * @return array{token_id:string,revoked:bool,invited_at:string,sent_at:string,responded_at:string,last_action:string,has_token:bool}
     */
    public function state(int $quoteId, int $lineId): array
    {
        [, , $line] = $this->resolveLine($quoteId, $lineId);
        $snapshot = $this->snapshot($line);
        $partner = is_array($snapshot['partnerConfirmation'] ?? null) ? $snapshot['partnerConfirmation'] : array();

        return array(
            'token_id' => (string) ($partner['tokenId'] ?? ''),
            'revoked' => ! empty($partner['revoked']),
            'invited_at' => (string) ($partner['invitedAt'] ?? ''),
            'sent_at' => (string) ($partner['sentAt'] ?? ''),
            'responded_at' => (string) ($partner['respondedAt'] ?? ''),
            'last_action' => (string) ($partner['lastAction'] ?? ''),
            'has_token' => ! empty($partner['tokenHash']) && ! empty($partner['tokenId']),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function revoke(int $quoteId, int $lineId, ?int $actorId = null): array
    {
        [$quote, $version, $line] = $this->resolveLine($quoteId, $lineId);
        $snapshot = $this->snapshot($line);
        $partner = is_array($snapshot['partnerConfirmation'] ?? null) ? $snapshot['partnerConfirmation'] : array();
        if ($partner === array() || empty($partner['tokenHash'])) {
            throw new InvalidArgumentException('Er is geen actieve partnerlink om in te trekken.');
        }

        $partner['revoked'] = true;
        $partner['revokedAt'] = $this->now();
        $snapshot['partnerConfirmation'] = $partner;
        $updatedLine = $this->repository->updateQuoteLine((int) $line['id'], array(
            'availability_snapshot_json' => $snapshot,
        ));

        $this->timeline->logOnce(
            'supplier_invite_revoked',
            'supplier_invite_revoked:line:' . (int) $line['id'] . ':token:' . (string) ($partner['tokenId'] ?? ''),
            (int) ($quote['quote_request_id'] ?? 0) ?: null,
            (int) $quote['id'],
            (int) $version['id'],
            $actorId,
            'Partnerbevestigingslink ingetrokken.',
            array(
                'line_id' => (int) $line['id'],
                'token_id' => (string) ($partner['tokenId'] ?? ''),
            )
        );

        return $updatedLine;
    }

    public function markSent(int $quoteId, int $lineId, int $messageId, ?int $actorId = null): array
    {
        [$quote, $version, $line] = $this->resolveLine($quoteId, $lineId);
        $snapshot = $this->snapshot($line);
        $partner = is_array($snapshot['partnerConfirmation'] ?? null) ? $snapshot['partnerConfirmation'] : array();
        if ($partner === array() || empty($partner['tokenHash']) || ! empty($partner['revoked'])) {
            throw new InvalidArgumentException('Er is geen actieve partnerlink om te verzenden.');
        }

        $partner['sentAt'] = $this->now();
        $partner['messageId'] = $messageId;
        $snapshot['partnerConfirmation'] = $partner;
        $updatedLine = $this->repository->updateQuoteLine((int) $line['id'], array(
            'availability_snapshot_json' => $snapshot,
        ));

        $this->timeline->logOnce(
            'supplier_invite_sent',
            'supplier_invite_sent:line:' . (int) $line['id'] . ':message:' . $messageId,
            (int) ($quote['quote_request_id'] ?? 0) ?: null,
            (int) $quote['id'],
            (int) $version['id'],
            $actorId,
            'Partnerverzoek verstuurd.',
            array(
                'line_id' => (int) $line['id'],
                'message_id' => $messageId,
                'token_id' => (string) ($partner['tokenId'] ?? ''),
            )
        );

        return $updatedLine;
    }

    /**
     * @return array{quote:array<string,mixed>,version:array<string,mixed>,line:array<string,mixed>,request:array<string,mixed>,token_id:string}
     */
    public function resolveByToken(string $token): array
    {
        $verified = $this->tokens->verify($token);
        if ($verified === null) {
            throw new InvalidArgumentException('Deze partnerlink is ongeldig.');
        }

        $quote = $this->repository->findQuote((int) $verified['quote_id']);
        if (! is_array($quote) || (string) ($quote['quote_reference'] ?? '') !== $verified['quote_reference']) {
            throw new InvalidArgumentException('Deze partnerlink is ongeldig.');
        }

        $version = $this->repository->findQuoteVersion((int) $verified['version_id']);
        if (! is_array($version) || (int) ($version['quote_id'] ?? 0) !== (int) $quote['id']) {
            throw new InvalidArgumentException('Deze partnerlink is ongeldig.');
        }

        $line = $this->repository->findQuoteLine((int) $verified['line_id']);
        if (! is_array($line) || (int) ($line['quote_version_id'] ?? 0) !== (int) $version['id']) {
            throw new InvalidArgumentException('Deze partnerlink is ongeldig.');
        }

        $snapshot = $this->snapshot($line);
        $partner = is_array($snapshot['partnerConfirmation'] ?? null) ? $snapshot['partnerConfirmation'] : array();
        if (! empty($partner['revoked']) || ! hash_equals((string) ($partner['tokenHash'] ?? ''), (string) $verified['secret_hash'])) {
            throw new InvalidArgumentException('Deze partnerlink is verlopen.');
        }

        $request = array();
        $requestId = (int) ($quote['quote_request_id'] ?? 0);
        if ($requestId > 0) {
            $found = $this->repository->findQuoteRequest($requestId);
            $request = is_array($found) ? $found : array();
        }

        return array(
            'quote' => $quote,
            'version' => $version,
            'line' => $line,
            'request' => $request,
            'token_id' => (string) $verified['token_id'],
        );
    }

    /**
     * @param array{ip?:string,user_agent?:string} $client
     * @return array<string,mixed>
     */
    public function respond(string $token, string $action, string $message = '', array $client = array()): array
    {
        $context = $this->resolveByToken($token);
        $quote = $context['quote'];
        $version = $context['version'];
        $line = $context['line'];
        $action = strtolower(trim($action));
        $message = trim($message);

        $status = match ($action) {
            'confirm' => self::STATUS_CONFIRMED,
            'decline' => self::STATUS_DECLINED,
            'alternative' => self::STATUS_ALTERNATIVE,
            default => '',
        };
        if ($status === '') {
            throw new InvalidArgumentException('Onbekende partneractie.');
        }
        if ($status === self::STATUS_ALTERNATIVE && $message === '') {
            throw new InvalidArgumentException('Beschrijf kort het alternatieve voorstel.');
        }

        $snapshot = $this->snapshot($line);
        $partner = is_array($snapshot['partnerConfirmation'] ?? null) ? $snapshot['partnerConfirmation'] : array();
        $partner['respondedAt'] = $this->now();
        $partner['lastAction'] = $action;
        $snapshot['partnerConfirmation'] = $partner;
        $snapshot['supplierStatus'] = $status;
        $snapshot['supplierResponseNote'] = $message;
        $snapshot['availabilityStatus'] = $status === self::STATUS_CONFIRMED ? 'available' : ($status === self::STATUS_DECLINED ? 'unavailable' : 'unknown');

        $changes = array(
            'availability_snapshot_json' => $snapshot,
            'availability_confidence' => $status === self::STATUS_CONFIRMED ? 'confirmed' : 'unknown',
        );
        if ($status === self::STATUS_DECLINED) {
            $changes['line_status'] = 'unavailable';
        } elseif ($status === self::STATUS_CONFIRMED && (string) ($line['line_status'] ?? '') === 'unavailable') {
            $changes['line_status'] = (int) ($line['product_id'] ?? 0) > 0 ? 'mapped' : 'directional';
        }

        $updatedLine = $this->repository->updateQuoteLine((int) $line['id'], $changes);
        $eventType = match ($status) {
            self::STATUS_CONFIRMED => 'supplier_confirmed',
            self::STATUS_DECLINED => 'supplier_declined',
            default => 'supplier_alternative_proposed',
        };

        $this->timeline->logOnce(
            $eventType,
            $eventType . ':line:' . (int) $line['id'] . ':token:' . (string) $context['token_id'],
            (int) ($quote['quote_request_id'] ?? 0) ?: null,
            (int) $quote['id'],
            (int) $version['id'],
            null,
            $this->eventMessage($eventType),
            array(
                'line_id' => (int) $line['id'],
                'status' => $status,
                'message' => $message,
                'token_id' => (string) $context['token_id'],
                'ip' => (string) ($client['ip'] ?? ''),
                'user_agent' => (string) ($client['user_agent'] ?? ''),
            )
        );

        $this->upsertPartnerResponseFollowup($quote, $version, $updatedLine, $status, $message, $eventType);

        return array(
            'quote' => $quote,
            'version' => $version,
            'line' => $updatedLine,
            'status' => $status,
        );
    }

    public static function publicUrl(string $token): string
    {
        $base = function_exists('home_url') ? (string) home_url('/') : '/';
        return self::addQueryArg(array('ddb_partner_confirmation' => $token), $base);
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function resolveLine(int $quoteId, int $lineId): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if (! is_array($quote)) {
            throw new InvalidArgumentException('Quote not found.');
        }

        $versionId = (int) ($quote['current_version_id'] ?? 0);
        $version = $versionId > 0 ? $this->repository->findQuoteVersion($versionId) : null;
        if (! is_array($version) || (int) ($version['quote_id'] ?? 0) !== $quoteId) {
            throw new InvalidArgumentException('Actieve quote-versie niet gevonden.');
        }

        $line = $this->repository->findQuoteLine($lineId);
        if (! is_array($line) || (int) ($line['quote_version_id'] ?? 0) !== $versionId) {
            throw new InvalidArgumentException('Programmaregel niet gevonden in de actieve quote-versie.');
        }

        return array($quote, $version, $line);
    }

    /**
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private function snapshot(array $line): array
    {
        return is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
    }

    private function eventMessage(string $eventType): string
    {
        return match ($eventType) {
            'supplier_confirmed' => 'Partner heeft beschikbaarheid bevestigd.',
            'supplier_declined' => 'Partner heeft aangegeven niet beschikbaar te zijn.',
            default => 'Partner heeft een alternatief voorgesteld.',
        };
    }

    /**
     * @param array<string,mixed> $quote
     * @param array<string,mixed> $version
     * @param array<string,mixed> $line
     */
    private function upsertPartnerResponseFollowup(array $quote, array $version, array $line, string $status, string $message, string $eventType): void
    {
        $marker = '[partner-response:line:' . (int) ($line['id'] ?? 0) . ']';
        $title = match ($status) {
            self::STATUS_CONFIRMED => 'Partnerbevestiging verwerken',
            self::STATUS_DECLINED => 'Partner niet beschikbaar: alternatief nodig',
            default => 'Partneralternatief beoordelen',
        };
        $note = $marker . "\n" . $this->eventMessage($eventType);
        if ($message !== '') {
            $note .= "\nReactie partner: " . $message;
        }

        $existing = $this->findFollowup((int) ($quote['id'] ?? 0), $marker);
        $payload = array(
            'status' => $status === self::STATUS_CONFIRMED ? 'completed' : 'open',
            'priority' => $status === self::STATUS_CONFIRMED ? 'normal' : 'high',
            'title' => $title,
            'note' => $note,
            'due_at' => $status === self::STATUS_CONFIRMED ? null : gmdate('Y-m-d H:i:s', strtotime('+4 hours') ?: time()),
        );

        if (is_array($existing)) {
            $this->repository->updateQuoteFollowup((int) $existing['id'], $payload + array(
                'completed_at' => $status === self::STATUS_CONFIRMED ? $this->now() : null,
            ));
            return;
        }

        $this->repository->createQuoteFollowup($payload + array(
            'quote_request_id' => isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            'quote_id' => (int) $quote['id'],
            'followup_type' => self::FOLLOWUP_TYPE,
            'created_by' => null,
            'completed_at' => $status === self::STATUS_CONFIRMED ? $this->now() : null,
        ));
    }

    private function findFollowup(int $quoteId, string $marker): ?array
    {
        foreach ($this->repository->listQuoteFollowups($quoteId) as $followup) {
            if (str_contains((string) ($followup['note'] ?? ''), $marker)) {
                return $followup;
            }
        }

        return null;
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }

    /**
     * @param array<string,string> $args
     */
    private static function addQueryArg(array $args, string $url): string
    {
        if (function_exists('add_query_arg')) {
            return (string) add_query_arg($args, $url);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }
}
