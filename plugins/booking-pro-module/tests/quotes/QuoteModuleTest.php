<?php

declare(strict_types=1);

namespace BSP\Tests\Quotes;

use BSP\Quotes\Rest\Controller;
use BSP\Quotes\Rest\InboundMailWebhookController;
use BSP\Quotes\Service\QuoteExecutionLaunchService;
use BSP\Quotes\Service\QuoteExecutionRunnerService;
use BSP\Quotes\Service\QuoteExecutionLookupService;
use BSP\Quotes\Service\QuoteHandoffAdapterService;
use BSP\Quotes\Service\OpenAiQuoteDraftAdapter;
use BSP\Quotes\Service\PlannerQuoteIntakeService;
use BSP\Quotes\Service\QuoteAssumptionService;
use BSP\Quotes\Service\QuoteConversionService;
use BSP\Quotes\Service\QuoteCommunicationService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteExecutionAdapterService;
use BSP\Quotes\Service\QuoteFollowupService;
use BSP\Quotes\Service\QuoteHandoffPreparationService;
use BSP\Quotes\Service\QuoteOperationsDraftService;
use BSP\Quotes\Service\QuoteRequestService;
use BSP\Quotes\Service\QuoteReviewService;
use BSP\Quotes\Service\QuoteRequestOrderBridgeService;
use BSP\Quotes\Service\QuoteSendService;
use BSP\Quotes\Service\QuoteWooCartHydrationService;
use BSP\Quotes\Service\WooCartLaunchGatewayInterface;
use BSP\Quotes\Support\Installer;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

require_once __DIR__ . '/InMemoryQuoteRepository.php';

final class QuoteModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb'] = new \wpdb();
        $GLOBALS['__test_options'] = array();
        $GLOBALS['__test_dbdelta_calls'] = array();
        $GLOBALS['__test_rest_routes'] = array();
        $GLOBALS['__test_current_user_can'] = false;
        $GLOBALS['__test_current_user_id'] = 0;
        $GLOBALS['__test_wp_mail_calls'] = array();
        unset($GLOBALS['__test_wp_mail_result'], $GLOBALS['__test_wp_mail_error']);
        $GLOBALS['__test_filters'] = array();
        $GLOBALS['__test_actions'] = array();
        $GLOBALS['__test_wp_remote_post'] = null;
    }

    public function testInstallerCreatesRequiredQuoteTables(): void
    {
        Installer::install();

        $this->assertNotEmpty($GLOBALS['__test_dbdelta_calls']);
        $this->assertArrayHasKey('wp_bsp_quote_requests', $GLOBALS['wpdb']->storage);
        $this->assertArrayHasKey('wp_bsp_quotes', $GLOBALS['wpdb']->storage);
        $this->assertArrayHasKey('wp_bsp_quote_versions', $GLOBALS['wpdb']->storage);
        $this->assertArrayHasKey('wp_bsp_quote_lines', $GLOBALS['wpdb']->storage);
        $this->assertArrayHasKey('wp_bsp_quote_assumptions', $GLOBALS['wpdb']->storage);
        $this->assertArrayHasKey('wp_bsp_quote_followups', $GLOBALS['wpdb']->storage);
        $this->assertArrayHasKey('wp_bsp_quote_events', $GLOBALS['wpdb']->storage);
        $this->assertArrayHasKey('wp_bsp_quote_messages', $GLOBALS['wpdb']->storage);
        $this->assertArrayHasKey('wp_bsp_quote_message_failures', $GLOBALS['wpdb']->storage);
    }

    public function testInstallerSkipsWhenSchemaVersionAndTablesAreCurrent(): void
    {
        Installer::install();
        $initialCalls = count($GLOBALS['__test_dbdelta_calls']);

        Installer::maybeInstall();

        $this->assertCount($initialCalls, $GLOBALS['__test_dbdelta_calls']);
        $this->assertSame('2026-04-21-2', $GLOBALS['__test_options']['bsp_quotes_schema_version'] ?? null);
    }

    public function testQuoteRequestCreationPersistsRequiredFields(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteRequestService($repository, $events);

        $request = $service->create(array(
            'request_summary' => 'Vrijgezellenfeest met diner en activiteit',
            'requester_name'  => 'Eva Example',
            'requester_email' => 'eva@example.test',
            'group_size'      => 12,
            'preferred_date'  => '2026-05-01',
            'items'           => array(
                array(
                    'product_id'              => 101,
                    'title'                   => 'Rondvaart',
                    'participants'            => 12,
                    'pricing_confidence'      => 'snapshot',
                    'availability_confidence' => 'projected',
                ),
            ),
        ));

        $this->assertSame('new', $request['status']);
        $this->assertSame('Eva Example', $request['requester_name']);
        $this->assertSame('eva@example.test', $request['requester_email']);
        $this->assertSame(12, $request['group_size']);
        $this->assertSame('snapshot', $request['pricing_confidence']);
        $this->assertSame('projected', $request['availability_confidence']);
        $this->assertNotEmpty($repository->listQuoteRequests());
    }

    public function testQuoteRequestReferencesStayUniqueForRapidConsecutiveCreates(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteRequestService($repository, $events);

        $first = $service->create(array(
            'request_summary' => 'Snelle aanvraag 1',
            'requester_email' => 'first@example.test',
        ));
        $second = $service->create(array(
            'request_summary' => 'Snelle aanvraag 2',
            'requester_email' => 'second@example.test',
        ));

        $this->assertNotSame($first['request_reference'], $second['request_reference']);
        $this->assertSame(2, count($repository->listQuoteRequests()));
    }

    public function testQuoteRequestCreationRequiresSummary(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteRequestService($repository, $events);

        $this->expectException(\InvalidArgumentException::class);
        $service->create(array(
            'request_summary' => '   ',
            'requester_email' => 'missing-summary@example.test',
        ));
    }

    public function testQuoteRequestNormalizedPayloadContainsRequesterContext(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteRequestService($repository, $events);

        $request = $service->create(array(
            'request_summary'   => 'Volledige contactaanvraag',
            'requester_name'    => 'Piet Planner',
            'requester_email'   => 'piet@example.test',
            'requester_phone'   => '0612345678',
            'requester_company' => 'Dagje BV',
            'requester_address' => array(
                'address_1' => 'Stationsweg 1',
                'postcode'  => '5211 AA',
                'city'      => '\'s-Hertogenbosch',
                'country'   => 'NL',
            ),
            'requester_message' => 'Bel me terug na 14:00.',
        ));

        $this->assertSame('Piet Planner', $request['normalized_payload']['requester']['name']);
        $this->assertSame('piet@example.test', $request['normalized_payload']['requester']['email']);
        $this->assertSame('0612345678', $request['normalized_payload']['requester']['phone']);
        $this->assertSame('Dagje BV', $request['normalized_payload']['requester']['company']);
        $this->assertSame('Stationsweg 1', $request['normalized_payload']['requester']['address']['address_1']);
        $this->assertSame('5211 AA', $request['normalized_payload']['requester']['address']['postcode']);
        $this->assertSame('Bel me terug na 14:00.', $request['normalized_payload']['notes']);
    }

    public function testQuoteRequestCreationLogsRequestCreatedEventWithUserActor(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteRequestService($repository, $events);

        $request = $service->create(array(
            'request_summary' => 'Event logging',
            'requester_email' => 'event@example.test',
            'actor_id' => 44,
            'source_type' => 'planner_offerte_form',
        ));

        $loggedEvents = $repository->listQuoteEvents(0);
        $eventTypes = array_column($loggedEvents, 'event_type');
        $this->assertContains('quote_request_created', $eventTypes);
        $this->assertContains('customer_request_submitted', $eventTypes);

        $requestCreated = $loggedEvents[array_search('quote_request_created', $eventTypes, true)];
        $this->assertSame('user', $requestCreated['actor_type']);
        $this->assertSame(44, $requestCreated['actor_id']);
        $this->assertSame((int) $request['id'], $requestCreated['quote_request_id']);
        $this->assertSame('planner_offerte_form', $requestCreated['payload_json']['source_type']);
    }

    public function testConvertQuoteRequestCreatesQuoteVersionAndEvents(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Teamuitje',
            'requester_name'  => 'Ops Team',
            'requester_email' => 'ops@example.test',
            'group_size'      => 8,
            'preferred_date'  => '2026-05-02',
            'items'           => array(
                array(
                    'product_id'              => 55,
                    'title'                   => 'Workshop',
                    'participants'            => 8,
                    'pricing_confidence'      => 'snapshot',
                    'availability_confidence' => 'projected',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 9);

        $this->assertSame('draft', $quote['status']);
        $this->assertSame('not_started', $quote['review_status']);
        $this->assertSame('not_ready', $quote['handoff_status']);
        $this->assertNotEmpty($repository->listQuoteVersions((int) $quote['id']));
        $this->assertNotEmpty($repository->listQuoteEvents((int) $quote['id']));
    }

    public function testConvertQuoteRequestIsIdempotentForExistingQuote(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Idempotente conversie',
            'requester_email' => 'idempotent@example.test',
            'group_size' => 6,
            'preferred_date' => '2026-06-01',
            'items' => array(
                array(
                    'product_id' => 301,
                    'title' => 'Boottocht',
                    'participants' => 6,
                    'pricing_confidence' => 'snapshot',
                    'availability_confidence' => 'projected',
                ),
            ),
        ));

        $first = $conversion->convertRequestToQuote((int) $request['id'], 7);
        $second = $conversion->convertRequestToQuote((int) $request['id'], 8);

        $this->assertSame((int) $first['id'], (int) $second['id']);
        $this->assertCount(1, $repository->listQuotes());
        $this->assertCount(1, $repository->listQuoteVersions((int) $first['id']));
        $eventTypes = array_column($repository->listQuoteEvents((int) $first['id']), 'event_type');
        $this->assertContains('quote_created_from_request', $eventTypes);
        $this->assertContains('quote_created', $eventTypes);
    }

    public function testMissingCriticalIntakeDataCreatesBlockingAssumptions(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Onvolledig verzoek',
            'requester_name'  => 'No Date',
            'requester_email' => 'missing@example.test',
            'group_size'      => 0,
            'items'           => array(
                array('title' => 'Nog te bepalen activiteit'),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 1);
        $storedAssumptions = $repository->listQuoteAssumptions((int) $quote['id']);

        $this->assertGreaterThanOrEqual(3, count($storedAssumptions));
        $blocking = array_filter($storedAssumptions, static fn (array $row): bool => ! empty($row['blocks_handoff']));
        $this->assertNotEmpty($blocking);
    }

    public function testOperationsDraftSavePersistsVersionBoundSortOrderAndProposedTimes(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $builder = new QuoteOperationsDraftService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Operations builder save',
            'requester_email' => 'builder@example.test',
            'group_size' => 10,
            'preferred_date' => '2026-07-01',
            'items' => array(
                array(
                    'product_id' => 111,
                    'title' => 'Boottocht',
                    'participants' => 10,
                    'start_time' => '10:00',
                    'end_time' => '11:30',
                    'selected_option_labels' => array('Open bar'),
                    'validated_slot_label' => '2026-07-01 10:00-11:30',
                ),
                array(
                    'product_id' => 222,
                    'title' => 'Lunch',
                    'participants' => 10,
                    'start_time' => '12:00',
                    'end_time' => '13:00',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 71);
        $save = $builder->saveDraft((int) $quote['id'], array(
            'lines' => array(
                array(
                    'source_line_number' => 2,
                    'sort_order' => 1,
                    'title' => 'Lunch uitgebreid',
                    'product_id' => 222,
                    'participants' => 12,
                    'quantity' => 1,
                    'service_date' => '2026-07-01',
                    'proposed_start_time' => '11:45',
                    'proposed_end_time' => '13:15',
                    'duration_minutes' => 90,
                    'selected_option_labels' => 'Chef menu, Welkomstdrank',
                    'validated_slot_label' => 'Middagslot',
                    'pricing_confidence' => 'snapshot',
                    'availability_confidence' => 'projected',
                    'currency' => 'EUR',
                ),
                array(
                    'source_line_number' => 1,
                    'sort_order' => 2,
                    'title' => 'Boottocht',
                    'product_id' => 111,
                    'participants' => 12,
                    'quantity' => 1,
                    'service_date' => '2026-07-01',
                    'proposed_start_time' => '09:30',
                    'proposed_end_time' => '11:00',
                    'duration_minutes' => 90,
                    'selected_option_labels' => 'Open bar',
                    'validated_slot_label' => 'Ochtendslot',
                    'pricing_confidence' => 'snapshot',
                    'availability_confidence' => 'projected',
                    'currency' => 'EUR',
                ),
            ),
        ), 71);

        $this->assertFalse((bool) $save['created_new_version']);
        $storedLines = $repository->listQuoteLines((int) ($quote['current_version_id'] ?? 0));
        $this->assertCount(2, $storedLines);
        $this->assertSame('Lunch uitgebreid', $storedLines[0]['title']);
        $this->assertSame(1, (int) $storedLines[0]['sort_order']);
        $this->assertSame('11:45', $storedLines[0]['proposed_start_time']);
        $this->assertSame('13:15', $storedLines[0]['proposed_end_time']);
        $this->assertSame(array('Chef menu', 'Welkomstdrank'), $storedLines[0]['selected_option_labels_json']);
        $this->assertSame('Middagslot', $storedLines[0]['validated_slot_label']);
    }

    public function testOperationsDraftSaveClonesFrozenVersionBeforeEditing(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $reviews = new QuoteReviewService($repository, $events, $followups);
        $builder = new QuoteOperationsDraftService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Frozen quote edit',
            'requester_email' => 'frozen@example.test',
            'group_size' => 8,
            'preferred_date' => '2026-07-02',
            'items' => array(
                array(
                    'product_id' => 301,
                    'title' => 'Workshop',
                    'participants' => 8,
                    'start_time' => '14:00',
                    'end_time' => '15:30',
                    'pricing_confidence' => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 72);
        $originalVersionId = (int) ($quote['current_version_id'] ?? 0);
        $reviews->approve((int) $quote['id'], 72);

        $save = $builder->saveDraft((int) $quote['id'], array(
            'lines' => array(
                array(
                    'source_line_number' => 1,
                    'sort_order' => 1,
                    'title' => 'Workshop aangepast',
                    'product_id' => 301,
                    'participants' => 9,
                    'quantity' => 1,
                    'service_date' => '2026-07-02',
                    'proposed_start_time' => '15:00',
                    'proposed_end_time' => '16:30',
                    'duration_minutes' => 90,
                    'selected_option_labels' => 'Late start',
                    'validated_slot_label' => 'Aangepast slot',
                    'pricing_confidence' => 'snapshot',
                    'availability_confidence' => 'projected',
                    'currency' => 'EUR',
                ),
            ),
        ), 72);

        $this->assertTrue((bool) $save['created_new_version']);
        $updatedQuote = $repository->findQuote((int) $quote['id']);
        $this->assertNotSame($originalVersionId, (int) ($updatedQuote['current_version_id'] ?? 0));
        $this->assertSame('draft', (string) ($updatedQuote['status'] ?? ''));
        $this->assertSame('not_started', (string) ($updatedQuote['review_status'] ?? ''));
        $this->assertSame('not_ready', (string) ($updatedQuote['send_status'] ?? ''));
        $this->assertSame('Workshop', $repository->listQuoteLines($originalVersionId)[0]['title']);
        $this->assertSame('Workshop aangepast', $repository->listQuoteLines((int) ($updatedQuote['current_version_id'] ?? 0))[0]['title']);
    }

    public function testAutomaticAssumptionsCoverMissingInfoUncertaintyAndUnmappedLines(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Exacte assumptions',
            'requester_email' => 'assumptions@example.test',
            'group_size' => 0,
            'items' => array(
                array(
                    'title' => 'Nog niet gekoppelde regel',
                    'participants' => 0,
                    'pricing_confidence' => 'snapshot',
                    'availability_confidence' => 'projected',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 5);
        $storedAssumptions = $repository->listQuoteAssumptions((int) $quote['id']);
        $types = array_map(static fn (array $row): string => (string) $row['assumption_type'], $storedAssumptions);
        sort($types);

        $this->assertSame(
            array(
                'manual_review_required',
                'missing_date',
                'missing_group_size',
                'uncertain_availability',
                'uncertain_pricing',
            ),
            $types
        );
        $eventTypes = array_column($repository->listQuoteEvents((int) $quote['id']), 'event_type');
        $this->assertContains('quote_created_from_request', $eventTypes);
        $this->assertContains('quote_created', $eventTypes);
    }

    public function testQuoteAndVersionStateDefaultsAreCorrect(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Basisverzoek',
            'requester_email' => 'basis@example.test',
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], null);
        $version = $repository->findQuoteVersion((int) $quote['current_version_id']);

        $this->assertSame('draft', $quote['status']);
        $this->assertSame('not_started', $quote['review_status']);
        $this->assertSame('not_ready', $quote['send_status']);
        $this->assertSame('not_ready', $quote['handoff_status']);
        $this->assertSame('draft', $version['status']);
        $this->assertSame('initial', $version['snapshot_type']);
    }

    public function testProposalEmailSendCreatesQuoteMessageAndMarksQuoteSent(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $reviews = new QuoteReviewService($repository, $events, $followups);
        $communication = new QuoteCommunicationService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Voorstelmail test',
            'requester_name'  => 'Mail Contact',
            'requester_email' => 'mail@example.test',
            'group_size'      => 6,
            'preferred_date'  => '2026-06-10',
            'items'           => array(
                array(
                    'product_id'              => 101,
                    'title'                   => 'Boottocht',
                    'participants'            => 6,
                    'pricing_confidence'      => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 11);
        $reviews->approve((int) $quote['id'], 11);
        $draft = $communication->generateProposalDraft((int) $quote['id'], 11);

        $message = $communication->sendEmail((int) $quote['id'], array(
            'message_type' => 'proposal',
            'draft_id'     => (int) $draft['id'],
            'to_name'      => 'Mail Contact',
            'to_email'     => 'mail@example.test',
            'subject'      => 'Voorstel [Q-20260413100000]',
            'body'         => "Hallo Mail Contact,\nHierbij ons voorstel.",
        ), 11);

        $storedQuote = $repository->findQuote((int) $quote['id']);

        $this->assertSame('outbound', $message['direction']);
        $this->assertSame('proposal', $message['message_type']);
        $this->assertSame('sent', $message['status']);
        $this->assertSame((int) $quote['current_version_id'], (int) $message['quote_version_id']);
        $this->assertSame('sent_manual', $storedQuote['send_status']);
        $this->assertSame('sent', $storedQuote['status']);
        $this->assertCount(1, $GLOBALS['__test_wp_mail_calls']);
        $this->assertSame('mail@example.test', $GLOBALS['__test_wp_mail_calls'][0]['to']);
        $this->assertCount(1, $repository->listQuoteMessages((int) $quote['id']));
    }

    public function testProposalEmailSendFailureReportsMailerReasonAndLogsEvent(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $reviews = new QuoteReviewService($repository, $events, $followups);
        $communication = new QuoteCommunicationService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Voorstelmail failure test',
            'requester_name'  => 'Mail Contact',
            'requester_email' => 'mail@example.test',
            'group_size'      => 6,
            'preferred_date'  => '2026-06-10',
            'items'           => array(
                array(
                    'product_id'              => 101,
                    'title'                   => 'Boottocht',
                    'participants'            => 6,
                    'pricing_confidence'      => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 11);
        $reviews->approve((int) $quote['id'], 11);
        $draft = $communication->generateProposalDraft((int) $quote['id'], 11);

        $GLOBALS['__test_wp_mail_result'] = false;
        $GLOBALS['__test_wp_mail_error'] = 'SMTP connect() failed.';

        try {
            $communication->sendEmail((int) $quote['id'], array(
                'message_type' => 'proposal',
                'draft_id'     => (int) $draft['id'],
                'to_name'      => 'Mail Contact',
                'to_email'     => 'mail@example.test',
                'subject'      => 'Voorstel [Q-20260413100000]',
                'body'         => "Hallo Mail Contact,\nHierbij ons voorstel.",
            ), 11);
            $this->fail('Expected proposal mail failure.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('De e-mail kon niet worden verstuurd.', $exception->getMessage());
            $this->assertStringContainsString('SMTP connect() failed.', $exception->getMessage());
        }

        $events = $repository->listQuoteEvents((int) $quote['id']);
        $this->assertContains('quote_message_send_failed', array_column($events, 'event_type'));
        $failure = end($events);
        $this->assertSame('SMTP connect() failed.', $failure['payload_json']['mail_failure']);
        $sentMessages = array_filter(
            $repository->listQuoteMessages((int) $quote['id']),
            static fn (array $message): bool => (string) ($message['status'] ?? '') === 'sent'
        );
        $this->assertCount(0, $sentMessages);
    }

    public function testInboundReplyMatchesQuoteByMessageReference(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $reviews = new QuoteReviewService($repository, $events, $followups);
        $communication = new QuoteCommunicationService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Inbound matching',
            'requester_name'  => 'Reply Contact',
            'requester_email' => 'reply@example.test',
            'group_size'      => 4,
            'preferred_date'  => '2026-06-11',
            'items'           => array(
                array(
                    'product_id'              => 88,
                    'title'                   => 'Stadswandeling',
                    'participants'            => 4,
                    'pricing_confidence'      => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 15);
        $reviews->approve((int) $quote['id'], 15);
        $outbound = $communication->sendEmail((int) $quote['id'], array(
            'message_type' => 'proposal',
            'to_name'      => 'Reply Contact',
            'to_email'     => 'reply@example.test',
            'subject'      => 'Voorstel [Q-20260413100000]',
            'body'         => "Hallo,\nHierbij het voorstel.",
        ), 15);

        $inbound = $communication->ingestInboundMessage(array(
            'message_id'  => 'customer-reply@example.test',
            'in_reply_to' => (string) ($outbound['provider_message_id'] ?? ''),
            'subject'     => 'Re: Voorstel [Q-20260413100000]',
            'body'        => 'Kunnen jullie nog iets doen met de tijd?',
            'from_name'   => 'Reply Contact',
            'from_email'  => 'reply@example.test',
        ), 15);

        $this->assertSame((int) $quote['id'], (int) $inbound['quote_id']);
        $this->assertSame('inbound', $inbound['direction']);
        $this->assertSame('customer_reply', $inbound['message_type']);
        $this->assertSame('received', $inbound['status']);
    }

    public function testInboundReplyFallsBackToQuoteTokenAndSummaryCanBeStored(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $communication = new QuoteCommunicationService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Token fallback',
            'requester_email' => 'token@example.test',
            'group_size'      => 3,
            'preferred_date'  => '2026-06-12',
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 22);

        $inbound = $communication->ingestInboundMessage(array(
            'message_id' => 'token-fallback@example.test',
            'subject'    => 'Vraag over ' . (string) $quote['quote_reference'],
            'body'       => 'Kunnen we de starttijd een uur later plannen?',
            'from_email' => 'token@example.test',
        ), 22);

        $summarized = $communication->summarizeInboundMessage((int) $quote['id'], (int) $inbound['id'], 22);

        $this->assertSame((int) $quote['id'], (int) $inbound['quote_id']);
        $this->assertNotSame('', (string) ($summarized['body_summary'] ?? ''));
        $this->assertSame((int) $inbound['id'], (int) $repository->listQuoteMessages((int) $quote['id'])[0]['id']);
    }

    public function testGeneratingDraftTwiceUpdatesExistingDraftInsteadOfCreatingDuplicates(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $communication = new QuoteCommunicationService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Draft dedupe',
            'requester_name'  => 'Draft Contact',
            'requester_email' => 'draft@example.test',
            'group_size'      => 5,
            'preferred_date'  => '2026-06-14',
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 31);
        $firstDraft = $communication->generateProposalDraft((int) $quote['id'], 31);
        $secondDraft = $communication->generateProposalDraft((int) $quote['id'], 31);

        $this->assertSame((int) $firstDraft['id'], (int) $secondDraft['id']);
        $this->assertCount(1, $repository->listQuoteMessages((int) $quote['id']));
    }

    public function testProposalDraftCarriesReferenceAndHumanCommercialCaveatsWhenTruthIsNotConfirmed(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $communication = new QuoteCommunicationService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Draft grounding',
            'requester_name'  => 'Grounded Contact',
            'requester_email' => 'grounded@example.test',
            'group_size'      => 10,
            'preferred_date'  => '2026-06-21',
            'items'           => array(
                array(
                    'title'                   => 'Rondvaart',
                    'participants'            => 10,
                    'pricing_confidence'      => 'snapshot',
                    'availability_confidence' => 'projected',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 61);
        $draft = $communication->generateProposalDraft((int) $quote['id'], 61);
        $body = (string) ($draft['body'] ?? '');

        $this->assertStringContainsString('Referentie: ' . (string) ($quote['quote_reference'] ?? ''), $body);
        $this->assertStringContainsString('Totaal voorstelbedrag: op aanvraag', $body);
        $this->assertStringContainsString('De beschikbaarheid en definitieve bevestiging blijven onder voorbehoud', $body);
        $this->assertStringNotContainsString('Quote token', $body);
        $this->assertStringNotContainsString('snapshot', $body);
        $this->assertStringNotContainsString('execution-laag', $body);
    }

    public function testProposalDraftIncludesProposedTimingAndOptionsFromCurrentVersionLines(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $builder = new QuoteOperationsDraftService($repository, $events);
        $communication = new QuoteCommunicationService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Proposal detail body',
            'requester_name'  => 'Proposal Contact',
            'requester_email' => 'proposal-detail@example.test',
            'group_size'      => 6,
            'preferred_date'  => '2026-07-03',
            'items'           => array(
                array(
                    'product_id' => 440,
                    'title' => 'Stadswandeling',
                    'participants' => 6,
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 73);
        $builder->saveDraft((int) $quote['id'], array(
            'lines' => array(
                array(
                    'source_line_number' => 1,
                    'sort_order' => 1,
                    'title' => 'Stadswandeling',
                    'product_id' => 440,
                    'participants' => 6,
                    'quantity' => 1,
                    'service_date' => '2026-07-03',
                    'proposed_start_time' => '13:00',
                    'proposed_end_time' => '14:30',
                    'duration_minutes' => 90,
                    'selected_option_labels' => 'Gids Engels, Borrel na afloop',
                    'validated_slot_label' => 'Middagwandeling',
                    'pricing_confidence' => 'snapshot',
                    'availability_confidence' => 'projected',
                    'currency' => 'EUR',
                ),
            ),
        ), 73);

        $draft = $communication->generateProposalDraft((int) $quote['id'], 73);

        $body = (string) ($draft['body'] ?? '');
        $this->assertStringContainsString('- Stadswandeling', $body);
        $this->assertStringContainsString('Tijd: 2026-07-03 13:00-14:30', $body);
        $this->assertStringContainsString('Optie: Gids Engels | Borrel na afloop', $body);
        $this->assertStringContainsString('Aantal personen: 6', $body);
    }

    public function testProposalEmailCannotBeSentBeforeApprovedReviewAndReadyToSend(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $communication = new QuoteCommunicationService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Blocked proposal send',
            'requester_email' => 'blocked@example.test',
            'group_size'      => 4,
            'preferred_date'  => '2026-06-22',
        ));
        $quote = $conversion->convertRequestToQuote((int) $request['id'], 62);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Een voorstelmail kan pas worden verstuurd na goedgekeurde review.');

        $communication->sendEmail((int) $quote['id'], array(
            'message_type' => 'proposal',
            'to_email'     => 'blocked@example.test',
            'subject'      => 'Voorstel',
            'body'         => 'Concept voorstel',
        ), 62);
    }

    public function testReplyCannotBeSentBeforeProposalExists(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $communication = new QuoteCommunicationService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Reply guard',
            'requester_email' => 'reply-guard@example.test',
            'group_size'      => 4,
            'preferred_date'  => '2026-06-23',
        ));
        $quote = $conversion->convertRequestToQuote((int) $request['id'], 63);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Een reply kan pas worden verstuurd nadat eerst een voorstelmail is verzonden.');

        $communication->sendEmail((int) $quote['id'], array(
            'message_type' => 'reply',
            'to_email'     => 'reply-guard@example.test',
            'subject'      => 'Re: vraag',
            'body'         => 'Antwoord',
        ), 63);
    }

    public function testResponseDraftLinksToInboundMessageAndCarriesReferenceChain(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $reviews = new QuoteReviewService($repository, $events, $followups);
        $communication = new QuoteCommunicationService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Response draft linkage',
            'requester_name'  => 'Link Contact',
            'requester_email' => 'link@example.test',
            'group_size'      => 5,
            'preferred_date'  => '2026-06-24',
            'items'           => array(
                array(
                    'product_id'              => 901,
                    'title'                   => 'Workshop',
                    'participants'            => 5,
                    'pricing_confidence'      => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 64);
        $reviews->approve((int) $quote['id'], 64);
        $outbound = $communication->sendEmail((int) $quote['id'], array(
            'message_type' => 'proposal',
            'to_name'      => 'Link Contact',
            'to_email'     => 'link@example.test',
            'subject'      => 'Voorstel [' . (string) ($quote['quote_reference'] ?? '') . ']',
            'body'         => 'Voorstel body',
        ), 64);

        $inbound = $communication->ingestInboundMessage(array(
            'message_id'  => 'linked-inbound@example.test',
            'in_reply_to' => (string) ($outbound['provider_message_id'] ?? ''),
            'references'  => array((string) ($outbound['provider_message_id'] ?? ''), 'older-thread@example.test'),
            'subject'     => 'Re: Voorstel',
            'body'        => 'Kunt u nog iets aanpassen?',
            'from_name'   => 'Link Contact',
            'from_email'  => 'link@example.test',
        ), 64);
        $communication->summarizeInboundMessage((int) $quote['id'], (int) $inbound['id'], 64);

        $draft = $communication->generateResponseDraft((int) $quote['id'], (int) $inbound['id'], 64);

        $this->assertSame('reply', $draft['message_type']);
        $this->assertSame((string) ($inbound['from_email'] ?? ''), (string) ($draft['to_email'] ?? ''));
        $this->assertSame((string) ($inbound['provider_message_id'] ?? ''), (string) ($draft['in_reply_to_message_id'] ?? ''));
        $this->assertSame(
            array((string) ($outbound['provider_message_id'] ?? ''), 'older-thread@example.test', (string) ($inbound['provider_message_id'] ?? '')),
            array_values((array) ($draft['references_json'] ?? array()))
        );
        $this->assertStringContainsString((string) ($quote['quote_reference'] ?? ''), (string) ($draft['body'] ?? ''));
        $this->assertStringContainsString('We hebben uw bericht als volgt samengevat:', (string) ($draft['body'] ?? ''));
    }

    public function testInboundReplyWithDuplicateProviderMessageIdReturnsExistingMessage(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $reviews = new QuoteReviewService($repository, $events, $followups);
        $communication = new QuoteCommunicationService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Inbound dedupe',
            'requester_name'  => 'Dedupe Contact',
            'requester_email' => 'dedupe@example.test',
            'group_size'      => 4,
            'preferred_date'  => '2026-06-25',
            'items'           => array(
                array(
                    'product_id'              => 902,
                    'title'                   => 'Quiz',
                    'participants'            => 4,
                    'pricing_confidence'      => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 65);
        $reviews->approve((int) $quote['id'], 65);
        $outbound = $communication->sendEmail((int) $quote['id'], array(
            'message_type' => 'proposal',
            'to_name'      => 'Dedupe Contact',
            'to_email'     => 'dedupe@example.test',
            'subject'      => 'Voorstel [' . (string) ($quote['quote_reference'] ?? '') . ']',
            'body'         => 'Voorstel body',
        ), 65);

        $firstInbound = $communication->ingestInboundMessage(array(
            'message_id'  => 'duplicate-inbound@example.test',
            'in_reply_to' => (string) ($outbound['provider_message_id'] ?? ''),
            'subject'     => 'Re: Voorstel',
            'body'        => 'Eerste ontvangst',
            'from_name'   => 'Dedupe Contact',
            'from_email'  => 'dedupe@example.test',
        ), 65);

        $secondInbound = $communication->ingestInboundMessage(array(
            'message_id'  => 'duplicate-inbound@example.test',
            'in_reply_to' => (string) ($outbound['provider_message_id'] ?? ''),
            'subject'     => 'Re: Voorstel',
            'body'        => 'Tweede ontvangst',
            'from_name'   => 'Dedupe Contact',
            'from_email'  => 'dedupe@example.test',
        ), 65);

        $this->assertSame((int) $firstInbound['id'], (int) $secondInbound['id']);
        $this->assertCount(2, $repository->listQuoteMessages((int) $quote['id']));
    }

    public function testInboundReplyNormalizesHeaderBasedProviderPayload(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $reviews = new QuoteReviewService($repository, $events, $followups);
        $communication = new QuoteCommunicationService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Header based inbound',
            'requester_name'  => 'Header Contact',
            'requester_email' => 'header@example.test',
            'group_size'      => 2,
            'preferred_date'  => '2026-06-15',
            'items'           => array(
                array(
                    'product_id'              => 77,
                    'title'                   => 'Header activiteit',
                    'participants'            => 2,
                    'pricing_confidence'      => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 41);
        $reviews->approve((int) $quote['id'], 41);
        $outbound = $communication->sendEmail((int) $quote['id'], array(
            'message_type' => 'proposal',
            'to_name'      => 'Header Contact',
            'to_email'     => 'header@example.test',
            'subject'      => 'Voorstel',
            'body'         => 'Voorstel body',
        ), 41);

        $inbound = $communication->ingestInboundMessage(array(
            'from'    => 'Header Contact <header@example.test>',
            'to'      => 'Dagje Den Bosch <quotes@example.test>',
            'subject' => 'Re: Voorstel',
            'text'    => 'Wij hebben nog een vraag.',
            'headers' => "Message-Id: <provider-inbound@example.test>\nIn-Reply-To: <" . (string) ($outbound['provider_message_id'] ?? '') . ">\nReferences: <" . (string) ($outbound['provider_message_id'] ?? '') . ">\nDate: 2026-06-15 09:00:00",
        ), 41);

        $this->assertSame('provider-inbound@example.test', (string) $inbound['provider_message_id']);
        $this->assertSame((string) ($outbound['provider_message_id'] ?? ''), (string) $inbound['in_reply_to_message_id']);
        $this->assertSame('header@example.test', (string) $inbound['from_email']);
        $this->assertSame((int) $quote['id'], (int) $inbound['quote_id']);
    }

    public function testInboundSecretPermissionGateAcceptsMatchingSecret(): void
    {
        add_filter('bsp/quotes/inbound_secret', static fn (): string => 'super-secret');

        $request = new WP_REST_Request('POST', '/bsp/v1/quotes/messages/inbound');
        $request->set_header('x-bsp-quote-inbound-secret', 'super-secret');

        $this->assertTrue(Controller::canIngestInboundQuoteMessages($request));

        remove_all_filters('bsp/quotes/inbound_secret');
    }

    public function testUnmatchedInboundReplyIsStoredInFailureQueue(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $communication = new QuoteCommunicationService($repository, $events);

        try {
            $communication->ingestInboundMessage(array(
                'message_id' => 'unmatched@example.test',
                'from_email' => 'lead@example.test',
                'subject'    => 'Losse reply zonder quote token',
                'body'       => 'Hallo, kunnen jullie terugbellen?',
            ), 51);
            $this->fail('Expected unmatched inbound ingest to throw.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Inbound reply kon niet aan een quote worden gekoppeld via message-id, references of quote-token.', $exception->getMessage());
        }

        $failures = $repository->listQuoteMessageFailures();
        $this->assertCount(1, $failures);
        $this->assertSame('open', $failures[0]['status']);
        $this->assertSame('unmatched_quote', $failures[0]['failure_reason']);
        $this->assertSame('lead@example.test', $failures[0]['from_email']);
    }

    public function testInboundFailureCanBeResolvedToQuote(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $communication = new QuoteCommunicationService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Resolve inbound failure',
            'requester_email' => 'resolve@example.test',
            'group_size'      => 4,
            'preferred_date'  => '2026-06-16',
        ));
        $quote = $conversion->convertRequestToQuote((int) $request['id'], 52);

        try {
            $communication->ingestInboundMessage(array(
                'message_id' => 'needs-resolution@example.test',
                'from_email' => 'resolve@example.test',
                'subject'    => 'Vraag zonder match',
                'body'       => 'Ik reageer buiten de thread om.',
            ), 52);
            $this->fail('Expected unmatched inbound ingest to throw.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $failures = $repository->listQuoteMessageFailures();
        $resolved = $communication->resolveInboundFailure((int) $failures[0]['id'], (int) $quote['id'], 52);

        $this->assertSame('resolved', $resolved['failure']['status']);
        $this->assertSame((int) $quote['id'], (int) $resolved['failure']['linked_quote_id']);
        $this->assertSame((int) $quote['id'], (int) $resolved['message']['quote_id']);
        $this->assertSame('received', $resolved['message']['status']);
    }

    public function testInboundFailureResolutionReusesExistingInboundMessageForSameProviderMessageId(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $communication = new QuoteCommunicationService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Reuse inbound message on resolve',
            'requester_email' => 'reuse@example.test',
            'group_size'      => 4,
            'preferred_date'  => '2026-06-18',
        ));
        $quote = $conversion->convertRequestToQuote((int) $request['id'], 53);

        $existingInbound = $repository->createQuoteMessage(array(
            'quote_id'            => (int) $quote['id'],
            'quote_version_id'    => (int) ($quote['current_version_id'] ?? 0),
            'direction'           => 'inbound',
            'message_type'        => 'customer_reply',
            'channel'             => 'email',
            'status'              => 'received',
            'subject'             => 'Bestaande inbound',
            'body'                => 'Deze reply is al gekoppeld.',
            'from_email'          => 'reuse@example.test',
            'provider_message_id' => 'existing-inbound@example.test',
        ));

        $failure = $repository->createQuoteMessageFailure(array(
            'direction'           => 'inbound',
            'channel'             => 'email',
            'failure_reason'      => 'unmatched_quote',
            'subject'             => 'Nogmaals dezelfde reply',
            'from_email'          => 'reuse@example.test',
            'provider_message_id' => 'existing-inbound@example.test',
            'status'              => 'open',
            'payload_json'        => array(
                'message_id'  => 'existing-inbound@example.test',
                'from_email'  => 'reuse@example.test',
                'subject'     => 'Nogmaals dezelfde reply',
                'body'        => 'Deze reply kwam al eerder binnen.',
            ),
        ));

        $resolved = $communication->resolveInboundFailure((int) $failure['id'], (int) $quote['id'], 53);

        $this->assertSame('resolved', $resolved['failure']['status']);
        $this->assertSame((int) $existingInbound['id'], (int) $resolved['message']['id']);
        $this->assertCount(1, $repository->listQuoteMessages((int) $quote['id']));
    }

    public function testHandoffPlaceholderDoesNotCreateWooOrdersOrBookings(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $reviews = new QuoteReviewService($repository, $events, $followups);

        $request = $requestService->create(array(
            'request_summary' => 'Handoff test',
            'requester_email' => 'handoff@example.test',
            'group_size' => 4,
            'preferred_date' => '2026-05-06',
            'items' => array(
                array(
                    'product_id' => 77,
                    'title' => 'Stadswandeling',
                    'participants' => 4,
                    'pricing_confidence' => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));
        $quote = $conversion->convertRequestToQuote((int) $request['id'], 2);
        $reviews->approve((int) $quote['id'], 2);
        $updated = $conversion->markReadyForResnapshot((int) $quote['id'], 2);

        $this->assertSame('ready_for_resnapshot', $updated['handoff_status']);
        $this->assertArrayNotHasKey('woo_order_id', array_filter($updated, static fn ($value) => $value !== null && $value !== ''));
        $this->assertArrayNotHasKey('booking_master_id', array_filter($updated, static fn ($value) => $value !== null && $value !== ''));
    }

    public function testHandoffPlaceholderLogsReadyForResnapshotEvent(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $reviews = new QuoteReviewService($repository, $events, $followups);

        $request = $requestService->create(array(
            'request_summary' => 'Handoff event',
            'requester_email' => 'handoff-event@example.test',
            'group_size' => 5,
            'preferred_date' => '2026-05-09',
            'items' => array(
                array(
                    'product_id' => 91,
                    'title' => 'Walking dinner',
                    'participants' => 5,
                    'pricing_confidence' => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 12);
        $reviews->approve((int) $quote['id'], 12);
        $conversion->markReadyForResnapshot((int) $quote['id'], 12);

        $eventsForQuote = $repository->listQuoteEvents((int) $quote['id']);
        $lastEvent = end($eventsForQuote);

        $this->assertSame('quote_handoff_ready_for_resnapshot', $lastEvent['event_type']);
        $this->assertSame('user', $lastEvent['actor_type']);
        $this->assertSame(12, $lastEvent['actor_id']);
        $this->assertSame('ready_for_resnapshot', $lastEvent['payload_json']['handoff_status']);
    }

    public function testUnauthorizedRestHandlersAreRejected(): void
    {
        $GLOBALS['__test_current_user_can'] = false;
        $this->assertFalse(Controller::canManageQuotes());

        $GLOBALS['__test_current_user_can'] = true;
        $this->assertTrue(Controller::canManageQuotes());

        Controller::register();
        $this->assertNotEmpty($GLOBALS['__test_rest_routes']);

        $request = new WP_REST_Request('POST', '/bsp/v1/quote-requests');
        $request['request_summary'] = 'REST request';
        $this->assertInstanceOf(WP_REST_Request::class, $request);
    }

    public function testInboundMailWebhookRegistersRoute(): void
    {
        InboundMailWebhookController::register();

        $registered = array_filter(
            $GLOBALS['__test_rest_routes'],
            static fn (array $route): bool => $route[0] === 'bsp/v1' && $route[1] === '/inbound-mail'
        );

        $this->assertNotEmpty($registered);
    }

    public function testInboundMailWebhookReturnsForbiddenWithoutMatchingSecret(): void
    {
        $GLOBALS['__test_options']['bsp_inbound_mail_secret'] = 'topsecret';

        $request = new WP_REST_Request('POST', '/bsp/v1/inbound-mail');
        $request->set_param('to_email', 'aanvragen@dagjedenbosch.nl');
        $request->set_param('from_email', 'klant@example.test');
        $request->set_param('subject', 'Nieuwe aanvraag');
        $request->set_param('body', 'Kunt u een offerte maken?');

        $result = InboundMailWebhookController::handle($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('forbidden', $result->code);
        $this->assertSame(403, $result->data['status']);
    }

    public function testInboundMailWebhookCreatesQuoteRequestForAanvragenAddressWithoutMatch(): void
    {
        $GLOBALS['__test_options']['bsp_inbound_mail_secret'] = 'topsecret';

        $request = new WP_REST_Request('POST', '/bsp/v1/inbound-mail');
        $request->set_header('X-BSP-Mail-Secret', 'topsecret');
        $request->set_param('to_email', 'aanvragen@dagjedenbosch.nl');
        $request->set_param('from_email', 'klant@example.test');
        $request->set_param('from_name', 'Klant');
        $request->set_param('subject', 'Nieuwe aanvraag zonder referentie');
        $request->set_param('body', 'Wij willen graag een voorstel voor een teamuitje.');
        $request->set_param('provider_message_id', 'provider-123');

        $result = InboundMailWebhookController::handle($request);

        $payload = $result instanceof \WP_REST_Response ? $result->get_data() : $result;
        $status = $result instanceof \WP_REST_Response ? $result->get_status() : (int) ($payload['status'] ?? 0);

        $this->assertIsArray($payload);
        $this->assertFalse($payload['matched']);
        $this->assertSame('created_request', $payload['action']);
        $this->assertSame(1, $payload['quote_request_id']);
        $this->assertSame(201, $status);
        $this->assertArrayHasKey('wp_bsp_quote_requests', $GLOBALS['wpdb']->storage);
        $this->assertCount(1, $GLOBALS['wpdb']->storage['wp_bsp_quote_requests']);
    }

    public function testInboundMailWebhookMatchesExistingQuoteAndTriggersInboundAction(): void
    {
        $GLOBALS['__test_options']['bsp_inbound_mail_secret'] = 'topsecret';
        Installer::install();

        $repository = new \BSP\Quotes\Repository\QuoteRepository();
        $request = $repository->createQuoteRequest(array(
            'request_reference' => 'QR-TEST-1',
            'source_type' => 'admin_manual',
            'status' => 'new',
            'request_summary' => 'Test request',
        ));
        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-TEST-123',
            'quote_request_id' => (int) $request['id'],
            'status' => 'draft',
            'review_status' => 'not_started',
            'send_status' => 'not_ready',
            'handoff_status' => 'not_ready',
            'current_version_id' => null,
        ));

        $captured = array();
        add_action('bsp/quotes/ai/inbound_received', static function (int $quoteId, int $messageId, string $toEmail) use (&$captured): void {
            $captured[] = array(
                'quote_id' => $quoteId,
                'message_id' => $messageId,
                'to_email' => $toEmail,
            );
        }, 10, 3);

        $request = new WP_REST_Request('POST', '/bsp/v1/inbound-mail');
        $request->set_header('X-BSP-Mail-Secret', 'topsecret');
        $request->set_param('to_email', 'aanvragen@dagjedenbosch.nl');
        $request->set_param('from_email', 'klant@example.test');
        $request->set_param('from_name', 'Klant');
        $request->set_param('subject', 'Reactie op offerte [Q-TEST-123]');
        $request->set_param('body', 'Dank, ik heb nog een vraag over deze offerte.');
        $request->set_param('provider_message_id', 'provider-match-1');
        $request->set_param('in_reply_to_message_id', 'provider-parent-1');

        $result = InboundMailWebhookController::handle($request);

        $payload = $result instanceof \WP_REST_Response ? $result->get_data() : $result;
        $status = $result instanceof \WP_REST_Response ? $result->get_status() : (int) ($payload['status'] ?? 0);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['matched']);
        $this->assertSame((int) $quote['id'], $payload['quote_id']);
        $this->assertSame(201, $status);
        $this->assertArrayHasKey('wp_bsp_quote_messages', $GLOBALS['wpdb']->storage);
        $this->assertCount(1, $GLOBALS['wpdb']->storage['wp_bsp_quote_messages']);
        $storedMessage = $GLOBALS['wpdb']->storage['wp_bsp_quote_messages'][0];
        $this->assertSame('inbound', $storedMessage['direction']);
        $this->assertSame('client_reply', $storedMessage['message_type']);
        $this->assertSame('received', $storedMessage['status']);
        $this->assertSame('aanvragen@dagjedenbosch.nl', $storedMessage['to_email']);
        $this->assertCount(1, $captured);
        $this->assertSame((int) $quote['id'], $captured[0]['quote_id']);
        $this->assertSame('aanvragen@dagjedenbosch.nl', $captured[0]['to_email']);
    }

    public function testInboundMailWebhookLogsFailureForInfoAddressWithoutMatch(): void
    {
        $GLOBALS['__test_options']['bsp_inbound_mail_secret'] = 'topsecret';

        $request = new WP_REST_Request('POST', '/bsp/v1/inbound-mail');
        $request->set_header('X-BSP-Mail-Secret', 'topsecret');
        $request->set_param('to_email', 'info@dagjedenbosch.nl');
        $request->set_param('from_email', 'klant@example.test');
        $request->set_param('from_name', 'Klant');
        $request->set_param('subject', 'Algemene vraag zonder match');
        $request->set_param('body', 'Ik heb een vraag over een offerte.');
        $request->set_param('provider_message_id', 'provider-456');

        $result = InboundMailWebhookController::handle($request);

        $payload = $result instanceof \WP_REST_Response ? $result->get_data() : $result;
        $status = $result instanceof \WP_REST_Response ? $result->get_status() : (int) ($payload['status'] ?? 0);

        $this->assertIsArray($payload);
        $this->assertFalse($payload['matched']);
        $this->assertSame('logged_failure', $payload['action']);
        $this->assertSame(1, $payload['failure_id']);
        $this->assertSame(202, $status);
        $this->assertArrayHasKey('wp_bsp_quote_message_failures', $GLOBALS['wpdb']->storage);
        $this->assertCount(1, $GLOBALS['wpdb']->storage['wp_bsp_quote_message_failures']);
    }

    public function testOpenAiDraftAdapterLeavesProposalDraftUnchangedWhenApiKeyIsEmpty(): void
    {
        new OpenAiQuoteDraftAdapter();

        $draft = array(
            'subject' => 'Voorstel [Q-ABC123]',
            'body' => '<p>Test</p>',
            'source' => 'template',
        );

        $filtered = apply_filters('bsp/quotes/ai/draft_proposal_email', $draft, array('quote_reference' => 'Q-ABC123'));

        $this->assertSame($draft, $filtered);
    }

    public function testOpenAiDraftAdapterLeavesResponseDraftUnchangedWhenApiKeyIsEmpty(): void
    {
        new OpenAiQuoteDraftAdapter();

        $draft = array(
            'subject' => 'Re: Vraag [Q-ABC123]',
            'body' => '<p>Antwoord</p>',
            'source' => 'template',
        );

        $filtered = apply_filters('bsp/quotes/ai/draft_response', $draft, array('quote_reference' => 'Q-ABC123'));

        $this->assertSame($draft, $filtered);
    }

    public function testOpenAiDraftAdapterLeavesSummaryUnchangedWhenApiKeyIsEmpty(): void
    {
        new OpenAiQuoteDraftAdapter();

        $draft = array(
            'summary' => 'Korte samenvatting.',
            'source' => 'template',
        );

        $filtered = apply_filters('bsp/quotes/ai/summarize_reply', $draft, array('quote_reference' => 'Q-ABC123'));

        $this->assertSame($draft, $filtered);
    }

    public function testPlannerIntakeCreatesQuoteRequestQuoteAndFollowup(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requests = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $intake = new PlannerQuoteIntakeService($requests, $conversion, $followups);

        $result = $intake->createFromPlannerPlan(
            42,
            array(
                'title' => 'Dagje Den Bosch',
                'days'  => array(array('date' => '2026-06-14')),
                'meta'  => array(
                    'participant_count' => 14,
                    'planner_items'     => array(
                        array(
                            'product_id' => 88,
                            'title' => 'Boottocht',
                            'participants' => 14,
                            'date' => '2026-06-14',
                            'starttime' => '10:00',
                            'endtime' => '12:00',
                            'bookingresolution' => array(
                                'pricing' => array(
                                    'per_person' => 24.95,
                                    'dynamic' => array('total' => 349.30),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
            array(
                'name' => 'Planner Contact',
                'email' => 'planner@example.test',
                'phone' => '0612345678',
                'message' => 'Graag inclusief lunchoptie.',
            ),
            7
        );

        $this->assertSame('planner_offerte_form', $result['request']['source_type']);
        $this->assertSame(42, $result['request']['planner_plan_id']);
        $this->assertSame('draft', $result['quote']['status']);
        $this->assertNotEmpty($repository->listQuoteFollowups((int) $result['quote']['id']));
    }

    public function testPlannerIntakePersistsCanonicalOptionAndSlotSnapshots(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requests = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $intake = new PlannerQuoteIntakeService($requests, $conversion, $followups);

        $result = $intake->createFromPlannerPlan(
            84,
            array(
                'title' => 'Snapshot fidelity',
                'days'  => array(array('date' => '2026-06-18')),
                'meta'  => array(
                    'participant_count' => 10,
                    'planner_items'     => array(
                        array(
                            'product_id' => 92,
                            'title' => 'Rondvaart deluxe',
                            'participants' => 10,
                            'date' => '2026-06-18',
                            'starttime' => '11:00',
                            'endtime' => '12:30',
                            'selected_options' => array(
                                array('label' => 'Lunch aan boord'),
                                array('name' => 'Open bar 90 min'),
                            ),
                            'slot_label' => '2026-06-18 11:00-12:30',
                            'bookingresolution' => array(
                                'pricing' => array(
                                    'per_person' => 32.5,
                                    'dynamic' => array('total' => 325.0),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
            array(
                'name' => 'Snapshot Contact',
                'email' => 'snapshot@example.test',
            ),
            7
        );

        $quote = $repository->findQuote((int) $result['quote']['id']);
        $version = $repository->findQuoteVersion((int) ($quote['current_version_id'] ?? 0));
        $lines = $repository->listQuoteLines((int) ($version['id'] ?? 0));

        $this->assertCount(1, $lines);
        $this->assertSame(array('Lunch aan boord', 'Open bar 90 min'), $lines[0]['selected_option_labels_json']);
        $this->assertSame('2026-06-18 11:00-12:30', $lines[0]['validated_slot_label']);
    }

    public function testPlannerIntakeCarriesRequesterAddressIntoNormalizedPayload(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requests = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $intake = new PlannerQuoteIntakeService($requests, $conversion, $followups);

        $result = $intake->createFromPlannerPlan(
            77,
            array(
                'title' => 'Adres test',
                'days'  => array(array('date' => '2026-06-20')),
                'meta'  => array('participant_count' => 10),
            ),
            array(
                'name'    => 'Contact Persoon',
                'email'   => 'contact@example.test',
                'phone'   => '0687654321',
                'company' => 'Voorbeeld BV',
                'address' => array(
                    'address_1' => 'Markt 10',
                    'postcode'  => '5211 JV',
                    'city'      => 'Den Bosch',
                    'country'   => 'NL',
                ),
                'message' => 'Graag NAW bewaren.',
            ),
            13
        );

        $requester = $result['request']['normalized_payload']['requester'];
        $this->assertSame('Voorbeeld BV', $requester['company']);
        $this->assertSame('Markt 10', $requester['address']['address_1']);
        $this->assertSame('5211 JV', $requester['address']['postcode']);
        $this->assertSame('Den Bosch', $requester['address']['city']);
        $this->assertSame('Graag NAW bewaren.', $result['request']['normalized_payload']['notes']);
    }

    public function testComposeCustomerPayloadNormalizerSupportsBillingDetails(): void
    {
        require_once dirname(__DIR__, 2) . '/modules/core/Rest/RestService.php';

        $reflection = new \ReflectionMethod(\BSPModule\Core\Rest\RestService::class, 'normalize_compose_customer_payload');
        $reflection->setAccessible(true);

        $customer = $reflection->invoke(null, array(
            'customer_name' => 'Klant Voorbeeld',
            'customer_email' => 'klant@example.test',
            'customer_phone' => '0611111111',
            'customer_company' => 'Example BV',
            'customer_billing' => array(
                'address_1' => 'Hinthamerstraat 1',
                'postcode' => '5211 MK',
                'city' => 'Den Bosch',
                'country' => 'NL',
            ),
        ));

        $this->assertSame('Klant Voorbeeld', $customer['name']);
        $this->assertSame('klant@example.test', $customer['email']);
        $this->assertSame('0611111111', $customer['phone']);
        $this->assertSame('Example BV', $customer['company']);
        $this->assertSame('Hinthamerstraat 1', $customer['billing']['address_1']);
        $this->assertSame('5211 MK', $customer['billing']['postcode']);
        $this->assertSame('Den Bosch', $customer['billing']['city']);
    }

    public function testReviewApprovalRequiresNoBlockingAssumptions(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $reviews = new QuoteReviewService($repository, $events, $followups);

        $request = $requestService->create(array(
            'request_summary' => 'Review blokkade',
            'requester_email' => 'review@example.test',
        ));
        $quote = $conversion->convertRequestToQuote((int) $request['id'], 11);

        $this->expectException(\InvalidArgumentException::class);
        $reviews->approve((int) $quote['id'], 11);
    }

    public function testReviewApprovalSetsSendStatusWhenClear(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $reviews = new QuoteReviewService($repository, $events, $followups);

        $request = $requestService->create(array(
            'request_summary' => 'Klaar voor review',
            'requester_email' => 'ready@example.test',
            'group_size' => 6,
            'preferred_date' => '2026-07-01',
            'items' => array(
                array(
                    'product_id' => 9,
                    'title' => 'Escape room',
                    'participants' => 6,
                    'pricing_confidence' => 'snapshot',
                    'availability_confidence' => 'projected',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 3);
        $updated = $reviews->approve((int) $quote['id'], 3);

        $this->assertSame('approved', $updated['review_status']);
        $this->assertSame('ready_to_send', $updated['send_status']);
        $this->assertSame('reviewed', $updated['status']);
    }

    public function testFollowupCanBeCompleted(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $followups = new QuoteFollowupService($repository, $events);
        $quote = $repository->createQuote(array(
            'quote_reference' => 'Q-TEST',
            'quote_request_id' => 2,
            'status' => 'draft',
            'review_status' => 'not_started',
            'send_status' => 'not_ready',
            'handoff_status' => 'not_ready',
        ));

        $followup = $followups->create(array(
            'quote_id' => (int) $quote['id'],
            'title' => 'Bel klant voor ontbrekende info',
            'followup_type' => 'customer_info',
            'priority' => 'normal',
            'actor_id' => 5,
        ));
        $completed = $followups->complete((int) $followup['id'], 5);

        $this->assertSame('completed', $completed['status']);
        $this->assertSame(5, $completed['completed_by']);
    }

    public function testQuoteCanBeMarkedSentManualAndReopenedWithoutWooExecution(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $reviews = new QuoteReviewService($repository, $events, $followups);
        $send = new QuoteSendService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Send flow',
            'requester_email' => 'send@example.test',
            'group_size' => 6,
            'preferred_date' => '2026-07-02',
            'items' => array(
                array(
                    'product_id' => 14,
                    'title' => 'Dinner game',
                    'participants' => 6,
                    'pricing_confidence' => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 21);
        $approved = $reviews->approve((int) $quote['id'], 21);
        $sent = $send->markSentManual((int) $approved['id'], 'phone', 'Klant telefonisch akkoord geïnformeerd.', 21);
        $reopened = $send->reopenSend((int) $approved['id'], 'Nog revisie nodig.', 21);

        $this->assertSame('sent_manual', $sent['send_status']);
        $this->assertSame('sent', $sent['status']);
        $this->assertSame(21, $sent['sent_by']);
        $this->assertSame('ready_to_send', $reopened['send_status']);
        $this->assertSame('reviewed', $reopened['status']);
        $this->assertArrayNotHasKey('woo_order_id', array_filter($reopened, static fn ($value) => $value !== null && $value !== ''));
        $this->assertArrayNotHasKey('booking_master_id', array_filter($reopened, static fn ($value) => $value !== null && $value !== ''));
    }

    public function testPrepareResnapshotCreatesNewVersionWithoutWooOrder(): void
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
                    'payload' => array('source' => 'stub_pricing'),
                    'unit_amount_snapshot' => 25.0,
                    'line_total_snapshot' => 100.0,
                    'currency' => 'EUR',
                );
            }

            public function lookupAvailability(array $line): array
            {
                return array(
                    'confidence' => 'confirmed',
                    'available' => true,
                    'payload' => array('source' => 'stub_availability'),
                );
            }
        };
        $handoff = new QuoteHandoffPreparationService($repository, $assumptions, $events, $lookup);

        $request = $requestService->create(array(
            'request_summary' => 'Prepare handoff',
            'requester_email' => 'resnapshot@example.test',
            'group_size' => 4,
            'preferred_date' => '2026-07-10',
            'items' => array(
                array(
                    'product_id' => 12,
                    'title' => 'Kookworkshop',
                    'participants' => 4,
                    'pricing_confidence' => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));
        $quote = $conversion->convertRequestToQuote((int) $request['id'], 4);
        $repository->updateQuoteVersion((int) ($quote['current_version_id'] ?? 0), array(
            'proposal_title' => 'Voorstel voor jullie kookworkshop',
            'proposal_summary' => 'Een klantgericht voorstel met programma, tijd en prijs.',
        ));
        $reviews->approve((int) $quote['id'], 4);
        $ready = $conversion->markReadyForResnapshot((int) $quote['id'], 4);
        $result = $handoff->prepareResnapshot((int) $ready['id'], 4);

        $this->assertSame('resnapshot_prepared', $result['quote']['handoff_status']);
        $this->assertSame(2, $result['version']['version_number']);
        $this->assertArrayNotHasKey('woo_order_id', array_filter($result['quote'], static fn ($value) => $value !== null && $value !== ''));
        $this->assertArrayNotHasKey('booking_master_id', array_filter($result['quote'], static fn ($value) => $value !== null && $value !== ''));
    }

    public function testPrepareResnapshotPersistsCanonicalOptionAndSlotLabels(): void
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
                    'payload' => array(
                        'option_label' => 'Chef menu 3 gangen',
                    ),
                    'unit_amount_snapshot' => 45.0,
                    'line_total_snapshot' => 180.0,
                    'currency' => 'EUR',
                );
            }

            public function lookupAvailability(array $line): array
            {
                return array(
                    'confidence' => 'confirmed',
                    'available' => true,
                    'payload' => array(
                        'slot_label' => '2026-07-10 18:00-20:00',
                    ),
                );
            }
        };
        $handoff = new QuoteHandoffPreparationService($repository, $assumptions, $events, $lookup);

        $request = $requestService->create(array(
            'request_summary' => 'Canonical resnapshot labels',
            'requester_email' => 'canonical@example.test',
            'group_size' => 4,
            'preferred_date' => '2026-07-10',
            'items' => array(
                array(
                    'product_id' => 12,
                    'title' => 'Kookworkshop',
                    'participants' => 4,
                    'service_date' => '2026-07-10',
                    'start_time' => '18:00',
                    'end_time' => '20:00',
                    'selected_option_labels' => array('Welkomsdrank'),
                    'validated_slot_label' => '2026-07-10 18:00-20:00',
                    'pricing_confidence' => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));
        $quote = $conversion->convertRequestToQuote((int) $request['id'], 4);
        $repository->updateQuoteVersion((int) ($quote['current_version_id'] ?? 0), array(
            'proposal_title' => 'Voorstel voor jullie kookworkshop',
            'proposal_summary' => 'Een klantgericht voorstel met programma, tijd en prijs.',
        ));
        $reviews->approve((int) $quote['id'], 4);
        $ready = $conversion->markReadyForResnapshot((int) $quote['id'], 4);
        $result = $handoff->prepareResnapshot((int) $ready['id'], 4);

        $this->assertCount(1, $result['lines']);
        $this->assertSame(array('Welkomsdrank', 'Chef menu 3 gangen'), $result['lines'][0]['selected_option_labels_json']);
        $this->assertSame('2026-07-10 18:00-20:00', $result['lines'][0]['validated_slot_label']);
    }

    public function testPrepareResnapshotBlocksWhenLookupCannotConfirm(): void
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
                    'confidence' => 'unknown',
                    'payload' => array('reason' => 'pricing_missing'),
                    'unit_amount_snapshot' => null,
                    'line_total_snapshot' => null,
                    'currency' => 'EUR',
                );
            }

            public function lookupAvailability(array $line): array
            {
                return array(
                    'confidence' => 'unknown',
                    'available' => false,
                    'payload' => array('reason' => 'availability_missing'),
                );
            }
        };
        $handoff = new QuoteHandoffPreparationService($repository, $assumptions, $events, $lookup);

        $request = $requestService->create(array(
            'request_summary' => 'Blocked handoff prep',
            'requester_email' => 'blocked@example.test',
            'group_size' => 5,
            'preferred_date' => '2026-07-11',
            'items' => array(
                array(
                    'product_id' => 18,
                    'title' => 'Proeverij',
                    'participants' => 5,
                    'pricing_confidence' => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));
        $quote = $conversion->convertRequestToQuote((int) $request['id'], 4);
        $reviews->approve((int) $quote['id'], 4);
        $ready = $conversion->markReadyForResnapshot((int) $quote['id'], 4);
        $result = $handoff->prepareResnapshot((int) $ready['id'], 4);

        $this->assertSame('resnapshot_blocked', $result['quote']['handoff_status']);
        $assumptionRows = $repository->listQuoteAssumptions((int) $ready['id']);
        $blocking = array_filter($assumptionRows, static fn (array $row): bool => (string) ($row['assumption_type'] ?? '') === 'resnapshot_availability_unconfirmed');
        $this->assertNotEmpty($blocking);
    }

    public function testControlledHandoffPackageBuildsFromPreparedResnapshot(): void
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
                    'payload' => array('source' => 'stub_pricing'),
                    'unit_amount_snapshot' => 19.5,
                    'line_total_snapshot' => 78.0,
                    'currency' => 'EUR',
                );
            }

            public function lookupAvailability(array $line): array
            {
                return array(
                    'confidence' => 'confirmed',
                    'available' => true,
                    'payload' => array('source' => 'stub_availability'),
                );
            }
        };
        $handoffPrep = new QuoteHandoffPreparationService($repository, $assumptions, $events, $lookup);
        $adapter = new QuoteHandoffAdapterService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Package build',
            'requester_email' => 'package@example.test',
            'group_size' => 4,
            'preferred_date' => '2026-07-12',
            'items' => array(
                array(
                    'product_id' => 22,
                    'title' => 'Quiz',
                    'participants' => 4,
                    'pricing_confidence' => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));
        $quote = $conversion->convertRequestToQuote((int) $request['id'], 6);
        $reviews->approve((int) $quote['id'], 6);
        $ready = $conversion->markReadyForResnapshot((int) $quote['id'], 6);
        $handoffPrep->prepareResnapshot((int) $ready['id'], 6);
        // Pin approved_version_id before handoff (simulates admin acceptance after resnapshot)
        $preparedQuote = $repository->findQuote((int) $ready['id']);
        $repository->updateQuote((int) $ready['id'], ['approved_version_id' => (int) $preparedQuote['current_version_id']]);

        $package = $adapter->buildControlledPackage((int) $ready['id'], 6);
        $storedQuote = $repository->findQuote((int) $ready['id']);
        $storedVersion = $repository->findQuoteVersion((int) $storedQuote['current_version_id']);

        $this->assertSame('handoff_package_ready', $storedQuote['handoff_status']);
        $this->assertTrue($package['ready_for_execution']);
        $this->assertSame('controlled_handoff', $package['package_type']);
        $this->assertSame(1, count($package['items']));
        $this->assertIsArray($storedVersion['handoff_payload_json']);
    }

    public function testExecutionAdapterPayloadBuildsFromControlledPackage(): void
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
                    'payload' => array('display_total' => 120.0),
                    'unit_amount_snapshot' => 30.0,
                    'line_total_snapshot' => 120.0,
                    'currency' => 'EUR',
                );
            }

            public function lookupAvailability(array $line): array
            {
                return array(
                    'confidence' => 'confirmed',
                    'available' => true,
                    'payload' => array('slots' => array(array('start' => '10:00'))),
                );
            }
        };
        $handoffPrep = new QuoteHandoffPreparationService($repository, $assumptions, $events, $lookup);
        $handoffAdapter = new QuoteHandoffAdapterService($repository, $events);
        $executionAdapter = new QuoteExecutionAdapterService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Execution payload',
            'requester_email' => 'execute@example.test',
            'group_size' => 4,
            'preferred_date' => '2026-07-13',
            'items' => array(
                array(
                    'product_id' => 44,
                    'title' => 'City game',
                    'participants' => 4,
                    'service_date' => '2026-07-13',
                    'start_time' => '10:00',
                    'end_time' => '12:00',
                    'pricing_confidence' => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));
        $quote = $conversion->convertRequestToQuote((int) $request['id'], 8);
        $reviews->approve((int) $quote['id'], 8);
        $ready = $conversion->markReadyForResnapshot((int) $quote['id'], 8);
        $handoffPrep->prepareResnapshot((int) $ready['id'], 8);
        // Pin approved_version_id before handoff (simulates admin acceptance after resnapshot)
        $preparedQuote8 = $repository->findQuote((int) $ready['id']);
        $repository->updateQuote((int) $ready['id'], ['approved_version_id' => (int) $preparedQuote8['current_version_id']]);
        $handoffAdapter->buildControlledPackage((int) $ready['id'], 8);

        $payload = $executionAdapter->buildCartOrderPrep((int) $ready['id'], 8);
        $storedQuote = $repository->findQuote((int) $ready['id']);
        $storedVersion = $repository->findQuoteVersion((int) $storedQuote['current_version_id']);
        $handoffPayload = $storedVersion['handoff_payload_json'];

        $this->assertSame('execution_payload_ready', $storedQuote['handoff_status']);
        $this->assertSame('cart_order_prep', $payload['adapter_type']);
        $this->assertSame(1, count($payload['items']));
        $this->assertIsArray($handoffPayload['execution_adapter']);
        $this->assertArrayNotHasKey('woo_order_id', array_filter($storedQuote, static fn ($value) => $value !== null && $value !== ''));
        $this->assertArrayNotHasKey('booking_master_id', array_filter($storedQuote, static fn ($value) => $value !== null && $value !== ''));
    }

    public function testControlledPackageCarriesFullRequesterContext(): void
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
                    'payload' => array('source' => 'stub_pricing'),
                    'unit_amount_snapshot' => 30.0,
                    'line_total_snapshot' => 120.0,
                    'currency' => 'EUR',
                );
            }

            public function lookupAvailability(array $line): array
            {
                return array(
                    'confidence' => 'confirmed',
                    'available' => true,
                    'payload' => array('source' => 'stub_availability'),
                );
            }
        };
        $handoffPrep = new QuoteHandoffPreparationService($repository, $assumptions, $events, $lookup);
        $adapter = new QuoteHandoffAdapterService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Volledige handoff klantcontext',
            'requester_name' => 'Handoff Contact',
            'requester_email' => 'handoff-contact@example.test',
            'requester_phone' => '0600000000',
            'requester_company' => 'Dagje Den Bosch BV',
            'requester_address' => array(
                'address_1' => 'Parade 1',
                'postcode' => '5211 KL',
                'city' => 'Den Bosch',
                'country' => 'NL',
            ),
            'requester_message' => 'Bel na akkoord.',
            'group_size' => 4,
            'preferred_date' => '2026-08-01',
            'preferred_start_time' => '10:00',
            'preferred_end_time' => '12:00',
            'planner_plan_id' => 123,
            'items' => array(
                array(
                    'product_id' => 88,
                    'title' => 'Handoff activiteit',
                    'participants' => 4,
                    'service_date' => '2026-08-01',
                    'start_time' => '10:00',
                    'end_time' => '12:00',
                    'pricing_confidence' => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));

        $quote = $conversion->convertRequestToQuote((int) $request['id'], 14);
        $reviews->approve((int) $quote['id'], 14);
        $ready = $conversion->markReadyForResnapshot((int) $quote['id'], 14);
        $handoffPrep->prepareResnapshot((int) $ready['id'], 14);
        // Pin approved_version_id before handoff (simulates admin acceptance after resnapshot)
        $preparedQuote14 = $repository->findQuote((int) $ready['id']);
        $repository->updateQuote((int) $ready['id'], ['approved_version_id' => (int) $preparedQuote14['current_version_id']]);

        $package = $adapter->buildControlledPackage((int) $ready['id'], 14);

        $this->assertSame('Handoff Contact', $package['customer']['name']);
        $this->assertSame('handoff-contact@example.test', $package['customer']['email']);
        $this->assertSame('0600000000', $package['customer']['phone']);
        $this->assertSame('Dagje Den Bosch BV', $package['customer']['company']);
        $this->assertSame('Parade 1', $package['customer']['billing']['address_1']);
        $this->assertSame('5211 KL', $package['customer']['billing']['postcode']);
        $this->assertSame('Den Bosch', $package['customer']['billing']['city']);
        $this->assertSame('Bel na akkoord.', $package['request_context']['notes']);
        $this->assertSame(123, $package['request_context']['planner_plan_id']);
    }

    public function testExecutionRunnerValidatesRuntimeWithoutExecuting(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $followups = new QuoteFollowupService($repository, $events);
        $reviews = new QuoteReviewService($repository, $events, $followups);
        $prepLookup = new class extends QuoteExecutionLookupService {
            public function lookupPricing(array $line): array
            {
                return array(
                    'confidence' => 'execution_verified',
                    'payload' => array('display_total' => 150.0),
                    'unit_amount_snapshot' => 37.5,
                    'line_total_snapshot' => 150.0,
                    'currency' => 'EUR',
                );
            }

            public function lookupAvailability(array $line): array
            {
                return array(
                    'confidence' => 'confirmed',
                    'available' => true,
                    'payload' => array('slots' => array(array('start' => '09:00'))),
                );
            }
        };
        $runtimeLookup = new class extends QuoteExecutionLookupService {
            public function lookupPricing(array $line): array
            {
                return array(
                    'confidence' => 'execution_verified',
                    'payload' => array('display_total' => 150.0),
                    'unit_amount_snapshot' => 37.5,
                    'line_total_snapshot' => 150.0,
                    'currency' => 'EUR',
                );
            }

            public function lookupAvailability(array $line): array
            {
                return array(
                    'confidence' => 'confirmed',
                    'available' => true,
                    'payload' => array('slots' => array(array('start' => '09:00'))),
                );
            }
        };
        $handoffPrep = new QuoteHandoffPreparationService($repository, $assumptions, $events, $prepLookup);
        $handoffAdapter = new QuoteHandoffAdapterService($repository, $events);
        $executionAdapter = new QuoteExecutionAdapterService($repository, $events);
        $runner = new QuoteExecutionRunnerService($repository, $events, $runtimeLookup);

        $request = $requestService->create(array(
            'request_summary' => 'Runtime validation',
            'requester_email' => 'runtime@example.test',
            'group_size' => 4,
            'preferred_date' => '2026-07-14',
            'items' => array(
                array(
                    'product_id' => 51,
                    'title' => 'Workshop',
                    'participants' => 4,
                    'service_date' => '2026-07-14',
                    'start_time' => '09:00',
                    'end_time' => '11:00',
                    'pricing_confidence' => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));
        $quote = $conversion->convertRequestToQuote((int) $request['id'], 9);
        $reviews->approve((int) $quote['id'], 9);
        $ready = $conversion->markReadyForResnapshot((int) $quote['id'], 9);
        $handoffPrep->prepareResnapshot((int) $ready['id'], 9);
        // Pin approved_version_id before handoff (simulates admin acceptance after resnapshot)
        $preparedQuote9 = $repository->findQuote((int) $ready['id']);
        $repository->updateQuote((int) $ready['id'], ['approved_version_id' => (int) $preparedQuote9['current_version_id']]);
        $handoffAdapter->buildControlledPackage((int) $ready['id'], 9);
        $executionAdapter->buildCartOrderPrep((int) $ready['id'], 9);

        $validation = $runner->validateCartReady((int) $ready['id'], 9);
        $storedQuote = $repository->findQuote((int) $ready['id']);
        $storedVersion = $repository->findQuoteVersion((int) $storedQuote['current_version_id']);
        $handoffPayload = $storedVersion['handoff_payload_json'];

        $this->assertSame('execution_validated', $storedQuote['handoff_status']);
        $this->assertTrue($validation['ready_for_runtime_execution']);
        $this->assertIsArray($handoffPayload['execution_validation']);
        $this->assertArrayNotHasKey('woo_order_id', array_filter($storedQuote, static fn ($value) => $value !== null && $value !== ''));
        $this->assertArrayNotHasKey('booking_master_id', array_filter($storedQuote, static fn ($value) => $value !== null && $value !== ''));
    }

    public function testExecutionLaunchBuildsAdminOnlyLaunchPayload(): void
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
                    'payload' => array('display_total' => 88.0),
                    'unit_amount_snapshot' => 22.0,
                    'line_total_snapshot' => 88.0,
                    'currency' => 'EUR',
                );
            }

            public function lookupAvailability(array $line): array
            {
                return array(
                    'confidence' => 'confirmed',
                    'available' => true,
                    'payload' => array('slots' => array(array('start' => '13:00'))),
                );
            }
        };
        $handoffPrep = new QuoteHandoffPreparationService($repository, $assumptions, $events, $lookup);
        $handoffAdapter = new QuoteHandoffAdapterService($repository, $events);
        $executionAdapter = new QuoteExecutionAdapterService($repository, $events);
        $runner = new QuoteExecutionRunnerService($repository, $events, $lookup);
        $launcher = new QuoteExecutionLaunchService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Execution launch',
            'requester_email' => 'launch@example.test',
            'group_size' => 4,
            'preferred_date' => '2026-07-15',
            'items' => array(
                array(
                    'product_id' => 61,
                    'title' => 'Pubquiz',
                    'participants' => 4,
                    'service_date' => '2026-07-15',
                    'start_time' => '13:00',
                    'end_time' => '15:00',
                    'pricing_confidence' => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));
        $quote = $conversion->convertRequestToQuote((int) $request['id'], 10);
        $reviews->approve((int) $quote['id'], 10);
        $ready = $conversion->markReadyForResnapshot((int) $quote['id'], 10);
        $handoffPrep->prepareResnapshot((int) $ready['id'], 10);
        // Pin approved_version_id before handoff (simulates admin acceptance after resnapshot)
        $preparedQuote10 = $repository->findQuote((int) $ready['id']);
        $repository->updateQuote((int) $ready['id'], ['approved_version_id' => (int) $preparedQuote10['current_version_id']]);
        $handoffAdapter->buildControlledPackage((int) $ready['id'], 10);
        $executionAdapter->buildCartOrderPrep((int) $ready['id'], 10);
        $runner->validateCartReady((int) $ready['id'], 10);

        $launch = $launcher->buildWooCartSessionPrep((int) $ready['id'], 10);
        $storedQuote = $repository->findQuote((int) $ready['id']);
        $storedVersion = $repository->findQuoteVersion((int) $storedQuote['current_version_id']);
        $handoffPayload = $storedVersion['handoff_payload_json'];

        $this->assertSame('execution_launch_ready', $storedQuote['handoff_status']);
        $this->assertSame('woo_cart_session_prep', $launch['launch_type']);
        $this->assertNotEmpty($launch['launch_token']);
        $this->assertSame(1, count($launch['items']));
        $this->assertIsArray($handoffPayload['execution_launch']);
        $this->assertArrayNotHasKey('woo_order_id', array_filter($storedQuote, static fn ($value) => $value !== null && $value !== ''));
        $this->assertArrayNotHasKey('booking_master_id', array_filter($storedQuote, static fn ($value) => $value !== null && $value !== ''));
    }

    public function testWooCartHydrationUsesGatewayWithoutCreatingBookingRecords(): void
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
            'request_summary' => 'Woo cart hydration',
            'requester_email' => 'hydrate@example.test',
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
        // Pin approved_version_id before handoff (simulates admin acceptance after resnapshot)
        $preparedQuote12 = $repository->findQuote((int) $ready['id']);
        $repository->updateQuote((int) $ready['id'], ['approved_version_id' => (int) $preparedQuote12['current_version_id']]);
        $handoffAdapter->buildControlledPackage((int) $ready['id'], 12);
        $executionAdapter->buildCartOrderPrep((int) $ready['id'], 12);
        $runner->validateCartReady((int) $ready['id'], 12);
        $launch = $launcher->buildWooCartSessionPrep((int) $ready['id'], 12);

        $result = $hydrator->hydrateLaunchToCart((int) $ready['id'], (string) $launch['launch_token'], 12);
        $storedQuote = $repository->findQuote((int) $ready['id']);
        $storedVersion = $repository->findQuoteVersion((int) $storedQuote['current_version_id']);
        $handoffPayload = $storedVersion['handoff_payload_json'];

        $this->assertSame('woo_cart_hydrated', $storedQuote['handoff_status']);
        $this->assertSame(1, $result['cart_item_count']);
        $this->assertSame('woo_cart_session_prep', $gateway->lastPayload['launch_type']);
        $this->assertIsArray($handoffPayload['hydration_result']);
        $this->assertSame(1, $handoffPayload['hydration_result']['result']['cart_item_count']);
        $this->assertArrayNotHasKey('woo_order_id', array_filter($storedQuote, static fn ($value) => $value !== null && $value !== ''));
        $this->assertArrayNotHasKey('booking_master_id', array_filter($storedQuote, static fn ($value) => $value !== null && $value !== ''));
    }

    public function testRequestOrderBridgeRequiresExecutionAdapterPayload(): void
    {
        $repository = new InMemoryQuoteRepository();
        $events = new QuoteEventLogger($repository);
        $requestService = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion = new QuoteConversionService($repository, $assumptions, $events);
        $bridge = new QuoteRequestOrderBridgeService($repository, $events);

        $request = $requestService->create(array(
            'request_summary' => 'Bridge gating',
            'requester_email' => 'bridge@example.test',
            'group_size' => 4,
            'preferred_date' => '2026-07-20',
            'items' => array(
                array(
                    'product_id' => 91,
                    'title' => 'Stadsquiz',
                    'participants' => 4,
                    'pricing_confidence' => 'execution_verified',
                    'availability_confidence' => 'confirmed',
                ),
            ),
        ));
        $quote = $conversion->convertRequestToQuote((int) $request['id'], 30);

        $this->expectException(\InvalidArgumentException::class);
        $bridge->createWooRequestOrder((int) $quote['id'], 30);
    }
}
