<?php

declare(strict_types=1);

namespace {
    if (! function_exists('get_post_meta')) {
        function get_post_meta(int $postId, string $key, bool $single = true)
        {
            unset($single);
            return $GLOBALS['__ddb_supplier_confirmation_meta'][$postId][$key] ?? '';
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

    final class QuoteSupplierConfirmationServiceTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['__ddb_supplier_confirmation_meta'] = array();
            $this->setBookingModeMeta(115, array(
                '_ddb_supplier_provider' => 'eliio',
                '_ddb_booking_mode' => 'direct',
                '_ddb_direct_booking_enabled' => 'yes',
                '_ddb_quote_os_enabled' => 'yes',
                '_ddb_supplier_confirmation_required' => 'yes',
                '_ddb_supplier_name' => 'Eropuitje',
                '_ddb_supplier_option_days' => '3',
                '_ddb_supplier_cancel_mode' => 'manual',
            ));
        }

        public function testProduct115CreatesSupplierConfirmationStateOnSync(): void
        {
            [$repository, $quote, $version, $line] = $this->makeQuoteFixture();
            $service = new QuoteSupplierConfirmationService($repository, new QuoteEventLogger($repository));

            $service->syncQuote((int) $quote['id'], 11);

            $updatedLine = $repository->findQuoteLine((int) $line['id']);
            $assumptions = $repository->listQuoteAssumptions((int) $quote['id']);
            $followups = $repository->listQuoteFollowups((int) $quote['id']);
            $supplierAssumptions = $this->filterSupplierAssumptions($assumptions);

            self::assertSame('supplier_confirmation', $updatedLine['availability_snapshot_json']['bookingMode']);
            self::assertSame('supplier_confirmation_required', $updatedLine['availability_snapshot_json']['supplierStatus']);
            self::assertSame('Eropuitje', $updatedLine['availability_snapshot_json']['supplierName']);
            self::assertSame('manual', $updatedLine['availability_snapshot_json']['supplierCancelMode']);
            self::assertSame('unknown', $updatedLine['availability_snapshot_json']['availabilityStatus']);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $updatedLine['availability_snapshot_json']['availabilityCheckedAt']);
            self::assertNotEmpty($updatedLine['availability_snapshot_json']['supplierTaskKey']);
            self::assertCount(1, $supplierAssumptions);
            self::assertSame('supplier_confirmation_required', $supplierAssumptions[0]['assumption_type']);
            self::assertSame('open', $supplierAssumptions[0]['status']);
            self::assertCount(1, $followups);
            self::assertSame('supplier_confirmation', $followups[0]['followup_type']);
            self::assertSame('open', $followups[0]['status']);
            self::assertSame((int) $line['id'], (int) $supplierAssumptions[0]['quote_line_id']);
        }

        public function testOptionHeldExpiresWithinThreeDaysAndBeforeActivityMinusFortyEightHours(): void
        {
            [$repository, $quote, , $line] = $this->makeQuoteFixture(array(
                'service_date' => '2026-05-23',
                'proposed_start_time' => '11:00',
                'start_time' => '11:00',
                'proposed_end_time' => '13:00',
                'end_time' => '13:00',
                'availability_snapshot_json' => array(
                    'bookingMode' => 'supplier_confirmation',
                    'supplierStatus' => 'supplier_confirmation_required',
                    'availabilityStatus' => 'available',
                    'availabilityCheckedAt' => '2026-05-20T09:00:00+00:00',
                    'participants' => 10,
                    'date' => '2026-05-23',
                    'startTime' => '11:00',
                    'endTime' => '13:00',
                    'supplierName' => 'Eropuitje',
                    'supplierOptionDays' => 3,
                    'supplierCancelMode' => 'manual',
                ),
            ));
            $service = new QuoteSupplierConfirmationService($repository, new QuoteEventLogger($repository));

            $service->updateStatus((int) $quote['id'], (int) $line['id'], 'supplier_option_held', array(
                'option_expires_at' => '2026-05-30T10:00:00+00:00',
            ), 22);

            $updatedLine = $repository->findQuoteLine((int) $line['id']);
            self::assertSame('supplier_option_held', $updatedLine['availability_snapshot_json']['supplierStatus']);
            self::assertSame('2026-05-21T11:00:00+00:00', $updatedLine['availability_snapshot_json']['optionExpiresAt']);
        }

        public function testBookingConfirmedResolvesAssumptionAndCompletesFollowup(): void
        {
            [$repository, $quote, , $line] = $this->makeQuoteFixture();
            $service = new QuoteSupplierConfirmationService($repository, new QuoteEventLogger($repository));

            $service->syncQuote((int) $quote['id'], 11);
            $service->updateStatus((int) $quote['id'], (int) $line['id'], 'supplier_booking_confirmed', array(
                'supplier_booking_reference' => 'EROP-2026-001',
            ), 33);

            $updatedLine = $repository->findQuoteLine((int) $line['id']);
            $assumptions = $repository->listQuoteAssumptions((int) $quote['id']);
            $followups = $repository->listQuoteFollowups((int) $quote['id']);
            $supplierAssumptions = $this->filterSupplierAssumptions($assumptions);

            self::assertSame('supplier_booking_confirmed', $updatedLine['availability_snapshot_json']['supplierStatus']);
            self::assertSame('available', $updatedLine['availability_snapshot_json']['availabilityStatus']);
            self::assertSame('EROP-2026-001', $updatedLine['availability_snapshot_json']['supplierBookingReference']);
            self::assertSame('confirmed', $updatedLine['availability_confidence']);
            self::assertSame('resolved', $supplierAssumptions[0]['status']);
            self::assertSame('completed', $followups[0]['status']);
        }

        public function testSupplierDeclinedIsStoredAndBlocksState(): void
        {
            [$repository, $quote, , $line] = $this->makeQuoteFixture();
            $service = new QuoteSupplierConfirmationService($repository, new QuoteEventLogger($repository));

            $service->syncQuote((int) $quote['id'], 11);
            $service->updateStatus((int) $quote['id'], (int) $line['id'], 'supplier_declined', array(
                'internal_note' => 'Partner heeft geen capaciteit meer.',
            ), 33);

            $updatedLine = $repository->findQuoteLine((int) $line['id']);
            $assumptions = $repository->listQuoteAssumptions((int) $quote['id']);
            $followups = $repository->listQuoteFollowups((int) $quote['id']);
            $supplierAssumptions = $this->filterSupplierAssumptions($assumptions);

            self::assertSame('supplier_declined', $updatedLine['availability_snapshot_json']['supplierStatus']);
            self::assertSame('unavailable', $updatedLine['availability_snapshot_json']['availabilityStatus']);
            self::assertSame('unavailable', $updatedLine['line_status']);
            self::assertSame('open', $supplierAssumptions[0]['status']);
            self::assertSame('open', $followups[0]['status']);
        }

        public function testSecondSyncDoesNotDuplicateAssumptionsOrFollowups(): void
        {
            [$repository, $quote] = $this->makeQuoteFixture();
            $service = new QuoteSupplierConfirmationService($repository, new QuoteEventLogger($repository));

            $service->syncQuote((int) $quote['id'], 11);
            $service->syncQuote((int) $quote['id'], 12);

            $allAssumptions = $repository->listQuoteAssumptions((int) $quote['id']);
            self::assertCount(1, $this->filterSupplierAssumptions($allAssumptions));
            self::assertCount(1, $repository->listQuoteFollowups((int) $quote['id']));
        }

        public function testBookingWidgetIsNotReferencedBySupplierConfirmationFlow(): void
        {
            $root = dirname(__DIR__, 2);
            $service = (string) file_get_contents($root . '/modules/quotes/Service/QuoteSupplierConfirmationService.php');
            $controller = (string) file_get_contents($root . '/modules/quotes/Admin/Controller.php');
            $builder = (string) file_get_contents($root . '/modules/quotes/Admin/QuoteBuilderRenderer.php');

            self::assertStringNotContainsString('booking-widget', $service);
            self::assertStringNotContainsString('booking-widget', $controller);
            self::assertStringNotContainsString('booking-widget', $builder);
        }

        public function testSupplierAdminPostCallbacksAreImplemented(): void
        {
            $root = dirname(__DIR__, 2);
            $module = (string) file_get_contents($root . '/modules/quotes/Module.php');
            $controller = (string) file_get_contents($root . '/modules/quotes/Admin/Controller.php');

            self::assertStringContainsString('admin_post_sbdp_quote_line_supplier_status', $module);
            self::assertStringContainsString('handleUpdateLineSupplierStatus', $controller);
            self::assertStringContainsString('admin_post_sbdp_quote_line_supplier_request_draft', $module);
            self::assertStringContainsString('handleGenerateSupplierRequestDraft', $controller);
        }

        /**
         * @param array<string, mixed>[] $assumptions
         * @return array<string, mixed>[]
         */
        private function filterSupplierAssumptions(array $assumptions): array
        {
            return array_values(array_filter(
                $assumptions,
                fn($a) => ($a['assumption_type'] ?? '') === 'supplier_confirmation_required'
            ));
        }

        /**
         * @return array{0:InMemoryQuoteRepository,1:array<string,mixed>,2:array<string,mixed>,3:array<string,mixed>}
         */
        private function makeQuoteFixture(array $lineOverrides = array()): array
        {
            $repository = new InMemoryQuoteRepository();
            $events = new QuoteEventLogger($repository);
            $requestService = new QuoteRequestService($repository, $events);
            $assumptions = new QuoteAssumptionService($repository, $events);
            $conversion = new QuoteConversionService($repository, $assumptions, $events);

            $request = $requestService->create(array(
                'request_summary' => 'Supplier confirmation quote',
                'requester_email' => 'klant@example.test',
                'group_size' => 10,
                'preferred_date' => '2026-05-23',
                'items' => array(
                    array(
                        'product_id' => 115,
                        'title' => 'E-Chopper tour',
                        'participants' => 10,
                        'service_date' => '2026-05-23',
                        'start_time' => '10:00',
                        'end_time' => '12:00',
                        'pricing_confidence' => 'snapshot',
                        'availability_confidence' => 'projected',
                        'availability_snapshot_json' => array(
                            'bookingMode' => 'supplier_confirmation',
                            'supplierStatus' => 'supplier_confirmation_required',
                            'availabilityStatus' => 'available',
                            'availabilityCheckedAt' => '2026-05-20T09:00:00+00:00',
                            'participants' => 10,
                            'date' => '2026-05-23',
                            'startTime' => '10:00',
                            'endTime' => '12:00',
                            'supplierName' => 'Eropuitje',
                            'supplierOptionDays' => 3,
                            'supplierCancelMode' => 'manual',
                        ),
                    ),
                ),
            ));

            $quote = $conversion->convertRequestToQuote((int) $request['id'], 7);
            $version = $repository->findQuoteVersion((int) $quote['current_version_id']);
            $lines = $repository->listQuoteLines((int) $version['id']);
            $line = array_merge($lines[0], $lineOverrides);
            if (is_array($lineOverrides['availability_snapshot_json'] ?? null)) {
                $baseSnapshot = is_array($lines[0]['availability_snapshot_json'] ?? null) ? $lines[0]['availability_snapshot_json'] : array();
                $line['availability_snapshot_json'] = array_merge($baseSnapshot, $lineOverrides['availability_snapshot_json']);
            }
            $repository->replaceQuoteLines((int) $version['id'], array($line));
            $quote = $repository->updateQuote((int) $quote['id'], array('current_version_id' => (int) $version['id']));

            return array($repository, $quote, $version, $repository->listQuoteLines((int) $version['id'])[0]);
        }

        /**
         * @param array<string, string> $meta
         */
        private function setBookingModeMeta(int $productId, array $meta): void
        {
            $GLOBALS['__ddb_supplier_confirmation_meta'][$productId] = $meta;
        }
    }
}
