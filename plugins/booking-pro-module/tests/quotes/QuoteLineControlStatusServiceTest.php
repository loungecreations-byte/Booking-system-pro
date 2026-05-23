<?php

declare(strict_types=1);

namespace BSP\Tests\Quotes;

use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteLineControlStatusService;
use BSP\Quotes\Service\QuoteSendReadinessValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryQuoteRepository.php';

final class QuoteLineControlStatusServiceTest extends TestCase
{
    public function testAdminCanConfirmLinePriceAndAvailabilityWithAuditEvent(): void
    {
        [$repository, $quote, $version, $line] = $this->makeDraftQuote();
        $service = new QuoteLineControlStatusService($repository, new QuoteEventLogger($repository));

        $service->updateStatus((int) $quote['id'], (int) $line['id'], 'pricing', 'confirmed', 42);
        $service->updateStatus((int) $quote['id'], (int) $line['id'], 'availability', 'confirmed', 42);

        $updatedLine = $repository->findQuoteLine((int) $line['id']);
        $updatedVersion = $repository->findQuoteVersion((int) $version['id']);
        $events = $repository->listQuoteEvents((int) $quote['id']);

        $this->assertSame('execution_verified', $updatedLine['pricing_confidence']);
        $this->assertSame('confirmed', $updatedLine['availability_confidence']);
        $this->assertSame('confirmed', $updatedLine['pricing_snapshot_json']['control_status']);
        $this->assertSame('confirmed', $updatedLine['availability_snapshot_json']['control_status']);
        $this->assertSame('execution_verified', $updatedVersion['pricing_confidence']);
        $this->assertSame('confirmed', $updatedVersion['availability_confidence']);
        $this->assertCount(2, $events);
        $this->assertSame('quote_program_line_updated', $events[0]['event_type']);
        $this->assertSame('quote_line_availability_updated', $events[1]['event_type']);
        $this->assertSame((int) $quote['id'], $events[1]['payload_json']['quote_id']);
        $this->assertSame((int) $version['id'], $events[1]['payload_json']['quote_version_id']);
        $this->assertSame((int) $line['id'], $events[1]['payload_json']['line_id']);
        $this->assertSame('needs_check', $events[1]['payload_json']['old_status']);
        $this->assertSame('confirmed', $events[1]['payload_json']['new_status']);
        $this->assertSame(42, $events[1]['payload_json']['admin_user_id']);
    }

    public function testConfirmedStatusesResolveSendReadinessBlockers(): void
    {
        [$repository, $quote, , $line] = $this->makeDraftQuote();
        $readiness = new QuoteSendReadinessValidator($repository);
        $service = new QuoteLineControlStatusService($repository, new QuoteEventLogger($repository));

        $this->assertFalse($readiness->inspect((int) $quote['id'])['ready']);

        $service->updateStatus((int) $quote['id'], (int) $line['id'], 'pricing', 'confirmed', 7);
        $service->updateStatus((int) $quote['id'], (int) $line['id'], 'availability', 'confirmed', 7);

        $this->assertTrue($readiness->inspect((int) $quote['id'])['ready']);
    }

    public function testUnavailableAvailabilityRemainsHardBlocker(): void
    {
        [$repository, $quote, , $line] = $this->makeDraftQuote();
        $service = new QuoteLineControlStatusService($repository, new QuoteEventLogger($repository));

        $service->updateStatus((int) $quote['id'], (int) $line['id'], 'pricing', 'confirmed', 7);
        $service->updateStatus((int) $quote['id'], (int) $line['id'], 'availability', 'unavailable', 7);

        $updatedLine = $repository->findQuoteLine((int) $line['id']);
        $readiness = (new QuoteSendReadinessValidator($repository))->inspect((int) $quote['id']);

        $this->assertSame('unavailable', $updatedLine['line_status']);
        $this->assertFalse($readiness['ready']);
        $this->assertSame('availability_confidence_missing', $readiness['blockers'][0]['code']);
    }

    public function testSentQuoteCannotMutateLineControlStatus(): void
    {
        [$repository, $quote, , $line] = $this->makeDraftQuote();
        $repository->updateQuote((int) $quote['id'], array('status' => 'sent'));
        $service = new QuoteLineControlStatusService($repository, new QuoteEventLogger($repository));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('commercieel vergrendeld');

        $service->updateStatus((int) $quote['id'], (int) $line['id'], 'pricing', 'confirmed', 7);
    }

    /**
     * @return array{0:InMemoryQuoteRepository,1:array<string,mixed>,2:array<string,mixed>,3:array<string,mixed>}
     */
    private function makeDraftQuote(): array
    {
        $repository = new InMemoryQuoteRepository();
        $request = $repository->createQuoteRequest(array(
            'requester_email' => 'klant@example.test',
        ));
        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-LINE-CONTROL',
            'quote_request_id' => (int) $request['id'],
            'current_version_id' => 0,
            'status' => 'draft',
            'review_status' => 'approved',
            'send_status' => 'not_ready',
            'approved_version_id' => 0,
        ));
        $version = $repository->createQuoteVersion(array(
            'quote_id' => (int) $quote['id'],
            'version_number' => 1,
            'pricing_confidence' => 'unknown',
            'availability_confidence' => 'unknown',
        ));
        $quote = $repository->updateQuote((int) $quote['id'], array('current_version_id' => (int) $version['id']));
        $lines = $repository->replaceQuoteLines((int) $version['id'], array(
            array(
                'line_number' => 1,
                'sort_order' => 1,
                'line_status' => 'directional',
                'title' => 'Rondvaart',
                'product_id' => 0,
                'participants' => 10,
                'service_date' => '2026-06-01',
                'proposed_start_time' => '10:00',
                'proposed_end_time' => '11:00',
                'pricing_confidence' => 'unknown',
                'availability_confidence' => 'unknown',
                'unit_amount_snapshot' => 24.0,
                'line_total_snapshot' => 240.0,
                'currency' => 'EUR',
                'pricing_snapshot_json' => array('control_status' => 'needs_check'),
                'availability_snapshot_json' => array('control_status' => 'needs_check'),
            ),
        ));

        return array($repository, $quote, $version, $lines[0]);
    }
}
