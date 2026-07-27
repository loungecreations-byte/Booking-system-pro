<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\ModuleTypes;

final class AudioModule extends AbstractContentModule
{
    public function type(): string
    {
        return 'audio';
    }

    protected function label(): string
    {
        return 'Audio';
    }

    protected function icon(): string
    {
        return 'format-audio';
    }

    public function normalize(array $module): array
    {
        $content = is_array($module['content'] ?? null) ? $module['content'] : array();
        $module['content'] = array(
            'attachment_id' => absint($content['attachment_id'] ?? 0),
            'url' => esc_url_raw((string) ($content['url'] ?? '')),
            'transcript' => wp_kses_post((string) ($content['transcript'] ?? '')),
        );

        return $module;
    }

    public function validate(array $module): array
    {
        $content = (array) ($module['content'] ?? array());
        if (absint($content['attachment_id'] ?? 0) === 0 && trim((string) ($content['url'] ?? '')) === '') {
            return array($this->error('content', 'audio_required', 'Selecteer een audiobestand.'));
        }

        return array();
    }
}
