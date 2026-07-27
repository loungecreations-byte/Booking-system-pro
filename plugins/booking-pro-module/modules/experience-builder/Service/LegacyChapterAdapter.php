<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\Service;

final class LegacyChapterAdapter
{
    /** @return array<string,mixed> */
    public function virtualDocument(int $chapterId): array
    {
        $post = get_post($chapterId);
        if (! $post || $post->post_type !== 'sbdp_tour_step') {
            return array('schema_version' => 1, 'document_id' => '', 'revision' => 0, 'virtual' => true, 'modules' => array());
        }

        $modules = array();
        $content = trim((string) $post->post_content);
        if ($content !== '') {
            $modules[] = $this->module($chapterId, 'text', count($modules), array('html' => wp_kses_post($content)));
        }

        $media = array(
            'image' => '_sbdp_step_image_url',
            'audio' => '_sbdp_step_audio_url',
            'video' => '_sbdp_step_video_url',
        );
        foreach ($media as $type => $metaKey) {
            $url = esc_url_raw((string) get_post_meta($chapterId, $metaKey, true));
            if ($url !== '') {
                $modules[] = $this->module($chapterId, $type, count($modules), array('attachment_id' => 0, 'url' => $url));
            }
        }

        $quiz = get_post_meta($chapterId, '_sbdp_step_quiz', true);
        if (is_array($quiz) && $quiz !== array()) {
            $modules[] = $this->module($chapterId, 'quiz', count($modules), $quiz);
        }

        $challenge = get_post_meta($chapterId, '_sbdp_photo_challenge_v1', true);
        if (is_array($challenge) && $challenge !== array()) {
            $modules[] = $this->module($chapterId, 'ai_photo_challenge', count($modules), $challenge);
        }

        return array(
            'schema_version' => 1,
            'document_id' => 'legacy-chapter-' . $chapterId,
            'revision' => 0,
            'virtual' => true,
            'modules' => $modules,
        );
    }

    /** @param array<string,mixed> $content @return array<string,mixed> */
    private function module(int $chapterId, string $type, int $index, array $content): array
    {
        return array(
            'id' => $this->stableUuid($chapterId . ':' . $type . ':' . $index),
            'type' => $type,
            'version' => 1,
            'index' => $index,
            'enabled' => true,
            'title' => '',
            'settings' => array(),
            'content' => $content,
            'conditions' => array(),
            'completion' => array('mode' => 'automatic', 'requirements' => array()),
            'visibility' => array('mode' => 'when_conditions_match'),
            'metadata' => array('source' => 'legacy', 'read_only' => true),
        );
    }

    private function stableUuid(string $value): string
    {
        $hash = md5($value);

        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-4' . substr($hash, 13, 3)
            . '-a' . substr($hash, 17, 3) . '-' . substr($hash, 20, 12);
    }
}
