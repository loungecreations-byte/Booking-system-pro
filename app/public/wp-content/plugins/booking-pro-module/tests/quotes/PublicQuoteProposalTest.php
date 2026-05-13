<?php

declare(strict_types=1);

namespace {
    if (! function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://example.test/' . ltrim($path, '/');
        }
    }

    if (! function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://example.test/wp-admin/' . ltrim($path, '/');
        }
    }

    if (! function_exists('wp_salt')) {
        function wp_salt(string $scheme = 'auth'): string
        {
            return 'unit-test-salt-' . $scheme;
        }
    }

    if (! function_exists('wp_nonce_field')) {
        function wp_nonce_field(string $action, string $name = '_wpnonce', bool $referer = true, bool $display = true): string
        {
            unset($referer);
            $field = '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';
            if ($display) {
                echo $field;
                return '';
            }

            return $field;
        }
    }
}

namespace BSP\Tests\Quotes {

use BSP\Quotes\PublicProposalController;
use BSP\Quotes\Service\PublicQuoteProposalService;
use BSP\Quotes\Service\PublicQuoteProposalTokenService;
use BSP\Quotes\Service\QuoteEventLogger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryQuoteRepository.php';

final class PublicQuoteProposalTest extends TestCase
{
    public function testValidSentQuoteRendersCustomerProposal(): void
    {
        [$repository, $quote, $version, $token] = $this->makeSentQuote();
        $service = $this->service($repository);

        $context = $service->resolveByToken($token);
        $html = PublicProposalController::renderPage($token, $context);

        $this->assertSame((int) $quote['id'], (int) $context['quote']['id']);
        $this->assertTrue($context['actionable']);
        $this->assertStringContainsString('Rondvaart', $html);
        $this->assertStringContainsString('Akkoord geven', $html);
        $this->assertStringContainsString('Wijziging aanvragen', $html);
        $this->assertStringContainsString('Afwijzen', $html);
        $this->assertStringContainsString('Rondvaart', $html);
        $this->assertStringContainsString('EUR 240,00', $html);
        $this->assertSame('sent', (string) $quote['status']);
        $this->assertSame('Rondvaart', $repository->findQuoteVersion((int) $version['id'])['proposal_title']);
    }

    public function testDraftAndReadyToSendQuotesDoNotExposeProposalOrAccept(): void
    {
        foreach (['draft', 'ready_to_send'] as $status) {
            [$repository, , , $token] = $this->makeSentQuote($status);

            try {
                $this->service($repository)->resolveByToken($token);
                $this->fail('Expected proposal access to be blocked for ' . $status);
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('niet beschikbaar', $exception->getMessage());
            }
        }
    }

    public function testAcceptedQuoteCannotBeAcceptedAgain(): void
    {
        [$repository, , $version, $token] = $this->makeSentQuote();
        $service = $this->service($repository);

        $accepted = $service->accept($token, $this->client());
        $this->assertSame('accepted', (string) $accepted['status']);
        $this->assertSame((int) $version['id'], (int) $accepted['approved_version_id']);

        $context = $service->resolveByToken($token);
        $html = PublicProposalController::renderPage($token, $context);

        $this->assertFalse($context['actionable']);
        $this->assertStringNotContainsString('Akkoord geven', $html);

        $this->expectException(InvalidArgumentException::class);
        $service->accept($token, $this->client());
    }

    public function testExpiredDeclinedAndCancelledQuotesCannotBeAccepted(): void
    {
        foreach (['expired', 'declined', 'cancelled'] as $status) {
            [$repository, , , $token] = $this->makeSentQuote($status);

            try {
                $this->service($repository)->accept($token, $this->client());
                $this->fail('Expected accept to be blocked for ' . $status);
            } catch (InvalidArgumentException $exception) {
                $this->assertMatchesRegularExpression('/niet beschikbaar|niet opnieuw worden geaccepteerd/', $exception->getMessage());
            }
        }
    }

    public function testRevisionRequestDoesNotMutateSentVersion(): void
    {
        [$repository, $quote, $version, $token] = $this->makeSentQuote();
        $before = $repository->findQuoteVersion((int) $version['id']);

        $updated = $this->service($repository)->requestRevision($token, 'Kan de starttijd een uur later?', $this->client());
        $after = $repository->findQuoteVersion((int) $version['id']);

        $this->assertSame('revision_requested', (string) $updated['status']);
        $this->assertSame($before, $after);
        $messages = $repository->listQuoteMessages((int) $quote['id']);
        $this->assertSame('customer_revision_request', (string) end($messages)['message_type']);
    }

    public function testDeclineLogsCustomerActionWithoutWooOrHandoff(): void
    {
        [$repository, $quote, , $token] = $this->makeSentQuote();

        $updated = $this->service($repository)->decline($token, 'Past helaas niet.', $this->client());
        $context = $this->service($repository)->resolveByToken($token);
        $html = PublicProposalController::renderPage($token, $context, 'Uw afwijzing is vastgelegd.');
        $events = $repository->listQuoteEvents((int) $quote['id']);
        $last = end($events);

        $this->assertSame('declined', (string) $updated['status']);
        $this->assertFalse($context['actionable']);
        $this->assertStringContainsString('Uw afwijzing is vastgelegd.', $html);
        $this->assertStringNotContainsString('Akkoord geven', $html);
        $this->assertSame('quote_public_proposal_declined', (string) $last['event_type']);
        $this->assertSame('127.0.0.1', (string) $last['payload_json']['ip']);
        $this->assertArrayHasKey('token_id', $last['payload_json']);
        $this->assertArrayNotHasKey('token', $last['payload_json']);
    }

    public function testRevisionRequestShowsReadOnlyThankYouState(): void
    {
        [$repository, , , $token] = $this->makeSentQuote();

        $this->service($repository)->requestRevision($token, 'Graag later starten.', $this->client());
        $context = $this->service($repository)->resolveByToken($token);
        $html = PublicProposalController::renderPage($token, $context, 'Dank u wel. Uw wijzigingsverzoek is ontvangen.');

        $this->assertFalse($context['actionable']);
        $this->assertStringContainsString('Dank u wel. Uw wijzigingsverzoek is ontvangen.', $html);
        $this->assertStringContainsString('Wijziging ontvangen', $html);
        $this->assertStringNotContainsString('Akkoord geven', $html);
    }

    public function testCustomerPageContainsNoTechnicalTermsOrWooExecutionActions(): void
    {
        [$repository, , , $token] = $this->makeSentQuote();
        $html = PublicProposalController::renderPage($token, $this->service($repository)->resolveByToken($token));

        foreach ([
            'approved_version_id',
            'current_version_id',
            'pricing_confidence',
            'availability_confidence',
            'execution-status',
            'handoff',
            'Woo',
            'checkout',
            'cart',
            'JSON',
            'payload',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function testInvalidTokenShowsSafeUnavailableMessage(): void
    {
        $service = $this->service(new InMemoryQuoteRepository());

        $this->expectException(InvalidArgumentException::class);
        $service->resolveByToken('invalid-token');
    }

    public function testSentQuoteWithoutSentProposalMessageIsNotPubliclyViewable(): void
    {
        [$repository, $quote, $version] = $this->makeSentQuote();
        foreach ($repository->listQuoteMessages((int) $quote['id']) as $message) {
            $repository->updateQuoteMessage((int) $message['id'], array('status' => 'draft'));
        }
        $token = (new PublicQuoteProposalTokenService())->create((int) $quote['id'], (int) $version['id'], (string) $quote['quote_reference']);

        $this->expectException(InvalidArgumentException::class);
        $this->service($repository)->resolveByToken($token);
    }

    /**
     * @return array{0:InMemoryQuoteRepository,1:array<string,mixed>,2:array<string,mixed>,3:string}
     */
    private function makeSentQuote(string $status = 'sent'): array
    {
        $repository = new InMemoryQuoteRepository();
        $request = $repository->createQuoteRequest(array(
            'request_summary' => 'Een compacte dag in Den Bosch.',
            'requester_email' => 'klant@example.test',
            'group_size' => 6,
            'preferred_date' => '2026-07-18',
        ));
        $version = $repository->createQuoteVersion(array(
            'quote_id' => 1,
            'version_number' => 1,
            'status' => 'sent',
            'proposal_title' => 'Rondvaart',
            'proposal_summary' => 'Een ontspannen programma met rondvaart en borrel.',
        ));
        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-PUBLIC-1',
            'quote_request_id' => (int) $request['id'],
            'current_version_id' => (int) $version['id'],
            'status' => $status,
            'review_status' => 'approved',
            'send_status' => 'sent_manual',
            'approved_version_id' => $status === 'accepted' ? (int) $version['id'] : 0,
        ));
        $repository->replaceQuoteLines((int) $version['id'], array(
            array(
                'line_number' => 1,
                'sort_order' => 1,
                'title' => 'Rondvaart',
                'proposed_start_time' => '13:00',
                'proposed_end_time' => '14:00',
                'line_total_snapshot' => 240.0,
                'currency' => 'EUR',
                'selected_option_labels_json' => array('Schipper', 'Route centrum'),
            ),
        ));
        $repository->createQuoteMessage(array(
            'quote_id' => (int) $quote['id'],
            'quote_version_id' => (int) $version['id'],
            'direction' => 'outbound',
            'message_type' => 'proposal',
            'status' => 'sent',
            'channel' => 'email',
            'sent_at' => '2026-05-13 12:00:00',
        ));

        $token = (new PublicQuoteProposalTokenService())->create((int) $quote['id'], (int) $version['id'], (string) $quote['quote_reference']);

        return [$repository, $quote, $version, $token];
    }

    private function service(InMemoryQuoteRepository $repository): PublicQuoteProposalService
    {
        return new PublicQuoteProposalService(
            $repository,
            new QuoteEventLogger($repository),
            new PublicQuoteProposalTokenService()
        );
    }

    /**
     * @return array{ip:string,user_agent:string}
     */
    private function client(): array
    {
        return array('ip' => '127.0.0.1', 'user_agent' => 'PHPUnit');
    }
}
}
