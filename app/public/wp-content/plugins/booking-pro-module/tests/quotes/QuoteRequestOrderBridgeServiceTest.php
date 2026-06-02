<?php

declare(strict_types=1);

namespace BSP\Tests\Quotes;

use BSP\Quotes\Service\HandoffValidationException;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteRequestOrderBridgeService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryQuoteRepository.php';

final class QuoteRequestOrderBridgeServiceTest extends TestCase
{
    public function testThrowsNotFoundCodeWhenQuoteIsMissing(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteRequestOrderBridgeService($repository, $events);

        try {
            $service->createWooRequestOrder(99999);
            $this->fail('Expected HandoffValidationException was not thrown.');
        } catch (HandoffValidationException $exception) {
            $this->assertSame('bsp_quotes_handoff_quote_not_found', $exception->restCode());
            $this->assertSame(404, $exception->status());
        }
    }

    public function testThrowsMissingRequestCodeWhenQuoteHasNoRequest(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteRequestOrderBridgeService($repository, $events);

        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-TEST-1',
            'current_version_id' => 10,
            'quote_request_id' => 0,
        ));

        try {
            $service->createWooRequestOrder((int) $quote['id']);
            $this->fail('Expected HandoffValidationException was not thrown.');
        } catch (HandoffValidationException $exception) {
            $this->assertSame('bsp_quotes_handoff_missing_request', $exception->restCode());
            $this->assertSame(422, $exception->status());
        }
    }

    public function testThrowsMissingVersionCodeWhenQuoteHasNoCurrentVersion(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteRequestOrderBridgeService($repository, $events);

        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-TEST-2',
            'quote_request_id' => 1,
            'current_version_id' => 0,
        ));

        try {
            $service->createWooRequestOrder((int) $quote['id']);
            $this->fail('Expected HandoffValidationException was not thrown.');
        } catch (HandoffValidationException $exception) {
            $this->assertSame('bsp_quotes_handoff_missing_version', $exception->restCode());
            $this->assertSame(422, $exception->status());
        }
    }

    public function testAcceptedQuoteFailsHardWhenApprovedVersionIsMissing(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteRequestOrderBridgeService($repository, $events);

        $version = $repository->createQuoteVersion(array(
            'quote_id' => 21,
            'handoff_payload_json' => array(
                'execution_adapter' => array(
                    'adapter_type' => 'cart_order_prep',
                    'items' => array(
                        array(
                            'product_id' => 352,
                            'start' => '2026-07-16T14:00:00+00:00',
                            'end' => '2026-07-16T16:00:00+00:00',
                            'participants' => 8,
                        ),
                    ),
                ),
            ),
        ));

        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-TEST-ACCEPTED-MISSING-APPROVED',
            'quote_request_id' => 61,
            'status' => 'accepted',
            'current_version_id' => (int) $version['id'],
            'approved_version_id' => 0,
        ));

        try {
            $service->createWooRequestOrder((int) $quote['id']);
            $this->fail('Expected HandoffValidationException was not thrown.');
        } catch (HandoffValidationException $exception) {
            $this->assertSame('bsp_quotes_handoff_missing_approved_version', $exception->restCode());
            $this->assertSame(422, $exception->status());
        }
    }

    public function testThrowsInvalidAdapterTypeCodeForUnsupportedPayload(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteRequestOrderBridgeService($repository, $events);

        $version = $repository->createQuoteVersion(array(
            'quote_id' => 12,
            'handoff_payload_json' => array(
                'execution_adapter' => array(
                    'adapter_type' => 'unknown_adapter',
                    'items' => array(),
                ),
            ),
        ));

        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-TEST-3',
            'quote_request_id' => 55,
            'current_version_id' => (int) $version['id'],
        ));

        try {
            $service->createWooRequestOrder((int) $quote['id']);
            $this->fail('Expected HandoffValidationException was not thrown.');
        } catch (HandoffValidationException $exception) {
            $this->assertSame('bsp_quotes_handoff_invalid_adapter_type', $exception->restCode());
            $this->assertSame(422, $exception->status());
            $this->assertArrayHasKey('adapter_type', $exception->context());
        }
    }

    public function testThrowsMissingItemsCodeForEmptyExecutionItems(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteRequestOrderBridgeService($repository, $events);

        $version = $repository->createQuoteVersion(array(
            'quote_id' => 13,
            'handoff_payload_json' => array(
                'execution_adapter' => array(
                    'adapter_type' => 'cart_order_prep',
                    'items' => array(),
                    'request_context' => array('group_size' => 4),
                ),
            ),
        ));

        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-TEST-4',
            'quote_request_id' => 56,
            'current_version_id' => (int) $version['id'],
        ));

        try {
            $service->createWooRequestOrder((int) $quote['id']);
            $this->fail('Expected HandoffValidationException was not thrown.');
        } catch (HandoffValidationException $exception) {
            $this->assertSame('bsp_quotes_handoff_missing_items', $exception->restCode());
            $this->assertSame(422, $exception->status());
        }
    }
}
