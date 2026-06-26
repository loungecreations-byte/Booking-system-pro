<?php

declare(strict_types=1);

namespace {
    if (! function_exists('current_time')) {
        function current_time($type = 'mysql', $gmt = false): string
        {
            unset($type, $gmt);
            return '2026-06-25 10:00:00';
        }
    }
    if (! function_exists('wp_mail')) {
        function wp_mail($to, $subject, $message, $headers = array()): bool
        {
            unset($to, $subject, $message, $headers);
            return true;
        }
    }
}

namespace BSP\Tests\Quotes {
    use BSP\Quotes\CustomerWorkspaceController;
    use BSP\Quotes\Service\PartnerConfirmationService;
    use BSP\Quotes\Service\PartnerConfirmationTokenService;
    use BSP\Quotes\Service\PublicQuoteProposalTokenService;
    use BSP\Quotes\Service\QuoteAcceptanceService;
    use BSP\Quotes\Service\QuoteCommunicationService;
    use BSP\Quotes\Service\QuoteEventLogger;
    use BSP\Quotes\Service\QuoteTimelineService;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/InMemoryQuoteRepository.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/Service/QuoteEventLogger.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/Service/QuoteTimelineService.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/Service/PartnerConfirmationTokenService.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/Service/PartnerConfirmationService.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/Service/PublicQuoteProposalTokenService.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/Service/QuoteAcceptanceService.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/Service/QuoteCommunicationService.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/CustomerWorkspaceController.php';

    final class BookingWorkspaceMvpTest extends TestCase
    {
        public function testTimelineLogOnceIsIdempotent(): void
        {
            [$repository, $quote, $version] = $this->fixture();
            $timeline = new QuoteTimelineService($repository);

            $first = $timeline->logOnce('supplier_invited', 'supplier_invited:line:1', (int) $quote['quote_request_id'], (int) $quote['id'], (int) $version['id']);
            $second = $timeline->logOnce('supplier_invited', 'supplier_invited:line:1', (int) $quote['quote_request_id'], (int) $quote['id'], (int) $version['id']);

            self::assertSame((int) $first['id'], (int) $second['id']);
            self::assertTrue((bool) ($second['idempotent_replay'] ?? false));
            self::assertCount(1, $repository->listQuoteEvents((int) $quote['id']));
        }

        public function testPartnerTokenConfirmDeclineAndAlternativeUpdateLineAndEvents(): void
        {
            [$repository, $quote, $version, $line] = $this->fixture();
            $service = new PartnerConfirmationService($repository, new QuoteTimelineService($repository), new PartnerConfirmationTokenService());
            $invite = $service->invite((int) $quote['id'], (int) $line['id'], 9);

            $resolved = $service->resolveByToken((string) $invite['token']);
            self::assertSame((int) $line['id'], (int) $resolved['line']['id']);

            $confirmed = $service->respond((string) $invite['token'], 'confirm', '', array('ip' => '127.0.0.1'));
            self::assertSame(PartnerConfirmationService::STATUS_CONFIRMED, $confirmed['status']);
            $updatedLine = $repository->findQuoteLine((int) $line['id']);
            self::assertSame('confirmed', $updatedLine['availability_confidence']);
            self::assertSame(PartnerConfirmationService::STATUS_CONFIRMED, $updatedLine['availability_snapshot_json']['supplierStatus']);
            self::assertContains('supplier_confirmed', array_column($repository->listQuoteEvents((int) $quote['id']), 'event_type'));
            $followups = $repository->listQuoteFollowups((int) $quote['id']);
            self::assertSame('completed', $followups[0]['status']);

            [$repository2, $quote2, , $line2] = $this->fixture();
            $service2 = new PartnerConfirmationService($repository2, new QuoteTimelineService($repository2), new PartnerConfirmationTokenService());
            $invite2 = $service2->invite((int) $quote2['id'], (int) $line2['id'], 9);
            $service2->respond((string) $invite2['token'], 'alternative', 'Kan om 12:00.');
            $altLine = $repository2->findQuoteLine((int) $line2['id']);
            self::assertSame(PartnerConfirmationService::STATUS_ALTERNATIVE, $altLine['availability_snapshot_json']['supplierStatus']);
            self::assertContains('supplier_alternative_proposed', array_column($repository2->listQuoteEvents((int) $quote2['id']), 'event_type'));
            $altFollowups = $repository2->listQuoteFollowups((int) $quote2['id']);
            self::assertSame('open', $altFollowups[0]['status']);
            self::assertStringContainsString('Kan om 12:00.', $altFollowups[0]['note']);
        }

        public function testPartnerTokenRejectsRevokedSnapshotHash(): void
        {
            [$repository, $quote, , $line] = $this->fixture();
            $service = new PartnerConfirmationService($repository, new QuoteTimelineService($repository), new PartnerConfirmationTokenService());
            $invite = $service->invite((int) $quote['id'], (int) $line['id'], 9);
            $updated = $repository->findQuoteLine((int) $line['id']);
            $snapshot = $updated['availability_snapshot_json'];
            $snapshot['partnerConfirmation']['revoked'] = true;
            $repository->updateQuoteLine((int) $line['id'], array('availability_snapshot_json' => $snapshot));

            $this->expectException(\InvalidArgumentException::class);
            $service->resolveByToken((string) $invite['token']);
        }

        public function testPartnerTokenCanBeRevokedAndMarkedSent(): void
        {
            [$repository, $quote, , $line] = $this->fixture();
            $service = new PartnerConfirmationService($repository, new QuoteTimelineService($repository), new PartnerConfirmationTokenService());
            $invite = $service->invite((int) $quote['id'], (int) $line['id'], 9);

            $service->markSent((int) $quote['id'], (int) $line['id'], 123, 9);
            $state = $service->state((int) $quote['id'], (int) $line['id']);
            self::assertTrue($state['has_token']);
            self::assertNotSame('', $state['sent_at']);

            $service->revoke((int) $quote['id'], (int) $line['id'], 9);
            $revoked = $service->state((int) $quote['id'], (int) $line['id']);
            self::assertTrue($revoked['revoked']);
            self::assertContains('supplier_invite_revoked', array_column($repository->listQuoteEvents((int) $quote['id']), 'event_type'));

            $this->expectException(\InvalidArgumentException::class);
            $service->resolveByToken((string) $invite['token']);
        }

        public function testSupplierConfirmationRequestCanBeSentBeforeCustomerProposal(): void
        {
            [$repository, $quote, $version] = $this->fixture(false);
            $draft = $repository->createQuoteMessage(array(
                'quote_id' => (int) $quote['id'],
                'quote_version_id' => (int) $version['id'],
                'direction' => 'outbound',
                'message_type' => 'supplier_confirmation_request',
                'status' => 'draft',
                'subject' => 'Partnerverzoek',
                'body' => 'Wilt u bevestigen?',
                'to_name' => 'Eropuitje',
                'to_email' => 'partner@example.test',
                'thread_token' => 'Q-WORKSPACE-supplier-line-1',
            ));

            $sent = (new QuoteCommunicationService($repository, new QuoteEventLogger($repository)))->sendEmail((int) $quote['id'], array(
                'message_type' => 'supplier_confirmation_request',
                'draft_id' => (int) $draft['id'],
                'to_name' => 'Eropuitje',
                'to_email' => 'partner@example.test',
                'subject' => 'Partnerverzoek',
                'body' => 'Wilt u bevestigen?',
            ), 9);

            self::assertSame('sent', $sent['status']);
            self::assertSame('supplier_confirmation_request', $sent['message_type']);
            self::assertSame('Q-WORKSPACE-supplier-line-1', $sent['thread_token']);
            self::assertContains('quote_message_sent', array_column($repository->listQuoteEvents((int) $quote['id']), 'event_type'));
        }

        public function testGeneratedCustomerProposalIncludesWorkspaceStatusLink(): void
        {
            [$repository, $quote] = $this->fixture(false);
            $draft = (new QuoteCommunicationService($repository, new QuoteEventLogger($repository)))->generateProposalDraft((int) $quote['id'], 9);

            self::assertStringContainsString('ddb_quote_proposal=', (string) ($draft['body'] ?? ''));
            self::assertStringContainsString('ddb_customer_workspace=', (string) ($draft['body'] ?? ''));
            self::assertStringContainsString('Volg de actuele status hier:', (string) ($draft['body'] ?? ''));
        }

        public function testCustomerWorkspaceRendersPublicStatusWithoutSupplierInternalNote(): void
        {
            [$repository, $quote, $version, $line] = $this->fixture();
            $snapshot = $line['availability_snapshot_json'];
            $snapshot['supplierInternalNote'] = 'Interne margeafspraak';
            $snapshot['supplierStatus'] = 'supplier_booking_confirmed';
            $repository->updateQuoteLine((int) $line['id'], array('availability_snapshot_json' => $snapshot, 'availability_confidence' => 'confirmed'));
            (new QuoteTimelineService($repository))->logOnce('supplier_confirmed', 'supplier_confirmed:line:' . (int) $line['id'], (int) $quote['quote_request_id'], (int) $quote['id'], (int) $version['id']);
            $token = (new PublicQuoteProposalTokenService())->create((int) $quote['id'], (int) $version['id'], (string) $quote['quote_reference']);

            $context = array(
                'quote' => $quote,
                'version' => $version,
                'request' => $repository->findQuoteRequest((int) $quote['quote_request_id']),
                'lines' => $repository->listQuoteLines((int) $version['id']),
                'events' => $repository->listQuoteEvents((int) $quote['id']),
                'token_id' => (new PublicQuoteProposalTokenService())->tokenId($token),
            );
            $html = CustomerWorkspaceController::renderPage($context);

            self::assertStringContainsString('Partneronderdeel bevestigd', $html);
            self::assertStringNotContainsString('Interne margeafspraak', $html);
        }

        public function testNewWorkspaceLayerDoesNotDuplicateWooCheckoutOrPayment(): void
        {
            $root = dirname(__DIR__, 2);
            $files = array(
                $root . '/modules/quotes/CustomerWorkspaceController.php',
                $root . '/modules/quotes/PartnerConfirmationController.php',
                $root . '/modules/quotes/Service/PartnerConfirmationService.php',
            );
            $source = '';
            foreach ($files as $file) {
                $source .= (string) file_get_contents($file);
            }

            self::assertStringNotContainsString('wc_create_order', $source);
            self::assertStringNotContainsString('woocommerce_payment_complete', $source);
            self::assertStringNotContainsString('booking-widget', $source);
        }

        public function testExistingQuoteAcceptFlowStillPinsApprovedVersion(): void
        {
            [$repository, $quote, $version] = $this->fixture();
            $updated = (new QuoteAcceptanceService($repository, new QuoteEventLogger($repository)))->acceptQuoteVersion((int) $quote['id'], (int) $version['id']);

            self::assertSame('accepted', $updated['status']);
            self::assertSame((int) $version['id'], (int) $updated['approved_version_id']);
            self::assertContains('quote_accepted', array_column($repository->listQuoteEvents((int) $quote['id']), 'event_type'));
        }

        /**
         * @return array{0:InMemoryQuoteRepository,1:array<string,mixed>,2:array<string,mixed>,3:array<string,mixed>}
         */
        private function fixture(bool $withSentProposal = true): array
        {
            $repository = new InMemoryQuoteRepository();
            $request = $repository->createQuoteRequest(array(
                'request_reference' => 'QR-WORKSPACE',
                'status' => 'converted_to_quote',
                'requester_name' => 'Klant Test',
                'requester_email' => 'klant@example.test',
                'request_summary' => 'Workspace aanvraag',
                'group_size' => 8,
                'preferred_date' => '2026-07-01',
            ));
            $quote = $repository->createQuote(array(
                'quote_reference' => 'Q-WORKSPACE',
                'quote_request_id' => (int) $request['id'],
                'status' => 'sent',
                'review_status' => 'approved',
                'send_status' => 'sent_manual',
                'handoff_status' => 'not_ready',
                'current_version_id' => 0,
                'approved_version_id' => 0,
                'woo_order_id' => 0,
            ));
            $version = $repository->createQuoteVersion(array(
                'quote_id' => (int) $quote['id'],
                'version_number' => 1,
                'status' => 'sent',
                'proposal_title' => 'Voorstel workspace',
                'proposal_summary' => 'Een rustig voorstel.',
            ));
            $quote = $repository->updateQuote((int) $quote['id'], array('current_version_id' => (int) $version['id']));
            $lines = $repository->replaceQuoteLines((int) $version['id'], array(
                array(
                    'line_number' => 1,
                    'sort_order' => 1,
                    'title' => 'E-Chopper tour',
                    'product_id' => 115,
                    'participants' => 8,
                    'service_date' => '2026-07-01',
                    'start_time' => '10:00',
                    'end_time' => '12:00',
                    'availability_confidence' => 'unknown',
                    'pricing_confidence' => 'snapshot',
                    'availability_snapshot_json' => array(
                        'bookingMode' => 'supplier_confirmation',
                        'supplierStatus' => 'supplier_confirmation_required',
                        'supplierName' => 'Eropuitje',
                        'participants' => 8,
                    ),
                ),
            ));
            if ($withSentProposal) {
                $repository->createQuoteMessage(array(
                    'quote_id' => (int) $quote['id'],
                    'quote_version_id' => (int) $version['id'],
                    'direction' => 'outbound',
                    'message_type' => 'proposal',
                    'status' => 'sent',
                    'subject' => 'Voorstel',
                ));
            }

            return array($repository, $quote, $version, $lines[0]);
        }
    }
}
