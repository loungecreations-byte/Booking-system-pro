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
use BSP\Quotes\Service\QuoteAcceptedDocumentService;
use BSP\Quotes\Service\QuoteAdminStatusSummaryService;
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
        $this->assertStringContainsString('name="acceptance_name"', $html);
        $this->assertStringContainsString('name="acceptance_email"', $html);
        $this->assertStringContainsString('name="acceptance_terms_checked"', $html);
        $this->assertStringContainsString('Ik ga akkoord met het programma, de prijsopbouw en de geldende voorwaarden.', $html);
        $this->assertStringContainsString('Wijziging aanvragen', $html);
        $this->assertStringContainsString('Afwijzen', $html);
        $this->assertStringContainsString('Voorgestelde planning', $html);
        $this->assertStringContainsString('Kostenoverzicht', $html);
        $this->assertStringContainsString('Inbegrepen', $html);
        $this->assertStringContainsString('Niet inclusief', $html);
        $this->assertStringContainsString('Geldigheid en bevestiging', $html);
        $this->assertStringContainsString('In voorstel opgenomen', $html);
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

        $accepted = $service->accept($token, $this->client(), $this->acceptance());
        $this->assertSame('accepted', (string) $accepted['status']);
        $this->assertSame((int) $version['id'], (int) $accepted['approved_version_id']);

        $context = $service->resolveByToken($token);
        $html = PublicProposalController::renderPage($token, $context);

        $this->assertFalse($context['actionable']);
        $this->assertStringNotContainsString('Akkoord geven', $html);

        $this->expectException(InvalidArgumentException::class);
        $service->accept($token, $this->client(), $this->acceptance());
    }

    public function testAcceptWithoutNameFails(): void
    {
        [$repository, , , $token] = $this->makeSentQuote();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vul uw naam in');
        $this->service($repository)->accept($token, $this->client(), $this->acceptance(array('acceptance_name' => '')));
    }

    public function testAcceptWithoutEmailFails(): void
    {
        [$repository, , , $token] = $this->makeSentQuote();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vul een geldig e-mailadres');
        $this->service($repository)->accept($token, $this->client(), $this->acceptance(array('acceptance_email' => '')));
    }

    public function testAcceptWithInvalidEmailFails(): void
    {
        [$repository, , , $token] = $this->makeSentQuote();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vul een geldig e-mailadres');
        $this->service($repository)->accept($token, $this->client(), $this->acceptance(array('acceptance_email' => 'geen-email')));
    }

    public function testAcceptWithoutTermsCheckboxFails(): void
    {
        [$repository, , , $token] = $this->makeSentQuote();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bevestig dat u akkoord gaat');
        $this->service($repository)->accept($token, $this->client(), $this->acceptance(array('acceptance_terms_checked' => '')));
    }

    public function testAcceptStoresLegalAcceptancePayloadAndHashes(): void
    {
        [$repository, $quote, $version, $token] = $this->makeSentQuote();

        $accepted = $this->service($repository)->accept($token, $this->client(), $this->acceptance());
        $publicPayload = $this->eventPayload($repository, (int) $quote['id'], 'quote_public_proposal_accepted');
        $acceptedPayload = $this->eventPayload($repository, (int) $quote['id'], 'quote_accepted');

        $this->assertSame('accepted', (string) $accepted['status']);
        $this->assertSame((int) $version['id'], (int) $publicPayload['approved_version_id']);
        $this->assertSame('Test Akkoordgever', $publicPayload['acceptance_name']);
        $this->assertSame('akkoord@example.test', $publicPayload['acceptance_email']);
        $this->assertSame('TEST-BSP BV', $publicPayload['acceptance_company']);
        $this->assertSame('Inkoper', $publicPayload['acceptance_role']);
        $this->assertTrue($publicPayload['terms_checked']);
        $this->assertSame('ddb-terms-test', $publicPayload['terms_version']);
        $this->assertSame('127.0.0.1', $publicPayload['ip_address']);
        $this->assertSame('PHPUnit', $publicPayload['user_agent']);
        $this->assertNotSame('', (string) $publicPayload['public_token_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $publicPayload['quote_version_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $publicPayload['proposal_snapshot_hash']);
        $this->assertIsArray($publicPayload['snapshot_json']);
        $this->assertSame((int) $version['id'], (int) $acceptedPayload['accepted_version_id']);
        $this->assertSame($publicPayload['quote_version_hash'], $acceptedPayload['quote_version_hash']);
        $this->assertSame($publicPayload['acceptance_email'], $acceptedPayload['legal_acceptance']['acceptance_email']);
    }

    public function testAcceptCreatesConfirmationDraftWithoutSendingEmail(): void
    {
        [$repository, $quote, , $token] = $this->makeSentQuote();

        $this->service($repository)->accept($token, $this->client(), $this->acceptance());
        $messages = array_values(array_filter(
            $repository->listQuoteMessages((int) $quote['id']),
            static fn (array $message): bool => (string) ($message['message_type'] ?? '') === 'acceptance_confirmation'
        ));
        $payload = $this->eventPayload($repository, (int) $quote['id'], 'quote_acceptance_confirmation_draft');

        $this->assertCount(1, $messages);
        $this->assertSame('draft', (string) ($messages[0]['status'] ?? ''));
        $this->assertSame('akkoord@example.test', (string) ($messages[0]['to_email'] ?? ''));
        $this->assertStringContainsString('ddb_quote_proposal_pdf=1', (string) ($messages[0]['body'] ?? ''));
        $this->assertStringContainsString('https://example.test/?ddb_quote_proposal=', (string) ($messages[0]['body'] ?? ''));
        $this->assertStringNotContainsString('/wp-admin/', (string) ($messages[0]['body'] ?? ''));
        $this->assertStringNotContainsString('admin.php', (string) ($messages[0]['body'] ?? ''));
        $this->assertFalse($payload['mail_sent']);
    }

    public function testAcceptRefreshesLegacyConfirmationDraftWithAdminUrl(): void
    {
        [$repository, $quote, $version, $token] = $this->makeSentQuote();
        $repository->createQuoteMessage(array(
            'quote_id' => (int) $quote['id'],
            'quote_version_id' => (int) $version['id'],
            'direction' => 'outbound',
            'message_type' => 'acceptance_confirmation',
            'channel' => 'email',
            'status' => 'draft',
            'subject' => 'Bevestiging akkoord offerte Q-PUBLIC-1',
            'body' => 'Bekijk via https://staging.dagjedenbosch.nl/wp-admin/admin.phphj?page=sbdp-quotes',
            'to_name' => 'Oude naam',
            'to_email' => 'oud@example.test',
            'thread_token' => 'Q-PUBLIC-1',
        ));

        $this->service($repository)->accept($token, $this->client(), $this->acceptance());
        $messages = array_values(array_filter(
            $repository->listQuoteMessages((int) $quote['id']),
            static fn (array $message): bool => (string) ($message['message_type'] ?? '') === 'acceptance_confirmation'
        ));
        $payload = $this->eventPayload($repository, (int) $quote['id'], 'quote_acceptance_confirmation_draft_refreshed');
        $body = (string) ($messages[0]['body'] ?? '');

        $this->assertCount(1, $messages);
        $this->assertSame('akkoord@example.test', (string) ($messages[0]['to_email'] ?? ''));
        $this->assertStringContainsString('ddb_quote_proposal_pdf=1', $body);
        $this->assertStringContainsString('https://example.test/?ddb_quote_proposal=', $body);
        $this->assertStringNotContainsString('/wp-admin/', $body);
        $this->assertStringNotContainsString('admin.php', $body);
        $this->assertSame((int) ($messages[0]['id'] ?? 0), (int) ($payload['message_id'] ?? 0));
        $this->assertFalse($payload['mail_sent']);
    }

    public function testAcceptedProposalDocumentRendersFromFrozenAcceptanceSnapshot(): void
    {
        [$repository, $quote, , $token] = $this->makeSentQuote();

        $this->service($repository)->accept($token, $this->client(), $this->acceptance());
        $html = (new QuoteAcceptedDocumentService($repository))->renderHtml((int) $quote['id']);

        $this->assertStringContainsString('Geaccepteerde offerte', $html);
        $this->assertStringContainsString('Test Akkoordgever', $html);
        $this->assertStringContainsString('akkoord@example.test', $html);
        $this->assertStringContainsString('Rondvaart', $html);
        $this->assertStringContainsString('Quote version hash', $html);
        $this->assertStringContainsString('WooCommerce blijft leidend', $html);
    }

    public function testAcceptedPublicProposalShowsPdfDownloadLink(): void
    {
        [$repository, , , $token] = $this->makeSentQuote();

        $this->service($repository)->accept($token, $this->client(), $this->acceptance());
        $context = $this->service($repository)->resolveByToken($token);
        $html = PublicProposalController::renderPage($token, $context);

        $this->assertStringContainsString('Download geaccepteerde offerte als PDF', $html);
        $this->assertStringContainsString('ddb_quote_proposal_pdf=1', $html);
    }

    public function testAcceptedProposalDocumentRequiresAcceptanceSnapshot(): void
    {
        [$repository, $quote] = $this->makeSentQuote();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nog geen geaccepteerde offerteversie');
        (new QuoteAcceptedDocumentService($repository))->renderHtml((int) $quote['id']);
    }

    public function testAdminSummaryExposesLegalAcceptanceCompleteness(): void
    {
        [$repository, $quote, , $token] = $this->makeSentQuote();

        $this->service($repository)->accept($token, $this->client(), $this->acceptance());
        $summary = (new QuoteAdminStatusSummaryService($repository))->summarize((int) $quote['id']);

        $this->assertTrue($summary['legal_acceptance_complete']);
        $this->assertSame('Test Akkoordgever', $summary['legal_acceptance']['acceptance_name']);
        $this->assertSame('akkoord@example.test', $summary['legal_acceptance']['acceptance_email']);
        $this->assertSame('ddb-terms-test', $summary['legal_acceptance']['terms_version']);
        $this->assertSame(array(), $summary['legal_acceptance']['missing']);
        $this->assertSame('draft', $summary['acceptance_confirmation_status']);
        $this->assertGreaterThan(0, (int) $summary['acceptance_confirmation_message_id']);
    }

    public function testAcceptedQuoteUsesApprovedVersionNotCurrentVersion(): void
    {
        [$repository, $quote, $version, $token] = $this->makeSentQuote();
        $newVersion = $repository->createQuoteVersion(array(
            'quote_id' => (int) $quote['id'],
            'version_number' => 2,
            'status' => 'draft',
            'proposal_title' => 'Nieuwe werkversie',
        ));
        $repository->updateQuote((int) $quote['id'], array('current_version_id' => (int) $newVersion['id']));

        $accepted = $this->service($repository)->accept($token, $this->client(), $this->acceptance());
        $payload = $this->eventPayload($repository, (int) $quote['id'], 'quote_public_proposal_accepted');

        $this->assertSame((int) $version['id'], (int) $accepted['approved_version_id']);
        $this->assertSame((int) $version['id'], (int) $payload['approved_version_id']);
        $this->assertSame((int) $newVersion['id'], (int) $payload['current_version_id_at_acceptance']);
        $this->assertNotSame((int) $payload['current_version_id_at_acceptance'], (int) $payload['approved_version_id']);
    }

    public function testConfirmedAndOperationsReadyQuotesRemainReadOnlyPubliclyViewable(): void
    {
        foreach (['confirmed', 'operations_ready'] as $status) {
            [$repository, , , $token] = $this->makeSentQuote($status);
            $context = $this->service($repository)->resolveByToken($token);
            $html = PublicProposalController::renderPage($token, $context);

            $this->assertFalse($context['actionable']);
            $this->assertStringContainsString('Bevestigd', $html);
            $this->assertStringContainsString('Rondvaart', $html);
            $this->assertStringNotContainsString('Akkoord geven', $html);
        }
    }

    public function testConfirmedQuoteBlocksTokenForNonApprovedVersion(): void
    {
        [$repository, $quote] = $this->makeSentQuote('confirmed');
        $otherVersion = $repository->createQuoteVersion(array(
            'quote_id' => (int) $quote['id'],
            'version_number' => 2,
            'status' => 'sent',
            'proposal_title' => 'Oude versie',
        ));
        $token = (new PublicQuoteProposalTokenService())->create((int) $quote['id'], (int) $otherVersion['id'], (string) $quote['quote_reference']);

        $this->expectException(InvalidArgumentException::class);
        $this->service($repository)->resolveByToken($token);
    }

    public function testExpiredDeclinedAndCancelledQuotesCannotBeAccepted(): void
    {
        foreach (['expired', 'declined', 'cancelled'] as $status) {
            [$repository, , , $token] = $this->makeSentQuote($status);

            try {
                $this->service($repository)->accept($token, $this->client(), $this->acceptance());
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

    public function testNewProposalTokenContainsExpiry(): void
    {
        [$repository, $quote, $version] = $this->makeSentQuote();
        unset($repository);

        $tokenService = new PublicQuoteProposalTokenService();
        $verified = $tokenService->verify($tokenService->create((int) $quote['id'], (int) $version['id'], (string) $quote['quote_reference']));

        $this->assertIsArray($verified);
        $this->assertArrayHasKey('issued_at', $verified);
        $this->assertArrayHasKey('expires_at', $verified);
        $this->assertGreaterThan(time(), (int) $verified['expires_at']);
    }

    public function testExpiredProposalTokenIsRejectedWithClearMessage(): void
    {
        [$repository, $quote, $version] = $this->makeSentQuote();
        $token = (new PublicQuoteProposalTokenService())->create(
            (int) $quote['id'],
            (int) $version['id'],
            (string) $quote['quote_reference'],
            time() - 60
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('voorstel-link is verlopen');
        $this->service($repository)->resolveByToken($token);
    }

    public function testRevokedQuoteProposalTokenIsRejectedAndLogged(): void
    {
        [$repository, $quote, , $token] = $this->makeSentQuote();
        $service = $this->service($repository);

        $this->assertSame((int) $quote['id'], (int) $service->resolveByToken($token)['quote']['id']);
        $result = $service->revokeQuotePublicTokens((int) $quote['id'], 12, 'unit test');

        $this->assertTrue($result['revoked']);
        $payload = $this->eventPayload($repository, (int) $quote['id'], 'quote_public_proposal_token_revoked');
        $this->assertSame('quote', $payload['scope']);
        $this->assertSame('unit test', $payload['reason']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('voorstel-link is ingetrokken');
        $service->resolveByToken($token);
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
            'requester_name' => 'Klant Test',
            'requester_email' => 'klant@example.test',
            'requester_company' => 'TEST-BSP BV',
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
            'approved_version_id' => in_array($status, array('accepted', 'confirmed', 'operations_ready'), true) ? (int) $version['id'] : 0,
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

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function acceptance(array $overrides = array()): array
    {
        return array_merge(array(
            'acceptance_name' => 'Test Akkoordgever',
            'acceptance_email' => 'akkoord@example.test',
            'acceptance_company' => 'TEST-BSP BV',
            'acceptance_role' => 'Inkoper',
            'acceptance_terms_checked' => '1',
            'terms_version' => 'ddb-terms-test',
            'terms_url' => 'https://example.test/voorwaarden/',
        ), $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(InMemoryQuoteRepository $repository, int $quoteId, string $eventType): array
    {
        $matches = array_values(array_filter(
            $repository->listQuoteEvents($quoteId),
            static fn (array $event): bool => (string) ($event['event_type'] ?? '') === $eventType
        ));
        $this->assertNotSame(array(), $matches, 'Expected event ' . $eventType);
        $event = end($matches);

        return is_array($event['payload_json'] ?? null) ? $event['payload_json'] : array();
    }
}
}
