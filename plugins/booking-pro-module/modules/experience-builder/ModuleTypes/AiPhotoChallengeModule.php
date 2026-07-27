<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\ModuleTypes;

use BSP\ExperienceBuilder\Domain\ModuleDefinition;

final class AiPhotoChallengeModule extends AbstractContentModule
{
    public function type(): string
    {
        return 'ai_photo_challenge';
    }

    protected function label(): string
    {
        return 'AI Photo Challenge';
    }

    protected function icon(): string
    {
        return 'camera';
    }

    public function definition(): array
    {
        $definition = parent::definition();
        $definition['category'] = 'interactive';
        $definition['completion_modes'] = array('photo_approved');
        $definition['events'] = array(
            'module_started',
            'photo_uploaded',
            'photo_approved',
            'photo_rejected',
            'module_completed',
        );
        $definition['defaults'] = array(
            'settings' => array('source' => 'chapter_meta'),
            'content' => array(),
            'completion' => array('mode' => 'photo_approved', 'requirements' => array()),
        );

        return ModuleDefinition::normalize($definition);
    }

    public function normalize(array $module): array
    {
        $module['settings'] = array('source' => 'chapter_meta');
        $module['content'] = array();
        $module['completion'] = array('mode' => 'photo_approved', 'requirements' => array());

        return $module;
    }
}
