<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\ModuleTypes;

use BSP\ExperienceBuilder\Domain\ModuleDefinition;

final class RewardModule extends AbstractContentModule
{
    public function type(): string { return 'reward'; }
    protected function label(): string { return 'Beloning'; }
    protected function icon(): string { return 'awards'; }

    public function definition(): array
    {
        $definition = parent::definition();
        $definition['category'] = 'progress';
        $definition['completion_modes'] = array('server_claim');
        $definition['events'] = array('reward_requested', 'reward_granted', 'module_completed');
        return ModuleDefinition::normalize($definition);
    }

    public function normalize(array $module): array
    {
        $content = is_array($module['content'] ?? null) ? $module['content'] : array();
        $module['content'] = array(
            'title' => sanitize_text_field((string) ($content['title'] ?? 'Beloning ontgrendeld')),
            'message' => sanitize_textarea_field((string) ($content['message'] ?? 'Je hebt alle onderdelen voltooid.')),
            'xp_amount' => min(500, max(0, absint($content['xp_amount'] ?? 0))),
        );
        $module['settings'] = array('event_type' => 'experience.module_reward');
        $module['completion'] = array('mode' => 'server_claim', 'requirements' => array());
        return $module;
    }
}
