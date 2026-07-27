<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\ModuleTypes;

use BSP\ExperienceBuilder\Domain\ModuleDefinition;

final class SketchfabModule extends AbstractContentModule
{
    public function type(): string
    {
        return 'sketchfab';
    }

    protected function label(): string
    {
        return 'Sketchfab 3D';
    }

    protected function icon(): string
    {
        return 'format-gallery';
    }

    public function definition(): array
    {
        $definition = parent::definition();
        $definition['completion_modes'] = array(
            'manual',
            'viewer_ready',
            'minimum_view_time',
            'annotation_opened',
            'all_required_annotations',
        );
        $definition['events'] = array(
            'module_started',
            'model_loading',
            'model_loaded',
            'model_error',
            'annotation_opened',
            'module_completed',
        );

        return ModuleDefinition::normalize($definition);
    }

    public function normalize(array $module): array
    {
        $content = is_array($module['content'] ?? null) ? $module['content'] : array();
        $settings = is_array($module['settings'] ?? null) ? $module['settings'] : array();
        $url = esc_url_raw((string) ($content['model_url'] ?? ''));
        $uid = sanitize_text_field((string) ($content['model_uid'] ?? ''));
        if ($uid === '' && $url !== '') {
            $uid = self::uidFromUrl($url);
        }
        $annotations = array_values(array_unique(array_filter(array_map(
            'absint',
            is_array($settings['required_annotations'] ?? null) ? $settings['required_annotations'] : array()
        ), static fn (int $value): bool => $value >= 0)));

        $module['content'] = array(
            'model_url' => $url,
            'model_uid' => $uid,
            'introduction' => wp_kses_post((string) ($content['introduction'] ?? '')),
            'instruction' => sanitize_textarea_field((string) ($content['instruction'] ?? '')),
            'fallback_text' => sanitize_textarea_field((string) ($content['fallback_text'] ?? 'Dit 3D-model kan niet worden geladen.')),
        );
        $module['settings'] = array(
            'autostart' => ! empty($settings['autostart']),
            'autorotate' => min(10, max(-10, (float) ($settings['autorotate'] ?? 0))),
            'animation_autoplay' => ! array_key_exists('animation_autoplay', $settings) || ! empty($settings['animation_autoplay']),
            'minimum_view_seconds' => min(600, max(5, absint($settings['minimum_view_seconds'] ?? 15))),
            'required_annotations' => $annotations,
        );

        return $module;
    }

    public function validate(array $module): array
    {
        $errors = array();
        $content = (array) ($module['content'] ?? array());
        $settings = (array) ($module['settings'] ?? array());
        $url = (string) ($content['model_url'] ?? '');
        $uid = (string) ($content['model_uid'] ?? '');
        if (! self::validUid($uid)) {
            $errors[] = $this->error('content.model_uid', 'invalid_sketchfab_uid', 'Gebruik een geldige Sketchfab model-UID.');
        }
        if ($url !== '' && ! self::allowedUrl($url)) {
            $errors[] = $this->error('content.model_url', 'invalid_sketchfab_url', 'Alleen officiële Sketchfab model-URL’s zijn toegestaan.');
        }
        $mode = (string) ($module['completion']['mode'] ?? 'manual');
        if (in_array($mode, array('annotation_opened', 'all_required_annotations'), true) && $settings['required_annotations'] === array()) {
            $errors[] = $this->error('settings.required_annotations', 'required_annotations_missing', 'Vul minimaal één annotation-index in.');
        }

        return $errors;
    }

    public static function allowedUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        return in_array($host, array('sketchfab.com', 'www.sketchfab.com'), true)
            && (bool) preg_match('~/(?:models|3d-models)/[a-z0-9_-]+~i', $path);
    }

    public static function validUid(string $uid): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{20,40}$/', $uid);
    }

    private static function uidFromUrl(string $url): string
    {
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?: '');
        if (preg_match('~/models/([A-Za-z0-9]{20,40})~', $path, $match)) {
            return $match[1];
        }
        if (preg_match('~-([A-Za-z0-9]{20,40})(?:/|$)~', $path, $match)) {
            return $match[1];
        }

        return '';
    }
}
