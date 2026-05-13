<?php

declare(strict_types=1);

namespace {
    if (! function_exists('__')) {
        function __(string $text, ?string $domain = null): string
        {
            return $text;
        }
    }

    if (! function_exists('_n')) {
        function _n(string $single, string $plural, int $number, ?string $domain = null): string
        {
            unset($domain);
            return $number === 1 ? $single : $plural;
        }
    }

    if (! function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://admin.example.test/' . ltrim($path, '/');
        }
    }

    if (! function_exists('sanitize_key')) {
        function sanitize_key(string $key): string
        {
            return strtolower(preg_replace('/[^a-zA-Z0-9_\\-]/', '', $key) ?? '');
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
use BSP\Quotes\Admin\QuoteWorkspaceRenderer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryQuoteRepository.php';
require_once dirname(__DIR__, 2) . '/modules/quotes/Admin/Controller.php';
require_once dirname(__DIR__, 2) . '/modules/quotes/Admin/QuoteWorkspaceRenderer.php';

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

    public function testWorkspaceNextActionPrioritizesPricingBeforeCustomerReply(): void
    {
        $method = new \ReflectionMethod(QuoteWorkspaceRenderer::class, 'buildQuoteWorkspaceState');
        $method->setAccessible(true);

        $state = $method->invoke(
            null,
            array(
                'review_status' => 'approved',
                'send_status' => 'ready_to_send',
                'handoff_status' => 'not_ready',
            ),
            array(
                'id' => 9,
                'pricing_confidence' => 'snapshot',
                'availability_confidence' => 'confirmed',
            ),
            array(
                array(
                    'product_id' => 501,
                    'pricing_confidence' => 'snapshot',
                    'availability_confidence' => 'confirmed',
                    'service_date' => '2026-06-01',
                    'proposed_start_time' => '13:00',
                ),
            ),
            array(),
            array(),
            array(
                'thread_label' => 'Waiting on us',
                'latest_inbound_message_id' => 44,
            ),
            array(
                'ready' => false,
                'blockers' => array(
                    array('code' => 'pricing_confidence_missing', 'message' => 'raw'),
                ),
            ),
            array('ready' => true)
        );

        $this->assertSame('Prijs bevestigen', $state['next_action']['title']);
        $this->assertSame('build', $state['next_action']['cta']);
        $this->assertNotSame('Verwerk klantreactie', $state['next_action']['title']);
        $this->assertStringContainsString('prijs nog niet definitief is bevestigd', $state['readiness_description']);
    }

    public function testOverviewChecksUseHumanLabelsWhenBlocked(): void
    {
        $method = new \ReflectionMethod(QuoteWorkspaceRenderer::class, 'buildWorkspaceSendCheckItems');
        $method->setAccessible(true);

        $items = $method->invoke(
            null,
            3,
            3,
            'snapshot',
            'unknown',
            false,
            array(
                'ready' => false,
                'blockers' => array(
                    array('code' => 'pricing_confidence_missing'),
                    array('code' => 'availability_confidence_missing'),
                    array('code' => 'review_not_approved'),
                    array('code' => 'send_status_not_ready'),
                ),
            ),
            array(
                'thread_label' => 'Waiting on us',
                'latest_inbound_message_id' => 11,
            )
        );

        $text = implode(' ', array_map(
            static fn (array $item): string => (string) $item['label'] . ' ' . (string) $item['detail'],
            $items
        ));

        $this->assertStringContainsString('Prijs: richtprijs', $text);
        $this->assertStringContainsString('Beschikbaarheid: nog bevestigen', $text);
        $this->assertStringContainsString('Versturen geblokkeerd', $text);
        $this->assertStringContainsString('4 punten open', $text);
        $this->assertStringNotContainsString('Verzendklaar', $text);
        $this->assertStringNotContainsString('Prijzen bevestigd', $text);
        $this->assertStringNotContainsString('pricing_confidence', $text);
        $this->assertStringNotContainsString('ready_to_send', $text);
        $this->assertStringNotContainsString('execution layer', $text);
    }

    public function testPrimaryActionDoesNotOfferSendWhenReadyToSendHasBlockers(): void
    {
        $method = new \ReflectionMethod(QuoteWorkspaceRenderer::class, 'resolveQuotePrimaryAction');
        $method->setAccessible(true);

        $action = $method->invoke(
            null,
            array(
                'status' => 'draft',
                'review_status' => 'approved',
                'send_status' => 'ready_to_send',
                'handoff_status' => 'not_ready',
            ),
            array(
                'blockers' => array('Prijs nog niet bevestigd.'),
                'next_action' => array('cta' => 'send_mark_sent', 'title' => 'Offerte versturen'),
            ),
            false,
            false
        );

        $this->assertSame('tab_link', $action['cta']);
        $this->assertSame('dashboard', $action['tab']);
        $this->assertSame('quote-blockers-card', $action['anchor']);
        $this->assertSame('Blockers oplossen', $action['title']);
    }

    public function testHandoffActionOnlyAppearsForAcceptedQuoteWithApprovedVersion(): void
    {
        $method = new \ReflectionMethod(QuoteWorkspaceRenderer::class, 'resolveQuotePrimaryAction');
        $method->setAccessible(true);

        $withoutApprovedVersion = $method->invoke(
            null,
            array('status' => 'accepted', 'approved_version_id' => 0),
            array('blockers' => array(), 'next_action' => array()),
            false,
            false
        );
        $withApprovedVersion = $method->invoke(
            null,
            array('status' => 'accepted', 'approved_version_id' => 12),
            array('blockers' => array(), 'next_action' => array()),
            false,
            true
        );

        $this->assertSame('history', $withoutApprovedVersion['tab']);
        $this->assertSame('Bekijk audit', $withoutApprovedVersion['label']);
        $this->assertSame('handoff', $withApprovedVersion['tab']);
        $this->assertSame('Open handoff', $withApprovedVersion['label']);
    }

    public function testWorkflowTabNormalizesToDashboard(): void
    {
        $method = new \ReflectionMethod(QuoteWorkspaceRenderer::class, 'normalizeWorkspaceTab');
        $method->setAccessible(true);

        $this->assertSame('dashboard', $method->invoke(null, 'workflow', 'dashboard'));
        $this->assertSame('handoff', $method->invoke(null, 'handoff', 'dashboard'));
    }

    public function testWorkflowRendererBranchIsRemoved(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/quotes/Admin/QuoteWorkspaceRenderer.php');

        $this->assertStringNotContainsString("\$currentTab === 'workflow'", $source);
        $this->assertStringNotContainsString('id="quote-workflow"', $source);
        $this->assertStringNotContainsString("'workflow'", $this->extractAllowedTabsSource($source));
    }

    public function testTerminalStatusesUseAuditActionAndHumanLabels(): void
    {
        $actionMethod = new \ReflectionMethod(QuoteWorkspaceRenderer::class, 'resolveQuotePrimaryAction');
        $actionMethod->setAccessible(true);
        $labelMethod = new \ReflectionMethod(QuoteWorkspaceRenderer::class, 'humanQuoteStatusLabel');
        $labelMethod->setAccessible(true);

        $expiredAction = $actionMethod->invoke(
            null,
            array('status' => 'expired', 'review_status' => 'approved', 'send_status' => 'not_ready'),
            array('blockers' => array(), 'next_action' => array()),
            false,
            false
        );

        $this->assertSame('history', $expiredAction['tab']);
        $this->assertSame('Bekijk audit', $expiredAction['label']);
        $this->assertSame('Verlopen', $labelMethod->invoke(null, array('status' => 'expired')));
        $this->assertSame('Afgesloten', $labelMethod->invoke(null, array('status' => 'declined')));
        $this->assertSame('Revisie gevraagd', $labelMethod->invoke(null, array('status' => 'revision_requested')));
    }

    private function extractAllowedTabsSource(string $source): string
    {
        $start = strpos($source, '$allowedTabs = array(');
        $this->assertNotFalse($start);
        $end = strpos($source, ');', (int) $start);
        $this->assertNotFalse($end);

        return substr($source, (int) $start, ((int) $end - (int) $start) + 2);
    }
}
}
