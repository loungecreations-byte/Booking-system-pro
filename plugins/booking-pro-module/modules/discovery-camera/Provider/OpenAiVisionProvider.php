<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Provider;

use RuntimeException;

final class OpenAiVisionProvider implements VisionProvider
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    public function analyze(array $challenge, string $imagePath): array
    {
        $apiKey = trim((string) get_option('bsp_openai_api_key', ''));
        if ($apiKey === '' || ! is_readable($imagePath)) {
            throw new RuntimeException('Vision-provider is niet geconfigureerd.');
        }

        $mime = function_exists('mime_content_type') ? (string) mime_content_type($imagePath) : 'image/jpeg';
        if (! in_array($mime, array('image/jpeg', 'image/png', 'image/webp'), true)) {
            $mime = 'image/jpeg';
        }
        $image = base64_encode((string) file_get_contents($imagePath));
        $model = trim((string) get_option('ddb_discovery_camera_model', 'gpt-4o'));
        if ($model === '') {
            $model = 'gpt-4o';
        }

        $prompt = $this->prompt($challenge);
        $content = array(
            array('type' => 'input_text', 'text' => $prompt),
            array('type' => 'input_image', 'image_url' => 'data:' . $mime . ';base64,' . $image, 'detail' => 'high'),
        );
        $referenceId = absint($challenge['reference_image_id'] ?? 0);
        $referencePath = $referenceId > 0 ? get_attached_file($referenceId) : '';
        if (is_string($referencePath) && is_readable($referencePath)) {
            $referenceMime = function_exists('mime_content_type') ? (string) mime_content_type($referencePath) : 'image/jpeg';
            $content[] = array('type' => 'input_text', 'text' => 'Hierna volgt het historische of redactionele referentiebeeld.');
            $content[] = array('type' => 'input_image', 'image_url' => 'data:' . $referenceMime . ';base64,' . base64_encode((string) file_get_contents($referencePath)), 'detail' => 'high');
        }
        $body = array(
            'model' => $model,
            'store' => false,
            'input' => array(array(
                'role' => 'user',
                'content' => $content,
            )),
            'text' => array(
                'format' => array(
                    'type' => 'json_schema',
                    'name' => 'ddb_photo_challenge_review',
                    'strict' => true,
                    'schema' => $this->schema(),
                ),
            ),
        );

        $started = microtime(true);
        $response = wp_remote_post(self::ENDPOINT, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($body),
            'timeout' => 45,
            'redirection' => 0,
            'data_format' => 'body',
        ));
        if (is_wp_error($response)) {
            throw new RuntimeException('Vision-provider tijdelijk niet bereikbaar.');
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || ! is_array($payload)) {
            $code = is_array($payload) ? sanitize_key((string) ($payload['error']['code'] ?? $payload['error']['type'] ?? 'unknown')) : 'invalid_json';
            throw new RuntimeException(sprintf('Vision-provider fout HTTP %d (%s).', $status, $code));
        }

        $text = $this->outputText($payload);
        $result = json_decode($text, true);
        if (! is_array($result)) {
            throw new RuntimeException('Vision-resultaat had een ongeldig formaat.');
        }

        $scores = array();
        foreach (array('object', 'historical', 'composition', 'creativity', 'perspective', 'lighting', 'symmetry', 'detail') as $key) {
            $scores[$key] = min(100, max(0, (int) ($result['scores'][$key] ?? 0)));
        }
        $total = min(100, max(0, (int) ($result['total_score'] ?? 0)));

        return array(
            'provider' => 'openai',
            'model' => $model,
            'provider_request_id' => sanitize_text_field((string) ($payload['id'] ?? '')),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'status' => 'completed',
            'scores' => $scores,
            'total_score' => $total,
            'passed' => ! empty($result['object_found']) && ! empty($result['passed']),
            'object_found' => ! empty($result['object_found']),
            'historically_correct' => ! empty($result['historically_correct']),
            'extra_details' => array_values(array_slice(array_map('sanitize_text_field', (array) ($result['extra_details'] ?? array())), 0, 5)),
            'feedback' => array(
                'title' => sanitize_text_field((string) ($result['feedback_title'] ?? 'Foto beoordeeld')),
                'message' => sanitize_textarea_field((string) ($result['feedback_message'] ?? '')),
                'coach_tip' => sanitize_textarea_field((string) ($result['coach_tip'] ?? '')),
            ),
        );
    }

    private function prompt(array $challenge): string
    {
        $types = implode(', ', array_map('sanitize_key', (array) ($challenge['validation_type'] ?? array())));
        $context = wp_strip_all_tags((string) ($challenge['historical_context'] ?? ''));
        $custom = wp_strip_all_tags((string) ($challenge['ai_prompt'] ?? ''));
        $boss = implode(', ', array_map(
            static fn (array $target): string => (int) ($target['count'] ?? 1) . '× ' . sanitize_text_field((string) ($target['label'] ?? '')),
            (array) ($challenge['boss_targets'] ?? array())
        ));

        return sprintf(
            "Beoordeel deze foto voor een mobiele stadstour in 's-Hertogenbosch.\n"
            . "Missie: %s\nVereist object: %s\nValidaties: %s\nHistorische context: %s\n"
            . "Pass score: %d.\nBoss-doelen: %s\nInteractietype: %s\n%s\n"
            . "Wees eerlijk maar speels. Een foto slaagt alleen als het vereiste object echt zichtbaar is. "
            . "Geef concrete Nederlandstalige feedback en één korte tip voor een betere herkansing.",
            wp_strip_all_tags((string) ($challenge['mission'] ?? '')),
            wp_strip_all_tags((string) ($challenge['required_object']['label'] ?? $challenge['required_object']['type'] ?? '')),
            $types,
            $context,
            (int) ($challenge['pass_score'] ?? 70),
            $boss,
            sanitize_key((string) ($challenge['interaction_type'] ?? 'photo')),
            $custom
        );
    }

    /** @return array<string,mixed> */
    private function schema(): array
    {
        $scoreProperties = array();
        foreach (array('object', 'historical', 'composition', 'creativity', 'perspective', 'lighting', 'symmetry', 'detail') as $key) {
            $scoreProperties[$key] = array('type' => 'integer', 'minimum' => 0, 'maximum' => 100);
        }

        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array('object_found', 'historically_correct', 'passed', 'total_score', 'scores', 'extra_details', 'feedback_title', 'feedback_message', 'coach_tip'),
            'properties' => array(
                'object_found' => array('type' => 'boolean'),
                'historically_correct' => array('type' => 'boolean'),
                'passed' => array('type' => 'boolean'),
                'total_score' => array('type' => 'integer', 'minimum' => 0, 'maximum' => 100),
                'scores' => array(
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => array_keys($scoreProperties),
                    'properties' => $scoreProperties,
                ),
                'extra_details' => array('type' => 'array', 'maxItems' => 5, 'items' => array('type' => 'string')),
                'feedback_title' => array('type' => 'string'),
                'feedback_message' => array('type' => 'string'),
                'coach_tip' => array('type' => 'string'),
            ),
        );
    }

    /** @param array<string,mixed> $payload */
    private function outputText(array $payload): string
    {
        if (isset($payload['output_text']) && is_string($payload['output_text'])) {
            return $payload['output_text'];
        }
        foreach ((array) ($payload['output'] ?? array()) as $item) {
            foreach ((array) ($item['content'] ?? array()) as $content) {
                if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                    return (string) $content['text'];
                }
            }
        }
        return '';
    }
}
