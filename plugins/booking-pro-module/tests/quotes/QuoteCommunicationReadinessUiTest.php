<?php

declare(strict_types=1);

namespace {
    if (! function_exists('__')) {
        function __(string $text, ?string $domain = null): string
        {
            return $text;
        }
    }

    if (! function_exists('wc_get_product')) {
        function wc_get_product(int $productId)
        {
            return $GLOBALS['__test_wc_products'][$productId] ?? null;
        }
    }

    final class QuoteCommunicationUiTestProductStub
    {
        public function exists(): bool
        {
            return true;
        }

        public function is_purchasable(): bool
        {
            return true;
        }

        public function get_status(): string
        {
            return 'publish';
        }

        public function get_tax_status(): string
        {
            return 'taxable';
        }
    }
}

namespace BSP\Tests\Quotes {

use BSP\Quotes\Admin\Controller;
use BSP\Quotes\Repository\QuoteRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryQuoteRepository.php';
require_once dirname(__DIR__, 2) . '/modules/quotes/Admin/Controller.php';

final class QuoteCommunicationReadinessUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__test_wc_products'] = array();
    }

    public function testCommunicationTabSendUnavailableWhenQuoteHasZeroLines(): void
    {
        $repository = new InMemoryQuoteRepository();
        $request = $repository->createQuoteRequest(array(
            'requester_email' => 'quote@example.test',
        ));
        $version = $repository->createQuoteVersion(array(
            'quote_id' => 1,
            'version_number' => 1,
            'pricing_confidence' => 'unknown',
            'availability_confidence' => 'unknown',
        ));
        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-COMM-1',
            'quote_request_id' => (int) $request['id'],
            'current_version_id' => (int) $version['id'],
            'review_status' => 'approved',
            'send_status' => 'ready_to_send',
        ));

        $communicationState = $this->buildCommunicationState($repository, $quote, $version);

        $this->assertFalse($communicationState['proposal_send_ready']);
        $codes = array_column($communicationState['proposal_send_blockers'], 'code');
        $this->assertContains('quote_lines_missing', $codes);
        $this->assertContains('pricing_confidence_missing', $codes);
        $this->assertContains('availability_confidence_missing', $codes);

        $repository->createQuoteFollowup(array(
            'quote_id' => (int) $quote['id'],
            'followup_type' => 'manual_review',
            'status' => 'open',
            'priority' => 'high',
            'title' => 'Bouw quote commercieel uit',
        ));
        $repository->createQuoteAssumption(array(
            'quote_id' => (int) $quote['id'],
            'quote_version_id' => (int) $version['id'],
            'assumption_type' => 'uncertain_pricing',
            'status' => 'open',
            'message' => 'Prijs is een snapshot/richtinggevend voorstel en geen definitieve commerciële waarheid.',
            'blocks_send' => 1,
        ));
        $repository->createQuoteAssumption(array(
            'quote_id' => (int) $quote['id'],
            'quote_version_id' => (int) $version['id'],
            'assumption_type' => 'uncertain_availability',
            'status' => 'open',
            'message' => 'Beschikbaarheid is nog niet definitief bevestigd via de execution-validatiepad.',
            'blocks_send' => 1,
        ));

        $notice = $this->buildCommercialNoticeState($repository, $quote);
        $this->assertTrue($notice['active']);
        $this->assertSame('Deze offerte bevat nog geen commerciële regels. Voeg eerst programmaregels, producten, datum/tijd en prijs toe.', $notice['message']);
        $this->assertCount(1, $notice['followups']);
        $this->assertSame('Bouw quote commercieel uit', $notice['followups'][0]['title']);
        $this->assertCount(2, $notice['assumption_messages']);

        $assumptions = $repository->listQuoteAssumptions((int) $quote['id']);
        $this->assertSame('open', $assumptions[0]['status']);
        $this->assertSame('open', $assumptions[1]['status']);
    }

    public function testResolvingAssumptionsAloneIsNotEnoughWhenVersionConfidenceRemainsUnknown(): void
    {
        $repository = new InMemoryQuoteRepository();
        $request = $repository->createQuoteRequest(array(
            'requester_email' => 'quote@example.test',
        ));
        $version = $repository->createQuoteVersion(array(
            'quote_id' => 1,
            'version_number' => 1,
            'pricing_confidence' => 'unknown',
            'availability_confidence' => 'unknown',
        ));
        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-COMM-2',
            'quote_request_id' => (int) $request['id'],
            'current_version_id' => (int) $version['id'],
            'review_status' => 'approved',
            'send_status' => 'ready_to_send',
        ));
        $repository->createQuoteAssumption(array(
            'quote_id' => (int) $quote['id'],
            'quote_version_id' => (int) $version['id'],
            'assumption_type' => 'uncertain_pricing',
            'status' => 'resolved',
            'message' => 'resolved',
            'blocks_send' => 0,
        ));
        $repository->createQuoteAssumption(array(
            'quote_id' => (int) $quote['id'],
            'quote_version_id' => (int) $version['id'],
            'assumption_type' => 'uncertain_availability',
            'status' => 'resolved',
            'message' => 'resolved',
            'blocks_send' => 0,
        ));

        $communicationState = $this->buildCommunicationState($repository, $quote, $version);

        $this->assertFalse($communicationState['proposal_send_ready']);
        $codes = array_column($communicationState['proposal_send_blockers'], 'code');
        $this->assertNotContains('send_assumption_open', $codes);
        $this->assertContains('pricing_confidence_missing', $codes);
        $this->assertContains('availability_confidence_missing', $codes);
    }

    public function testValidQuoteShowsProposalSendAvailable(): void
    {
        $repository = new InMemoryQuoteRepository();
        $request = $repository->createQuoteRequest(array(
            'requester_email' => 'valid@example.test',
        ));
        $version = $repository->createQuoteVersion(array(
            'quote_id' => 1,
            'version_number' => 1,
            'pricing_confidence' => 'execution_verified',
            'availability_confidence' => 'confirmed',
        ));
        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-COMM-3',
            'quote_request_id' => (int) $request['id'],
            'current_version_id' => (int) $version['id'],
            'review_status' => 'approved',
            'send_status' => 'ready_to_send',
        ));
        $repository->replaceQuoteLines((int) $version['id'], array(
            array(
                'line_number' => 1,
                'product_id' => 501,
                'pricing_confidence' => 'execution_verified',
                'availability_confidence' => 'confirmed',
                'unit_amount_snapshot' => 50.0,
                'line_total_snapshot' => 200.0,
                'currency' => 'EUR',
            ),
        ));
        $GLOBALS['__test_wc_products'][501] = new \QuoteCommunicationUiTestProductStub();

        $communicationState = $this->buildCommunicationState($repository, $quote, $version);

        $this->assertTrue($communicationState['proposal_send_ready']);
        $this->assertSame(array(), $communicationState['proposal_send_blockers']);
    }

    public function testSentProposalDoesNotShowAsUnavailableToSendAgain(): void
    {
        $repository = new InMemoryQuoteRepository();
        $request = $repository->createQuoteRequest(array(
            'requester_email' => 'sent@example.test',
        ));
        $version = $repository->createQuoteVersion(array(
            'quote_id' => 1,
            'version_number' => 2,
            'pricing_confidence' => 'snapshot',
            'availability_confidence' => 'projected',
        ));
        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-COMM-SENT',
            'quote_request_id' => (int) $request['id'],
            'current_version_id' => (int) $version['id'],
            'review_status' => 'approved',
            'send_status' => 'sent_manual',
        ));
        $repository->createQuoteMessage(array(
            'quote_id' => (int) $quote['id'],
            'quote_version_id' => (int) $version['id'],
            'direction' => 'outbound',
            'message_type' => 'proposal',
            'status' => 'sent',
            'created_at' => '2026-05-11 09:43:11',
        ));

        $communicationState = $this->buildCommunicationState($repository, $quote, $version);

        $this->assertTrue($communicationState['proposal_already_sent']);
        $this->assertFalse($communicationState['proposal_send_ready']);
        $this->assertSame('Proposal sent', $communicationState['proposal_label']);
        $this->assertSame('Waiting on customer', $communicationState['thread_label']);
        $this->assertSame('Deze quote staat nog niet in ready_to_send.', $communicationState['proposal_send_block_reason']);
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     * @return array<string, mixed>
     */
    private function buildCommunicationState(InMemoryQuoteRepository $repository, array $quote, array $version): array
    {
        $inspectMethod = new \ReflectionMethod(Controller::class, 'inspectQuoteSendReadiness');
        $inspectMethod->setAccessible(true);
        $sendReadiness = $inspectMethod->invoke(null, (int) $quote['id'], $quote, $version, $repository);

        $method = new \ReflectionMethod(Controller::class, 'buildQuoteCommunicationState');
        $method->setAccessible(true);

        return $method->invoke(
            null,
            $quote,
            $version,
            $repository->listQuoteMessages((int) $quote['id']),
            $repository->listQuoteAssumptions((int) $quote['id']),
            $sendReadiness
        );
    }

    /**
     * @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    private function buildCommercialNoticeState(InMemoryQuoteRepository $repository, array $quote): array
    {
        $method = new \ReflectionMethod(Controller::class, 'buildCommercialIntakeNoticeState');
        $method->setAccessible(true);

        return $method->invoke(
            null,
            $repository->listQuoteLines((int) ($quote['current_version_id'] ?? 0)),
            $repository->listQuoteFollowups((int) $quote['id']),
            $repository->listQuoteAssumptions((int) $quote['id'])
        );
    }
}
}
