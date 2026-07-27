<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\ModuleTypes;

use BSP\ExperienceBuilder\Contract\ModuleTypeInterface;
use BSP\ExperienceBuilder\Domain\ModuleDefinition;

abstract class AbstractContentModule implements ModuleTypeInterface
{
    abstract protected function label(): string;

    protected function icon(): string
    {
        return 'media-default';
    }

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ModuleDefinition::normalize(array(
            'type' => $this->type(),
            'label' => $this->label(),
            'icon' => $this->icon(),
            'category' => 'content',
            'schema_version' => 1,
            'defaults' => array(
                'settings' => array(),
                'content' => array(),
                'completion' => array('mode' => 'automatic', 'requirements' => array()),
            ),
            'events' => array('module_viewed', 'module_started', 'module_completed', 'module_failed'),
            'completion_modes' => array('automatic', 'manual'),
            'capability' => 'edit_posts',
        ));
    }

    /** @param array<string,mixed> $module @return array<string,mixed> */
    public function normalize(array $module): array
    {
        return $module;
    }

    /** @param array<string,mixed> $module @return array<int,array<string,string>> */
    public function validate(array $module): array
    {
        return array();
    }

    /** @return array<string,string> */
    protected function error(string $path, string $code, string $message): array
    {
        return array('path' => $path, 'code' => $code, 'message' => $message);
    }
}
