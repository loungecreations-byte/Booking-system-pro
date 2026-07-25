<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Service;

use BSP\DiscoveryCamera\Provider\FakeVisionProvider;
use BSP\DiscoveryCamera\Provider\VisionProvider;
use WP_Error;
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

    /** @param array<string,mixed> $file @param array<string,mixed> $challenge @return array<string,mixed>|WP_Error */
    public function completeUpload(string $uuid, int $userId, array $file, array $challenge)
    {
        $attempt = $this->db->get_row(
            $this->db->prepare(
                "SELECT id,attempt_uuid,status,challenge_revision FROM {$this->db->prefix}bsp_photo_attempts WHERE attempt_uuid=%s AND user_id=%d",
                $uuid,
                $userId
            ),
            ARRAY_A
        );
        if (! is_array($attempt)) {
            return new WP_Error('photo_attempt_not_found', 'Fotopoging niet gevonden.', array('status' => 404));
        }
        if (in_array((string) $attempt['status'], array('review', 'passed', 'failed'), true)) {
            return $this->findForUser($uuid, $userId) ?? array();
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($error !== UPLOAD_ERR_OK || $tmpName === '' || ! is_uploaded_file($tmpName)) {
            return new WP_Error('invalid_photo_upload', 'De foto-upload is ongeldig.', array('status' => 400));
        }
        if ($size <= 0 || $size > 8 * MB_IN_BYTES) {
            return new WP_Error('photo_too_large', 'De foto mag maximaal 8 MB zijn.', array('status' => 413));
        }

        $imageInfo = @getimagesize($tmpName);
        $mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
        if (! in_array($mime, array('image/jpeg', 'image/png', 'image/webp'), true)) {
            return new WP_Error('unsupported_photo_type', 'Gebruik JPEG, PNG of WebP.', array('status' => 415));
        }
        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        if ($width < 480 || $height < 480 || $width * $height > 40000000) {
            return new WP_Error('invalid_photo_dimensions', 'De fotoresolutie is niet geschikt.', array('status' => 422));
        }

        $privateDir = (string) apply_filters(
            'ddb/discovery_camera/private_directory',
            dirname(rtrim(ABSPATH, '/\\')) . DIRECTORY_SEPARATOR . 'ddb-private-media'
        );
        if (! wp_mkdir_p($privateDir)) {
            return new WP_Error('private_storage_unavailable', 'Privéopslag is niet beschikbaar.', array('status' => 503));
        }

        $editor = wp_get_image_editor($tmpName);
        if (is_wp_error($editor)) {
            return new WP_Error('photo_decode_failed', 'De foto kon niet veilig worden verwerkt.', array('status' => 422));
        }
        $editor->set_quality(82);
        $editor->resize(1600, 1600, false);
        $filename = sanitize_file_name($uuid . '.jpg');
        $destination = trailingslashit($privateDir) . $filename;
        $saved = $editor->save($destination, 'image/jpeg');
        if (is_wp_error($saved) || ! is_readable($destination)) {
            return new WP_Error('photo_store_failed', 'De foto kon niet privé worden opgeslagen.', array('status' => 500));
        }

        $hash = hash_file('sha256', $destination);
        $analysis = $this->provider->analyze($challenge, $hash);
        $now = gmdate('Y-m-d H:i:s');
        $attemptId = (int) $attempt['id'];

        $this->db->insert(
            $this->db->prefix . 'bsp_photo_analyses',
            array(
                'attempt_id' => $attemptId,
                'analysis_version' => 1,
                'provider' => sanitize_key((string) ($analysis['provider'] ?? 'fake')),
                'model' => 'staging-review-v1',
                'status' => 'review',
                'result_json' => wp_json_encode($analysis),
                'created_at' => $now,
                'completed_at' => $now,
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        $this->db->update(
            $this->db->prefix . 'bsp_photo_attempts',
            array(
                'status' => 'review',
                'upload_hash' => $hash,
                'private_object_key' => $filename,
                'captured_at' => $now,
                'updated_at' => $now,
            ),
            array('id' => $attemptId),
            array('%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        return array(
            'attempt_uuid' => $uuid,
            'status' => 'review',
            'challenge_revision' => (int) $attempt['challenge_revision'],
            'feedback' => array(
                'title' => 'Foto veilig ontvangen',
                'message' => 'De staging-provider heeft de foto klaargezet voor menselijke review.',
                'codes' => array('STAGING_FAKE_PROVIDER'),
            ),
            'rewarded' => false,
            'completed' => false,
        );
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
