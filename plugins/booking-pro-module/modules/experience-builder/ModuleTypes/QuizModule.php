<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\ModuleTypes;

use BSP\ExperienceBuilder\Domain\ModuleDefinition;

final class QuizModule extends AbstractContentModule
{
    public function type(): string { return 'quiz'; }
    protected function label(): string { return 'Quiz'; }
    protected function icon(): string { return 'editor-help'; }

    public function definition(): array
    {
        $definition = parent::definition();
        $definition['category'] = 'interactive';
        $definition['completion_modes'] = array('quiz_passed');
        $definition['events'] = array('quiz_submitted', 'quiz_completed', 'module_completed');
        $definition['defaults'] = array(
            'settings' => array('source' => 'chapter_meta'),
            'content' => array(),
            'completion' => array('mode' => 'quiz_passed', 'requirements' => array()),
        );
        return ModuleDefinition::normalize($definition);
    }

    public function normalize(array $module): array
    {
        $module['settings'] = array('source' => 'chapter_meta');
        $module['content'] = array();
        $module['completion'] = array('mode' => 'quiz_passed', 'requirements' => array());
        return $module;
    }
}
