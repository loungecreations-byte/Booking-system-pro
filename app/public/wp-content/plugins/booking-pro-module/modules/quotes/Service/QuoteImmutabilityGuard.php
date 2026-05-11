<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

/**
 * Central immutability guard for commercial quote context.
 *
 * Terminology:
 * - TERMINAL statuses: quote is fully closed; no revisions, no status changes allowed.
 * - CONTENT_FROZEN statuses: commercial version content is frozen;
 *   in-place edits are blocked. New versions (revisions) are still possible for 'sent'.
 *
 * Relationships:
 * - assertQuoteAcceptsRevision  → blocks TERMINAL only (used in saveDraft before clone logic)
 * - assertQuoteCommercialContextEditable → blocks CONTENT_FROZEN (includes 'sent')
 * - assertVersionCommerciallyEditable → blocks if version == approved_version_id or CONTENT_FROZEN
 */
final class QuoteImmutabilityGuard
{
    /**
     * Statuses where no further edits or revisions are allowed.
     * approved_version_id is permanently pinned; the quote lifecycle is closed.
     */
    public const TERMINAL_STATUSES = [
        'accepted',
        'declined',
        'expired',
        'cancelled',
        'revised',
        'handoff_completed',
        'handoff_failed',
    ];

    /**
     * Statuses where the existing commercial version content is frozen.
     * Includes TERMINAL_STATUSES plus 'sent' (where a new revision clone is allowed
     * but in-place editing of the sent version is not).
     */
    public const CONTENT_FROZEN_STATUSES = [
        'sent',
        'accepted',
        'declined',
        'expired',
        'cancelled',
        'revised',
        'handoff_completed',
        'handoff_failed',
    ];

    public function __construct(private QuoteRepositoryInterface $repository)
    {
    }

    /**
     * Assert that a new revision (new draft version via clone) may still be created.
     *
     * Throws for TERMINAL statuses. Allows 'sent' so that saveDraft() can still
     * route into the clone/revision path without being blocked here.
     *
     * @throws InvalidArgumentException
     */
    public function assertQuoteAcceptsRevision(int $quoteId): void
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException("Quote {$quoteId} niet gevonden.");
        }

        $status = (string) ($quote['status'] ?? '');
        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            throw new InvalidArgumentException(
                "Quote {$quoteId} is vergrendeld (status: {$status}). "
                . 'Geen revisies of aanpassingen meer mogelijk na een afgesloten status.'
            );
        }
    }

    /**
     * Assert that the commercial context of the quote may be edited in place.
     *
     * Throws for CONTENT_FROZEN statuses (includes 'sent').
     * Use this guard for direct mutations: intake update, proposal fields, lines.
     *
     * @throws InvalidArgumentException
     */
    public function assertQuoteCommercialContextEditable(int $quoteId): void
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException("Quote {$quoteId} niet gevonden.");
        }

        $status = (string) ($quote['status'] ?? '');
        if (in_array($status, self::CONTENT_FROZEN_STATUSES, true)) {
            throw new InvalidArgumentException(
                "Quote {$quoteId} is commercieel vergrendeld (status: {$status}). "
                . 'Directe aanpassingen zijn niet toegestaan. Maak een nieuwe revisie aan via het revisieproces.'
            );
        }
    }

    /**
     * Assert that a specific version may have its commercial content updated.
     *
     * Throws if:
     * - The version is the approved_version_id (pinned at customer acceptance).
     * - The quote is in CONTENT_FROZEN status.
     *
     * @throws InvalidArgumentException
     */
    public function assertVersionCommerciallyEditable(int $quoteId, int $versionId): void
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException("Quote {$quoteId} niet gevonden.");
        }

        $approvedVersionId = (int) ($quote['approved_version_id'] ?? 0);
        if ($approvedVersionId > 0 && $approvedVersionId === $versionId) {
            throw new InvalidArgumentException(
                "Quote-versie {$versionId} is de goedgekeurde versie (approved_version_id) "
                . 'en kan niet worden gewijzigd. De geaccepteerde commerciële context is onveranderlijk.'
            );
        }

        $status = (string) ($quote['status'] ?? '');
        if (in_array($status, self::CONTENT_FROZEN_STATUSES, true)) {
            throw new InvalidArgumentException(
                "Quote {$quoteId} is commercieel vergrendeld (status: {$status}). "
                . "Versie {$versionId} mag niet in-place worden aangepast."
            );
        }
    }
}
