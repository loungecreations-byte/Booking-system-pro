<?php
declare(strict_types=1);
namespace BSP\Gamification\Service;
use BSP\Gamification\Repository\ProgressRepository;

final class BadgeEvaluationService
{
    public function __construct(private ProgressRepository $repository, private XpLedgerService $ledger) {}
    public function evaluate(int $userId, string $eventType, int $eventId): void
    {
        foreach ($this->repository->badges($userId) as $badge) {
            if (! empty($badge['awarded_at']) && empty($badge['revoked_at'])) { continue; }
            $criteria = json_decode((string) $badge['criteria_json'],true);
            if (! is_array($criteria) || ($criteria['event_type'] ?? '') !== $eventType) { continue; }
            $count = $this->repository->eventCount($userId,$eventType,! empty($criteria['unique_source']));
            if ($count < (int) ($criteria['count'] ?? 1)) { continue; }
            if ($this->repository->awardBadge($userId,(int)$badge['id'],$eventId,array('title'=>$badge['title'],'slug'=>$badge['slug']))) {
                $reward = (int) ($badge['xp_reward'] ?? 0);
                if ($reward > 0) { $this->ledger->award($userId,'badge.awarded','badge',(string)$badge['id'],array('badge'=>$badge['slug']),$reward); }
            }
        }
    }
}
