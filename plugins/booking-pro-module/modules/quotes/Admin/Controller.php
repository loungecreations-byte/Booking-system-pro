<?php

declare(strict_types=1);

namespace BSP\Quotes\Admin;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteAssumptionService;
use BSP\Quotes\Service\QuoteCommunicationService;
use BSP\Quotes\Service\QuoteConversionService;
use BSP\Quotes\Service\QuoteExecutionLookupService;
use BSP\Quotes\Service\QuoteExecutionLaunchService;
use BSP\Quotes\Service\QuoteExecutionRunnerService;
use BSP\Quotes\Service\DashboardBlockerService;
use BSP\Quotes\Service\QuoteBusinessRuleValidator;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteExecutionAdapterService;
use BSP\Quotes\Service\QuoteImmutabilityGuard;
use BSP\Quotes\Service\QuoteWooCartHydrationService;
use BSP\Quotes\Service\QuoteFollowupService;
use BSP\Quotes\Service\QuoteHandoffAdapterService;
use BSP\Quotes\Service\QuoteHandoffPreparationService;
use BSP\Quotes\Service\QuoteOperationsDraftService;
use BSP\Quotes\Service\QuoteRequestService;
use BSP\Quotes\Service\QuoteReviewService;
use BSP\Quotes\Service\QuoteSendService;
use BSP\Quotes\Service\QuoteSendReadinessValidator;
use BSP\Quotes\Service\WooCartLaunchGateway;

use function add_query_arg;
use function add_menu_page;
use function add_submenu_page;
use function admin_url;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
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

    public static function registerMenu(): void
    {
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

    public static function handleCreateQuoteRequest(): void
    {
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

    public static function handleConvertQuoteRequest(): void
    {
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

    public static function handleRequestReview(): void
    {
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

    public static function handleApproveReview(): void
    {
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

    public static function handleReturnToDraft(): void
    {
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

    public static function handleResolveAssumption(): void
    {
        self::assertAccess();
        check_admin_referer('sbdp_quote_resolve_assumption');

        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $assumptionId = isset($_POST['assumption_id']) ? (int) $_POST['assumption_id'] : 0;
        $resolutionNote = trim(sanitize_textarea_field((string) ($_POST['resolution_note'] ?? '')));
        $workspaceTab = sanitize_key((string) ($_POST['workspace_tab'] ?? 'workflow'));
        if (! in_array($workspaceTab, array('dashboard', 'build', 'proposal', 'communication', 'workflow', 'history'), true)) {
            $workspaceTab = 'workflow';
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

    public static function handleQuickPrepareToSend(): void
    {
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

    public static function handleMarkSentManual(): void
    {
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

    public static function handleReopenSend(): void
    {
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

    public static function handleSaveOperationsDraft(): void
    {
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

    public static function handleGenerateProposalDraft(): void
    {
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

    public static function handleGenerateResponseDraft(): void
    {
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

    public static function handleSummarizeMessage(): void
    {
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

    public static function handleSendMessage(): void
    {
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

    public static function handleUpdateQuoteIntake(): void
    {
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
                'workspace_tab' => 'workflow',
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
                'workspace_tab' => 'workflow',
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
                'workspace_tab' => 'workflow',
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
            'workspace_tab' => 'workflow',
            'quote_intake_updated' => '1',
        ));
    }

    public static function handleSaveAiMailSettings(): void
    {
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

    public static function handleLogInboundMessage(): void
    {
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

    public static function handleResolveInboundFailure(): void
    {
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

    public static function handleCreateFollowup(): void
    {
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

    public static function handleCompleteFollowup(): void
    {
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

    public static function handleMarkReadyForResnapshot(): void
    {
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

    public static function handlePrepareResnapshot(): void
    {
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

    public static function handleBuildHandoffPackage(): void
    {
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

    public static function handleBuildExecutionPayload(): void
    {
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

    public static function handleValidateExecutionPayload(): void
    {
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

    public static function handleBuildExecutionLaunch(): void
    {
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

    public static function handleHydrateWooCart(): void
    {
        self::assertAccess();
        check_admin_referer('sbdp_quote_hydrate_woo_cart');

        $quoteId = isset($_POST['quote_id']) ? (int) $_POST['quote_id'] : 0;
        $repository = new QuoteRepository();
        $quote = $repository->findQuote($quoteId);
        if ($quote === null) {
            self::redirect('sbdp_quotes', array('quote_id' => $quoteId, 'quote_error' => rawurlencode('Quote niet gevonden.')));
        }

        $versionId = (int) ($quote['current_version_id'] ?? 0);
        $version = $versionId > 0 ? $repository->findQuoteVersion($versionId) : null;
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

    public static function renderQuoteRequestsPage(): void
    {
        self::assertAccess();

        $repository = new QuoteRepository();
        $requests   = $repository->listQuoteRequests();
        $quotes     = $repository->listQuotes();

        $showTestData = isset($_GET['show_testdata']) && (string) $_GET['show_testdata'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $activeView = isset($_GET['inbox_view']) ? sanitize_key((string) $_GET['inbox_view']) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (! in_array($activeView, array('all', 'new', 'action', 'ready', 'waiting', 'booked', 'problem'), true)) {
            $activeView = 'all';
        }

        $allRows = self::buildQuoteRequestInboxRows($repository, $requests, $quotes);
        $hiddenTestCount = count(array_values(array_filter($allRows, static fn (array $row): bool => ! empty($row['is_test_data']))));
        $rows = $showTestData
            ? $allRows
            : array_values(array_filter($allRows, static fn (array $row): bool => empty($row['is_test_data'])));
        $metricCounts = self::buildQuoteRequestInboxMetrics($rows);
        $filteredRows = self::filterQuoteRequestInboxRows($rows, $activeView);
        $actionNeededRows = array_values(array_filter($filteredRows, static fn (array $row): bool => ! empty($row['is_action_needed'])));

        echo '<div class="wrap"><h1>' . esc_html__('Quote Requests Inbox', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Operator-overzicht voor intake, opvolging en volgende stap per aanvraag.', 'sbdp') . '</p>';
        self::renderAdminStyles();
        self::renderNotices();

        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__actions" style="justify-content:space-between;">';
        echo '<details class="bsp-quote-admin__request-create">';
        echo '<summary class="button button-primary">' . esc_html__('+ Nieuwe aanvraag', 'sbdp') . '</summary>';
        self::renderCreateRequestForm(false);
        echo '</details>';

        $toggleQuery = array('page' => 'sbdp_quote_requests', 'show_testdata' => $showTestData ? '0' : '1', 'inbox_view' => $activeView);
        $toggleLabel = $showTestData ? __('Verberg testdata', 'sbdp') : __('Toon testdata', 'sbdp');
        echo '<div class="bsp-quote-admin__actions bsp-quote-admin__actions--stacked">';
        echo '<a class="button" href="' . esc_url(add_query_arg($toggleQuery, admin_url('admin.php'))) . '">' . esc_html($toggleLabel) . '</a>';
        if (! $showTestData && $hiddenTestCount > 0) {
            echo '<span class="bsp-quote-admin__muted">' . esc_html(sprintf(__(' %d testregel(s) verborgen', 'sbdp'), $hiddenTestCount)) . '</span>';
        }
        echo '</div>';
        echo '</div></div></section>';

        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__overview-stat-grid">';
        echo self::renderRequestInboxMetricCard(__('Nieuwe aanvragen', 'sbdp'), $metricCounts['new'], 'new', $activeView, $showTestData);
        echo self::renderRequestInboxMetricCard(__('Actie nodig', 'sbdp'), $metricCounts['action'], 'action', $activeView, $showTestData);
        echo self::renderRequestInboxMetricCard(__('Klaar voor offerte', 'sbdp'), $metricCounts['ready'], 'ready', $activeView, $showTestData);
        echo self::renderRequestInboxMetricCard(__('Wacht op klant', 'sbdp'), $metricCounts['waiting'], 'waiting', $activeView, $showTestData);
        echo self::renderRequestInboxMetricCard(__('Geboekt', 'sbdp'), $metricCounts['booked'], 'booked', $activeView, $showTestData);
        echo self::renderRequestInboxMetricCard(__('Probleem', 'sbdp'), $metricCounts['problem'], 'problem', $activeView, $showTestData);
        echo self::renderRequestInboxMetricCard(__('Alles', 'sbdp'), count($rows), 'all', $activeView, $showTestData);
        echo '</div></div></section>';

        self::renderQuoteRequestFocusPanel($actionNeededRows);

        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html__('Actie nodig', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Eerst deze aanvragen/offertes afhandelen.', 'sbdp') . '</p></div><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__('Aanvraag', 'sbdp') . '</th><th>' . esc_html__('Klant', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('Volgende actie', 'sbdp') . '</th><th>' . esc_html__('Actie', 'sbdp') . '</th></tr></thead><tbody>';

        if ($actionNeededRows === array()) {
            echo '<tr><td colspan="5">' . esc_html__('Geen urgente acties in de huidige filter.', 'sbdp') . '</td></tr>';
        } else {
            foreach (array_slice($actionNeededRows, 0, 10) as $row) {
                self::renderQuoteRequestPriorityRow($row);
            }
        }

        echo '</tbody></table></div></div></section>';

        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html__('Alle aanvragen', 'sbdp') . '</h3></div><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__('Aanvraag', 'sbdp') . '</th><th>' . esc_html__('Klant', 'sbdp') . '</th><th>' . esc_html__('Datum & groep', 'sbdp') . '</th><th>' . esc_html__('Waarde', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('Volgende actie', 'sbdp') . '</th><th>' . esc_html__('Laatste update', 'sbdp') . '</th><th>' . esc_html__('Actie', 'sbdp') . '</th></tr></thead><tbody>';

        if ($filteredRows === array()) {
            echo '<tr><td colspan="8">' . esc_html__('Geen aanvragen in deze filter.', 'sbdp') . '</td></tr>';
        } else {
            foreach ($filteredRows as $row) {
                self::renderQuoteRequestInboxRow($row);
            }
        }

        echo '</tbody></table></div></div></section>';
        echo '</div>';
    }

    /**
     * @param array<int, array<string, mixed>> $requests
     * @param array<int, array<string, mixed>> $quotes
     * @return array<int, array<string, mixed>>
     */
    private static function buildQuoteRequestInboxRows(QuoteRepositoryInterface $repository, array $requests, array $quotes): array
    {
        $quotesByRequestId = array();
        foreach ($quotes as $quote) {
            if (! is_array($quote)) {
                continue;
            }

            $requestId = (int) ($quote['quote_request_id'] ?? 0);
            if ($requestId <= 0) {
                continue;
            }

            if (! isset($quotesByRequestId[$requestId])) {
                $quotesByRequestId[$requestId] = $quote;
                continue;
            }

            $existingUpdatedAt = (string) ($quotesByRequestId[$requestId]['updated_at'] ?? '');
            $candidateUpdatedAt = (string) ($quote['updated_at'] ?? '');
            if ($candidateUpdatedAt > $existingUpdatedAt) {
                $quotesByRequestId[$requestId] = $quote;
            }
        }

        $rows = array();
        foreach ($requests as $request) {
            if (! is_array($request)) {
                continue;
            }

            $requestId = (int) ($request['id'] ?? 0);
            if ($requestId <= 0) {
                continue;
            }

            $quote = isset($quotesByRequestId[$requestId]) && is_array($quotesByRequestId[$requestId])
                ? $quotesByRequestId[$requestId]
                : null;
            $requester = self::extractRequesterContext($request);

            $currentVersion = null;
            $lines = array();
            $sendReadiness = array('ready' => false, 'blockers' => array());
            $openSendAssumptions = 0;
            $hasOpenSendBlockers = false;
            $handoffFailed = false;

            $pricingConfidence = (string) ($request['pricing_confidence'] ?? 'unknown');
            $availabilityConfidence = (string) ($request['availability_confidence'] ?? 'unknown');

            if (is_array($quote)) {
                $currentVersion = self::resolveCurrentQuoteVersion($repository, $quote);
                if (is_array($currentVersion)) {
                    $pricingConfidence = (string) ($currentVersion['pricing_confidence'] ?? $pricingConfidence);
                    $availabilityConfidence = (string) ($currentVersion['availability_confidence'] ?? $availabilityConfidence);
                    $lines = $repository->listQuoteLines((int) ($currentVersion['id'] ?? 0));
                }

                $sendReadiness = self::inspectQuoteSendReadiness((int) ($quote['id'] ?? 0), $quote, $currentVersion, $repository);
                $assumptions = $repository->listQuoteAssumptions((int) ($quote['id'] ?? 0));
                foreach ($assumptions as $assumption) {
                    if (! is_array($assumption)) {
                        continue;
                    }
                    if ((string) ($assumption['status'] ?? 'open') === 'open' && ! empty($assumption['blocks_send'])) {
                        $openSendAssumptions++;
                    }
                }

                $sendReadinessCodes = array_map(
                    static fn (array $blocker): string => (string) ($blocker['code'] ?? ''),
                    array_values(array_filter((array) ($sendReadiness['blockers'] ?? array()), static fn ($blocker): bool => is_array($blocker)))
                );
                $hasOpenSendBlockers = $openSendAssumptions > 0 || in_array('send_assumption_open', $sendReadinessCodes, true);

                $handoffStatus = (string) ($quote['handoff_status'] ?? 'not_ready');
                $handoffFailed = in_array($handoffStatus, array('resnapshot_blocked', 'failed', 'error'), true)
                    || str_contains($handoffStatus, 'blocked')
                    || str_contains($handoffStatus, 'failed');
            }

            $lineCount = count($lines);
            $hasQuoteLines = $lineCount > 0;
            $isPricingUnknown = $pricingConfidence === 'unknown';
            $isAvailabilityUnknown = ! in_array($availabilityConfidence, array('confirmed'), true);
            $requestIsNew = (string) ($request['status'] ?? 'new') === 'new' && ! is_array($quote);

            $humanStatus = self::mapQuoteRequestHumanStatus($request, $quote, $hasQuoteLines, $pricingConfidence, $availabilityConfidence, $hasOpenSendBlockers, $handoffFailed);
            $nextAction = self::mapQuoteRequestNextAction($humanStatus, $requestIsNew, $hasQuoteLines, $pricingConfidence, $availabilityConfidence, $quote, $handoffFailed);

            $rows[] = array(
                'request' => $request,
                'quote' => $quote,
                'requester' => $requester,
                'human_status' => $humanStatus,
                'next_action' => $nextAction,
                'pricing_confidence' => $pricingConfidence,
                'availability_confidence' => $availabilityConfidence,
                'line_count' => $lineCount,
                'has_quote_lines' => $hasQuoteLines,
                'open_send_assumptions' => $openSendAssumptions,
                'has_open_send_blockers' => $hasOpenSendBlockers,
                'handoff_failed' => $handoffFailed,
                'request_is_new' => $requestIsNew,
                'is_action_needed' => $requestIsNew
                    || ! $hasQuoteLines
                    || $isPricingUnknown
                    || $isAvailabilityUnknown
                    || $hasOpenSendBlockers
                    || $handoffFailed,
                'is_test_data' => self::isLikelyTestData($request, $requester),
                'value_display' => self::resolveQuoteRequestValueDisplay($lines),
                'last_update' => self::resolveQuoteRequestLastUpdate($request, $quote),
                'links' => self::buildQuoteRequestLinks($request, $quote),
            );
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string) ($right['last_update'] ?? ''), (string) ($left['last_update'] ?? ''));
        });

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{new:int,action:int,ready:int,waiting:int,booked:int,problem:int}
     */
    private static function buildQuoteRequestInboxMetrics(array $rows): array
    {
        $counts = array('new' => 0, 'action' => 0, 'ready' => 0, 'waiting' => 0, 'booked' => 0, 'problem' => 0);
        foreach ($rows as $row) {
            $status = (string) ($row['human_status'] ?? 'Aanvullen nodig');
            if ($status === 'Nieuwe aanvraag') {
                $counts['new']++;
            }
            if (! empty($row['is_action_needed'])) {
                $counts['action']++;
            }
            if ($status === 'Klaar om te versturen') {
                $counts['ready']++;
            }
            if ($status === 'Wacht op klant') {
                $counts['waiting']++;
            }
            if ($status === 'Geboekt') {
                $counts['booked']++;
            }
            if ($status === 'Probleem') {
                $counts['problem']++;
            }
        }

        return $counts;
    }

    private static function renderRequestInboxMetricCard(string $label, int $count, string $view, string $activeView, bool $showTestData): string
    {
        $url = add_query_arg(
            array(
                'page' => 'sbdp_quote_requests',
                'inbox_view' => $view,
                'show_testdata' => $showTestData ? '1' : '0',
            ),
            admin_url('admin.php')
        );
        $class = 'bsp-quote-admin__overview-stat' . ($activeView === $view ? ' is-active' : '');

        return '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) $count) . '</strong></a>';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function filterQuoteRequestInboxRows(array $rows, string $activeView): array
    {
        if ($activeView === 'all') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($activeView): bool {
            $status = (string) ($row['human_status'] ?? '');
            return match ($activeView) {
                'new' => $status === 'Nieuwe aanvraag',
                'action' => ! empty($row['is_action_needed']),
                'ready' => $status === 'Klaar om te versturen',
                'waiting' => $status === 'Wacht op klant',
                'booked' => $status === 'Geboekt',
                'problem' => $status === 'Probleem',
                default => true,
            };
        }));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function renderQuoteRequestPriorityRow(array $row): void
    {
        $request = is_array($row['request'] ?? null) ? $row['request'] : array();
        $requester = is_array($row['requester'] ?? null) ? $row['requester'] : array();
        $reason = self::quoteRequestActionReason($row);

        echo '<tr>';
        echo '<td><strong>' . esc_html((string) ($request['request_reference'] ?? '')) . '</strong><br><span class="bsp-quote-admin__muted">' . esc_html((string) ($request['request_summary'] ?? '')) . '</span></td>';
        echo '<td><strong>' . esc_html((string) (($requester['name'] ?? '') ?: __('Onbekend', 'sbdp'))) . '</strong><br><span class="bsp-quote-admin__muted">' . esc_html((string) ($requester['email'] ?? '')) . '</span></td>';
        echo '<td>' . self::renderQuoteRequestHumanStatusBadge((string) ($row['human_status'] ?? 'Aanvullen nodig')) . '</td>';
        echo '<td><strong>' . esc_html((string) ($row['next_action'] ?? __('Geen actie nodig', 'sbdp'))) . '</strong>';
        if ($reason !== '') {
            echo '<br><span class="bsp-quote-admin__muted">' . esc_html($reason) . '</span>';
        }
        echo '</td>';
        echo '<td>' . self::renderQuoteRequestActionCell($row) . '</td>';
        echo '</tr>';
    }

    /**
     * @param array<int, array<string, mixed>> $actionNeededRows
     */
    private static function renderQuoteRequestFocusPanel(array $actionNeededRows): void
    {
        if ($actionNeededRows === array()) {
            return;
        }

        $first = $actionNeededRows[0];
        $request = is_array($first['request'] ?? null) ? $first['request'] : array();
        $requester = is_array($first['requester'] ?? null) ? $first['requester'] : array();
        $reason = self::quoteRequestActionReason($first);

        echo '<section class="postbox bsp-quote-admin__panel bsp-quote-admin__overview-next"><div class="bsp-quote-admin__panel-body">';
        echo '<span class="bsp-quote-admin__field-label">' . esc_html__('Eerst doen', 'sbdp') . '</span>';
        echo '<strong>' . esc_html((string) ($first['next_action'] ?? __('Beoordeel aanvraag', 'sbdp'))) . '</strong>';
        echo '<p>' . esc_html((string) ($request['request_reference'] ?? '')) . ' · ' . esc_html((string) (($requester['name'] ?? '') ?: __('Onbekende klant', 'sbdp'))) . '</p>';
        if ($reason !== '') {
            echo '<p class="bsp-quote-admin__muted">' . esc_html($reason) . '</p>';
        }
        echo self::renderQuoteRequestActionCell($first);
        echo '</div></section>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function quoteRequestActionReason(array $row): string
    {
        if (! empty($row['request_is_new'])) {
            return 'Nieuwe aanvraag zonder gekoppelde offerte.';
        }
        if (empty($row['has_quote_lines'])) {
            return 'Nog geen programmaregels of prijsregels aanwezig.';
        }
        if ((string) ($row['pricing_confidence'] ?? 'unknown') === 'unknown') {
            return 'Prijs is nog onbekend en moet commercieel bevestigd worden.';
        }
        if (! in_array((string) ($row['availability_confidence'] ?? 'unknown'), array('confirmed'), true)) {
            return 'Beschikbaarheid is nog niet bevestigd.';
        }
        if (! empty($row['has_open_send_blockers'])) {
            return 'Er staan nog open send-blockers op de offerte.';
        }
        if (! empty($row['handoff_failed'])) {
            return 'Handoff/executie status geeft een blokkade.';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function renderQuoteRequestInboxRow(array $row): void
    {
        $request = is_array($row['request'] ?? null) ? $row['request'] : array();
        $requester = is_array($row['requester'] ?? null) ? $row['requester'] : array();
        $preferredDate = (string) ($request['preferred_date'] ?? '');
        $groupSize = (int) ($request['group_size'] ?? 0);

        echo '<tr>';
        echo '<td><strong>' . esc_html((string) ($request['request_reference'] ?? '')) . '</strong>';
        echo '<br><span class="bsp-quote-admin__muted">' . esc_html((string) ($request['request_summary'] ?? '')) . '</span>';
        if (! empty($row['is_test_data'])) {
            echo '<br>' . self::renderInlineBadge(__('Testdata', 'sbdp'), 'is-neutral');
        }
        echo '</td>';

        $contact = array_filter(array(
            (string) ($requester['email'] ?? ''),
            (string) ($requester['phone'] ?? ''),
            (string) ($requester['company'] ?? ''),
        ));
        echo '<td><strong>' . esc_html((string) (($requester['name'] ?? '') ?: __('Onbekend', 'sbdp'))) . '</strong>';
        if ($contact !== array()) {
            echo '<br><span class="bsp-quote-admin__muted">' . esc_html(implode(' | ', $contact)) . '</span>';
        }
        echo '</td>';

        echo '<td><strong>' . esc_html($preferredDate !== '' ? $preferredDate : __('Geen datum', 'sbdp')) . '</strong>';
        echo '<br><span class="bsp-quote-admin__muted">' . esc_html($groupSize > 0 ? sprintf(__('%d personen', 'sbdp'), $groupSize) : __('Groep open', 'sbdp')) . '</span></td>';

        echo '<td><strong>' . esc_html((string) ($row['value_display'] ?? __('Nog niet bepaald', 'sbdp'))) . '</strong>';
        echo '<br>' . self::renderQuoteRequestConfidenceBadges((string) ($row['pricing_confidence'] ?? 'unknown'), (string) ($row['availability_confidence'] ?? 'unknown'));
        echo '</td>';

        echo '<td>' . self::renderQuoteRequestHumanStatusBadge((string) ($row['human_status'] ?? 'Aanvullen nodig')) . '</td>';
        echo '<td><strong>' . esc_html((string) ($row['next_action'] ?? __('Geen actie nodig', 'sbdp'))) . '</strong></td>';
        echo '<td>' . esc_html((string) ($row['last_update'] ?? '')) . '</td>';
        echo '<td>' . self::renderQuoteRequestActionCell($row) . '</td>';
        echo '</tr>';
    }

    private static function mapQuoteRequestHumanStatus(
        array $request,
        ?array $quote,
        bool $hasQuoteLines,
        string $pricingConfidence,
        string $availabilityConfidence,
        bool $hasOpenSendBlockers,
        bool $handoffFailed
    ): string {
        if ($handoffFailed) {
            return 'Probleem';
        }

        $wooOrderId = (int) ($quote['woo_order_id'] ?? 0);
        if ($wooOrderId > 0) {
            return 'Geboekt';
        }

        $quoteStatus = (string) ($quote['status'] ?? '');
        if (in_array($quoteStatus, array('accepted', 'confirmed'), true) && $wooOrderId <= 0) {
            return 'Akkoord / betaling';
        }

        $sendStatus = (string) ($quote['send_status'] ?? '');
        if (in_array($quoteStatus, array('sent'), true) || in_array($sendStatus, array('sent', 'sent_manual'), true)) {
            return 'Wacht op klant';
        }

        $reviewStatus = (string) ($quote['review_status'] ?? 'not_started');
        if ($reviewStatus === 'approved' && $sendStatus === 'ready_to_send' && ! $hasOpenSendBlockers) {
            return 'Klaar om te versturen';
        }

        if (is_array($quote) && (! $hasQuoteLines || $pricingConfidence === 'unknown' || $availabilityConfidence === 'unknown' || $hasOpenSendBlockers)) {
            return 'Aanvullen nodig';
        }

        if ((string) ($request['status'] ?? 'new') === 'new' && ! is_array($quote)) {
            return 'Nieuwe aanvraag';
        }

        return 'Aanvullen nodig';
    }

    private static function mapQuoteRequestNextAction(
        string $humanStatus,
        bool $requestIsNew,
        bool $hasQuoteLines,
        string $pricingConfidence,
        string $availabilityConfidence,
        ?array $quote,
        bool $handoffFailed
    ): string {
        if ($requestIsNew) {
            return 'Beoordeel aanvraag';
        }
        if (! $hasQuoteLines) {
            return 'Programma + prijs toevoegen';
        }
        if (in_array($pricingConfidence, array('unknown', 'snapshot', 'projected', 'directional'), true)) {
            return 'Prijs bevestigen';
        }
        if (! in_array($availabilityConfidence, array('confirmed'), true)) {
            return 'Beschikbaarheid bevestigen';
        }
        if ($humanStatus === 'Klaar om te versturen') {
            return 'Offerte versturen';
        }
        if ($humanStatus === 'Wacht op klant') {
            return 'Klant opvolgen';
        }
        if ($humanStatus === 'Akkoord / betaling') {
            return 'Betaling controleren';
        }
        if ($humanStatus === 'Geboekt' || in_array((string) ($quote['status'] ?? ''), array('confirmed'), true)) {
            return 'Uitvoering voorbereiden';
        }
        if ($handoffFailed || $humanStatus === 'Probleem') {
            return 'Uitvoering voorbereiden';
        }

        return 'Geen actie nodig';
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private static function resolveQuoteRequestValueDisplay(array $lines): string
    {
        $total = 0.0;
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $total += (float) ($line['line_total_snapshot'] ?? 0.0);
        }

        if ($total <= 0.0) {
            return (string) __('Nog niet bepaald', 'sbdp');
        }

        return self::formatMoney($total, 'EUR');
    }

    private static function resolveQuoteRequestLastUpdate(array $request, ?array $quote): string
    {
        $requestUpdated = (string) ($request['updated_at'] ?? $request['created_at'] ?? '');
        $quoteUpdated = is_array($quote) ? (string) ($quote['updated_at'] ?? $quote['created_at'] ?? '') : '';

        if ($quoteUpdated === '' || $requestUpdated >= $quoteUpdated) {
            return $requestUpdated;
        }

        return $quoteUpdated;
    }

    /**
     * @return array<string, string>
     */
    private static function buildQuoteRequestLinks(array $request, ?array $quote): array
    {
        $requestId = (int) ($request['id'] ?? 0);
        $links = array(
            'request' => add_query_arg(array('page' => 'sbdp_quote_requests', 'request_id' => $requestId), admin_url('admin.php')),
            'quote' => '',
            'build' => '',
            'communication' => '',
            'order' => '',
        );

        if (! is_array($quote)) {
            return $links;
        }

        $quoteId = (int) ($quote['id'] ?? 0);
        if ($quoteId <= 0) {
            return $links;
        }

        $links['quote'] = add_query_arg(array('page' => 'sbdp_quotes', 'quote_id' => $quoteId, 'workspace_tab' => 'dashboard'), admin_url('admin.php'));
        $links['build'] = add_query_arg(array('page' => 'sbdp_quotes', 'quote_id' => $quoteId, 'workspace_tab' => 'build'), admin_url('admin.php'));
        $links['communication'] = add_query_arg(array('page' => 'sbdp_quotes', 'quote_id' => $quoteId, 'workspace_tab' => 'communication'), admin_url('admin.php'));

        if (! empty($quote['woo_order_id'])) {
            $links['order'] = add_query_arg(array('post' => (int) $quote['woo_order_id'], 'action' => 'edit'), admin_url('post.php'));
        }

        return $links;
    }

    private static function renderQuoteRequestHumanStatusBadge(string $humanStatus): string
    {
        $class = match ($humanStatus) {
            'Geboekt', 'Klaar om te versturen' => 'is-good',
            'Probleem', 'Aanvullen nodig' => 'is-warn',
            default => 'is-neutral',
        };

        return self::renderInlineBadge($humanStatus, $class);
    }

    private static function renderQuoteRequestConfidenceBadges(string $pricingConfidence, string $availabilityConfidence): string
    {
        $pricingLabel = match ($pricingConfidence) {
            'execution_verified', 'confirmed' => __('Prijs: bevestigd', 'sbdp'),
            'snapshot', 'projected', 'directional' => __('Prijs: richtprijs', 'sbdp'),
            default => __('Prijs: onbekend', 'sbdp'),
        };
        $availabilityLabel = match ($availabilityConfidence) {
            'confirmed' => __('Beschikbaarheid: bevestigd', 'sbdp'),
            default => __('Beschikbaarheid: onbekend', 'sbdp'),
        };

        $pricingClass = in_array($pricingConfidence, array('execution_verified', 'confirmed'), true) ? 'is-good' : ($pricingConfidence === 'unknown' ? 'is-neutral' : 'is-warn');
        $availabilityClass = $availabilityConfidence === 'confirmed' ? 'is-good' : 'is-neutral';

        return self::renderInlineBadge($pricingLabel, $pricingClass)
            . ' '
            . self::renderInlineBadge($availabilityLabel, $availabilityClass);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function renderQuoteRequestActionCell(array $row): string
    {
        $links = is_array($row['links'] ?? null) ? $row['links'] : array();
        $nextAction = (string) ($row['next_action'] ?? 'Geen actie nodig');
        $request = is_array($row['request'] ?? null) ? $row['request'] : array();
        $quote = is_array($row['quote'] ?? null) ? $row['quote'] : null;
        $requestId = (int) ($request['id'] ?? 0);

        $primary = '';
        if ($nextAction === 'Beoordeel aanvraag') {
            $primary = '<a class="button button-secondary" href="' . esc_url((string) ($links['request'] ?? '')) . '">' . esc_html__('Open aanvraag', 'sbdp') . '</a>';
        } elseif (in_array($nextAction, array('Programma + prijs toevoegen', 'Prijs bevestigen', 'Beschikbaarheid bevestigen'), true)) {
            $primary = '<a class="button button-primary" href="' . esc_url((string) ($links['build'] ?? '')) . '">' . esc_html__('Offerte aanvullen', 'sbdp') . '</a>';
        } elseif ($nextAction === 'Offerte versturen') {
            $primary = '<a class="button button-primary" href="' . esc_url((string) ($links['communication'] ?? '')) . '">' . esc_html__('Verstuur voorstel', 'sbdp') . '</a>';
        } elseif ($nextAction === 'Uitvoering voorbereiden' && (string) ($links['order'] ?? '') !== '') {
            $primary = '<a class="button button-secondary" href="' . esc_url((string) $links['order']) . '">' . esc_html__('Bekijk order', 'sbdp') . '</a>';
        } else {
            $primary = '<a class="button button-secondary" href="' . esc_url((string) (($links['quote'] ?? '') !== '' ? $links['quote'] : ($links['request'] ?? ''))) . '">' . esc_html__('Open offerte', 'sbdp') . '</a>';
        }

        $secondary = '';
        if ($quote === null && $requestId > 0) {
            $secondary = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">'
                . wp_nonce_field('sbdp_quote_request_convert', '_wpnonce', true, false)
                . '<input type="hidden" name="action" value="sbdp_quote_request_convert">'
                . '<input type="hidden" name="quote_request_id" value="' . esc_attr((string) $requestId) . '">'
                . '<button class="button-link" type="submit">' . esc_html__('Offerte aanmaken', 'sbdp') . '</button>'
                . '</form>';
        } elseif ((string) ($links['quote'] ?? '') !== '') {
            $secondary = '<a class="button-link" href="' . esc_url((string) $links['quote']) . '">' . esc_html__('Open offerte', 'sbdp') . '</a>';
        }

        return '<div class="bsp-quote-admin__actions bsp-quote-admin__actions--stacked">' . $primary . $secondary . '</div>';
    }

    private static function isLikelyTestData(array $request, array $requester): bool
    {
        $signals = 0;
        $email = strtolower(trim((string) ($requester['email'] ?? '')));
        if ($email !== '' && (str_contains($email, 'example.test') || str_contains($email, 'qa') || str_contains($email, 'test'))) {
            $signals++;
        }

        $textCorpus = strtolower(implode(' ', array(
            (string) ($request['request_reference'] ?? ''),
            (string) ($request['request_summary'] ?? ''),
            (string) ($requester['name'] ?? ''),
        )));
        if ($textCorpus !== '' && preg_match('/\b(qa|test|dummy|codex|smoke)\b/', $textCorpus) === 1) {
            $signals++;
        }

        $sourcePayload = $request['source_payload'] ?? null;
        if (is_array($sourcePayload) && ! empty($sourcePayload['is_test'])) {
            $signals++;
        }

        return $signals >= 2;
    }

    public static function renderQuotesPage(): void
    {
        self::assertAccess();

        $repository = new QuoteRepository();
        $quoteId    = isset($_GET['quote_id']) ? (int) $_GET['quote_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($quoteId > 0) {
            self::renderQuoteDetail($repository, $quoteId);
            return;
        }

        $quotes = $repository->listQuotes();
        $overviewRows = self::buildQuoteOverviewRows($repository, $quotes);
        $activeView = isset($_GET['quote_view']) ? sanitize_key((string) $_GET['quote_view']) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (! in_array($activeView, array('all', 'action', 'assumptions', 'ready', 'done'), true)) {
            $activeView = 'all';
        }
        $visibleRows = self::filterQuoteOverviewRows($overviewRows, $activeView);

        echo '<div class="wrap"><h1>' . esc_html__('Quotes', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Centraal quote-overzicht: eerst zien welke offerte aandacht nodig heeft, daarna pas de quote openen. Dit overzicht is read-only en voert geen bulkacties uit.', 'sbdp') . '</p>';
        self::renderAdminStyles();
        self::renderNotices();
        self::renderQuoteOverviewDashboard($overviewRows, $visibleRows, $activeView);
        echo '</div>';
    }

    /**
     * @param array<int, array<string, mixed>> $quotes
     * @return array<int, array<string, mixed>>
     */
    private static function buildQuoteOverviewRows(QuoteRepositoryInterface $repository, array $quotes): array
    {
        $rows = array();
        $dashboardService = new DashboardBlockerService();
        $businessValidator = new QuoteBusinessRuleValidator($repository);

        foreach ($quotes as $quote) {
            if (! is_array($quote)) {
                continue;
            }

            $quoteId = (int) ($quote['id'] ?? 0);
            if ($quoteId <= 0) {
                continue;
            }

            $request = isset($quote['quote_request_id']) ? $repository->findQuoteRequest((int) $quote['quote_request_id']) : null;
            $requester = is_array($request) ? self::extractRequesterContext($request) : array();
            $currentVersion = self::resolveCurrentQuoteVersion($repository, $quote);
            $assumptions = $repository->listQuoteAssumptions($quoteId);
            $sendReadiness = self::inspectQuoteSendReadiness($quoteId, $quote, $currentVersion, $repository);
            try {
                $businessValidation = $businessValidator->validateComplete($quoteId);
            } catch (\Throwable) {
                $businessValidation = array(
                    'valid' => false,
                    'violations' => array(
                        array(
                            'code' => 'business_context_invalid',
                            'severity' => 'error',
                            'message' => __('Quote-context kon niet volledig worden gelezen.', 'sbdp'),
                        ),
                    ),
                );
            }

            $quoteCommerciallyEditable = ! in_array((string) ($quote['status'] ?? ''), QuoteImmutabilityGuard::CONTENT_FROZEN_STATUSES, true);
            $dashboardState = $dashboardService->buildState($sendReadiness, $businessValidation, $assumptions, $quoteCommerciallyEditable);
            $category = self::quoteOverviewCategory($dashboardState, $quote);
            $primaryBlocker = is_array($dashboardState['primary_blocker'] ?? null) ? $dashboardState['primary_blocker'] : array();
            $state = (string) ($dashboardState['state'] ?? 'blocked');

            $focusLabel = match ($state) {
                'assumptions' => __('Controleer & bevestig', 'sbdp'),
                'ready' => __('Klaar voor voorstelmail', 'sbdp'),
                'locked' => __('Afgehandeld / audit', 'sbdp'),
                default => (string) ($primaryBlocker['label'] ?? __('Actie nodig', 'sbdp')),
            };
            $focusDescription = match ($state) {
                'assumptions' => sprintf(__('%d open bevestiging(en)', 'sbdp'), count((array) ($dashboardState['assumptions'] ?? array()))),
                'ready' => __('Open Communication en verstuur via de bestaande send-flow.', 'sbdp'),
                'locked' => __('Commercieel bevroren. Geen directe actie in overzicht.', 'sbdp'),
                default => (string) ($primaryBlocker['message'] ?? __('Open de quote voor de volgende stap.', 'sbdp')),
            };

            $rows[] = array(
                'quote' => $quote,
                'request' => $request,
                'requester' => $requester,
                'dashboard_state' => $dashboardState,
                'category' => $category,
                'focus_label' => $focusLabel,
                'focus_description' => $focusDescription,
                'priority' => self::quoteOverviewPriority($category, $state),
                'detail_url' => add_query_arg(array('page' => 'sbdp_quotes', 'quote_id' => $quoteId), admin_url('admin.php')),
                'request_url' => is_array($request)
                    ? add_query_arg(array('page' => 'sbdp_quote_requests', 'request_id' => (int) ($request['id'] ?? 0)), admin_url('admin.php'))
                    : '',
            );
        }

        usort($rows, static function (array $left, array $right): int {
            $priority = (int) ($left['priority'] ?? 99) <=> (int) ($right['priority'] ?? 99);
            if ($priority !== 0) {
                return $priority;
            }

            return strcmp((string) ($right['quote']['updated_at'] ?? ''), (string) ($left['quote']['updated_at'] ?? ''));
        });

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function filterQuoteOverviewRows(array $rows, string $activeView): array
    {
        if ($activeView === 'all') {
            return $rows;
        }

        return array_values(array_filter($rows, static fn (array $row): bool => (string) ($row['category'] ?? '') === $activeView));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $visibleRows
     */
    private static function renderQuoteOverviewDashboard(array $rows, array $visibleRows, string $activeView): void
    {
        $counts = array(
            'all' => count($rows),
            'action' => count(array_filter($rows, static fn (array $row): bool => (string) ($row['category'] ?? '') === 'action')),
            'assumptions' => count(array_filter($rows, static fn (array $row): bool => (string) ($row['category'] ?? '') === 'assumptions')),
            'ready' => count(array_filter($rows, static fn (array $row): bool => (string) ($row['category'] ?? '') === 'ready')),
            'done' => count(array_filter($rows, static fn (array $row): bool => (string) ($row['category'] ?? '') === 'done')),
        );

        echo '<section class="postbox bsp-quote-admin__panel bsp-quote-admin__overview-hero"><div class="bsp-quote-admin__panel-body">';
        echo '<p class="bsp-quote-admin__eyebrow">' . esc_html__('Quote command center', 'sbdp') . '</p>';
        echo '<h2>' . esc_html__('Wat heeft nu aandacht nodig?', 'sbdp') . '</h2>';
        echo '<p class="bsp-quote-admin__muted">' . esc_html__('Read-only overzicht. Open de quote voor bewerken, review, communicatie of handoff.', 'sbdp') . '</p>';
        echo '<div class="bsp-quote-admin__overview-stat-grid">';
        echo self::renderQuoteOverviewStat(__('Totaal', 'sbdp'), $counts['all'], 'all', $activeView);
        echo self::renderQuoteOverviewStat(__('Actie nodig', 'sbdp'), $counts['action'], 'action', $activeView);
        echo self::renderQuoteOverviewStat(__('Te bevestigen', 'sbdp'), $counts['assumptions'], 'assumptions', $activeView);
        echo self::renderQuoteOverviewStat(__('Klaar', 'sbdp'), $counts['ready'], 'ready', $activeView);
        echo self::renderQuoteOverviewStat(__('Afgehandeld', 'sbdp'), $counts['done'], 'done', $activeView);
        echo '</div>';
        echo '</div></section>';

        if ($visibleRows !== array()) {
            $first = $visibleRows[0];
            echo '<section class="postbox bsp-quote-admin__panel bsp-quote-admin__overview-next"><div class="bsp-quote-admin__panel-body">';
            echo '<span class="bsp-quote-admin__field-label">' . esc_html__('Eerst openen', 'sbdp') . '</span>';
            echo '<strong>' . esc_html((string) ($first['focus_label'] ?? __('Actie nodig', 'sbdp'))) . '</strong>';
            echo '<p>' . esc_html((string) ($first['focus_description'] ?? '')) . '</p>';
            echo '<a class="button button-primary" href="' . esc_url((string) ($first['detail_url'] ?? '')) . '">' . esc_html__('Open deze quote', 'sbdp') . '</a>';
            echo '</div></section>';
        }

        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html__('Alle quotes', 'sbdp') . '</h3></div><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__table-wrap"><table class="widefat striped bsp-quote-admin__overview-table"><thead><tr>';
        echo '<th>' . esc_html__('Aandacht', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Quote', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Klant / event', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Workflow', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Handoff / order', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Bijgewerkt', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Actie', 'sbdp') . '</th>';
        echo '</tr></thead><tbody>';

        if ($visibleRows === array()) {
            echo '<tr><td colspan="7">' . esc_html__('Geen quotes in deze filter.', 'sbdp') . '</td></tr>';
        }

        foreach ($visibleRows as $row) {
            self::renderQuoteOverviewRow($row);
        }

        echo '</tbody></table></div></div></section>';
    }

    private static function renderQuoteOverviewStat(string $label, int $count, string $view, string $activeView): string
    {
        $url = add_query_arg(array('page' => 'sbdp_quotes', 'quote_view' => $view), admin_url('admin.php'));
        $class = 'bsp-quote-admin__overview-stat' . ($activeView === $view ? ' is-active' : '');

        return '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) $count) . '</strong></a>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function renderQuoteOverviewRow(array $row): void
    {
        $quote = is_array($row['quote'] ?? null) ? $row['quote'] : array();
        $request = is_array($row['request'] ?? null) ? $row['request'] : null;
        $requester = is_array($row['requester'] ?? null) ? $row['requester'] : array();
        $status = (string) ($quote['status'] ?? 'draft');
        $reviewStatus = (string) ($quote['review_status'] ?? 'not_started');
        $sendStatus = (string) ($quote['send_status'] ?? 'not_ready');
        $handoffStatus = (string) ($quote['handoff_status'] ?? 'not_ready');
        $category = (string) ($row['category'] ?? 'action');

        echo '<tr class="bsp-quote-admin__overview-row is-' . esc_attr($category) . '">';
        echo '<td><strong>' . esc_html((string) ($row['focus_label'] ?? __('Actie nodig', 'sbdp'))) . '</strong><br><span class="bsp-quote-admin__muted">' . esc_html((string) ($row['focus_description'] ?? '')) . '</span></td>';
        echo '<td><strong>' . esc_html((string) ($quote['quote_reference'] ?? '')) . '</strong><br><span class="bsp-quote-admin__muted">#' . esc_html((string) ($quote['id'] ?? '0')) . '</span></td>';
        echo '<td>';
        echo '<strong>' . esc_html((string) (($requester['name'] ?? '') ?: __('Onbekend', 'sbdp'))) . '</strong>';
        if (! empty($requester['email'])) {
            echo '<br><span class="bsp-quote-admin__muted">' . esc_html((string) $requester['email']) . '</span>';
        }
        if (is_array($request)) {
            echo '<br><span class="bsp-quote-admin__muted">' . esc_html((string) (((int) ($request['group_size'] ?? 0)) > 0 ? ((int) $request['group_size']) . ' personen' : __('Groep open', 'sbdp'))) . ' · ' . esc_html((string) (($request['preferred_date'] ?? '') ?: __('Datum open', 'sbdp'))) . '</span>';
        }
        echo '</td>';
        echo '<td>';
        echo self::renderInlineBadge($status, self::statusBadgeClass($status)) . ' ';
        echo self::renderInlineBadge($reviewStatus, self::workflowBadgeClass($reviewStatus));
        echo '<br>' . self::renderInlineBadge($sendStatus, self::workflowBadgeClass($sendStatus));
        echo '</td>';
        echo '<td>' . self::renderInlineBadge($handoffStatus, self::workflowBadgeClass($handoffStatus));
        if (! empty($quote['woo_order_id'])) {
            echo '<br><span class="bsp-quote-admin__muted">' . esc_html(sprintf(__('Order #%d', 'sbdp'), (int) $quote['woo_order_id'])) . '</span>';
        }
        echo '</td>';
        echo '<td>' . esc_html((string) ($quote['updated_at'] ?? '')) . '</td>';
        echo '<td><div class="bsp-quote-admin__actions"><a class="button button-primary" href="' . esc_url((string) ($row['detail_url'] ?? '')) . '">' . esc_html__('Open quote', 'sbdp') . '</a>';
        if ((string) ($row['request_url'] ?? '') !== '') {
            echo self::renderInlineLink((string) $row['request_url'], __('Request', 'sbdp'));
        }
        echo '</div></td>';
        echo '</tr>';
    }

    /**
     * @param array<string, mixed> $dashboardState
     * @param array<string, mixed> $quote
     */
    private static function quoteOverviewCategory(array $dashboardState, array $quote): string
    {
        $state = (string) ($dashboardState['state'] ?? 'blocked');
        if ($state === 'ready') {
            return 'ready';
        }
        if ($state === 'assumptions') {
            return 'assumptions';
        }
        if ($state === 'locked' || in_array((string) ($quote['status'] ?? ''), array('sent', 'accepted', 'confirmed'), true)) {
            return 'done';
        }

        return 'action';
    }

    private static function quoteOverviewPriority(string $category, string $state): int
    {
        if ($category === 'action') {
            return 10;
        }
        if ($category === 'assumptions') {
            return 20;
        }
        if ($category === 'ready') {
            return 30;
        }
        if ($state === 'locked') {
            return 80;
        }

        return 90;
    }

    /**
     * @param array<string, mixed> $quote
     * @return array<string, mixed>|null
     */
    private static function resolveCurrentQuoteVersion(QuoteRepositoryInterface $repository, array $quote): ?array
    {
        $currentVersionId = (int) ($quote['current_version_id'] ?? 0);
        if ($currentVersionId > 0) {
            $version = $repository->findQuoteVersion($currentVersionId);
            if (is_array($version)) {
                return $version;
            }
        }

        $versions = $repository->listQuoteVersions((int) ($quote['id'] ?? 0));
        return $versions[0] ?? null;
    }

    public static function renderQuoteInboxPage(): void
    {
        self::assertAccess();

        $repository = new QuoteRepository();
        $failures = self::sortInboxFailures($repository->listQuoteMessageFailures());
        $quotes = $repository->listQuotes();

        echo '<div class="wrap"><h1>' . esc_html__('Quote Inbox', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Inbound replies die niet automatisch aan een quote konden worden gekoppeld. Dit voorkomt commercieel verlies bij slechte mail headers of ontbrekende thread-data.', 'sbdp') . '</p>';
        self::renderAdminStyles();
        self::renderNotices();

        $openFailures = array_values(array_filter($failures, static fn (array $failure): bool => (string) ($failure['status'] ?? 'open') === 'open'));
        $waitingOverDay = array_values(array_filter($openFailures, static fn (array $failure): bool => self::failureAgeInHours($failure) >= 24));
        $waitingOverThreeDays = array_values(array_filter($openFailures, static fn (array $failure): bool => self::failureAgeInHours($failure) >= 72));
        echo '<div class="bsp-quote-admin__stats">';
        echo self::renderStatCard(__('Open inbox issues', 'sbdp'), (string) count($openFailures));
        echo self::renderStatCard(__('Waiting >24h', 'sbdp'), (string) count($waitingOverDay));
        echo self::renderStatCard(__('Waiting >72h', 'sbdp'), (string) count($waitingOverThreeDays));
        echo self::renderStatCard(__('Resolved', 'sbdp'), (string) max(0, count($failures) - count($openFailures)));
        echo '</div>';

        echo '<div class="bsp-quote-admin__thread">';
        if ($failures === array()) {
            echo '<div class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-body"><p>' . esc_html__('Geen unmatched inbound replies.', 'sbdp') . '</p></div></div>';
        } else {
            foreach ($failures as $failure) {
                $status = (string) ($failure['status'] ?? 'open');
                $reason = (string) ($failure['failure_reason'] ?? 'unmatched_quote');
                $body = trim((string) ($failure['body'] ?? ''));
                $guessedQuoteReference = (string) ($failure['guessed_quote_reference'] ?? '');
                $suggestedQuote = null;
                if ($guessedQuoteReference !== '') {
                    foreach ($quotes as $quote) {
                        if ((string) ($quote['quote_reference'] ?? '') === $guessedQuoteReference) {
                            $suggestedQuote = $quote;
                            break;
                        }
                    }
                }
                $triage = self::buildInboxFailureTriage($failure, $suggestedQuote);
                echo '<article class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-body">';
                echo '<div class="bsp-quote-admin__thread-meta">';
                echo self::renderInlineBadge($status, $status === 'resolved' ? 'is-good' : 'is-warn');
                echo self::renderInlineBadge($reason, 'is-neutral');
                echo self::renderInlineBadge((string) ($triage['age_label'] ?? __('Nieuw', 'sbdp')), (string) ($triage['age_badge_class'] ?? 'is-neutral'));
                echo '<strong>' . esc_html((string) (($failure['subject'] ?? '') !== '' ? $failure['subject'] : __('Zonder onderwerp', 'sbdp'))) . '</strong>';
                echo '<span class="bsp-quote-admin__muted">' . esc_html((string) ($failure['created_at'] ?? '')) . '</span>';
                echo '</div>';
                echo '<div class="bsp-quote-admin__cell-stack">';
                echo '<span class="bsp-quote-admin__muted">' . esc_html(sprintf(
                    __('Van: %s <%s> | Aan: %s', 'sbdp'),
                    (string) ($failure['from_name'] ?? ''),
                    (string) ($failure['from_email'] ?? ''),
                    (string) ($failure['to_email'] ?? '')
                )) . '</span>';
                if (! empty($failure['guessed_quote_reference'])) {
                    echo '<span class="bsp-quote-admin__muted">' . esc_html(sprintf(__('Gedetecteerde quote reference: %s', 'sbdp'), (string) $failure['guessed_quote_reference'])) . '</span>';
                }
                if (! empty($failure['provider_message_id'])) {
                    echo '<span class="bsp-quote-admin__muted">' . esc_html(sprintf(__('Message-ID: %s', 'sbdp'), (string) $failure['provider_message_id'])) . '</span>';
                }
                echo '</div>';
                if (! empty($triage['action_title']) || ! empty($triage['action_description'])) {
                    echo '<div class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--compact">';
                    if (! empty($triage['action_title'])) {
                        echo '<strong>' . esc_html((string) $triage['action_title']) . '</strong>';
                    }
                    if (! empty($triage['action_description'])) {
                        echo '<p>' . esc_html((string) $triage['action_description']) . '</p>';
                    }
                    echo '</div>';
                }
                if ($suggestedQuote !== null) {
                    $suggestedQuoteUrl = add_query_arg(array('page' => 'sbdp_quotes', 'quote_id' => (int) ($suggestedQuote['id'] ?? 0)), admin_url('admin.php'));
                    echo '<div class="bsp-quote-admin__actions bsp-quote-admin__thread-actions">';
                    echo self::renderInlineBadge(__('Voorgestelde quote', 'sbdp'), 'is-good');
                    echo '<a class="button button-secondary" href="' . esc_url($suggestedQuoteUrl) . '">' . esc_html(sprintf(__('Open %s', 'sbdp'), (string) ($suggestedQuote['quote_reference'] ?? ''))) . '</a>';
                    echo '</div>';
                }
                if ($body !== '') {
                    echo '<div class="bsp-quote-admin__message-body">' . nl2br(esc_html($body)) . '</div>';
                }

                if ($status === 'open') {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form bsp-quote-admin__stack-form--muted">';
                    \wp_nonce_field('sbdp_quote_resolve_inbound_failure');
                    echo '<input type="hidden" name="action" value="sbdp_quote_resolve_inbound_failure"><input type="hidden" name="failure_id" value="' . esc_attr((string) ($failure['id'] ?? 0)) . '">';
                    echo '<label>' . esc_html__('Koppel aan quote', 'sbdp') . '<select name="quote_id" required><option value="">' . esc_html__('Selecteer quote', 'sbdp') . '</option>';
                    foreach ($quotes as $quote) {
                        $selected = ((string) ($quote['quote_reference'] ?? '') === $guessedQuoteReference) ? ' selected' : '';
                        $label = (string) (($quote['quote_reference'] ?? '') . ' | #' . ($quote['id'] ?? 0));
                        if ($selected !== '') {
                            $label .= ' | ' . __('suggested match', 'sbdp');
                        }
                        echo '<option value="' . esc_attr((string) ($quote['id'] ?? 0)) . '"' . $selected . '>' . esc_html($label) . '</option>';
                    }
                    echo '</select></label><button class="button button-primary" type="submit">' . esc_html__('Koppel en verwerk reply', 'sbdp') . '</button></form>';
                } elseif (! empty($failure['linked_quote_id'])) {
                    $detailUrl = add_query_arg(array('page' => 'sbdp_quotes', 'quote_id' => (int) $failure['linked_quote_id']), admin_url('admin.php'));
                    echo '<div class="bsp-quote-admin__actions bsp-quote-admin__thread-actions"><a class="button button-secondary" href="' . esc_url($detailUrl) . '">' . esc_html__('Open gekoppelde quote', 'sbdp') . '</a></div>';
                }

                echo '</div></article>';
            }
        }
        echo '</div></div>';
    }

    public static function renderQuoteAiMailSettingsPage(): void
    {
        self::assertAccess();

        $inboundSecret = (string) \get_option('bsp_inbound_mail_secret', '');
        $openAiModel = (string) \get_option('bsp_openai_model', 'gpt-4o');
        $openAiApiKey = (string) \get_option('bsp_openai_api_key', '');
        $openAiStatus = self::readOpenAiStatus();
        $maskedApiKey = $openAiApiKey !== '' ? str_repeat('*', max(0, strlen($openAiApiKey) - 4)) . substr($openAiApiKey, -4) : '';
        $endpoint = \function_exists('rest_url')
            ? (string) \rest_url('bsp/v1/inbound-mail')
            : '/wp-json/bsp/v1/inbound-mail';

        echo '<div class="wrap"><h1>' . esc_html__('Quote AI & Mail', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Beheer hier de inbound mail bridge en de OpenAI-draftconfiguratie voor de quotes-module.', 'sbdp') . '</p>';
        self::renderAdminStyles();
        self::renderNotices();

        echo '<div class="bsp-quote-admin__workspace">';
        echo '<div class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html__('Configuratie', 'sbdp') . '</h3><p>' . esc_html__('Slaat de webhook-secret en OpenAI-instellingen op in WordPress options.', 'sbdp') . '</p></div><div class="bsp-quote-admin__panel-body">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form">';
        \wp_nonce_field('sbdp_quote_save_ai_mail_settings');
        echo '<input type="hidden" name="action" value="sbdp_quote_save_ai_mail_settings">';
        echo '<label><span class="bsp-quote-admin__field-label">' . esc_html__('Inbound mail secret', 'sbdp') . '</span><input type="text" class="regular-text" name="bsp_inbound_mail_secret" value="' . esc_attr($inboundSecret) . '" autocomplete="off"></label>';
        echo '<p class="description">' . esc_html__('Webhook callers moeten deze exacte waarde meesturen in de X-BSP-Mail-Secret header.', 'sbdp') . '</p>';
        echo '<label><span class="bsp-quote-admin__field-label">' . esc_html__('OpenAI model', 'sbdp') . '</span><input type="text" class="regular-text" name="bsp_openai_model" value="' . esc_attr($openAiModel !== '' ? $openAiModel : 'gpt-4o') . '"></label>';
        echo '<label><span class="bsp-quote-admin__field-label">' . esc_html__('OpenAI API key', 'sbdp') . '</span><input type="password" class="regular-text" name="bsp_openai_api_key" value="' . esc_attr($openAiApiKey) . '" autocomplete="new-password"></label>';
        if ($maskedApiKey !== '') {
            echo '<p class="description">' . esc_html(sprintf(__('Huidige key opgeslagen: %s', 'sbdp'), $maskedApiKey)) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('Leeg laten betekent: AI-filters vallen stil terug op de bestaande template-drafts.', 'sbdp') . '</p>';
        }
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Instellingen opslaan', 'sbdp') . '</button></p>';
        echo '</form></div></div>';

        if ($openAiStatus !== null) {
            echo '<div class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html__('Laatste OpenAI status', 'sbdp') . '</h3><p>' . esc_html__('Laat de laatste bekende AI-callstatus zien voor proposal-, reply- en summary-drafts.', 'sbdp') . '</p></div><div class="bsp-quote-admin__panel-body">';
            echo self::renderOpenAiStatusNotice($openAiStatus);
            echo '</div></div>';
        }

        echo '<div class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html__('Runtime checks', 'sbdp') . '</h3><p>' . esc_html__('Gebruik deze checklist om de bridge in staging of productie gecontroleerd te activeren.', 'sbdp') . '</p></div><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__readiness-summary"><strong>' . esc_html__('REST endpoint', 'sbdp') . '</strong><p>' . esc_html($endpoint) . '</p></div>';
        echo '<ul class="bsp-quote-admin__checklist">';
        echo '<li><strong>' . esc_html__('1. Security', 'sbdp') . '</strong><span>' . esc_html__('Doe eerst een POST zonder header en verifieer dat het endpoint 403 teruggeeft.', 'sbdp') . '</span></li>';
        echo '<li><strong>' . esc_html__('2. Aanvragen flow', 'sbdp') . '</strong><span>' . esc_html__('Stuur een unmatched mail naar aanvragen@dagjedenbosch.nl en controleer of een nieuw quote request ontstaat.', 'sbdp') . '</span></li>';
        echo '<li><strong>' . esc_html__('3. Inbox flow', 'sbdp') . '</strong><span>' . esc_html__('Stuur een unmatched mail naar info@ of inkoop@ en controleer of Quote Inbox een failure toont.', 'sbdp') . '</span></li>';
        echo '<li><strong>' . esc_html__('4. AI fallback', 'sbdp') . '</strong><span>' . esc_html__('Laat de API key leeg en verifieer dat voorstel/reply/samenvatting ongewijzigd via template blijven werken.', 'sbdp') . '</span></li>';
        echo '</ul>';
        echo '<p><code>' . esc_html('pwsh -File scripts/test-inbound-mail-bridge.ps1 -BaseUrl https://jouwdomein.nl -Secret jouwsecret') . '</code></p>';
        echo '</div></div>';
        echo '</div></div>';
    }

    private static function renderQuoteDetail(QuoteRepository $repository, int $quoteId): void
    {
        $quote = $repository->findQuote($quoteId);
        if ($quote === null) {
            wp_die(esc_html__('Quote niet gevonden.', 'sbdp'));
        }

        $request = isset($quote['quote_request_id']) ? $repository->findQuoteRequest((int) $quote['quote_request_id']) : null;
        $versions = $repository->listQuoteVersions($quoteId);
        $currentVersion = null;
        foreach ($versions as $version) {
            if ((int) ($version['id'] ?? 0) === (int) ($quote['current_version_id'] ?? 0)) {
                $currentVersion = $version;
                break;
            }
        }
        if ($currentVersion === null && $versions !== array()) {
            $currentVersion = $versions[0];
        }

        $lines = $currentVersion !== null ? $repository->listQuoteLines((int) $currentVersion['id']) : array();

        if (is_array($request)) {
            self::resolveIntakeAssumptions(
                $repository,
                $quoteId,
                max(0, (int) ($request['group_size'] ?? 0)),
                trim((string) ($request['preferred_date'] ?? '')),
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        }

        $assumptions = $repository->listQuoteAssumptions($quoteId);
        $openAssumptions = array_values(array_filter($assumptions, static function ($assumption): bool {
            return is_array($assumption) && (string) ($assumption['status'] ?? 'open') === 'open';
        }));
        $followups = $repository->listQuoteFollowups($quoteId);
        $events = $repository->listQuoteEvents($quoteId);
        $messages = $repository->listQuoteMessages($quoteId);
        $requester = $request !== null ? self::extractRequesterContext($request) : array();
        $contactSummary = array_filter(array(
            (string) ($requester['email'] ?? ''),
            (string) ($requester['phone'] ?? ''),
        ));
        $formattedAddress = self::formatRequesterAddress($requester);
        $lineSummary = self::summarizeQuoteLines($lines, $currentVersion);
        $proposalProgram = self::buildQuoteProgram($lines, (string) ($lineSummary['currency'] ?? 'EUR'));
        $proposalReadiness = self::buildQuoteProposalReadiness($quote, $currentVersion, $lines, $assumptions);
        $sendReadiness = self::inspectQuoteSendReadiness($quoteId, $quote, $currentVersion, $repository);
        $businessValidation = (new QuoteBusinessRuleValidator($repository))->validateComplete($quoteId);
        $communicationState = self::buildQuoteCommunicationState($quote, $currentVersion, $messages, $assumptions, $sendReadiness);
        $commercialIntakeNotice = self::buildCommercialIntakeNoticeState($lines, $followups, $assumptions);
        $openAiStatus = self::readOpenAiStatus();
        $workspaceState = self::buildQuoteWorkspaceState($quote, $currentVersion, $lines, $assumptions, $followups, $communicationState);
        $quoteCommerciallyEditable = ! in_array((string) ($quote['status'] ?? ''), QuoteImmutabilityGuard::CONTENT_FROZEN_STATUSES, true);
        $operatorDecision = self::buildQuoteOperatorDecision($quote, $proposalReadiness, $workspaceState, $communicationState);
        $messageDrafts = self::resolveQuoteMessageDrafts($messages);
        $currentPayload = isset($currentVersion['handoff_payload_json']) && is_array($currentVersion['handoff_payload_json'])
            ? $currentVersion['handoff_payload_json']
            : array();
        $currentTab = self::resolveWorkspaceTab();
        $workspaceTabs = array(
            'dashboard' => __('📊 Dashboard', 'sbdp'),
            'build' => __('Bewerken / Build', 'sbdp'),
            'proposal' => __('Proposal', 'sbdp'),
            'communication' => __('Communication', 'sbdp'),
            'workflow' => __('Follow-up / Workflow', 'sbdp'),
            'history' => __('History / Advanced', 'sbdp'),
        );

        echo '<div class="wrap"><h1>' . esc_html__('Quote Workspace', 'sbdp') . '</h1>';
        self::renderAdminStyles();
        self::renderNotices();
        echo '<div class="bsp-quote-admin__workspace">';
        echo '<section class="postbox bsp-quote-admin__workspace-hero"><div class="bsp-quote-admin__workspace-hero-inner">';
        echo '<div class="bsp-quote-admin__workspace-heading">';
        echo '<p class="bsp-quote-admin__eyebrow">' . esc_html__('Operator workspace', 'sbdp') . '</p>';
        echo '<h2>' . esc_html((string) ($quote['quote_reference'] ?? '')) . '</h2>';
        if ($currentVersion !== null && trim((string) ($currentVersion['proposal_summary'] ?? '')) !== '') {
            echo '<p class="bsp-quote-admin__hero-summary">' . esc_html((string) $currentVersion['proposal_summary']) . '</p>';
        } elseif ($request !== null) {
            echo '<p class="bsp-quote-admin__hero-summary">' . esc_html((string) ($request['request_summary'] ?? '')) . '</p>';
        } else {
            echo '<p class="bsp-quote-admin__hero-summary">' . esc_html__('Commerciële quote met gescheiden review-, send- en handoff-truth.', 'sbdp') . '</p>';
        }
        echo '<div class="bsp-quote-admin__badge-row">';
        echo self::renderInlineBadge((string) ($quote['status'] ?? 'draft'), self::statusBadgeClass((string) ($quote['status'] ?? 'draft')));
        echo self::renderInlineBadge((string) ($quote['review_status'] ?? 'not_started'), self::workflowBadgeClass((string) ($quote['review_status'] ?? 'not_started')));
        echo self::renderInlineBadge((string) ($quote['send_status'] ?? 'not_ready'), self::workflowBadgeClass((string) ($quote['send_status'] ?? 'not_ready')));
        echo self::renderInlineBadge((string) ($quote['handoff_status'] ?? 'not_ready'), self::workflowBadgeClass((string) ($quote['handoff_status'] ?? 'not_ready')));
        if ($currentVersion !== null) {
            echo self::renderInlineBadge((string) ($currentVersion['pricing_confidence'] ?? 'unknown'), self::confidenceBadgeClass((string) ($currentVersion['pricing_confidence'] ?? 'unknown')));
            echo self::renderInlineBadge((string) ($currentVersion['availability_confidence'] ?? 'unknown'), self::confidenceBadgeClass((string) ($currentVersion['availability_confidence'] ?? 'unknown')));
        }
        echo '</div></div>';
        echo '<div class="bsp-quote-admin__next-action">';
        echo '<span class="bsp-quote-admin__next-action-label">' . esc_html__('Operatorfocus', 'sbdp') . '</span>';
        echo '<strong>' . esc_html((string) ($operatorDecision['status_label'] ?? __('Intern concept', 'sbdp'))) . '</strong>';
        echo '<p>' . esc_html((string) ($operatorDecision['status_description'] ?? '')) . '</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="bsp-quote-admin__workspace-meta">';
        echo self::renderStatCard(__('Klant', 'sbdp'), (string) ($requester['name'] ?? __('Onbekend', 'sbdp')));
        echo self::renderStatCard(__('Eventdatum', 'sbdp'), (string) (($request['preferred_date'] ?? '') ?: __('Nog niet bevestigd', 'sbdp')));
        echo self::renderStatCard(__('Groepsgrootte', 'sbdp'), (string) (((int) ($request['group_size'] ?? 0) > 0) ? (string) ((int) $request['group_size']) : __('Nog open', 'sbdp')));
        echo self::renderStatCard(__('Programmaregels', 'sbdp'), (string) ($proposalProgram['stats']['total_lines'] ?? count($lines)));
        echo self::renderStatCard(__('Gepland', 'sbdp'), sprintf('%d / %d', (int) ($proposalProgram['stats']['scheduled_lines'] ?? 0), (int) ($proposalProgram['stats']['total_lines'] ?? count($lines))));
        echo self::renderStatCard(__('Bevestigd', 'sbdp'), sprintf('%d / %d', (int) ($proposalProgram['stats']['confirmed_lines'] ?? 0), (int) ($proposalProgram['stats']['total_lines'] ?? count($lines))));
        echo self::renderStatCard(__('Snapshot totaal', 'sbdp'), (string) ($lineSummary['total_label'] ?? __('Nog niet bepaald', 'sbdp')));
        echo '</div></section>';

        echo '<nav class="nav-tab-wrapper bsp-quote-admin__workspace-tabs" aria-label="' . esc_attr__('Quote Workspace modes', 'sbdp') . '">';
        foreach ($workspaceTabs as $tabKey => $tabLabel) {
            $tabUrl = add_query_arg(array(
                'page' => 'sbdp_quotes',
                'quote_id' => $quoteId,
                'workspace_tab' => $tabKey,
            ), admin_url('admin.php'));
            $tabClass = 'nav-tab' . ($currentTab === $tabKey ? ' nav-tab-active' : '');
            echo '<a class="' . esc_attr($tabClass) . '" href="' . esc_url($tabUrl) . '">' . esc_html($tabLabel) . '</a>';
        }
        echo '</nav>';
        self::renderCommercialIntakeNotice($commercialIntakeNotice);

        if ($currentTab === 'dashboard') {
            $dashboardState = (new DashboardBlockerService())->buildState(
                $sendReadiness,
                $businessValidation,
                $assumptions,
                $quoteCommerciallyEditable
            );

            echo '<div class="bsp-quote-admin__workspace-single">';
            self::renderQuoteDashboardFocus($dashboardState, $quoteId);

            if ($request !== null) {
                self::renderQuoteDashboardCustomerSummary($request, $requester);
            }

            echo '</div>';
        } elseif ($currentTab === 'build') {
            self::renderQuoteBuildWorkspace($quoteId, $quote, $request, $currentVersion, $lines);
        } elseif ($currentTab === 'proposal') {
            echo '<div class="bsp-quote-admin__workspace-grid">';
            echo '<div class="bsp-quote-admin__workspace-main">';

            echo '<section id="quote-proposal-program" class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><div><h3>' . esc_html__('Proposal Program', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Dit is het voorgestelde dagprogramma op basis van de vastgelegde quote-versie en line snapshots. Geen live Woo-truth wordt hier teruggeschreven of verzonnen.', 'sbdp') . '</p></div></div><div class="bsp-quote-admin__panel-body">';
        if ($currentVersion !== null) {
            echo '<div class="bsp-quote-admin__proposal-summary">';
            echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Voorsteltitel', 'sbdp') . '</span><strong>' . esc_html((string) (($currentVersion['proposal_title'] ?? '') !== '' ? $currentVersion['proposal_title'] : __('Nog zonder titel', 'sbdp'))) . '</strong></div>';
            echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Versie', 'sbdp') . '</span><strong>' . esc_html(sprintf('#%s', (string) ($currentVersion['version_number'] ?? '1'))) . '</strong></div>';
            echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Snapshot type', 'sbdp') . '</span><strong>' . esc_html((string) ($currentVersion['snapshot_type'] ?? 'initial')) . '</strong></div>';
            echo '</div>';
        }
        echo '<div class="bsp-quote-admin__totals-grid">';
        echo self::renderStatCard(__('Stops', 'sbdp'), (string) ($proposalProgram['stats']['total_lines'] ?? count($lines)));
        echo self::renderStatCard(__('Met tijd', 'sbdp'), sprintf('%d / %d', (int) ($proposalProgram['stats']['scheduled_lines'] ?? 0), (int) ($proposalProgram['stats']['total_lines'] ?? count($lines))));
        echo self::renderStatCard(__('Prijs snapshot', 'sbdp'), sprintf('%d / %d', (int) ($proposalProgram['stats']['priced_lines'] ?? 0), (int) ($proposalProgram['stats']['total_lines'] ?? count($lines))));
        echo self::renderStatCard(__('Onder voorbehoud', 'sbdp'), (string) ($proposalProgram['stats']['provisional_lines'] ?? 0));
        echo '</div>';
        if (($proposalProgram['groups'] ?? array()) === array()) {
            echo '<p>' . esc_html__('Nog geen programmaregels beschikbaar in deze quote-versie.', 'sbdp') . '</p>';
        } else {
            echo '<div class="bsp-quote-admin__program">';
            foreach ((array) $proposalProgram['groups'] as $group) {
                echo '<section class="bsp-quote-admin__program-day">';
                echo '<div class="bsp-quote-admin__program-day-header"><h4>' . esc_html((string) ($group['label'] ?? __('Datum nog open', 'sbdp'))) . '</h4><span class="bsp-quote-admin__muted">' . esc_html(sprintf(__('%d onderdeel/onderdelen', 'sbdp'), count((array) ($group['items'] ?? array())))) . '</span></div>';
                echo '<div class="bsp-quote-admin__program-list">';
                foreach ((array) ($group['items'] ?? array()) as $item) {
                    echo '<article class="bsp-quote-admin__program-item">';
                    echo '<div class="bsp-quote-admin__program-time">';
                    if (! empty($item['time_label'])) {
                        echo self::renderInlineBadge((string) $item['time_label'], (string) ($item['time_badge_class'] ?? 'is-neutral'));
                    }
                    echo '<strong>' . esc_html((string) ($item['time_primary'] ?? __('Planning nog open', 'sbdp'))) . '</strong>';
                    if (! empty($item['time_secondary'])) {
                        echo '<span>' . esc_html((string) $item['time_secondary']) . '</span>';
                    }
                    echo '</div>';
                    echo '<div class="bsp-quote-admin__program-body">';
                    echo '<div class="bsp-quote-admin__badge-row">';
                    echo self::renderInlineBadge((string) ($item['availability_label'] ?? __('Onder voorbehoud', 'sbdp')), (string) ($item['availability_badge_class'] ?? 'is-warn'));
                    echo self::renderInlineBadge((string) ($item['pricing_label'] ?? __('Snapshot', 'sbdp')), (string) ($item['pricing_badge_class'] ?? 'is-neutral'));
                    if (! empty($item['optional_label'])) {
                        echo self::renderInlineBadge((string) $item['optional_label'], 'is-neutral');
                    }
                    echo '</div>';
                    echo '<h4>' . esc_html((string) ($item['title'] ?? __('Programmastop', 'sbdp'))) . '</h4>';
                    if (($item['option_labels'] ?? array()) !== array()) {
                        echo '<p class="bsp-quote-admin__program-subtitle">' . esc_html(sprintf(__('Geselecteerde optie: %s', 'sbdp'), implode(' | ', (array) $item['option_labels']))) . '</p>';
                    }
                    echo '<div class="bsp-quote-admin__cell-stack">';
                    foreach ((array) ($item['detail_bits'] ?? array()) as $detailBit) {
                        echo '<span>' . esc_html((string) $detailBit) . '</span>';
                    }
                    echo '</div>';
                    echo '<div class="bsp-quote-admin__program-price">';
                    echo '<strong>' . esc_html((string) ($item['price_primary'] ?? __('Prijs nog open', 'sbdp'))) . '</strong>';
                    if (! empty($item['price_secondary'])) {
                        echo '<span class="bsp-quote-admin__muted">' . esc_html((string) $item['price_secondary']) . '</span>';
                    }
                    echo '</div>';
                    if (! empty($item['availability_note'])) {
                        echo '<p class="bsp-quote-admin__muted bsp-quote-admin__program-note">' . esc_html((string) $item['availability_note']) . '</p>';
                    }
                    echo '</div></article>';
                }
                echo '</div></section>';
            }
            echo '</div>';
        }
        echo '</div></section>';

            echo '<section id="quote-assumptions" class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><div><h3>' . esc_html__('Assumptions', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Expliciete onzekerheden blijven zichtbaar en blokkeren review, send of handoff waar nodig.', 'sbdp') . '</p></div></div><div class="bsp-quote-admin__panel-body">';
        $openAssumptions = array_values(array_filter($assumptions, static function ($assumption): bool {
            return is_array($assumption) && (string) ($assumption['status'] ?? 'open') === 'open';
        }));
        if ($openAssumptions === array()) {
            echo '<p>' . esc_html__('Geen open assumptions.', 'sbdp') . '</p>';
        } else {
            echo '<ul class="bsp-quote-admin__checklist">';
            foreach ($openAssumptions as $assumption) {
                $flags = array();
                if (! empty($assumption['blocks_review'])) {
                    $flags[] = 'blocks review';
                }
                if (! empty($assumption['blocks_send'])) {
                    $flags[] = 'blocks send';
                }
                if (! empty($assumption['blocks_handoff'])) {
                    $flags[] = 'blocks handoff';
                }
                echo '<li><strong>' . esc_html((string) ($assumption['assumption_type'] ?? 'assumption')) . '</strong><span>' . esc_html((string) ($assumption['message'] ?? '')) . '</span>';
                if ($flags !== array()) {
                    echo '<span class="bsp-quote-admin__muted">' . esc_html(implode(' | ', $flags)) . '</span>';
                }
                if (
                    $quoteCommerciallyEditable
                    &&
                    (string) ($assumption['status'] ?? 'open') === 'open'
                    && in_array((string) ($assumption['assumption_type'] ?? ''), array('uncertain_pricing', 'uncertain_availability'), true)
                ) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form bsp-quote-admin__stack-form--muted">';
                    \wp_nonce_field('sbdp_quote_resolve_assumption');
                    echo '<input type="hidden" name="action" value="sbdp_quote_resolve_assumption"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="assumption_id" value="' . esc_attr((string) ($assumption['id'] ?? 0)) . '"><input type="hidden" name="workspace_tab" value="' . esc_attr($currentTab) . '">';
                    echo '<label>' . esc_html__('Operatornotitie bij vrijgave', 'sbdp') . '<textarea class="large-text" rows="3" name="resolution_note" required placeholder="' . esc_attr__('Bijv. prijs bevestigd met leverancier op 2026-05-08 / beschikbaarheid gecontroleerd in execution-validatie.', 'sbdp') . '"></textarea></label>';
                    echo '<p class="bsp-quote-admin__muted">' . esc_html__('Notitie verplicht: leg kort vast hoe je deze blocker hebt gecontroleerd.', 'sbdp') . '</p>';
                    echo '<button class="button button-secondary" type="submit">' . esc_html__('Markeer als gecontroleerd', 'sbdp') . '</button></form>';
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form" style="margin-top:4px">';
                    \wp_nonce_field('sbdp_quote_resolve_assumption');
                    echo '<input type="hidden" name="action" value="sbdp_quote_resolve_assumption"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="assumption_id" value="' . esc_attr((string) ($assumption['id'] ?? 0)) . '"><input type="hidden" name="workspace_tab" value="' . esc_attr($currentTab) . '"><input type="hidden" name="resolution_note" value="Gecheckt door operator: akkoord voor verzending."><button class="button" type="submit">' . esc_html__('Snel bevestigen', 'sbdp') . '</button></form>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div></section>';
            echo '</div>';

            echo '<aside class="bsp-quote-admin__workspace-side">';
            echo '<section id="quote-operator-decision" class="postbox bsp-quote-admin__panel bsp-quote-admin__panel--operator"><div class="bsp-quote-admin__panel-header"><div><h3>' . esc_html__('Operator Decision', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Eerst status begrijpen, daarna alleen de eerstvolgende stap uitvoeren. De proposal blijft leidend; Woo en booking blijven buiten deze laag.', 'sbdp') . '</p></div></div><div class="bsp-quote-admin__panel-body">';
            echo '<div class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--operator">';
            echo self::renderInlineBadge((string) ($operatorDecision['status_label'] ?? __('Intern concept', 'sbdp')), (string) ($operatorDecision['status_badge_class'] ?? 'is-neutral'));
            if (! empty($operatorDecision['thread_label'])) {
                echo self::renderInlineBadge((string) $operatorDecision['thread_label'], (string) ($operatorDecision['thread_badge_class'] ?? 'is-neutral'));
            }
            echo '<strong>' . esc_html((string) ($operatorDecision['title'] ?? __('Nog niet klantklaar', 'sbdp'))) . '</strong>';
            echo '<p>' . esc_html((string) ($operatorDecision['status_description'] ?? '')) . '</p>';
            echo '</div>';
            echo '<div class="bsp-quote-admin__decision-grid">';
            echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Wat mist nog', 'sbdp') . '</span>';
            if (($operatorDecision['missing_items'] ?? array()) === array()) {
                echo '<p class="bsp-quote-admin__muted">' . esc_html__('Geen open blockers op workspace-niveau. Controleer alleen nog de klantcommunicatie voordat je verzendt.', 'sbdp') . '</p>';
            } else {
                echo '<ul class="bsp-quote-admin__checklist bsp-quote-admin__checklist--compact">';
                foreach ((array) $operatorDecision['missing_items'] as $item) {
                    echo '<li><strong>' . esc_html((string) ($item['title'] ?? '')) . '</strong><span>' . esc_html((string) ($item['description'] ?? '')) . '</span></li>';
                }
                echo '</ul>';
            }
            echo '</div>';
            echo '<div class="bsp-quote-admin__decision-action"><span class="bsp-quote-admin__field-label">' . esc_html__('Primaire volgende stap', 'sbdp') . '</span><strong>' . esc_html((string) ($workspaceState['next_action']['title'] ?? __('Werk quote bij', 'sbdp'))) . '</strong><p>' . esc_html((string) ($workspaceState['next_action']['description'] ?? '')) . '</p>' . self::renderQuotePrimaryAction($quoteId, is_array($workspaceState['next_action'] ?? null) ? $workspaceState['next_action'] : array('cta' => 'review_request')) . '</div>';
            echo '</div>';
            echo '</div></section>';
            echo '</aside></div>';
        } elseif ($currentTab === 'communication') {
            echo '<section id="quote-communication" class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><div><h3>' . esc_html__('Communication', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Proposal mail, klantreacties en reply drafts in een aparte werkmodus. Dit blijft los van Woo- en booking-truth.', 'sbdp') . '</p></div></div><div class="bsp-quote-admin__panel-body">';
            echo '<div class="bsp-quote-admin__badge-row">';
            echo self::renderInlineBadge((string) ($communicationState['proposal_label'] ?? __('Nothing sent', 'sbdp')), (string) ($communicationState['proposal_badge_class'] ?? 'is-neutral'));
            echo self::renderInlineBadge((string) ($communicationState['thread_label'] ?? __('No thread yet', 'sbdp')), (string) ($communicationState['thread_badge_class'] ?? 'is-neutral'));
            if (! empty($communicationState['latest_outbound_version_label'])) {
                echo self::renderInlineBadge((string) $communicationState['latest_outbound_version_label'], 'is-neutral');
            }
            echo '</div>';
            echo '<div class="bsp-quote-admin__readiness-summary"><strong>' . esc_html((string) ($communicationState['headline'] ?? __('Nog geen klantcommunicatie.', 'sbdp'))) . '</strong><p>' . esc_html((string) ($communicationState['description'] ?? '')) . '</p></div>';
            if (! empty($communicationState['operator_action_title']) || ! empty($communicationState['operator_action_description'])) {
                echo '<div class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--action">';
                echo '<span class="bsp-quote-admin__field-label">' . esc_html__('Operatoractie', 'sbdp') . '</span>';
                if (! empty($communicationState['operator_action_title'])) {
                    echo '<strong>' . esc_html((string) $communicationState['operator_action_title']) . '</strong>';
                }
                if (! empty($communicationState['operator_action_description'])) {
                    echo '<p>' . esc_html((string) $communicationState['operator_action_description']) . '</p>';
                }
                if (! empty($communicationState['operator_action_age_label'])) {
                    echo '<div class="bsp-quote-admin__badge-row">';
                    echo self::renderInlineBadge((string) $communicationState['operator_action_age_label'], (string) ($communicationState['operator_action_age_badge_class'] ?? 'is-neutral'));
                    echo '</div>';
                }
                if (! empty($communicationState['latest_inbound_message_id'])) {
                    echo '<div class="bsp-quote-admin__actions bsp-quote-admin__thread-actions">';
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">';
                    \wp_nonce_field('sbdp_quote_summarize_message');
                    echo '<input type="hidden" name="action" value="sbdp_quote_summarize_message"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_id" value="' . esc_attr((string) $communicationState['latest_inbound_message_id']) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button button-secondary" type="submit">' . esc_html__('Vat laatste reply samen', 'sbdp') . '</button></form>';
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">';
                    \wp_nonce_field('sbdp_quote_generate_response_draft');
                    echo '<input type="hidden" name="action" value="sbdp_quote_generate_response_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_id" value="' . esc_attr((string) $communicationState['latest_inbound_message_id']) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button" type="submit">' . esc_html__('Maak antwoorddraft', 'sbdp') . '</button></form>';
                    echo '</div>';
                }
                echo '</div>';
            }
            echo '<div class="bsp-quote-admin__workflow-group"><h4>' . esc_html__('Proposal Email', 'sbdp') . '</h4>';
            if ($openAiStatus !== null) {
                echo self::renderOpenAiStatusNotice($openAiStatus);
            }
            if (! empty($communicationState['proposal_already_sent'])) {
                echo '<div class="bsp-quote-admin__readiness-summary"><strong>' . esc_html__('Voorstel is al verstuurd', 'sbdp') . '</strong><p>' . esc_html__('Deze quote heeft al een verzonden voorstelmail in de thread. Verstuur geen tweede voorstel vanuit deze workflow; wacht op klantreactie of gebruik de reply-flow zodra er inbound mail is.', 'sbdp') . '</p></div>';
            } elseif ($communicationState['proposal_send_ready'] !== true) {
                echo '<div class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--action"><strong>' . esc_html__('Voorstel verzenden nog niet beschikbaar', 'sbdp') . '</strong><p>' . esc_html((string) ($communicationState['proposal_send_block_reason'] ?? __('Vraag eerst review aan en keur de quote goed.', 'sbdp'))) . '</p></div>';
                if (($communicationState['proposal_send_blockers'] ?? array()) !== array()) {
                    echo '<ul class="bsp-quote-admin__checklist bsp-quote-admin__checklist--compact">';
                    foreach ((array) ($communicationState['proposal_send_blockers'] ?? array()) as $blocker) {
                        if (! is_array($blocker)) {
                            continue;
                        }
                        echo '<li><strong>' . esc_html((string) ($blocker['label'] ?? __('Blokkade', 'sbdp'))) . '</strong><span>' . esc_html((string) ($blocker['message'] ?? '')) . '</span></li>';
                    }
                    echo '</ul>';
                }
                if ($quoteCommerciallyEditable) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form bsp-quote-admin__stack-form--muted" style="margin-top:12px">';
                    \wp_nonce_field('sbdp_quote_quick_prepare_to_send');
                    echo '<input type="hidden" name="action" value="sbdp_quote_quick_prepare_to_send"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
                    echo '<p>' . esc_html__('Bevestig dat prijs en beschikbaarheid zijn gecheckt. Het systeem lost de open blockers op en zet de quote klaar voor verzending.', 'sbdp') . '</p>';
                    echo '<button class="button button-primary" type="submit">' . esc_html__('Bevestig en klaar voor verzending', 'sbdp') . '</button>';
                    echo '</form>';
                }
            }
            if (empty($communicationState['proposal_already_sent'])) {
                echo '<div class="bsp-quote-admin__actions">';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">';
                \wp_nonce_field('sbdp_quote_generate_proposal_draft');
                echo '<input type="hidden" name="action" value="sbdp_quote_generate_proposal_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button" type="submit">' . esc_html__('AI draft proposal', 'sbdp') . '</button></form>';
                echo '</div>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form">';
                \wp_nonce_field('sbdp_quote_send_message');
                echo '<input type="hidden" name="action" value="sbdp_quote_send_message"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_type" value="proposal"><input type="hidden" name="draft_id" value="' . esc_attr((string) ($messageDrafts['proposal']['id'] ?? '')) . '"><input type="hidden" name="workspace_tab" value="communication">';
                echo '<label>' . esc_html__('Aan', 'sbdp') . '<input class="regular-text" type="text" name="to_name" value="' . esc_attr((string) ($requester['name'] ?? '')) . '"></label>';
                echo '<label>' . esc_html__('E-mail', 'sbdp') . '<input class="regular-text" type="email" name="to_email" value="' . esc_attr((string) ($requester['email'] ?? '')) . '"></label>';
                echo '<label>' . esc_html__('Onderwerp', 'sbdp') . '<input class="regular-text" type="text" name="subject" value="' . esc_attr((string) ($messageDrafts['proposal']['subject'] ?? '')) . '"></label>';
                echo '<label>' . esc_html__('Bericht', 'sbdp') . '<textarea class="large-text" rows="8" name="body">' . esc_html((string) ($messageDrafts['proposal']['body'] ?? '')) . '</textarea></label>';
                if ($communicationState['proposal_send_ready'] === true) {
                    echo '<button class="button button-primary" type="submit">' . esc_html__('Verstuur voorstelmail', 'sbdp') . '</button>';
                }
                echo '</form>';
            }
            echo '</div>';
            echo '<div class="bsp-quote-admin__workflow-group"><h4>' . esc_html__('Inbound Replies & Response', 'sbdp') . '</h4>';
            if ($communicationState['reply_ready'] !== true) {
                echo '<div class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--action"><strong>' . esc_html__('Antwoordflow nog niet beschikbaar', 'sbdp') . '</strong><p>' . esc_html((string) ($communicationState['reply_block_reason'] ?? __('Verstuur eerst een voorstel en wacht daarna op een inbound klantreply.', 'sbdp'))) . '</p></div>';
            }
            echo '<div class="bsp-quote-admin__actions">';
            if (! empty($communicationState['latest_inbound_message_id'])) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">';
                \wp_nonce_field('sbdp_quote_summarize_message');
                echo '<input type="hidden" name="action" value="sbdp_quote_summarize_message"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_id" value="' . esc_attr((string) $communicationState['latest_inbound_message_id']) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button" type="submit">' . esc_html__('Summarize latest reply', 'sbdp') . '</button></form>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">';
                \wp_nonce_field('sbdp_quote_generate_response_draft');
                echo '<input type="hidden" name="action" value="sbdp_quote_generate_response_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_id" value="' . esc_attr((string) $communicationState['latest_inbound_message_id']) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button" type="submit">' . esc_html__('AI draft response', 'sbdp') . '</button></form>';
            }
            echo '</div>';
            if ($communicationState['reply_ready'] === true) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form">';
                \wp_nonce_field('sbdp_quote_send_message');
                echo '<input type="hidden" name="action" value="sbdp_quote_send_message"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_type" value="reply"><input type="hidden" name="draft_id" value="' . esc_attr((string) ($messageDrafts['reply']['id'] ?? '')) . '"><input type="hidden" name="reply_to_message_id" value="' . esc_attr((string) ($messageDrafts['reply']['in_reply_to_message_id'] ?? '')) . '"><input type="hidden" name="workspace_tab" value="communication">';
                echo '<label>' . esc_html__('Aan', 'sbdp') . '<input class="regular-text" type="text" name="to_name" value="' . esc_attr((string) ($messageDrafts['reply']['to_name'] ?? ($requester['name'] ?? ''))) . '"></label>';
                echo '<label>' . esc_html__('E-mail', 'sbdp') . '<input class="regular-text" type="email" name="to_email" value="' . esc_attr((string) ($messageDrafts['reply']['to_email'] ?? ($requester['email'] ?? ''))) . '"></label>';
                echo '<label>' . esc_html__('Onderwerp', 'sbdp') . '<input class="regular-text" type="text" name="subject" value="' . esc_attr((string) ($messageDrafts['reply']['subject'] ?? '')) . '"></label>';
                echo '<label>' . esc_html__('Bericht', 'sbdp') . '<textarea class="large-text" rows="6" name="body">' . esc_html((string) ($messageDrafts['reply']['body'] ?? '')) . '</textarea></label>';
                echo '<button class="button button-secondary" type="submit">' . esc_html__('Verstuur reply', 'sbdp') . '</button></form>';
            }
            echo '<div class="bsp-quote-admin__thread">';
            if ($messages === array()) {
                echo '<p class="bsp-quote-admin__muted">' . esc_html__('Nog geen quote-berichten opgeslagen.', 'sbdp') . '</p>';
            } else {
                foreach ($messages as $message) {
                    echo self::renderQuoteMessageCard($quoteId, $message);
                }
            }
            echo '</div></div></section>';
        } elseif ($currentTab === 'workflow') {
            echo '<div class="bsp-quote-admin__workspace-stack">';
            echo '<section id="quote-workflow" class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><div><h3>' . esc_html__('Workflow & Communicatie', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Review en send blijven Quote OS-workflows en raken geen Woo-order- of booking-statussen.', 'sbdp') . '</p></div></div><div class="bsp-quote-admin__panel-body">';
            echo '<div class="bsp-quote-admin__workflow-group">';
            echo '<h4>' . esc_html__('Review', 'sbdp') . '</h4>';
            echo '<div class="bsp-quote-admin__actions">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            \wp_nonce_field('sbdp_quote_review_request');
            echo '<input type="hidden" name="action" value="sbdp_quote_review_request"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button" type="submit">' . esc_html__('Vraag review aan', 'sbdp') . '</button></form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            \wp_nonce_field('sbdp_quote_review_approve');
            echo '<input type="hidden" name="action" value="sbdp_quote_review_approve"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-secondary" type="submit">' . esc_html__('Keur review goed', 'sbdp') . '</button></form>';
            echo '</div>';
            if ($quoteCommerciallyEditable) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form" style="margin-top:8px">';
                \wp_nonce_field('sbdp_quote_quick_prepare_to_send');
                echo '<input type="hidden" name="action" value="sbdp_quote_quick_prepare_to_send"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-primary" type="submit">' . esc_html__('Klaar voor verzending (snelvoorbereiding)', 'sbdp') . '</button></form>';
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form">';
            \wp_nonce_field('sbdp_quote_review_return');
            echo '<input type="hidden" name="action" value="sbdp_quote_review_return"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><label>' . esc_html__('Review-notitie', 'sbdp') . '<input class="regular-text" type="text" name="review_note"></label><button class="button" type="submit">' . esc_html__('Zet terug naar draft', 'sbdp') . '</button></form>';
            echo '</div>';
            echo '<div class="bsp-quote-admin__workflow-group">';
            echo '<h4>' . esc_html__('Send', 'sbdp') . '</h4>';
            $openSendAssumptions = array_values(array_filter($assumptions, static function (array $assumption): bool {
                return (string) ($assumption['status'] ?? 'open') === 'open' && ! empty($assumption['blocks_send']);
            }));
            if ($openSendAssumptions !== array()) {
                echo '<div class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--action"><strong>' . esc_html__('Versturen nog geblokkeerd', 'sbdp') . '</strong><p>' . esc_html__('Deze quote heeft nog open commerciële send-blockers. Los deze expliciet op voordat je een voorstelmail verstuurt.', 'sbdp') . '</p></div>';
                echo '<ul class="bsp-quote-admin__checklist bsp-quote-admin__checklist--compact">';
                foreach ($openSendAssumptions as $assumption) {
                    echo '<li><strong>' . esc_html((string) ($assumption['assumption_type'] ?? 'send_blocker')) . '</strong><span>' . esc_html((string) ($assumption['message'] ?? '')) . '</span></li>';
                }
                echo '</ul>';
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form">';
            \wp_nonce_field('sbdp_quote_mark_sent_manual');
            echo '<input type="hidden" name="action" value="sbdp_quote_mark_sent_manual"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><label>' . esc_html__('Kanaal', 'sbdp') . '<input class="regular-text" type="text" name="send_channel" value="manual"></label><label>' . esc_html__('Verzendnotitie', 'sbdp') . '<textarea class="large-text" rows="3" name="send_note"></textarea></label><button class="button button-secondary" type="submit">' . esc_html__('Markeer als handmatig verzonden', 'sbdp') . '</button></form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form">';
            \wp_nonce_field('sbdp_quote_reopen_send');
            echo '<input type="hidden" name="action" value="sbdp_quote_reopen_send"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><label>' . esc_html__('Heropen-notitie', 'sbdp') . '<input class="regular-text" type="text" name="send_reopen_note"></label><button class="button" type="submit">' . esc_html__('Zet terug naar ready_to_send', 'sbdp') . '</button></form>';
            echo '</div>';
            if ($request !== null) {
                echo '<div class="bsp-quote-admin__workflow-group"><h4>' . esc_html__('Intake context', 'sbdp') . '</h4>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form">';
                \wp_nonce_field('sbdp_quote_update_intake');
                echo '<input type="hidden" name="action" value="sbdp_quote_update_intake"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
                echo '<label>' . esc_html__('Groepsgrootte', 'sbdp') . '<input class="small-text" type="number" name="group_size" min="0" value="' . esc_attr((string) max(0, (int) ($request['group_size'] ?? 0))) . '"></label>';
                echo '<label>' . esc_html__('Voorkeursdatum', 'sbdp') . '<input class="regular-text" type="date" name="preferred_date" value="' . esc_attr((string) ($request['preferred_date'] ?? '')) . '"></label>';
                echo '<button class="button button-secondary" type="submit">' . esc_html__('Werk intake bij', 'sbdp') . '</button>';
                echo '</form>';
                echo '<div class="bsp-quote-admin__cell-stack">';
                echo '<span><strong>' . esc_html__('Aanvrager', 'sbdp') . ':</strong> ' . esc_html((string) ($requester['name'] ?? '')) . '</span>';
                if ($contactSummary !== array()) {
                    echo '<span class="bsp-quote-admin__muted">' . esc_html(implode(' | ', $contactSummary)) . '</span>';
                }
                if (! empty($requester['company'])) {
                    echo '<span><strong>' . esc_html__('Bedrijf', 'sbdp') . ':</strong> ' . esc_html((string) $requester['company']) . '</span>';
                }
                if ($formattedAddress !== '') {
                    echo '<span><strong>' . esc_html__('Adres', 'sbdp') . ':</strong> ' . esc_html($formattedAddress) . '</span>';
                }
                if (isset($request['normalized_payload']['notes']) && is_string($request['normalized_payload']['notes']) && trim($request['normalized_payload']['notes']) !== '') {
                    echo '<span><strong>' . esc_html__('Klantnotitie', 'sbdp') . ':</strong> ' . esc_html((string) $request['normalized_payload']['notes']) . '</span>';
                }
                echo '</div></div>';
            }
            $internalNotes = trim((string) ($quote['internal_notes'] ?? ''));
            echo '<div class="bsp-quote-admin__workflow-group"><h4>' . esc_html__('Interne notitie', 'sbdp') . '</h4>';
            echo '<p class="bsp-quote-admin__muted">' . esc_html($internalNotes !== '' ? $internalNotes : __('Nog geen interne notities.', 'sbdp')) . '</p></div>';
            echo '</div></section>';
            echo '<section id="quote-followups" class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><div><h3>' . esc_html__('Follow-ups', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Open taken en opvolging voor commerciële voortgang.', 'sbdp') . '</p></div></div><div class="bsp-quote-admin__panel-body">';
            if ($followups === array()) {
                echo '<p>' . esc_html__('Nog geen follow-ups.', 'sbdp') . '</p>';
            } else {
                echo '<ul class="bsp-quote-admin__checklist">';
                foreach ($followups as $followup) {
                    echo '<li><strong>' . esc_html((string) ($followup['title'] ?? '')) . '</strong><span>' . esc_html(sprintf('%s | %s | %s', (string) ($followup['followup_type'] ?? 'manual_review'), (string) ($followup['priority'] ?? 'normal'), (string) ($followup['status'] ?? 'open'))) . '</span>';
                    if (! empty($followup['due_at'])) {
                        echo '<span class="bsp-quote-admin__muted">' . esc_html((string) $followup['due_at']) . '</span>';
                    }
                    if ((string) ($followup['status'] ?? 'open') === 'open') {
                        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                        \wp_nonce_field('sbdp_quote_followup_complete');
                        echo '<input type="hidden" name="action" value="sbdp_quote_followup_complete"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="followup_id" value="' . esc_attr((string) ($followup['id'] ?? 0)) . '"><button class="button button-secondary" type="submit">' . esc_html__('Afronden', 'sbdp') . '</button></form>';
                    }
                    echo '</li>';
                }
                echo '</ul>';
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form">';
            \wp_nonce_field('sbdp_quote_followup_create');
            echo '<input type="hidden" name="action" value="sbdp_quote_followup_create"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><label>' . esc_html__('Titel', 'sbdp') . '<input class="regular-text" type="text" name="title" required></label><label>' . esc_html__('Type', 'sbdp') . '<input class="regular-text" type="text" name="followup_type" value="manual_review"></label><label>' . esc_html__('Prioriteit', 'sbdp') . '<select name="priority"><option value="low">low</option><option value="normal" selected>normal</option><option value="high">high</option></select></label><label>' . esc_html__('Due at', 'sbdp') . '<input class="regular-text" type="datetime-local" name="due_at"></label><label>' . esc_html__('Notitie', 'sbdp') . '<textarea class="large-text" rows="3" name="note"></textarea></label><button class="button button-secondary" type="submit">' . esc_html__('Follow-up opslaan', 'sbdp') . '</button></form>';
            echo '</div></section>';
            echo '</div>';
        } elseif ($currentTab === 'history') {
            echo '<div class="bsp-quote-admin__workspace-stack">';
            
            // 1. Event Timeline (vaak handig om als eerste te zien in geschiedenis)
            echo '<details class="postbox bsp-quote-admin__panel bsp-quote-admin__panel--collapsible" open><summary class="bsp-quote-admin__panel-toggle"><span><strong>' . esc_html__('Event Timeline', 'sbdp') . '</strong><small>' . esc_html__('Alle quote-events blijven beschikbaar onder de operatorlaag.', 'sbdp') . '</small></span></summary><div class="bsp-quote-admin__panel-body">';
            if ($events === array()) {
                echo '<p>' . esc_html__('Nog geen events.', 'sbdp') . '</p>';
            } else {
                echo '<ul class="bsp-quote-admin__timeline">';
                foreach ($events as $event) {
                    echo '<li><strong>' . esc_html((string) ($event['occurred_at'] ?? '')) . '</strong><span>' . esc_html((string) ($event['event_type'] ?? '')) . '</span><span class="bsp-quote-admin__muted">' . esc_html((string) ($event['message'] ?? '')) . '</span></li>';
                }
                echo '</ul>';
            }
            echo '</div></details>';

            // 2. Pricing & Line Records Snapshot Data
            echo '<details id="quote-commercial" class="postbox bsp-quote-admin__panel bsp-quote-admin__panel--collapsible"><summary class="bsp-quote-admin__panel-toggle"><span><strong>' . esc_html__('Pricing & Line Records', 'sbdp') . '</strong><small>' . esc_html__('Onderliggende quote-regels en snapshots voor detailcontrole.', 'sbdp') . '</small></span></summary><div class="bsp-quote-admin__panel-body">';
            if ($currentVersion !== null) {
                echo '<div class="bsp-quote-admin__proposal-summary">';
                echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Prijs confidence', 'sbdp') . '</span><strong>' . esc_html((string) ($currentVersion['pricing_confidence'] ?? 'unknown')) . '</strong></div>';
                echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Beschikbaarheid confidence', 'sbdp') . '</span><strong>' . esc_html((string) ($currentVersion['availability_confidence'] ?? 'unknown')) . '</strong></div>';
                echo '</div>';
            }
            echo '<div class="bsp-quote-admin__totals-grid">';
            echo self::renderStatCard(__('Regels', 'sbdp'), (string) count($lines));
            echo self::renderStatCard(__('Geprijsde regels', 'sbdp'), count($lines) > 0 ? sprintf('%d / %d', (int) ($lineSummary['priced_lines'] ?? 0), count($lines)) : '0 / 0');
            echo self::renderStatCard(__('Snapshot subtotaal', 'sbdp'), (string) ($lineSummary['subtotal_label'] ?? __('Nog niet bepaald', 'sbdp')));
            echo self::renderStatCard(__('BTW / definitief totaal', 'sbdp'), __('Volgt pas in Woo / execution', 'sbdp'));
            echo '</div>';
            echo '<p class="bsp-quote-admin__muted">' . esc_html__('Geen definitieve prijs- of beschikbaarheidszekerheid wordt hier verzonnen. Snapshot-waarden blijven expliciet gelabeld totdat de execution-laag ze bevestigt.', 'sbdp') . '</p>';
            echo '<div class="bsp-quote-admin__table-wrap"><table class="widefat striped bsp-quote-admin__lines-table"><thead><tr><th>#</th><th>' . esc_html__('Regel', 'sbdp') . '</th><th>' . esc_html__('Planning', 'sbdp') . '</th><th>' . esc_html__('Commercieel', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th></tr></thead><tbody>';
            if ($lines === array()) {
                echo '<tr><td colspan="5">' . esc_html__('Geen regels opgeslagen.', 'sbdp') . '</td></tr>';
            }
            foreach ($lines as $line) {
                $lineTotal = isset($line['line_total_snapshot']) ? (float) $line['line_total_snapshot'] : null;
                $unitAmount = isset($line['unit_amount_snapshot']) ? (float) $line['unit_amount_snapshot'] : null;
                $currency = (string) (($line['currency'] ?? '') ?: ($lineSummary['currency'] ?? 'EUR'));
                $scheduleBits = array_filter(array(
                    (string) ($line['service_date'] ?? ''),
                    trim((string) (($line['start_time'] ?? '') . ((isset($line['end_time']) && (string) $line['end_time'] !== '') ? ' - ' . (string) $line['end_time'] : ''))),
                ));
                $detailBits = array_filter(array(
                    (int) ($line['product_id'] ?? 0) > 0 ? sprintf(__('Product #%d', 'sbdp'), (int) $line['product_id']) : __('Nog niet gemapt op product', 'sbdp'),
                    (int) ($line['vendor_id'] ?? 0) > 0 ? sprintf(__('Leverancier #%d', 'sbdp'), (int) $line['vendor_id']) : '',
                    sprintf(__('Aantal %d', 'sbdp'), max(1, (int) ($line['quantity'] ?? 1))),
                    (int) ($line['participants'] ?? 0) > 0 ? sprintf(__('%d deelnemers', 'sbdp'), (int) $line['participants']) : __('Deelnemers nog open', 'sbdp'),
                ));
                echo '<tr>';
                echo '<td>' . esc_html((string) ($line['line_number'] ?? '')) . '</td>';
                echo '<td><strong>' . esc_html((string) ($line['title'] ?? '')) . '</strong><div class="bsp-quote-admin__cell-stack">';
                foreach ($detailBits as $detailBit) {
                    echo '<span class="bsp-quote-admin__muted">' . esc_html($detailBit) . '</span>';
                }
                echo '</div></td><td>';
                if ($scheduleBits === array()) {
                    echo '<span class="bsp-quote-admin__muted">' . esc_html__('Nog geen datum/tijd vastgelegd', 'sbdp') . '</span>';
                } else {
                    echo '<div class="bsp-quote-admin__cell-stack">';
                    foreach ($scheduleBits as $scheduleBit) {
                        echo '<span>' . esc_html($scheduleBit) . '</span>';
                    }
                    echo '</div>';
                }
                echo '</td><td><div class="bsp-quote-admin__cell-stack">';
                if ($lineTotal !== null) {
                    echo '<strong>' . esc_html(self::formatMoney($lineTotal, $currency)) . '</strong>';
                    if ($unitAmount !== null) {
                        echo '<span class="bsp-quote-admin__muted">' . esc_html(sprintf(__('Per stuk %s', 'sbdp'), self::formatMoney($unitAmount, $currency))) . '</span>';
                    }
                } else {
                    echo '<strong>' . esc_html__('Nog geen prijs-snapshot', 'sbdp') . '</strong>';
                }
                echo '<span class="bsp-quote-admin__muted">' . esc_html((string) ($line['pricing_mode'] ?? 'directional')) . '</span>';
                echo '</div></td><td>';
                echo self::renderInlineBadge((string) ($line['line_status'] ?? 'mapped'), self::statusBadgeClass((string) ($line['line_status'] ?? 'mapped')));
                echo self::renderInlineBadge((string) ($line['pricing_confidence'] ?? 'unknown'), self::confidenceBadgeClass((string) ($line['pricing_confidence'] ?? 'unknown')));
                echo self::renderInlineBadge((string) ($line['availability_confidence'] ?? 'unknown'), self::confidenceBadgeClass((string) ($line['availability_confidence'] ?? 'unknown')));
                echo '</td></tr>';
            }
            echo '</tbody></table></div></div></details>';

            // 3. Manual Inbound Logging
            echo '<details class="postbox bsp-quote-admin__panel bsp-quote-admin__panel--collapsible"><summary class="bsp-quote-admin__panel-toggle"><span><strong>' . esc_html__('Manual Inbound Logging', 'sbdp') . '</strong><small>' . esc_html__('Gebruik dit alleen voor handmatige mailbox-herstelacties of auditwerk.', 'sbdp') . '</small></span></summary><div class="bsp-quote-admin__panel-body">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form">';
            \wp_nonce_field('sbdp_quote_log_inbound_message');
            echo '<input type="hidden" name="action" value="sbdp_quote_log_inbound_message"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><label>' . esc_html__('Handmatig inbound reply loggen', 'sbdp') . '<input class="regular-text" type="text" name="from_name" placeholder="' . esc_attr__('Naam afzender', 'sbdp') . '"></label>';
            echo '<label>' . esc_html__('Afzender e-mail', 'sbdp') . '<input class="regular-text" type="email" name="from_email"></label>';
            echo '<label>' . esc_html__('Ontvanger e-mail', 'sbdp') . '<input class="regular-text" type="email" name="to_email" value="' . esc_attr((string) ($requester['email'] ?? '')) . '"></label>';
            echo '<label>' . esc_html__('Onderwerp', 'sbdp') . '<input class="regular-text" type="text" name="subject"></label>';
            echo '<label>' . esc_html__('Message-ID', 'sbdp') . '<input class="regular-text" type="text" name="message_id"></label>';
            echo '<label>' . esc_html__('In-Reply-To', 'sbdp') . '<input class="regular-text" type="text" name="in_reply_to"></label>';
            echo '<label>' . esc_html__('References', 'sbdp') . '<input class="regular-text" type="text" name="references"></label>';
            echo '<label>' . esc_html__('Berichttekst', 'sbdp') . '<textarea class="large-text" rows="5" name="body"></textarea></label>';
            echo '<button class="button" type="submit">' . esc_html__('Log klantreply', 'sbdp') . '</button></form></div></details>';

            // 4. Advanced Handoff
            echo '<details id="quote-handoff" class="postbox bsp-quote-admin__panel bsp-quote-admin__panel--muted bsp-quote-admin__panel--collapsible"><summary class="bsp-quote-admin__panel-toggle"><span><strong>' . esc_html__('Advanced Handoff', 'sbdp') . '</strong><small>' . esc_html__('Execution-voorbereiding en adapter payload actions.', 'sbdp') . '</small></span></summary><div class="bsp-quote-admin__panel-body">';
            echo '<div class="bsp-quote-admin__badge-row">';
            echo self::renderInlineBadge((string) ($quote['handoff_status'] ?? 'not_ready'), self::workflowBadgeClass((string) ($quote['handoff_status'] ?? 'not_ready')));
            if ($currentPayload !== array() && isset($currentPayload['ready_for_execution'])) {
                echo self::renderInlineBadge(! empty($currentPayload['ready_for_execution']) ? __('execution ready', 'sbdp') : __('execution blocked', 'sbdp'), ! empty($currentPayload['ready_for_execution']) ? 'is-good' : 'is-warn');
            }
            echo '</div>';
            echo '<div class="bsp-quote-admin__actions bsp-quote-admin__actions--stacked">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            \wp_nonce_field('sbdp_quote_mark_ready_for_resnapshot');
            echo '<input type="hidden" name="action" value="sbdp_quote_mark_ready_for_resnapshot"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button" type="submit">' . esc_html__('Markeer als ready_for_resnapshot', 'sbdp') . '</button></form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            \wp_nonce_field('sbdp_quote_prepare_resnapshot');
            echo '<input type="hidden" name="action" value="sbdp_quote_prepare_resnapshot"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button" type="submit">' . esc_html__('Bereid execution resnapshot voor', 'sbdp') . '</button></form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            \wp_nonce_field('sbdp_quote_build_handoff_package');
            echo '<input type="hidden" name="action" value="sbdp_quote_build_handoff_package"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button" type="submit">' . esc_html__('Bouw controlled handoff package', 'sbdp') . '</button></form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            \wp_nonce_field('sbdp_quote_build_execution_payload');
            echo '<input type="hidden" name="action" value="sbdp_quote_build_execution_payload"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button" type="submit">' . esc_html__('Bouw execution adapter payload', 'sbdp') . '</button></form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            \wp_nonce_field('sbdp_quote_validate_execution_payload');
            echo '<input type="hidden" name="action" value="sbdp_quote_validate_execution_payload"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button" type="submit">' . esc_html__('Valideer execution payload runtime', 'sbdp') . '</button></form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            \wp_nonce_field('sbdp_quote_build_execution_launch');
            echo '<input type="hidden" name="action" value="sbdp_quote_build_execution_launch"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-secondary" type="submit">' . esc_html__('Bouw execution launch payload', 'sbdp') . '</button></form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            \wp_nonce_field('sbdp_quote_hydrate_woo_cart');
            echo '<input type="hidden" name="action" value="sbdp_quote_hydrate_woo_cart"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-secondary" type="submit">' . esc_html__('Hydrate Woo cart', 'sbdp') . '</button></form>';
            echo '</div></div></details></div>';
        } else {
            echo '<p>' . esc_html__('Tab niet gevonden.', 'sbdp') . '</p>';
        }
        echo '</div></div>';
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed>|null        $currentVersion
     * @return array<string, mixed>
     */
    private static function summarizeQuoteLines(array $lines, ?array $currentVersion): array
    {
        $currency = 'EUR';
        $subtotal = 0.0;
        $pricedLines = 0;
        $totalLines = 0;
        $commercialAdjustments = self::resolveQuoteCommercialAdjustments($currentVersion);
        $discountAmount = (float) ($commercialAdjustments['discount_amount'] ?? 0.0);

        foreach ($lines as $line) {
            $totalLines++;
            if (! empty($line['currency']) && is_string($line['currency'])) {
                $currency = (string) $line['currency'];
            }
            if (! isset($line['line_total_snapshot']) || $line['line_total_snapshot'] === null || $line['line_total_snapshot'] === '') {
                continue;
            }

            $subtotal += (float) $line['line_total_snapshot'];
            $pricedLines++;
        }

        if ($currentVersion !== null && isset($currentVersion['handoff_payload_json']['totals']['currency']) && is_string($currentVersion['handoff_payload_json']['totals']['currency'])) {
            $currency = (string) $currentVersion['handoff_payload_json']['totals']['currency'];
        }

        if ($totalLines === 0) {
            $subtotalLabel = __('Nog niet bepaald', 'sbdp');
            $totalLabel = __('Nog niet bepaald', 'sbdp');
        } elseif ($pricedLines === 0) {
            $subtotalLabel = __('Prijs op aanvraag', 'sbdp');
            $totalLabel = __('Prijs op aanvraag', 'sbdp');
        } elseif ($pricedLines < $totalLines) {
            $subtotalLabel = sprintf(__('Deels geprijsd: %s', 'sbdp'), self::formatMoney($subtotal, $currency));
            $totalLabel = $subtotalLabel;
        } else {
            $total = max(0.0, $subtotal - $discountAmount);
            $subtotalLabel = self::formatMoney($subtotal, $currency);
            $totalLabel = self::formatMoney($total, $currency);
        }

        return array(
            'currency'       => $currency,
            'priced_lines'   => $pricedLines,
            'total_lines'     => $totalLines,
            'subtotal_label' => $subtotalLabel,
            'discount_amount' => $discountAmount,
            'discount_label' => (string) ($commercialAdjustments['discount_label'] ?? __('Korting', 'sbdp')),
            'total_label'    => $totalLabel,
        );
    }

    /**
     * @param array<string, mixed>|null $currentVersion
     * @return array<string, mixed>
     */
    private static function resolveQuoteCommercialAdjustments(?array $currentVersion): array
    {
        $pricingSnapshot = is_array($currentVersion['pricing_snapshot_json'] ?? null)
            ? $currentVersion['pricing_snapshot_json']
            : array();
        $adjustments = is_array($pricingSnapshot['commercial_adjustments'] ?? null)
            ? $pricingSnapshot['commercial_adjustments']
            : array();
        $discountAmount = isset($adjustments['discount_amount']) && is_numeric($adjustments['discount_amount'])
            ? max(0.0, round((float) $adjustments['discount_amount'], 2))
            : 0.0;
        $discountLabel = trim((string) ($adjustments['discount_label'] ?? __('Korting', 'sbdp')));

        return array(
            'type' => 'fixed_amount',
            'discount_amount' => $discountAmount,
            'discount_label' => $discountLabel !== '' ? $discountLabel : __('Korting', 'sbdp'),
            'currency' => trim((string) (($adjustments['currency'] ?? '') ?: 'EUR')),
        );
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed>|null $currentVersion
     * @param array<int, array<string, mixed>> $lines
     */
    private static function renderQuoteBuildWorkspace(int $quoteId, array $quote, ?array $request, ?array $currentVersion, array $lines): void
    {
        $catalog = self::loadOperationsCatalogProducts();
        $defaultDate = trim((string) ($request['preferred_date'] ?? ''));
        $defaultParticipants = max(0, (int) ($request['group_size'] ?? 0));
        $builderRows = array();
        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $builderRows[] = array(
                'source_line_number' => (int) ($line['line_number'] ?? ($index + 1)),
                'sort_order' => (int) ($line['sort_order'] ?? ($line['line_number'] ?? ($index + 1))),
                'title' => (string) ($line['title'] ?? ''),
                'product_id' => (int) ($line['product_id'] ?? 0),
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                'participants' => max(0, (int) ($line['participants'] ?? 0)),
                'service_date' => (string) ($line['service_date'] ?? ''),
                'proposed_start_time' => (string) ($line['proposed_start_time'] ?? ($line['start_time'] ?? '')),
                'proposed_end_time' => (string) ($line['proposed_end_time'] ?? ($line['end_time'] ?? '')),
                'duration_minutes' => (int) ($line['duration_minutes'] ?? 0),
                'selected_option_labels' => implode(', ', isset($line['selected_option_labels_json']) && is_array($line['selected_option_labels_json']) ? $line['selected_option_labels_json'] : array()),
                'validated_slot_label' => (string) ($line['validated_slot_label'] ?? ''),
                'vendor_id' => (string) ($line['vendor_id'] ?? ''),
                'resource_id' => (string) ($line['resource_id'] ?? ''),
                'pricing_mode' => (string) ($line['pricing_mode'] ?? 'directional'),
                'pricing_confidence' => (string) ($line['pricing_confidence'] ?? 'unknown'),
                'availability_confidence' => (string) ($line['availability_confidence'] ?? 'unknown'),
                'unit_amount_snapshot' => (string) ($line['unit_amount_snapshot'] ?? ''),
                'line_total_snapshot' => (string) ($line['line_total_snapshot'] ?? ''),
                'currency' => (string) (($line['currency'] ?? '') ?: 'EUR'),
                'tax_class' => (string) ($line['tax_class'] ?? ''),
                'pricing_snapshot_json' => isset($line['pricing_snapshot_json']) && is_array($line['pricing_snapshot_json']) ? $line['pricing_snapshot_json'] : array(),
                'availability_snapshot_json' => isset($line['availability_snapshot_json']) && is_array($line['availability_snapshot_json']) ? $line['availability_snapshot_json'] : array(),
                'mapping_notes' => (string) ($line['mapping_notes'] ?? ''),
                'external_label' => (string) ($line['external_label'] ?? ''),
                'is_optional' => ! empty($line['is_optional']) ? 1 : 0,
                'position_group' => (string) ($line['position_group'] ?? ''),
            );
        }

        if ($builderRows === array()) {
            $builderRows[] = array(
                'source_line_number' => 0,
                'sort_order' => 1,
                'title' => '',
                'product_id' => 0,
                'quantity' => 1,
                'participants' => $defaultParticipants,
                'service_date' => $defaultDate,
                'proposed_start_time' => '',
                'proposed_end_time' => '',
                'duration_minutes' => 0,
                'selected_option_labels' => '',
                'validated_slot_label' => '',
                'vendor_id' => '',
                'resource_id' => '',
                'pricing_mode' => 'directional',
                'pricing_confidence' => 'unknown',
                'availability_confidence' => 'unknown',
                'unit_amount_snapshot' => '',
                'line_total_snapshot' => '',
                'currency' => 'EUR',
                'tax_class' => '',
                'pricing_snapshot_json' => array(),
                'availability_snapshot_json' => array(),
                'mapping_notes' => '',
                'external_label' => '',
                'is_optional' => 0,
                'position_group' => '',
            );
        }

        $versionLabel = $currentVersion !== null ? sprintf('#%d', (int) ($currentVersion['version_number'] ?? 1)) : __('Nog geen versie', 'sbdp');
        $frozenHint = ((string) ($quote['review_status'] ?? 'not_started') === 'approved' || (string) ($quote['review_status'] ?? 'not_started') === 'pending_review' || (string) ($quote['send_status'] ?? 'not_ready') !== 'not_ready')
            ? __('Deze actieve versie is commercieel bevroren. Opslaan maakt een nieuwe draftversie aan.', 'sbdp')
            : __('Je werkt direct op de actieve draftversie totdat review of verzending de versie bevriest.', 'sbdp');
        $availabilitySlotsUrl = function_exists('rest_url') ? (string) rest_url('sbdp/v1/availability/slots') : '';
        $restNonce = function_exists('wp_create_nonce') ? (string) wp_create_nonce('wp_rest') : '';
        $commercialAdjustments = self::resolveQuoteCommercialAdjustments($currentVersion);
        $discountAmount = (float) ($commercialAdjustments['discount_amount'] ?? 0.0);
        $discountLabel = (string) ($commercialAdjustments['discount_label'] ?? __('Korting', 'sbdp'));
        $discountCurrency = (string) ($commercialAdjustments['currency'] ?? 'EUR');

        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><div><h3>' . esc_html__('Dagprogramma bouwen', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Kies activiteit, datum, aantal personen en daarna een beschikbaar tijdslot. Prijs en beschikbaarheid worden vóór verzenden gecontroleerd.', 'sbdp') . '</p></div></div><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--operator"><strong>' . esc_html(sprintf(__('Werkversie %s', 'sbdp'), $versionLabel)) . '</strong><p>' . esc_html($frozenHint) . '</p></div>';
        echo '<div class="bsp-quote-admin__builder-intake"><div><span class="bsp-quote-admin__field-label">' . esc_html__('Eventdatum', 'sbdp') . '</span><strong>' . esc_html($defaultDate !== '' ? $defaultDate : __('Nog niet ingevuld', 'sbdp')) . '</strong></div><div><span class="bsp-quote-admin__field-label">' . esc_html__('Aantal personen', 'sbdp') . '</span><strong>' . esc_html($defaultParticipants > 0 ? sprintf(__('%d personen', 'sbdp'), $defaultParticipants) : __('Nog open', 'sbdp')) . '</strong></div><div><span class="bsp-quote-admin__field-label">' . esc_html__('Normale flow', 'sbdp') . '</span><strong>' . esc_html__('Activiteit -> datum -> tijdslot -> opslaan', 'sbdp') . '</strong></div></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__builder-form" data-builder-slots-url="' . esc_url($availabilitySlotsUrl) . '" data-builder-rest-nonce="' . esc_attr($restNonce) . '">';
        echo wp_nonce_field('sbdp_quote_save_operations_draft', '_wpnonce', true, false);
        echo '<input type="hidden" name="action" value="sbdp_quote_save_operations_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
        echo '<div class="bsp-quote-admin__actions bsp-quote-admin__actions--stacked"><button class="button button-primary" type="submit">' . esc_html__('Bewaar dagprogramma', 'sbdp') . '</button><button class="button button-secondary" type="submit" name="create_new_version" value="1">' . esc_html__('Maak nieuwe versie', 'sbdp') . '</button><button class="button button-secondary bsp-quote-admin__builder-add" type="button">' . esc_html__('Voeg programmaregel toe', 'sbdp') . '</button></div>';
        echo '<p class="bsp-quote-admin__muted bsp-quote-admin__proposal-copy">' . esc_html__('Sleep regels in volgorde. Beschikbaarheid en prijs blijven onder voorbehoud totdat ze zijn bevestigd.', 'sbdp') . '</p>';
        echo '<div class="bsp-quote-admin__quote-total-card" data-builder-commercial-summary><div><span class="bsp-quote-admin__field-label">' . esc_html__('Subtotaal', 'sbdp') . '</span><strong data-builder-summary-subtotal>EUR 0,00</strong></div><label><span class="bsp-quote-admin__field-label">' . esc_html__('Korting', 'sbdp') . '</span><input type="number" min="0" step="0.01" name="commercial_adjustments[discount_amount]" value="' . esc_attr($discountAmount > 0.0 ? number_format($discountAmount, 2, '.', '') : '') . '" placeholder="0.00" data-builder-discount-amount><input type="text" name="commercial_adjustments[discount_label]" value="' . esc_attr($discountLabel) . '" data-builder-discount-label><input type="hidden" name="commercial_adjustments[currency]" value="' . esc_attr($discountCurrency) . '" data-builder-discount-currency><strong data-builder-summary-discount>Niet toegepast</strong></label><div><span class="bsp-quote-admin__field-label">' . esc_html__('Totale offerteprijs', 'sbdp') . '</span><strong data-builder-summary-total>EUR 0,00</strong></div></div>';
        echo '<div class="bsp-quote-admin__builder-list" data-builder-list>';
        foreach (array_values($builderRows) as $index => $builderRow) {
            echo self::renderQuoteBuildRow($index, $builderRow, $catalog);
        }
        echo '</div>';
        echo '<template id="bsp-quote-builder-row-template">' . self::renderQuoteBuildRow('__INDEX__', array(
            'source_line_number' => 0,
            'sort_order' => 0,
            'title' => '',
            'product_id' => 0,
            'quantity' => 1,
            'participants' => $defaultParticipants,
            'service_date' => $defaultDate,
            'proposed_start_time' => '',
            'proposed_end_time' => '',
            'duration_minutes' => 0,
            'selected_option_labels' => '',
            'validated_slot_label' => '',
            'vendor_id' => '',
            'resource_id' => '',
            'pricing_mode' => 'directional',
            'pricing_confidence' => 'unknown',
            'availability_confidence' => 'unknown',
            'unit_amount_snapshot' => '',
            'line_total_snapshot' => '',
            'currency' => 'EUR',
            'tax_class' => '',
            'pricing_snapshot_json' => array(),
            'availability_snapshot_json' => array(),
            'mapping_notes' => '',
            'external_label' => '',
            'is_optional' => 0,
            'position_group' => '',
        ), $catalog) . '</template>';
        echo '<script type="application/json" id="bsp-quote-builder-catalog">' . esc_html((string) \wp_json_encode($catalog)) . '</script>';
        echo '</form></div></section>';
        self::renderQuoteBuildScript();
    }

    /**
     * @param int|string $index
     * @param array<string, mixed> $line
     * @param array<int, array<string, mixed>> $catalog
     */
    private static function renderQuoteBuildRow($index, array $line, array $catalog): string
    {
        $productId = (int) ($line['product_id'] ?? 0);
        $productOptions = '<option value="">' . esc_html__('Kies product', 'sbdp') . '</option>';
        foreach ($catalog as $product) {
            $optionValue = (int) ($product['id'] ?? 0);
            $selected = $productId === $optionValue ? ' selected' : '';
            $productOptions .= '<option value="' . esc_attr((string) $optionValue) . '"' . $selected
                . ' data-title="' . esc_attr((string) ($product['title'] ?? '')) . '"'
                . ' data-duration="' . esc_attr((string) ((int) ($product['duration_minutes'] ?? 0))) . '"'
                . ' data-unit-amount="' . esc_attr((string) ($product['unit_amount_snapshot'] ?? '')) . '"'
                . ' data-currency="' . esc_attr((string) ($product['currency'] ?? 'EUR')) . '">'
                . esc_html((string) ($product['title'] ?? ''))
                . '</option>';
        }

        $indexAttr = esc_attr((string) $index);
        $sortOrder = max(1, (int) ($line['sort_order'] ?? 1));
        $priceSnapshot = self::quoteBuilderPriceLabel($line);
        $pricingConfidence = (string) ($line['pricing_confidence'] ?? 'unknown');
        $availabilityConfidence = (string) ($line['availability_confidence'] ?? 'unknown');
        $pricingLabel = self::quoteBuilderPricingLabel($pricingConfidence);
        $availabilityLabel = self::quoteBuilderAvailabilityLabel($availabilityConfidence);
        $rowTitle = trim((string) ($line['title'] ?? ''));
        if ($rowTitle === '') {
            foreach ($catalog as $product) {
                if ((int) ($product['id'] ?? 0) === $productId) {
                    $rowTitle = (string) ($product['title'] ?? '');
                    break;
                }
            }
        }
        if ($rowTitle === '') {
            $rowTitle = $productId > 0 ? __('Activiteit zonder titel', 'sbdp') : __('Maatwerkregel', 'sbdp');
        }
        $lineTypeLabel = $productId > 0 ? __('Activiteit', 'sbdp') : __('Maatwerkregel', 'sbdp');
        $dateValue = (string) ($line['service_date'] ?? '');
        $startValue = (string) ($line['proposed_start_time'] ?? '');
        $endValue = (string) ($line['proposed_end_time'] ?? '');
        $slotLabel = self::quoteBuilderSlotLabel($startValue, $endValue, (string) ($line['validated_slot_label'] ?? ''));

        $html = '<article class="bsp-quote-admin__builder-row" data-builder-row draggable="true">';
        $html .= '<div class="bsp-quote-admin__builder-row-top"><div class="bsp-quote-admin__builder-row-order"><button type="button" class="button-link bsp-quote-admin__builder-handle" aria-label="' . esc_attr__('Versleep programmaregel', 'sbdp') . '">≡</button><div><strong data-builder-order-label>' . esc_html(sprintf(__('Stop %d', 'sbdp'), $sortOrder)) . '</strong><span data-builder-card-title>' . esc_html($rowTitle) . '</span></div></div><div class="bsp-quote-admin__actions"><button type="button" class="button-link bsp-quote-admin__builder-duplicate">' . esc_html__('Dupliceer', 'sbdp') . '</button><button type="button" class="button-link bsp-quote-admin__builder-remove">' . esc_html__('Verwijder', 'sbdp') . '</button></div></div>';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][source_line_number]" value="' . esc_attr((string) ($line['source_line_number'] ?? 0)) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][sort_order]" value="' . esc_attr((string) $sortOrder) . '" data-builder-sort-order>';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][vendor_id]" value="' . esc_attr((string) ($line['vendor_id'] ?? '')) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][resource_id]" value="' . esc_attr((string) ($line['resource_id'] ?? '')) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][pricing_mode]" value="' . esc_attr((string) ($line['pricing_mode'] ?? 'directional')) . '" data-builder-pricing-mode>';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][pricing_confidence]" value="' . esc_attr((string) ($line['pricing_confidence'] ?? 'unknown')) . '" data-builder-pricing-confidence>';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][availability_confidence]" value="' . esc_attr((string) ($line['availability_confidence'] ?? 'unknown')) . '" data-builder-availability-confidence>';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][currency]" value="' . esc_attr((string) (($line['currency'] ?? '') ?: 'EUR')) . '" data-builder-currency>';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][tax_class]" value="' . esc_attr((string) ($line['tax_class'] ?? '')) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][pricing_snapshot_json]" value="' . esc_attr((string) \wp_json_encode($line['pricing_snapshot_json'] ?? array())) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][availability_snapshot_json]" value="' . esc_attr((string) \wp_json_encode($line['availability_snapshot_json'] ?? array())) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][mapping_notes]" value="' . esc_attr((string) ($line['mapping_notes'] ?? '')) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][external_label]" value="' . esc_attr((string) ($line['external_label'] ?? '')) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][position_group]" value="' . esc_attr((string) ($line['position_group'] ?? '')) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][is_optional]" value="' . esc_attr(! empty($line['is_optional']) ? '1' : '0') . '">';
        $html .= '<div class="bsp-quote-admin__builder-card-status">';
        $html .= self::renderInlineBadge($lineTypeLabel, $productId > 0 ? 'is-neutral' : 'is-warn');
        $html .= self::renderInlineBadge($pricingLabel, self::confidenceBadgeClass($pricingConfidence));
        $html .= self::renderInlineBadge($availabilityLabel, self::confidenceBadgeClass($availabilityConfidence));
        $html .= '</div>';
        $html .= '<div class="bsp-quote-admin__builder-grid bsp-quote-admin__builder-grid--primary">';
        $html .= '<label><span>' . esc_html__('Activiteit', 'sbdp') . '</span><select name="lines[' . $indexAttr . '][product_id]" data-builder-product-select>' . $productOptions . '</select></label>';
        $html .= '<label><span>' . esc_html__('Naam voor klant', 'sbdp') . '</span><input type="text" name="lines[' . $indexAttr . '][title]" value="' . esc_attr((string) ($line['title'] ?? '')) . '" placeholder="' . esc_attr__('bijv. Bierproeverij', 'sbdp') . '" data-builder-title></label>';
        $html .= '<label><span>' . esc_html__('Datum', 'sbdp') . '</span><input type="date" name="lines[' . $indexAttr . '][service_date]" value="' . esc_attr($dateValue) . '" data-builder-date></label>';
        $html .= '<label><span>' . esc_html__('Aantal personen', 'sbdp') . '</span><input type="number" min="0" step="1" name="lines[' . $indexAttr . '][participants]" value="' . esc_attr((string) ((int) ($line['participants'] ?? 0))) . '" data-builder-participants></label>';
        $html .= '</div>';
        $html .= '<div class="bsp-quote-admin__builder-grid bsp-quote-admin__builder-grid--commercial">';
        $html .= '<label><span>' . esc_html__('Prijs p.p.', 'sbdp') . '</span><input type="number" min="0" step="0.01" name="lines[' . $indexAttr . '][unit_amount_snapshot]" value="' . esc_attr((string) ($line['unit_amount_snapshot'] ?? '')) . '" data-builder-unit-amount></label>';
        $html .= '<label><span>' . esc_html__('Totaal regel', 'sbdp') . '</span><input type="number" min="0" step="0.01" name="lines[' . $indexAttr . '][line_total_snapshot]" value="' . esc_attr((string) ($line['line_total_snapshot'] ?? '')) . '" data-builder-line-total></label>';
        $html .= '<div class="bsp-quote-admin__builder-commercial-note"><span class="bsp-quote-admin__field-label">' . esc_html__('Prijs', 'sbdp') . '</span><strong data-builder-line-total-label>' . esc_html($priceSnapshot) . '</strong><p class="bsp-quote-admin__muted">' . esc_html__('Controleer prijs voor verzenden. WooCommerce rekent btw, betaling en order definitief af.', 'sbdp') . '</p></div>';
        $html .= '</div>';
        $html .= '<div class="bsp-quote-admin__slot-picker" data-builder-slot-picker>';
        $html .= '<div class="bsp-quote-admin__slot-picker-head"><div><span class="bsp-quote-admin__field-label">' . esc_html__('Beschikbare tijden', 'sbdp') . '</span><strong data-builder-selected-slot>' . esc_html($slotLabel !== '' ? $slotLabel : __('Kies een tijdslot', 'sbdp')) . '</strong><p class="bsp-quote-admin__muted">' . esc_html__('Alleen tijden die passen bij activiteit, datum en aantal personen worden getoond. Definitieve bevestiging gebeurt vóór verzenden.', 'sbdp') . '</p></div><div class="bsp-quote-admin__slot-actions"><button type="button" class="button button-secondary" data-builder-refresh-slots>' . esc_html__('Toon tijden', 'sbdp') . '</button><label><input type="checkbox" data-builder-show-unavailable> ' . esc_html__('Toon ook niet-beschikbare tijden', 'sbdp') . '</label></div></div>';
        $html .= '<div class="bsp-quote-admin__slot-list" data-builder-slot-list>';
        if ($slotLabel !== '') {
            $html .= '<button type="button" class="bsp-quote-admin__slot-pill is-selected" data-builder-slot-start="' . esc_attr($startValue) . '" data-builder-slot-end="' . esc_attr($endValue) . '">' . esc_html($slotLabel) . '</button>';
        } else {
            $html .= '<span class="bsp-quote-admin__slot-empty">' . esc_html__('Kies eerst activiteit, datum en aantal personen.', 'sbdp') . '</span>';
        }
        $html .= '</div></div>';
        $html .= '<div class="bsp-quote-admin__builder-meta"><span class="bsp-quote-admin__field-label">' . esc_html__('Prijsstatus', 'sbdp') . '</span><strong data-builder-price-summary>' . esc_html($priceSnapshot) . '</strong><span class="bsp-quote-admin__muted"><span data-builder-pricing-label>' . esc_html($pricingLabel) . '</span> | <span data-builder-availability-label>' . esc_html($availabilityLabel) . '</span></span></div>';
        $html .= '<details class="bsp-quote-admin__builder-advanced"><summary>' . esc_html__('Geavanceerd / maatwerk', 'sbdp') . '</summary><div class="bsp-quote-admin__builder-grid">';
        $html .= '<label><span>' . esc_html__('Extra opties voor klant', 'sbdp') . '</span><input type="text" name="lines[' . $indexAttr . '][selected_option_labels]" value="' . esc_attr((string) ($line['selected_option_labels'] ?? '')) . '" placeholder="' . esc_attr__('bijv. lunch aan boord, open bar', 'sbdp') . '"></label>';
        $html .= '<label><span>' . esc_html__('Interne slotnaam', 'sbdp') . '</span><input type="text" name="lines[' . $indexAttr . '][validated_slot_label]" value="' . esc_attr((string) ($line['validated_slot_label'] ?? '')) . '" placeholder="' . esc_attr__('wordt automatisch gevuld bij slotkeuze', 'sbdp') . '" data-builder-slot-label></label>';
        $html .= '<label><span>' . esc_html__('Starttijd handmatig', 'sbdp') . '</span><input type="time" name="lines[' . $indexAttr . '][proposed_start_time]" value="' . esc_attr($startValue) . '" data-builder-start-time></label>';
        $html .= '<label><span>' . esc_html__('Eindtijd handmatig', 'sbdp') . '</span><input type="time" name="lines[' . $indexAttr . '][proposed_end_time]" value="' . esc_attr($endValue) . '" data-builder-end-time></label>';
        $html .= '<label><span>' . esc_html__('Duur in minuten', 'sbdp') . '</span><input type="number" min="0" step="5" name="lines[' . $indexAttr . '][duration_minutes]" value="' . esc_attr((string) ((int) ($line['duration_minutes'] ?? 0))) . '" data-builder-duration></label>';
        $html .= '<label><span>' . esc_html__('Aantal regels', 'sbdp') . '</span><input type="number" min="1" step="1" name="lines[' . $indexAttr . '][quantity]" value="' . esc_attr((string) ((int) ($line['quantity'] ?? 1))) . '"></label>';
        $html .= '</div><p class="bsp-quote-admin__muted">' . esc_html__('Maatwerkregels of handmatig aangepaste tijden blijven onder voorbehoud en kunnen niet worden verzonden totdat prijs en beschikbaarheid bevestigd zijn.', 'sbdp') . '</p></details>';
        $html .= '</article>';

        return $html;
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function quoteBuilderPriceLabel(array $line): string
    {
        $currency = (string) (($line['currency'] ?? '') ?: 'EUR');
        if ((string) ($line['line_total_snapshot'] ?? '') !== '') {
            $amount = (float) ($line['line_total_snapshot'] ?? 0);
            if ($amount <= 0.0) {
                return __('Inbegrepen / geen meerprijs', 'sbdp');
            }

            return sprintf(__('Totaal %s', 'sbdp'), self::formatMoney($amount, $currency));
        }

        if ((string) ($line['unit_amount_snapshot'] ?? '') !== '') {
            $amount = (float) ($line['unit_amount_snapshot'] ?? 0);
            if ($amount <= 0.0) {
                return __('Inbegrepen / geen meerprijs', 'sbdp');
            }

            return sprintf(__('Prijs per persoon %s', 'sbdp'), self::formatMoney($amount, $currency));
        }

        return __('Prijs nog niet bevestigd', 'sbdp');
    }

    private static function quoteBuilderPricingLabel(string $confidence): string
    {
        return match ($confidence) {
            'execution_verified' => __('Prijs bevestigd', 'sbdp'),
            'snapshot' => __('Prijs vastgelegd', 'sbdp'),
            'projected', 'directional' => __('Prijs nog controleren', 'sbdp'),
            default => __('Prijs nog niet bevestigd', 'sbdp'),
        };
    }

    private static function quoteBuilderAvailabilityLabel(string $confidence): string
    {
        return match ($confidence) {
            'confirmed' => __('Beschikbaarheid bevestigd', 'sbdp'),
            'snapshot', 'projected' => __('Beschikbaarheid nog controleren', 'sbdp'),
            default => __('Beschikbaarheid nog niet bevestigd', 'sbdp'),
        };
    }

    private static function quoteBuilderSlotLabel(string $start, string $end, string $validatedSlotLabel): string
    {
        $validatedSlotLabel = trim($validatedSlotLabel);
        if ($validatedSlotLabel !== '') {
            return $validatedSlotLabel;
        }

        $start = trim($start);
        $end = trim($end);
        if ($start !== '' && $end !== '') {
            return $start . ' - ' . $end;
        }

        if ($start !== '') {
            return $start;
        }

        return '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadOperationsCatalogProducts(): array
    {
        $catalog = array();

        if (class_exists('\BSP\DayPlanner\Service\ProductCatalogService')) {
            $service = new \BSP\DayPlanner\Service\ProductCatalogService();
            $items = $service->listProducts(array('include_arrangements' => true));
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $productId = (int) ($item['product_id'] ?? ($item['id'] ?? 0));
                    if ($productId <= 0) {
                        continue;
                    }

                    $title = trim((string) ($item['name'] ?? ($item['title'] ?? '')));
                    if ($title === '') {
                        continue;
                    }

                    $catalog[] = array(
                        'id' => $productId,
                        'title' => $title,
                        'duration_minutes' => max(0, (int) ($item['duration_minutes'] ?? ($item['duration']['minutes'] ?? 0))),
                        'unit_amount_snapshot' => isset($item['price_pp']) && is_numeric($item['price_pp']) ? (string) ((float) $item['price_pp']) : '',
                        'currency' => (string) (($item['pricing']['currency'] ?? $item['currency'] ?? 'EUR') ?: 'EUR'),
                    );
                }
            }
        }

        if ($catalog === array() && function_exists('wc_get_products')) {
            $products = wc_get_products(array(
                'status' => 'publish',
                'limit' => 50,
                'orderby' => 'title',
                'order' => 'ASC',
            ));
            if (is_array($products)) {
                foreach ($products as $product) {
                    if (! is_object($product) || ! method_exists($product, 'get_id')) {
                        continue;
                    }

                    $catalog[] = array(
                        'id' => (int) $product->get_id(),
                        'title' => (string) $product->get_name(),
                        'duration_minutes' => 0,
                        'unit_amount_snapshot' => method_exists($product, 'get_price') ? (string) $product->get_price() : '',
                        'currency' => 'EUR',
                    );
                }
            }
        }

        usort($catalog, static function (array $left, array $right): int {
            return strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        });

        return $catalog;
    }

    private static function renderQuoteBuildScript(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }

        $rendered = true;

        echo '<script>
        (function(){
            const list = document.querySelector("[data-builder-list]");
            const template = document.getElementById("bsp-quote-builder-row-template");
            if (!list || !template) { return; }
            const form = list.closest("form");
            const slotsUrl = form ? (form.dataset.builderSlotsUrl || "") : "";
            const restNonce = form ? (form.dataset.builderRestNonce || "") : "";
            let dragSource = null;
            const pricingLabels = {
                execution_verified: "Prijs bevestigd",
                snapshot: "Prijs vastgelegd",
                projected: "Prijs nog controleren",
                directional: "Prijs nog controleren",
                unknown: "Prijs nog niet bevestigd"
            };
            const availabilityLabels = {
                confirmed: "Beschikbaarheid bevestigd",
                snapshot: "Beschikbaarheid nog controleren",
                projected: "Beschikbaarheid nog controleren",
                unknown: "Beschikbaarheid nog niet bevestigd"
            };

            const formatPriceLabel = (currency, amount) => {
                if (amount === "" || amount === null || typeof amount === "undefined") {
                    return "Prijs nog niet bevestigd";
                }
                const parsed = Number(amount);
                if (!Number.isFinite(parsed)) {
                    return "Prijs nog niet bevestigd";
                }
                if (parsed <= 0) {
                    return "Inbegrepen / geen meerprijs";
                }
                return "Prijs per persoon " + (currency || "EUR") + " " + parsed.toLocaleString("nl-NL", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            };

            const parseAmount = (value) => {
                const parsed = Number(String(value || "").replace(",", "."));
                return Number.isFinite(parsed) ? parsed : null;
            };

            const formatMoney = (currency, amount) => {
                return (currency || "EUR") + " " + amount.toLocaleString("nl-NL", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            };

            const refreshCommercialSummary = () => {
                const rows = Array.from(list.querySelectorAll("[data-builder-row]"));
                const currency = rows.find((row) => row.querySelector("[data-builder-currency]")?.value)?.querySelector("[data-builder-currency]")?.value || "EUR";
                let subtotal = 0;
                let priced = 0;
                rows.forEach((row) => {
                    const totalInput = row.querySelector("[data-builder-line-total]");
                    const amount = totalInput ? parseAmount(totalInput.value) : null;
                    if (amount !== null) {
                        subtotal += amount;
                        priced += 1;
                    }
                });
                const discountInput = form ? form.querySelector("[data-builder-discount-amount]") : null;
                const discountLabelInput = form ? form.querySelector("[data-builder-discount-label]") : null;
                const discount = Math.max(0, parseAmount(discountInput ? discountInput.value : "") || 0);
                const discountText = discountLabelInput && discountLabelInput.value.trim() ? discountLabelInput.value.trim() : "Korting";
                const total = Math.max(0, subtotal - discount);
                const subtotalLabel = priced > 0 ? formatMoney(currency, subtotal) : "Prijs op aanvraag";
                const finalTotalLabel = formatMoney(currency, total);
                const totalLabel = priced === rows.length && rows.length > 0 ? finalTotalLabel : (priced > 0 ? "Deels geprijsd: " + subtotalLabel : "Prijs op aanvraag");
                const subtotalNode = document.querySelector("[data-builder-summary-subtotal]");
                const totalNode = document.querySelector("[data-builder-summary-total]");
                const discountNode = document.querySelector("[data-builder-summary-discount]");
                if (subtotalNode) { subtotalNode.textContent = subtotalLabel; }
                if (totalNode) { totalNode.textContent = totalLabel; }
                if (discountNode) { discountNode.textContent = discount > 0 ? (discountText + " -" + formatMoney(currency, discount)) : "Niet toegepast"; }
            };

            const updateLinePricePresentation = (row) => {
                const currency = row.querySelector("[data-builder-currency]")?.value || "EUR";
                const totalInput = row.querySelector("[data-builder-line-total]");
                const unitInput = row.querySelector("[data-builder-unit-amount]");
                const total = totalInput ? parseAmount(totalInput.value) : null;
                const unit = unitInput ? parseAmount(unitInput.value) : null;
                const label = total !== null ? ("Totaal " + formatMoney(currency, total)) : (unit !== null ? formatPriceLabel(currency, unit) : "Prijs nog niet bevestigd");
                const priceSummary = row.querySelector("[data-builder-price-summary]");
                const lineTotalLabel = row.querySelector("[data-builder-line-total-label]");
                if (priceSummary) { priceSummary.textContent = label; }
                if (lineTotalLabel) { lineTotalLabel.textContent = label; }
                refreshCommercialSummary();
            };

            const calculateLineTotalFromUnit = (row, force) => {
                const unitInput = row.querySelector("[data-builder-unit-amount]");
                const totalInput = row.querySelector("[data-builder-line-total]");
                const participantsInput = row.querySelector("[data-builder-participants]");
                if (!unitInput || !totalInput) { return; }
                if (!force && totalInput.value !== "") { return; }
                const unit = parseAmount(unitInput.value);
                const participants = participantsInput ? Number(participantsInput.value || 0) : 0;
                if (unit === null || !Number.isFinite(participants) || participants <= 0) { return; }
                totalInput.value = (unit * participants).toFixed(2);
                updateLinePricePresentation(row);
            };

            const timeToMinutes = (value) => {
                const match = String(value || "").match(/^(\\d{2}):(\\d{2})$/);
                if (!match) { return null; }
                return (Number(match[1]) * 60) + Number(match[2]);
            };

            const minutesToTime = (minutes) => {
                const bounded = Math.max(0, Math.min(24 * 60, minutes));
                const hours = Math.floor(bounded / 60);
                const mins = bounded % 60;
                return String(hours).padStart(2, "0") + ":" + String(mins).padStart(2, "0");
            };

            const getSelectedDuration = (row) => {
                const productSelect = row.querySelector("[data-builder-product-select]");
                const option = productSelect && productSelect.selectedOptions.length ? productSelect.selectedOptions[0] : null;
                const optionDuration = option ? Number(option.dataset.duration || 0) : 0;
                if (Number.isFinite(optionDuration) && optionDuration > 0) {
                    return Math.round(optionDuration);
                }
                const durationInput = row.querySelector("[data-builder-duration]");
                const inputDuration = durationInput ? Number(durationInput.value || 0) : 0;
                return Number.isFinite(inputDuration) && inputDuration > 0 ? Math.round(inputDuration) : 0;
            };

            const mergeIntervals = (intervals) => {
                const merged = [];
                for (const interval of intervals) {
                    const last = merged[merged.length - 1];
                    if (last && interval.start <= last.end) {
                        last.end = Math.max(last.end, interval.end);
                    } else {
                        merged.push({ start: interval.start, end: interval.end });
                    }
                }
                return merged;
            };

            const inferSlotStep = (intervals) => {
                const starts = intervals.map((slot) => slot.start).sort((left, right) => left - right);
                const steps = [];
                for (let index = 1; index < starts.length; index += 1) {
                    const delta = starts[index] - starts[index - 1];
                    if (delta > 0) { steps.push(delta); }
                }
                intervals.forEach((slot) => {
                    const duration = slot.end - slot.start;
                    if (duration > 0) { steps.push(duration); }
                });
                const usable = steps.filter((step) => step > 0 && step <= 120);
                return usable.length ? Math.max(5, Math.min(...usable)) : 30;
            };

            const normalizeSlotsForDuration = (row, slots) => {
                if (!Array.isArray(slots) || slots.length === 0) { return []; }
                const duration = getSelectedDuration(row);
                if (duration <= 0) { return slots; }
                const intervals = slots
                    .map((slot) => ({
                        start: timeToMinutes(slot.start || ""),
                        end: timeToMinutes(slot.end || "")
                    }))
                    .filter((slot) => slot.start !== null && slot.end !== null && slot.end > slot.start)
                    .sort((left, right) => left.start - right.start);
                const seen = new Set();
                const candidates = [];
                const step = inferSlotStep(intervals);
                mergeIntervals(intervals).forEach((interval) => {
                    for (let cursor = interval.start; cursor + duration <= interval.end; cursor += step) {
                        if (seen.has(cursor)) { continue; }
                        seen.add(cursor);
                        candidates.push({
                            start: minutesToTime(cursor),
                            end: minutesToTime(cursor + duration),
                            duration_minutes: duration
                        });
                    }
                });
                return candidates;
            };

            const slotText = (start, end) => {
                if (start && end) { return start + " - " + end; }
                return start || "Tijdslot";
            };

            const renderSlotMessage = (row, message) => {
                const listNode = row.querySelector("[data-builder-slot-list]");
                if (!listNode) { return; }
                listNode.innerHTML = "";
                const node = document.createElement("span");
                node.className = "bsp-quote-admin__slot-empty";
                node.textContent = message;
                listNode.appendChild(node);
            };

            const refreshRowState = (row) => {
                const titleInput = row.querySelector("[data-builder-title]");
                const productSelect = row.querySelector("[data-builder-product-select]");
                const titleLabel = row.querySelector("[data-builder-card-title]");
                const pricingInput = row.querySelector("[data-builder-pricing-confidence]");
                const availabilityInput = row.querySelector("[data-builder-availability-confidence]");
                const pricingLabel = row.querySelector("[data-builder-pricing-label]");
                const availabilityLabel = row.querySelector("[data-builder-availability-label]");
                const selectedSlot = row.querySelector("[data-builder-selected-slot]");
                const slotInput = row.querySelector("[data-builder-slot-label]");
                const startInput = row.querySelector("[data-builder-start-time]");
                const endInput = row.querySelector("[data-builder-end-time]");
                if (titleLabel) {
                    const selectedTitle = productSelect && productSelect.selectedOptions.length ? (productSelect.selectedOptions[0].dataset.title || "") : "";
                    titleLabel.textContent = (titleInput && titleInput.value.trim()) || selectedTitle || "Maatwerkregel";
                }
                if (selectedSlot) {
                    selectedSlot.textContent = (slotInput && slotInput.value.trim()) || slotText(startInput ? startInput.value : "", endInput ? endInput.value : "") || "Kies een tijdslot";
                }
                if (pricingLabel && pricingInput) {
                    pricingLabel.textContent = pricingLabels[pricingInput.value] || pricingLabels.unknown;
                }
                if (availabilityLabel && availabilityInput) {
                    availabilityLabel.textContent = availabilityLabels[availabilityInput.value] || availabilityLabels.unknown;
                }
                updateLinePricePresentation(row);
            };

            const setSelectedSlot = (row, start, end) => {
                const startInput = row.querySelector("[data-builder-start-time]");
                const endInput = row.querySelector("[data-builder-end-time]");
                const slotInput = row.querySelector("[data-builder-slot-label]");
                const durationInput = row.querySelector("[data-builder-duration]");
                const availabilityInput = row.querySelector("[data-builder-availability-confidence]");
                const selectedSlot = row.querySelector("[data-builder-selected-slot]");
                const label = slotText(start, end);
                if (startInput) { startInput.value = start || ""; }
                if (endInput) { endInput.value = end || ""; }
                if (slotInput) { slotInput.value = label; }
                if (selectedSlot) { selectedSlot.textContent = label; }
                if (durationInput && start && end) {
                    const startMinutes = timeToMinutes(start);
                    const endMinutes = timeToMinutes(end);
                    if (startMinutes !== null && endMinutes !== null && endMinutes > startMinutes) {
                        durationInput.value = String(endMinutes - startMinutes);
                    }
                }
                if (availabilityInput && availabilityInput.value === "unknown") {
                    availabilityInput.value = "projected";
                }
                refreshRowState(row);
            };

            const renderSlots = (row, slots) => {
                const listNode = row.querySelector("[data-builder-slot-list]");
                const showUnavailable = row.querySelector("[data-builder-show-unavailable]");
                const currentStart = row.querySelector("[data-builder-start-time]")?.value || "";
                const currentEnd = row.querySelector("[data-builder-end-time]")?.value || "";
                const durationMatchedSlots = normalizeSlotsForDuration(row, slots);
                if (!listNode) { return; }
                listNode.innerHTML = "";
                if (durationMatchedSlots.length === 0) {
                    const duration = getSelectedDuration(row);
                    renderSlotMessage(row, duration > 0 ? "Geen vrij tijdslot gevonden dat past bij de duur van deze activiteit." : "Geen vrij tijdslot gevonden. Gebruik maatwerk alleen als je prijs en beschikbaarheid daarna bevestigt.");
                    return;
                }
                let correctedCurrentSlot = null;
                durationMatchedSlots.forEach((slot) => {
                    const start = String(slot.start || "");
                    const end = String(slot.end || "");
                    if (!start) { return; }
                    if (start === currentStart && end !== currentEnd) {
                        correctedCurrentSlot = { start, end };
                    }
                    const button = document.createElement("button");
                    button.type = "button";
                    button.className = "bsp-quote-admin__slot-pill" + (start === currentStart && end === currentEnd ? " is-selected" : "");
                    button.textContent = slotText(start, end);
                    button.dataset.builderSlotStart = start;
                    button.dataset.builderSlotEnd = end;
                    button.addEventListener("click", () => {
                        Array.from(listNode.querySelectorAll(".bsp-quote-admin__slot-pill")).forEach((node) => node.classList.remove("is-selected"));
                        button.classList.add("is-selected");
                        setSelectedSlot(row, start, end);
                    });
                    listNode.appendChild(button);
                });
                if (correctedCurrentSlot) {
                    setSelectedSlot(row, correctedCurrentSlot.start, correctedCurrentSlot.end);
                    const selectedButton = listNode.querySelector("[data-builder-slot-start=\"" + correctedCurrentSlot.start + "\"]");
                    if (selectedButton) {
                        selectedButton.classList.add("is-selected");
                    }
                }
                if (showUnavailable && showUnavailable.checked) {
                    const note = document.createElement("span");
                    note.className = "bsp-quote-admin__slot-empty";
                    note.textContent = "Niet-passende of bezette tijden worden bewust niet aangeboden voor directe keuze.";
                    listNode.appendChild(note);
                }
            };

            const loadSlots = async (row) => {
                const productSelect = row.querySelector("[data-builder-product-select]");
                const dateInput = row.querySelector("[data-builder-date]");
                const participantsInput = row.querySelector("[data-builder-participants]");
                if (!productSelect || !dateInput || !slotsUrl) {
                    renderSlotMessage(row, "Slotservice niet beschikbaar. Gebruik Geavanceerd en bevestig handmatig.");
                    return;
                }
                const productId = productSelect.value || "";
                const date = dateInput.value || "";
                const participants = participantsInput && participantsInput.value ? participantsInput.value : "1";
                if (!productId || !date) {
                    renderSlotMessage(row, "Kies eerst activiteit, datum en aantal personen.");
                    return;
                }
                renderSlotMessage(row, "Tijden laden...");
                const url = new URL(slotsUrl, window.location.origin);
                url.searchParams.set("product_id", productId);
                url.searchParams.set("date", date);
                url.searchParams.set("participants", participants);
                try {
                    const response = await fetch(url.toString(), {
                        credentials: "same-origin",
                        headers: restNonce ? { "X-WP-Nonce": restNonce } : {}
                    });
                    if (!response.ok) {
                        throw new Error("availability failed");
                    }
                    const payload = await response.json();
                    renderSlots(row, payload && Array.isArray(payload.slots) ? payload.slots : []);
                } catch (error) {
                    renderSlotMessage(row, "Tijden konden niet geladen worden. Controleer availability later of gebruik maatwerk.");
                }
            };

            const refreshOrders = () => {
                Array.from(list.querySelectorAll("[data-builder-row]")).forEach((row, index) => {
                    const sortInput = row.querySelector("[data-builder-sort-order]");
                    const orderLabel = row.querySelector("[data-builder-order-label]");
                    if (sortInput) { sortInput.value = String(index + 1); }
                    if (orderLabel) { orderLabel.textContent = "Stop " + (index + 1); }
                });
            };

            const bindRow = (row) => {
                if (!row) { return; }
                row.addEventListener("dragstart", () => {
                    dragSource = row;
                    row.classList.add("is-dragging");
                });
                row.addEventListener("dragend", () => {
                    row.classList.remove("is-dragging");
                    dragSource = null;
                    refreshOrders();
                });
                row.addEventListener("dragover", (event) => {
                    event.preventDefault();
                    if (!dragSource || dragSource === row) { return; }
                    const rect = row.getBoundingClientRect();
                    const before = (event.clientY - rect.top) < (rect.height / 2);
                    if (before) {
                        list.insertBefore(dragSource, row);
                    } else {
                        list.insertBefore(dragSource, row.nextSibling);
                    }
                });

                const removeButton = row.querySelector(".bsp-quote-admin__builder-remove");
                if (removeButton) {
                    removeButton.addEventListener("click", () => {
                        const rows = list.querySelectorAll("[data-builder-row]");
                        if (rows.length <= 1) { return; }
                        row.remove();
                        refreshCommercialSummary();
                        refreshOrders();
                    });
                }

                const duplicateButton = row.querySelector(".bsp-quote-admin__builder-duplicate");
                if (duplicateButton) {
                    duplicateButton.addEventListener("click", () => {
                        const clone = createRowFromTemplate();
                        if (!clone) { return; }
                        copyRowValues(row, clone);
                        list.insertBefore(clone, row.nextSibling);
                        refreshRowState(clone);
                        refreshCommercialSummary();
                        refreshOrders();
                    });
                }

                const productSelect = row.querySelector("[data-builder-product-select]");
                if (productSelect) {
                    productSelect.addEventListener("change", () => {
                        const option = productSelect.selectedOptions && productSelect.selectedOptions.length ? productSelect.selectedOptions[0] : null;
                        const titleInput = row.querySelector("[data-builder-title]");
                        const durationInput = row.querySelector("[data-builder-duration]");
                        const unitAmountInput = row.querySelector("[data-builder-unit-amount]");
                        const currencyInput = row.querySelector("[data-builder-currency]");
                        const pricingConfidenceInput = row.querySelector("[data-builder-pricing-confidence]");
                        const availabilityConfidenceInput = row.querySelector("[data-builder-availability-confidence]");
                        const priceSummary = row.querySelector("[data-builder-price-summary]");
                        if (!option) { return; }
                        if (titleInput && !titleInput.value.trim()) {
                            titleInput.value = option.dataset.title || "";
                        }
                        if (durationInput && !durationInput.value && option.dataset.duration) {
                            durationInput.value = option.dataset.duration;
                        }
                        if (unitAmountInput) {
                            unitAmountInput.value = option.dataset.unitAmount || "";
                        }
                        if (currencyInput) {
                            currencyInput.value = option.dataset.currency || "EUR";
                        }
                        if (pricingConfidenceInput && (option.dataset.unitAmount || "") !== "") {
                            pricingConfidenceInput.value = "snapshot";
                        }
                        if (availabilityConfidenceInput && productSelect.value) {
                            availabilityConfidenceInput.value = "projected";
                        }
                        if (priceSummary) {
                            priceSummary.textContent = formatPriceLabel(option.dataset.currency || "EUR", option.dataset.unitAmount || "");
                        }
                        calculateLineTotalFromUnit(row, true);
                        refreshRowState(row);
                        loadSlots(row);
                    });
                }
                const titleInput = row.querySelector("[data-builder-title]");
                if (titleInput) {
                    titleInput.addEventListener("input", () => refreshRowState(row));
                }
                const dateInput = row.querySelector("[data-builder-date]");
                if (dateInput) {
                    dateInput.addEventListener("change", () => loadSlots(row));
                }
                const participantsInput = row.querySelector("[data-builder-participants]");
                if (participantsInput) {
                    participantsInput.addEventListener("change", () => {
                        calculateLineTotalFromUnit(row, true);
                        loadSlots(row);
                    });
                }
                const unitAmountInput = row.querySelector("[data-builder-unit-amount]");
                if (unitAmountInput) {
                    unitAmountInput.addEventListener("change", () => calculateLineTotalFromUnit(row, true));
                    unitAmountInput.addEventListener("input", () => updateLinePricePresentation(row));
                }
                const lineTotalInput = row.querySelector("[data-builder-line-total]");
                if (lineTotalInput) {
                    lineTotalInput.addEventListener("change", () => updateLinePricePresentation(row));
                    lineTotalInput.addEventListener("input", () => updateLinePricePresentation(row));
                }
                const refreshSlotsButton = row.querySelector("[data-builder-refresh-slots]");
                if (refreshSlotsButton) {
                    refreshSlotsButton.addEventListener("click", () => loadSlots(row));
                }
                const showUnavailable = row.querySelector("[data-builder-show-unavailable]");
                if (showUnavailable) {
                    showUnavailable.addEventListener("change", () => loadSlots(row));
                }
                Array.from(row.querySelectorAll("[data-builder-slot-start]")).forEach((button) => {
                    button.addEventListener("click", () => {
                        setSelectedSlot(row, button.dataset.builderSlotStart || "", button.dataset.builderSlotEnd || "");
                    });
                });
                Array.from(row.querySelectorAll("[data-builder-start-time], [data-builder-end-time], [data-builder-slot-label]")).forEach((field) => {
                    field.addEventListener("change", () => refreshRowState(row));
                    field.addEventListener("input", () => refreshRowState(row));
                });
                refreshRowState(row);
            };

            const createRowFromTemplate = () => {
                const index = list.querySelectorAll("[data-builder-row]").length;
                const html = template.innerHTML.replaceAll("__INDEX__", String(index));
                const wrapper = document.createElement("div");
                wrapper.innerHTML = html.trim();
                const row = wrapper.firstElementChild;
                if (row) {
                    list.appendChild(row);
                    bindRow(row);
                }
                return row;
            };

            const copyRowValues = (source, target) => {
                const sourceFields = source.querySelectorAll("input, select, textarea");
                sourceFields.forEach((field) => {
                    const name = field.getAttribute("name") || "";
                    const match = name.match(/\\]\\[(.+?)\\]$/);
                    if (!match) { return; }
                    const targetField = target.querySelector(`[name$="][${match[1]}]"]`);
                    if (!targetField) { return; }
                    targetField.value = field.value;
                });
            };

            Array.from(list.querySelectorAll("[data-builder-row]")).forEach(bindRow);
            if (form) {
                Array.from(form.querySelectorAll("[data-builder-discount-amount], [data-builder-discount-label]")).forEach((field) => {
                    field.addEventListener("input", refreshCommercialSummary);
                    field.addEventListener("change", refreshCommercialSummary);
                });
            }
            refreshOrders();
            refreshCommercialSummary();

            const addButton = document.querySelector(".bsp-quote-admin__builder-add");
            if (addButton) {
                addButton.addEventListener("click", () => {
                    const row = createRowFromTemplate();
                    if (row) { refreshRowState(row); }
                    refreshCommercialSummary();
                    refreshOrders();
                });
            }
        })();
        </script>';
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private static function buildQuoteProgram(array $lines, string $defaultCurrency): array
    {
        usort($lines, static function (array $left, array $right): int {
            $leftDate = (string) ($left['service_date'] ?? '');
            $rightDate = (string) ($right['service_date'] ?? '');
            $dateCompare = strcmp($leftDate !== '' ? $leftDate : '9999-99-99', $rightDate !== '' ? $rightDate : '9999-99-99');
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            $leftTime = self::resolveProposalLineStartTime($left);
            $rightTime = self::resolveProposalLineStartTime($right);
            $timeCompare = strcmp($leftTime !== '' ? $leftTime : '99:99', $rightTime !== '' ? $rightTime : '99:99');
            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            return ((int) ($left['sort_order'] ?? ($left['line_number'] ?? 0))) <=> ((int) ($right['sort_order'] ?? ($right['line_number'] ?? 0)));
        });

        $groups = array();
        $stats = array(
            'total_lines' => count($lines),
            'scheduled_lines' => 0,
            'priced_lines' => 0,
            'confirmed_lines' => 0,
            'provisional_lines' => 0,
        );

        foreach ($lines as $line) {
            $programLine = self::buildQuoteProgramLine($line, $defaultCurrency);
            $groupKey = (string) $programLine['group_key'];
            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = array(
                    'label' => (string) $programLine['group_label'],
                    'items' => array(),
                );
            }
            $groups[$groupKey]['items'][] = $programLine;

            if (! empty($programLine['is_scheduled'])) {
                $stats['scheduled_lines']++;
            }
            if (! empty($programLine['has_price'])) {
                $stats['priced_lines']++;
            }
            if (! empty($programLine['is_confirmed'])) {
                $stats['confirmed_lines']++;
            }
            if (! empty($programLine['is_provisional'])) {
                $stats['provisional_lines']++;
            }
        }

        return array(
            'stats' => $stats,
            'groups' => array_values($groups),
        );
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private static function buildQuoteProgramLine(array $line, string $defaultCurrency): array
    {
        $date = trim((string) ($line['service_date'] ?? ''));
        $startTime = self::resolveProposalLineStartTime($line);
        $endTime = self::resolveProposalLineEndTime($line);
        $durationMinutes = self::resolveProgramLineDurationMinutes($line);
        $optionLabels = self::resolveProgramOptionLabels($line);
        $validatedSlotLabel = self::resolveProgramValidatedSlotLabel($line, $date, $startTime, $endTime);
        $timingPresentation = self::buildProgramTimingPresentation($startTime, $endTime, $validatedSlotLabel, (string) ($line['availability_confidence'] ?? 'unknown'), $date);
        $lineTotal = isset($line['line_total_snapshot']) && $line['line_total_snapshot'] !== '' ? (float) $line['line_total_snapshot'] : null;
        $unitAmount = isset($line['unit_amount_snapshot']) && $line['unit_amount_snapshot'] !== '' ? (float) $line['unit_amount_snapshot'] : null;
        $currency = (string) (($line['currency'] ?? '') ?: $defaultCurrency);
        $participants = max(0, (int) ($line['participants'] ?? 0));
        $quantity = max(1, (int) ($line['quantity'] ?? 1));
        $pricingConfidence = (string) ($line['pricing_confidence'] ?? 'unknown');
        $availabilityConfidence = (string) ($line['availability_confidence'] ?? 'unknown');
        $isConfirmed = $availabilityConfidence === 'confirmed';
        $isProvisional = $availabilityConfidence !== 'confirmed' || $pricingConfidence !== 'execution_verified';

        $detailBits = array();
        if ($participants > 0) {
            $detailBits[] = sprintf(__('%d deelnemers', 'sbdp'), $participants);
        } else {
            $detailBits[] = __('Deelnemers nog open', 'sbdp');
        }
        $detailBits[] = sprintf(__('Aantal %d', 'sbdp'), $quantity);
        if ($durationMinutes !== null) {
            $detailBits[] = sprintf(__('Duur %d min', 'sbdp'), $durationMinutes);
        }
        if ($validatedSlotLabel !== '' && $validatedSlotLabel !== (string) ($timingPresentation['primary'] ?? '')) {
            $detailBits[] = sprintf(__('Gevalideerd slot %s', 'sbdp'), $validatedSlotLabel);
        }

        $availabilityLabel = $isConfirmed ? __('Beschikbaarheid bevestigd', 'sbdp') : __('Beschikbaarheid onder voorbehoud', 'sbdp');
        $availabilityNote = $isConfirmed
            ? __('Deze regel had op snapshotmoment een bevestigd tijdslot of positieve execution-validatie.', 'sbdp')
            : __('Behandel tijd en beschikbaarheid als richtinggevend totdat execution of leverancier deze bevestigt.', 'sbdp');
        $pricingLabel = $pricingConfidence === 'execution_verified' ? __('Prijs bevestigd', 'sbdp') : __('Prijs nog controleren', 'sbdp');

        return array(
            'group_key' => $date !== '' ? $date : 'open',
            'group_label' => $date !== '' ? $date : __('Datum nog open', 'sbdp'),
            'time_label' => (string) ($timingPresentation['label'] ?? __('Planning nog open', 'sbdp')),
            'time_badge_class' => (string) ($timingPresentation['badge_class'] ?? 'is-neutral'),
            'time_primary' => (string) ($timingPresentation['primary'] ?? __('Planning nog open', 'sbdp')),
            'time_secondary' => (string) ($timingPresentation['secondary'] ?? ''),
            'title' => (string) (($line['title'] ?? '') !== '' ? $line['title'] : __('Programmastop', 'sbdp')),
            'option_labels' => $optionLabels,
            'detail_bits' => $detailBits,
            'price_primary' => $lineTotal !== null ? self::formatMoney($lineTotal, $currency) : __('Prijs nog open', 'sbdp'),
            'price_secondary' => $unitAmount !== null ? sprintf(__('Per stuk %s', 'sbdp'), self::formatMoney($unitAmount, $currency)) : '',
            'availability_label' => $availabilityLabel,
            'availability_badge_class' => $isConfirmed ? 'is-good' : 'is-warn',
            'availability_note' => $availabilityNote,
            'pricing_label' => $pricingLabel,
            'pricing_badge_class' => $pricingConfidence === 'execution_verified' ? 'is-good' : 'is-neutral',
            'optional_label' => ! empty($line['is_optional']) ? __('Optioneel', 'sbdp') : '',
            'is_scheduled' => ! empty($timingPresentation['is_scheduled']),
            'has_price' => $lineTotal !== null,
            'is_confirmed' => $isConfirmed,
            'is_provisional' => $isProvisional,
        );
    }

    /**
     * @return array{label: string, badge_class: string, primary: string, secondary: string, is_scheduled: bool}
     */
    private static function buildProgramTimingPresentation(
        string $startTime,
        string $endTime,
        string $validatedSlotLabel,
        string $availabilityConfidence,
        string $serviceDate
    ): array {
        $rangeLabel = self::formatProgramTimeRange($startTime, $endTime, $validatedSlotLabel);

        if ($rangeLabel !== '' && $availabilityConfidence === 'confirmed') {
            return array(
                'label' => __('Bevestigd tijdslot', 'sbdp'),
                'badge_class' => 'is-good',
                'primary' => $rangeLabel,
                'secondary' => __('Tijd en slot waren bevestigd op snapshotmoment.', 'sbdp'),
                'is_scheduled' => true,
            );
        }

        if ($rangeLabel !== '' && ($availabilityConfidence === 'projected' || $availabilityConfidence === 'snapshot')) {
            return array(
                'label' => __('Voorgesteld tijdslot', 'sbdp'),
                'badge_class' => 'is-neutral',
                'primary' => $rangeLabel,
                'secondary' => __('Nog onder voorbehoud totdat beschikbaarheid wordt bevestigd.', 'sbdp'),
                'is_scheduled' => true,
            );
        }

        if ($rangeLabel !== '') {
            return array(
                'label' => __('Onder review', 'sbdp'),
                'badge_class' => 'is-warn',
                'primary' => $rangeLabel,
                'secondary' => __('Controleer dit tijdslot nog voordat je het als klantafspraak behandelt.', 'sbdp'),
                'is_scheduled' => true,
            );
        }

        if ($serviceDate !== '') {
            return array(
                'label' => __('Tijd nog plannen', 'sbdp'),
                'badge_class' => 'is-warn',
                'primary' => __('Tijdslot nog invullen', 'sbdp'),
                'secondary' => __('Datum staat al vast, maar een bruikbaar tijdslot ontbreekt nog.', 'sbdp'),
                'is_scheduled' => false,
            );
        }

        return array(
            'label' => __('Planning ontbreekt', 'sbdp'),
            'badge_class' => 'is-warn',
            'primary' => __('Plan datum en tijd', 'sbdp'),
            'secondary' => __('Deze programmaregel heeft nog geen datum of tijd om met de klant te delen.', 'sbdp'),
            'is_scheduled' => false,
        );
    }

    private static function formatProgramTimeRange(string $startTime, string $endTime, string $validatedSlotLabel): string
    {
        if ($startTime !== '' && $endTime !== '') {
            return sprintf('%s - %s', $startTime, $endTime);
        }

        if ($startTime !== '') {
            return sprintf(__('Start %s', 'sbdp'), $startTime);
        }

        if ($endTime !== '') {
            return sprintf(__('Tot %s', 'sbdp'), $endTime);
        }

        return $validatedSlotLabel;
    }

    /**
     * @param array<string, mixed> $line
     * @return array<int, string>
     */
    private static function resolveProgramOptionLabels(array $line): array
    {
        $labels = array();

        $canonicalLabels = isset($line['selected_option_labels_json']) && is_array($line['selected_option_labels_json'])
            ? $line['selected_option_labels_json']
            : array();
        foreach ($canonicalLabels as $candidate) {
            if (! is_scalar($candidate) || $candidate === null) {
                continue;
            }

            $label = trim((string) $candidate);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        $externalLabel = trim((string) ($line['external_label'] ?? ''));
        if ($externalLabel !== '') {
            $labels[] = $externalLabel;
        }

        $pricingSnapshot = isset($line['pricing_snapshot_json']) && is_array($line['pricing_snapshot_json'])
            ? $line['pricing_snapshot_json']
            : array();
        $availabilitySnapshot = isset($line['availability_snapshot_json']) && is_array($line['availability_snapshot_json'])
            ? $line['availability_snapshot_json']
            : array();

        foreach (array('selection_label', 'selection_name', 'option_label', 'option_name', 'resource_label', 'resource_name', 'variant_label', 'variant_name') as $key) {
            foreach (array($pricingSnapshot, $availabilitySnapshot) as $snapshot) {
                if (! isset($snapshot[$key]) || ! is_scalar($snapshot[$key])) {
                    continue;
                }

                $candidate = trim((string) $snapshot[$key]);
                if ($candidate !== '') {
                    $labels[] = $candidate;
                }
            }
        }

        return array_values(array_unique(array_filter($labels)));
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function resolveProgramValidatedSlotLabel(array $line, string $serviceDate, string $startTime, string $endTime): string
    {
        $slotLabel = trim((string) ($line['validated_slot_label'] ?? ''));
        if ($slotLabel !== '') {
            return $slotLabel;
        }

        $timeRange = '';
        if ($startTime !== '' && $endTime !== '') {
            $timeRange = sprintf('%s-%s', $startTime, $endTime);
        } elseif ($startTime !== '') {
            $timeRange = $startTime;
        } elseif ($endTime !== '') {
            $timeRange = $endTime;
        }

        if ($serviceDate !== '' && $timeRange !== '') {
            return sprintf('%s %s', $serviceDate, $timeRange);
        }

        return $timeRange !== '' ? $timeRange : $serviceDate;
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function resolveProgramLineDurationMinutes(array $line): ?int
    {
        $duration = isset($line['duration_minutes']) ? (int) $line['duration_minutes'] : 0;
        if ($duration > 0) {
            return $duration;
        }

        $start = self::resolveProposalLineStartTime($line);
        $end = self::resolveProposalLineEndTime($line);
        if (! preg_match('/^(\d{2}):(\d{2})$/', $start, $startMatches) || ! preg_match('/^(\d{2}):(\d{2})$/', $end, $endMatches)) {
            return null;
        }

        $startMinutes = (((int) $startMatches[1]) * 60) + (int) $startMatches[2];
        $endMinutes = (((int) $endMatches[1]) * 60) + (int) $endMatches[2];

        return $endMinutes > $startMinutes ? $endMinutes - $startMinutes : null;
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function resolveProposalLineStartTime(array $line): string
    {
        return trim((string) ($line['proposed_start_time'] ?? ($line['start_time'] ?? '')));
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function resolveProposalLineEndTime(array $line): string
    {
        return trim((string) ($line['proposed_end_time'] ?? ($line['end_time'] ?? '')));
    }

    /**
     * @param array<string, mixed>      $quote
     * @param array<string, mixed>|null $currentVersion
     * @param array<int, array<string, mixed>> $lines
     * @param array<int, array<string, mixed>> $assumptions
     * @param array<int, array<string, mixed>> $followups
     * @return array<string, mixed>
     */
    private static function buildQuoteWorkspaceState(
        array $quote,
        ?array $currentVersion,
        array $lines,
        array $assumptions,
        array $followups,
        array $communicationState
    ): array {
        $blockers = array();
        $waiting = array();
        $mappedLines = 0;
        $projectedPricingLines = 0;
        $projectedAvailabilityLines = 0;
        $unscheduledLines = 0;

        if ($currentVersion === null) {
            $blockers[] = __('Er is nog geen actieve quote-versie beschikbaar.', 'sbdp');
        }
        if ($lines === array()) {
            $blockers[] = __('De quote bevat nog geen commerciële regels.', 'sbdp');
        }

        foreach ($lines as $line) {
            if ((int) ($line['product_id'] ?? 0) > 0) {
                $mappedLines++;
            }
            if ((string) ($line['pricing_confidence'] ?? 'unknown') !== 'execution_verified') {
                $projectedPricingLines++;
            }
            if ((string) ($line['availability_confidence'] ?? 'unknown') !== 'confirmed') {
                $projectedAvailabilityLines++;
            }
            if (trim((string) ($line['service_date'] ?? '')) === '' || self::resolveProposalLineStartTime($line) === '') {
                $unscheduledLines++;
            }
        }

        foreach ($assumptions as $assumption) {
            if ((string) ($assumption['status'] ?? 'open') !== 'open') {
                continue;
            }

            $message = (string) ($assumption['message'] ?? '');
            if ($message === '') {
                continue;
            }

            if (! empty($assumption['blocks_review']) || ! empty($assumption['blocks_send'])) {
                $blockers[] = $message;
                continue;
            }

            $waiting[] = $message;
        }

        if ((string) ($quote['review_status'] ?? 'not_started') === 'pending_review') {
            $waiting[] = __('De quote wacht op interne review.', 'sbdp');
        }
        if ((string) ($quote['review_status'] ?? 'not_started') === 'approved' && (string) ($quote['send_status'] ?? 'not_ready') === 'ready_to_send') {
            $waiting[] = __('De quote is commercieel gereed maar nog niet als verzonden geregistreerd.', 'sbdp');
        }
        if ($currentVersion !== null && (string) ($currentVersion['availability_confidence'] ?? 'unknown') !== 'confirmed') {
            $waiting[] = __('Beschikbaarheid is nog niet definitief bevestigd.', 'sbdp');
        }
        if ($currentVersion !== null && (string) ($currentVersion['pricing_confidence'] ?? 'unknown') !== 'execution_verified') {
            $waiting[] = __('Prijs blijft richtinggevend totdat de execution-laag deze verifieert.', 'sbdp');
        }

        $openFollowups = 0;
        foreach ($followups as $followup) {
            if ((string) ($followup['status'] ?? 'open') === 'open') {
                $openFollowups++;
            }
        }
        if ($openFollowups > 0) {
            $waiting[] = sprintf(__('Er staan nog %d open follow-ups.', 'sbdp'), $openFollowups);
        }

        $cta = 'review_request';
        $nextTitle = __('Vraag review aan', 'sbdp');
        $nextDescription = __('Zodra de commerciële opzet klopt, zet je de quote door naar interne review.', 'sbdp');
        $readinessLabel = __('Nog niet verzendklaar', 'sbdp');
        $readinessDescription = __('Werk blockers weg en maak de review-flow leidend voordat je naar send of handoff kijkt.', 'sbdp');
        $nextAction = array(
            'title' => $nextTitle,
            'description' => $nextDescription,
            'cta' => $cta,
        );

        if (
            (string) ($communicationState['thread_label'] ?? '') === __('Waiting on us', 'sbdp')
            && ! empty($communicationState['latest_inbound_message_id'])
        ) {
            $nextTitle = __('Verwerk klantreactie', 'sbdp');
            $nextDescription = __('De klant heeft gereageerd. Maak eerst een antwoorddraft of samenvatting voordat je verdere workflowstappen neemt.', 'sbdp');
            $readinessLabel = __('Klant wacht op antwoord', 'sbdp');
            $readinessDescription = __('De thread heeft nu voorrang op interne vervolgacties.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'reply_draft',
                'message_id' => (int) $communicationState['latest_inbound_message_id'],
            );
        } elseif ($blockers !== array()) {
            $nextTitle = __('Werk blockers weg', 'sbdp');
            $nextDescription = __('Open assumptions blokkeren review of verzending en moeten eerst expliciet worden opgelost.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'assumptions',
            );
        } elseif ((string) ($quote['review_status'] ?? 'not_started') === 'pending_review') {
            $nextTitle = __('Rond review af', 'sbdp');
            $nextDescription = __('De quote staat in review en wacht op goedkeuring of terugkoppeling.', 'sbdp');
            $readinessLabel = __('Wacht op review', 'sbdp');
            $readinessDescription = __('De commerciële inhoud ligt klaar voor een reviewbeslissing.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'review_approve',
            );
        } elseif ((string) ($quote['review_status'] ?? 'not_started') === 'approved' && (string) ($quote['send_status'] ?? 'not_ready') === 'ready_to_send') {
            $hasCommercialCaveats = (
                $mappedLines !== count($lines)
                || $projectedPricingLines > 0
                || $projectedAvailabilityLines > 0
                || $unscheduledLines > 0
                || ($currentVersion !== null && (string) ($currentVersion['pricing_confidence'] ?? 'unknown') !== 'execution_verified')
                || ($currentVersion !== null && (string) ($currentVersion['availability_confidence'] ?? 'unknown') !== 'confirmed')
            );

            if ((string) ($communicationState['proposal_label'] ?? '') === __('Nothing sent', 'sbdp')) {
                $nextTitle = __('Maak voorstelmail', 'sbdp');
                $nextDescription = __('Review is afgerond. Leg nu eerst een echte voorstelmail vast voordat je deze quote als klantcommunicatie behandelt.', 'sbdp');
                $readinessLabel = $hasCommercialCaveats ? __('Verzendcheck nodig', 'sbdp') : __('Klaar voor klantcontact', 'sbdp');
                $readinessDescription = $hasCommercialCaveats
                    ? __('Er is nog een voorstelmail nodig en commerciële caveats moeten expliciet worden meegenomen.', 'sbdp')
                    : __('De inhoud staat klaar; maak nu de voorstelmail om het klantvoorstel echt te verzenden.', 'sbdp');
                $nextAction = array(
                    'title' => $nextTitle,
                    'description' => $nextDescription,
                    'cta' => 'proposal_draft',
                );
            } elseif ($hasCommercialCaveats) {
                $nextTitle = __('Doorloop verzendcheck', 'sbdp');
                $nextDescription = __('Review is goedgekeurd, maar de operator moet eerst de open proposal-readiness acties nalopen voordat deze versie veilig als verzonden wordt geregistreerd.', 'sbdp');
                $readinessLabel = __('Verzendcheck nodig', 'sbdp');
                $readinessDescription = __('De quote staat in ready_to_send, maar bevat nog commerciële caveats die expliciet moeten worden meegenomen in klantcommunicatie.', 'sbdp');
                $nextAction = array(
                    'title' => $nextTitle,
                    'description' => $nextDescription,
                    'cta' => 'proposal_readiness',
                );
            } else {
                $nextTitle = __('Registreer verzending', 'sbdp');
                $nextDescription = __('Review is goedgekeurd; de logische operatorstap is nu verzenden of handmatig als verzonden markeren.', 'sbdp');
                $readinessLabel = __('Klaar om te verzenden', 'sbdp');
                $readinessDescription = __('De quote heeft review doorlopen en staat klaar voor commerciële communicatie.', 'sbdp');
                $nextAction = array(
                    'title' => $nextTitle,
                    'description' => $nextDescription,
                    'cta' => 'send_mark_sent',
                );
            }
        } elseif ((string) ($quote['send_status'] ?? 'not_ready') === 'sent_manual' && (string) ($quote['handoff_status'] ?? 'not_ready') === 'not_ready') {
            $nextTitle = __('Bewaak opvolging', 'sbdp');
            $nextDescription = __('De quote is verzonden. Gebruik follow-ups om akkoord, vragen en vervolgstappen te managen.', 'sbdp');
            $readinessLabel = __('Verzonden, wacht op vervolg', 'sbdp');
            $readinessDescription = __('Communicatie loopt; geavanceerde handoff blijft beschikbaar maar is nog geen primaire operator-taak.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'followups',
            );
        } elseif ((string) ($quote['handoff_status'] ?? 'not_ready') === 'ready_for_resnapshot') {
            $nextTitle = __('Handoff staat klaar', 'sbdp');
            $nextDescription = __('De quote mag naar de gecontroleerde resnapshot-stap zodra dat operationeel nodig is.', 'sbdp');
            $readinessLabel = __('Handoff voorbereid', 'sbdp');
            $readinessDescription = __('De commerciële werkruimte is afgerond; execution-voorbereiding is beschikbaar onder de vouw.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'handoff',
            );
        }

        return array(
            'blockers'              => array_values(array_unique($blockers)),
            'waiting'               => array_values(array_unique($waiting)),
            'readiness_label'       => $readinessLabel,
            'readiness_description' => $readinessDescription,
            'next_action'           => $nextAction,
        );
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $proposalReadiness
     * @param array<string, mixed> $workspaceState
     * @param array<string, mixed> $communicationState
     * @return array<string, mixed>
     */
    private static function buildQuoteOperatorDecision(
        array $quote,
        array $proposalReadiness,
        array $workspaceState,
        array $communicationState
    ): array {
        $statusLabel = __('Intern concept', 'sbdp');
        $statusBadgeClass = 'is-neutral';
        $title = __('Werk de offerte nog intern uit', 'sbdp');
        $statusDescription = (string) ($proposalReadiness['description'] ?? __('Werk eerst review en ontbrekende commerciële details uit.', 'sbdp'));
        $threadLabel = '';
        $threadBadgeClass = 'is-neutral';

        if ((string) ($communicationState['thread_label'] ?? '') === __('Waiting on us', 'sbdp')) {
            $statusLabel = __('Klant heeft gereageerd', 'sbdp');
            $statusBadgeClass = 'is-warn';
            $title = __('De thread vraagt nu om operatoractie', 'sbdp');
            $statusDescription = (string) ($communicationState['description'] ?? '');
            $threadLabel = (string) ($communicationState['operator_action_age_label'] ?? '');
            $threadBadgeClass = (string) ($communicationState['operator_action_age_badge_class'] ?? 'is-neutral');
        } elseif ((string) ($communicationState['proposal_label'] ?? '') === __('Proposal sent', 'sbdp')) {
            $statusLabel = __('Wacht op klant', 'sbdp');
            $statusBadgeClass = 'is-neutral';
            $title = __('Voorstel is verstuurd en de thread loopt', 'sbdp');
            $statusDescription = (string) ($communicationState['description'] ?? '');
        } elseif ((string) ($quote['review_status'] ?? 'not_started') === 'approved') {
            if ((string) ($proposalReadiness['badge_class'] ?? 'is-neutral') === 'is-good') {
                $statusLabel = __('Klaar voor klant', 'sbdp');
                $statusBadgeClass = 'is-good';
                $title = __('Commercieel voorstel staat strak genoeg om te delen', 'sbdp');
            } else {
                $statusLabel = __('Richtinggevend voorstel', 'sbdp');
                $statusBadgeClass = 'is-warn';
                $title = __('Deelbaar, maar nog expliciet onder voorbehoud', 'sbdp');
            }
            $statusDescription = (string) ($proposalReadiness['description'] ?? $statusDescription);
        }

        $missingItems = array();
        foreach ((array) ($proposalReadiness['items'] ?? array()) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $missingItems[] = array(
                'title' => (string) ($item['title'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
            );
            if (count($missingItems) >= 4) {
                break;
            }
        }

        if ($missingItems === array()) {
            foreach ((array) ($workspaceState['blockers'] ?? array()) as $blocker) {
                $missingItems[] = array(
                    'title' => __('Blokkade', 'sbdp'),
                    'description' => (string) $blocker,
                );
                if (count($missingItems) >= 4) {
                    break;
                }
            }
        }

        return array(
            'status_label' => $statusLabel,
            'status_badge_class' => $statusBadgeClass,
            'title' => $title,
            'status_description' => $statusDescription,
            'thread_label' => $threadLabel,
            'thread_badge_class' => $threadBadgeClass,
            'missing_items' => $missingItems,
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function renderQuoteDashboardFocus(array $state, int $quoteId): void
    {
        $stateName = (string) ($state['state'] ?? 'blocked');

        if ($stateName === 'ready') {
            self::renderQuoteDashboardReady($quoteId);
            return;
        }

        if ($stateName === 'locked') {
            self::renderQuoteDashboardLocked();
            return;
        }

        if ($stateName === 'assumptions') {
            self::renderQuoteDashboardAssumptions($state, $quoteId);
            return;
        }

        self::renderQuoteDashboardBlocked($state, $quoteId);
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function renderQuoteDashboardBlocked(array $state, int $quoteId): void
    {
        $blocker = is_array($state['primary_blocker'] ?? null) ? $state['primary_blocker'] : array();
        $label = (string) ($blocker['label'] ?? __('Offerte is nog geblokkeerd', 'sbdp'));
        $message = trim((string) ($blocker['message'] ?? ''));
        $steps = array_values(array_filter((array) ($blocker['steps'] ?? array()), static fn ($step): bool => trim((string) $step) !== ''));
        $buttonTab = trim((string) ($blocker['button_tab'] ?? ''));
        $buttonLabel = trim((string) ($blocker['button_label'] ?? __('Naar juiste tab', 'sbdp')));

        echo '<section class="postbox bsp-quote-admin__panel bsp-quote-admin__focus-card bsp-quote-admin__focus-card--blocked">';
        echo '<div class="bsp-quote-admin__panel-body">';
        echo '<p class="bsp-quote-admin__focus-kicker">' . esc_html__('Stop - handeling nodig', 'sbdp') . '</p>';
        echo '<h2>' . esc_html($label) . '</h2>';
        if ($message !== '') {
            echo '<p class="bsp-quote-admin__focus-message">' . esc_html($message) . '</p>';
        }
        if ($steps !== array()) {
            echo '<div class="bsp-quote-admin__focus-steps"><strong>' . esc_html__('Wat nu doen:', 'sbdp') . '</strong><ol>';
            foreach ($steps as $step) {
                echo '<li>' . esc_html((string) $step) . '</li>';
            }
            echo '</ol></div>';
        }
        if ($buttonTab !== '') {
            echo '<p><a class="button button-primary button-large bsp-quote-admin__focus-button" href="' . esc_url(self::workspaceTabUrl($quoteId, $buttonTab)) . '">' . esc_html($buttonLabel) . '</a></p>';
        }
        if ((int) ($state['hidden_count'] ?? 0) > 0) {
            echo '<details class="bsp-quote-admin__focus-details"><summary>' . esc_html(sprintf(__('%d latere checks verborgen', 'sbdp'), (int) $state['hidden_count'])) . '</summary><p>' . esc_html__('Los eerst de hoofdactie hierboven op. Daarna toont het dashboard automatisch de volgende stap.', 'sbdp') . '</p></details>';
        }
        echo '</div></section>';
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function renderQuoteDashboardAssumptions(array $state, int $quoteId): void
    {
        $assumptions = array_values(array_filter((array) ($state['assumptions'] ?? array()), static fn ($assumption): bool => is_array($assumption)));

        echo '<section class="postbox bsp-quote-admin__panel bsp-quote-admin__focus-card bsp-quote-admin__focus-card--assumptions">';
        echo '<div class="bsp-quote-admin__panel-body">';
        echo '<p class="bsp-quote-admin__focus-kicker">' . esc_html__('Controleer & bevestig', 'sbdp') . '</p>';
        echo '<h2>' . esc_html__('Nog even checken voor verzending', 'sbdp') . '</h2>';
        echo '<p class="bsp-quote-admin__focus-message">' . esc_html(sprintf(__('Deze offerte heeft %d punt(en) die je moet OK-en.', 'sbdp'), count($assumptions))) . '</p>';

        foreach ($assumptions as $index => $assumption) {
            $label = (string) ($assumption['label'] ?? __('Open check', 'sbdp'));
            $message = trim((string) ($assumption['message'] ?? ''));
            $steps = array_values(array_filter((array) ($assumption['steps'] ?? array()), static fn ($step): bool => trim((string) $step) !== ''));
            $buttonLabel = (string) ($assumption['button_label'] ?? __('Bevestigd', 'sbdp'));

            echo '<article class="bsp-quote-admin__assumption-card">';
            echo '<strong>' . esc_html(sprintf('%d. %s', $index + 1, $label)) . '</strong>';
            if ($message !== '') {
                echo '<p>' . esc_html($message) . '</p>';
            }
            if ($steps !== array()) {
                echo '<ol>';
                foreach ($steps as $step) {
                    echo '<li>' . esc_html((string) $step) . '</li>';
                }
                echo '</ol>';
            }
            if (! empty($state['quote_editable']) && (int) ($assumption['assumption_id'] ?? 0) > 0) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">';
                \wp_nonce_field('sbdp_quote_resolve_assumption');
                echo '<input type="hidden" name="action" value="sbdp_quote_resolve_assumption">';
                echo '<input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
                echo '<input type="hidden" name="assumption_id" value="' . esc_attr((string) ($assumption['assumption_id'] ?? 0)) . '">';
                echo '<input type="hidden" name="workspace_tab" value="dashboard">';
                echo '<input type="hidden" name="resolution_note" value="Gecheckt door operator - akkoord voor verzending.">';
                echo '<button class="button button-primary" type="submit">' . esc_html($buttonLabel) . '</button>';
                echo '</form>';
            }
            echo '</article>';
        }

        echo '<p class="bsp-quote-admin__muted">' . esc_html__('Na bevestiging toont het dashboard automatisch de volgende stap.', 'sbdp') . '</p>';
        echo '</div></section>';
    }

    private static function renderQuoteDashboardReady(int $quoteId): void
    {
        echo '<section class="postbox bsp-quote-admin__panel bsp-quote-admin__focus-card bsp-quote-admin__focus-card--ready">';
        echo '<div class="bsp-quote-admin__panel-body">';
        echo '<p class="bsp-quote-admin__focus-kicker">' . esc_html__('Klaar om te verzenden', 'sbdp') . '</p>';
        echo '<h2>' . esc_html__('Alle vereisten zijn voldaan', 'sbdp') . '</h2>';
        echo '<p class="bsp-quote-admin__focus-message">' . esc_html__('De backend checks zijn groen. Controleer de voorstelmail en verstuur vanuit Communication.', 'sbdp') . '</p>';
        echo '<p><a class="button button-primary button-large bsp-quote-admin__focus-button" href="' . esc_url(self::workspaceTabUrl($quoteId, 'communication')) . '">' . esc_html__('Naar voorstelmail', 'sbdp') . '</a></p>';
        echo '</div></section>';
    }

    private static function renderQuoteDashboardLocked(): void
    {
        echo '<section class="postbox bsp-quote-admin__panel bsp-quote-admin__focus-card bsp-quote-admin__focus-card--ready">';
        echo '<div class="bsp-quote-admin__panel-body">';
        echo '<p class="bsp-quote-admin__focus-kicker">' . esc_html__('Geen actie nodig', 'sbdp') . '</p>';
        echo '<h2>' . esc_html__('Deze offerte is commercieel bevroren', 'sbdp') . '</h2>';
        echo '<p class="bsp-quote-admin__focus-message">' . esc_html__('Sent of accepted offertes tonen alleen auditinformatie. Maak een revisie als er commercieel iets moet wijzigen.', 'sbdp') . '</p>';
        echo '</div></section>';
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $requester
     */
    private static function renderQuoteDashboardCustomerSummary(array $request, array $requester): void
    {
        echo '<section class="postbox bsp-quote-admin__panel bsp-quote-admin__customer-strip"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html__('Klantinfo', 'sbdp') . '</h3></div><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__customer-grid">';
        echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Naam', 'sbdp') . '</span><strong>' . esc_html((string) ($requester['name'] ?? __('Onbekend', 'sbdp'))) . '</strong></div>';
        echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('E-mail', 'sbdp') . '</span><strong>' . esc_html((string) (($requester['email'] ?? '') ?: __('Ontbreekt', 'sbdp'))) . '</strong></div>';
        echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Groep', 'sbdp') . '</span><strong>' . esc_html((string) ($request['group_size'] ?? '0')) . ' ' . esc_html__('personen', 'sbdp') . '</strong></div>';
        echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Datum', 'sbdp') . '</span><strong>' . esc_html((string) (($request['preferred_date'] ?? '') ?: __('Geen voorkeur', 'sbdp'))) . '</strong></div>';
        echo '</div></div></section>';
    }

    private static function workspaceTabUrl(int $quoteId, string $tab): string
    {
        return add_query_arg(array(
            'page' => 'sbdp_quotes',
            'quote_id' => $quoteId,
            'workspace_tab' => self::normalizeWorkspaceTab($tab, 'dashboard'),
        ), admin_url('admin.php'));
    }

    /**
     * @param array<string, mixed> $validation
     * @param array<string, mixed> $sendReadiness
     */
    private static function renderQuoteBusinessValidationSummary(array $validation, array $sendReadiness): void
    {
        $checks = array_values(array_filter(
            isset($validation['checks']) && is_array($validation['checks']) ? $validation['checks'] : array(),
            static fn ($check): bool => is_array($check)
        ));
        $totalChecks = count($checks);
        $passedChecks = count(array_filter($checks, static fn (array $check): bool => ! empty($check['passed'])));
        $completionPercent = $totalChecks > 0 ? (int) round(($passedChecks / $totalChecks) * 100) : 0;
        $violations = isset($validation['violations']) && is_array($validation['violations']) ? $validation['violations'] : array();
        $hasSendBlockers = ! empty($sendReadiness['ready']) ? false : ((array) ($sendReadiness['blockers'] ?? array())) !== array();

        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html__('🧭 Validatie & voortgang', 'sbdp') . '</h3></div><div class="bsp-quote-admin__panel-body">';
        echo '<p><strong>' . esc_html(sprintf(__('Voorbereiding: %d%% klaar', 'sbdp'), $completionPercent)) . '</strong></p>';
        echo '<div style="background:#e5e7eb;border-radius:999px;overflow:hidden;height:10px;margin-bottom:12px;"><div style="background:#2271b1;height:10px;width:' . esc_attr((string) $completionPercent) . '%;"></div></div>';
        echo '<div class="bsp-quote-admin__badge-row">';
        foreach ($checks as $check) {
            $badgeClass = ! empty($check['passed']) ? 'is-good' : (((string) ($check['severity'] ?? 'warning')) === 'error' ? 'is-warn' : 'is-neutral');
            $label = (! empty($check['passed']) ? 'OK' : 'Open') . ' · ' . (string) ($check['label'] ?? '');
            echo self::renderInlineBadge($label, $badgeClass);
        }
        echo '</div>';

        if ($violations !== array()) {
            echo '<div style="margin-top:16px">';
            foreach ($violations as $violation) {
                if (! is_array($violation)) {
                    continue;
                }

                $tab = trim((string) ($violation['fix_url'] ?? ''));
                $href = $tab !== ''
                    ? esc_url(add_query_arg(array(
                        'page' => 'sbdp_quotes',
                        'quote_id' => isset($_GET['quote_id']) ? (int) $_GET['quote_id'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        'workspace_tab' => $tab,
                    ), admin_url('admin.php')))
                    : '';

                echo '<article class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--action" style="margin-bottom:10px">';
                echo '<strong>' . esc_html((string) ($violation['message'] ?? '')) . '</strong>';
                echo '<p>' . esc_html((string) ($violation['fix'] ?? '')) . '</p>';
                if ($href !== '') {
                    echo '<p><a class="button button-secondary" href="' . $href . '">' . esc_html__('Ga naar fix', 'sbdp') . '</a></p>';
                }
                echo '</article>';
            }
            echo '</div>';
        } elseif ($hasSendBlockers) {
            echo '<p class="bsp-quote-admin__muted" style="margin-top:12px">' . esc_html__('De basis is ingevuld, maar backend send-readiness blokkeert nog verzending. Gebruik de checklist hieronder voor de exacte blokkade.', 'sbdp') . '</p>';
        } else {
            echo '<p class="bsp-quote-admin__muted" style="margin-top:12px">' . esc_html__('De basiscontext is compleet. Gebruik nu de send-readiness en communicatieblokken om de laatste commerciële stap af te ronden.', 'sbdp') . '</p>';
        }
        echo '</div></section>';
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed>|null $currentVersion
     * @param array<int, array<string, mixed>> $lines
     * @param array<int, array<string, mixed>> $assumptions
     * @return array<string, mixed>
     */
    private static function buildQuoteProposalReadiness(
        array $quote,
        ?array $currentVersion,
        array $lines,
        array $assumptions
    ): array {
        $items = array();
        $blockingAssumptions = 0;
        $sendBlockingAssumptions = 0;
        $mappedLines = 0;
        $projectedPricingLines = 0;
        $projectedAvailabilityLines = 0;
        $unscheduledLines = 0;

        foreach ($assumptions as $assumption) {
            if ((string) ($assumption['status'] ?? 'open') !== 'open') {
                continue;
            }

            if (! empty($assumption['blocks_review']) || ! empty($assumption['blocks_send'])) {
                $blockingAssumptions++;
            }
            if (! empty($assumption['blocks_send'])) {
                $sendBlockingAssumptions++;
            }
        }

        foreach ($lines as $line) {
            if ((int) ($line['product_id'] ?? 0) > 0) {
                $mappedLines++;
            }
            if ((string) ($line['pricing_confidence'] ?? 'unknown') !== 'execution_verified') {
                $projectedPricingLines++;
            }
            if ((string) ($line['availability_confidence'] ?? 'unknown') !== 'confirmed') {
                $projectedAvailabilityLines++;
            }
            if (trim((string) ($line['service_date'] ?? '')) === '' || self::resolveProposalLineStartTime($line) === '') {
                $unscheduledLines++;
            }
        }

        if ((string) ($quote['review_status'] ?? 'not_started') !== 'approved') {
            $reviewAction = ((string) ($quote['review_status'] ?? 'not_started') === 'pending_review')
                ? array(
                    'type'  => 'review_approve',
                    'label' => __('Keur review goed', 'sbdp'),
                )
                : array(
                    'type'  => 'review_request',
                    'label' => __('Vraag review aan', 'sbdp'),
                );
            $items[] = array(
                'title'       => __('Interne review ontbreekt', 'sbdp'),
                'description' => __('Deze versie is nog niet vrijgegeven voor klantcommunicatie. Rond eerst de review-flow af.', 'sbdp'),
                'action'      => $reviewAction,
            );
        }

        if ($blockingAssumptions > 0) {
            $items[] = array(
                'title'       => sprintf(__('Open blockers: %d', 'sbdp'), $blockingAssumptions),
                'description' => __('Er staan assumptions open die review of verzending blokkeren en eerst expliciet opgelost moeten worden.', 'sbdp'),
                'action'      => array(
                    'type'          => 'followup_create',
                    'label'         => __('Maak blocker follow-up', 'sbdp'),
                    'title'         => __('Werk open quote-blockers weg', 'sbdp'),
                    'note'          => __('Los de open assumptions op die review of verzending blokkeren en werk daarna de readiness opnieuw bij.', 'sbdp'),
                    'priority'      => 'high',
                    'followup_type' => 'manual_review',
                    'secondary_href'=> '#quote-assumptions',
                    'secondary_label' => __('Bekijk assumptions', 'sbdp'),
                ),
            );
        }

        if ($mappedLines !== count($lines)) {
            $items[] = array(
                'title'       => sprintf(__('Regels nog niet volledig gemapt: %d van %d', 'sbdp'), max(0, count($lines) - $mappedLines), count($lines)),
                'description' => __('Niet-technische operators missen hier nog productzekerheid. Koppel alle regels voordat je dit als strak klantvoorstel behandelt.', 'sbdp'),
                'action'      => array(
                    'type'            => 'followup_create',
                    'label'           => __('Maak mapping-taak', 'sbdp'),
                    'title'           => __('Werk productmapping van quote-regels bij', 'sbdp'),
                    'note'            => __('Controleer de quote-regels en koppel alle nog open regels aan concrete producten voordat de offerte wordt gedeeld.', 'sbdp'),
                    'priority'        => 'high',
                    'followup_type'   => 'manual_review',
                    'secondary_href'  => '#quote-commercial',
                    'secondary_label' => __('Werk quote-regels bij', 'sbdp'),
                ),
            );
        }

        if ($projectedPricingLines > 0 || ($currentVersion !== null && (string) ($currentVersion['pricing_confidence'] ?? 'unknown') !== 'execution_verified')) {
            $items[] = array(
                'title'       => sprintf(__('Prijs nog richtinggevend op %d regel(s)', 'sbdp'), $projectedPricingLines),
                'description' => __('Maak in communicatie duidelijk dat prijs nog een snapshot of richtinggevend voorstel is, niet definitieve Woo-truth.', 'sbdp'),
                'action'      => array(
                    'type'            => 'followup_create',
                    'label'           => __('Plan prijscheck', 'sbdp'),
                    'title'           => __('Bevestig of label prijsvoorbehoud in offerte', 'sbdp'),
                    'note'            => __('Controleer alle richtinggevende prijsregels en zorg dat klantcommunicatie expliciet benoemt dat prijs nog een snapshot of onder voorbehoud is.', 'sbdp'),
                    'priority'        => 'normal',
                    'followup_type'   => 'manual_review',
                    'secondary_href'  => '#quote-commercial',
                    'secondary_label' => __('Controleer commercieel overzicht', 'sbdp'),
                ),
            );
        }

        if ($projectedAvailabilityLines > 0 || ($currentVersion !== null && (string) ($currentVersion['availability_confidence'] ?? 'unknown') !== 'confirmed')) {
            $items[] = array(
                'title'       => sprintf(__('Beschikbaarheid nog onbevestigd op %d regel(s)', 'sbdp'), $projectedAvailabilityLines),
                'description' => __('Beschikbaarheid moet richtinggevend of onder voorbehoud blijven totdat execution-validatie of leverancier dit bevestigt.', 'sbdp'),
                'action'      => array(
                    'type'            => 'followup_create',
                    'label'           => __('Plan beschikbaarheidscheck', 'sbdp'),
                    'title'           => __('Bevestig of label beschikbaarheidsvoorbehoud', 'sbdp'),
                    'note'            => __('Controleer welke regels nog projected beschikbaarheid hebben en zorg dat klantcommunicatie dit als voorbehoud behandelt totdat bevestiging binnen is.', 'sbdp'),
                    'priority'        => 'normal',
                    'followup_type'   => 'manual_review',
                    'secondary_href'  => '#quote-commercial',
                    'secondary_label' => __('Controleer commercieel overzicht', 'sbdp'),
                ),
            );
        }

        if ($unscheduledLines > 0) {
            $items[] = array(
                'title'       => sprintf(__('Planning nog onvolledig op %d regel(s)', 'sbdp'), $unscheduledLines),
                'description' => __('Datum of starttijd ontbreekt nog op één of meer regels. Dat maakt een voorstel minder direct bruikbaar voor operators en klanten.', 'sbdp'),
                'action'      => array(
                    'type'            => 'followup_create',
                    'label'           => __('Vraag planning aan', 'sbdp'),
                    'title'           => __('Werk ontbrekende datum of tijd in quote bij', 'sbdp'),
                    'note'            => __('Vraag ontbrekende datum, starttijd of serviceplanning op en werk de quote-regels daarna bij.', 'sbdp'),
                    'priority'        => 'high',
                    'followup_type'   => 'manual_review',
                    'secondary_href'  => '#quote-commercial',
                    'secondary_label' => __('Bekijk quote-regels', 'sbdp'),
                ),
            );
        }

        $label = __('Interne werkversie', 'sbdp');
        $title = __('Nog niet klaar als klantvoorstel', 'sbdp');
        $description = __('Gebruik deze versie nog intern. Eerst review, blockers en commerciële onduidelijkheden wegwerken.', 'sbdp');
        $badgeClass = 'is-neutral';

        if ((string) ($quote['review_status'] ?? 'not_started') === 'approved' && $sendBlockingAssumptions === 0) {
            if (
                $blockingAssumptions === 0
                && $mappedLines === count($lines)
                && $projectedPricingLines === 0
                && $projectedAvailabilityLines === 0
                && $unscheduledLines === 0
                && count($lines) > 0
            ) {
                $label = __('Klantvoorstel met bevestigde kern', 'sbdp');
                $title = __('Commercieel sterk genoeg om te versturen', 'sbdp');
                $description = __('De regels zijn gemapt, prijs en beschikbaarheid zijn bevestigd op workspaceniveau en de review-flow is afgerond.', 'sbdp');
                $badgeClass = 'is-good';
            } else {
                $label = __('Richtinggevend klantvoorstel', 'sbdp');
                $title = __('Deelbaar, maar expliciet onder voorbehoud', 'sbdp');
                $description = __('Deze quote kan commercieel worden gedeeld, maar operators moeten projected prijs of beschikbaarheid nog duidelijk als voorbehoud benoemen.', 'sbdp');
                $badgeClass = 'is-warn';
            }
        }

        return array(
            'label'       => $label,
            'title'       => $title,
            'description' => $description,
            'badge_class' => $badgeClass,
            'items'       => $items,
        );
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed>|null $currentVersion
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $assumptions
     * @param array<string, mixed> $sendReadiness
     * @return array<string, mixed>
     */
    private static function buildQuoteCommunicationState(array $quote, ?array $currentVersion, array $messages, array $assumptions, array $sendReadiness): array
    {
        $latestProposal = null;
        $latestInbound = null;
        $latestOutbound = null;
        foreach ($messages as $message) {
            if ((string) ($message['direction'] ?? '') === 'outbound' && (string) ($message['status'] ?? '') === 'sent') {
                $latestOutbound = $message;
                if ((string) ($message['message_type'] ?? '') === 'proposal') {
                    $latestProposal = $message;
                }
            }
            if ((string) ($message['direction'] ?? '') === 'inbound') {
                $latestInbound = $message;
            }
        }
        $proposalSendBlockers = self::formatSendReadinessBlockers($sendReadiness);

        $proposalLabel = __('Nothing sent', 'sbdp');
        $proposalBadgeClass = 'is-neutral';
        $threadLabel = __('No thread yet', 'sbdp');
        $threadBadgeClass = 'is-neutral';
        $headline = __('Nog geen voorstelmail verstuurd.', 'sbdp');
        $description = __('Gebruik de voorstelmail als eerste expliciete klantcommunicatie. Review/send truth blijft daarnaast apart zichtbaar.', 'sbdp');
        $operatorActionTitle = __('Eerst voorstel versturen', 'sbdp');
        $operatorActionDescription = __('Maak of controleer eerst de voorstelmail. Zonder echte outbound mail is er nog geen klantthread om op te werken.', 'sbdp');
        $operatorActionAgeLabel = '';
        $operatorActionAgeBadgeClass = 'is-neutral';
        $proposalSendReady = false;
        $proposalSendBlockReason = __('Vraag eerst review aan, los blockers op en keur de review goed voordat de voorstelmail verzonden mag worden.', 'sbdp');
        $replyReady = false;
        $replyBlockReason = __('Verstuur eerst een voorstelmail. Zonder verzonden voorstel bestaat er nog geen reply-thread.', 'sbdp');
        $proposalAlreadySent = $latestProposal !== null;

        if ($latestProposal !== null) {
            $proposalLabel = __('Proposal sent', 'sbdp');
            $proposalBadgeClass = 'is-good';
            $headline = __('Voorstelmail is verzonden.', 'sbdp');
            $description = __('De quote heeft nu een expliciete uitgaande communicatiehistorie met versie-linking.', 'sbdp');
            $operatorActionTitle = __('Wacht op klantreactie', 'sbdp');
            $operatorActionDescription = __('Er staat geen nieuw inbound bericht open. Houd de thread in de gaten of plan een follow-up buiten de reply-flow.', 'sbdp');

            if ($latestInbound !== null && self::messageOccurredAfter($latestInbound, $latestProposal)) {
                $replyReady = true;
                $replyBlockReason = '';
                $threadLabel = __('Waiting on us', 'sbdp');
                $threadBadgeClass = 'is-warn';
                $headline = __('Klant heeft gereageerd.', 'sbdp');
                $description = __('Er staat een inbound reply klaar. Vat deze samen of maak direct een antwoorddraft voordat je reageert.', 'sbdp');
                $operatorActionTitle = __('Reageer op de laatste klantreply', 'sbdp');
                $operatorActionDescription = __('Lees de laatste inbound reply, vat die samen en maak daarna een antwoorddraft zodat de thread niet blijft hangen bij operations.');
                $operatorActionAgeLabel = self::formatMessageAgeLabel($latestInbound);
                $operatorActionAgeBadgeClass = self::messageAgeBadgeClass($latestInbound);
            } elseif ($latestInbound !== null) {
                $threadLabel = __('Waiting on customer', 'sbdp');
                $threadBadgeClass = 'is-neutral';
                $description = __('De laatste klantreply is al opgevolgd met uitgaande communicatie. De thread wacht nu op de klant.', 'sbdp');
                $operatorActionTitle = __('Laatste reply is al opgevolgd', 'sbdp');
                $operatorActionDescription = __('De meest recente klantreply heeft al een uitgaande vervolgstap. Verdere actie is nu alleen nodig als je buiten de thread wilt nabellen of najagen.');
                $replyBlockReason = __('De laatste inbound klantreply is al opgevolgd met uitgaande communicatie. Wacht op een nieuwe klantreactie voordat je opnieuw een reply opstelt.', 'sbdp');
            } else {
                $threadLabel = __('Waiting on customer', 'sbdp');
                $threadBadgeClass = 'is-neutral';
                $description = __('Voorstel is verstuurd en er is nog geen klantreply zichtbaar in deze thread.', 'sbdp');
                $replyBlockReason = __('Er is nog geen inbound klantreply binnengekomen na het verzonden voorstel.', 'sbdp');
            }
        }

        if ((string) ($quote['send_status'] ?? 'not_ready') === 'ready_to_send' && $latestProposal === null) {
            $description = __('Review is afgerond, maar er is nog geen echte voorstelmail in de quote-thread vastgelegd.', 'sbdp');
        }

        if ((string) ($quote['review_status'] ?? 'not_started') === 'approved'
            && (string) ($quote['send_status'] ?? 'not_ready') === 'ready_to_send'
            && ! empty($sendReadiness['ready'])) {
            $proposalSendReady = true;
            $proposalSendBlockReason = '';
        } elseif ((string) ($quote['review_status'] ?? 'not_started') !== 'approved') {
            $proposalSendBlockReason = __('Een voorstelmail kan pas worden verstuurd na goedgekeurde review.', 'sbdp');
        } elseif (($proposalSendBlockers !== array())) {
            $proposalSendBlockReason = (string) ($proposalSendBlockers[0]['message'] ?? __('Quote is nog niet verzendklaar.', 'sbdp'));
        } elseif ((string) ($quote['send_status'] ?? 'not_ready') !== 'ready_to_send') {
            $proposalSendBlockReason = __('Deze quote staat nog niet in ready_to_send.', 'sbdp');
        }

        return array(
            'proposal_label'             => $proposalLabel,
            'proposal_badge_class'       => $proposalBadgeClass,
            'thread_label'               => $threadLabel,
            'thread_badge_class'         => $threadBadgeClass,
            'headline'                   => $headline,
            'description'                => $description,
            'latest_inbound_message_id'  => $latestInbound['id'] ?? null,
            'operator_action_title'      => $operatorActionTitle,
            'operator_action_description' => $operatorActionDescription,
            'operator_action_age_label'  => $operatorActionAgeLabel,
            'operator_action_age_badge_class' => $operatorActionAgeBadgeClass,
            'proposal_send_ready'        => $proposalSendReady,
            'proposal_already_sent'      => $proposalAlreadySent,
            'proposal_send_block_reason' => $proposalSendBlockReason,
            'proposal_send_blockers'     => $proposalSendBlockers,
            'reply_ready'                => $replyReady,
            'reply_block_reason'         => $replyBlockReason,
            'latest_outbound_version_label' => $latestProposal !== null && ! empty($latestProposal['quote_version_id'])
                ? __('Versie gekoppeld aan voorstelmail', 'sbdp')
                : ($currentVersion !== null && $latestProposal !== null
                    ? sprintf(__('Versie #%d verzonden', 'sbdp'), (int) ($currentVersion['version_number'] ?? 1))
                    : ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function inspectQuoteSendReadiness(int $quoteId, array $quote, ?array $currentVersion, QuoteRepositoryInterface $repository): array
    {
        $workflowBlockers = array();
        if ((string) ($quote['review_status'] ?? 'not_started') !== 'approved') {
            $workflowBlockers[] = array(
                'code' => 'review_not_approved',
                'message' => __('Een voorstelmail kan pas worden verstuurd na goedgekeurde review.', 'sbdp'),
            );
        }
        if ((string) ($quote['send_status'] ?? 'not_ready') !== 'ready_to_send') {
            $workflowBlockers[] = array(
                'code' => 'send_status_not_ready',
                'message' => __('Deze quote staat nog niet in ready_to_send.', 'sbdp'),
            );
        }

        $inspection = (new QuoteSendReadinessValidator($repository))->inspect($quoteId);
        $blockers = array_merge($workflowBlockers, (array) ($inspection['blockers'] ?? array()));

        return array(
            'ready' => $workflowBlockers === array() && ! empty($inspection['ready']),
            'blockers' => $blockers,
            'version_id' => (int) ($currentVersion['id'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $sendReadiness
     * @return array<int, array{code:string,label:string,message:string}>
     */
    private static function formatSendReadinessBlockers(array $sendReadiness): array
    {
        $formatted = array();
        foreach ((array) ($sendReadiness['blockers'] ?? array()) as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }

            $code = (string) ($blocker['code'] ?? 'unknown');
            $formatted[] = array(
                'code' => $code,
                'label' => self::sendReadinessBlockerLabel($code),
                'message' => (string) ($blocker['message'] ?? ''),
            );
        }

        return $formatted;
    }

    private static function sendReadinessBlockerLabel(string $code): string
    {
        return match ($code) {
            'quote_lines_missing' => __('Geen programmaregels', 'sbdp'),
            'customer_email_invalid' => __('Klant e-mail ontbreekt', 'sbdp'),
            'send_assumption_open' => __('Open send-blocker', 'sbdp'),
            'pricing_confidence_missing' => __('Prijs confidence ontbreekt', 'sbdp'),
            'availability_confidence_missing' => __('Beschikbaarheid confidence ontbreekt', 'sbdp'),
            'line_amount_invalid' => __('Bedrag ongeldig', 'sbdp'),
            'line_amount_negative' => __('Ongeldig bedrag', 'sbdp'),
            'line_currency_invalid' => __('Ongeldige valuta', 'sbdp'),
            'mixed_currency' => __('Gemengde valuta', 'sbdp'),
            'woo_product_missing' => __('Woo product ontbreekt', 'sbdp'),
            'woo_product_unavailable' => __('Woo product niet beschikbaar', 'sbdp'),
            'woo_product_not_purchasable' => __('Woo product niet koopbaar', 'sbdp'),
            'woo_product_status_invalid' => __('Woo productstatus ongeldig', 'sbdp'),
            'woo_product_tax_invalid' => __('Woo btw-configuratie ongeldig', 'sbdp'),
            'review_not_approved' => __('Review niet afgerond', 'sbdp'),
            'send_status_not_ready' => __('Send-status niet klaar', 'sbdp'),
            default => __('Blokkade', 'sbdp'),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<int, array<string, mixed>> $followups
     * @param array<int, array<string, mixed>> $assumptions
     * @return array<string, mixed>
     */
    private static function buildCommercialIntakeNoticeState(array $lines, array $followups, array $assumptions): array
    {
        if ($lines !== array()) {
            return array('active' => false);
        }

        $openFollowups = array_values(array_filter($followups, static function ($followup): bool {
            return is_array($followup) && (string) ($followup['status'] ?? 'open') === 'open';
        }));
        $openSendAssumptions = array_values(array_filter($assumptions, static function ($assumption): bool {
            return is_array($assumption)
                && (string) ($assumption['status'] ?? 'open') === 'open'
                && in_array((string) ($assumption['assumption_type'] ?? ''), array('uncertain_pricing', 'uncertain_availability'), true);
        }));

        $followupSummaries = array();
        foreach ($openFollowups as $followup) {
            $followupSummaries[] = array(
                'title' => (string) ($followup['title'] ?? ''),
                'meta' => sprintf(
                    '%s | %s | %s',
                    (string) ($followup['followup_type'] ?? 'manual_review'),
                    (string) ($followup['priority'] ?? 'normal'),
                    (string) ($followup['status'] ?? 'open')
                ),
            );
        }

        $assumptionMessages = array();
        foreach ($openSendAssumptions as $assumption) {
            $assumptionMessages[] = (string) ($assumption['message'] ?? '');
        }

        return array(
            'active' => true,
            'title' => __('Commerciële opvolging nodig', 'sbdp'),
            'message' => __('Deze offerte bevat nog geen commerciële regels. Voeg eerst programmaregels, producten, datum/tijd en prijs toe.', 'sbdp'),
            'technical_status' => __('Geen technische fout: deze quote is nog een intake-shell en wacht op commerciële uitwerking.', 'sbdp'),
            'send_blocker_status' => __('Versturen blijft geblokkeerd totdat echte commerciële brondata is vastgelegd en bevestigd.', 'sbdp'),
            'followups' => $followupSummaries,
            'assumption_messages' => array_values(array_filter($assumptionMessages)),
        );
    }

    /**
     * @param array<string, mixed> $notice
     */
    private static function renderCommercialIntakeNotice(array $notice): void
    {
        if (empty($notice['active'])) {
            return;
        }

        echo '<section class="notice notice-warning bsp-quote-admin__workspace-notice"><p><strong>' . esc_html((string) ($notice['title'] ?? __('Commerciële opvolging nodig', 'sbdp'))) . '</strong> ' . esc_html((string) ($notice['message'] ?? '')) . '</p>';
        echo '<p>' . esc_html((string) ($notice['technical_status'] ?? '')) . '</p>';
        echo '<p>' . esc_html((string) ($notice['send_blocker_status'] ?? '')) . '</p>';

        if (($notice['followups'] ?? array()) !== array()) {
            echo '<ul class="bsp-quote-admin__checklist bsp-quote-admin__checklist--compact">';
            foreach ((array) ($notice['followups'] ?? array()) as $followup) {
                if (! is_array($followup)) {
                    continue;
                }
                echo '<li><strong>' . esc_html((string) ($followup['title'] ?? __('Open follow-up', 'sbdp'))) . '</strong><span>' . esc_html((string) ($followup['meta'] ?? '')) . '</span></li>';
            }
            echo '</ul>';
        }

        if (($notice['assumption_messages'] ?? array()) !== array()) {
            echo '<ul class="bsp-quote-admin__checklist bsp-quote-admin__checklist--compact">';
            foreach ((array) ($notice['assumption_messages'] ?? array()) as $message) {
                echo '<li><strong>' . esc_html__('Send-readiness blocker', 'sbdp') . '</strong><span>' . esc_html((string) $message) . '</span></li>';
            }
            echo '</ul>';
        }

        echo '</section>';
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function formatMessageAgeLabel(array $message): string
    {
        $ageHours = self::messageAgeInHours($message);
        if ($ageHours >= 72) {
            return __('Klantreply ouder dan 72h', 'sbdp');
        }
        if ($ageHours >= 24) {
            return __('Klantreply ouder dan 24h', 'sbdp');
        }

        return __('Nieuwe klantreply', 'sbdp');
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function messageAgeBadgeClass(array $message): string
    {
        $ageHours = self::messageAgeInHours($message);
        if ($ageHours >= 72) {
            return 'is-warn';
        }

        return $ageHours >= 24 ? 'is-neutral' : 'is-good';
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function messageAgeInHours(array $message): int
    {
        $date = (string) (($message['received_at'] ?? '') ?: ($message['sent_at'] ?? '') ?: ($message['created_at'] ?? ''));
        if ($date === '') {
            return 0;
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return 0;
        }

        return (int) floor(max(0, time() - $timestamp) / HOUR_IN_SECONDS);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<string, array<string, mixed>>
     */
    private static function resolveQuoteMessageDrafts(array $messages): array
    {
        $drafts = array(
            'proposal' => array(),
            'reply'    => array(),
        );

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            if ((string) ($message['status'] ?? '') !== 'draft') {
                continue;
            }

            $type = (string) ($message['message_type'] ?? '');
            if (($type === 'proposal' || $type === 'reply') && $drafts[$type] === array()) {
                $drafts[$type] = $message;
            }
        }

        return $drafts;
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function renderQuoteMessageCard(int $quoteId, array $message): string
    {
        $direction = (string) ($message['direction'] ?? 'outbound');
        $directionClass = $direction === 'inbound' ? 'is-inbound' : 'is-outbound';
        $subject = trim((string) ($message['subject'] ?? ''));
        $body = trim((string) ($message['body'] ?? ''));
        $summary = trim((string) ($message['body_summary'] ?? ''));
        $metaBits = array_filter(array(
            (string) ($message['message_type'] ?? ''),
            (string) ($message['status'] ?? ''),
            ! empty($message['quote_version_id']) ? sprintf(__('versie %d', 'sbdp'), (int) $message['quote_version_id']) : '',
            $direction === 'inbound'
                ? trim((string) ($message['from_email'] ?? ''))
                : trim((string) ($message['to_email'] ?? '')),
        ));
        $occurredAt = (string) (($direction === 'inbound' ? ($message['received_at'] ?? '') : ($message['sent_at'] ?? '')) ?: ($message['created_at'] ?? ''));

        $html = '<article class="bsp-quote-admin__thread-item ' . esc_attr($directionClass) . '">';
        $html .= '<div class="bsp-quote-admin__thread-meta">';
        $html .= self::renderInlineBadge($direction === 'inbound' ? __('Inbound', 'sbdp') : __('Outbound', 'sbdp'), $direction === 'inbound' ? 'is-warn' : 'is-good');
        $html .= '<strong>' . esc_html($subject !== '' ? $subject : __('Zonder onderwerp', 'sbdp')) . '</strong>';
        if ($occurredAt !== '') {
            $html .= '<span class="bsp-quote-admin__muted">' . esc_html($occurredAt) . '</span>';
        }
        $html .= '</div>';
        if ($metaBits !== array()) {
            $html .= '<div class="bsp-quote-admin__cell-stack"><span class="bsp-quote-admin__muted">' . esc_html(implode(' | ', $metaBits)) . '</span></div>';
        }
        if ($summary !== '') {
            $html .= '<p class="bsp-quote-admin__message-summary"><strong>' . esc_html__('Samenvatting', 'sbdp') . ':</strong> ' . esc_html($summary) . '</p>';
        }
        if ($body !== '') {
            $html .= '<div class="bsp-quote-admin__message-body">' . nl2br(esc_html($body)) . '</div>';
        }
        if ($direction === 'inbound') {
            $html .= '<div class="bsp-quote-admin__actions bsp-quote-admin__thread-actions">';
            $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">' . wp_nonce_field('sbdp_quote_summarize_message', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_summarize_message"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_id" value="' . esc_attr((string) ($message['id'] ?? 0)) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button button-secondary" type="submit">' . esc_html__('Samenvatten', 'sbdp') . '</button></form>';
            $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">' . wp_nonce_field('sbdp_quote_generate_response_draft', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_generate_response_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_id" value="' . esc_attr((string) ($message['id'] ?? 0)) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button" type="submit">' . esc_html__('Antwoorddraft', 'sbdp') . '</button></form>';
            $html .= '</div>';
        }
        $html .= '</article>';

        return $html;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private static function messageOccurredAfter(array $left, array $right): bool
    {
        $leftTime = (string) (($left['received_at'] ?? '') ?: ($left['sent_at'] ?? '') ?: ($left['created_at'] ?? ''));
        $rightTime = (string) (($right['received_at'] ?? '') ?: ($right['sent_at'] ?? '') ?: ($right['created_at'] ?? ''));

        return $leftTime !== '' && $rightTime !== '' && strcmp($leftTime, $rightTime) > 0;
    }

    /**
     * @param array<int, array<string, mixed>> $failures
     * @return array<int, array<string, mixed>>
     */
    private static function sortInboxFailures(array $failures): array
    {
        usort($failures, static function (array $left, array $right): int {
            $leftOpen = (string) ($left['status'] ?? 'open') === 'open' ? 0 : 1;
            $rightOpen = (string) ($right['status'] ?? 'open') === 'open' ? 0 : 1;

            if ($leftOpen !== $rightOpen) {
                return $leftOpen <=> $rightOpen;
            }

            $leftTime = self::failureTimestamp($left);
            $rightTime = self::failureTimestamp($right);

            if ($leftOpen === 0) {
                return $leftTime <=> $rightTime;
            }

            return $rightTime <=> $leftTime;
        });

        return $failures;
    }

    /**
     * @param array<string, mixed> $failure
     * @param array<string, mixed>|null $suggestedQuote
     * @return array<string, string>
     */
    private static function buildInboxFailureTriage(array $failure, ?array $suggestedQuote): array
    {
        $status = (string) ($failure['status'] ?? 'open');
        $reason = (string) ($failure['failure_reason'] ?? 'unmatched_quote');
        $ageHours = self::failureAgeInHours($failure);

        $ageLabel = __('Nieuw', 'sbdp');
        $ageBadgeClass = 'is-neutral';
        if ($status === 'open' && $ageHours >= 72) {
            $ageLabel = __('Ouder dan 72h', 'sbdp');
            $ageBadgeClass = 'is-warn';
        } elseif ($status === 'open' && $ageHours >= 24) {
            $ageLabel = __('Ouder dan 24h', 'sbdp');
            $ageBadgeClass = 'is-neutral';
        } elseif ($status !== 'open') {
            $ageLabel = __('Afgerond', 'sbdp');
            $ageBadgeClass = 'is-good';
        }

        if ($status !== 'open') {
            return array(
                'age_label' => $ageLabel,
                'age_badge_class' => $ageBadgeClass,
                'action_title' => __('Afgehandeld', 'sbdp'),
                'action_description' => __('Deze inbound reply is al gekoppeld. Gebruik de gekoppelde quote voor verdere opvolging.', 'sbdp'),
            );
        }

        if ($suggestedQuote !== null) {
            return array(
                'age_label' => $ageLabel,
                'age_badge_class' => $ageBadgeClass,
                'action_title' => __('Nu doen', 'sbdp'),
                'action_description' => __('Open eerst de voorgestelde quote, controleer afzender en context, en koppel daarna deze reply aan de thread.', 'sbdp'),
            );
        }

        if ($reason === 'invalid_sender') {
            return array(
                'age_label' => $ageLabel,
                'age_badge_class' => $ageBadgeClass,
                'action_title' => __('Nu doen', 'sbdp'),
                'action_description' => __('Verifieer eerst het afzenderadres. Koppel deze reply pas nadat duidelijk is welke klant of quote erbij hoort.', 'sbdp'),
            );
        }

        if ($reason === 'missing_content') {
            return array(
                'age_label' => $ageLabel,
                'age_badge_class' => $ageBadgeClass,
                'action_title' => __('Nu doen', 'sbdp'),
                'action_description' => __('Controleer headers en mailboxcontext. Zonder onderwerp of body is veilig koppelen lastiger en moet je eerst meer context verzamelen.', 'sbdp'),
            );
        }

        return array(
            'age_label' => $ageLabel,
            'age_badge_class' => $ageBadgeClass,
            'action_title' => __('Nu doen', 'sbdp'),
            'action_description' => __('Zoek de bedoelde quote op afzender, onderwerp en eventdatum. Koppel daarna de reply zodat de thread weer in de workspace zichtbaar is.', 'sbdp'),
        );
    }

    /**
     * @param array<string, mixed> $failure
     */
    private static function failureAgeInHours(array $failure): int
    {
        $timestamp = self::failureTimestamp($failure);
        if ($timestamp <= 0) {
            return 0;
        }

        return (int) floor(max(0, time() - $timestamp) / HOUR_IN_SECONDS);
    }

    /**
     * @param array<string, mixed> $failure
     */
    private static function failureTimestamp(array $failure): int
    {
        $date = trim((string) ($failure['created_at'] ?? ''));
        if ($date === '') {
            return 0;
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? 0 : $timestamp;
    }

    /**
     * @param array<string, mixed> $nextAction
     */
    private static function renderQuotePrimaryAction(int $quoteId, array $nextAction): string
    {
        $cta = (string) ($nextAction['cta'] ?? 'review_request');
        switch ($cta) {
            case 'review_approve':
                return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_review_approve', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_review_approve"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-primary" type="submit">' . esc_html__('Keur review goed', 'sbdp') . '</button></form>';
            case 'send_mark_sent':
                return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_mark_sent_manual', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_mark_sent_manual"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="send_channel" value="manual"><input type="hidden" name="send_note" value=""><button class="button button-primary" type="submit">' . esc_html__('Markeer als verzonden', 'sbdp') . '</button></form>';
            case 'proposal_readiness':
                return '<a class="button button-primary" href="#quote-operator-decision">' . esc_html__('Doorloop verzendcheck', 'sbdp') . '</a>';
            case 'proposal_draft':
                return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_generate_proposal_draft', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_generate_proposal_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button button-primary" type="submit">' . esc_html__('Maak voorstelmail', 'sbdp') . '</button></form>';
            case 'reply_draft':
                return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_generate_response_draft', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_generate_response_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_id" value="' . esc_attr((string) ((int) ($nextAction['message_id'] ?? 0))) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button button-primary" type="submit">' . esc_html__('Verwerk klantreactie', 'sbdp') . '</button></form>';
            case 'assumptions':
                return '<a class="button button-primary" href="#quote-assumptions">' . esc_html__('Bekijk blockers', 'sbdp') . '</a>';
            case 'followups':
                return '<a class="button button-primary" href="#quote-followups">' . esc_html__('Open follow-ups', 'sbdp') . '</a>';
            case 'handoff':
                return '<a class="button button-primary" href="#quote-handoff">' . esc_html__('Open handoff', 'sbdp') . '</a>';
            case 'review_request':
            default:
                return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_review_request', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_review_request"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-primary" type="submit">' . esc_html__('Vraag review aan', 'sbdp') . '</button></form>';
        }
    }

    private static function resolveWorkspaceTab(): string
    {
        $tab = isset($_GET['workspace_tab']) ? (string) $_GET['workspace_tab'] : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return self::normalizeWorkspaceTab($tab, 'dashboard');
    }

    private static function normalizeWorkspaceTab(string $tab, string $default = 'dashboard'): string
    {
        $tab = sanitize_key($tab);
        $default = sanitize_key($default);
        $allowedTabs = array('dashboard', 'build', 'proposal', 'communication', 'workflow', 'history');

        if (! in_array($default, $allowedTabs, true)) {
            $default = 'dashboard';
        }

        return in_array($tab, $allowedTabs, true) ? $tab : $default;
    }

    /**
     * @param array<string, mixed> $action
     */
    private static function renderWorkspaceAction(int $quoteId, array $action): string
    {
        $type = (string) ($action['type'] ?? '');
        $label = (string) ($action['label'] ?? '');
        $secondaryHref = (string) ($action['secondary_href'] ?? '');
        $secondaryLabel = (string) ($action['secondary_label'] ?? '');
        $secondary = '';

        if ($secondaryHref !== '' && $secondaryLabel !== '') {
            $secondary = '<a class="button-link" href="' . esc_url($secondaryHref) . '">' . esc_html($secondaryLabel) . '</a>';
        }

        if ($label === '') {
            return $secondary;
        }

        switch ($type) {
            case 'review_request':
                return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">' . wp_nonce_field('sbdp_quote_review_request', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_review_request"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-secondary" type="submit">' . esc_html($label) . '</button></form>' . $secondary;
            case 'review_approve':
                return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">' . wp_nonce_field('sbdp_quote_review_approve', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_review_approve"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-secondary" type="submit">' . esc_html($label) . '</button></form>' . $secondary;
            case 'followup_create':
                return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">' . wp_nonce_field('sbdp_quote_followup_create', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_followup_create"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="title" value="' . esc_attr((string) ($action['title'] ?? '')) . '"><input type="hidden" name="note" value="' . esc_attr((string) ($action['note'] ?? '')) . '"><input type="hidden" name="priority" value="' . esc_attr((string) ($action['priority'] ?? 'normal')) . '"><input type="hidden" name="followup_type" value="' . esc_attr((string) ($action['followup_type'] ?? 'manual_review')) . '"><button class="button button-secondary" type="submit">' . esc_html($label) . '</button></form>' . $secondary;
            case 'link':
                $href = (string) ($action['href'] ?? '');
                if ($href === '') {
                    return $secondary;
                }

                return '<a class="button-link" href="' . esc_url($href) . '">' . esc_html($label) . '</a>' . $secondary;
            default:
                return $secondary;
        }
    }

    private static function formatMoney(float $amount, string $currency): string
    {
        $formatted = \function_exists('number_format_i18n')
            ? (string) \number_format_i18n($amount, 2)
            : number_format($amount, 2, ',', '.');

        return trim($currency . ' ' . $formatted);
    }

    private static function renderCreateRequestForm(bool $withWrapper = true): void
    {
        if ($withWrapper) {
            echo '<div class="postbox" style="padding:16px; margin:16px 0;">';
        }

        echo '<div class="bsp-quote-admin__request-form"><h2>' . esc_html__('Nieuwe aanvraag invoeren', 'sbdp') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        \wp_nonce_field('sbdp_quote_request_create');
        echo '<input type="hidden" name="action" value="sbdp_quote_request_create"><p><label>' . esc_html__('Naam', 'sbdp') . '<br><input class="regular-text" type="text" name="requester_name"></label></p><p><label>' . esc_html__('E-mail', 'sbdp') . '<br><input class="regular-text" type="email" name="requester_email"></label></p><p><label>' . esc_html__('Bedrijf', 'sbdp') . '<br><input class="regular-text" type="text" name="requester_company"></label></p><p><label>' . esc_html__('Telefoon', 'sbdp') . '<br><input class="regular-text" type="text" name="requester_phone"></label></p><p><label>' . esc_html__('Straat en huisnummer', 'sbdp') . '<br><input class="regular-text" type="text" name="requester_address_1"></label></p><p><label>' . esc_html__('Adresregel extra', 'sbdp') . '<br><input class="regular-text" type="text" name="requester_address_2"></label></p><p><label>' . esc_html__('Postcode', 'sbdp') . '<br><input class="regular-text" type="text" name="requester_postcode"></label></p><p><label>' . esc_html__('Plaats', 'sbdp') . '<br><input class="regular-text" type="text" name="requester_city"></label></p><p><label>' . esc_html__('Landcode', 'sbdp') . '<br><input class="small-text" type="text" name="requester_country" value="NL"></label></p><p><label>' . esc_html__('Groepsgrootte', 'sbdp') . '<br><input class="small-text" type="number" name="group_size" min="0" value="0"></label></p><p><label>' . esc_html__('Voorkeursdatum', 'sbdp') . '<br><input class="regular-text" type="date" name="preferred_date"></label></p><p><label>' . esc_html__('Klantnotitie', 'sbdp') . '<br><textarea class="large-text" name="requester_message" rows="3"></textarea></label></p><p><label>' . esc_html__('Samenvatting', 'sbdp') . '<br><textarea class="large-text" name="request_summary" rows="4" required></textarea></label></p><p><button class="button button-primary" type="submit">' . esc_html__('Quote request opslaan', 'sbdp') . '</button></p></form></div>';

        if ($withWrapper) {
            echo '</div>';
        }
    }

    private static function renderNotices(): void
    {
        $notices = array(
            'quote_request_created' => __('Quote request aangemaakt.', 'sbdp'),
            'quote_converted'       => __('Quote aangemaakt vanuit request.', 'sbdp'),
            'review_requested'      => __('Quote ter review aangeboden.', 'sbdp'),
            'review_approved'       => __('Quote review goedgekeurd.', 'sbdp'),
            'review_returned'       => __('Quote teruggezet naar draft.', 'sbdp'),
            'quote_marked_sent'     => __('Quote als handmatig verzonden gemarkeerd.', 'sbdp'),
            'quote_send_reopened'   => __('Quote teruggezet naar ready_to_send.', 'sbdp'),
            'quote_operations_saved' => __('Operations draft opgeslagen in de actieve quote-versie.', 'sbdp'),
            'quote_message_draft_generated' => __('Berichtdraft opgeslagen in de quote-thread.', 'sbdp'),
            'quote_message_summarized' => __('Inbound klantreply samengevat.', 'sbdp'),
            'quote_message_sent'    => __('Quote-mail verstuurd en vastgelegd in de thread.', 'sbdp'),
            'quote_inbound_logged'  => __('Inbound klantreply opgeslagen in de quote-thread.', 'sbdp'),
            'quote_intake_updated'  => __('Intakecontext bijgewerkt en intake-blockers opnieuw beoordeeld.', 'sbdp'),
            'quote_assumption_resolved' => __('Commerciële assumption opgelost en vrijgegeven voor de volgende workflowstap.', 'sbdp'),
            'quote_inbound_resolved' => __('Inbound inbox-item gekoppeld en verwerkt.', 'sbdp'),
            'followup_created'      => __('Follow-up aangemaakt.', 'sbdp'),
            'followup_completed'    => __('Follow-up afgerond.', 'sbdp'),
            'handoff_ready'         => __('Quote gemarkeerd als ready_for_resnapshot.', 'sbdp'),
            'resnapshot_prepared'   => __('Execution resnapshot voorbereid.', 'sbdp'),
            'handoff_package_ready' => __('Controlled handoff package opgebouwd.', 'sbdp'),
            'execution_payload_ready' => __('Execution adapter payload opgebouwd.', 'sbdp'),
            'execution_validated'   => __('Execution payload runtime gevalideerd.', 'sbdp'),
            'execution_launch_ready' => __('Execution launch payload opgebouwd.', 'sbdp'),
            'woo_cart_hydrated'     => __('Woo cart is gehydrateerd vanuit het launch payload.', 'sbdp'),
            'quote_ai_mail_saved'   => __('Quote AI & Mail instellingen opgeslagen.', 'sbdp'),
        );

        foreach ($notices as $key => $message) {
            if (! isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                continue;
            }

            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }

        if (isset($_GET['quote_error']) && is_string($_GET['quote_error'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-error"><p>' . esc_html(rawurldecode((string) $_GET['quote_error'])) . '</p></div>';
        }

        if (isset($_GET['cart_url']) && is_string($_GET['cart_url'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $cartUrl = rawurldecode((string) $_GET['cart_url']);
            echo '<div class="notice notice-info"><p><a href="' . esc_url($cartUrl) . '">' . esc_html__('Open Woo winkelwagen', 'sbdp') . '</a></p></div>';
        }
    }

    private static function redirect(string $page, array $query = array()): void
    {
        $url = add_query_arg(array_merge(array('page' => $page), $query), admin_url('admin.php'));
        wp_redirect($url);
        exit;
    }

    private static function assertAccess(): void
    {
        if (! self::canManageQuotes()) {
            wp_die(esc_html__('U heeft geen toegang tot Quote OS.', 'sbdp'), 403);
        }
    }

    public static function canManageQuotes(): bool
    {
        return current_user_can(self::CAPABILITY) || current_user_can(self::FALLBACK_CAPABILITY);
    }

    private static function capability(): string
    {
        return current_user_can(self::CAPABILITY) ? self::CAPABILITY : self::FALLBACK_CAPABILITY;
    }

    /**
     * @param array<int, array<string, mixed>> $requests
     */
    private static function renderRequestStats(array $requests): void
    {
        $newCount = 0;
        $convertedCount = 0;
        foreach ($requests as $request) {
            $status = (string) ($request['status'] ?? 'new');
            if ($status === 'new') {
                $newCount++;
            }
            if ($status === 'converted_to_quote') {
                $convertedCount++;
            }
        }

        echo '<div class="bsp-quote-admin__stats">';
        echo self::renderStatCard(__('Totaal requests', 'sbdp'), (string) count($requests));
        echo self::renderStatCard(__('Nieuw', 'sbdp'), (string) $newCount);
        echo self::renderStatCard(__('Omgezet', 'sbdp'), (string) $convertedCount);
        echo '</div>';
    }

    /**
     * @param array<int, array<string, mixed>> $quotes
     */
    private static function renderQuoteStats(array $quotes): void
    {
        $draftCount = 0;
        $reviewCount = 0;
        $readyCount = 0;
        foreach ($quotes as $quote) {
            if ((string) ($quote['status'] ?? '') === 'draft') {
                $draftCount++;
            }
            if ((string) ($quote['review_status'] ?? '') === 'pending_review') {
                $reviewCount++;
            }
            if ((string) ($quote['handoff_status'] ?? '') === 'ready_for_resnapshot') {
                $readyCount++;
            }
        }

        echo '<div class="bsp-quote-admin__stats">';
        echo self::renderStatCard(__('Totaal quotes', 'sbdp'), (string) count($quotes));
        echo self::renderStatCard(__('Draft', 'sbdp'), (string) $draftCount);
        echo self::renderStatCard(__('In review', 'sbdp'), (string) $reviewCount);
        echo self::renderStatCard(__('Ready for resnapshot', 'sbdp'), (string) $readyCount);
        echo '</div>';
    }

    private static function renderStatCard(string $label, string $value): string
    {
        return '<div class="bsp-quote-admin__stat"><span class="bsp-quote-admin__stat-value">' . esc_html($value) . '</span><span class="bsp-quote-admin__stat-label">' . esc_html($label) . '</span></div>';
    }

    private static function renderInlineBadge(string $label, string $className): string
    {
        return '<span class="bsp-quote-admin__badge ' . esc_attr($className) . '">' . esc_html($label) . '</span>';
    }

    private static function renderInlineLink(string $url, string $label): string
    {
        return '<a class="bsp-quote-admin__link" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readOpenAiStatus(): ?array
    {
        $status = \get_option('bsp_quotes_openai_last_status', array());

        return is_array($status) && $status !== array() ? $status : null;
    }

    /**
     * @param array<string, mixed> $status
     */
    private static function renderOpenAiStatusNotice(array $status): string
    {
        $state = (string) ($status['state'] ?? 'unknown');
        $httpStatus = isset($status['http_status']) && $status['http_status'] !== null
            ? (string) (int) $status['http_status']
            : '';
        $message = trim((string) ($status['message'] ?? ''));
        $model = trim((string) ($status['model'] ?? ''));
        $updatedAt = trim((string) ($status['updated_at'] ?? ''));

        $className = match ($state) {
            'ok' => 'bsp-quote-admin__readiness-summary',
            'disabled' => 'bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--compact',
            default => 'bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--action',
        };

        $title = match ($state) {
            'ok' => __('OpenAI actief', 'sbdp'),
            'disabled' => __('OpenAI uitgeschakeld', 'sbdp'),
            default => __('OpenAI fallback actief', 'sbdp'),
        };

        $meta = array_filter(array(
            $model !== '' ? sprintf(__('model %s', 'sbdp'), $model) : '',
            $httpStatus !== '' ? sprintf(__('HTTP %s', 'sbdp'), $httpStatus) : '',
            $updatedAt !== '' ? $updatedAt : '',
        ));

        $html = '<div class="' . esc_attr($className) . '">';
        $html .= '<strong>' . esc_html($title) . '</strong>';
        if ($message !== '') {
            $html .= '<p>' . esc_html($message) . '</p>';
        }
        if ($meta !== array()) {
            $html .= '<p class="bsp-quote-admin__muted">' . esc_html(implode(' | ', $meta)) . '</p>';
        }
        $html .= '</div>';

        return $html;
    }

    private static function resolveIntakeAssumptions(
        QuoteRepository $repository,
        int $quoteId,
        int $groupSize,
        string $preferredDate,
        ?int $actorId
    ): void {
        $now = \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');

        foreach ($repository->listQuoteAssumptions($quoteId) as $assumption) {
            if (! is_array($assumption) || (string) ($assumption['status'] ?? 'open') !== 'open') {
                continue;
            }

            $type = (string) ($assumption['assumption_type'] ?? '');
            $shouldResolve = ($type === 'missing_group_size' && $groupSize > 0)
                || ($type === 'missing_date' && $preferredDate !== '');

            if (! $shouldResolve) {
                continue;
            }

            $repository->updateQuoteAssumption((int) ($assumption['id'] ?? 0), array(
                'status' => 'resolved',
                'resolution_note' => 'Automatisch opgelost na intake-update in quote-workspace.',
                'blocks_review' => 0,
                'blocks_send' => 0,
                'blocks_handoff' => 0,
                'resolved_at' => $now,
                'resolved_by' => $actorId,
            ));
        }
    }

    private static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'reviewed', 'converted_to_quote', 'mapped', 'sent' => 'is-good',
            'draft', 'new' => 'is-neutral',
            default => 'is-warn',
        };
    }

    private static function workflowBadgeClass(string $status): string
    {
        return match ($status) {
            'approved', 'ready_to_send', 'ready_for_resnapshot', 'execution_payload_ready', 'execution_validated', 'execution_launch_ready', 'woo_cart_hydrated' => 'is-good',
            'not_started', 'not_ready' => 'is-neutral',
            default => 'is-warn',
        };
    }

    private static function confidenceBadgeClass(string $status): string
    {
        return match ($status) {
            'execution_verified', 'confirmed' => 'is-good',
            'snapshot', 'projected' => 'is-warn',
            default => 'is-neutral',
        };
    }

    private static function renderAdminStyles(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }

        $rendered = true;

        echo '<style>
            .bsp-quote-admin__stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0 20px}
            .bsp-quote-admin__stat{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;display:flex;flex-direction:column;gap:4px}
            .bsp-quote-admin__stat-value{font-size:24px;font-weight:600;line-height:1.2}
            .bsp-quote-admin__stat-label,.bsp-quote-admin__muted{color:#50575e}
            .bsp-quote-admin__badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:600;line-height:1.4;background:#f0f0f1;color:#1d2327;margin:0 4px 4px 0}
            .bsp-quote-admin__badge.is-good{background:#dff5e3;color:#0a5222}
            .bsp-quote-admin__badge.is-warn{background:#fff3cd;color:#7a4b00}
            .bsp-quote-admin__badge.is-neutral{background:#eef2f7;color:#324055}
            .bsp-quote-admin__actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
            .bsp-quote-admin__actions--stacked{align-items:flex-start}
            .bsp-quote-admin__link{text-decoration:none}
            .bsp-quote-admin__workspace{display:flex;flex-direction:column;gap:16px;margin-top:16px}
            .bsp-quote-admin__workspace-tabs{margin:0;padding-top:0}
            .bsp-quote-admin__workspace-stack{display:flex;flex-direction:column;gap:16px}
            .bsp-quote-admin__workspace-hero{margin:0;padding:0}
            .bsp-quote-admin__workspace-hero-inner{display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:16px;padding:20px;border-bottom:1px solid #dcdcde}
            .bsp-quote-admin__workspace-heading h2{margin:0 0 6px;font-size:28px;line-height:1.2}
            .bsp-quote-admin__hero-summary{margin:0 0 10px;color:#50575e;max-width:72ch}
            .bsp-quote-admin__eyebrow,.bsp-quote-admin__next-action-label,.bsp-quote-admin__field-label{text-transform:uppercase;letter-spacing:.04em;font-size:11px;font-weight:700;color:#646970}
            .bsp-quote-admin__badge-row{display:flex;flex-wrap:wrap;gap:4px}
            .bsp-quote-admin__next-action{background:#f6f7f7;border:1px solid #dcdcde;border-radius:10px;padding:16px;display:flex;flex-direction:column;gap:10px}
            .bsp-quote-admin__next-action strong{font-size:18px;line-height:1.3}
            .bsp-quote-admin__next-action p{margin:0;color:#50575e}
            .bsp-quote-admin__workspace-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;padding:16px 20px 20px}
            .bsp-quote-admin__workspace-grid{display:grid;grid-template-columns:minmax(0,1.8fr) minmax(320px,1fr);gap:16px;align-items:start}
            .bsp-quote-admin__workspace-main,.bsp-quote-admin__workspace-side{display:flex;flex-direction:column;gap:16px}
            .bsp-quote-admin__panel{margin:0}
            .bsp-quote-admin__panel--muted{opacity:.96}
            .bsp-quote-admin__panel--operator{position:sticky;top:32px}
            .bsp-quote-admin__panel--collapsible > .bsp-quote-admin__panel-body{padding-top:0}
            .bsp-quote-admin__panel-header{padding:16px 16px 0}
            .bsp-quote-admin__panel-header h3{margin:0 0 4px;font-size:18px}
            .bsp-quote-admin__panel-header p{margin:0}
            .bsp-quote-admin__panel-body{padding:16px}
            .bsp-quote-admin__panel-toggle{list-style:none;cursor:pointer;padding:16px;display:flex;align-items:center;justify-content:space-between;gap:12px}
            .bsp-quote-admin__panel-toggle::-webkit-details-marker{display:none}
            .bsp-quote-admin__panel-toggle span{display:flex;flex-direction:column;gap:4px}
            .bsp-quote-admin__panel-toggle small{color:#50575e}
            .bsp-quote-admin__proposal-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:12px}
            .bsp-quote-admin__proposal-summary strong{display:block;margin-top:4px}
            .bsp-quote-admin__proposal-copy{margin:0 0 12px;max-width:72ch}
            .bsp-quote-admin__totals-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:12px 0 16px}
            .bsp-quote-admin__program{display:flex;flex-direction:column;gap:16px}
            .bsp-quote-admin__program-day{display:flex;flex-direction:column;gap:12px}
            .bsp-quote-admin__program-day-header{display:flex;justify-content:space-between;gap:12px;align-items:baseline;border-bottom:1px solid #dcdcde;padding-bottom:8px}
            .bsp-quote-admin__program-day-header h4{margin:0;font-size:16px}
            .bsp-quote-admin__program-list{display:flex;flex-direction:column;gap:12px}
            .bsp-quote-admin__program-item{display:grid;grid-template-columns:110px minmax(0,1fr);gap:16px;padding:16px;border:1px solid #dcdcde;border-radius:10px;background:linear-gradient(180deg,#ffffff 0%,#fcfcfd 100%)}
            .bsp-quote-admin__program-time{display:flex;flex-direction:column;gap:4px;padding-right:12px;border-right:1px solid #ececec}
            .bsp-quote-admin__program-time strong{font-size:20px;line-height:1.2}
            .bsp-quote-admin__program-time span{color:#50575e}
            .bsp-quote-admin__program-body{display:flex;flex-direction:column;gap:8px;min-width:0}
            .bsp-quote-admin__program-body h4{margin:0;font-size:18px;line-height:1.3}
            .bsp-quote-admin__program-subtitle{margin:0;color:#50575e}
            .bsp-quote-admin__program-price{display:flex;flex-direction:column;gap:2px}
            .bsp-quote-admin__program-note{margin:0}
            .bsp-quote-admin__table-wrap{overflow:auto}
            .bsp-quote-admin__lines-table td,.bsp-quote-admin__lines-table th{vertical-align:top}
            .bsp-quote-admin__cell-stack{display:flex;flex-direction:column;gap:4px;margin-top:4px}
            .bsp-quote-admin__checklist,.bsp-quote-admin__timeline{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:10px}
            .bsp-quote-admin__checklist li,.bsp-quote-admin__timeline li{display:flex;flex-direction:column;gap:4px;padding:12px;border:1px solid #dcdcde;border-radius:8px;background:#fff}
            .bsp-quote-admin__checklist--compact{margin-top:8px;gap:8px}
            .bsp-quote-admin__checklist--compact li{padding:10px;background:#fcfcfd}
            .bsp-quote-admin__checklist--actions li{gap:6px}
            .bsp-quote-admin__checklist--actions .button-link{font-weight:600;text-decoration:none}
            .bsp-quote-admin__inline-form{display:inline-flex;align-items:center}
            .bsp-quote-admin__checklist--actions .bsp-quote-admin__inline-form + .button-link,
            .bsp-quote-admin__checklist--actions .button-link + .button-link{margin-top:2px}
            .bsp-quote-admin__readiness-summary{padding:12px;border-radius:8px;background:#f6f7f7;border:1px solid #dcdcde;margin-bottom:12px}
            .bsp-quote-admin__readiness-summary--operator{background:#fff;border-color:#d0d7de}
            .bsp-quote-admin__readiness-summary--compact{margin-top:12px;margin-bottom:0}
            .bsp-quote-admin__readiness-summary--action{background:#fffaf0;border-color:#e7c98b}
            .bsp-quote-admin__readiness-summary strong{display:block;margin-bottom:4px}
            .bsp-quote-admin__readiness-summary p{margin:0;color:#50575e}
            .bsp-quote-admin__focus-card{border-left:5px solid #dcdcde}
            .bsp-quote-admin__focus-card--blocked{background:#fff8f8;border-left-color:#d63638}
            .bsp-quote-admin__focus-card--assumptions{background:#fffbf0;border-left-color:#dba617}
            .bsp-quote-admin__focus-card--ready{background:#f8fff8;border-left-color:#46b450}
            .bsp-quote-admin__focus-card h2{margin:0 0 10px;font-size:24px;line-height:1.25}
            .bsp-quote-admin__focus-kicker{margin:0 0 8px;text-transform:uppercase;letter-spacing:.04em;font-size:12px;font-weight:700;color:#50575e}
            .bsp-quote-admin__focus-message{font-size:14px;margin:0 0 14px;color:#50575e;max-width:72ch}
            .bsp-quote-admin__focus-steps{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;margin:14px 0}
            .bsp-quote-admin__focus-steps ol,.bsp-quote-admin__assumption-card ol{margin:8px 0 0 20px}
            .bsp-quote-admin__focus-button{min-width:220px;text-align:center}
            .bsp-quote-admin__focus-details{margin-top:12px;color:#50575e}
            .bsp-quote-admin__assumption-card{background:#fff;border:1px solid #e7c98b;border-radius:8px;padding:14px;margin:12px 0}
            .bsp-quote-admin__assumption-card strong{display:block;font-size:15px}
            .bsp-quote-admin__assumption-card p{margin:8px 0;color:#50575e}
            .bsp-quote-admin__assumption-card .button{margin-top:10px}
            .bsp-quote-admin__overview-hero{background:linear-gradient(180deg,#fff 0%,#f6f7f7 100%)}
            .bsp-quote-admin__overview-hero h2{margin:0 0 6px;font-size:26px;line-height:1.2}
            .bsp-quote-admin__overview-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:16px}
            .bsp-quote-admin__overview-stat{display:flex;flex-direction:column;gap:4px;padding:12px 14px;border:1px solid #dcdcde;border-radius:8px;background:#fff;text-decoration:none;color:#1d2327}
            .bsp-quote-admin__overview-stat:hover,.bsp-quote-admin__overview-stat.is-active{border-color:#2271b1;box-shadow:inset 0 0 0 1px #2271b1}
            .bsp-quote-admin__overview-stat span{font-size:12px;color:#50575e;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
            .bsp-quote-admin__overview-stat strong{font-size:24px;line-height:1.1}
            .bsp-quote-admin__overview-next{border-left:5px solid #2271b1}
            .bsp-quote-admin__overview-next strong{display:block;font-size:20px;line-height:1.3;margin:4px 0}
            .bsp-quote-admin__overview-next p{margin:0 0 12px;color:#50575e}
            .bsp-quote-admin__overview-table td{vertical-align:top}
            .bsp-quote-admin__overview-row.is-action td:first-child{border-left:4px solid #d63638}
            .bsp-quote-admin__overview-row.is-assumptions td:first-child{border-left:4px solid #dba617}
            .bsp-quote-admin__overview-row.is-ready td:first-child{border-left:4px solid #46b450}
            .bsp-quote-admin__overview-row.is-done td:first-child{border-left:4px solid #8c8f94}
            .bsp-quote-admin__customer-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px}
            .bsp-quote-admin__customer-grid strong{display:block;margin-top:4px}
            .bsp-quote-admin__decision-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:16px}
            .bsp-quote-admin__decision-action{padding:14px;border:1px solid #dcdcde;border-radius:10px;background:#f6f7f7;display:flex;flex-direction:column;gap:8px}
            .bsp-quote-admin__decision-action strong{font-size:18px;line-height:1.3}
            .bsp-quote-admin__decision-action p{margin:0;color:#50575e}
            .bsp-quote-admin__workflow-group + .bsp-quote-admin__workflow-group{margin-top:16px;padding-top:16px;border-top:1px solid #dcdcde}
            .bsp-quote-admin__workflow-group h4{margin:0 0 8px}
            .bsp-quote-admin__stack-form{display:flex;flex-direction:column;gap:10px;margin-top:10px}
            .bsp-quote-admin__stack-form--muted{padding-top:12px;border-top:1px dashed #dcdcde}
            .bsp-quote-admin__stack-form label{display:flex;flex-direction:column;gap:4px;font-weight:600}
            .bsp-quote-admin__stack-form input,.bsp-quote-admin__stack-form textarea,.bsp-quote-admin__stack-form select{font-weight:400}
            .bsp-quote-admin__thread{display:flex;flex-direction:column;gap:12px;margin-top:16px}
            .bsp-quote-admin__thread-item{border:1px solid #dcdcde;border-radius:8px;padding:12px;background:#fff}
            .bsp-quote-admin__thread-item.is-inbound{background:#fffbf0}
            .bsp-quote-admin__thread-meta{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
            .bsp-quote-admin__message-summary{margin:10px 0 0}
            .bsp-quote-admin__message-body{margin-top:8px;white-space:pre-wrap;line-height:1.5}
            .bsp-quote-admin__thread-actions{margin-top:10px}
            .bsp-quote-admin__builder-intake{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:0 0 16px;padding:14px;border:1px solid #d0d7de;border-radius:10px;background:#f6f8fa}
            .bsp-quote-admin__builder-intake strong{display:block;margin-top:4px}
            .bsp-quote-admin__quote-total-card{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;padding:16px;border:1px solid #3b342d;border-radius:14px;background:#18120f;color:#fff}
            .bsp-quote-admin__quote-total-card .bsp-quote-admin__field-label,.bsp-quote-admin__quote-total-card .bsp-quote-admin__muted{color:#d7ccc2}
            .bsp-quote-admin__quote-total-card strong{display:block;margin-top:5px;font-size:20px;line-height:1.25}
            .bsp-quote-admin__quote-total-card label{display:flex;flex-direction:column;gap:6px}
            .bsp-quote-admin__quote-total-card input{max-width:180px;background:#fff;color:#1d2327}
            .bsp-quote-admin__builder-form{display:flex;flex-direction:column;gap:16px}
            .bsp-quote-admin__builder-list{display:flex;flex-direction:column;gap:12px}
            .bsp-quote-admin__builder-row{display:flex;flex-direction:column;gap:12px;padding:16px;border:1px solid #d0d7de;border-radius:14px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .bsp-quote-admin__builder-row.is-dragging{opacity:.55}
            .bsp-quote-admin__builder-row-top{display:flex;justify-content:space-between;gap:12px;align-items:center}
            .bsp-quote-admin__builder-row-order{display:flex;align-items:center;gap:10px}
            .bsp-quote-admin__builder-row-order > div{display:flex;flex-direction:column;gap:2px}
            .bsp-quote-admin__builder-row-order span{color:#50575e}
            .bsp-quote-admin__builder-handle{cursor:grab;text-decoration:none;font-size:18px;line-height:1}
            .bsp-quote-admin__builder-card-status{display:flex;flex-wrap:wrap;gap:4px}
            .bsp-quote-admin__builder-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
            .bsp-quote-admin__builder-grid--primary{grid-template-columns:minmax(240px,1.4fr) minmax(220px,1fr) minmax(170px,.7fr) minmax(150px,.6fr)}
            .bsp-quote-admin__builder-grid--commercial{grid-template-columns:180px 180px minmax(260px,1fr);align-items:end;padding:12px;border:1px solid #d0d7de;border-radius:12px;background:#fffaf0}
            .bsp-quote-admin__builder-grid label{display:flex;flex-direction:column;gap:4px;font-weight:600}
            .bsp-quote-admin__builder-grid input,.bsp-quote-admin__builder-grid select{font-weight:400}
            .bsp-quote-admin__builder-commercial-note{display:flex;flex-direction:column;gap:3px}
            .bsp-quote-admin__builder-commercial-note strong{font-size:16px}
            .bsp-quote-admin__builder-meta{display:flex;flex-direction:column;gap:4px;padding:10px 12px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7}
            .bsp-quote-admin__slot-picker{border:1px solid #d0d7de;border-radius:12px;background:#fbfcfe;padding:12px;display:flex;flex-direction:column;gap:12px}
            .bsp-quote-admin__slot-picker-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
            .bsp-quote-admin__slot-picker-head strong{display:block;margin-top:3px;font-size:16px}
            .bsp-quote-admin__slot-picker-head p{margin:4px 0 0}
            .bsp-quote-admin__slot-actions{display:flex;flex-direction:column;gap:8px;align-items:flex-start}
            .bsp-quote-admin__slot-actions label{font-size:12px;color:#50575e}
            .bsp-quote-admin__slot-list{display:flex;flex-wrap:wrap;gap:8px}
            .bsp-quote-admin__slot-pill{border:1px solid #8c8f94;border-radius:999px;background:#fff;color:#1d2327;padding:7px 12px;cursor:pointer}
            .bsp-quote-admin__slot-pill:hover,.bsp-quote-admin__slot-pill.is-selected{border-color:#0a5222;background:#dff5e3;color:#0a5222}
            .bsp-quote-admin__slot-empty{display:inline-flex;align-items:center;min-height:30px;color:#646970}
            .bsp-quote-admin__builder-advanced{border-top:1px dashed #d0d7de;padding-top:10px}
            .bsp-quote-admin__builder-advanced summary{cursor:pointer;font-weight:600;color:#50575e}
            .bsp-quote-admin__builder-advanced .bsp-quote-admin__builder-grid{margin-top:12px}
            @media (max-width: 1080px){
                .bsp-quote-admin__workspace-hero-inner,.bsp-quote-admin__workspace-grid{grid-template-columns:1fr}
                .bsp-quote-admin__program-item{grid-template-columns:1fr}
                .bsp-quote-admin__program-time{border-right:0;border-bottom:1px solid #ececec;padding-right:0;padding-bottom:8px}
                .bsp-quote-admin__panel--operator{position:static}
                .bsp-quote-admin__builder-grid--primary{grid-template-columns:1fr 1fr}
                .bsp-quote-admin__builder-grid--commercial{grid-template-columns:1fr 1fr}
            }
            @media (max-width: 782px){
                .bsp-quote-admin__workspace{gap:12px}
                .bsp-quote-admin__workspace-hero-inner{padding:14px}
                .bsp-quote-admin__workspace-heading h2{font-size:22px}
                .bsp-quote-admin__workspace-meta{grid-template-columns:1fr;padding:12px 14px 14px}
                .bsp-quote-admin__panel-body{padding:14px}
                .bsp-quote-admin__focus-card h2{font-size:20px}
                .bsp-quote-admin__focus-button{width:100%;min-width:0;text-align:center}
                .bsp-quote-admin__assumption-card .button{width:100%;text-align:center}
                .bsp-quote-admin__overview-stat-grid{grid-template-columns:1fr 1fr}
                .bsp-quote-admin__overview-next .button{width:100%;text-align:center}
                .bsp-quote-admin__customer-grid{grid-template-columns:1fr}
                .bsp-quote-admin__builder-grid--primary,.bsp-quote-admin__builder-grid--commercial,.bsp-quote-admin__slot-picker-head{grid-template-columns:1fr;display:grid}
            }
        </style>';
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function extractRequesterContext(array $request): array
    {
        $requester = isset($request['normalized_payload']['requester']) && is_array($request['normalized_payload']['requester'])
            ? $request['normalized_payload']['requester']
            : array();

        if (! isset($requester['name'])) {
            $requester['name'] = (string) ($request['requester_name'] ?? '');
        }
        if (! isset($requester['email'])) {
            $requester['email'] = (string) ($request['requester_email'] ?? '');
        }
        if (! isset($requester['phone'])) {
            $requester['phone'] = (string) ($request['requester_phone'] ?? '');
        }
        if (! isset($requester['company'])) {
            $requester['company'] = (string) ($request['requester_company'] ?? '');
        }

        return $requester;
    }

    /**
     * @param array<string, mixed> $requester
     */
    private static function formatRequesterAddress(array $requester): string
    {
        $address = isset($requester['address']) && is_array($requester['address']) ? $requester['address'] : array();
        if ($address === array()) {
            return '';
        }

        $cityLine = trim((string) (($address['postcode'] ?? '') . ' ' . ($address['city'] ?? '')));

        return implode(', ', array_filter(array(
            (string) ($address['address_1'] ?? ''),
            (string) ($address['address_2'] ?? ''),
            $cityLine,
            (string) ($address['country'] ?? ''),
        )));
    }
}
