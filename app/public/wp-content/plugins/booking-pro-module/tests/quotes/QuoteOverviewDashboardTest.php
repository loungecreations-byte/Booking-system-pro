<?php

declare(strict_types=1);

namespace {
    if (! function_exists('__')) {
        function __(string $text, ?string $domain = null): string
        {
            return $text;
        }
    }

    if (! function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://admin.example.test/' . ltrim($path, '/');
        }
    }

    if (! function_exists('add_query_arg')) {
        function add_query_arg(array $args, string $url = ''): string
        {
            return rtrim($url, '?') . '?' . http_build_query($args);
        }
    }
}

namespace BSP\Tests\Quotes {

use BSP\Quotes\Admin\Controller;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryQuoteRepository.php';
require_once dirname(__DIR__, 2) . '/modules/quotes/Admin/Controller.php';

final class QuoteOverviewDashboardTest extends TestCase
{
    public function testOverviewRowsPrioritizeActionThenAssumptionsThenReady(): void
    {
        $repository = new InMemoryQuoteRepository();

        $blockedRequest = $repository->createQuoteRequest(array(
            'requester_name' => 'Blocked',
            'requester_email' => '',
            'group_size' => 20,
            'preferred_date' => gmdate('Y-m-d', strtotime('+10 days') ?: time()),
        ));
        $blockedVersion = $repository->createQuoteVersion(array(
            'quote_id' => 1,
            'version_number' => 1,
            'pricing_confidence' => 'unknown',
            'availability_confidence' => 'unknown',
        ));
        $repository->createQuote(array(
            'quote_reference' => 'Q-ACTION',
            'quote_request_id' => (int) $blockedRequest['id'],
            'current_version_id' => (int) $blockedVersion['id'],
            'status' => 'draft',
            'review_status' => 'not_started',
            'send_status' => 'not_ready',
            'handoff_status' => 'not_ready',
            'updated_at' => '2026-05-11 10:00:00',
        ));

        $assumptionRequest = $repository->createQuoteRequest(array(
            'requester_name' => 'Assumption',
            'requester_email' => 'assumption@example.test',
            'group_size' => 20,
            'preferred_date' => gmdate('Y-m-d', strtotime('+20 days') ?: time()),
        ));
        $assumptionVersion = $repository->createQuoteVersion(array(
            'quote_id' => 2,
            'version_number' => 1,
            'pricing_confidence' => 'execution_verified',
            'availability_confidence' => 'confirmed',
        ));
        $assumptionQuote = $repository->createQuote(array(
            'quote_reference' => 'Q-ASSUMPTION',
            'quote_request_id' => (int) $assumptionRequest['id'],
            'current_version_id' => (int) $assumptionVersion['id'],
            'status' => 'draft',
            'review_status' => 'approved',
            'send_status' => 'ready_to_send',
            'handoff_status' => 'not_ready',
            'updated_at' => '2026-05-11 11:00:00',
        ));
        $repository->replaceQuoteLines((int) $assumptionVersion['id'], array(
            array(
                'line_number' => 1,
                'product_id' => 0,
                'pricing_confidence' => 'execution_verified',
                'availability_confidence' => 'confirmed',
                'unit_amount_snapshot' => 100.0,
                'line_total_snapshot' => 225.0,
                'currency' => 'EUR',
            ),
        ));
        $repository->createQuoteAssumption(array(
            'quote_id' => (int) $assumptionQuote['id'],
            'quote_version_id' => (int) $assumptionVersion['id'],
            'assumption_type' => 'uncertain_pricing',
            'status' => 'open',
            'message' => 'Prijs moet bevestigd worden.',
            'blocks_send' => 1,
        ));

        $readyRequest = $repository->createQuoteRequest(array(
            'requester_name' => 'Ready',
            'requester_email' => 'ready@example.test',
            'group_size' => 20,
            'preferred_date' => gmdate('Y-m-d', strtotime('+30 days') ?: time()),
        ));
        $readyVersion = $repository->createQuoteVersion(array(
            'quote_id' => 3,
            'version_number' => 1,
            'pricing_confidence' => 'execution_verified',
            'availability_confidence' => 'confirmed',
        ));
        $repository->createQuote(array(
            'quote_reference' => 'Q-READY',
            'quote_request_id' => (int) $readyRequest['id'],
            'current_version_id' => (int) $readyVersion['id'],
            'status' => 'draft',
            'review_status' => 'approved',
            'send_status' => 'ready_to_send',
            'handoff_status' => 'not_ready',
            'updated_at' => '2026-05-11 12:00:00',
        ));
        $repository->replaceQuoteLines((int) $readyVersion['id'], array(
            array(
                'line_number' => 1,
                'product_id' => 0,
                'pricing_confidence' => 'execution_verified',
                'availability_confidence' => 'confirmed',
                'unit_amount_snapshot' => 100.0,
                'line_total_snapshot' => 225.0,
                'currency' => 'EUR',
            ),
        ));

        $method = new \ReflectionMethod(Controller::class, 'buildQuoteOverviewRows');
        $method->setAccessible(true);
        $rows = $method->invoke(null, $repository, $repository->listQuotes());

        $this->assertSame(array('action', 'assumptions', 'ready'), array_column($rows, 'category'));
        $this->assertSame('Q-ACTION', $rows[0]['quote']['quote_reference']);
        $this->assertSame('Q-ASSUMPTION', $rows[1]['quote']['quote_reference']);
        $this->assertSame('Q-READY', $rows[2]['quote']['quote_reference']);
    }
}
}
