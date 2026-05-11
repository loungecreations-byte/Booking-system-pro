<?php

declare(strict_types=1);

namespace BSP\Tests\Quotes;

use BSP\Quotes\Service\QuoteBusinessRuleValidator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryQuoteRepository.php';

final class QuoteBusinessRuleValidatorTest extends TestCase
{
    public function testValidatorReportsMissingCommercialLinesAndCustomerContext(): void
    {
        $repository = new InMemoryQuoteRepository();
        $request = $repository->createQuoteRequest(array(
            'requester_name' => '',
            'requester_email' => 'invalid-email',
            'group_size' => 0,
            'preferred_date' => '2020-01-01',
        ));
        $version = $repository->createQuoteVersion(array(
            'quote_id' => 1,
            'version_number' => 1,
        ));
        $quote = $repository->createQuote(array(
            'quote_request_id' => (int) $request['id'],
            'current_version_id' => (int) $version['id'],
        ));

        $validation = (new QuoteBusinessRuleValidator($repository))->validateComplete((int) $quote['id']);

        $this->assertFalse($validation['valid']);
        $codes = array_column($validation['violations'], 'code');
        $this->assertContains('no_program', $codes);
        $this->assertContains('no_customer', $codes);
        $this->assertContains('date_invalid', $codes);
        $this->assertContains('group_size_unusual', $codes);
    }

    public function testValidatorAcceptsOperationallyValidQuote(): void
    {
        $repository = new InMemoryQuoteRepository();
        $request = $repository->createQuoteRequest(array(
            'requester_name' => 'Codex QA',
            'requester_email' => 'codex@example.test',
            'group_size' => 10,
            'preferred_date' => gmdate('Y-m-d', strtotime('+7 days') ?: time()),
        ));
        $version = $repository->createQuoteVersion(array(
            'quote_id' => 1,
            'version_number' => 1,
            'pricing_confidence' => 'execution_verified',
            'availability_confidence' => 'confirmed',
        ));
        $quote = $repository->createQuote(array(
            'quote_request_id' => (int) $request['id'],
            'current_version_id' => (int) $version['id'],
        ));
        $repository->replaceQuoteLines((int) $version['id'], array(
            array(
                'line_number' => 1,
                'product_id' => 501,
                'line_total_snapshot' => 225.0,
                'currency' => 'EUR',
            ),
        ));

        $validation = (new QuoteBusinessRuleValidator($repository))->validateComplete((int) $quote['id']);

        $this->assertTrue($validation['valid']);
        $this->assertSame(0, $validation['error_count']);
    }
}
