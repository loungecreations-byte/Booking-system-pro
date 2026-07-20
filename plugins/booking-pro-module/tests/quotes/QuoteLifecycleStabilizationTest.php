<?php

declare(strict_types=1);

namespace {
    if (! class_exists('WC_Order')) {
        class WC_Order
        {
            /** @var array<string, mixed> */
            private array $meta = array();

            /** @var array<int, object> */
            private array $items = array();

            /**
             * @param array<string, mixed> $meta
             * @param array<int, object> $items
             */
            public function __construct(private int $id, array $meta = array(), private string $transactionId = '', private float $total = 0.0, array $items = array())
            {
                $this->meta = $meta;
                $this->items = $items;
            }

            public function get_id(): int
            {
                return $this->id;
            }

            public function get_meta(string $key)
            {
                return $this->meta[$key] ?? '';
            }

            public function update_meta_data(string $key, $value): void
            {
                $this->meta[$key] = $value;
            }

            public function get_transaction_id(): string
            {
                return $this->transactionId;
            }

            public function get_total(): float
            {
                return $this->total;
            }

            public function set_total(float $total): void
            {
                $this->total = $total;
            }

            public function calculate_totals(bool $and_taxes = true): void
            {
                unset($and_taxes);
            }

            /**
             * @return array<int, object>
             */
            public function get_items(string $type = ''): array
            {
                unset($type);

                return $this->items;
            }

            public function add_order_note(string $note): void
            {
                $this->meta['_last_order_note'] = $note;
            }

            public function save(): void
            {
            }
        }
    }

    if (! class_exists('BSP_Test_WC_Order_Item_Product')) {
        class BSP_Test_WC_Order_Item_Product
        {
            /** @var array<string, mixed> */
            private array $meta = array();

            public function __construct(private float $subtotal = 0.0, private float $total = 0.0)
            {
            }

            public function set_subtotal(float $subtotal): void
            {
                $this->subtotal = $subtotal;
            }

            public function set_total(float $total): void
            {
                $this->total = $total;
            }

            public function get_subtotal(): float
            {
                return $this->subtotal;
            }

            public function get_total(): float
            {
                return $this->total;
            }

            public function add_meta_data(string $key, $value, bool $unique = false): void
            {
                unset($unique);
                $this->meta[$key] = $value;
            }

            public function get_meta(string $key)
            {
                return $this->meta[$key] ?? '';
            }

            public function save(): void
            {
            }
        }
    }

    if (! function_exists('wc_get_order')) {
        function wc_get_order(int $orderId)
        {
            return $GLOBALS['__quote_lifecycle_orders'][$orderId] ?? null;
        }
    }

    if (! class_exists('BSP_Test_WC_Product')) {
        class BSP_Test_WC_Product
        {
            public function __construct(private float $price)
            {
            }

            public function get_price(): float
            {
                return $this->price;
            }
        }
    }

    if (! function_exists('wc_get_product')) {
        function wc_get_product(int $productId)
        {
            if (! isset($GLOBALS['__quote_lifecycle_product_prices'][$productId])) {
                return null;
            }

            return new \BSP_Test_WC_Product((float) $GLOBALS['__quote_lifecycle_product_prices'][$productId]);
        }
    }

    if (! function_exists('wp_salt')) {
        function wp_salt(string $scheme = 'auth'): string
        {
            return 'quote-lifecycle-test-' . $scheme;
        }
    }
}

namespace BSP\Tests\Quotes {

use BSP\Quotes\Service\PublicQuoteProposalService;
use BSP\Quotes\Service\PublicQuoteProposalTokenService;
use BSP\Quotes\Service\QuoteAdminStatusSummaryService;
use BSP\Quotes\Service\QuoteBookingBridgeService;
use BSP\Quotes\Service\QuoteConfirmationService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteLifecycleMap;
use BSP\Quotes\Service\QuotePaymentSyncService;
use BSP\Quotes\Service\QuoteRequestOrderBridgeService;
use BSP\Quotes\Service\HandoffValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryQuoteRepository.php';

final class QuoteLifecycleStabilizationTest extends TestCase
{
    private InMemoryQuoteRepository $repository;
    private QuoteEventLogger $events;

    protected function setUp(): void
    {
        $this->repository = new InMemoryQuoteRepository();
        $this->events = new QuoteEventLogger($this->repository);
        $GLOBALS['__quote_lifecycle_orders'] = array();
        $GLOBALS['__quote_lifecycle_product_prices'] = array();
    }

    public function testLifecycleMapContainsCoreStatusDefinitionsAndAdminActions(): void
    {
        $statuses = QuoteLifecycleMap::statuses();

        foreach (array('draft', 'ready_to_send', 'sent', 'viewed', 'revision_requested', 'accepted', 'woo_request_order_created', 'payment_pending', 'woo_payment_completed', 'confirmed', 'operations_ready', 'declined', 'cancelled', 'expired') as $status) {
            self::assertArrayHasKey($status, $statuses);
            self::assertNotSame('', (string) ($statuses[$status]['owner'] ?? ''));
            self::assertNotSame('', (string) ($statuses[$status]['admin_next_action'] ?? ''));
            self::assertIsArray($statuses[$status]['previous'] ?? null);
            self::assertIsArray($statuses[$status]['next'] ?? null);
        }

        self::assertSame('Woo order / betaalverzoek aanmaken', $statuses['accepted']['admin_next_action']);
        self::assertSame('Bevestiging afronden', $statuses['woo_payment_completed']['admin_next_action']);
        self::assertSame('Gereed voor uitvoering', $statuses['operations_ready']['admin_next_action']);
    }

    public function testAdminNextActionFollowsLifecycleStage(): void
    {
        [$quote, $version] = $this->makeQuote('accepted');
        $this->repository->updateQuote((int) $quote['id'], array(
            'approved_version_id' => (int) $version['id'],
        ));

        $summary = (new QuoteAdminStatusSummaryService($this->repository))->summarize((int) $quote['id']);
        self::assertSame('accepted', $summary['lifecycle_status']);
        self::assertSame('Woo order / betaalverzoek aanmaken', $summary['next_action']);

        $this->repository->updateQuote((int) $quote['id'], array(
            'woo_order_id' => 501,
            'handoff_status' => 'woo_request_order_created',
        ));
        $summary = (new QuoteAdminStatusSummaryService($this->repository))->summarize((int) $quote['id']);
        self::assertSame('woo_request_order_created', $summary['lifecycle_status']);
        self::assertSame('Wachten op betaling', $summary['next_action']);

        $this->events->log(QuotePaymentSyncService::COMPLETED_EVENT, null, (int) $quote['id'], (int) $version['id'], null, 'paid', array('order_id' => 501));
        $this->repository->updateQuote((int) $quote['id'], array('handoff_status' => QuotePaymentSyncService::COMPLETED_STATUS));
        $summary = (new QuoteAdminStatusSummaryService($this->repository))->summarize((int) $quote['id']);
        self::assertSame('woo_payment_completed', $summary['lifecycle_status']);
        self::assertSame('Bevestiging afronden', $summary['next_action']);
    }

    public function testAdminNextActionHandlesRevisionDeclineAndConfirmedOperationsStates(): void
    {
        [$revisionQuote] = $this->makeQuote('revision_requested');
        $revisionSummary = (new QuoteAdminStatusSummaryService($this->repository))->summarize((int) $revisionQuote['id']);
        self::assertSame('revision_requested', $revisionSummary['lifecycle_status']);
        self::assertSame('Wijziging verwerken', $revisionSummary['next_action']);

        [$declinedQuote] = $this->makeQuote('declined');
        $declinedSummary = (new QuoteAdminStatusSummaryService($this->repository))->summarize((int) $declinedQuote['id']);
        self::assertSame('declined', $declinedSummary['lifecycle_status']);
        self::assertSame('Geen actie / archiveren', $declinedSummary['next_action']);

        [$confirmedQuote, $confirmedVersion] = $this->makeQuote('confirmed');
        $confirmedQuoteId = (int) $confirmedQuote['id'];
        $confirmedVersionId = (int) $confirmedVersion['id'];
        $this->repository->updateQuote($confirmedQuoteId, array(
            'approved_version_id' => $confirmedVersionId,
            'woo_order_id' => 901,
            'handoff_status' => QuotePaymentSyncService::COMPLETED_STATUS,
        ));
        $this->storeOrder(901, array('_sbdp_quote_id' => $confirmedQuoteId, '_sbdp_quote_version_id' => $confirmedVersionId));
        $this->events->log('quote_confirmed', null, $confirmedQuoteId, $confirmedVersionId, null, 'confirmed');
        $blockedSummary = (new QuoteAdminStatusSummaryService($this->repository))->summarize($confirmedQuoteId);
        self::assertSame('Bevestigd, operations geblokkeerd', $blockedSummary['admin_status_label']);
        self::assertSame('Cart/order hydration controleren', $blockedSummary['next_action']);

        $this->events->log('quote_woo_request_order_created', null, $confirmedQuoteId, $confirmedVersionId, null, 'order created');
        $this->events->log(QuotePaymentSyncService::COMPLETED_EVENT, null, $confirmedQuoteId, $confirmedVersionId, null, 'paid', array('order_id' => 901));
        $readySummary = (new QuoteAdminStatusSummaryService($this->repository))->summarize($confirmedQuoteId);
        self::assertSame('Bevestigd', $readySummary['admin_status_label']);
        self::assertSame('Operations booking aanmaken / controleren', $readySummary['next_action']);
        self::assertTrue($readySummary['request_order_handoff_verified']);
        self::assertTrue($readySummary['cta_visibility']['create_booking_bridge']);
    }

    public function testPaymentCompleteIgnoresWrongVersionOrderAndNonAcceptedQuote(): void
    {
        [$quote, $version] = $this->makeQuote('accepted');
        $quoteId = (int) $quote['id'];
        $versionId = (int) $version['id'];
        $this->repository->updateQuote($quoteId, array(
            'approved_version_id' => $versionId,
            'woo_order_id' => 601,
        ));

        $this->storeOrder(601, array('_sbdp_quote_id' => $quoteId, '_sbdp_quote_version_id' => $versionId + 1));
        $service = new QuotePaymentSyncService($this->repository, $this->events);
        $wrongVersion = $service->syncOrderPayment(601);
        self::assertFalse($wrongVersion['ok']);
        self::assertSame('quote_version_mismatch', $wrongVersion['code']);
        self::assertSame('not_ready', (string) $this->repository->findQuote($quoteId)['handoff_status']);

        $this->storeOrder(602, array('_sbdp_quote_id' => $quoteId, '_sbdp_quote_version_id' => $versionId));
        $this->repository->updateQuote($quoteId, array('woo_order_id' => 601));
        $wrongOrder = $service->syncOrderPayment(602);
        self::assertFalse($wrongOrder['ok']);
        self::assertSame('quote_order_mismatch', $wrongOrder['code']);

        [$draftQuote, $draftVersion] = $this->makeQuote('sent');
        $draftQuoteId = (int) $draftQuote['id'];
        $this->repository->updateQuote($draftQuoteId, array(
            'approved_version_id' => (int) $draftVersion['id'],
            'woo_order_id' => 603,
        ));
        $this->storeOrder(603, array('_sbdp_quote_id' => $draftQuoteId, '_sbdp_quote_version_id' => (int) $draftVersion['id']));
        $notAccepted = $service->syncOrderPayment(603);
        self::assertFalse($notAccepted['ok']);
        self::assertSame('quote_not_accepted', $notAccepted['code']);
    }

    public function testPaymentCompleteAndConfirmationRequireMatchingAcceptedQuoteEventChain(): void
    {
        [$quote, $version] = $this->makeQuote('accepted');
        $quoteId = (int) $quote['id'];
        $versionId = (int) $version['id'];
        $this->repository->updateQuote($quoteId, array(
            'approved_version_id' => $versionId,
            'woo_order_id' => 701,
        ));
        $this->storeOrder(701, array('_sbdp_quote_id' => $quoteId, '_sbdp_quote_version_id' => $versionId), 'txn-701');

        $confirmation = new QuoteConfirmationService($this->repository, $this->events);
        $beforePayment = $confirmation->confirmPaidQuoteOrder(701);
        self::assertFalse($beforePayment['ok']);
        self::assertSame('payment_handoff_not_completed', $beforePayment['code']);

        $payment = (new QuotePaymentSyncService($this->repository, $this->events))->syncOrderPayment(701);
        self::assertTrue($payment['ok']);
        self::assertSame('quote_payment_completed', $payment['code']);
        self::assertSame(QuotePaymentSyncService::COMPLETED_STATUS, (string) $this->repository->findQuote($quoteId)['handoff_status']);

        $confirmed = $confirmation->confirmPaidQuoteOrder(701);
        self::assertTrue($confirmed['ok']);
        self::assertSame('quote_confirmed', $confirmed['code']);
        self::assertSame('confirmed', (string) $this->repository->findQuote($quoteId)['status']);
    }

    public function testOperationsBookingRequiresConfirmedStatusAndRequiredEvents(): void
    {
        [$quote, $version] = $this->makeQuote('accepted');
        $quoteId = (int) $quote['id'];
        $versionId = (int) $version['id'];
        $this->repository->updateQuote($quoteId, array(
            'approved_version_id' => $versionId,
            'woo_order_id' => 801,
            'handoff_status' => 'woo_cart_hydrated',
        ));

        $bookingManager = (new \ReflectionClass(\BSP\Bookings\Service\BookingManager::class))->newInstanceWithoutConstructor();
        $service = new QuoteBookingBridgeService($this->repository, $this->events, $bookingManager);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('confirmed quote');
        $service->createOperationsBooking($quoteId);
    }

    public function testOperationsBookingRequiresPaymentEventAndOrderMetaMatch(): void
    {
        [$quote, $version] = $this->makeQuote('confirmed');
        $quoteId = (int) $quote['id'];
        $versionId = (int) $version['id'];
        $this->repository->updateQuote($quoteId, array(
            'approved_version_id' => $versionId,
            'woo_order_id' => 811,
            'handoff_status' => QuotePaymentSyncService::COMPLETED_STATUS,
        ));
        $this->storeOrder(811, array('_sbdp_quote_id' => $quoteId, '_sbdp_quote_version_id' => $versionId + 1));
        $bookingManager = (new \ReflectionClass(\BSP\Bookings\Service\BookingManager::class))->newInstanceWithoutConstructor();
        $service = new QuoteBookingBridgeService($this->repository, $this->events, $bookingManager);

        try {
            $service->createOperationsBooking($quoteId);
            self::fail('Expected order meta mismatch guard.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('quote_version_id matcht niet', $exception->getMessage());
        }

        $this->storeOrder(811, array('_sbdp_quote_id' => $quoteId, '_sbdp_quote_version_id' => $versionId));
        $this->events->log('quote_confirmed', null, $quoteId, $versionId, null, 'confirmed');
        try {
            $service->createOperationsBooking($quoteId);
            self::fail('Expected missing payment event guard.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString(QuotePaymentSyncService::COMPLETED_EVENT, $exception->getMessage());
        }
    }

    public function testRequestOrderFlowDoesNotRequireDirectCartHydrationWhenOrderPaymentAndConfirmationAreVerified(): void
    {
        [$quote, $version] = $this->makeQuote('confirmed');
        $quoteId = (int) $quote['id'];
        $versionId = (int) $version['id'];
        $this->repository->updateQuote($quoteId, array(
            'approved_version_id' => $versionId,
            'woo_order_id' => 812,
            'handoff_status' => QuotePaymentSyncService::COMPLETED_STATUS,
        ));
        $this->repository->updateQuoteVersion($versionId, array(
            'handoff_payload_json' => array(
                'execution_adapter' => array(
                    'adapter_type' => 'cart_order_prep',
                    'items' => array(),
                ),
            ),
        ));
        $this->storeOrder(812, array('_sbdp_quote_id' => $quoteId, '_sbdp_quote_version_id' => $versionId));
        $this->events->log('quote_woo_request_order_created', null, $quoteId, $versionId, null, 'order created');
        $this->events->log(QuotePaymentSyncService::COMPLETED_EVENT, null, $quoteId, $versionId, null, 'paid', array('order_id' => 812));
        $this->events->log('quote_confirmed', null, $quoteId, $versionId, null, 'confirmed');

        $bookingManager = (new \ReflectionClass(\BSP\Bookings\Service\BookingManager::class))->newInstanceWithoutConstructor();
        $service = new QuoteBookingBridgeService($this->repository, $this->events, $bookingManager);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Booking bridge vond geen booking items.');
        $service->createOperationsBooking($quoteId);
    }

    public function testPriceMismatchBlocksRequestOrderAndLogsReviewEvent(): void
    {
        $GLOBALS['__quote_lifecycle_product_prices'][352] = 22.50;
        [$quote, $version] = $this->makeQuote('accepted');
        $quoteId = (int) $quote['id'];
        $versionId = (int) $version['id'];
        $this->repository->updateQuote($quoteId, array('approved_version_id' => $versionId));
        $this->repository->updateQuoteVersion($versionId, array(
            'handoff_payload_json' => array(
                'execution_adapter' => array(
                    'adapter_type' => 'cart_order_prep',
                    'request_context' => array('group_size' => 6),
                    'totals' => array('currency' => 'EUR', 'display_total' => 120),
                    'items' => array(array(
                        'product_id' => 999352,
                        'start' => '2026-08-15T14:00:00+01:00',
                        'end' => '2026-08-15T15:30:00+01:00',
                        'participants' => 6,
                        'sbdp_pricing' => array('woo_unit_price' => 22.50),
                    )),
                ),
            ),
        ));
        $this->repository->replaceQuoteLines($versionId, array(array(
            'line_type' => 'product',
            'line_status' => 'mapped',
            'title' => 'Bierproeverij',
            'product_id' => 999352,
            'participants' => 6,
            'line_total_snapshot' => 120.00,
            'currency' => 'EUR',
        )));

        $service = new QuoteRequestOrderBridgeService($this->repository, $this->events);
        try {
            $service->createWooRequestOrder($quoteId, 1);
            self::fail('Expected price mismatch guard.');
        } catch (HandoffValidationException $exception) {
            self::assertSame('bsp_quotes_handoff_price_mismatch', $exception->restCode());
            self::assertSame(409, $exception->status());
            self::assertSame(120.00, $exception->context()['proposal_total']);
            self::assertSame(135.00, $exception->context()['woo_total']);
        }

        $storedQuote = $this->repository->findQuote($quoteId);
        self::assertSame('price_mismatch_requires_review', (string) $storedQuote['handoff_status']);
        $events = $this->repository->listQuoteEvents($quoteId);
        self::assertContains('quote_order_price_mismatch', array_column($events, 'event_type'));
        $summary = (new QuoteAdminStatusSummaryService($this->repository))->summarize($quoteId);
        self::assertSame('Prijsverschil controleren', $summary['next_action']);
    }

    public function testCreatedWooOrderTotalMismatchMarksOrderAndBlocksHandoff(): void
    {
        [$quote, $version] = $this->makeQuote('accepted');
        $quoteId = (int) $quote['id'];
        $versionId = (int) $version['id'];
        $this->repository->updateQuote($quoteId, array('approved_version_id' => $versionId));
        $this->repository->replaceQuoteLines($versionId, array(array(
            'line_type' => 'product',
            'line_status' => 'mapped',
            'title' => 'Smoke product',
            'product_id' => 3391,
            'participants' => 6,
            'line_total_snapshot' => 120.00,
            'currency' => 'EUR',
        )));
        $this->storeOrder(3392, array('_sbdp_quote_id' => $quoteId, '_sbdp_quote_version_id' => $versionId), '', 105.60);
        $service = new QuoteRequestOrderBridgeService($this->repository, $this->events);
        $method = new \ReflectionMethod($service, 'assertCreatedWooOrderMatchesProposal');
        $method->setAccessible(true);

        $result = $method->invoke($service, $this->repository->findQuote($quoteId), $this->repository->findQuoteVersion($versionId), array('totals' => array('currency' => 'EUR')), 3392, 1);

        self::assertTrue($result['blocked']);
        self::assertSame(120.00, $result['proposal_total']);
        self::assertSame(105.60, $result['woo_total']);
        self::assertSame(-14.40, $result['delta']);
        self::assertSame('actual_woo_order_total', $result['source']);
        self::assertSame('price_mismatch_requires_review', (string) $this->repository->findQuote($quoteId)['handoff_status']);
        self::assertSame(3392, (int) $this->repository->findQuote($quoteId)['woo_order_id']);
        self::assertSame('yes', $GLOBALS['__quote_lifecycle_orders'][3392]->get_meta('_sbdp_quote_price_mismatch_requires_review'));
        self::assertContains('quote_order_price_mismatch', array_column($this->repository->listQuoteEvents($quoteId), 'event_type'));
    }

    public function testAcceptedRequestOrderNormalizesWooTotalToApprovedProposalBeforeFinalGuard(): void
    {
        [$quote, $version] = $this->makeQuote('accepted');
        $quoteId = (int) $quote['id'];
        $versionId = (int) $version['id'];
        $this->repository->updateQuote($quoteId, array('approved_version_id' => $versionId));
        $this->repository->replaceQuoteLines($versionId, array(array(
            'line_type' => 'product',
            'line_status' => 'mapped',
            'title' => 'Smoke product',
            'product_id' => 3391,
            'participants' => 6,
            'line_total_snapshot' => 120.00,
            'currency' => 'EUR',
        )));
        $orderItem = new \BSP_Test_WC_Order_Item_Product(105.60, 105.60);
        $this->storeOrder(3395, array('_sbdp_quote_id' => $quoteId, '_sbdp_quote_version_id' => $versionId), '', 105.60, array($orderItem));
        $service = new QuoteRequestOrderBridgeService($this->repository, $this->events);

        $normalize = new \ReflectionMethod($service, 'applyApprovedProposalTotalToOrder');
        $normalize->setAccessible(true);
        $normalize->invoke($service, $this->repository->findQuote($quoteId), $this->repository->findQuoteVersion($versionId), array('totals' => array('currency' => 'EUR')), 3395);

        $check = new \ReflectionMethod($service, 'assertCreatedWooOrderMatchesProposal');
        $check->setAccessible(true);
        $result = $check->invoke($service, $this->repository->findQuote($quoteId), $this->repository->findQuoteVersion($versionId), array('totals' => array('currency' => 'EUR')), 3395, 1);

        self::assertFalse($result['blocked']);
        self::assertSame(120.00, $result['proposal_total']);
        self::assertSame(120.00, $result['woo_total']);
        self::assertSame(120.00, $GLOBALS['__quote_lifecycle_orders'][3395]->get_total());
        self::assertSame(120.00, $orderItem->get_total());
        self::assertSame(120.00, $orderItem->get_meta('sbdp_display_total'));
        self::assertSame('approved_quote_version', $GLOBALS['__quote_lifecycle_orders'][3395]->get_meta('_sbdp_quote_total_source'));
        self::assertSame('yes', $GLOBALS['__quote_lifecycle_orders'][3395]->get_meta('_sbdp_quote_total_normalized'));
    }

    public function testRevisionAndDeclineDoNotCreateWooOrder(): void
    {
        [$quote, $version] = $this->makeQuote('sent');
        $token = $this->makePublicProposalToken($quote, $version);
        $service = new PublicQuoteProposalService($this->repository, $this->events, new PublicQuoteProposalTokenService());

        $revision = $service->requestRevision($token, 'Graag een andere starttijd.', array('ip' => '127.0.0.1', 'user_agent' => 'phpunit'));
        self::assertSame('revision_requested', (string) $revision['status']);
        self::assertSame(0, (int) ($this->repository->findQuote((int) $quote['id'])['woo_order_id'] ?? 0));

        [$declineQuote, $declineVersion] = $this->makeQuote('sent');
        $declineToken = $this->makePublicProposalToken($declineQuote, $declineVersion);
        $declined = $service->decline($declineToken, 'Past niet.', array('ip' => '127.0.0.1', 'user_agent' => 'phpunit'));
        self::assertSame('declined', (string) $declined['status']);
        self::assertSame(0, (int) ($this->repository->findQuote((int) $declineQuote['id'])['woo_order_id'] ?? 0));
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function makeQuote(string $status): array
    {
        $request = $this->repository->createQuoteRequest(array(
            'request_reference' => 'REQ-' . uniqid(),
            'request_summary' => 'Lifecycle test',
            'requester_email' => 'test@example.test',
            'group_size' => 4,
        ));
        $quote = $this->repository->createQuote(array(
            'quote_reference' => 'Q-' . uniqid(),
            'quote_request_id' => (int) $request['id'],
            'status' => $status,
            'review_status' => 'approved',
            'send_status' => $status === 'sent' ? 'sent_manual' : 'not_ready',
            'handoff_status' => 'not_ready',
            'current_version_id' => 0,
            'approved_version_id' => 0,
            'woo_order_id' => 0,
            'booking_master_id' => 0,
        ));
        $version = $this->repository->createQuoteVersion(array(
            'quote_id' => (int) $quote['id'],
            'version_number' => 1,
            'status' => $status === 'sent' ? 'sent' : 'draft',
            'proposal_title' => 'Lifecycle proposal',
        ));
        $this->repository->updateQuote((int) $quote['id'], array('current_version_id' => (int) $version['id']));
        $this->repository->replaceQuoteLines((int) $version['id'], array(
            array(
                'line_number' => 1,
                'sort_order' => 1,
                'line_type' => 'product',
                'line_status' => 'mapped',
                'title' => 'Testactiviteit',
                'product_id' => 352,
                'participants' => 4,
                'pricing_confidence' => 'execution_verified',
                'availability_confidence' => 'confirmed',
                'availability_snapshot_json' => array('slots' => array(array('start' => '10:00'))),
            ),
        ));

        if ($status === 'sent') {
            $this->repository->createQuoteMessage(array(
                'quote_id' => (int) $quote['id'],
                'quote_version_id' => (int) $version['id'],
                'direction' => 'outbound',
                'message_type' => 'proposal',
                'status' => 'sent',
                'channel' => 'email',
            ));
        }

        return array($this->repository->findQuote((int) $quote['id']), $version);
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     */
    private function makePublicProposalToken(array $quote, array $version): string
    {
        return (new PublicQuoteProposalTokenService())->create(
            (int) $quote['id'],
            (int) $version['id'],
            (string) $quote['quote_reference']
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function storeOrder(int $orderId, array $meta, string $transactionId = '', float $total = 0.0, array $items = array()): void
    {
        $GLOBALS['__quote_lifecycle_orders'][$orderId] = new \WC_Order($orderId, $meta, $transactionId, $total, $items);
    }
}
}
