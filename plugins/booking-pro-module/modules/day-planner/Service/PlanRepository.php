<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

use BSP\DayPlanner\PostType\PlanPostType;
use RuntimeException;

final class PlanRepository
{
    private const META_KEY_PAYLOAD = '_sbdp_plan_payload';

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, int $ownerId): array
    {
        if (! \function_exists('wp_insert_post')) {
            throw new RuntimeException('WordPress environment is not available.');
        }

        $planId = \wp_insert_post(
            [
                'post_type'   => PlanPostType::POST_TYPE,
                'post_title'  => $payload['title'] ?? __('New plan', 'sbdp'),
                'post_status' => 'publish',
                'post_author' => $ownerId,
            ],
            true
        );

        if ($planId instanceof \WP_Error) {
            throw new RuntimeException($planId->get_error_message());
        }

        $planId = (int) $planId;

        $this->persistPayload($planId, $payload);

        return $this->get($planId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $planId, array $payload): array
    {
        if (! \function_exists('get_post')) {
            return $payload;
        }

        $post = \get_post($planId);
        if (! $post || $post->post_type !== PlanPostType::POST_TYPE) {
            throw new RuntimeException('Plan not found.');
        }

        $this->persistPayload($planId, $payload);

        if (isset($payload['title']) && $payload['title'] !== $post->post_title) {
            \wp_update_post(
                [
                    'ID'         => $planId,
                    'post_title' => (string) $payload['title'],
                ]
            );
        }

        return $this->get($planId);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $planId): array
    {
        if (! \function_exists('get_post')) {
            return [];
        }

        $post = \get_post($planId);
        if (! $post || $post->post_type !== PlanPostType::POST_TYPE) {
            throw new RuntimeException('Plan not found.');
        }

        $payload = \get_post_meta($planId, self::META_KEY_PAYLOAD, true);
        if (! is_array($payload)) {
            $payload = [];
        }

        $payload['id']     = $planId;
        $payload['title']  = $post->post_title;
        $payload['owner']  = (int) $post->post_author;
        $payload['status'] = $post->post_status;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistPayload(int $planId, array $payload): void
    {
        if (! \function_exists('update_post_meta')) {
            return;
        }

        \update_post_meta($planId, self::META_KEY_PAYLOAD, $payload);
    }

}
