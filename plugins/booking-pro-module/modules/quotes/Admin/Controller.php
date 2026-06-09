<?php
declare(strict_types=1);
namespace BSP\Quotes\Admin;
use BSP\Bookings\Service\BookingManager;
use BSP\Bookings\Service\BookingRepository as BookingStorageRepository;
use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Repository\QuoteRepositoryInterface;
use BSP\Quotes\Service\QuoteAssumptionService;
use BSP\Quotes\Service\QuoteAdminConfirmationService;
use BSP\Quotes\Service\QuoteBookingBridgeService;
use BSP\Quotes\Service\QuoteCommunicationService;
use BSP\Quotes\Service\QuoteConversionService;
use BSP\Quotes\Service\QuoteConfirmationReadinessService;
use BSP\Quotes\Service\QuoteExecutionLookupService;
use BSP\Quotes\Service\QuoteExecutionLaunchService;
use BSP\Quotes\Service\QuoteExecutionRunnerService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteExecutionAdapterService;
use BSP\Quotes\Service\QuoteImmutabilityGuard;
use BSP\Quotes\Service\QuoteWooCartHydrationService;
use BSP\Quotes\Service\QuoteFollowupService;
use BSP\Quotes\Service\QuoteHandoffAdapterService;
use BSP\Quotes\Service\QuoteHandoffPreparationService;
use BSP\Quotes\Service\QuoteLineControlStatusService;
use BSP\Quotes\Service\QuoteOperationsDraftService;
use BSP\Quotes\Service\QuoteProposalSendDecisionService;
use BSP\Quotes\Service\QuoteRequestService;
use BSP\Quotes\Service\QuoteReviewService;
use BSP\Quotes\Service\QuoteSendService;
use BSP\Quotes\Service\QuoteSendReadinessValidator;
use BSP\Quotes\Service\QuoteSupplierConfirmationService;
use BSP\Quotes\Service\WooCartLaunchGateway;
use function add_query_arg;
use function add_menu_page;
use function add_submenu_page;
use function admin_url;
use function check_admin_referer;
use function current_user_can;
use function esc_html;
use function esc_html__;
require_once __DIR__ . '/QuoteWorkspaceRenderer.php';
require_once __DIR__ . '/QuoteBuilderRenderer.php';
use function sanitize_email;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function wp_die;
use function wp_redirect;
final class Controller
{
    private const CAPABILITY = 'manage_woocommerce';
    private const FALLBACK_CAPABILITY = 'manage_options';
    public static function registerMenu(): void {
        $capability = self::capability();
        add_menu_page(
            __('Quotes', 'sbdp'),
            __('Quotes', 'sbdp'),
            $capability,
            'sbdp_quotes',
            array(__CLASS__, 'renderQuotesPage'),
            'dashicons-media-spreadsheet',
            57
        );
        add_submenu_page('sbdp_quotes', __('Quotes', 'sbdp'), __('Alle Quotes', 'sbdp'), $capability, 'sbdp_quotes', array(__CLASS__, 'renderQuotesPage'));
        add_submenu_page('sbdp_quotes', __('Quote Inbox', 'sbdp'), __('Quote Inbox', 'sbdp'), $capability, 'sbdp_quote_inbox', array(__CLASS__, 'renderQuoteInboxPage'));
        add_submenu_page('sbdp_quotes', __('Quote Requests', 'sbdp'), __('Quote Requests', 'sbdp'), $capability, 'sbdp_quote_requests', array(__CLASS__, 'renderQuoteRequestsPage'));
        add_submenu_page('sbdp_quotes', __('Quote AI & Mail', 'sbdp'), __('Quote AI & Mail', 'sbdp'), $capability, 'sbdp_quote_ai_mail', array(__CLASS__, 'renderQuoteAiMailSettingsPage'));
        add_submenu_page('sbdp_bookings', __('Quote Requests', 'sbdp'), __('Quote Requests', 'sbdp'), $capability, 'sbdp_quote_requests', array(__CLASS__, 'renderQuoteRequestsPage'));
        add_submenu_page('sbdp_bookings', __('Quotes', 'sbdp'), __('Quotes', 'sbdp'), $capability, 'sbdp_quotes', array(__CLASS__, 'renderQuotesPage'));
    }
    public static function handleCreateQuoteRequest(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_request_create');
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteRequestService($repository, $events);
        try {
            $service->create(array(
                'source_type'        => 'admin_manual',
                'request_summary'    => sanitize_text_field((string) ($_POST['request_summary'] ?? '')),
                'requester_name'     => sanitize_text_field((string) ($_POST['requester_name'] ?? '')),
                'requester_email'    => sanitize_email((string) ($_POST['requester_email'] ?? '')),
                'requester_phone'    => sanitize_text_field((string) ($_POST['requester_phone'] ?? '')),
                'requester_company'  => sanitize_text_field((string) ($_POST['requester_company'] ?? '')),
                'requester_address'  => array(
                    'address_1' => sanitize_text_field((string) ($_POST['requester_address_1'] ?? '')),
                    'address_2' => sanitize_text_field((string) ($_POST['requester_address_2'] ?? '')),
                    'postcode'  => sanitize_text_field((string) ($_POST['requester_postcode'] ?? '')),
                    'city'      => sanitize_text_field((string) ($_POST['requester_city'] ?? '')),
                    'country'   => sanitize_text_field((string) ($_POST['requester_country'] ?? 'NL')),
                ),
                'requester_message'  => sanitize_textarea_field((string) ($_POST['requester_message'] ?? '')),
                'group_size'         => isset($_POST['group_size']) ? (int) $_POST['group_size'] : 0,
                'preferred_date'     => sanitize_text_field((string) ($_POST['preferred_date'] ?? '')),
                'actor_id'           => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
            ));
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quote_requests', array('quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quote_requests', array('quote_request_created' => '1'));
    }
    public static function handleConvertQuoteRequest(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_request_convert');
        $requestId = isset($_POST['quote_request_id']) ? (int) $_POST['quote_request_id'] : 0;
        $repository  = new QuoteRepository();
        $events      = new QuoteEventLogger($repository);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $followups   = new QuoteFollowupService($repository, $events);
        $service     = new QuoteConversionService($repository, $assumptions, $events);
        try {
            $quote = $service->convertRequestToQuote($requestId, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
            $followups->createInitialReviewFollowup($quote, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quote_requests', array('quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => (int) ($quote['id'] ?? 0), 'quote_converted' => '1'));
    }
    public static function handleRequestReview(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_review_request');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $followups  = new QuoteFollowupService($repository, $events);
        $service    = new QuoteReviewService($repository, $events, $followups);
        try {
            $service->requestReview($quoteId, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'review_requested' => '1'));
    }
    public static function handleApproveReview(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_review_approve');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $followups  = new QuoteFollowupService($repository, $events);
        $service    = new QuoteReviewService($repository, $events, $followups);
        try {
            $service->approve($quoteId, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'review_approved' => '1'));
    }
    public static function handleReturnToDraft(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_review_return');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $note    = sanitize_textarea_field((string) ($_POST['review_note'] ?? ''));
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $followups  = new QuoteFollowupService($repository, $events);
        $service    = new QuoteReviewService($repository, $events, $followups);
        try {
            $service->returnToDraft($quoteId, $note, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'review_returned' => '1'));
    }
    public static function handleResolveAssumption(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_resolve_assumption');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $assumptionId = isset($_POST['assumption_id']) ? (int) $_POST['assumption_id'] : 0;
        $resolutionNote = trim(sanitize_textarea_field((string) ($_POST['resolution_note'] ?? '')));
        $workspaceTab = sanitize_key((string) ($_POST['workspace_tab'] ?? 'dashboard'));
        if (! in_array($workspaceTab, array('dashboard', 'build', 'proposal', 'communication', 'handoff', 'history'), true)) {
            $workspaceTab = 'dashboard';
        }
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        try {
            (new QuoteImmutabilityGuard($repository))->assertQuoteCommercialContextEditable($quoteId);
        } catch (\InvalidArgumentException $guardException) {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => $workspaceTab,
                'quote_error' => rawurlencode($guardException->getMessage()),
            ));
        }
        if ($resolutionNote === '') {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => $workspaceTab,
                'quote_error' => rawurlencode(__('Voeg een korte operatornotitie toe voordat je een commerciële blocker oplost.', 'sbdp')),
            ));
        }
        $matchedAssumption = null;
        foreach ($repository->listQuoteAssumptions($quoteId) as $assumption) {
            if ((int) ($assumption['id'] ?? 0) !== $assumptionId) {
                continue;
            }
            $matchedAssumption = $assumption;
            break;
        }
        if (! is_array($matchedAssumption)) {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => $workspaceTab,
                'quote_error' => rawurlencode(__('Assumption niet gevonden voor deze quote.', 'sbdp')),
            ));
        }
        $assumptionType = (string) ($matchedAssumption['assumption_type'] ?? '');
        if (! in_array($assumptionType, array('uncertain_pricing', 'uncertain_availability'), true)) {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => $workspaceTab,
                'quote_error' => rawurlencode(__('Alleen commerciële prijs- of beschikbaarheidsblockers kunnen hier handmatig worden opgelost.', 'sbdp')),
            ));
        }
        if ((string) ($matchedAssumption['status'] ?? 'open') !== 'open') {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => $workspaceTab,
                'quote_error' => rawurlencode(__('Deze assumption is al opgelost.', 'sbdp')),
            ));
        }
        $actorId = function_exists('get_current_user_id') ? (int) get_current_user_id() : null;
        $now = \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
        $repository->updateQuoteAssumption($assumptionId, array(
            'status' => 'resolved',
            'resolution_note' => $resolutionNote,
            'blocks_review' => 0,
            'blocks_send' => 0,
            'blocks_handoff' => 0,
            'resolved_at' => $now,
            'resolved_by' => $actorId,
        ));
        $quote = $repository->findQuote($quoteId);
        $events->log(
            'quote_assumption_resolved',
            is_array($quote) && isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            is_array($quote) && isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
            $actorId,
            'Commerciële assumption handmatig opgelost.',
            array(
                'assumption_id' => $assumptionId,
                'assumption_type' => $assumptionType,
                'resolution_note' => $resolutionNote,
            )
        );
        self::redirect('sbdp_quotes', array(
            'quote_id' => $quoteId,
            'workspace_tab' => $workspaceTab,
            'quote_assumption_resolved' => '1',
        ));
    }
    public static function handleQuickPrepareToSend(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_quick_prepare_to_send');
        $quoteId    = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $actorId    = function_exists('get_current_user_id') ? (int) get_current_user_id() : null;
        try {
            (new QuoteImmutabilityGuard($repository))->assertQuoteCommercialContextEditable($quoteId);
        } catch (\InvalidArgumentException $guardException) {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => 'communication',
                'quote_error' => rawurlencode($guardException->getMessage()),
            ));
        }
        // 1. Resolve all open standard assumptions with a default operator note.
        $now         = \function_exists('current_time') ? (string) \current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $defaultNote = 'Operator bevestiging: prijs en beschikbaarheid gecheckt voor verzending.';
        foreach ($repository->listQuoteAssumptions($quoteId) as $assumption) {
            if ((string) ($assumption['status'] ?? 'open') !== 'open') {
                continue;
            }
            if (! in_array((string) ($assumption['assumption_type'] ?? ''), array('uncertain_pricing', 'uncertain_availability'), true)) {
                continue;
            }
            $assumptionId = (int) ($assumption['id'] ?? 0);
            if ($assumptionId === 0) {
                continue;
            }
            $repository->updateQuoteAssumption($assumptionId, array(
                'status'          => 'resolved',
                'resolution_note' => $defaultNote,
                'blocks_review'   => 0,
                'blocks_send'     => 0,
                'blocks_handoff'  => 0,
                'resolved_at'     => $now,
                'resolved_by'     => $actorId,
            ));
            $quote = $repository->findQuote($quoteId);
            $events->log(
                'quote_assumption_resolved',
                is_array($quote) && isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
                $quoteId,
                is_array($quote) && isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
                $actorId,
                'Assumption vrijgegeven via snelvoorbereiding.',
                array(
                    'assumption_id'   => $assumptionId,
                    'assumption_type' => (string) ($assumption['assumption_type'] ?? ''),
                    'resolution_note' => $defaultNote,
                )
            );
        }
        // 2. Move through review flow only if not already approved.
        $quote = $repository->findQuote($quoteId);
        if (! is_array($quote)) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'workspace_tab' => 'communication', 'quote_error' => rawurlencode(__('Quote niet gevonden.', 'sbdp'))));
        }
        $reviewStatus  = (string) ($quote['review_status'] ?? 'not_started');
        $followups     = new QuoteFollowupService($repository, $events);
        $reviewService = new QuoteReviewService($repository, $events, $followups);
        if ($reviewStatus === 'not_started') {
            try {
                $reviewService->requestReview($quoteId, $actorId);
            } catch (\Throwable $exception) {
                // If the quote cannot transition to in_review, proceed and let approve report the issue.
            }
        }
        if ($reviewStatus !== 'approved') {
            try {
                $reviewService->approve($quoteId, $actorId);
            } catch (\Throwable $exception) {
                self::redirect('sbdp_quotes', array(
                    'quote_id'      => $quoteId,
                    'workspace_tab' => 'communication',
                    'quote_error'   => rawurlencode($exception->getMessage()),
                ));
            }
        }
        self::redirect('sbdp_quotes', array(
            'quote_id'         => $quoteId,
            'workspace_tab'    => 'communication',
            'quote_send_ready' => '1',
        ));
    }
    public static function handleMarkSentManual(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_mark_sent_manual');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $note    = sanitize_textarea_field((string) ($_POST['send_note'] ?? ''));
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteSendService($repository, $events);
        try {
            $service->markSentManual(
                $quoteId,
                sanitize_text_field((string) ($_POST['send_channel'] ?? 'manual')),
                $note,
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_marked_sent' => '1'));
    }
    public static function handleReopenSend(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_reopen_send');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $note    = sanitize_textarea_field((string) ($_POST['send_reopen_note'] ?? ''));
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteSendService($repository, $events);
        try {
            $service->reopenSend(
                $quoteId,
                $note,
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_send_reopened' => '1'));
    }
    public static function handleSaveOperationsDraft(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_save_operations_draft');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteOperationsDraftService($repository, $events);
        $lines = isset($_POST['lines']) && is_array($_POST['lines']) ? $_POST['lines'] : array();
        $commercialAdjustments = isset($_POST['commercial_adjustments']) && is_array($_POST['commercial_adjustments']) ? $_POST['commercial_adjustments'] : array();
        try {
            $service->saveDraft($quoteId, array(
                'lines' => $lines,
                'commercial_adjustments' => $commercialAdjustments,
                'create_new_version' => ! empty($_POST['create_new_version']),
            ), function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => 'build',
                'quote_error' => rawurlencode($exception->getMessage()),
            ));
        }
        self::redirect('sbdp_quotes', array(
            'quote_id' => $quoteId,
            'workspace_tab' => 'build',
            'quote_operations_saved' => '1',
        ));
    }
    public static function handleUpdateLineControlStatus(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_line_control_status');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $lineId = isset($_POST['line_id']) ? (int) $_POST['line_id'] : 0;
        $dimension = sanitize_key((string) ($_POST['dimension'] ?? ''));
        $status = sanitize_key((string) ($_POST['status'] ?? ''));
        $repository = new QuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteLineControlStatusService($repository, $events);
        try {
            $service->updateStatus($quoteId, $lineId, $dimension, $status, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => 'build',
                'quote_error' => rawurlencode($exception->getMessage()),
            ));
        }
        self::redirect('sbdp_quotes', array(
            'quote_id' => $quoteId,
            'workspace_tab' => 'build',
            'quote_line_control_updated' => '1',
        ));
    }
    public static function handleGenerateProposalDraft(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_generate_proposal_draft');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $workspaceTab = self::normalizeWorkspaceTab((string) ($_POST['workspace_tab'] ?? 'communication'), 'communication');
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteCommunicationService($repository, $events);
        try {
            $service->generateProposalDraft(
                $quoteId,
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'workspace_tab' => $workspaceTab, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'workspace_tab' => $workspaceTab, 'quote_message_draft_generated' => '1'));
    }

    public static function handleUpdateProposalText(): void {
        self::assertAccess();
        if (function_exists('check_ajax_referer')) {
            \check_ajax_referer('sbdp_quote_update_proposal_text');
        }

        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteCommunicationService($repository, $events);
        $payload = array(
            'subject' => sanitize_text_field((string) \wp_unslash($_POST['subject'] ?? '')),
            'intro' => sanitize_textarea_field((string) \wp_unslash($_POST['intro'] ?? '')),
            'program_text' => sanitize_textarea_field((string) \wp_unslash($_POST['program_text'] ?? '')),
            'price_rule' => sanitize_textarea_field((string) \wp_unslash($_POST['price_rule'] ?? '')),
            'terms' => sanitize_textarea_field((string) \wp_unslash($_POST['terms'] ?? '')),
            'closing' => sanitize_textarea_field((string) \wp_unslash($_POST['closing'] ?? '')),
            'internal_note' => sanitize_textarea_field((string) \wp_unslash($_POST['internal_note'] ?? '')),
        );

        try {
            $result = $service->updateProposalText(
                $quoteId,
                $payload,
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        } catch (\Throwable $exception) {
            \wp_send_json_error(array(
                'message' => $exception->getMessage(),
            ), 400);
        }

        $sendInspection = (new QuoteSendReadinessValidator($repository))->inspect($quoteId);
        $sendDecision = (new QuoteProposalSendDecisionService($repository))->decide($quoteId);
        $sendReady = ! empty($sendDecision['proposal_send_ready']);
        $canCompleteControl = ! empty($sendDecision['can_complete_control']);
        $canSend = ! empty($sendDecision['can_send']);
        $firstBlocker = '';
        foreach ((array) ($sendDecision['blockers'] ?? ($sendInspection['blockers'] ?? array())) as $blocker) {
            if (is_array($blocker)) {
                $firstBlocker = (string) ($blocker['message'] ?? '');
                break;
            }
        }

        \wp_send_json_success(array(
            'message' => $canSend
                ? __('Voorstel klaar voor verzending.', 'sbdp')
                : ($canCompleteControl
                    ? __('Voorstel klaar voor verzending. Rond de controle af voordat je verstuurt.', 'sbdp')
                    : __('Voorstelmail opgeslagen. Je kunt het voorstel nu versturen zodra alle checks groen zijn.', 'sbdp')),
            'subject' => (string) ($result['subject'] ?? ''),
            'body' => (string) ($result['body'] ?? ''),
            'summary' => (string) ($result['summary'] ?? ''),
            'terms' => (string) ($result['terms'] ?? ''),
            'closing' => (string) ($result['closing'] ?? ''),
            'statusLabel' => $canSend ? __('Klaar voor verzending', 'sbdp') : ($canCompleteControl ? __('Controle afronden', 'sbdp') : __('Controle nodig', 'sbdp')),
            'readinessTitle' => $sendReady ? __('Voorstel klaar voor verzending', 'sbdp') : __('Nog nodig', 'sbdp'),
            'readinessDescription' => $sendReady
                ? ($canSend ? __('Je kunt het voorstel versturen.', 'sbdp') : __('Alle verplichte controles zijn groen. Rond de controle af.', 'sbdp'))
                : ($firstBlocker !== '' ? $firstBlocker : __('Controleer open punten voordat je verzendt.', 'sbdp')),
            'sendReady' => $sendReady,
            'canCompleteControl' => $canCompleteControl,
            'canSend' => $canSend,
            'eventType' => 'quote_proposal_text_updated',
            'eventMessage' => __('Voorsteltekst bijgewerkt vanuit Quote Control Dashboard.', 'sbdp'),
            'sanitizerTerms' => (array) ($result['sanitizer_terms'] ?? array()),
            'sanitizerMessage' => ((array) ($result['sanitizer_terms'] ?? array())) !== array()
                ? __('Interne systeemtekst gevonden in klantvoorstel.', 'sbdp')
                : '',
        ));
    }

    public static function handleSuggestProposalText(): void {
        self::assertAccess();
        if (function_exists('check_ajax_referer')) {
            \check_ajax_referer('sbdp_quote_suggest_proposal_text');
        }

        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteCommunicationService($repository, $events);
        $payload = array(
            'subject' => sanitize_text_field((string) \wp_unslash($_POST['subject'] ?? '')),
            'intro' => sanitize_textarea_field((string) \wp_unslash($_POST['intro'] ?? '')),
            'program_text' => sanitize_textarea_field((string) \wp_unslash($_POST['program_text'] ?? '')),
            'price_rule' => sanitize_textarea_field((string) \wp_unslash($_POST['price_rule'] ?? '')),
            'terms' => sanitize_textarea_field((string) \wp_unslash($_POST['terms'] ?? '')),
            'closing' => sanitize_textarea_field((string) \wp_unslash($_POST['closing'] ?? '')),
        );
        $mode = sanitize_key((string) ($_POST['mode'] ?? 'improve'));

        try {
            $draft = $service->suggestProposalText($quoteId, $payload, $mode);
        } catch (\Throwable $exception) {
            \wp_send_json_error(array(
                'message' => $exception->getMessage(),
            ), 400);
        }

        \wp_send_json_success(array(
            'message' => $mode === 'generate'
                ? __('Klantmail opgesteld. Controleer de tekst en sla daarna expliciet op.', 'sbdp')
                : __('AI-voorstel geladen. Controleer de tekst en sla daarna expliciet op.', 'sbdp'),
            'subject' => (string) ($draft['subject'] ?? ''),
            'intro' => (string) ($draft['intro'] ?? ''),
            'body' => (string) ($draft['body'] ?? ''),
            'summary' => (string) ($draft['summary'] ?? ''),
            'priceRule' => (string) ($draft['price_rule'] ?? ''),
            'terms' => (string) ($draft['terms'] ?? ''),
            'closing' => (string) ($draft['closing'] ?? ''),
            'source' => (string) ($draft['source'] ?? 'template'),
            'sanitizerTerms' => (array) ($draft['sanitizer_terms'] ?? array()),
        ));
    }

    public static function handleGenerateResponseDraft(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_generate_response_draft');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $messageId = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;
        $workspaceTab = self::normalizeWorkspaceTab((string) ($_POST['workspace_tab'] ?? 'communication'), 'communication');
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteCommunicationService($repository, $events);
        try {
            $service->generateResponseDraft(
                $quoteId,
                $messageId > 0 ? $messageId : null,
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'workspace_tab' => $workspaceTab, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'workspace_tab' => $workspaceTab, 'quote_message_draft_generated' => '1'));
    }
    public static function handleSummarizeMessage(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_summarize_message');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $messageId = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;
        $workspaceTab = self::normalizeWorkspaceTab((string) ($_POST['workspace_tab'] ?? 'communication'), 'communication');
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteCommunicationService($repository, $events);
        try {
            $service->summarizeInboundMessage(
                $quoteId,
                $messageId,
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'workspace_tab' => $workspaceTab, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'workspace_tab' => $workspaceTab, 'quote_message_summarized' => '1'));
    }
    public static function handleSendMessage(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_send_message');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $workspaceTab = self::normalizeWorkspaceTab((string) ($_POST['workspace_tab'] ?? 'communication'), 'communication');
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteCommunicationService($repository, $events);
        try {
            $service->sendEmail($quoteId, array(
                'message_type'       => sanitize_text_field((string) ($_POST['message_type'] ?? 'proposal')),
                'draft_id'           => isset($_POST['draft_id']) ? (int) $_POST['draft_id'] : 0,
                'to_name'            => sanitize_text_field((string) ($_POST['to_name'] ?? '')),
                'to_email'           => sanitize_email((string) ($_POST['to_email'] ?? '')),
                'subject'            => sanitize_text_field((string) ($_POST['subject'] ?? '')),
                'body'               => sanitize_textarea_field((string) ($_POST['body'] ?? '')),
                'reply_to_message_id'=> sanitize_text_field((string) ($_POST['reply_to_message_id'] ?? '')),
            ), function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'workspace_tab' => $workspaceTab, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'workspace_tab' => $workspaceTab, 'quote_message_sent' => '1'));
    }
    public static function handleUpdateQuoteIntake(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_update_intake');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $quote      = $repository->findQuote($quoteId);
        $requestId  = is_array($quote) ? (int) ($quote['quote_request_id'] ?? 0) : 0;
        $request    = $requestId > 0 ? $repository->findQuoteRequest($requestId) : null;
        if (! is_array($quote) || ! is_array($request)) {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => 'dashboard',
                'quote_error' => rawurlencode(__('Gekoppelde quote request ontbreekt.', 'sbdp')),
            ));
        }
        // Block commercial intake mutations when the quote is in a content-frozen status.
        // group_size and preferred_date affect pricing and handoff — they are commercial context.
        try {
            (new QuoteImmutabilityGuard($repository))->assertQuoteCommercialContextEditable($quoteId);
        } catch (\InvalidArgumentException $guardException) {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => 'dashboard',
                'quote_error' => rawurlencode($guardException->getMessage()),
            ));
        }
        $existingGroupSize = (int) ($request['group_size'] ?? 0);
        $existingPreferredDate = is_string($request['preferred_date'] ?? null) ? trim((string) $request['preferred_date']) : '';
        $postedGroupSize = isset($_POST['group_size']) ? max(0, (int) $_POST['group_size']) : 0;
        $postedPreferredDate = trim(sanitize_text_field((string) ($_POST['preferred_date'] ?? '')));
        if ($postedPreferredDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $postedPreferredDate) !== 1) {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => 'dashboard',
                'quote_error' => rawurlencode(__('Voer een geldige voorkeursdatum in (YYYY-MM-DD).', 'sbdp')),
            ));
        }
        $groupSize = $postedGroupSize > 0 ? $postedGroupSize : $existingGroupSize;
        $preferredDate = $postedPreferredDate !== '' ? $postedPreferredDate : $existingPreferredDate;
        $repository->updateQuoteRequest($requestId, array(
            'group_size' => $groupSize,
            'preferred_date' => $preferredDate !== '' ? $preferredDate : null,
        ));
        self::resolveIntakeAssumptions(
            $repository,
            $quoteId,
            $groupSize,
            $preferredDate,
            function_exists('get_current_user_id') ? (int) get_current_user_id() : null
        );
        $events->log(
            'quote_request_intake_updated',
            $requestId,
            $quoteId,
            isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
            function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
            'Intakecontext bijgewerkt vanuit quote-workspace.',
            array(
                'group_size' => $groupSize,
                'preferred_date' => $preferredDate,
            )
        );
        self::redirect('sbdp_quotes', array(
            'quote_id' => $quoteId,
            'workspace_tab' => 'dashboard',
            'quote_intake_updated' => '1',
        ));
    }
    /**
     * Handles saving of full customer contact data (NAW) from the QCD customer modal.
     * Meta keys used:
     *   quote_requests.requester_name
     *   quote_requests.requester_email
     *   quote_requests.requester_phone
     *   quote_requests.requester_company
     *   quote_requests.request_summary
     *   quote_requests.group_size
     *   quote_requests.preferred_date
     *   quote_requests.normalized_payload.requester (JSON sub-object with address)
     * No WooCommerce order/customer/cart data is touched.
     */
    public static function handleUpdateCustomerContact(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_update_customer_contact');
        $quoteId    = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $quote      = $repository->findQuote($quoteId);
        $requestId  = is_array($quote) ? (int) ($quote['quote_request_id'] ?? 0) : 0;
        $request    = $requestId > 0 ? $repository->findQuoteRequest($requestId) : null;
        if (! is_array($quote) || ! is_array($request)) {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => 'dashboard',
                'quote_error' => rawurlencode(__('Gekoppelde quote request ontbreekt.', 'sbdp')),
            ));
            return;
        }
        try {
            (new \BSP\Quotes\Service\QuoteImmutabilityGuard($repository))->assertQuoteCommercialContextEditable($quoteId);
        } catch (\InvalidArgumentException $guardException) {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => 'dashboard',
                'quote_error' => rawurlencode($guardException->getMessage()),
            ));
            return;
        }
        // Sanitize contact fields
        $name    = sanitize_text_field((string) ($_POST['requester_name']    ?? ''));
        $company = sanitize_text_field((string) ($_POST['requester_company'] ?? ''));
        $phone   = sanitize_text_field((string) ($_POST['requester_phone']   ?? ''));
        $summary = sanitize_textarea_field((string) ($_POST['request_summary'] ?? ''));
        // Validate email
        $rawEmail = trim((string) ($_POST['requester_email'] ?? ''));
        $email    = $rawEmail !== '' ? sanitize_email($rawEmail) : '';
        if ($rawEmail !== '' && $email === '') {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => 'dashboard',
                'quote_error' => rawurlencode(__('Voer een geldig e-mailadres in.', 'sbdp')),
            ));
            return;
        }
        // Sanitize date
        $rawDate = trim(sanitize_text_field((string) ($_POST['preferred_date'] ?? '')));
        if ($rawDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) !== 1) {
            self::redirect('sbdp_quotes', array(
                'quote_id' => $quoteId,
                'workspace_tab' => 'dashboard',
                'quote_error' => rawurlencode(__('Voer een geldige voorkeursdatum in (YYYY-MM-DD).', 'sbdp')),
            ));
            return;
        }
        $preferredDate = $rawDate !== '' ? $rawDate : (string) ($request['preferred_date'] ?? '');
        $groupSize     = isset($_POST['group_size']) ? max(0, (int) $_POST['group_size']) : (int) ($request['group_size'] ?? 0);
        // Sanitize address
        $address1 = sanitize_text_field((string) ($_POST['requester_address_1'] ?? ''));
        $postcode = sanitize_text_field((string) ($_POST['requester_postcode']   ?? ''));
        $city     = sanitize_text_field((string) ($_POST['requester_city']       ?? ''));
        $country  = sanitize_text_field((string) ($_POST['requester_country']    ?? 'NL'));
        // Build updated normalized_payload.requester (preserve existing payload, only update requester)
        $existingPayload = isset($request['normalized_payload']) && is_array($request['normalized_payload'])
            ? $request['normalized_payload']
            : array();
        $updatedRequester = array(
            'name'    => $name,
            'email'   => $email,
            'phone'   => $phone,
            'company' => $company,
        );
        $updatedAddress = array_filter(array(
            'address_1' => $address1,
            'postcode'  => $postcode,
            'city'      => $city,
            'country'   => $country,
        ), static fn ($v): bool => $v !== '');
        if ($updatedAddress !== array()) {
            $updatedRequester['address'] = $updatedAddress;
        }
        $updatedRequester = array_filter($updatedRequester, static fn ($v): bool => is_array($v) ? $v !== array() : $v !== '');
        $existingPayload['requester'] = $updatedRequester;
        $changes = array(
            'requester_name'    => $name,
            'requester_email'   => $email,
            'requester_phone'   => $phone,
            'requester_company' => $company,
            'request_summary'   => $summary,
            'group_size'        => $groupSize,
            'preferred_date'    => $preferredDate !== '' ? $preferredDate : null,
            'normalized_payload'=> $existingPayload,
        );
        $repository->updateQuoteRequest($requestId, $changes);
        $events->log(
            'quote_customer_contact_updated',
            $requestId,
            $quoteId,
            isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
            function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
            'Klantgegevens bijgewerkt vanuit quote-workspace.',
            array(
                'fields' => array_filter(array(
                    'name'    => $name !== '' ? $name : null,
                    'email'   => $email !== '' ? $email : null,
                    'company' => $company !== '' ? $company : null,
                    'city'    => $city !== '' ? $city : null,
                )),
            )
        );
        self::redirect('sbdp_quotes', array(
            'quote_id' => $quoteId,
            'workspace_tab' => 'dashboard',
            'quote_contact_updated' => '1',
        ));
    }
    public static function handleSaveAiMailSettings(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_save_ai_mail_settings');
        $inboundSecret = sanitize_text_field((string) ($_POST['bsp_inbound_mail_secret'] ?? ''));
        $openAiModel = sanitize_text_field((string) ($_POST['bsp_openai_model'] ?? 'gpt-4o'));
        $openAiApiKey = trim((string) ($_POST['bsp_openai_api_key'] ?? ''));
        if ($openAiModel === '') {
            $openAiModel = 'gpt-4o';
        }
        \update_option('bsp_inbound_mail_secret', $inboundSecret, false);
        \update_option('bsp_openai_model', $openAiModel, false);
        \update_option('bsp_openai_api_key', $openAiApiKey, false);
        self::redirect('sbdp_quote_ai_mail', array('quote_ai_mail_saved' => '1'));
    }
    public static function handleLogInboundMessage(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_log_inbound_message');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteCommunicationService($repository, $events);
        try {
            $service->ingestInboundMessage(array(
                'message_id'  => sanitize_text_field((string) ($_POST['message_id'] ?? '')),
                'in_reply_to' => sanitize_text_field((string) ($_POST['in_reply_to'] ?? '')),
                'references'  => sanitize_text_field((string) ($_POST['references'] ?? '')),
                'subject'     => sanitize_text_field((string) ($_POST['subject'] ?? '')),
                'body'        => sanitize_textarea_field((string) ($_POST['body'] ?? '')),
                'from_name'   => sanitize_text_field((string) ($_POST['from_name'] ?? '')),
                'from_email'  => sanitize_email((string) ($_POST['from_email'] ?? '')),
                'to_email'    => sanitize_email((string) ($_POST['to_email'] ?? '')),
            ), function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_inbound_logged' => '1'));
    }
    public static function handleResolveInboundFailure(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_resolve_inbound_failure');
        $failureId = isset($_POST['failure_id']) ? (int) $_POST['failure_id'] : 0;
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteCommunicationService($repository, $events);
        try {
            $service->resolveInboundFailure(
                $failureId,
                $quoteId,
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quote_inbox', array('quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quote_inbox', array('quote_inbound_resolved' => '1'));
    }
    public static function handleCreateFollowup(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_followup_create');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteFollowupService($repository, $events);
        try {
            $service->create(array(
                'quote_id'         => $quoteId,
                'followup_type'    => sanitize_text_field((string) ($_POST['followup_type'] ?? 'manual_review')),
                'priority'         => sanitize_text_field((string) ($_POST['priority'] ?? 'normal')),
                'title'            => sanitize_text_field((string) ($_POST['title'] ?? '')),
                'note'             => sanitize_textarea_field((string) ($_POST['note'] ?? '')),
                'due_at'           => sanitize_text_field((string) ($_POST['due_at'] ?? '')),
                'assigned_user_id' => isset($_POST['assigned_user_id']) ? (int) $_POST['assigned_user_id'] : 0,
                'actor_id'         => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
            ));
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'followup_created' => '1'));
    }
    public static function handleCompleteFollowup(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_followup_complete');
        $followupId = isset($_POST['followup_id']) ? (int) $_POST['followup_id'] : 0;
        $quoteId    = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteFollowupService($repository, $events);
        try {
            $service->complete($followupId, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'followup_completed' => '1'));
    }
    public static function handleMarkReadyForResnapshot(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_mark_ready_for_resnapshot');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository  = new QuoteRepository();
        $events      = new QuoteEventLogger($repository);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $service     = new QuoteConversionService($repository, $assumptions, $events);
        try {
            $service->markReadyForResnapshot($quoteId, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'handoff_ready' => '1'));
    }
    public static function handlePrepareResnapshot(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_prepare_resnapshot');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository  = new QuoteRepository();
        $events      = new QuoteEventLogger($repository);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $lookup      = new QuoteExecutionLookupService();
        $service     = new QuoteHandoffPreparationService($repository, $assumptions, $events, $lookup);
        try {
            $service->prepareResnapshot($quoteId, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'resnapshot_prepared' => '1'));
    }
    public static function handleBuildHandoffPackage(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_build_handoff_package');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteHandoffAdapterService($repository, $events);
        try {
            $service->buildControlledPackage($quoteId, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'handoff_package_ready' => '1'));
    }
    public static function handleBuildExecutionPayload(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_build_execution_payload');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteExecutionAdapterService($repository, $events);
        try {
            $service->buildCartOrderPrep($quoteId, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'execution_payload_ready' => '1'));
    }
    public static function handleValidateExecutionPayload(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_validate_execution_payload');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $lookup     = new QuoteExecutionLookupService();
        $service    = new QuoteExecutionRunnerService($repository, $events, $lookup);
        try {
            $service->validateCartReady($quoteId, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'execution_validated' => '1'));
    }
    public static function handleBuildExecutionLaunch(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_build_execution_launch');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events     = new QuoteEventLogger($repository);
        $service    = new QuoteExecutionLaunchService($repository, $events);
        try {
            $service->buildWooCartSessionPrep($quoteId, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'execution_launch_ready' => '1'));
    }
    public static function handleHydrateWooCart(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_hydrate_woo_cart');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $quote = $repository->findQuote($quoteId);
        if ($quote === null) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode('Quote niet gevonden.')));
        }
        $versionId = (int) ($quote['approved_version_id'] ?? 0);
        if ($versionId <= 0) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode('Quote heeft geen geaccepteerde versie (approved_version_id ontbreekt).')));
        }
        $version = $repository->findQuoteVersion($versionId);
        $handoffPayload = is_array($version['handoff_payload_json'] ?? null) ? $version['handoff_payload_json'] : array();
        $launchPayload = isset($handoffPayload['execution_launch']) && is_array($handoffPayload['execution_launch'])
            ? $handoffPayload['execution_launch']
            : array();
        $launchToken = trim((string) ($launchPayload['launch_token'] ?? ''));
        $events  = new QuoteEventLogger($repository);
        $gateway = new WooCartLaunchGateway();
        $service = new QuoteWooCartHydrationService($gateway, $repository, $events);
        try {
            $result = $service->hydrateLaunchToCart($quoteId, $launchToken, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        $query = array(
            'quote_id' => $quoteId,
            'woo_cart_hydrated' => '1',
        );
        if (! empty($result['cart_url']) && is_string($result['cart_url'])) {
            $query['cart_url'] = rawurlencode($result['cart_url']);
        }
        self::redirect('sbdp_quotes', $query);
    }
    public static function handleCreateBookingBridge(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_create_booking_bridge');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events = new QuoteEventLogger($repository);
        try {
            $service = new QuoteBookingBridgeService($repository, $events, self::createQuoteBookingBridgeManager());
            $result = $service->createOperationsBooking($quoteId, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'workspace_tab' => 'handoff', 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array(
            'quote_id' => $quoteId,
            'workspace_tab' => 'handoff',
            'operations_ready' => '1',
            'booking_master_id' => (string) ((int) ($result['booking_master_id'] ?? 0)),
        ));
    }
    public static function handleConfirmReadyQuote(): void {
        self::assertAccess();
        check_admin_referer('sbdp_quote_confirm_ready');
        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $events = new QuoteEventLogger($repository);
        $service = new QuoteAdminConfirmationService(
            $repository,
            $events,
            new QuoteConfirmationReadinessService($repository, $events)
        );
        try {
            $service->confirmReadyQuote($quoteId, function_exists('get_current_user_id') ? (int) get_current_user_id() : null);
        } catch (\Throwable $exception) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode($exception->getMessage())));
        }
        self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_confirmed' => '1'));
    }
    public static function renderQuoteRequestsPage(): void {
        QuoteWorkspaceRenderer::renderQuoteRequestsPage();
    }
    public static function renderQuotesPage(): void {
        QuoteWorkspaceRenderer::renderQuotesPage();
    }
    public static function renderQuoteInboxPage(): void {
        QuoteWorkspaceRenderer::renderQuoteInboxPage();
    }
    public static function renderQuoteAiMailSettingsPage(): void {
        QuoteWorkspaceRenderer::renderQuoteAiMailSettingsPage();
    }
    private static function renderQuoteBuildRow($index, array $line, array $catalog): string { return QuoteBuilderRenderer::renderQuoteBuildRow($index, $line, $catalog); }
    private static function summarizeQuoteLines(array $lines, ?array $currentVersion): array { return QuoteWorkspaceRenderer::summarizeQuoteLines($lines, $currentVersion); }
    private static function inspectQuoteSendReadiness(int $quoteId, array $quote, ?array $currentVersion, QuoteRepositoryInterface $repository): array { return QuoteWorkspaceRenderer::inspectQuoteSendReadiness($quoteId, $quote, $currentVersion, $repository); }
    private static function buildQuoteOverviewRows(QuoteRepositoryInterface $repository, array $quotes): array { return QuoteWorkspaceRenderer::buildQuoteOverviewRows($repository, $quotes); }
    private static function buildQuoteCommunicationState(array $quote, ?array $currentVersion, array $messages, array $assumptions, array $sendReadiness, array $sendDecision = array()): array { return QuoteWorkspaceRenderer::buildQuoteCommunicationState($quote, $currentVersion, $messages, $assumptions, $sendReadiness, $sendDecision); }
    private static function buildCommercialIntakeNoticeState(array $lines, array $followups, array $assumptions): array { return QuoteWorkspaceRenderer::buildCommercialIntakeNoticeState($lines, $followups, $assumptions); }
    private static function normalizeWorkspaceTab(string $tab, string $default = 'dashboard'): string {
        $tab = sanitize_key($tab);
        $default = sanitize_key($default);
        $allowedTabs = array('dashboard', 'build', 'proposal', 'communication', 'handoff', 'history');
        if (! in_array($default, $allowedTabs, true)) {
            $default = 'dashboard';
        }
        return in_array($tab, $allowedTabs, true) ? $tab : $default;
    }
    private static function redirect(string $page, array $query = array()): void {
        $url = add_query_arg(array_merge(array('page' => $page), $query), admin_url('admin.php'));
        wp_redirect($url);
        exit;
    }
    private static function createQuoteBookingBridgeManager(): BookingManager {
        if (! class_exists('\BSP\Planner\Vendor\CityGuideProfileStore')) {
            throw new \InvalidArgumentException('Booking bridge vereist de planner guide profile store.');
        }
        if (! class_exists('\BSP\Planner\Module') && class_exists('\SBDP\Modules\Planner\Module')) {
            class_alias('SBDP\Modules\Planner\Module', 'BSP\Planner\Module');
        }
        if (! class_exists('\BSP\Planner\Module')) {
            throw new \InvalidArgumentException('Booking bridge vereist de planner module.');
        }
        return BookingManager::createDefault(new BookingStorageRepository());
    }
    private static function assertAccess(): void {
        if (! self::canManageQuotes()) {
            wp_die(esc_html__('U heeft geen toegang tot Quote OS.', 'sbdp'), 403);
        }
    }
    public static function canManageQuotes(): bool {
        return current_user_can(self::CAPABILITY) || current_user_can(self::FALLBACK_CAPABILITY);
    }
    private static function capability(): string {
        return current_user_can(self::CAPABILITY) ? self::CAPABILITY : self::FALLBACK_CAPABILITY;
    }
}
