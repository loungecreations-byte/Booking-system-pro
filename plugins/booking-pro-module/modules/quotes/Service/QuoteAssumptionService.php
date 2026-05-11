<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;

final class QuoteAssumptionService
{
    public function __construct(private QuoteRepositoryInterface $repository, private QuoteEventLogger $events)
    {
    }

    /**
     * @param array<string, mixed> $quoteRequest
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    public function createAutomaticAssumptions(array $quoteRequest, array $quote, array $version, array $lines): array
    {
        $assumptions = array();

        if (empty($quoteRequest['preferred_date'])) {
            $assumptions[] = $this->create((int) $quote['id'], (int) $version['id'], 'missing_date', 'warning', 'internal', 'Voorkeursdatum ontbreekt; quote blijft richtinggevend tot datum is bevestigd.', true, true, true);
        }

        if ((int) ($quoteRequest['group_size'] ?? 0) <= 0) {
            $assumptions[] = $this->create((int) $quote['id'], (int) $version['id'], 'missing_group_size', 'warning', 'internal', 'Groepsgrootte ontbreekt; definitieve prijs en inzet kunnen niet worden bevestigd.', true, true, true);
        }

        if (($version['pricing_confidence'] ?? 'unknown') !== 'execution_verified') {
            $assumptions[] = $this->create((int) $quote['id'], (int) $version['id'], 'uncertain_pricing', 'warning', 'internal', 'Prijs is een snapshot/richtinggevend voorstel en geen definitieve commerciële waarheid.', false, false, true);
        }

        if (($version['availability_confidence'] ?? 'unknown') !== 'confirmed') {
            $assumptions[] = $this->create((int) $quote['id'], (int) $version['id'], 'uncertain_availability', 'warning', 'internal', 'Beschikbaarheid is nog niet definitief bevestigd via de execution-validatiepad.', false, false, true);
        }

        foreach ($lines as $line) {
            if ((int) ($line['product_id'] ?? 0) <= 0) {
                $assumptions[] = $this->create(
                    (int) $quote['id'],
                    (int) $version['id'],
                    'manual_review_required',
                    'warning',
                    'internal',
                    'Ten minste één quote-regel is nog niet gekoppeld aan een concreet product.',
                    true,
                    true,
                    true,
                    isset($line['id']) ? (int) $line['id'] : null
                );
                break;
            }
        }

        return $assumptions;
    }

    public function create(
        int $quoteId,
        int $quoteVersionId,
        string $type,
        string $severity,
        string $visibility,
        string $message,
        bool $blocksReview,
        bool $blocksSend,
        bool $blocksHandoff,
        ?int $lineId = null
    ): array {
        $assumption = $this->repository->createQuoteAssumption(array(
            'quote_id'        => $quoteId,
            'quote_version_id'=> $quoteVersionId,
            'quote_line_id'   => $lineId,
            'assumption_type' => $type,
            'severity'        => $severity,
            'visibility'      => $visibility,
            'status'          => 'open',
            'message'         => $message,
            'blocks_review'   => $blocksReview ? 1 : 0,
            'blocks_send'     => $blocksSend ? 1 : 0,
            'blocks_handoff'  => $blocksHandoff ? 1 : 0,
        ));

        $this->events->log('quote_assumption_created', null, $quoteId, $quoteVersionId, null, $message, array('assumption_type' => $type));

        return $assumption;
    }
}
