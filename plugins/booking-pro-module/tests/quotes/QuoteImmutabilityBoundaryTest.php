<?php

declare(strict_types=1);

namespace BSP\Tests\Quotes;

use BSP\Quotes\Service\QuoteAssumptionService;
use BSP\Quotes\Service\QuoteConversionService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteFollowupService;
use BSP\Quotes\Service\QuoteImmutabilityGuard;
use BSP\Quotes\Service\QuoteOperationsDraftService;
use BSP\Quotes\Service\QuoteReviewService;
use BSP\Quotes\Service\QuoteSendService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryQuoteRepository.php';

/**
 * P0.2 — Quote Immutability Boundary Tests.
 *
 * Architecture rules under test:
 * - Draft quote versions may be edited (no guard fires).
 * - Sent quote versions are immutable in-place; saveDraft must clone into a new revision.
 * - Accepted/terminal quotes block all commercial edits AND new revisions via saveDraft.
 * - approved_version_id cannot be overwritten by a normal updateQuote call.
 * - returnToDraft is blocked for sent/accepted quotes.
 * - reopenSend is blocked for accepted/terminal quotes.
 * - QuoteImmutabilityGuard::assertVersionCommerciallyEditable blocks the approved version.
 * - QuoteImmutabilityGuard::assertQuoteCommercialContextEditable blocks sent/accepted.
 * - Internal notes (non-commercial) are not tested here; they remain handled by services
 *   that explicitly separate commercial vs. log context.
 */
final class QuoteImmutabilityBoundaryTest extends TestCase
{
    private InMemoryQuoteRepository $repo;
    private QuoteEventLogger $events;
    private QuoteImmutabilityGuard $guard;
    private QuoteOperationsDraftService $draft;
    private QuoteReviewService $review;
    private QuoteSendService $send;

    protected function setUp(): void
    {
        $this->repo   = new InMemoryQuoteRepository();
        $this->events = new QuoteEventLogger($this->repo);
        $this->guard  = new QuoteImmutabilityGuard($this->repo);

        $assumptions   = new QuoteAssumptionService($this->repo, $this->events);
        $conversion    = new QuoteConversionService($this->repo, $assumptions, $this->events);
        $followups     = new QuoteFollowupService($this->repo, $this->events);
        $this->draft   = new QuoteOperationsDraftService($this->repo, $this->events);
        $this->review  = new QuoteReviewService($this->repo, $this->events, $followups);
        $this->send    = new QuoteSendService($this->repo, $this->events);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a quote + version in the specified status.
     * Optionally pins approved_version_id (simulates prior acceptance).
     *
     * @return array{quote: array<string,mixed>, version: array<string,mixed>}
     */
    private function makeQuote(string $status, bool $pinApproved = false): array
    {
        $quote = $this->repo->createQuote(array(
            'status'              => $status,
            'review_status'       => in_array($status, ['reviewed', 'sent', 'accepted'], true) ? 'approved' : 'not_started',
            'send_status'         => $status === 'sent' ? 'sent_manual' : ($status === 'reviewed' ? 'ready_to_send' : 'not_ready'),
            'handoff_status'      => 'not_ready',
            'current_version_id'  => 0,
            'approved_version_id' => 0,
        ));
        $version = $this->repo->createQuoteVersion(array(
            'quote_id'       => (int) $quote['id'],
            'version_number' => 1,
            'status'         => 'draft',
            'proposal_title' => 'Test Offerte',
        ));
        $this->repo->updateQuote((int) $quote['id'], array(
            'current_version_id' => (int) $version['id'],
        ));
        if ($pinApproved) {
            $this->repo->updateQuote((int) $quote['id'], array(
                'approved_version_id' => (int) $version['id'],
            ));
        }

        return array(
            'quote'   => $this->repo->findQuote((int) $quote['id']),
            'version' => $version,
        );
    }

    /** Build minimal saveDraft payload with one line. */
    private function draftPayload(): array
    {
        return array(
            'lines' => array(
                array(
                    'sort_order'              => 1,
                    'title'                   => 'Escape room',
                    'product_id'              => 10,
                    'quantity'                => 1,
                    'participants'            => 8,
                    'pricing_confidence'      => 'snapshot',
                    'availability_confidence' => 'projected',
                    'unit_amount_snapshot'    => 25.0,
                    'line_total_snapshot'     => 200.0,
                    'currency'                => 'EUR',
                ),
            ),
        );
    }

    // -----------------------------------------------------------------------
    // TC-IMM-01: saveDraft throws for accepted status
    // -----------------------------------------------------------------------

    public function testSaveDraftThrowsForAcceptedQuote(): void
    {
        ['quote' => $quote] = $this->makeQuote('accepted');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/vergrendeld.*accepted/i');

        $this->draft->saveDraft((int) $quote['id'], $this->draftPayload(), 1);
    }

    // -----------------------------------------------------------------------
    // TC-IMM-02: saveDraft throws for cancelled status
    // -----------------------------------------------------------------------

    public function testSaveDraftThrowsForCancelledQuote(): void
    {
        ['quote' => $quote] = $this->makeQuote('cancelled');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/vergrendeld.*cancelled/i');

        $this->draft->saveDraft((int) $quote['id'], $this->draftPayload(), 1);
    }

    // -----------------------------------------------------------------------
    // TC-IMM-03: saveDraft on sent quote creates a NEW draft version (clone path)
    // -----------------------------------------------------------------------

    public function testSaveDraftOnSentQuoteCreatesNewDraftVersion(): void
    {
        ['quote' => $quote, 'version' => $sentVersion] = $this->makeQuote('sent');
        $sentVersionId = (int) $sentVersion['id'];

        $result = $this->draft->saveDraft((int) $quote['id'], $this->draftPayload(), 1);

        $this->assertTrue((bool) $result['created_new_version'], 'A new version must be created for sent quotes.');
        $this->assertNotSame($sentVersionId, (int) $result['version']['id'], 'New version ID must differ from sent version ID.');
    }

    // -----------------------------------------------------------------------
    // TC-IMM-04: After saveDraft clone on sent, old sent version lines are intact
    // -----------------------------------------------------------------------

    public function testSaveDraftOnSentQuoteLeavesOldVersionLinesIntact(): void
    {
        // Seed original lines on the sent version before save
        ['quote' => $quote, 'version' => $sentVersion] = $this->makeQuote('sent');
        $sentVersionId = (int) $sentVersion['id'];
        $this->repo->replaceQuoteLines($sentVersionId, array(
            array('title' => 'Originele activiteit', 'line_number' => 1, 'sort_order' => 1, 'quantity' => 1, 'participants' => 4),
        ));

        $this->draft->saveDraft((int) $quote['id'], $this->draftPayload(), 1);

        // Original sent version lines must be unchanged
        $originalLines = $this->repo->listQuoteLines($sentVersionId);
        $this->assertCount(1, $originalLines);
        $this->assertSame('Originele activiteit', $originalLines[0]['title']);
    }

    // -----------------------------------------------------------------------
    // TC-IMM-05: returnToDraft is blocked for a sent quote
    // -----------------------------------------------------------------------

    public function testReturnToDraftBlockedForSentQuote(): void
    {
        ['quote' => $quote] = $this->makeQuote('sent');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/commercieel vergrendeld/i');

        $this->review->returnToDraft((int) $quote['id'], 'blocked', 1);
    }

    // -----------------------------------------------------------------------
    // TC-IMM-06: returnToDraft is blocked for an accepted quote
    // -----------------------------------------------------------------------

    public function testReturnToDraftBlockedForAcceptedQuote(): void
    {
        ['quote' => $quote] = $this->makeQuote('accepted');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/commercieel vergrendeld/i');

        $this->review->returnToDraft((int) $quote['id'], 'blocked', 1);
    }

    // -----------------------------------------------------------------------
    // TC-IMM-07: returnToDraft still works for a draft/reviewed quote
    // -----------------------------------------------------------------------

    public function testReturnToDraftWorksForReviewedQuote(): void
    {
        // reviewed = status set by QuoteReviewService::approve()
        ['quote' => $quote] = $this->makeQuote('reviewed');

        // Should not throw
        $updated = $this->review->returnToDraft((int) $quote['id'], 'needs_changes', 1);

        $this->assertSame('draft', $updated['status']);
    }

    public function testRequestReviewBlockedForSentQuote(): void
    {
        ['quote' => $quote] = $this->makeQuote('sent');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/commercieel vergrendeld.*sent/i');

        $this->review->requestReview((int) $quote['id'], 1);
    }

    public function testApproveReviewBlockedForSentQuote(): void
    {
        ['quote' => $quote] = $this->makeQuote('sent');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/commercieel vergrendeld.*sent/i');

        $this->review->approve((int) $quote['id'], 1);
    }

    // -----------------------------------------------------------------------
    // TC-IMM-08: reopenSend is blocked for an accepted quote
    // -----------------------------------------------------------------------

    public function testReopenSendBlockedForAcceptedQuote(): void
    {
        ['quote' => $quote] = $this->makeQuote('accepted');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/vergrendeld.*accepted/i');

        $this->send->reopenSend((int) $quote['id'], 'attempt', 1);
    }

    // -----------------------------------------------------------------------
    // TC-IMM-09: reopenSend is blocked for declined/cancelled terminal statuses
    // -----------------------------------------------------------------------

    public function testReopenSendBlockedForDeclinedQuote(): void
    {
        ['quote' => $quote] = $this->makeQuote('declined');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/vergrendeld/i');

        $this->send->reopenSend((int) $quote['id'], 'attempt', 1);
    }

    // -----------------------------------------------------------------------
    // TC-IMM-10: Cannot overwrite approved_version_id via updateQuote
    // -----------------------------------------------------------------------

    public function testCannotOverwriteApprovedVersionIdViaUpdateQuote(): void
    {
        ['quote' => $quote, 'version' => $v1] = $this->makeQuote('accepted', true);

        $v2 = $this->repo->createQuoteVersion(array(
            'quote_id'       => (int) $quote['id'],
            'version_number' => 2,
            'status'         => 'draft',
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/approved_version_id.*vastgezet/i');

        // Attempt to point approved_version_id at a different version
        $this->repo->updateQuote((int) $quote['id'], array(
            'approved_version_id' => (int) $v2['id'],
        ));
    }

    // -----------------------------------------------------------------------
    // TC-IMM-11: Setting approved_version_id for the first time is allowed
    // -----------------------------------------------------------------------

    public function testSettingApprovedVersionIdForFirstTimeIsAllowed(): void
    {
        ['quote' => $quote, 'version' => $version] = $this->makeQuote('sent');

        // approved_version_id is 0, so first write should succeed
        $updated = $this->repo->updateQuote((int) $quote['id'], array(
            'approved_version_id' => (int) $version['id'],
        ));

        $this->assertSame((int) $version['id'], (int) $updated['approved_version_id']);
    }

    // -----------------------------------------------------------------------
    // TC-IMM-12: Guard assertVersionCommerciallyEditable blocks approved version
    // -----------------------------------------------------------------------

    public function testGuardBlocksApprovedVersionDirectEdit(): void
    {
        ['quote' => $quote, 'version' => $version] = $this->makeQuote('accepted', true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/approved_version_id/i');

        $this->guard->assertVersionCommerciallyEditable((int) $quote['id'], (int) $version['id']);
    }

    // -----------------------------------------------------------------------
    // TC-IMM-13: Guard assertQuoteCommercialContextEditable blocks sent status
    // -----------------------------------------------------------------------

    public function testGuardBlocksCommercialEditOnSentQuote(): void
    {
        ['quote' => $quote] = $this->makeQuote('sent');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/commercieel vergrendeld.*sent/i');

        $this->guard->assertQuoteCommercialContextEditable((int) $quote['id']);
    }

    // -----------------------------------------------------------------------
    // TC-IMM-14: Guard assertQuoteAcceptsRevision allows sent (clone path)
    // -----------------------------------------------------------------------

    public function testGuardAllowsRevisionOnSentQuote(): void
    {
        ['quote' => $quote] = $this->makeQuote('sent');

        // Should NOT throw — 'sent' is not a terminal status
        $this->guard->assertQuoteAcceptsRevision((int) $quote['id']);

        // If we reach here, no exception was thrown
        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // TC-IMM-15: Draft quote is fully editable — no guard fires on saveDraft
    // -----------------------------------------------------------------------

    public function testSaveDraftOnDraftQuoteEditsInPlace(): void
    {
        ['quote' => $quote] = $this->makeQuote('draft');

        $result = $this->draft->saveDraft((int) $quote['id'], $this->draftPayload(), 1);

        $this->assertFalse((bool) $result['created_new_version'], 'Draft quotes must be edited in-place, no clone needed.');
        $this->assertCount(1, $result['lines']);
    }

    // -----------------------------------------------------------------------
    // TC-IMM-16: Drift protection — approved_version_id stays pinned after
    //            saveDraft creates a new version on a sent quote
    // -----------------------------------------------------------------------

    public function testApprovedVersionIdStaysPinnedAfterRevisionClone(): void
    {
        // Quote was sent and accepted — pin approved_version_id
        ['quote' => $quote, 'version' => $originalVersion] = $this->makeQuote('sent');
        $this->repo->updateQuote((int) $quote['id'], array('approved_version_id' => (int) $originalVersion['id']));

        // Now the status is 'sent' but approved_version_id is set.
        // saveDraft must throw because the original version IS the approved version
        // and 'sent' status triggers isFrozenForEditing → mustClone.
        // However, assertVersionCommerciallyEditable in the else-branch would fire IF we get there.
        // Actually: quote status is 'sent' → isFrozenForEditing returns true → mustClone=true → new version is created.
        // The approved_version_id on the quote remains the original one even after clone.

        $result = $this->draft->saveDraft((int) $quote['id'], $this->draftPayload(), 1);

        // Clone was created
        $this->assertTrue((bool) $result['created_new_version']);

        // approved_version_id on the updated quote must still point to the ORIGINAL version
        $updatedQuote = $this->repo->findQuote((int) $quote['id']);
        $this->assertSame(
            (int) $originalVersion['id'],
            (int) ($updatedQuote['approved_version_id'] ?? 0),
            'approved_version_id must not change after a revision clone.'
        );
    }
}
