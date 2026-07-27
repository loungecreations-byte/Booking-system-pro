<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\ModuleTypes;

final class VideoModule extends AbstractContentModule
{
    public function type(): string
    {
        return 'video';
    }

    protected function label(): string
    {
        return 'Video';
    }

    protected function icon(): string
    {
        return 'format-video';
    }

    public function normalize(array $module): array
    {
        $content = is_array($module['content'] ?? null) ? $module['content'] : array();
        $module['content'] = array(
            'attachment_id' => absint($content['attachment_id'] ?? 0),
            'url' => esc_url_raw((string) ($content['url'] ?? '')),
            'poster_attachment_id' => absint($content['poster_attachment_id'] ?? 0),
            'transcript' => wp_kses_post((string) ($content['transcript'] ?? '')),
        );

        return $module;
    }

    public function validate(array $module): array
    {
        $content = (array) ($module['content'] ?? array());
        if (absint($content['attachment_id'] ?? 0) === 0 && trim((string) ($content['url'] ?? '')) === '') {
            return array($this->error('content', 'video_required', 'Selecteer een video.'));
        }

        return array();
    }
}
