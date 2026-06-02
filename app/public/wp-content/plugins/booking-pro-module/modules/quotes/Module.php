<?php

declare(strict_types=1);

namespace BSP\Quotes;

use BSP\Core\Interfaces\ModuleInterface;
use BSP\Quotes\Admin\Controller as AdminController;
use BSP\Quotes\PublicProposalController;
use BSP\Quotes\Rest\Controller as RestController;
use BSP\Quotes\Rest\InboundMailWebhookController;
use BSP\Quotes\Service\OpenAiQuoteDraftAdapter;
use BSP\Quotes\Service\QuotePaymentSyncService;
use BSP\Quotes\Service\WooCartLaunchGateway;
use BSP\Quotes\Support\Installer;

final class Module implements ModuleInterface
{
    public function init(): void
    {
        Installer::maybeInstall();
        WooCartLaunchGateway::registerHooks();
        QuotePaymentSyncService::registerHooks();
        PublicProposalController::register();

        if (\function_exists('add_action')) {
            \add_action('admin_menu', array(AdminController::class, 'registerMenu'));
            \add_action('rest_api_init', array(RestController::class, 'register'));
            \add_action('rest_api_init', array(InboundMailWebhookController::class, 'register'));
            \add_action('admin_post_sbdp_quote_request_create', array(AdminController::class, 'handleCreateQuoteRequest'));
            \add_action('admin_post_sbdp_quote_request_convert', array(AdminController::class, 'handleConvertQuoteRequest'));
            \add_action('admin_post_sbdp_quote_review_request', array(AdminController::class, 'handleRequestReview'));
            \add_action('admin_post_sbdp_quote_review_approve', array(AdminController::class, 'handleApproveReview'));
            \add_action('admin_post_sbdp_quote_review_return', array(AdminController::class, 'handleReturnToDraft'));
            \add_action('admin_post_sbdp_quote_resolve_assumption', array(AdminController::class, 'handleResolveAssumption'));
            \add_action('admin_post_sbdp_quote_mark_sent_manual', array(AdminController::class, 'handleMarkSentManual'));
            \add_action('admin_post_sbdp_quote_reopen_send', array(AdminController::class, 'handleReopenSend'));
            \add_action('admin_post_sbdp_quote_save_operations_draft', array(AdminController::class, 'handleSaveOperationsDraft'));
            \add_action('admin_post_sbdp_quote_line_control_status', array(AdminController::class, 'handleUpdateLineControlStatus'));
            \add_action('admin_post_sbdp_quote_line_supplier_status', array(AdminController::class, 'handleUpdateLineSupplierStatus'));
            \add_action('admin_post_sbdp_quote_line_supplier_request_draft', array(AdminController::class, 'handleGenerateSupplierRequestDraft'));
            \add_action('admin_post_sbdp_quote_generate_proposal_draft', array(AdminController::class, 'handleGenerateProposalDraft'));
            \add_action('wp_ajax_sbdp_quote_update_proposal_text', array(AdminController::class, 'handleUpdateProposalText'));
            \add_action('wp_ajax_sbdp_quote_suggest_proposal_text', array(AdminController::class, 'handleSuggestProposalText'));
            \add_action('admin_post_sbdp_quote_generate_response_draft', array(AdminController::class, 'handleGenerateResponseDraft'));
            \add_action('admin_post_sbdp_quote_summarize_message', array(AdminController::class, 'handleSummarizeMessage'));
            \add_action('admin_post_sbdp_quote_send_message', array(AdminController::class, 'handleSendMessage'));
            \add_action('admin_post_sbdp_quote_update_intake', array(AdminController::class, 'handleUpdateQuoteIntake'));
            \add_action('admin_post_sbdp_quote_update_customer_contact', array(AdminController::class, 'handleUpdateCustomerContact'));
            \add_action('admin_post_sbdp_quote_save_ai_mail_settings', array(AdminController::class, 'handleSaveAiMailSettings'));
            \add_action('admin_post_sbdp_quote_log_inbound_message', array(AdminController::class, 'handleLogInboundMessage'));
            \add_action('admin_post_sbdp_quote_resolve_inbound_failure', array(AdminController::class, 'handleResolveInboundFailure'));
            \add_action('admin_post_sbdp_quote_followup_create', array(AdminController::class, 'handleCreateFollowup'));
            \add_action('admin_post_sbdp_quote_followup_complete', array(AdminController::class, 'handleCompleteFollowup'));
            \add_action('admin_post_sbdp_quote_mark_ready_for_resnapshot', array(AdminController::class, 'handleMarkReadyForResnapshot'));
            \add_action('admin_post_sbdp_quote_prepare_resnapshot', array(AdminController::class, 'handlePrepareResnapshot'));
            \add_action('admin_post_sbdp_quote_build_handoff_package', array(AdminController::class, 'handleBuildHandoffPackage'));
            \add_action('admin_post_sbdp_quote_build_execution_payload', array(AdminController::class, 'handleBuildExecutionPayload'));
            \add_action('admin_post_sbdp_quote_validate_execution_payload', array(AdminController::class, 'handleValidateExecutionPayload'));
            \add_action('admin_post_sbdp_quote_build_execution_launch', array(AdminController::class, 'handleBuildExecutionLaunch'));
            \add_action('admin_post_sbdp_quote_hydrate_woo_cart', array(AdminController::class, 'handleHydrateWooCart'));
            \add_action('admin_post_sbdp_quote_confirm_ready', array(AdminController::class, 'handleConfirmReadyQuote'));
            \add_action('admin_post_sbdp_quote_quick_prepare_to_send', array(AdminController::class, 'handleQuickPrepareToSend'));

            new OpenAiQuoteDraftAdapter();
        }
    }
}

if (! \class_exists('BSPModule\\Quotes\\Module', false)) {
    \class_alias(Module::class, 'BSPModule\\Quotes\\Module');
}
