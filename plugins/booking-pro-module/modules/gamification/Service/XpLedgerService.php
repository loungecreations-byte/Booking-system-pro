<?php
declare(strict_types=1);
namespace BSP\Gamification\Service;
use BSP\Gamification\Repository\ProgressRepository;
use InvalidArgumentException;

final class XpLedgerService
{
    private const XP = array('account.first_activity'=>25,'qr.checkpoint_verified'=>10,'route.completed'=>100,'audio_tour.completed'=>75,'booking.payment_completed'=>75,'ticket.attendance_confirmed'=>50,'review.verified'=>30,'category.discovered'=>20);
    public function __construct(private ?ProgressRepository $repository = null) { $this->repository ??= new ProgressRepository(); }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function award(int $userId, string $type, string $sourceType, string $sourceId, array $context = array(), ?int $xp = null): array
    {
        if ($userId <= 0 || $type === '' || $sourceId === '') { throw new InvalidArgumentException('Ongeldig XP-event.'); }
        if ((string)get_user_meta($userId,'_bsp_gamification_opt_in',true) === '0' && $type !== 'event.reversed' && $type !== 'admin.adjustment') { return array('created'=>false,'reason'=>'opted_out'); }
        $amount = $xp ?? $this->xpFor($type, $userId); if ($amount === 0) { return array('created'=>false); }
        $key = hash('sha256', implode('|',array($userId,$type,$sourceType,$sourceId)));
        $eventId = $this->repository->insertEvent(array('user_id'=>$userId,'event_type'=>$type,'source_type'=>$sourceType,'source_id'=>$sourceId,'idempotency_key'=>$key,'xp_delta'=>$amount,'status'=>'confirmed','reason_code'=>'','context_json'=>wp_json_encode($context),'occurred_at'=>gmdate('Y-m-d H:i:s')));
        if ($eventId === 0) { return array('created'=>false); }
        $level = $this->repository->project($userId,$eventId,$amount);
        (new BadgeEvaluationService($this->repository,$this))->evaluate($userId,$type,$eventId);
        do_action('bsp/gamification/xp_awarded',$userId,$eventId,$amount,$level);
        return array('created'=>true,'event_id'=>$eventId,'xp'=>$amount,'level'=>$level);
    }

    public function adjust(int $userId, int $delta, string $reason, string $sourceId): array
    {
        if ($delta === 0 || trim($reason) === '') { throw new InvalidArgumentException('Correctie en reden zijn verplicht.'); }
        return $this->award($userId,'admin.adjustment','admin',$sourceId,array('reason'=>$reason),$delta);
    }

    public function reverseSource(int $userId, string $eventType, string $sourceType, string $sourceId, string $reason): array
    {
        $event = $this->repository->confirmedSourceEvent($userId,$eventType,$sourceType,$sourceId);
        if (! $event) { return array('created'=>false); }
        return $this->award($userId,'event.reversed','xp_event',(string)$event['id'],array('reason'=>$reason,'original_event_id'=>(int)$event['id']),-1 * (int)$event['xp_delta']);
    }

    private function xpFor(string $type, int $userId): int
    {
        $xp = self::XP[$type] ?? 0;
        if ($type === 'booking.payment_completed' && $this->repository->eventCount($userId,$type,true) === 0) { $xp = 150; }
        return (int) apply_filters('bsp/gamification/xp_for_event',$xp,$type,$userId);
    }
}
