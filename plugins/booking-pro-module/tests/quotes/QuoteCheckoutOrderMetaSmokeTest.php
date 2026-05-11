<?php

declare(strict_types=1);

namespace {
    if (! function_exists('sanitize_text_field')) {
        function sanitize_text_field($value): string
        {
            return trim((string) $value);
        }
    }
}

namespace BSP\Tests\Quotes {

    use BSP\Quotes\Service\QuoteAssumptionService;
    use BSP\Quotes\Service\QuoteConversionService;
    use BSP\Quotes\Service\QuoteEventLogger;
    use BSP\Quotes\Service\QuoteExecutionAdapterService;
    use BSP\Quotes\Service\QuoteExecutionLaunchService;
    use BSP\Quotes\Service\QuoteExecutionLookupService;
    use BSP\Quotes\Service\QuoteExecutionRunnerService;
    use BSP\Quotes\Service\QuoteFollowupService;
    use BSP\Quotes\Service\QuoteHandoffAdapterService;
    use BSP\Quotes\Service\QuoteHandoffPreparationService;
    use BSP\Quotes\Service\QuoteRequestService;
    use BSP\Quotes\Service\QuoteReviewService;
    use BSP\Quotes\Service\QuoteWooCartHydrationService;
    use BSP\Quotes\Service\WooCartLaunchGatewayInterface;
    use BSPModule\Core\Emails\EmailsService;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/InMemoryQuoteRepository.php';
    require_once dirname(__DIR__, 2) . '/modules/core/Emails/EmailsService.php';

    final class QuoteCheckoutOrderMetaSmokeTest extends TestCase
    {
        public function testQuoteHandoffCarriesPinnedVersionMetadataIntoWooOrderItemMeta(): void
        {
            $repository = new InMemoryQuoteRepository();
            $events = new QuoteEventLogger($repository);
            $requestService = new QuoteRequestService($repository, $events);
            $assumptions = new QuoteAssumptionService($repository, $events);
            $conversion = new QuoteConversionService($repository, $assumptions, $events);
            $followups = new QuoteFollowupService($repository, $events);
            $reviews = new QuoteReviewService($repository, $events, $followups);
            $lookup = new class extends QuoteExecutionLookupService {
                public function lookupPricing(array $line): array
                {
                    return array(
                        'confidence' => 'execution_verified',
                        'payload' => array('display_total' => 99.0),
                        'unit_amount_snapshot' => 33.0,
                        'line_total_snapshot' => 99.0,
                        'currency' => 'EUR',
                    );
                }

                public function lookupAvailability(array $line): array
                {
                    return array(
                        'confidence' => 'confirmed',
                        'available' => true,
                        'payload' => array('slots' => array(array('start' => '14:00'))),
                    );
                }
            };
            $handoffPrep = new QuoteHandoffPreparationService($repository, $assumptions, $events, $lookup);
            $handoffAdapter = new QuoteHandoffAdapterService($repository, $events);
            $executionAdapter = new QuoteExecutionAdapterService($repository, $events);
            $runner = new QuoteExecutionRunnerService($repository, $events, $lookup);
            $launcher = new QuoteExecutionLaunchService($repository, $events);
            $gateway = new class implements WooCartLaunchGatewayInterface {
                public array $lastPayload = array();

                public function hydrate(array $launchPayload): array
                {
                    $this->lastPayload = $launchPayload;

                    return array(
                        'cart_item_count' => count((array) ($launchPayload['items'] ?? array())),
                        'cart_url' => 'https://example.test/cart',
                        'checkout_url' => 'https://example.test/checkout',
                    );
                }
            };
            $hydrator = new QuoteWooCartHydrationService($gateway, $repository, $events);

            $request = $requestService->create(array(
                'request_summary' => 'Quote checkout order meta smoke',
                'requester_email' => 'checkout-meta@example.test',
                'group_size' => 3,
                'preferred_date' => '2026-07-16',
                'items' => array(
                    array(
                        'product_id' => 71,
                        'title' => 'Borrelboot',
                        'participants' => 3,
                        'service_date' => '2026-07-16',
                        'start_time' => '14:00',
                        'end_time' => '16:00',
                        'pricing_confidence' => 'execution_verified',
                        'availability_confidence' => 'confirmed',
                    ),
                ),
            ));
            $quote = $conversion->convertRequestToQuote((int) $request['id'], 12);
            $reviews->approve((int) $quote['id'], 12);
            $ready = $conversion->markReadyForResnapshot((int) $quote['id'], 12);
            $handoffPrep->prepareResnapshot((int) $ready['id'], 12);

            $preparedQuote = $repository->findQuote((int) $ready['id']);
            $approvedVersionId = (int) ($preparedQuote['current_version_id'] ?? 0);
            $repository->updateQuote((int) $ready['id'], array('approved_version_id' => $approvedVersionId));

            $handoffAdapter->buildControlledPackage((int) $ready['id'], 12);
            $executionAdapter->buildCartOrderPrep((int) $ready['id'], 12);
            $runner->validateCartReady((int) $ready['id'], 12);
            $launch = $launcher->buildWooCartSessionPrep((int) $ready['id'], 12);
            $hydrator->hydrateLaunchToCart((int) $ready['id'], (string) $launch['launch_token'], 12);

            $this->assertSame($approvedVersionId, (int) $launch['quote_version_id']);
            $this->assertSame($approvedVersionId, (int) $gateway->lastPayload['quote_version_id']);
            $this->assertCount(1, $gateway->lastPayload['items']);

            $orderItem = new class {
                /** @var array<string, mixed> */
                public array $meta = array();

                public function add_meta_data(string $key, $value, bool $unique = false): void
                {
                    unset($unique);
                    $this->meta[$key] = $value;
                }
            };

            EmailsService::carry_meta(
                $orderItem,
                'quote-cart-line',
                $gateway->lastPayload['items'][0],
                null
            );

            $this->assertSame((int) $ready['id'], (int) $orderItem->meta['quote_id']);
            $this->assertSame($approvedVersionId, (int) $orderItem->meta['quote_version_id']);
            $this->assertSame('quote_execution_resnapshot', (string) $orderItem->meta['sbdp_pricing_source']);
            $this->assertSame(3, (int) $orderItem->meta['sbdp_participants']);
            $this->assertIsArray($orderItem->meta['_sbdp_pricing']);
            $this->assertSame(99.0, (float) $orderItem->meta['_sbdp_pricing']['display_total']);
        }
    }
}
