<?php

declare(strict_types=1);

namespace {
    if (! defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

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

    if (! function_exists('esc_html')) {
        function esc_html($text): string
        {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('esc_attr')) {
        function esc_attr($text): string
        {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('esc_url')) {
        function esc_url(string $url): string
        {
            return $url;
        }
    }

    if (! function_exists('sanitize_key')) {
        function sanitize_key(string $key): string
        {
            return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $key) ?? '');
        }
    }

    if (! function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return '/wp-admin/' . ltrim($path, '/');
        }
    }

    if (! function_exists('add_query_arg')) {
        function add_query_arg(array $args, string $url): string
        {
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
        }
    }

    if (! function_exists('wp_nonce_field')) {
        function wp_nonce_field(string $action, string $name = '_wpnonce', bool $referer = true, bool $display = true): string
        {
            unset($referer);
            $field = '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($action) . '">';
            if ($display) {
                echo $field;
                return '';
            }

            return $field;
        }
    }

    if (! function_exists('wp_strip_all_tags')) {
        function wp_strip_all_tags(string $text): string
        {
            return strip_tags($text);
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
use BSP\Quotes\Admin\QuoteWorkspaceRenderer;
use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteProposalSendDecisionService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryQuoteRepository.php';
require_once dirname(__DIR__, 2) . '/modules/quotes/Service/QuoteProposalSendDecisionService.php';
require_once dirname(__DIR__, 2) . '/modules/quotes/Admin/Controller.php';
require_once dirname(__DIR__, 2) . '/modules/quotes/Admin/QuoteWorkspaceRenderer.php';

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
            'proposal_title' => 'Voorstel voor jullie dag',
            'proposal_summary' => 'Een klantgericht voorstel met programma en prijs.',
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
            'proposal_title' => 'Voorstel voor jullie dag',
            'proposal_summary' => 'Een klantgericht voorstel met programma en prijs.',
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
        $this->assertSame('Deze offerte kan nog niet worden verstuurd.', $communicationState['proposal_send_block_reason']);
    }

    public function testCommunicationWorkflowPrioritizesCustomerReplyComposerAndCompactTimeline(): void
    {
        $messages = array(
            array(
                'id' => 10,
                'direction' => 'outbound',
                'message_type' => 'proposal',
                'status' => 'sent',
                'subject' => 'Voorstel Dagje Den Bosch',
                'body' => 'Beste klant, hierbij het voorstel.',
                'to_email' => 'klant@example.test',
                'sent_at' => '2026-05-11 09:00:00',
                'created_at' => '2026-05-11 09:00:00',
            ),
            array(
                'id' => 11,
                'direction' => 'inbound',
                'message_type' => 'reply',
                'status' => 'received',
                'subject' => 'Re: Voorstel Dagje Den Bosch',
                'body_summary' => 'Klant vraagt of de starttijd een uur later kan.',
                'body' => 'Kunnen we misschien om 14:00 starten in plaats van 13:00?',
                'from_email' => 'klant@example.test',
                'received_at' => '2026-05-11 11:00:00',
                'created_at' => '2026-05-11 11:00:00',
            ),
        );
        $communicationState = array(
            'proposal_label' => 'Proposal sent',
            'proposal_badge_class' => 'is-good',
            'thread_label' => 'Waiting on us',
            'thread_badge_class' => 'is-warn',
            'latest_inbound_message_id' => 11,
            'operator_action_title' => 'Reageer op de laatste klantreply',
            'operator_action_description' => 'Lees de laatste inbound reply en maak daarna een antwoorddraft.',
            'operator_action_age_label' => 'vandaag',
            'operator_action_age_badge_class' => 'is-good',
            'proposal_send_ready' => false,
            'proposal_already_sent' => true,
            'proposal_send_blockers' => array(),
            'reply_ready' => true,
            'reply_block_reason' => '',
            'latest_outbound_version_label' => 'Versie gekoppeld aan voorstelmail',
        );
        $messageDrafts = array(
            'proposal' => array(),
            'reply' => array(
                'id' => 12,
                'to_name' => 'Klant',
                'to_email' => 'klant@example.test',
                'subject' => 'Re: Voorstel Dagje Den Bosch',
                'body' => 'Dank voor je reactie. Ik controleer de latere starttijd.',
                'in_reply_to_message_id' => 11,
            ),
        );

        $html = $this->renderCommunicationWorkflow($messages, $communicationState, $messageDrafts);

        $this->assertStringContainsString('Klantreactie verwerken', $html);
        $this->assertStringContainsString('Klant heeft gereageerd', $html);
        $this->assertStringContainsString('Vat reactie samen', $html);
        $this->assertStringContainsString('Maak antwoorddraft', $html);
        $this->assertStringContainsString('Beantwoord handmatig', $html);
        $this->assertStringContainsString('Antwoord aan klant', $html);
        $this->assertStringContainsString('Verstuur antwoord', $html);
        $this->assertStringContainsString('Voorstelstatus', $html);
        $this->assertStringContainsString('Communicatiehistorie', $html);
        $this->assertStringContainsString('Klantreactie ontvangen', $html);
        $this->assertStringContainsString('Voorstel verzonden', $html);
        $this->assertStringContainsString('Geavanceerd', $html);
        $this->assertStringNotContainsString('Woo- en booking-truth', $html);
        $this->assertStringNotContainsString('OpenAI fallback', $html);
    }

    public function testCommunicationWorkflowDoesNotExposeQuickPrepareShortcut(): void
    {
        $html = $this->renderCommunicationWorkflow(
            array(),
            array(
                'proposal_label' => 'Nothing sent',
                'proposal_badge_class' => 'is-neutral',
                'thread_label' => 'No thread yet',
                'thread_badge_class' => 'is-neutral',
                'latest_inbound_message_id' => null,
                'operator_action_title' => 'Eerst voorstel versturen',
                'operator_action_description' => 'Controleer de open punten.',
                'proposal_send_ready' => false,
                'proposal_already_sent' => false,
                'proposal_send_blockers' => array(
                    array('code' => 'pricing_confidence_missing', 'label' => 'Prijs nog niet bevestigd', 'message' => 'Prijs moet nog bevestigd worden.'),
                ),
                'reply_ready' => false,
                'reply_block_reason' => 'Nog geen voorstelmail verzonden.',
                'latest_outbound_version_label' => '',
            ),
            array('proposal' => array(), 'reply' => array())
        );

        $this->assertStringNotContainsString('sbdp_quote_quick_prepare_to_send', $html);
        $this->assertStringNotContainsString('Bevestig en klaar voor verzending', $html);
        $this->assertStringContainsString('Prijs moet nog bevestigd worden.', $html);
    }

    public function testReadyProposalSendFormIsVisibleAndAnchorable(): void
    {
        $html = $this->renderCommunicationWorkflow(
            array(),
            array(
                'proposal_label' => 'Nothing sent',
                'proposal_badge_class' => 'is-neutral',
                'thread_label' => 'No thread yet',
                'thread_badge_class' => 'is-neutral',
                'latest_inbound_message_id' => null,
                'operator_action_title' => 'Voorstel versturen',
                'operator_action_description' => 'Alle controles zijn groen.',
                'proposal_send_ready' => true,
                'proposal_already_sent' => false,
                'proposal_send_blockers' => array(),
                'reply_ready' => false,
                'reply_block_reason' => 'Verstuur eerst een voorstelmail.',
                'latest_outbound_version_label' => '',
            ),
            array(
                'proposal' => array(
                    'id' => 20,
                    'subject' => 'Voorstel voor jullie dag',
                    'body' => 'Klantgerichte voorsteltekst.',
                ),
                'reply' => array(),
            )
        );

        $this->assertStringContainsString('<details class="bsp-quote-admin__advanced-panel" open>', $html);
        $this->assertStringContainsString('id="quote-proposal-send-form"', $html);
        $this->assertStringContainsString('name="action" value="sbdp_quote_send_message"', $html);
        $this->assertStringContainsString('Verstuur voorstelmail', $html);
    }

    public function testReadyQcdPrimaryActionSubmitsProposalSendInsteadOfTabLink(): void
    {
        $method = new \ReflectionMethod(QuoteWorkspaceRenderer::class, 'renderQcdDecisionBar');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(
            null,
            42,
            array(
                'id' => 42,
                'quote_reference' => 'Q-TEST',
                'status' => 'draft',
                'handoff_status' => 'not_ready',
            ),
            array(
                'preferred_date' => '2026-07-01',
                'group_size' => 10,
            ),
            array(
                'name' => 'Jeroen Schalks',
                'email' => 'js073@icloud.com',
            ),
            array(
                'version_number' => 1,
            ),
            array(
                'total_label' => 'EUR 125,00',
            ),
            array(
                'customer' => array('icon' => 'ok', 'tab' => 'dashboard', 'status' => 'Complete'),
                'program' => array('icon' => 'ok', 'tab' => 'build', 'status' => 'Complete'),
                'availability' => array('icon' => 'ok', 'tab' => 'build', 'status' => 'Bevestigd'),
                'proposal' => array('icon' => 'ok', 'tab' => 'communication', 'status' => 'Gereed'),
                'communication' => array('icon' => 'ok', 'tab' => 'communication', 'status' => 'Verzenden klaar'),
                'audit' => array('icon' => 'ok', 'tab' => 'history', 'status' => 'Gereed'),
            ),
            true,
            array('ready' => true, 'blockers' => array()),
            'execution_verified',
            'confirmed',
            array(),
            array(
                'proposal' => array(
                    'id' => 20,
                    'to_name' => 'Jeroen Schalks',
                    'to_email' => 'js073@icloud.com',
                    'subject' => 'Voorstel voor jullie dag',
                    'body' => 'Klantgerichte voorsteltekst.',
                ),
            )
        );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('name="action" value="sbdp_quote_send_message"', $html);
        $this->assertStringContainsString('name="message_type" value="proposal"', $html);
        $this->assertStringContainsString('name="draft_id" value="20"', $html);
        $this->assertStringContainsString('<button type="submit" class="button button-primary bsp-qcd__primary-btn">Voorstel versturen</button>', $html);
        $this->assertStringNotContainsString('href="/wp-admin/admin.php?page=sbdp_quotes&amp;quote_id=42&amp;workspace_tab=communication"', $html);
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
        $sendDecision = (new QuoteProposalSendDecisionService($repository))->decide((int) $quote['id']);

        $method = new \ReflectionMethod(Controller::class, 'buildQuoteCommunicationState');
        $method->setAccessible(true);

        return $method->invoke(
            null,
            $quote,
            $version,
            $repository->listQuoteMessages((int) $quote['id']),
            $repository->listQuoteAssumptions((int) $quote['id']),
            $sendReadiness,
            $sendDecision
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

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $communicationState
     * @param array<string, array<string, mixed>> $messageDrafts
     */
    private function renderCommunicationWorkflow(array $messages, array $communicationState, array $messageDrafts): string
    {
        $method = new \ReflectionMethod(QuoteWorkspaceRenderer::class, 'renderQuoteCommunicationWorkflow');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(
            null,
            1,
            array('name' => 'Klant', 'email' => 'klant@example.test'),
            array('total_label' => 'EUR 250,00'),
            $messages,
            $communicationState,
            $messageDrafts,
            null,
            true
        );

        return (string) ob_get_clean();
    }
}
}
