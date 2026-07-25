<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Service;

use BSP\DiscoveryCamera\Provider\FakeVisionProvider;
use BSP\DiscoveryCamera\Provider\VisionProvider;
use wpdb;

final class PhotoAttemptService
{
    private wpdb $db;
    private VisionProvider $provider;

    public function __construct(?wpdb $db = null, ?VisionProvider $provider = null)
    {
        global $wpdb;
        $this->db = $db ?? $wpdb;
        $this->provider = $provider ?? new FakeVisionProvider();
    }

    /** @param array<string,mixed> $challenge @return array<string,mixed> */
    public function create(
        int $userId,
        int $tourId,
        int $stepId,
        array $challenge,
        string $idempotencyKey,
        string $uploadHash = ''
    ): array {
        $table = $this->db->prefix . 'bsp_photo_attempts';
        $idempotencyKey = hash('sha256', implode('|', array(
            $userId,
            $tourId,
            $stepId,
            sanitize_text_field($idempotencyKey),
        )));

        $existing = $this->db->get_row(
            $this->db->prepare(
                "SELECT attempt_uuid,status,challenge_revision,created_at FROM {$table} WHERE idempotency_key=%s",
                $idempotencyKey
            ),
            ARRAY_A
        );
        if (is_array($existing)) {
            return $this->present($existing, true);
        }

        $uuid = wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        $normalizedHash = preg_match('/^[a-f0-9]{64}$/', $uploadHash) ? $uploadHash : null;

        $inserted = $this->db->insert(
            $table,
            array(
                'attempt_uuid' => $uuid,
                'idempotency_key' => $idempotencyKey,
                'user_id' => $userId,
                'tour_id' => $tourId,
                'step_id' => $stepId,
                'challenge_revision' => (int) ($challenge['revision'] ?? 1),
                'status' => 'created',
                'upload_hash' => $normalizedHash,
                'consent_version' => 'staging-v1',
                'expires_at' => $expires,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($inserted !== 1) {
            return array('created' => false, 'error' => 'attempt_create_failed');
        }

        return array(
            'created' => true,
            'replayed' => false,
            'attempt_uuid' => $uuid,
            'status' => 'created',
            'challenge_revision' => (int) ($challenge['revision'] ?? 1),
            'expires_at' => $expires,
            'provider_mode' => 'fake',
        );
    }

    /** @return array<string,mixed>|null */
    public function findForUser(string $uuid, int $userId): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT attempt_uuid,tour_id,step_id,status,challenge_revision,expires_at,created_at,updated_at FROM {$this->db->prefix}bsp_photo_attempts WHERE attempt_uuid=%s AND user_id=%d",
                $uuid,
                $userId
            ),
            ARRAY_A
        );

        return is_array($row) ? $this->present($row, false) : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function present(array $row, bool $replayed): array
    {
        return array(
            'created' => ! $replayed,
            'replayed' => $replayed,
            'attempt_uuid' => (string) ($row['attempt_uuid'] ?? ''),
            'status' => (string) ($row['status'] ?? 'created'),
            'challenge_revision' => (int) ($row['challenge_revision'] ?? 1),
            'tour_id' => isset($row['tour_id']) ? (int) $row['tour_id'] : null,
            'step_id' => isset($row['step_id']) ? (int) $row['step_id'] : null,
            'expires_at' => $row['expires_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'provider_mode' => 'fake',
        );
    }
}
