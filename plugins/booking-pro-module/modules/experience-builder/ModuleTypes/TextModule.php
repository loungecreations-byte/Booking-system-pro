<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\ModuleTypes;

final class TextModule extends AbstractContentModule
{
    public function type(): string
    {
        return 'text';
    }

    protected function label(): string
    {
        return 'Tekst';
    }

    protected function icon(): string
    {
        return 'editor-paragraph';
    }

    public function normalize(array $module): array
    {
        $module['content'] = array(
            'html' => wp_kses_post((string) (($module['content']['html'] ?? ''))),
        );

        return $module;
    }
}
