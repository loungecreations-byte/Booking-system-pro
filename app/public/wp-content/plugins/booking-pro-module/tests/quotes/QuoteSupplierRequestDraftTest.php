<?php

declare(strict_types=1);

namespace {
    if (! function_exists('get_post_meta')) {
        function get_post_meta(int $postId, string $key, bool $single = true)
        {
            unset($single);
            return $GLOBALS['__ddb_supplier_request_draft_meta'][$postId][$key] ?? '';
        }
    }

    if (! function_exists('current_time')) {
        function current_time($type = 'mysql', $gmt = false): string
        {
            unset($type, $gmt);
            return '2026-05-20 10:00:00';
        }
    }
}

namespace BSP\Tests\Quotes {
    use BSP\Quotes\Service\QuoteEventLogger;
    use BSP\Quotes\Service\QuoteRequestService;
    use BSP\Quotes\Service\QuoteConversionService;
    use BSP\Quotes\Service\QuoteSupplierConfirmationService;
    use BSP\Quotes\Service\QuoteAssumptionService;
    use BSP\Quotes\Service\QuoteOperationsDraftService;
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__, 2) . '/modules/core/Services/BookingModeService.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/Repository/QuoteRepositoryInterface.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/Service/QuoteEventLogger.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/Service/QuoteAssumptionService.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/Service/QuoteRequestService.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/Service/QuoteConversionService.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/Service/QuoteOperationsDraftService.php';
    require_once dirname(__DIR__, 2) . '/modules/quotes/Service/QuoteSupplierConfirmationService.php';
    require_once __DIR__ . '/InMemoryQuoteRepository.php';

    final class QuoteSupplierRequestDraftTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['__ddb_supplier_request_draft_meta'] = array();
            $this->setBookingModeMeta(115, array(
                '_ddb_supplier_provider'             => 'eliio',
                '_ddb_booking_mode'                  => 'direct',
                '_ddb_direct_booking_enabled'        => 'yes',
                '_ddb_quote_os_enabled'              => 'yes',
                '_ddb_supplier_confirmation_required' => 'yes',
                '_ddb_supplier_name'                 => 'Eropuitje',
                '_ddb_supplier_option_days'          => '3',
                '_ddb_supplier_cancel_mode'          => 'manual',
            ));
        }

        // ── Test 1 ──────────────────────────────────────────────────────────
        public function testDraftIsCreatedForSupplierConfirmationLine(): void
        {
            [$repository, $quote, , $line] = $this->makeQuoteFixture();
            $service = new QuoteSupplierConfirmationService($repository, new QuoteEventLogger($repository));

            $result = $service->generateSupplierRequestDraft((int) $quote['id'], (int) $line['id'], 99);

            $message = $result['message'];
            self::assertIsArray($message);
            self::assertSame('outbound', $message['direction']);
            self::assertSame('supplier_confirmation_request', $message['message_type']);
            self::assertSame('draft', $message['status']);
            self::assertSame('Eropuitje', $message['to_name']);
            self::assertStringContainsString('supplier-line-' . $line['id'], (string) $message['thread_token']);
        }

        // ── Test 2 ──────────────────────────────────────────────────────────
        public function testDraftBodyContainsKeyFields(): void
        {
            [$repository, $quote, , $line] = $this->makeQuoteFixture();
            $service = new QuoteSupplierConfirmationService($repository, new QuoteEventLogger($repository));

            $result = $service->generateSupplierRequestDraft((int) $quote['id'], (int) $line['id'], 99);

            $body = (string) ($result['message']['body'] ?? '');
            $subject = (string) ($result['message']['subject'] ?? '');

            self::assertStringContainsString('E-Chopper tour', $body);  // activiteit
            self::assertStringContainsString('2026-05-23', $body);      // datum
            self::assertStringContainsString('10:00', $body);           // starttijd
            self::assertStringContainsString('10', $body);              // personen
            self::assertStringContainsString('n.b.', $body);            // availabilityStatus remains unconfirmed

            // subject line must contain title, date, participants
            self::assertStringContainsString('E-Chopper tour', $subject);
            self::assertStringContainsString('2026-05-23', $subject);
            self::assertStringContainsString('10 personen', $subject);

            // quote_reference must appear somewhere
            $quoteReference = (string) ($quote['quote_reference'] ?? '');
            if ($quoteReference !== '') {
                self::assertStringContainsString($quoteReference, $body);
            }
        }

        // ── Test 3 ──────────────────────────────────────────────────────────
        public function testStatusAdvancesToSupplierOptionRequestedAfterDraftGeneration(): void
        {
            [$repository, $quote, , $line] = $this->makeQuoteFixture();
            $service = new QuoteSupplierConfirmationService($repository, new QuoteEventLogger($repository));

            $result = $service->generateSupplierRequestDraft((int) $quote['id'], (int) $line['id'], 99);

            self::assertTrue($result['status_changed']);

            $updatedLine = $repository->findQuoteLine((int) $line['id']);
            self::assertSame(
                'supplier_option_requested',
                $updatedLine['availability_snapshot_json']['supplierStatus']
            );
        }

        // ── Test 4 ──────────────────────────────────────────────────────────
        public function testAuditEventIsLogged(): void
        {
            [$repository, $quote, , $line] = $this->makeQuoteFixture();
            $service = new QuoteSupplierConfirmationService($repository, new QuoteEventLogger($repository));

            $service->generateSupplierRequestDraft((int) $quote['id'], (int) $line['id'], 99);

            $events = $repository->listQuoteEvents((int) $quote['id']);
            $draftEvents = array_values(array_filter(
                $events,
                static fn (array $e): bool => ($e['event_type'] ?? '') === 'quote_supplier_request_draft_generated'
            ));
            self::assertCount(1, $draftEvents);
            self::assertSame(99, (int) ($draftEvents[0]['actor_id'] ?? 0));
        }

        // ── Test 5 ──────────────────────────────────────────────────────────
        public function testSecondCallUpdatesExistingDraftIdempotent(): void
        {
            [$repository, $quote, , $line] = $this->makeQuoteFixture();
            $service = new QuoteSupplierConfirmationService($repository, new QuoteEventLogger($repository));

            $first  = $service->generateSupplierRequestDraft((int) $quote['id'], (int) $line['id'], 99);
            $second = $service->generateSupplierRequestDraft((int) $quote['id'], (int) $line['id'], 99);

            // Must reference the same message row (same id), not create a second one
            self::assertSame((int) ($first['message']['id'] ?? -1), (int) ($second['message']['id'] ?? -2));

            // Exactly one supplier_confirmation_request draft in the repo
            $allMessages = $repository->listQuoteMessages((int) $quote['id']);
            $supplierDrafts = array_values(array_filter(
                $allMessages,
                static fn (array $m): bool =>
                    ($m['message_type'] ?? '') === 'supplier_confirmation_request' &&
                    ($m['status'] ?? '')       === 'draft'
            ));
            self::assertCount(1, $supplierDrafts);
        }

        // ── Test 6 ──────────────────────────────────────────────────────────
        public function testNoProposalMessageIsCreated(): void
        {
            [$repository, $quote, , $line] = $this->makeQuoteFixture();
            $service = new QuoteSupplierConfirmationService($repository, new QuoteEventLogger($repository));

            $service->generateSupplierRequestDraft((int) $quote['id'], (int) $line['id'], 99);

            $allMessages = $repository->listQuoteMessages((int) $quote['id']);
            $proposalMessages = array_filter(
                $allMessages,
                static fn (array $m): bool => ($m['message_type'] ?? '') === 'proposal'
            );
            self::assertCount(0, $proposalMessages);
        }

        // ── Test 7 ──────────────────────────────────────────────────────────
        public function testAvailabilitySnapshotJsonDoesNotContainSupplierEmail(): void
        {
            [$repository, $quote, , $line] = $this->makeQuoteFixture();
            $service = new QuoteSupplierConfirmationService($repository, new QuoteEventLogger($repository));

            // Give the product an email so we can verify it doesn't leak into the snapshot
            $this->setBookingModeMeta(115, array(
                '_ddb_supplier_provider'              => 'eliio',
                '_ddb_booking_mode'                   => 'direct',
                '_ddb_supplier_confirmation_required' => 'yes',
                '_ddb_supplier_name'                  => 'Eropuitje',
                '_ddb_supplier_email'                 => 'supplier@eropuitje.nl',
                '_ddb_supplier_option_days'           => '3',
                '_ddb_supplier_cancel_mode'           => 'manual',
            ));

            $service->generateSupplierRequestDraft((int) $quote['id'], (int) $line['id'], 99);

            $updatedLine = $repository->findQuoteLine((int) $line['id']);
            $snapshot = $updatedLine['availability_snapshot_json'] ?? array();
            self::assertArrayNotHasKey('supplierEmail', $snapshot);
        }

        // ── Test 8 ──────────────────────────────────────────────────────────
        public function testBookingWidgetIsNotReferencedBySupplierRequestDraftFlow(): void
        {
            $root = dirname(__DIR__, 2);
            $service  = (string) file_get_contents($root . '/modules/quotes/Service/QuoteSupplierConfirmationService.php');
            $renderer = (string) file_get_contents($root . '/modules/quotes/Admin/QuoteBuilderRenderer.php');

            self::assertStringNotContainsString('booking-widget', $service);
            self::assertStringNotContainsString('booking-widget', $renderer);
        }

        // ── Test 9 ──────────────────────────────────────────────────────────
        public function testDraftStillCreatedWhenSupplierEmailMissing(): void
        {
            // setUp installs meta without _ddb_supplier_email → supplierEmail=''
            [$repository, $quote, , $line] = $this->makeQuoteFixture();
            $service = new QuoteSupplierConfirmationService($repository, new QuoteEventLogger($repository));

            $result = $service->generateSupplierRequestDraft((int) $quote['id'], (int) $line['id'], 99);

            self::assertTrue($result['supplier_email_missing']);
            self::assertSame('', (string) ($result['message']['to_email'] ?? 'not-empty'));
            // Draft was still persisted
            self::assertNotEmpty($result['message']['id'] ?? null);
        }

        // ── Test 10 ─────────────────────────────────────────────────────────
        public function testHigherStatusIsNotDowngradedByDraftGeneration(): void
        {
            foreach (array('supplier_option_held', 'supplier_booking_confirmed') as $higherStatus) {
                [$repository, $quote, , $line] = $this->makeQuoteFixture(array(
                    'availability_snapshot_json' => array(
                        'supplierStatus' => $higherStatus,
                    ),
                ));
                $service = new QuoteSupplierConfirmationService($repository, new QuoteEventLogger($repository));

                $result = $service->generateSupplierRequestDraft((int) $quote['id'], (int) $line['id'], 99);

                self::assertFalse($result['status_changed'], "Status '$higherStatus' must not be downgraded.");

                $updatedLine = $repository->findQuoteLine((int) $line['id']);
                self::assertSame(
                    $higherStatus,
                    $updatedLine['availability_snapshot_json']['supplierStatus'],
                    "Snapshot supplierStatus must remain '$higherStatus'."
                );
            }
        }

        // ── Helpers ─────────────────────────────────────────────────────────

        /**
         * @return array{0:InMemoryQuoteRepository,1:array<string,mixed>,2:array<string,mixed>,3:array<string,mixed>}
         */
        private function makeQuoteFixture(array $lineOverrides = array()): array
        {
            $repository = new InMemoryQuoteRepository();
            $events     = new QuoteEventLogger($repository);
            $requestSvc = new QuoteRequestService($repository, $events);
            $assumptions = new QuoteAssumptionService($repository, $events);
            $conversion  = new QuoteConversionService($repository, $assumptions, $events);

            $request = $requestSvc->create(array(
                'request_summary' => 'Partnerverzoek draft test',
                'requester_email' => 'klant@example.test',
                'group_size'      => 10,
                'preferred_date'  => '2026-05-23',
                'items'           => array(
                    array(
                        'product_id'                => 115,
                        'title'                     => 'E-Chopper tour',
                        'participants'              => 10,
                        'service_date'              => '2026-05-23',
                        'start_time'                => '10:00',
                        'end_time'                  => '12:00',
                        'pricing_confidence'        => 'snapshot',
                        'availability_confidence'   => 'projected',
                        'availability_snapshot_json' => array(
                            'bookingMode'           => 'supplier_confirmation',
                            'supplierStatus'        => 'supplier_confirmation_required',
                            'availabilityStatus'    => 'available',
                            'availabilityCheckedAt' => '2026-05-20T09:00:00+00:00',
                            'participants'          => 10,
                            'date'                  => '2026-05-23',
                            'startTime'             => '10:00',
                            'endTime'               => '12:00',
                            'supplierName'          => 'Eropuitje',
                            'supplierOptionDays'    => 3,
                            'supplierCancelMode'    => 'manual',
                        ),
                    ),
                ),
            ));

            $quote   = $conversion->convertRequestToQuote((int) $request['id'], 7);
            $version = $repository->findQuoteVersion((int) $quote['current_version_id']);
            $lines   = $repository->listQuoteLines((int) $version['id']);
            $line    = array_merge($lines[0], $lineOverrides);

            if (is_array($lineOverrides['availability_snapshot_json'] ?? null)) {
                $baseSnapshot = is_array($lines[0]['availability_snapshot_json'] ?? null)
                    ? $lines[0]['availability_snapshot_json']
                    : array();
                $line['availability_snapshot_json'] = array_merge(
                    $baseSnapshot,
                    $lineOverrides['availability_snapshot_json']
                );
            }

            $repository->replaceQuoteLines((int) $version['id'], array($line));
            $quote = $repository->updateQuote((int) $quote['id'], array(
                'current_version_id' => (int) $version['id'],
            ));

            return array(
                $repository,
                $quote,
                $version,
                $repository->listQuoteLines((int) $version['id'])[0],
            );
        }

        /**
         * @param array<string, string> $meta
         */
        private function setBookingModeMeta(int $productId, array $meta): void
        {
            $GLOBALS['__ddb_supplier_request_draft_meta'][$productId] = $meta;
        }
    }
}
