<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\ModuleTypes;

final class ImageModule extends AbstractContentModule
{
    public function type(): string
    {
        return 'image';
    }

    protected function label(): string
    {
        return 'Afbeelding';
    }

    protected function icon(): string
    {
        return 'format-image';
    }

    public function normalize(array $module): array
    {
        $content = is_array($module['content'] ?? null) ? $module['content'] : array();
        $module['content'] = array(
            'attachment_id' => absint($content['attachment_id'] ?? 0),
            'url' => esc_url_raw((string) ($content['url'] ?? '')),
            'alt' => sanitize_text_field((string) ($content['alt'] ?? '')),
            'caption' => wp_kses_post((string) ($content['caption'] ?? '')),
        );

        return $module;
    }

    public function validate(array $module): array
    {
        $content = (array) ($module['content'] ?? array());
        if (absint($content['attachment_id'] ?? 0) === 0 && trim((string) ($content['url'] ?? '')) === '') {
            return array($this->error('content', 'image_required', 'Selecteer een afbeelding.'));
        }

        return array();
    }
}
