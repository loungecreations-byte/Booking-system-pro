<?php
declare(strict_types=1);
namespace BSP\Gamification\Service;
use BSP\Gamification\Repository\CollectibleRepository;

final class CollectibleUnlockService
{
    public function __construct(private ?CollectibleRepository $repository=null,private ?XpLedgerService $ledger=null){$this->repository??=new CollectibleRepository();$this->ledger??=new XpLedgerService();}
    public function consume(string $eventType,int $userId,int $routeId,string $checkpointId='',int $verifiedEventId=0,array $context=array()): array
    {
        if($userId<=0||$routeId<=0||$verifiedEventId<=0){return array();}$unlocked=array();$progress=new \BSP\Gamification\Repository\ProgressRepository();$badgeEventId=0;
        foreach($this->repository->candidates($eventType,$routeId,$checkpointId) as $item){$created=$this->repository->unlock($userId,$item,$verifiedEventId,$context);if($created){$result=$this->ledger->award($userId,'collectible.unlocked','collectible',(string)$item['id'],array('route_id'=>$routeId,'rarity'=>$item['rarity']),(int)$item['xp_reward']);$badgeEventId=(int)($result['event_id']??0);do_action('bsp/gamification/collectible_unlocked',$userId,(int)$item['id'],$routeId);}else{$event=$progress->confirmedSourceEvent($userId,'collectible.unlocked','collectible',(string)$item['id']);$badgeEventId=max($badgeEventId,(int)($event['id']??0));}$unlocked[]=$this->repository->find((int)$item['id'],$userId);}
        if($unlocked){if($badgeEventId>0)(new BadgeEvaluationService($progress,$this->ledger))->evaluate($userId,'collectible.unlocked',$badgeEventId);(new CollectibleSetService())->evaluate($userId);}return array_values(array_filter($unlocked));
    }
}
