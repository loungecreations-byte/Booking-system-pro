<?php

declare(strict_types=1);

namespace {
    if (! function_exists('wc_get_product')) {
        function wc_get_product(int $productId)
        {
            return $GLOBALS['__test_wc_products'][$productId] ?? null;
        }
    }

    final class QuoteSendReadinessTestProductStub
    {
        public function __construct(
            private bool $purchasable = true,
            private string $status = 'publish',
            private string $taxStatus = 'taxable'
        ) {
        }

        public function exists(): bool
        {
            return true;
        }

        public function is_purchasable(): bool
        {
            return $this->purchasable;
        }

        public function get_status(): string
        {
            return $this->status;
        }

        public function get_tax_status(): string
        {
            return $this->taxStatus;
        }
    }
}

namespace BSP\Tests\Quotes {

use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteFollowupService;
use BSP\Quotes\Service\QuoteReviewService;
use BSP\Quotes\Service\QuoteSendReadinessValidator;
use BSP\Quotes\Service\QuoteSendService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryQuoteRepository.php';

final class QuoteSendReadinessValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__test_wc_products'] = array();
    }

    public function testApproveBlocksReadyToSendWhenCustomerEmailIsMissing(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $followups = new QuoteFollowupService($repository, $events);
        $review = new QuoteReviewService($repository, $events, $followups);

        $request = $repository->createQuoteRequest(array(
            'requester_email' => '',
        ));
        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-EMAIL-1',
            'quote_request_id' => (int) $request['id'],
            'current_version_id' => 1,
        ));
        $repository->createQuoteVersion(array(
            'quote_id' => (int) $quote['id'],
            'version_number' => 1,
            'pricing_confidence' => 'snapshot',
            'availability_confidence' => 'projected',
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('geldig klant e-mailadres');

        $review->approve((int) $quote['id'], 8);
    }

    public function testManualSendBlocksUnavailableWooProductForDirectCheckoutLine(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $send = new QuoteSendService($repository, $events);

        $request = $repository->createQuoteRequest(array(
            'requester_email' => 'planner@example.test',
        ));
        $version = $repository->createQuoteVersion(array(
            'quote_id' => 1,
            'version_number' => 1,
            'pricing_confidence' => 'snapshot',
            'availability_confidence' => 'projected',
            'proposal_title' => 'Voorstel voor jullie dag',
            'proposal_summary' => 'Een klantgericht voorstel met programma en prijs.',
        ));
        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-WOO-1',
            'quote_request_id' => (int) $request['id'],
            'current_version_id' => (int) $version['id'],
            'review_status' => 'approved',
            'send_status' => 'ready_to_send',
        ));

        $repository->replaceQuoteLines((int) $version['id'], array(
            array(
                'line_number' => 1,
                'product_id' => 97,
                'currency' => 'EUR',
                'pricing_confidence' => 'snapshot',
                'unit_amount_snapshot' => 24.5,
                'line_total_snapshot' => 245.0,
            ),
        ));

        $GLOBALS['__test_wc_products'][97] = new \QuoteSendReadinessTestProductStub(false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('niet beschikbaar voor directe checkout');

        $send->markSentManual((int) $quote['id'], 'manual', '', 11);
    }

    public function testManualSendRequiresApprovedReviewStatus(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $send = new QuoteSendService($repository, $events);

        $quote = $this->createReadyQuote($repository, array(
            'review_status' => 'pending_review',
            'send_status' => 'ready_to_send',
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('goedgekeurde review');

        $send->markSentManual((int) $quote['id'], 'manual', '', 11);
    }

    public function testManualSendRequiresReadyToSendStatus(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $send = new QuoteSendService($repository, $events);

        $quote = $this->createReadyQuote($repository, array(
            'review_status' => 'approved',
            'send_status' => 'not_ready',
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('niet klaar om te worden verzonden');

        $send->markSentManual((int) $quote['id'], 'manual', '', 11);
    }

    public function testManualSendBlocksInternalSystemTermsInProposalText(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $send = new QuoteSendService($repository, $events);

        $quote = $this->createReadyQuote($repository, array(
            'review_status' => 'approved',
            'send_status' => 'ready_to_send',
        ));

        $repository->createQuoteMessage(array(
            'quote_id' => (int) $quote['id'],
            'message_type' => 'proposal',
            'status' => 'draft',
            'subject' => 'Voorstel voor jullie dag',
            'body' => 'Deze mail bevat readiness en blockers en mag daarom niet naar de klant.',
            'body_summary' => '',
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('interne systeemtekst');

        $send->markSentManual((int) $quote['id'], 'manual', '', 11);
    }

    public function testManualSendMarksValidApprovedReadyQuoteAsSent(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $send = new QuoteSendService($repository, $events);

        $quote = $this->createReadyQuote($repository, array(
            'review_status' => 'approved',
            'send_status' => 'ready_to_send',
        ));

        $sent = $send->markSentManual((int) $quote['id'], 'manual', 'Verstuurd na review.', 11);

        $this->assertSame('sent_manual', $sent['send_status']);
        $this->assertSame('sent', $sent['status']);
    }

    public function testInspectReportsZeroLinesAndUnknownVersionConfidence(): void
    {
        $repository = new InMemoryQuoteRepository();
        $request = $repository->createQuoteRequest(array(
            'requester_email' => 'operator@example.test',
        ));
        $version = $repository->createQuoteVersion(array(
            'quote_id' => 1,
            'version_number' => 1,
            'pricing_confidence' => 'unknown',
            'availability_confidence' => 'unknown',
        ));
        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-READINESS-1',
            'quote_request_id' => (int) $request['id'],
            'current_version_id' => (int) $version['id'],
            'review_status' => 'approved',
            'send_status' => 'ready_to_send',
        ));

        $inspection = (new QuoteSendReadinessValidator($repository))->inspect((int) $quote['id']);

        $this->assertFalse($inspection['ready']);
        $codes = array_column($inspection['blockers'], 'code');
        $this->assertContains('quote_lines_missing', $codes);
        $this->assertContains('pricing_confidence_missing', $codes);
        $this->assertContains('availability_confidence_missing', $codes);

        $messages = implode("\n", array_column($inspection['blockers'], 'message'));
        $this->assertStringNotContainsString('ready_to_send', $messages);
        $this->assertStringNotContainsString('pricing_confidence', $messages);
        $this->assertStringNotContainsString('availability_confidence', $messages);
    }

    public function testInspectBlocksDiscountHigherThanCommercialSubtotal(): void
    {
        $repository = new InMemoryQuoteRepository();
        $request = $repository->createQuoteRequest(array(
            'requester_email' => 'operator@example.test',
        ));
        $version = $repository->createQuoteVersion(array(
            'quote_id' => 1,
            'version_number' => 1,
            'pricing_confidence' => 'snapshot',
            'availability_confidence' => 'projected',
            'pricing_snapshot_json' => array(
                'commercial_adjustments' => array(
                    'discount_amount' => 250.0,
                    'discount_label' => 'Actiekorting',
                    'currency' => 'EUR',
                ),
            ),
            'proposal_title' => 'Voorstel voor jullie dag',
            'proposal_summary' => 'Een klantgericht voorstel met programma en prijs.',
        ));
        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-DISCOUNT-1',
            'quote_request_id' => (int) $request['id'],
            'current_version_id' => (int) $version['id'],
            'review_status' => 'approved',
            'send_status' => 'ready_to_send',
        ));

        $repository->replaceQuoteLines((int) $version['id'], array(
            array(
                'line_number' => 1,
                'product_id' => 0,
                'currency' => 'EUR',
                'pricing_confidence' => 'snapshot',
                'unit_amount_snapshot' => 100.0,
                'line_total_snapshot' => 100.0,
            ),
        ));

        $inspection = (new QuoteSendReadinessValidator($repository))->inspect((int) $quote['id']);

        $this->assertFalse($inspection['ready']);
        $this->assertContains('quote_discount_exceeds_subtotal', array_column($inspection['blockers'], 'code'));
    }

    /**
     * @param array<string, mixed> $quoteOverrides
     * @return array<string, mixed>
     */
    private function createReadyQuote(InMemoryQuoteRepository $repository, array $quoteOverrides = array()): array
    {
        $request = $repository->createQuoteRequest(array(
            'requester_email' => 'operator@example.test',
        ));
        $version = $repository->createQuoteVersion(array(
            'quote_id' => 1,
            'version_number' => 1,
            'pricing_confidence' => 'snapshot',
            'availability_confidence' => 'projected',
            'proposal_title' => 'Voorstel voor jullie dag',
            'proposal_summary' => 'Een klantgericht voorstel met programma en prijs.',
        ));
        $quote = $repository->createQuote(array_merge(array(
            'quote_reference' => 'Q-SEND-GUARD',
            'quote_request_id' => (int) $request['id'],
            'current_version_id' => (int) $version['id'],
            'review_status' => 'approved',
            'send_status' => 'ready_to_send',
        ), $quoteOverrides));

        $repository->replaceQuoteLines((int) $version['id'], array(
            array(
                'line_number' => 1,
                'product_id' => 0,
                'currency' => 'EUR',
                'pricing_confidence' => 'snapshot',
                'unit_amount_snapshot' => 100.0,
                'line_total_snapshot' => 100.0,
            ),
        ));

        return $quote;
    }
}
}
