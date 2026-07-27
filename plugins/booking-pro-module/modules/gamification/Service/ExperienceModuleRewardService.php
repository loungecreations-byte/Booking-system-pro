<?php

declare(strict_types=1);

namespace BSP\Gamification\Service;

final class ExperienceModuleRewardService
{
    /** @param array<string,mixed> $intent @return array<string,mixed> */
    public function grant(int $userId, int $tourId, int $chapterId, string $moduleId, array $intent): array
    {
        $amount = min(500, max(0, absint($intent['xp_amount'] ?? 0)));
        $context = array('tour_id' => $tourId, 'chapter_id' => $chapterId, 'module_id' => $moduleId);
        $xp = (new XpLedgerService())->award(
            $userId,
            'experience.module_reward',
            'tour_module',
            $chapterId . ':' . $moduleId,
            $context,
            $amount
        );
        $eventId = (int) ($xp['event_id'] ?? 0);
        $collectibles = $eventId > 0
            ? (new CollectibleUnlockService())->consume(
                'experience.module_reward',
                $userId,
                $tourId,
                (string) $chapterId,
                $eventId,
                $context
            )
            : array();

        return array('xp' => $xp, 'collectibles' => $collectibles);
    }
}
