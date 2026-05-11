<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteAcceptanceService
{
    /** Statuses from which a CUSTOMER may accept (no capability check needed). */
    private const CUSTOMER_ACCEPTABLE_STATUSES = ['sent'];

    /** Statuses from which an ADMIN may manually accept (requires explicit admin flag). */
    private const ADMIN_ACCEPTABLE_STATUSES = ['sent', 'ready_to_send'];

    /** Quote statuses that are terminal / non-acceptable. */
    private const TERMINAL_STATUSES = ['accepted', 'declined', 'expired', 'cancelled', 'revised', 'handoff_completed', 'handoff_failed'];

    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events
    ) {
    }

    /**
     * Customer-facing acceptance: requires status === 'sent'.
     * Pins approved_version_id, transitions quote to 'accepted'.
     *
     * @return array<string, mixed>
     */
    public function acceptQuoteVersion(int $quoteId, int $versionId, ?int $actorId = null): array
    {
        return $this->doAccept($quoteId, $versionId, $actorId, false);
    }

    /**
     * Admin-only manual acceptance override (e.g., from ready_to_send).
     * Requires explicit $isAdminOverride = true and is audited separately.
     *
     * @return array<string, mixed>
     */
    public function adminAcceptQuoteVersion(int $quoteId, int $versionId, int $adminActorId): array
    {
        return $this->doAccept($quoteId, $versionId, $adminActorId, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function doAccept(int $quoteId, int $versionId, ?int $actorId, bool $isAdminOverride): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        $currentStatus = (string) ($quote['status'] ?? '');

        // Block terminal statuses.
        if (in_array($currentStatus, self::TERMINAL_STATUSES, true)) {
            throw new InvalidArgumentException(
                sprintf('Quote kan niet geaccepteerd worden; huidige status is "%s".', $currentStatus)
            );
        }

        // Customer path: only 'sent' is valid.
        // Admin path: 'sent' or 'ready_to_send'.
        $allowedStatuses = $isAdminOverride ? self::ADMIN_ACCEPTABLE_STATUSES : self::CUSTOMER_ACCEPTABLE_STATUSES;
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Quote kan alleen geaccepteerd worden vanuit status "%s"; huidige status is "%s".',
                    implode('" of "', $allowedStatuses),
                    $currentStatus
                )
            );
        }

        // Validate version belongs to this quote.
        $version = $this->repository->findQuoteVersion($versionId);
        if ($version === null) {
            throw new InvalidArgumentException('Opgegeven versie bestaat niet.');
        }
        if ((int) $version['quote_id'] !== $quoteId) {
            throw new InvalidArgumentException('Versie hoort niet bij deze quote.');
        }

        // Prevent re-acceptance to a different version if already pinned.
        $existingApprovedId = (int) ($quote['approved_version_id'] ?? 0);
        if ($existingApprovedId > 0 && $existingApprovedId !== $versionId) {
            throw new InvalidArgumentException(
                sprintf(
                    'Quote heeft al een geaccepteerde versie (%d). Maak eerst een revisie aan.',
                    $existingApprovedId
                )
            );
        }

        $now = gmdate('Y-m-d H:i:s');
        $changes = array(
            'status'              => 'accepted',
            'handoff_status'      => $this->resolveAcceptedHandoffStatus($quote, $versionId, $version),
            'approved_version_id' => $versionId,
        );

        // Write accepted_at if the column exists (schema v2+); safe fallback via meta.
        if (array_key_exists('accepted_at', $quote)) {
            $changes['accepted_at'] = $now;
        }

        $updatedQuote = $this->repository->updateQuote($quoteId, $changes);

        $this->events->log(
            $isAdminOverride ? 'quote_admin_accepted' : 'quote_accepted',
            null,
            $quoteId,
            $versionId,
            $actorId,
            sprintf(
                '%s heeft quote versie %d geaccepteerd.',
                $isAdminOverride ? 'Admin' : 'Klant',
                $versionId
            ),
            array(
                'accepted_version_id' => $versionId,
                'is_admin_override'   => $isAdminOverride,
                'accepted_at'         => $now,
            )
        );

        return $updatedQuote;
    }

    /**
     * Preserve a valid resnapshot-prepared handoff state when the accepted version
     * is already the active execution resnapshot. Otherwise fail closed and require
     * a fresh resnapshot before handoff can proceed.
     *
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     */
    private function resolveAcceptedHandoffStatus(array $quote, int $versionId, array $version): string
    {
        $currentVersionId = (int) ($quote['current_version_id'] ?? 0);
        $handoffStatus = (string) ($quote['handoff_status'] ?? 'not_ready');
        $snapshotType = (string) ($version['snapshot_type'] ?? '');

        if (
            $currentVersionId === $versionId
            && $handoffStatus === 'resnapshot_prepared'
            && $snapshotType === 'execution_resnapshot'
        ) {
            return 'resnapshot_prepared';
        }

        return 'not_ready';
    }
}
