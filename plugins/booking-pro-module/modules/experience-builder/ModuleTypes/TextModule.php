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

    /** @param array<string,mixed> $module @return array<int,array<string,string>> */
    public function validate(array $module): array
    {
        $text = trim(wp_strip_all_tags((string) ($module['content']['html'] ?? '')));

        return $text === ''
            ? array($this->error('content.html', 'missing_text_content', 'Vul tekst in of schakel deze module uit.'))
            : array();
    }
}
