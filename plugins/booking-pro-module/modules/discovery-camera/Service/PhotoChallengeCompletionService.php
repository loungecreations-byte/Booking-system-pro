<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Service;

use BSP\Experience\Service\ExperienceProgressService;
use BSP\ExperienceBuilder\Service\ModuleCompletionService;
use BSP\Gamification\Service\CollectibleUnlockService;
use BSP\Gamification\Service\XpLedgerService;

final class PhotoChallengeCompletionService
{
    /** @param array<string,mixed> $challenge @param array<string,mixed> $analysis @return array<string,mixed> */
    public function complete(int $userId, int $tourId, int $stepId, string $attemptUuid, array $challenge, array $analysis): array
    {
        $moduleId = class_exists(ModuleCompletionService::class)
            ? ModuleCompletionService::firstModuleIdForType($stepId, 'ai_photo_challenge')
            : '';
        $moduleCompletion = $moduleId !== ''
            ? (new ModuleCompletionService())->complete(
                $tourId,
                $stepId,
                $moduleId,
                $userId,
                0,
                array('event' => 'photo_approved', 'attempt_uuid' => $attemptUuid)
            )
            : array();
        $progress = $moduleId === ''
            ? (new ExperienceProgressService())->merge($userId, $tourId, array($stepId), $stepId)
            : array();
        $xp = (new XpLedgerService())->award(
            $userId,
            'photo_challenge.passed',
            'photo_attempt',
            $attemptUuid,
            array(
                'tour_id' => $tourId,
                'step_id' => $stepId,
                'score' => (int) ($analysis['total_score'] ?? 0),
                'badge_reward' => sanitize_key((string) ($challenge['badge_reward'] ?? '')),
            ),
            (int) ($challenge['xp_reward'] ?? 0)
        );
        $eventId = (int) ($xp['event_id'] ?? 0);
        $collectibles = $eventId > 0
            ? (new CollectibleUnlockService())->consume(
                'photo_challenge.passed',
                $userId,
                $tourId,
                (string) $stepId,
                $eventId,
                array('attempt_uuid' => $attemptUuid, 'score' => (int) ($analysis['total_score'] ?? 0))
            )
            : array();

        do_action('ddb/discovery_camera/challenge_passed', $userId, $tourId, $stepId, $attemptUuid, $analysis);

        return array(
            'progress' => $progress,
            'module_completion' => is_wp_error($moduleCompletion) ? array() : $moduleCompletion,
            'xp' => $xp,
            'collectibles' => $collectibles,
            'next_unlock' => sanitize_key((string) ($challenge['next_unlock'] ?? 'next_chapter')),
        );
    }
}
