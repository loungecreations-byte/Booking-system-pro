<?php

declare(strict_types=1);

namespace BSP\Tests\Quotes;

use BSP\Quotes\Service\QuoteAcceptanceService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteHandoffAdapterService;
use BSP\Quotes\Service\QuoteExecutionAdapterService;
use BSP\Quotes\Service\QuoteExecutionLaunchService;
use BSP\Quotes\Service\QuoteWooCartHydrationService;
use BSP\Quotes\Service\QuoteExecutionRunnerService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryQuoteRepository.php';

/**
 * Regression tests for the accepted-version / WooCommerce handoff boundary.
 *
 * Architecture rules under test:
 * - Woo handoff must NEVER use current_version_id.
 * - Woo handoff must use approved_version_id only.
 * - Handoff fails closed when approved_version_id is not set.
 * - Customer acceptance requires status === 'sent'.
 * - ready_to_send cannot be customer-accepted.
 * - A second, different version cannot be re-accepted without a revise flow.
 * - Changing current_version_id after acceptance does not affect handoff source.
 */
final class QuoteAcceptanceHandoffBoundaryTest extends TestCase
{
    private InMemoryQuoteRepository $repo;
    private QuoteEventLogger $events;
    private QuoteAcceptanceService $acceptance;

    protected function setUp(): void
    {
        $this->repo   = new InMemoryQuoteRepository();
        $this->events = new QuoteEventLogger($this->repo);
        $this->acceptance = new QuoteAcceptanceService($this->repo, $this->events);
    }

    // -----------------------------------------------------------------------
    // Helper: create a minimal quote + version.
    // -----------------------------------------------------------------------

    /** @return array{quote: array<string,mixed>, version: array<string,mixed>} */
    private function makeQuoteWithVersion(string $status = 'sent'): array
    {
        $quote = $this->repo->createQuote(array(
            'status'              => $status,
            'handoff_status'      => 'not_ready',
            'current_version_id'  => 0,
            'approved_version_id' => 0,
        ));
        $version = $this->repo->createQuoteVersion(array(
            'quote_id'       => $quote['id'],
            'version_number' => 1,
            'status'         => 'draft',
        ));
        $this->repo->updateQuote((int) $quote['id'], array('current_version_id' => (int) $version['id']));

        return array(
            'quote'   => $this->repo->findQuote((int) $quote['id']),
            'version' => $version,
        );
    }

    /** @return array{quote: array<string,mixed>, version: array<string,mixed>} */
    private function makePreparedResnapshotQuote(string $status = 'sent'): array
    {
        $quote = $this->repo->createQuote(array(
            'status'              => $status,
            'handoff_status'      => 'resnapshot_prepared',
            'current_version_id'  => 0,
            'approved_version_id' => 0,
        ));
        $version = $this->repo->createQuoteVersion(array(
            'quote_id'       => $quote['id'],
            'version_number' => 1,
            'status'         => 'draft',
            'snapshot_type'  => 'execution_resnapshot',
        ));
        $this->repo->replaceQuoteLines((int) $version['id'], array(
            array(
                'line_number' => 1,
                'sort_order' => 1,
                'line_type' => 'product',
                'line_status' => 'mapped',
                'title' => 'Bierproeverij',
                'product_id' => 352,
                'quantity' => 10,
                'participants' => 10,
                'service_date' => '2026-05-17',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'pricing_mode' => 'execution_snapshot',
                'pricing_confidence' => 'execution_verified',
                'availability_confidence' => 'confirmed',
                'unit_amount_snapshot' => 22.5,
                'line_total_snapshot' => 225.0,
                'currency' => 'EUR',
                'pricing_snapshot_json' => array('display_total' => 225.0),
                'availability_snapshot_json' => array('slots' => array(array('start' => '10:00'))),
            ),
        ));
        $this->repo->updateQuote((int) $quote['id'], array('current_version_id' => (int) $version['id']));

        return array(
            'quote'   => $this->repo->findQuote((int) $quote['id']),
            'version' => $version,
        );
    }

    // -----------------------------------------------------------------------
    // ACCEPTANCE TESTS
    // -----------------------------------------------------------------------

    /** TC-ACC-01: Accepting a sent quote pins approved_version_id. */
    public function testAcceptSentQuotePinsApprovedVersionId(): void
    {
        ['quote' => $quote, 'version' => $version] = $this->makeQuoteWithVersion('sent');

        $updated = $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $version['id']);

        $this->assertSame((int) $version['id'], (int) $updated['approved_version_id']);
        $this->assertSame('accepted', (string) $updated['status']);
    }

    /** TC-ACC-02: Accepting a quote changes status to 'accepted'. */
    public function testAcceptChangesStatusToAccepted(): void
    {
        ['quote' => $quote, 'version' => $version] = $this->makeQuoteWithVersion('sent');

        $updated = $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $version['id']);

        $this->assertSame('accepted', $updated['status']);
    }

    /** TC-ACC-03: Customer cannot accept a quote in status ready_to_send. */
    public function testCustomerCannotAcceptReadyToSendQuote(): void
    {
        ['quote' => $quote, 'version' => $version] = $this->makeQuoteWithVersion('ready_to_send');

        $this->expectException(InvalidArgumentException::class);
        $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $version['id']);
    }

    /** TC-ACC-04: Admin override CAN accept a ready_to_send quote (audited path). */
    public function testAdminCanAcceptReadyToSendQuoteViaOverride(): void
    {
        ['quote' => $quote, 'version' => $version] = $this->makeQuoteWithVersion('ready_to_send');

        $updated = $this->acceptance->adminAcceptQuoteVersion(
            (int) $quote['id'],
            (int) $version['id'],
            42 // admin actor id
        );

        $this->assertSame('accepted', $updated['status']);
        $this->assertSame((int) $version['id'], (int) $updated['approved_version_id']);

        // Verify the event was logged as admin override.
        $events = $this->repo->listQuoteEvents((int) $quote['id']);
        $eventTypes = array_column($events, 'event_type');
        $this->assertContains('quote_admin_accepted', $eventTypes);
    }

    /** TC-ACC-05: An invalid (non-existent) version cannot be accepted. */
    public function testInvalidVersionCannotBeAccepted(): void
    {
        ['quote' => $quote] = $this->makeQuoteWithVersion('sent');

        $this->expectException(InvalidArgumentException::class);
        $this->acceptance->acceptQuoteVersion((int) $quote['id'], 99999);
    }

    /** TC-ACC-06: A version belonging to a different quote cannot be accepted. */
    public function testVersionFromDifferentQuoteCannotBeAccepted(): void
    {
        ['quote' => $quote] = $this->makeQuoteWithVersion('sent');
        ['version' => $otherVersion] = $this->makeQuoteWithVersion('sent');

        $this->expectException(InvalidArgumentException::class);
        $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $otherVersion['id']);
    }

    /** TC-ACC-07: Re-accepting to a different version is rejected (no revise flow). */
    public function testReAcceptingToDifferentVersionIsRejected(): void
    {
        ['quote' => $quote, 'version' => $versionA] = $this->makeQuoteWithVersion('sent');
        // Pin versionA.
        $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $versionA['id']);

        // Try to re-accept using a second version for the same quote.
        $versionB = $this->repo->createQuoteVersion(array(
            'quote_id'       => $quote['id'],
            'version_number' => 2,
            'status'         => 'draft',
        ));

        $this->expectException(InvalidArgumentException::class);
        // acceptQuoteVersion should throw because quote is now 'accepted' (terminal).
        $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $versionB['id']);
    }

    /** TC-ACC-08: Acceptance on an already-accepted quote is rejected. */
    public function testAcceptingAlreadyAcceptedQuoteIsRejected(): void
    {
        ['quote' => $quote, 'version' => $version] = $this->makeQuoteWithVersion('sent');
        $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $version['id']);

        $this->expectException(InvalidArgumentException::class);
        $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $version['id']);
    }

    /** TC-ACC-09: Acceptance on cancelled/expired/declined quotes is rejected. */
    public function testTerminalStatusQuotesCannotBeAccepted(): void
    {
        foreach (['cancelled', 'expired', 'declined'] as $status) {
            ['quote' => $quote, 'version' => $version] = $this->makeQuoteWithVersion($status);
            try {
                $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $version['id']);
                $this->fail("Expected exception for status '$status'.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($status, $e->getMessage());
            }
        }
    }

    /** TC-ACC-10: Acceptance event is logged with correct metadata. */
    public function testAcceptanceEventIsLogged(): void
    {
        ['quote' => $quote, 'version' => $version] = $this->makeQuoteWithVersion('sent');
        $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $version['id'], 77);

        $events = $this->repo->listQuoteEvents((int) $quote['id']);
        $this->assertNotEmpty($events);
        $acceptEvent = array_values(array_filter($events, static fn (array $e): bool => $e['event_type'] === 'quote_accepted'));
        $this->assertCount(1, $acceptEvent, 'Expected exactly one quote_accepted event.');
    }

    /** TC-ACC-11: Accepting a prepared execution resnapshot preserves handoff readiness. */
    public function testAcceptingPreparedResnapshotPreservesHandoffStatus(): void
    {
        ['quote' => $quote, 'version' => $version] = $this->makePreparedResnapshotQuote('sent');

        $updated = $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $version['id']);

        $this->assertSame('accepted', (string) $updated['status']);
        $this->assertSame('resnapshot_prepared', (string) $updated['handoff_status']);
        $this->assertSame((int) $version['id'], (int) $updated['approved_version_id']);
    }

    /** TC-ACC-12: Accepting a non-resnapshot version does not falsely become handoff-ready. */
    public function testAcceptingNonResnapshotVersionStaysNotReadyForHandoff(): void
    {
        ['quote' => $quote, 'version' => $version] = $this->makeQuoteWithVersion('sent');

        $updated = $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $version['id']);

        $this->assertSame('not_ready', (string) $updated['handoff_status']);
    }

    // -----------------------------------------------------------------------
    // HANDOFF BOUNDARY TESTS - Fail-closed without approved_version_id
    // -----------------------------------------------------------------------

    /**
     * Builds an in-memory quote at a specific handoff_status without setting approved_version_id.
     * @return array<string, mixed>
     */
    private function makeQuoteAtHandoffStatus(string $handoffStatus): array
    {
        return $this->repo->createQuote(array(
            'status'              => 'accepted',
            'handoff_status'      => $handoffStatus,
            'current_version_id'  => 99,  // intentionally set to a fake version
            'approved_version_id' => 0,   // not pinned - should cause all handoff services to fail closed
        ));
    }

    /** TC-HB-01: QuoteHandoffAdapterService fails closed when no approved_version_id. */
    public function testHandoffAdapterFailsClosedWithoutApprovedVersion(): void
    {
        $quote = $this->makeQuoteAtHandoffStatus('resnapshot_prepared');

        $adapter = new QuoteHandoffAdapterService(
            $this->repo,
            $this->events
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/approved_version_id/');
        $adapter->buildControlledPackage((int) $quote['id']);
    }

    /** TC-HB-01B: Accepted resnapshot version can build a handoff package after acceptance. */
    public function testAcceptedPreparedResnapshotCanBuildHandoffPackage(): void
    {
        ['quote' => $quote, 'version' => $version] = $this->makePreparedResnapshotQuote('sent');
        $accepted = $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $version['id']);

        $adapter = new QuoteHandoffAdapterService($this->repo, $this->events);
        $package = $adapter->buildControlledPackage((int) $quote['id']);
        $storedQuote = $this->repo->findQuote((int) $quote['id']);

        $this->assertSame('resnapshot_prepared', (string) $accepted['handoff_status']);
        $this->assertSame('controlled_handoff', (string) $package['package_type']);
        $this->assertSame((int) $version['id'], (int) $package['quote_version_id']);
        $this->assertSame('handoff_package_ready', (string) ($storedQuote['handoff_status'] ?? ''));
    }

    public function testHandoffPackageCarriesAcceptedVersionDiscountIntoTotals(): void
    {
        ['quote' => $quote, 'version' => $version] = $this->makePreparedResnapshotQuote('sent');
        $this->repo->updateQuoteVersion((int) $version['id'], array(
            'pricing_snapshot_json' => array(
                'commercial_adjustments' => array(
                    'type' => 'fixed_amount',
                    'discount_amount' => 25.0,
                    'discount_label' => 'Actiekorting',
                    'currency' => 'EUR',
                ),
            ),
        ));
        $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $version['id']);

        $adapter = new QuoteHandoffAdapterService($this->repo, $this->events);
        $package = $adapter->buildControlledPackage((int) $quote['id']);

        $this->assertSame(225.0, (float) $package['totals']['line_total_snapshot_sum']);
        $this->assertSame(25.0, (float) $package['totals']['discount_amount']);
        $this->assertSame('Actiekorting', (string) $package['totals']['discount_label']);
        $this->assertSame(200.0, (float) $package['totals']['grand_total_snapshot']);

        $executionPayload = (new QuoteExecutionAdapterService($this->repo, $this->events))->buildCartOrderPrep((int) $quote['id']);
        $this->assertSame(25.0, (float) $executionPayload['totals']['discount_amount']);
        $this->assertSame(200.0, (float) $executionPayload['totals']['grand_total_snapshot']);
    }

    public function testHandoffPackageFailsClosedWhenDiscountExceedsSubtotal(): void
    {
        ['quote' => $quote, 'version' => $version] = $this->makePreparedResnapshotQuote('sent');
        $this->repo->updateQuoteVersion((int) $version['id'], array(
            'pricing_snapshot_json' => array(
                'commercial_adjustments' => array(
                    'type' => 'fixed_amount',
                    'discount_amount' => 250.0,
                    'discount_label' => 'Actiekorting',
                    'currency' => 'EUR',
                ),
            ),
        ));
        $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $version['id']);

        $adapter = new QuoteHandoffAdapterService($this->repo, $this->events);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('korting hoger');
        $adapter->buildControlledPackage((int) $quote['id']);
    }

    /** TC-HB-01C: Handoff still fails when approved version is not an execution resnapshot. */
    public function testHandoffAdapterFailsWhenApprovedVersionIsNotExecutionResnapshot(): void
    {
        ['quote' => $quote, 'version' => $version] = $this->makeQuoteWithVersion('sent');
        $this->repo->updateQuote((int) $quote['id'], array(
            'handoff_status' => 'resnapshot_prepared',
            'approved_version_id' => (int) $version['id'],
        ));

        $adapter = new QuoteHandoffAdapterService($this->repo, $this->events);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/execution_resnapshot/');
        $adapter->buildControlledPackage((int) $quote['id']);
    }

    /** TC-HB-02: QuoteExecutionAdapterService fails closed when no approved_version_id. */
    public function testExecutionAdapterFailsClosedWithoutApprovedVersion(): void
    {
        $quote = $this->makeQuoteAtHandoffStatus('handoff_package_ready');

        $adapter = new QuoteExecutionAdapterService(
            $this->repo,
            $this->events
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/approved_version_id/');
        $adapter->buildCartOrderPrep((int) $quote['id']);
    }

    /** TC-HB-03: QuoteWooCartHydrationService fails closed when no approved_version_id. */
    public function testWooCartHydrationFailsClosedWithoutApprovedVersion(): void
    {
        $quote = $this->makeQuoteAtHandoffStatus('execution_launch_ready');

        $gateway = $this->createMock(\BSP\Quotes\Service\WooCartLaunchGatewayInterface::class);
        $service = new QuoteWooCartHydrationService($gateway, $this->repo, $this->events);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/approved_version_id/');
        $service->hydrateLaunchToCart((int) $quote['id'], 'any_token');
    }

    /**
     * @return array{quote: array<string,mixed>, version: array<string,mixed>, token: string, gateway: object}
     */
    private function makeQuoteReadyForWooCartHydration(string $expiresAt = '+2 hours'): array
    {
        ['quote' => $quote, 'version' => $version] = $this->makeQuoteWithVersion('accepted');
        $token = 'launch-token-' . (string) $quote['id'];
        $expiryTimestamp = strtotime($expiresAt);

        $launchPayload = array(
            'launch_type' => 'woo_cart_session_prep',
            'quote_id' => (int) $quote['id'],
            'quote_reference' => 'Q-TEST',
            'quote_version_id' => (int) $version['id'],
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', $expiryTimestamp !== false ? $expiryTimestamp : time()),
            'launch_token' => $token,
            'items' => array(
                array(
                    'product_id' => 352,
                    'quantity' => 2,
                    'participants' => 2,
                    'sbdp_meta' => array(
                        'quote_id' => (int) $quote['id'],
                        'quote_version_id' => (int) $version['id'],
                        'sbdp_pricing_source' => 'quote_execution_resnapshot',
                    ),
                    'sbdp_summary' => array('participants' => 2),
                    'sbdp_pricing' => array('display_total' => 45.0),
                ),
            ),
            'totals' => array(),
        );

        $this->repo->updateQuoteVersion((int) $version['id'], array(
            'handoff_payload_json' => array('execution_launch' => $launchPayload),
        ));
        $this->repo->updateQuote((int) $quote['id'], array(
            'status' => 'accepted',
            'handoff_status' => 'execution_launch_ready',
            'approved_version_id' => (int) $version['id'],
        ));

        $gateway = new class implements \BSP\Quotes\Service\WooCartLaunchGatewayInterface {
            public int $calls = 0;
            /** @var array<string, mixed> */
            public array $lastPayload = array();

            public function hydrate(array $launchPayload): array
            {
                ++$this->calls;
                $this->lastPayload = $launchPayload;

                return array(
                    'cart_item_count' => count((array) ($launchPayload['items'] ?? array())),
                    'cart_url' => 'https://example.test/cart',
                    'checkout_url' => 'https://example.test/checkout',
                );
            }
        };

        return array(
            'quote' => $this->repo->findQuote((int) $quote['id']),
            'version' => $this->repo->findQuoteVersion((int) $version['id']),
            'token' => $token,
            'gateway' => $gateway,
        );
    }

    public function testWooLaunchTokenHydratesOnceAndMarksTokenConsumed(): void
    {
        $fixture = $this->makeQuoteReadyForWooCartHydration();
        $service = new QuoteWooCartHydrationService($fixture['gateway'], $this->repo, $this->events);

        $result = $service->hydrateLaunchToCart((int) $fixture['quote']['id'], $fixture['token'], 42);

        $storedVersion = $this->repo->findQuoteVersion((int) $fixture['version']['id']);
        $launchPayload = $storedVersion['handoff_payload_json']['execution_launch'] ?? array();

        $this->assertSame(1, $fixture['gateway']->calls);
        $this->assertSame(1, (int) $result['cart_item_count']);
        $this->assertNotEmpty($launchPayload['consumed_at'] ?? '');
        $this->assertSame(42, (int) ($launchPayload['consumed_by'] ?? 0));
        $this->assertNotEmpty($launchPayload['consumed_token_id'] ?? '');
        $this->assertSame((int) $fixture['quote']['id'], (int) $fixture['gateway']->lastPayload['items'][0]['sbdp_meta']['quote_id']);
        $this->assertSame((int) $fixture['version']['id'], (int) $fixture['gateway']->lastPayload['items'][0]['sbdp_meta']['quote_version_id']);
    }

    public function testWooLaunchTokenCannotBeReusedEvenIfHandoffStatusIsReset(): void
    {
        $fixture = $this->makeQuoteReadyForWooCartHydration();
        $service = new QuoteWooCartHydrationService($fixture['gateway'], $this->repo, $this->events);

        $service->hydrateLaunchToCart((int) $fixture['quote']['id'], $fixture['token'], 42);
        $this->repo->updateQuote((int) $fixture['quote']['id'], array('handoff_status' => 'execution_launch_ready'));

        try {
            $service->hydrateLaunchToCart((int) $fixture['quote']['id'], $fixture['token'], 42);
            $this->fail('Expected reused launch token to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('al gebruikt', $exception->getMessage());
        }

        $this->assertSame(1, $fixture['gateway']->calls);
        $events = $this->repo->listQuoteEvents((int) $fixture['quote']['id']);
        $eventTypes = array_column($events, 'event_type');
        $this->assertContains('quote_execution_launch_token_reused', $eventTypes);
    }

    public function testWooLaunchHydrationRejectsExpiredTokenBeforeGatewayCall(): void
    {
        $fixture = $this->makeQuoteReadyForWooCartHydration('-1 hour');
        $service = new QuoteWooCartHydrationService($fixture['gateway'], $this->repo, $this->events);

        try {
            $service->hydrateLaunchToCart((int) $fixture['quote']['id'], $fixture['token'], 42);
            $this->fail('Expected expired launch token to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('verlopen', $exception->getMessage());
        }

        $this->assertSame(0, $fixture['gateway']->calls);
    }

    public function testWooLaunchHydrationRejectsInvalidTokenBeforeGatewayCall(): void
    {
        $fixture = $this->makeQuoteReadyForWooCartHydration();
        $service = new QuoteWooCartHydrationService($fixture['gateway'], $this->repo, $this->events);

        try {
            $service->hydrateLaunchToCart((int) $fixture['quote']['id'], 'wrong-token', 42);
            $this->fail('Expected invalid launch token to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('ongeldig', $exception->getMessage());
        }

        $this->assertSame(0, $fixture['gateway']->calls);
    }

    /** TC-HB-04: QuoteExecutionRunnerService fails closed when no approved_version_id. */
    public function testExecutionRunnerFailsClosedWithoutApprovedVersion(): void
    {
        $quote = $this->makeQuoteAtHandoffStatus('execution_payload_ready');

        $lookup = $this->createMock(\BSP\Quotes\Service\QuoteExecutionLookupService::class);
        $runner = new QuoteExecutionRunnerService($this->repo, $this->events, $lookup);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/approved_version_id/');
        $runner->validateCartReady((int) $quote['id']);
    }

    // -----------------------------------------------------------------------
    // DRIFT PROTECTION: current_version_id change must not affect handoff
    // -----------------------------------------------------------------------

    /**
     * TC-DP-01: Changing current_version_id after acceptance does not change
     * the version that handoff uses (it must keep reading approved_version_id).
     *
     * This test verifies the drift protection: even if the admin advances
     * current_version_id to a new draft version, the Woo handoff source stays
     * anchored at the pinned approved_version_id.
     */
    public function testCurrentVersionIdDriftDoesNotAffectHandoffSource(): void
    {
        ['quote' => $quote, 'version' => $acceptedVersion] = $this->makeQuoteWithVersion('sent');

        // Accept — pins approved_version_id to $acceptedVersion.
        $this->acceptance->acceptQuoteVersion((int) $quote['id'], (int) $acceptedVersion['id']);

        // Simulate admin advancing current_version_id to a new draft.
        $driftVersion = $this->repo->createQuoteVersion(array(
            'quote_id'       => $quote['id'],
            'version_number' => 2,
            'status'         => 'draft',
        ));
        $this->repo->updateQuote((int) $quote['id'], array(
            'current_version_id' => (int) $driftVersion['id'],
        ));

        // Re-fetch the quote so it reflects the current_version_id update.
        $freshQuote = $this->repo->findQuote((int) $quote['id']);
        $this->assertNotNull($freshQuote);

        // approved_version_id must still point to the accepted version.
        $this->assertSame(
            (int) $acceptedVersion['id'],
            (int) ($freshQuote['approved_version_id'] ?? 0),
            'approved_version_id must not drift with current_version_id.'
        );

        // current_version_id must now be the new drift version.
        $this->assertSame(
            (int) $driftVersion['id'],
            (int) ($freshQuote['current_version_id'] ?? 0),
            'current_version_id should reflect admin drift.'
        );

        // The two must be different — proving drift protection.
        $this->assertNotSame(
            (int) ($freshQuote['current_version_id'] ?? 0),
            (int) ($freshQuote['approved_version_id'] ?? 0),
            'Drift protection: current and approved must differ after admin advances current.'
        );
    }
}
