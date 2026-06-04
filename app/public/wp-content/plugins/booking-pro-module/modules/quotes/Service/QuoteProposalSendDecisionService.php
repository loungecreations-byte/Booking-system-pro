<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;

final class QuoteProposalSendDecisionService
{
    public function __construct(private QuoteRepositoryInterface $repository)
    {
    }

    /**
     * @return array{
     *     proposal_send_ready:bool,
     *     can_complete_control:bool,
     *     can_send:bool,
     *     blockers:array<int,array{code:string,message:string}>,
     *     next_action:string,
     *     review_status:string,
     *     send_status:string
     * }
     */
    public function decide(int $quoteId): array
    {
        $quote = $this->repository->findQuote($quoteId);
        $reviewStatus = is_array($quote) ? (string) ($quote['review_status'] ?? 'not_started') : 'not_started';
        $sendStatus = is_array($quote) ? (string) ($quote['send_status'] ?? 'not_ready') : 'not_ready';
        $inspection = (new QuoteSendReadinessValidator($this->repository))->inspect($quoteId);
        $validatorReady = ! empty($inspection['ready']) && (array) ($inspection['blockers'] ?? array()) === array();
        $blockers = $this->normalizeBlockers((array) ($inspection['blockers'] ?? array()));

        if (is_array($quote)) {
            $blockers = array_merge($blockers, $this->supplierConfirmationBlockers($quote));
        }

        $blockers = $this->uniqueBlockers($blockers);
        $proposalSendReady = $validatorReady && $blockers === array();
        $canCompleteControl = $proposalSendReady && ($reviewStatus !== 'approved' || $sendStatus !== 'ready_to_send');
        $canSend = $proposalSendReady && $reviewStatus === 'approved' && $sendStatus === 'ready_to_send';

        return array(
            'proposal_send_ready' => $proposalSendReady,
            'can_complete_control' => $canCompleteControl,
            'can_send' => $canSend,
            'blockers' => $blockers,
            'next_action' => $this->nextAction($proposalSendReady, $canCompleteControl, $canSend, $blockers),
            'review_status' => $reviewStatus,
            'send_status' => $sendStatus,
        );
    }

    /**
     * @param array<string, mixed> $quote
     * @return array<int,array{code:string,message:string}>
     */
    private function supplierConfirmationBlockers(array $quote): array
    {
        $versionId = (int) ($quote['current_version_id'] ?? 0);
        if ($versionId <= 0) {
            return array();
        }

        $blockers = array();
        foreach ($this->repository->listQuoteLines($versionId) as $line) {
            if (! $this->requiresSupplierConfirmation($line)) {
                continue;
            }

            $lineNumber = (int) ($line['line_number'] ?? 0);
            $blockers[] = array(
                'code' => 'supplier_confirmation_missing',
                'message' => $lineNumber > 0
                    ? sprintf('Programmaregel %d mist supplier_booking_confirmed.', $lineNumber)
                    : 'Supplier confirmation ontbreekt.',
            );
        }

        return $blockers;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function requiresSupplierConfirmation(array $line): bool
    {
        $productId = (int) ($line['product_id'] ?? 0);
        $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
        $bookingMode = strtolower(trim((string) ($snapshot['bookingMode'] ?? $snapshot['booking_mode'] ?? '')));
        $supplierProvider = strtolower(trim((string) ($snapshot['supplierProvider'] ?? $snapshot['provider'] ?? '')));
        $supplierStatus = strtolower(trim((string) ($snapshot['supplierStatus'] ?? $snapshot['supplier_status'] ?? '')));

        if ($supplierStatus === 'supplier_booking_confirmed') {
            return false;
        }

        if ($productId === 115 || $bookingMode === 'supplier_confirmation' || $supplierProvider === 'eliio') {
            return true;
        }

        return in_array($supplierStatus, array('supplier_confirmation_required', 'supplier_option_requested'), true);
    }

    /**
     * @param array<int, mixed> $blockers
     * @return array<int,array{code:string,message:string}>
     */
    private function normalizeBlockers(array $blockers): array
    {
        $normalized = array();
        foreach ($blockers as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }

            $code = (string) ($blocker['code'] ?? 'unknown');
            $normalized[] = array(
                'code' => $this->normalizeCode($code),
                'message' => $this->normalizeMessage($code, (string) ($blocker['message'] ?? '')),
            );
        }

        return $normalized;
    }

    private function normalizeCode(string $code): string
    {
        return match ($code) {
            'customer_email_invalid' => 'customer_email_missing',
            'proposal_customer_text_missing' => 'proposal_text_missing',
            default => $code,
        };
    }

    private function normalizeMessage(string $code, string $message): string
    {
        return match ($code) {
            'customer_email_invalid' => 'Klantmail ontbreekt.',
            'proposal_customer_text_missing' => 'Voorsteltekst ontbreekt.',
            'quote_lines_missing' => 'Open programmaregel ontbreekt.',
            default => $message !== '' ? $message : 'Controleer open punten.',
        };
    }

    /**
     * @param array<int,array{code:string,message:string}> $blockers
     * @return array<int,array{code:string,message:string}>
     */
    private function uniqueBlockers(array $blockers): array
    {
        $seen = array();
        $unique = array();
        foreach ($blockers as $blocker) {
            $key = (string) ($blocker['code'] ?? '') . '|' . (string) ($blocker['message'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $blocker;
        }

        return $unique;
    }

    /**
     * @param array<int,array{code:string,message:string}> $blockers
     */
    private function nextAction(bool $proposalSendReady, bool $canCompleteControl, bool $canSend, array $blockers): string
    {
        if ($canSend) {
            return 'Voorstel versturen';
        }
        if ($canCompleteControl) {
            return 'Controle afronden';
        }
        if (! $proposalSendReady && $blockers !== array()) {
            return 'Nog nodig: ' . implode(', ', array_map(
                static fn (array $blocker): string => (string) ($blocker['message'] ?? 'controlepunt'),
                array_slice($blockers, 0, 3)
            ));
        }

        return 'Controle nodig';
    }
}
