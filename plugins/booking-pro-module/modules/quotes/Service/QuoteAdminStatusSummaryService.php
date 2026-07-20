<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use wpdb;

final class QuoteAdminStatusSummaryService
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function summarize(int $quoteId, array $context = array()): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if (! is_array($quote)) {
            return array(
                'quote_id' => $quoteId,
                'chain_status' => 'blocked',
                'next_action' => 'Quote niet gevonden',
                'blockers' => array('quote_not_found'),
                'cta_visibility' => $this->emptyCtaVisibility(),
            );
        }

        $approvedVersionId = (int) ($quote['approved_version_id'] ?? 0);
        $approvedVersion = $approvedVersionId > 0 ? $this->repository->findQuoteVersion($approvedVersionId) : null;
        $handoffPayload = is_array($approvedVersion['handoff_payload_json'] ?? null)
            ? $approvedVersion['handoff_payload_json']
            : array();
        $events = $this->repository->listQuoteEvents($quoteId);
        $eventTypes = $this->eventTypes($events);
        $legalAcceptance = $this->legalAcceptanceSummary($events);
        $lifecycle = QuoteLifecycleMap::resolve($quote, $eventTypes);
        $orderId = (int) ($quote['woo_order_id'] ?? 0);
        $paymentEvent = $this->latestEvent($events, QuotePaymentSyncService::COMPLETED_EVENT);
        $readiness = $this->latestReadiness($quote, $events);
        $blockers = $this->supplierAndManualBlockers($approvedVersionId);
        $hydration = $this->hydrationUrls($handoffPayload);
        $booking = $this->bookingStatus((int) ($quote['booking_master_id'] ?? 0));
        $invoice = $this->invoiceMetadata($orderId, $paymentEvent);
        $order = $this->orderStatus($orderId, $quoteId, $approvedVersionId);
        $requestOrderVerified = $this->requestOrderHandoffVerified($quote, $eventTypes, $order['meta_matches']);
        $decision = (new QuoteProposalSendDecisionService($this->repository))->decide($quoteId);
        $sendAllowed = ! empty($context['send_allowed']) && ! empty($decision['can_send']);
        $communication = $this->communicationStatus($quoteId, $quote, $decision);
        $acceptanceConfirmation = $this->acceptanceConfirmationStatus($quoteId);

        $nextAction = $this->nextAction(
            $quote,
            $sendAllowed,
            $readiness['outcome'],
            $eventTypes,
            $hydration,
            $booking,
            $blockers,
            $communication,
            $lifecycle,
            $requestOrderVerified
        );
        $ctaVisibility = array(
            'confirm_quote' => $readiness['outcome'] === QuoteConfirmationReadinessService::READY_TO_CONFIRM
                && (string) ($quote['status'] ?? '') === 'accepted'
                && (string) ($quote['handoff_status'] ?? '') === QuoteConfirmationReadinessService::READY_TO_CONFIRM,
            'open_woo_cart' => $hydration['cart_url'] !== '',
            'create_booking_bridge' => (string) ($quote['status'] ?? '') === 'confirmed'
                && (
                    (string) ($quote['handoff_status'] ?? '') === 'woo_cart_hydrated'
                    || $requestOrderVerified
                )
                && (int) ($quote['booking_master_id'] ?? 0) <= 0
                && $blockers === array(),
        );

        return array(
            'quote_id' => $quoteId,
            'quote_status' => (string) ($quote['status'] ?? ''),
            'handoff_status' => (string) ($quote['handoff_status'] ?? ''),
            'lifecycle_status' => (string) ($lifecycle['status'] ?? ''),
            'lifecycle_owner' => (string) ($lifecycle['owner'] ?? ''),
            'lifecycle_event' => (string) ($lifecycle['event'] ?? ''),
            'customer_status_label' => (string) ($lifecycle['customer_label'] ?? ''),
            'admin_status_label' => $this->adminStatusLabel($lifecycle, $quote, $eventTypes, $booking, $requestOrderVerified),
            'approved_version_id' => $approvedVersionId,
            'woo_order_id' => $orderId,
            'woo_order_admin_url' => $order['admin_url'],
            'woo_order_meta_matches' => $order['meta_matches'],
            'request_order_handoff_verified' => $requestOrderVerified,
            'payment_event_present' => in_array(QuotePaymentSyncService::COMPLETED_EVENT, $eventTypes, true),
            'invoice_available' => $invoice['invoice_available'],
            'invoice_number' => $invoice['invoice_number'],
            'readiness_outcome' => $readiness['outcome'],
            'readiness_source' => $readiness['source'],
            'confirmed_event_present' => in_array('quote_confirmed', $eventTypes, true),
            'legal_acceptance_complete' => ! empty($legalAcceptance['complete']),
            'legal_acceptance' => $legalAcceptance,
            'acceptance_confirmation_status' => $acceptanceConfirmation['status'],
            'acceptance_confirmation_message_id' => $acceptanceConfirmation['message_id'],
            'woo_cart_hydrated_event_present' => in_array('quote_woo_cart_hydrated', $eventTypes, true),
            'cart_url' => $hydration['cart_url'],
            'checkout_url' => $hydration['checkout_url'],
            'booking_master_id' => $booking['booking_master_id'],
            'booking_master_admin_url' => $booking['admin_url'],
            'booking_legs_count' => $booking['legs_count'],
            'operations_status' => $booking['operations_status'],
            'supplier_manual_blockers' => $blockers,
            'communication_status' => $communication['status'],
            'communication_label' => $communication['label'],
            'communication_blockers' => $communication['blockers'],
            'proposal_review_status' => $communication['review_status'],
            'proposal_send_status' => $communication['send_status'],
            'proposal_send_ready' => $communication['send_ready'],
            'proposal_can_complete_control' => $communication['can_complete_control'],
            'proposal_can_send' => $communication['can_send'],
            'proposal_next_action' => $communication['next_action'],
            'next_action' => $nextAction,
            'next_action_reason' => $this->nextActionReason($nextAction, $readiness['outcome'], $blockers),
            'chain_steps' => $this->chainSteps($quote, $sendAllowed, $readiness['outcome'], $eventTypes, $hydration, $booking, $blockers),
            'meta_chips' => $this->metaChips($approvedVersionId, $orderId, $order['admin_url'], $invoice, $booking),
            'blocker_chips' => $this->blockerChips($blockers, $readiness['outcome']),
            'communication_chips' => $communication['chips'],
            'cta_visibility' => $ctaVisibility,
        );
    }

    public function __construct(private QuoteRepositoryInterface $repository)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<int, string>
     */
    private function eventTypes(array $events): array
    {
        return array_values(array_unique(array_map(
            static fn (array $event): string => (string) ($event['event_type'] ?? ''),
            $events
        )));
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<string, mixed>|null
     */
    private function latestEvent(array $events, string $eventType): ?array
    {
        $matches = array_values(array_filter(
            $events,
            static fn (array $event): bool => (string) ($event['event_type'] ?? '') === $eventType
        ));

        return $matches !== array() ? end($matches) : null;
    }

    /**
     * Existing acceptance flow persists legal proof in bsp_quote_events.payload_json.
     * The public event carries the customer POST/context payload; quote_accepted mirrors
     * it under legal_acceptance so future table migration can copy fixed keys directly.
     *
     * @param array<int, array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function legalAcceptanceSummary(array $events): array
    {
        $event = $this->latestEvent($events, 'quote_public_proposal_accepted');
        if ($event === null) {
            $event = $this->latestEvent($events, 'quote_accepted');
        }
        if ($event === null) {
            return array('complete' => false, 'missing' => array('acceptance_event'));
        }

        $payload = is_array($event['payload_json'] ?? null) ? $event['payload_json'] : array();
        if (is_array($payload['legal_acceptance'] ?? null)) {
            $payload = $payload['legal_acceptance'];
        }

        $summary = array(
            'event_id' => (int) ($event['id'] ?? 0),
            'accepted_at' => (string) (($payload['accepted_at'] ?? '') ?: ($event['created_at'] ?? '')),
            'approved_version_id' => (int) (($payload['approved_version_id'] ?? 0) ?: ($payload['accepted_version_id'] ?? 0)),
            'current_version_id_at_acceptance' => (int) ($payload['current_version_id_at_acceptance'] ?? 0),
            'acceptance_name' => trim((string) ($payload['acceptance_name'] ?? '')),
            'acceptance_email' => trim((string) ($payload['acceptance_email'] ?? '')),
            'acceptance_company' => trim((string) ($payload['acceptance_company'] ?? '')),
            'acceptance_role' => trim((string) ($payload['acceptance_role'] ?? '')),
            'terms_checked' => ! empty($payload['terms_checked']),
            'terms_version' => trim((string) ($payload['terms_version'] ?? '')),
            'terms_url' => trim((string) ($payload['terms_url'] ?? '')),
            'ip_address' => trim((string) (($payload['ip_address'] ?? '') ?: ($payload['ip'] ?? ''))),
            'user_agent' => trim((string) ($payload['user_agent'] ?? '')),
            'public_token_id' => trim((string) (($payload['public_token_id'] ?? '') ?: ($payload['token_id'] ?? ''))),
            'quote_version_hash' => trim((string) ($payload['quote_version_hash'] ?? '')),
            'proposal_snapshot_hash' => trim((string) ($payload['proposal_snapshot_hash'] ?? '')),
        );

        $missing = array();
        foreach (array('acceptance_name', 'acceptance_email', 'terms_version', 'quote_version_hash', 'proposal_snapshot_hash') as $key) {
            if ((string) ($summary[$key] ?? '') === '') {
                $missing[] = $key;
            }
        }
        if (empty($summary['terms_checked'])) {
            $missing[] = 'terms_checked';
        }
        if ((int) ($summary['approved_version_id'] ?? 0) <= 0) {
            $missing[] = 'approved_version_id';
        }

        $summary['complete'] = $missing === array();
        $summary['missing'] = $missing;

        return $summary;
    }

    /**
     * @return array{status:string,message_id:int}
     */
    private function acceptanceConfirmationStatus(int $quoteId): array
    {
        $latest = null;
        foreach ($this->repository->listQuoteMessages($quoteId) as $message) {
            if ((string) ($message['message_type'] ?? '') !== 'acceptance_confirmation') {
                continue;
            }
            $latest = $message;
        }

        if ($latest === null) {
            return array('status' => 'missing', 'message_id' => 0);
        }

        return array(
            'status' => (string) ($latest['status'] ?? 'draft'),
            'message_id' => (int) ($latest['id'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<int, array<string, mixed>> $events
     * @return array{outcome:string,source:string}
     */
    private function latestReadiness(array $quote, array $events): array
    {
        $handoffStatus = (string) ($quote['handoff_status'] ?? '');
        $known = array(
            QuoteConfirmationReadinessService::READY_TO_CONFIRM,
            QuoteConfirmationReadinessService::AWAITING_SUPPLIER_CONFIRMATION,
            QuoteConfirmationReadinessService::REQUIRES_ADMIN_CONFIRMATION,
            QuoteConfirmationReadinessService::CONFIRMATION_BLOCKED,
        );
        if (in_array($handoffStatus, $known, true)) {
            return array('outcome' => $handoffStatus, 'source' => 'quote_handoff_status');
        }

        $event = $this->latestEvent($events, QuoteConfirmationReadinessService::EVENT_EVALUATED);
        $payload = is_array($event['payload_json'] ?? null) ? $event['payload_json'] : array();
        $outcome = (string) ($payload['outcome'] ?? '');
        if (in_array($outcome, $known, true)) {
            return array('outcome' => $outcome, 'source' => 'quote_event');
        }

        return array('outcome' => '', 'source' => 'not_evaluated');
    }

    /**
     * @param array<string, mixed> $handoffPayload
     * @return array{cart_url:string,checkout_url:string}
     */
    private function hydrationUrls(array $handoffPayload): array
    {
        $hydration = is_array($handoffPayload['hydration_result']['result'] ?? null)
            ? $handoffPayload['hydration_result']['result']
            : array();

        return array(
            'cart_url' => trim((string) ($hydration['cart_url'] ?? '')),
            'checkout_url' => trim((string) ($hydration['checkout_url'] ?? '')),
        );
    }

    /**
     * @param array<string, mixed>|null $paymentEvent
     * @return array{invoice_available:bool,invoice_number:string}
     */
    private function invoiceMetadata(int $orderId, ?array $paymentEvent): array
    {
        $payload = is_array($paymentEvent['payload_json'] ?? null) ? $paymentEvent['payload_json'] : array();
        $number = trim((string) ($payload['invoice_number'] ?? ''));

        if ($number === '' && $orderId > 0 && function_exists('wc_get_order')) {
            $order = \wc_get_order($orderId);
            if ($order instanceof \WC_Order) {
                $number = trim((string) $order->get_meta('_wcpdf_invoice_number'));
            }
        }

        return array(
            'invoice_available' => ! empty($payload['invoice_available']) || $number !== '',
            'invoice_number' => $number,
        );
    }

    /**
     * @return array{meta_matches:bool,admin_url:string}
     */
    private function orderStatus(int $orderId, int $quoteId, int $approvedVersionId): array
    {
        $matches = false;
        if ($orderId > 0 && function_exists('wc_get_order')) {
            $order = \wc_get_order($orderId);
            if ($order instanceof \WC_Order) {
                $matches = (int) $order->get_meta('_sbdp_quote_id') === $quoteId
                    && (int) $order->get_meta('_sbdp_quote_version_id') === $approvedVersionId;
            }
        }

        return array(
            'meta_matches' => $matches,
            'admin_url' => $orderId > 0 && function_exists('admin_url')
                ? \admin_url('post.php?post=' . $orderId . '&action=edit')
                : '',
        );
    }

    /**
     * A direct checkout flow proves cart handoff with quote_woo_cart_hydrated.
     * A B2B request-order flow never needs a customer cart session; it proves
     * handoff through request-order creation, matching order meta and payment.
     *
     * @param array<string, mixed> $quote
     * @param array<int, string> $eventTypes
     */
    private function requestOrderHandoffVerified(array $quote, array $eventTypes, bool $orderMetaMatches): bool
    {
        return (int) ($quote['woo_order_id'] ?? 0) > 0
            && $orderMetaMatches
            && in_array('quote_woo_request_order_created', $eventTypes, true)
            && in_array(QuotePaymentSyncService::COMPLETED_EVENT, $eventTypes, true)
            && in_array('quote_confirmed', $eventTypes, true);
    }

    /**
     * @return array{booking_master_id:int,admin_url:string,legs_count:int,operations_status:string}
     */
    private function bookingStatus(int $bookingMasterId): array
    {
        $legsCount = 0;
        if ($bookingMasterId > 0) {
            global $wpdb;
            if ($wpdb instanceof wpdb) {
                $table = $wpdb->prefix . 'bsp_booking_legs';
                $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
                if ((string) $existing === $table) {
                    $legsCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE master_id = %d", $bookingMasterId));
                }
            }
        }

        return array(
            'booking_master_id' => $bookingMasterId,
            'admin_url' => $bookingMasterId > 0 && function_exists('admin_url')
                ? \admin_url('admin.php?page=sbdp_bookings')
                : '',
            'legs_count' => $legsCount,
            'operations_status' => $bookingMasterId > 0 ? 'operations_ready' : 'not_started',
        );
    }

    /**
     * @return array<int, string>
     */
    private function supplierAndManualBlockers(int $versionId): array
    {
        if ($versionId <= 0) {
            return array();
        }

        $blockers = array();
        foreach ($this->repository->listQuoteLines($versionId) as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $lineType = strtolower(trim((string) ($line['line_type'] ?? 'product')));
            $snapshot = is_array($line['availability_snapshot_json'] ?? null)
                ? $line['availability_snapshot_json']
                : array();
            $bookingMode = strtolower(trim((string) ($snapshot['bookingMode'] ?? $snapshot['booking_mode'] ?? '')));
            $supplierProvider = strtolower(trim((string) ($snapshot['supplierProvider'] ?? $snapshot['provider'] ?? '')));
            $supplierStatus = strtolower(trim((string) ($snapshot['supplierStatus'] ?? $snapshot['supplier_status'] ?? '')));

            if ($productId <= 0 || in_array($lineType, array('manual', 'custom', 'note', 'directional'), true)) {
                $blockers[] = 'manual/custom';
            }
            if ($productId === 115 || $supplierProvider === 'eliio') {
                $blockers[] = 'product 115/Eliio';
            }
            if ($bookingMode === 'supplier_confirmation' || in_array($supplierStatus, array('supplier_confirmation_required', 'supplier_option_requested'), true)) {
                $blockers[] = 'supplier_confirmation_required';
            }
            if (($productId === 115 || $supplierProvider === 'eliio' || $bookingMode === 'supplier_confirmation') && $supplierStatus !== 'supplier_booking_confirmed') {
                $blockers[] = 'missing supplier_booking_confirmed';
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<int, string> $eventTypes
     * @param array{cart_url:string,checkout_url:string} $hydration
     * @param array{booking_master_id:int,admin_url:string,legs_count:int,operations_status:string} $booking
     * @param array<int, string> $blockers
     * @return array<int, array{key:string,label:string,status:string}>
     */
    private function chainSteps(array $quote, bool $sendAllowed, string $readinessOutcome, array $eventTypes, array $hydration, array $booking, array $blockers): array
    {
        $quoteStatus = (string) ($quote['status'] ?? '');
        $sendStatus = (string) ($quote['send_status'] ?? '');
        $handoffStatus = (string) ($quote['handoff_status'] ?? '');
        $accepted = in_array($quoteStatus, array('accepted', 'confirmed'), true);
        $paid = $handoffStatus === QuotePaymentSyncService::COMPLETED_STATUS || in_array(QuotePaymentSyncService::COMPLETED_EVENT, $eventTypes, true);
        $confirmed = $quoteStatus === 'confirmed' || in_array('quote_confirmed', $eventTypes, true);
        $requestOrderReady = (int) ($quote['woo_order_id'] ?? 0) > 0
            && in_array('quote_woo_request_order_created', $eventTypes, true)
            && in_array(QuotePaymentSyncService::COMPLETED_EVENT, $eventTypes, true);
        $cartReady = $handoffStatus === 'woo_cart_hydrated'
            || in_array('quote_woo_cart_hydrated', $eventTypes, true)
            || $hydration['cart_url'] !== ''
            || $requestOrderReady;
        $bookingReady = $booking['booking_master_id'] > 0;
        $readinessBlocked = in_array($readinessOutcome, array(
            QuoteConfirmationReadinessService::AWAITING_SUPPLIER_CONFIRMATION,
            QuoteConfirmationReadinessService::REQUIRES_ADMIN_CONFIRMATION,
            QuoteConfirmationReadinessService::CONFIRMATION_BLOCKED,
        ), true);

        return array(
            array('key' => 'intake', 'label' => 'Intake', 'status' => 'done'),
            array(
                'key' => 'proposal',
                'label' => 'Proposal',
                'status' => in_array($quoteStatus, array('sent', 'accepted', 'confirmed'), true) || $sendStatus === 'sent_manual'
                    ? 'done'
                    : ($sendAllowed ? 'current' : 'pending'),
            ),
            array(
                'key' => 'accepted',
                'label' => 'Accepted',
                'status' => $accepted ? 'done' : ((in_array($quoteStatus, array('sent'), true) || $sendStatus === 'sent_manual') ? 'current' : 'not_started'),
            ),
            array(
                'key' => 'paid',
                'label' => 'Paid',
                'status' => $paid ? 'done' : ($accepted ? 'current' : 'not_started'),
            ),
            array(
                'key' => 'confirmed',
                'label' => 'Confirmed',
                'status' => $confirmed ? 'done' : ($readinessBlocked ? 'blocked' : (($readinessOutcome === QuoteConfirmationReadinessService::READY_TO_CONFIRM || $paid) ? 'current' : 'not_started')),
            ),
            array(
                'key' => 'cart',
                'label' => 'Cart',
                'status' => $cartReady ? 'done' : ($confirmed ? 'current' : 'not_started'),
            ),
            array(
                'key' => 'booking',
                'label' => 'Booking',
                'status' => $bookingReady ? 'done' : ($blockers !== array() ? 'blocked' : ($cartReady ? 'current' : 'not_started')),
            ),
            array(
                'key' => 'operations',
                'label' => 'Operations',
                'status' => $bookingReady && $handoffStatus === 'operations_ready' ? 'done' : ($bookingReady ? 'current' : 'not_started'),
            ),
        );
    }

    /**
     * @param array{invoice_available:bool,invoice_number:string} $invoice
     * @param array{booking_master_id:int,admin_url:string,legs_count:int,operations_status:string} $booking
     * @return array<int, array{key:string,label:string,value:string,url:string}>
     */
    private function metaChips(int $approvedVersionId, int $orderId, string $orderUrl, array $invoice, array $booking): array
    {
        $chips = array();
        if ($approvedVersionId > 0) {
            $chips[] = array('key' => 'approved_version', 'label' => 'Approved version', 'value' => '#' . $approvedVersionId, 'url' => '');
        }
        if ($orderId > 0) {
            $chips[] = array('key' => 'woo_order', 'label' => 'Woo order', 'value' => '#' . $orderId, 'url' => $orderUrl);
        }
        if (! empty($invoice['invoice_available']) && (string) ($invoice['invoice_number'] ?? '') !== '') {
            $chips[] = array('key' => 'invoice', 'label' => 'Invoice', 'value' => (string) $invoice['invoice_number'], 'url' => '');
        }
        if ($booking['booking_master_id'] > 0) {
            $chips[] = array('key' => 'booking_master', 'label' => 'Booking master', 'value' => '#' . (string) $booking['booking_master_id'], 'url' => $booking['admin_url']);
        }
        if ($booking['legs_count'] > 0) {
            $chips[] = array('key' => 'legs', 'label' => 'Legs', 'value' => (string) $booking['legs_count'], 'url' => '');
        }

        return $chips;
    }

    /**
     * @param array<int, string> $blockers
     * @return array<int, array{key:string,label:string,status:string}>
     */
    private function blockerChips(array $blockers, string $readinessOutcome): array
    {
        $chips = array();
        foreach ($blockers as $blocker) {
            $chips[] = array(
                'key' => strtolower(str_replace(array(' ', '/'), '_', $blocker)),
                'label' => $blocker,
                'status' => 'blocked',
            );
        }
        if ($readinessOutcome === QuoteConfirmationReadinessService::CONFIRMATION_BLOCKED && ! in_array('confirmation_blocked', $blockers, true)) {
            $chips[] = array('key' => 'confirmation_blocked', 'label' => 'confirmation_blocked', 'status' => 'blocked');
        }

        return $chips;
    }

    /**
     * @param array<string, mixed> $quote
     * @return array{
     *     status:string,
     *     label:string,
     *     blockers:array<int, array{code:string,label:string,status:string}>,
     *     chips:array<int, array{key:string,label:string,status:string}>,
     *     review_status:string,
     *     send_status:string,
     *     send_ready:bool,
     *     can_complete_control:bool,
     *     can_send:bool,
     *     next_action:string
     * }
     */
    private function communicationStatus(int $quoteId, array $quote, array $decision): array
    {
        $reviewStatus = (string) ($decision['review_status'] ?? ($quote['review_status'] ?? 'not_started'));
        $sendStatus = (string) ($decision['send_status'] ?? ($quote['send_status'] ?? 'not_ready'));
        $proposalSent = $this->hasSentProposal($quoteId) || in_array($sendStatus, array('sent', 'sent_manual'), true);
        $proposalSendReady = ! empty($decision['proposal_send_ready']);
        $canCompleteControl = ! empty($decision['can_complete_control']);
        $canSend = ! empty($decision['can_send']);
        $sendReady = $canSend;
        $blockers = array();

        foreach ((array) ($decision['blockers'] ?? array()) as $decisionBlocker) {
            if (! is_array($decisionBlocker)) {
                continue;
            }
            $blockers[] = array(
                'code' => (string) ($decisionBlocker['code'] ?? 'proposal_send_blocker'),
                'label' => $this->proposalBlockerLabel((string) ($decisionBlocker['code'] ?? 'proposal_send_blocker')),
                'status' => 'blocked',
            );
        }

        if ($proposalSent) {
            $status = 'sent';
            $label = 'Proposal verzonden';
            $nextAction = 'Communicatiehistorie aanwezig';
        } elseif ($canSend) {
            $status = 'send_ready';
            $label = 'Verzenden klaar';
            $nextAction = 'Voorstel versturen';
        } elseif ($canCompleteControl) {
            $status = 'control_complete_available';
            $label = 'Voorstel klaar voor verzending';
            $nextAction = 'Controle afronden';
        } elseif ($proposalSendReady) {
            $status = 'control_complete_available';
            $label = 'Voorstel klaar voor verzending';
            $nextAction = (string) ($decision['next_action'] ?? 'Controle afronden');
        } else {
            $status = 'send_not_ready';
            $label = 'Controle nodig';
            $nextAction = (string) ($decision['next_action'] ?? 'Nog nodig: controleer open punten');
        }

        $chips = array(array(
            'key' => 'communication_status',
            'label' => 'Communicatie: ' . lcfirst($label),
            'status' => $blockers === array() ? 'done' : 'notice',
        ));

        if ($canCompleteControl) {
            $chips[] = array('key' => 'control_complete_available', 'label' => 'Controle afronden', 'status' => 'notice');
        }
        if ($reviewStatus === 'approved') {
            $chips[] = array('key' => 'review_approved', 'label' => 'Review akkoord', 'status' => 'done');
        }
        if ($sendStatus === 'ready_to_send') {
            $chips[] = array('key' => 'send_ready', 'label' => 'Verzenden klaar', 'status' => 'done');
        }
        if ($proposalSent) {
            $chips[] = array('key' => 'proposal_sent', 'label' => 'Proposal verzonden', 'status' => 'done');
        }
        foreach ($blockers as $blocker) {
            $chips[] = array(
                'key' => (string) ($blocker['code'] ?? 'communication_blocker'),
                'label' => (string) ($blocker['label'] ?? 'Communicatieblokker'),
                'status' => (string) ($blocker['status'] ?? 'blocked'),
            );
        }

        return array(
            'status' => $status,
            'label' => $label,
            'blockers' => $blockers,
            'chips' => $chips,
            'review_status' => $reviewStatus,
            'send_status' => $sendStatus,
            'send_ready' => $sendReady,
            'can_complete_control' => $canCompleteControl,
            'can_send' => $canSend,
            'next_action' => $nextAction,
        );
    }

    private function proposalBlockerLabel(string $code): string
    {
        return match ($code) {
            'customer_email_missing' => 'Klantmail ontbreekt',
            'proposal_text_missing' => 'Voorsteltekst ontbreekt',
            'quote_lines_missing' => 'Open programmaregel',
            'supplier_confirmation_missing' => 'Supplier confirmation ontbreekt',
            default => 'Nog nodig voor verzenden',
        };
    }

    private function hasSentProposal(int $quoteId): bool
    {
        foreach ($this->repository->listQuoteMessages($quoteId) as $message) {
            if ((string) ($message['direction'] ?? '') !== 'outbound') {
                continue;
            }
            if ((string) ($message['message_type'] ?? '') !== 'proposal') {
                continue;
            }
            if ((string) ($message['status'] ?? '') === 'sent') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<int, string> $eventTypes
     * @param array{cart_url:string,checkout_url:string} $hydration
     * @param array{booking_master_id:int,admin_url:string,legs_count:int,operations_status:string} $booking
     * @param array<int, string> $blockers
     */
    private function nextAction(array $quote, bool $sendAllowed, string $readinessOutcome, array $eventTypes, array $hydration, array $booking, array $blockers, array $communication = array(), array $lifecycle = array(), bool $requestOrderHandoffVerified = false): string
    {
        $quoteStatus = (string) ($quote['status'] ?? '');
        $handoffStatus = (string) ($quote['handoff_status'] ?? '');
        $lifecycleStatus = (string) ($lifecycle['status'] ?? QuoteLifecycleMap::resolveStage($quote, $eventTypes));

        if ($handoffStatus === 'price_mismatch_requires_review') {
            return 'Prijsverschil controleren';
        }
        if ($lifecycleStatus === 'declined') {
            return 'Geen actie / archiveren';
        }
        if (in_array($lifecycleStatus, array('cancelled', 'expired'), true)) {
            return (string) ($lifecycle['admin_next_action'] ?? 'Geen actie');
        }
        if ($lifecycleStatus === 'operations_ready') {
            return 'Geen actie';
        }
        if ($lifecycleStatus === 'revision_requested') {
            return 'Wijziging verwerken';
        }
        if ($quoteStatus === 'confirmed' && $booking['booking_master_id'] <= 0) {
            if ($blockers !== array()) {
                return 'Leveranciers / operatie controleren';
            }
            if (! $requestOrderHandoffVerified && ! in_array('quote_woo_cart_hydrated', $eventTypes, true) && $hydration['cart_url'] === '') {
                return 'Cart/order hydration controleren';
            }

            return 'Operations booking aanmaken / controleren';
        }
        if ($readinessOutcome === QuoteConfirmationReadinessService::AWAITING_SUPPLIER_CONFIRMATION) {
            return 'Wacht op supplier confirmation';
        }
        if ($readinessOutcome === QuoteConfirmationReadinessService::REQUIRES_ADMIN_CONFIRMATION) {
            return 'Admin bevestiging nodig';
        }
        if ($readinessOutcome === QuoteConfirmationReadinessService::CONFIRMATION_BLOCKED) {
            return 'Los blokkade op';
        }
        if ($blockers !== array() && in_array('missing supplier_booking_confirmed', $blockers, true)) {
            return 'Wacht op supplier confirmation';
        }
        if ($blockers !== array() && in_array('manual/custom', $blockers, true)) {
            return 'Admin bevestiging nodig';
        }
        if ($lifecycleStatus === 'woo_payment_completed') {
            return 'Bevestiging afronden';
        }
        if ($lifecycleStatus === 'woo_request_order_created' || $lifecycleStatus === 'payment_pending') {
            return 'Wachten op betaling';
        }
        if ($lifecycleStatus === 'accepted') {
            return 'Woo order / betaalverzoek aanmaken';
        }
        if ($lifecycleStatus === 'sent' || $lifecycleStatus === 'viewed') {
            return 'Wachten op klant';
        }
        if ($lifecycleStatus === 'ready_to_send') {
            return 'Versturen naar klant';
        }

        if ($booking['booking_master_id'] > 0 || ($handoffStatus === 'operations_ready' && $booking['booking_master_id'] > 0)) {
            return 'Gereed voor uitvoering';
        }
        if ($readinessOutcome === QuoteConfirmationReadinessService::READY_TO_CONFIRM || $handoffStatus === QuoteConfirmationReadinessService::READY_TO_CONFIRM) {
            return 'Bevestig quote';
        }
        if ($handoffStatus === QuotePaymentSyncService::COMPLETED_STATUS || in_array(QuotePaymentSyncService::COMPLETED_EVENT, $eventTypes, true)) {
            return 'Beoordeel readiness';
        }
        if ($quoteStatus === 'accepted') {
            return 'Wacht op betaling';
        }
        if (in_array($quoteStatus, array('sent'), true) || (string) ($quote['send_status'] ?? '') === 'sent_manual') {
            return 'Wacht op acceptatie';
        }
        if ($sendAllowed) {
            return 'Voorstel versturen';
        }
        if (! empty($communication['can_complete_control'])) {
            return 'Controle afronden';
        }

        return (string) ($lifecycle['admin_next_action'] ?? 'Offerte voorbereiden');
    }

    /**
     * @param array<int, string> $blockers
     */
    private function nextActionReason(string $nextAction, string $readinessOutcome, array $blockers): string
    {
        if ($blockers !== array()) {
            return implode(' | ', $blockers);
        }
        if ($readinessOutcome !== '') {
            return 'Readiness: ' . $readinessOutcome;
        }

        return match ($nextAction) {
            'Wacht op betaling' => 'Quote is geaccepteerd; Woo payment_complete is nog niet gezien.',
            'Wachten op betaling' => 'Woo order of betaalverzoek bestaat; Woo payment_complete is nog niet gezien.',
            'Woo order / betaalverzoek aanmaken' => 'Quote is geaccepteerd; maak nu de Woo order of het betaalverzoek op basis van approved_version_id.',
            'Bevestiging afronden' => 'Woo payment_complete is gezien; rond confirmation/readiness af.',
            'Wijziging verwerken' => 'Klant heeft via de publieke proposal een wijziging aangevraagd.',
            'Prijsverschil controleren' => 'Woo ordercreatie is geblokkeerd omdat het offertebedrag afwijkt van de Woo-prijs.',
            'Cart/order hydration controleren' => 'Confirmed quote mist nog een aantoonbaar direct-cart of request-order handoff bewijs.',
            'Operations booking aanmaken / controleren' => 'Quote is confirmed en order/payment zijn gecontroleerd; maak of controleer de operations booking.',
            'Bevestig quote' => 'Readiness is groen en wacht op expliciete admin bevestiging.',
            'Maak operationele boeking' => 'Woo cart is voorbereid; operations booking ontbreekt nog.',
            'Geen actie nodig / operations ready' => 'Booking master en operations projectie zijn aanwezig.',
            'Gereed voor uitvoering' => 'Booking master en operations projectie zijn aanwezig of de quote is operationeel klaar.',
            'Geen actie' => 'Quote is terminal of gesloten.',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $lifecycle
     * @param array<string, mixed> $quote
     * @param array<int, string> $eventTypes
     * @param array{booking_master_id:int,admin_url:string,legs_count:int,operations_status:string} $booking
     */
    private function adminStatusLabel(array $lifecycle, array $quote, array $eventTypes, array $booking, bool $requestOrderHandoffVerified): string
    {
        $handoffStatus = (string) ($quote['handoff_status'] ?? '');
        if ($handoffStatus === 'price_mismatch_requires_review') {
            return 'Prijsverschil';
        }
        if ((string) ($lifecycle['status'] ?? '') === 'operations_ready') {
            return 'Gereed voor uitvoering';
        }
        if ((string) ($quote['status'] ?? '') === 'confirmed' && $booking['booking_master_id'] <= 0) {
            if (! $requestOrderHandoffVerified && ! in_array('quote_woo_cart_hydrated', $eventTypes, true)) {
                return 'Bevestigd, operations geblokkeerd';
            }

            return 'Bevestigd';
        }

        return match ((string) ($lifecycle['status'] ?? 'draft')) {
            'draft' => 'Concept',
            'ready_to_send' => 'Klaar om te versturen',
            'sent' => 'Verzonden',
            'viewed' => 'Bekeken',
            'revision_requested' => 'Wijziging aangevraagd',
            'accepted' => 'Akkoord',
            'woo_request_order_created', 'payment_pending' => 'Wachten op betaling',
            'woo_payment_completed' => 'Betaald',
            'declined' => 'Afgewezen',
            'cancelled' => 'Geannuleerd',
            'expired' => 'Verlopen',
            default => (string) ($lifecycle['customer_label'] ?? 'Concept'),
        };
    }

    /**
     * @return array{confirm_quote:bool,open_woo_cart:bool,create_booking_bridge:bool}
     */
    private function emptyCtaVisibility(): array
    {
        return array(
            'confirm_quote' => false,
            'open_woo_cart' => false,
            'create_booking_bridge' => false,
        );
    }
}
