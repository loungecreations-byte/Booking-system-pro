<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

/**
 * Internal status map for the existing Quote OS.
 *
 * This is documentation-as-code: it does not introduce a new state machine and
 * must not become a parallel proposal system. Services remain the owners of
 * transitions; this map describes the intended lifecycle and admin-facing next
 * action.
 */
final class QuoteLifecycleMap
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function statuses(): array
    {
        return array(
            'draft' => array(
                'meaning' => 'Quote is aangemaakt maar nog niet klaar voor klantcommunicatie.',
                'owner' => 'QuoteConversionService / QuoteOperationsDraftService',
                'previous' => array(),
                'next' => array('ready_to_send', 'cancelled'),
                'event' => 'quote_created',
                'admin_next_action' => 'Offerte voorbereiden',
                'customer_label' => 'In voorbereiding',
            ),
            'ready_to_send' => array(
                'meaning' => 'Quote is intern gecontroleerd en mag naar de klant.',
                'owner' => 'QuoteReviewService / QuoteSendReadinessValidator',
                'previous' => array('draft'),
                'next' => array('sent', 'draft', 'cancelled'),
                'event' => 'quote_review_approved',
                'admin_next_action' => 'Versturen naar klant',
                'customer_label' => 'Klaar om te versturen',
            ),
            'sent' => array(
                'meaning' => 'Proposal is naar de klant verzonden en wacht op reactie.',
                'owner' => 'QuoteCommunicationService',
                'previous' => array('ready_to_send'),
                'next' => array('viewed', 'revision_requested', 'accepted', 'declined', 'expired'),
                'event' => 'quote_proposal_sent',
                'admin_next_action' => 'Wachten op klant',
                'customer_label' => 'Wacht op uw reactie',
            ),
            'viewed' => array(
                'meaning' => 'Klant heeft de publieke proposal geopend.',
                'owner' => 'PublicQuoteProposalService / PublicProposalController',
                'previous' => array('sent'),
                'next' => array('revision_requested', 'accepted', 'declined', 'expired'),
                'event' => 'quote_public_proposal_viewed',
                'admin_next_action' => 'Wachten op klant',
                'customer_label' => 'Bekeken',
            ),
            'revision_requested' => array(
                'meaning' => 'Klant vraagt een wijziging aan; huidige voorstelversie blijft read-only.',
                'owner' => 'PublicQuoteProposalService',
                'previous' => array('sent', 'viewed'),
                'next' => array('draft', 'ready_to_send', 'cancelled'),
                'event' => 'quote_public_proposal_revision_requested',
                'admin_next_action' => 'Wijziging verwerken',
                'customer_label' => 'Wijziging ontvangen',
            ),
            'accepted' => array(
                'meaning' => 'Klant/admin heeft een versie akkoord gegeven; approved_version_id is de waarheid.',
                'owner' => 'QuoteAcceptanceService',
                'previous' => array('sent', 'ready_to_send'),
                'next' => array('woo_request_order_created', 'payment_pending', 'cancelled'),
                'event' => 'quote_accepted',
                'admin_next_action' => 'Woo order / betaalverzoek aanmaken',
                'customer_label' => 'Akkoord ontvangen',
            ),
            'woo_request_order_created' => array(
                'meaning' => 'Woo request order of betaalverzoek is gekoppeld aan approved_version_id.',
                'owner' => 'QuoteRequestOrderBridgeService / QuoteWooCartHydrationService',
                'previous' => array('accepted'),
                'next' => array('woo_payment_completed', 'cancelled'),
                'event' => 'quote_woo_request_order_created',
                'admin_next_action' => 'Wachten op betaling',
                'customer_label' => 'Betaalverzoek klaar',
            ),
            'payment_pending' => array(
                'meaning' => 'Woo order bestaat maar payment_complete is nog niet gezien.',
                'owner' => 'WooCommerce',
                'previous' => array('accepted', 'woo_request_order_created'),
                'next' => array('woo_payment_completed', 'cancelled', 'expired'),
                'event' => 'quote_payment_pending',
                'admin_next_action' => 'Wachten op betaling',
                'customer_label' => 'Wacht op betaling',
            ),
            'woo_payment_completed' => array(
                'meaning' => 'Woo payment_complete is geaccepteerd door quote/order/version guards.',
                'owner' => 'QuotePaymentSyncService',
                'previous' => array('woo_request_order_created', 'payment_pending'),
                'next' => array('confirmed'),
                'event' => QuotePaymentSyncService::COMPLETED_EVENT,
                'admin_next_action' => 'Bevestiging afronden',
                'customer_label' => 'Betaling ontvangen',
            ),
            'confirmed' => array(
                'meaning' => 'Betaalde quote is bevestigd voor operationele opvolging.',
                'owner' => 'QuoteConfirmationService / QuoteAdminConfirmationService',
                'previous' => array('woo_payment_completed'),
                'next' => array('operations_ready'),
                'event' => 'quote_confirmed',
                'admin_next_action' => 'Operations booking aanmaken / controleren',
                'customer_label' => 'Bevestigd',
            ),
            'operations_ready' => array(
                'meaning' => 'Operations booking master/legs zijn aangemaakt.',
                'owner' => 'QuoteBookingBridgeService / OperationsSyncService',
                'previous' => array('confirmed'),
                'next' => array(),
                'event' => 'quote_booking_bridge_created',
                'admin_next_action' => 'Gereed voor uitvoering',
                'customer_label' => 'Gereed voor uitvoering',
            ),
            'declined' => array(
                'meaning' => 'Klant heeft het voorstel afgewezen.',
                'owner' => 'PublicQuoteProposalService',
                'previous' => array('sent', 'viewed'),
                'next' => array(),
                'event' => 'quote_public_proposal_declined',
                'admin_next_action' => 'Geen actie / archiveren',
                'customer_label' => 'Afgewezen',
            ),
            'cancelled' => array(
                'meaning' => 'Quote is intern geannuleerd.',
                'owner' => 'Admin',
                'previous' => array('draft', 'ready_to_send', 'sent', 'accepted', 'payment_pending'),
                'next' => array(),
                'event' => 'quote_cancelled',
                'admin_next_action' => 'Geen actie',
                'customer_label' => 'Geannuleerd',
            ),
            'expired' => array(
                'meaning' => 'Quote is verlopen zonder akkoord/betaling.',
                'owner' => 'Admin / scheduled expiry',
                'previous' => array('sent', 'viewed', 'payment_pending'),
                'next' => array(),
                'event' => 'quote_expired',
                'admin_next_action' => 'Geen actie',
                'customer_label' => 'Verlopen',
            ),
        );
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<int, string> $eventTypes
     */
    public static function resolveStage(array $quote, array $eventTypes = array()): string
    {
        $quoteStatus = (string) ($quote['status'] ?? '');
        $handoffStatus = (string) ($quote['handoff_status'] ?? '');
        $sendStatus = (string) ($quote['send_status'] ?? '');

        if (in_array($quoteStatus, array('declined', 'cancelled', 'expired'), true)) {
            return $quoteStatus;
        }
        if ($handoffStatus === 'price_mismatch_requires_review') {
            return 'accepted';
        }
        if ($handoffStatus === 'operations_ready' || (int) ($quote['booking_master_id'] ?? 0) > 0) {
            return 'operations_ready';
        }
        if ($quoteStatus === 'confirmed' || in_array('quote_confirmed', $eventTypes, true)) {
            return 'confirmed';
        }
        if ($handoffStatus === QuotePaymentSyncService::COMPLETED_STATUS || in_array(QuotePaymentSyncService::COMPLETED_EVENT, $eventTypes, true)) {
            return 'woo_payment_completed';
        }
        if ($handoffStatus === 'woo_request_order_created') {
            return 'woo_request_order_created';
        }
        if ((int) ($quote['woo_order_id'] ?? 0) > 0 && $quoteStatus === 'accepted') {
            return 'payment_pending';
        }
        if ($quoteStatus === 'accepted') {
            return 'accepted';
        }
        if ($quoteStatus === 'revision_requested') {
            return 'revision_requested';
        }
        if ($quoteStatus === 'viewed' || in_array('quote_public_proposal_viewed', $eventTypes, true)) {
            return 'viewed';
        }
        if ($quoteStatus === 'sent' || in_array($sendStatus, array('sent', 'sent_manual'), true)) {
            return 'sent';
        }
        if ($quoteStatus === 'ready_to_send' || $sendStatus === 'ready_to_send') {
            return 'ready_to_send';
        }

        return 'draft';
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<int, string> $eventTypes
     * @return array<string, mixed>
     */
    public static function resolve(array $quote, array $eventTypes = array()): array
    {
        $stage = self::resolveStage($quote, $eventTypes);
        $definition = self::statuses()[$stage] ?? self::statuses()['draft'];

        return array_merge(array('status' => $stage), $definition);
    }
}
