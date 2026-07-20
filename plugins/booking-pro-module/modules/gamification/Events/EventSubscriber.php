<?php
declare(strict_types=1);
namespace BSP\Gamification\Events;
use BSP\Gamification\Service\XpLedgerService;
use BSP\Gamification\Service\CollectibleUnlockService;

final class EventSubscriber
{
    public function __construct(private ?XpLedgerService $ledger = null) { $this->ledger ??= new XpLedgerService(); }
    public function register(): void
    {
        add_action('bsp/gamification/event',array($this,'consume'),10,5);
        add_action('woocommerce_payment_complete',array($this,'paymentCompleted'));
        add_action('woocommerce_order_status_processing',array($this,'paymentCompleted'));
        add_action('woocommerce_order_status_completed',array($this,'paymentCompleted'));
        add_action('woocommerce_order_status_refunded',array($this,'paymentReversed'));
        add_action('woocommerce_order_status_cancelled',array($this,'paymentReversed'));
        add_action('sbdp/route/completed',array($this,'routeCompleted'),10,2);
        add_action('sbdp/audio_tour/completed',array($this,'audioCompleted'),10,2);
        add_action('sbdp/qr/checkpoint_verified',array($this,'qrVerified'),10,3);
        add_action('sbdp/ticket/attendance_confirmed',array($this,'attendanceConfirmed'),10,2);
        add_action('sbdp/review/verified',array($this,'reviewVerified'),10,2);
        add_action('bsp/gamification/verified_route_event',array($this,'collectibles'),10,6);
    }

    public function consume(string $type, int $userId, string $sourceType, string $sourceId, array $context = array()): void { $this->ledger->award($userId,$type,$sourceType,$sourceId,$context); }
    public function paymentCompleted(int $orderId): void
    {
        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        if (! $order) { return; } $userId = (int) $order->get_user_id(); if ($userId <= 0) { return; }
        $this->ledger->award($userId,'booking.payment_completed','woo_order',(string)$orderId,array('order_status'=>$order->get_status()));
    }
    public function paymentReversed(int $orderId): void
    {
        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        if (! $order || (int)$order->get_user_id() <= 0) { return; }
        $this->ledger->reverseSource((int)$order->get_user_id(),'booking.payment_completed','woo_order',(string)$orderId,'Woo-order geannuleerd of terugbetaald');
    }
    public function routeCompleted(int $userId, $routeId): void { $this->ledger->award($userId,'route.completed','route',(string)$routeId); }
    public function audioCompleted(int $userId, $tourId): void { $this->ledger->award($userId,'audio_tour.completed','audio_tour',(string)$tourId); }
    public function qrVerified(int $userId, $checkpointId, $routeId = ''): void { $this->ledger->award($userId,'qr.checkpoint_verified','qr_checkpoint',(string)$checkpointId,array('route_id'=>$routeId)); }
    public function attendanceConfirmed(int $userId, $ticketId): void { $this->ledger->award($userId,'ticket.attendance_confirmed','ticket',(string)$ticketId); }
    public function reviewVerified(int $userId, $reviewId): void { $this->ledger->award($userId,'review.verified','review',(string)$reviewId); }
    public function collectibles(string $eventType,int $userId,int $routeId,string $checkpointId,int $verifiedEventId,array $context=array()): void { (new CollectibleUnlockService())->consume($eventType,$userId,$routeId,$checkpointId,$verifiedEventId,$context); }
}
