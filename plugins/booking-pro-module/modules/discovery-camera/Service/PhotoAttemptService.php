<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Service;

use BSP\DiscoveryCamera\Domain\ExperienceState;
use BSP\DiscoveryCamera\Provider\ProviderFactory;
use BSP\DiscoveryCamera\Provider\VisionProvider;
use BSP\DiscoveryCamera\Support\FeatureFlags;
use Throwable;
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
        $this->provider = $provider ?? ProviderFactory::make();
    }

    /** @param array<string,mixed> $challenge @return array<string,mixed> */
    public function create(
        int $userId,
        int $tourId,
        int $stepId,
        array $challenge,
        string $idempotencyKey,
        string $uploadHash = '',
        int $ticketId = 0
    ): array {
        $table = $this->db->prefix . 'bsp_photo_attempts';
        $idempotencyKey = hash('sha256', implode('|', array(
            $ticketId > 0 ? 'ticket:' . $ticketId : 'user:' . $userId,
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
                'ticket_id' => $ticketId > 0 ? $ticketId : null,
                'tour_id' => $tourId,
                'step_id' => $stepId,
                'challenge_revision' => (int) ($challenge['revision'] ?? 1),
                'status' => 'created',
                'upload_hash' => $normalizedHash,
                'consent_version' => '2026-privacy-v1',
                'expires_at' => $expires,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
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
    public function findForUser(string $uuid, int $userId, int $ticketId = 0): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT attempt_uuid,tour_id,step_id,status,challenge_revision,expires_at,created_at,updated_at FROM {$this->db->prefix}bsp_photo_attempts "
                . "WHERE attempt_uuid=%s AND ((%d>0 AND ticket_id=%d) OR (%d=0 AND user_id=%d))",
                $uuid,
                $ticketId,
                $ticketId,
                $ticketId,
                $userId
            ),
            ARRAY_A
        );

        return is_array($row) ? $this->present($row, false) : null;
    }

    /** @param array<string,mixed> $file @param array<string,mixed> $challenge @return array<string,mixed>|WP_Error */
    public function completeUpload(string $uuid, int $userId, array $file, array $challenge, int $ticketId = 0)
    {
        $attempt = $this->db->get_row(
            $this->db->prepare(
                "SELECT id,attempt_uuid,tour_id,step_id,status,challenge_revision FROM {$this->db->prefix}bsp_photo_attempts "
                . "WHERE attempt_uuid=%s AND ((%d>0 AND ticket_id=%d) OR (%d=0 AND user_id=%d))",
                $uuid,
                $ticketId,
                $ticketId,
                $ticketId,
                $userId
            ),
            ARRAY_A
        );
        if (! is_array($attempt)) {
            return new WP_Error('photo_attempt_not_found', 'Fotopoging niet gevonden.', array('status' => 404));
        }
        if (in_array((string) $attempt['status'], array('review', 'passed', 'failed'), true)) {
            return $this->resultForUser($uuid, $userId, $ticketId) ?? array();
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
        $now = gmdate('Y-m-d H:i:s');
        $attemptId = (int) $attempt['id'];
        if (! ExperienceState::canTransition((string) $attempt['status'], ExperienceState::ANALYZING)) {
            return new WP_Error('photo_state_conflict', 'Deze fotopoging kan niet opnieuw worden geanalyseerd.', array('status' => 409));
        }
        $this->db->update(
            $this->db->prefix . 'bsp_photo_attempts',
            array(
                'status' => ExperienceState::ANALYZING,
                'upload_hash' => $hash,
                'private_object_key' => $filename,
                'captured_at' => $now,
                'updated_at' => $now,
            ),
            array('id' => $attemptId),
            array('%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        try {
            $analysis = $this->provider->analyze($challenge, $destination);
        } catch (Throwable $error) {
            $analysis = array(
                'provider' => FeatureFlags::providerMode() === 'fake' ? 'fake' : 'openai',
                'status' => 'review',
                'scores' => array(),
                'total_score' => null,
                'passed' => false,
                'feedback' => array(
                    'title' => 'Menselijke controle nodig',
                    'message' => 'De automatische beoordeling is tijdelijk niet beschikbaar. Je foto is veilig ontvangen.',
                    'coach_tip' => '',
                ),
                'error_code' => 'VISION_PROVIDER_UNAVAILABLE',
            );
        }

        $mode = FeatureFlags::providerMode();
        $score = isset($analysis['total_score']) ? (int) $analysis['total_score'] : null;
        $passed = ! empty($analysis['passed']) && $score !== null && $score >= (int) ($challenge['pass_score'] ?? 70);
        $bossProgress = array();
        $isBoss = (string) ($challenge['interaction_type'] ?? '') === 'boss' && (array) ($challenge['boss_targets'] ?? array()) !== array();
        if ($isBoss && $mode !== 'fake') {
            $bossProgress = (new BossProgressService())->record(
                $userId,
                (int) $attempt['tour_id'],
                (int) $attempt['step_id'],
                (array) $challenge['boss_targets'],
                (array) ($analysis['detected_targets'] ?? array()),
                $ticketId
            );
            $passed = ! empty($bossProgress['completed']);
        }
        $status = $mode === 'live'
            ? ($passed ? ExperienceState::PASSED : ($isBoss ? ExperienceState::PARTIAL : ExperienceState::FAILED))
            : ExperienceState::REVIEW;
        if (! ExperienceState::canTransition(ExperienceState::ANALYZING, $status)) {
            return new WP_Error('photo_state_conflict', 'De beoordelingsstatus is ongeldig.', array('status' => 409));
        }
        $scores = (array) ($analysis['scores'] ?? array());

        $this->db->insert(
            $this->db->prefix . 'bsp_photo_analyses',
            array(
                'attempt_id' => $attemptId,
                'analysis_version' => 1,
                'provider' => sanitize_key((string) ($analysis['provider'] ?? 'fake')),
                'model' => sanitize_text_field((string) ($analysis['model'] ?? 'staging-review-v1')),
                'status' => $status,
                'object_score' => $this->decimalScore($scores['object'] ?? null),
                'historical_score' => $this->decimalScore($scores['historical'] ?? null),
                'composition_score' => $this->decimalScore($scores['composition'] ?? null),
                'creativity_score' => $this->decimalScore($scores['creativity'] ?? null),
                'perspective_score' => $this->decimalScore($scores['perspective'] ?? null),
                'lighting_score' => $this->decimalScore($scores['lighting'] ?? null),
                'symmetry_score' => $this->decimalScore($scores['symmetry'] ?? null),
                'detail_score' => $this->decimalScore($scores['detail'] ?? null),
                'total_score' => $score,
                'result_json' => wp_json_encode($analysis),
                'provider_request_id' => sanitize_text_field((string) ($analysis['provider_request_id'] ?? '')),
                'latency_ms' => absint($analysis['latency_ms'] ?? 0),
                'created_at' => $now,
                'completed_at' => $now,
            ),
            array('%d', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%d', '%s', '%s')
        );
        $this->db->update(
            $this->db->prefix . 'bsp_photo_attempts',
            array(
                'status' => $status,
                'updated_at' => $now,
            ),
            array('id' => $attemptId),
            array('%s', '%s'),
            array('%d')
        );

        $rewards = array();
        if ($status === 'passed' && $userId > 0) {
            $rewards = (new PhotoChallengeCompletionService())->complete(
                $userId,
                (int) $attempt['tour_id'],
                (int) $attempt['step_id'],
                $uuid,
                $challenge,
                $analysis
            );
        } elseif ($status === 'passed' && $ticketId > 0) {
            $rewards = $this->completeTicket($ticketId, (int) $attempt['step_id'], $challenge, $uuid);
        }

        $feedback = (array) ($analysis['feedback'] ?? array());
        return array(
            'attempt_uuid' => $uuid,
            'status' => $status,
            'challenge_revision' => (int) $attempt['challenge_revision'],
            'feedback' => array(
                'title' => sanitize_text_field((string) ($feedback['title'] ?? ($status === 'passed' ? 'Ontdekking geslaagd' : 'Foto beoordeeld'))),
                'message' => sanitize_textarea_field((string) ($feedback['message'] ?? '')),
                'coach_tip' => sanitize_textarea_field((string) ($feedback['coach_tip'] ?? '')),
            ),
            'scores' => $scores,
            'total_score' => $score,
            'extra_details' => array_values((array) ($analysis['extra_details'] ?? array())),
            'boss_progress' => $bossProgress,
            'rewarded' => ! empty($rewards['xp']['created']) || ! empty($rewards['collectibles']),
            'completed' => $status === 'passed',
            'rewards' => $rewards,
        );
    }

    /** @return array<string,mixed>|null */
    public function resultForUser(string $uuid, int $userId, int $ticketId = 0): ?array
    {
        $attempt = $this->db->get_row(
            $this->db->prepare(
                "SELECT a.attempt_uuid,a.tour_id,a.step_id,a.status,a.challenge_revision,a.expires_at,a.created_at,a.updated_at,n.result_json "
                . "FROM {$this->db->prefix}bsp_photo_attempts a "
                . "LEFT JOIN {$this->db->prefix}bsp_photo_analyses n ON n.attempt_id=a.id "
                . "WHERE a.attempt_uuid=%s AND ((%d>0 AND a.ticket_id=%d) OR (%d=0 AND a.user_id=%d)) "
                . "ORDER BY n.analysis_version DESC LIMIT 1",
                $uuid,
                $ticketId,
                $ticketId,
                $ticketId,
                $userId
            ),
            ARRAY_A
        );
        if (! is_array($attempt)) {
            return null;
        }
        $result = $this->present($attempt, false);
        $analysis = json_decode((string) ($attempt['result_json'] ?? ''), true);
        if (is_array($analysis)) {
            $result['scores'] = (array) ($analysis['scores'] ?? array());
            $result['total_score'] = $analysis['total_score'] ?? null;
            $result['feedback'] = (array) ($analysis['feedback'] ?? array());
            $result['extra_details'] = (array) ($analysis['extra_details'] ?? array());
            $result['completed'] = (string) $attempt['status'] === 'passed';
        }
        $challenge = \BSP\DiscoveryCamera\Content\PhotoChallengeMeta::forStep((int) ($attempt['step_id'] ?? 0));
        if ((string) ($challenge['interaction_type'] ?? '') === 'boss') {
            $result['boss_progress'] = (new BossProgressService())->get($userId, (int) $attempt['step_id'], $ticketId);
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    public function recentForAdmin(int $limit = 50): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT a.id,a.attempt_uuid,a.user_id,a.tour_id,a.step_id,a.status,a.private_object_key,a.created_at,a.updated_at,"
                . "n.total_score,n.result_json FROM {$this->db->prefix}bsp_photo_attempts a "
                . "LEFT JOIN {$this->db->prefix}bsp_photo_analyses n ON n.attempt_id=a.id "
                . "ORDER BY a.id DESC LIMIT %d",
                max(1, min(100, $limit))
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    /** @return array<string,mixed>|WP_Error */
    public function manualReview(string $uuid, bool $approved, int $reviewerId)
    {
        $attempt = $this->db->get_row(
            $this->db->prepare(
                "SELECT id,attempt_uuid,user_id,ticket_id,tour_id,step_id,status FROM {$this->db->prefix}bsp_photo_attempts WHERE attempt_uuid=%s",
                $uuid
            ),
            ARRAY_A
        );
        if (! is_array($attempt)) {
            return new WP_Error('photo_attempt_not_found', 'Fotopoging niet gevonden.', array('status' => 404));
        }
        if ((string) $attempt['status'] === 'passed' && $approved) {
            return array('status' => 'passed', 'replayed' => true);
        }

        $status = $approved ? 'passed' : 'failed';
        if (! ExperienceState::canTransition((string) $attempt['status'], $status)) {
            return new WP_Error('photo_state_conflict', 'Deze beoordeling kan niet meer worden gewijzigd.', array('status' => 409));
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->update(
            $this->db->prefix . 'bsp_photo_attempts',
            array('status' => $status, 'updated_at' => $now),
            array('id' => (int) $attempt['id']),
            array('%s', '%s'),
            array('%d')
        );

        $analysis = array(
            'provider' => 'human',
            'model' => 'admin-review-v1',
            'status' => $status,
            'total_score' => $approved ? 100 : 0,
            'passed' => $approved,
            'scores' => array(),
            'feedback' => array(
                'title' => $approved ? 'Handmatig goedgekeurd' : 'Nieuwe poging nodig',
                'message' => $approved ? 'Een beheerder heeft deze ontdekking goedgekeurd.' : 'Een beheerder heeft om een nieuwe foto gevraagd.',
                'coach_tip' => '',
            ),
            'reviewer_id' => $reviewerId,
        );
        $this->db->query($this->db->prepare(
            "INSERT INTO {$this->db->prefix}bsp_photo_analyses "
            . "(attempt_id,analysis_version,provider,model,status,total_score,result_json,created_at,completed_at) "
            . "VALUES (%d,(SELECT COALESCE(MAX(x.analysis_version),0)+1 FROM {$this->db->prefix}bsp_photo_analyses x WHERE x.attempt_id=%d),%s,%s,%s,%d,%s,%s,%s)",
            (int) $attempt['id'],
            (int) $attempt['id'],
            'human',
            'admin-review-v1',
            $status,
            $approved ? 100 : 0,
            wp_json_encode($analysis),
            $now,
            $now
        ));

        $rewards = array();
        if ($approved && (int) $attempt['user_id'] > 0) {
            $challenge = \BSP\DiscoveryCamera\Content\PhotoChallengeMeta::forStep((int) $attempt['step_id']);
            $rewards = (new PhotoChallengeCompletionService())->complete(
                (int) $attempt['user_id'],
                (int) $attempt['tour_id'],
                (int) $attempt['step_id'],
                $uuid,
                $challenge,
                $analysis
            );
        } elseif ($approved && (int) ($attempt['ticket_id'] ?? 0) > 0) {
            $challenge = \BSP\DiscoveryCamera\Content\PhotoChallengeMeta::forStep((int) $attempt['step_id']);
            $rewards = $this->completeTicket((int) $attempt['ticket_id'], (int) $attempt['step_id'], $challenge, $uuid);
        }
        return array('status' => $status, 'rewards' => $rewards, 'replayed' => false);
    }

    /** @param array<string,mixed> $challenge @return array<string,mixed> */
    private function completeTicket(int $ticketId, int $stepId, array $challenge, string $attemptUuid): array
    {
        $table = $this->db->prefix . 'sbdp_private_tour_tickets';
        $ticket = $this->db->get_row($this->db->prepare(
            "SELECT id,tour_id,progress FROM {$table} WHERE id=%d AND status IN ('active','preview')",
            $ticketId
        ), ARRAY_A);
        if (! is_array($ticket) || ! class_exists('\SBDP_Private_Tours_Tickets')) {
            return array('ticket_progress' => array(), 'xp' => array('created' => false));
        }
        $moduleId = class_exists('\BSP\ExperienceBuilder\Service\ModuleCompletionService')
            ? \BSP\ExperienceBuilder\Service\ModuleCompletionService::firstModuleIdForType($stepId, 'ai_photo_challenge')
            : '';
        if ($moduleId !== '') {
            $completion = (new \BSP\ExperienceBuilder\Service\ModuleCompletionService($this->db))->complete(
                (int) ($ticket['tour_id'] ?? 0),
                $stepId,
                $moduleId,
                0,
                $ticketId,
                array('event' => 'photo_approved', 'attempt_uuid' => $attemptUuid)
            );
            if (! is_wp_error($completion)) {
                return array(
                    'ticket_progress' => (array) ($completion['progress'] ?? array()),
                    'module_completion' => $completion,
                    'xp' => array('created' => false, 'pending_claim' => true, 'amount' => (int) ($challenge['xp_reward'] ?? 0)),
                    'collectibles' => array(),
                    'next_unlock' => sanitize_key((string) ($challenge['next_unlock'] ?? 'next_chapter')),
                );
            }
        }

        $progress = \SBDP_Private_Tours_Tickets::decode_progress($ticket['progress'] ?? null);
        $progress[$stepId] = array(
            'completed' => true,
            'updatedAt' => current_time('mysql', true),
            'payload' => array(
                'source' => 'photo_challenge',
                'pending_xp' => (int) ($challenge['xp_reward'] ?? 0),
                'pending_badge' => sanitize_key((string) ($challenge['badge_reward'] ?? '')),
            ),
        );
        \SBDP_Private_Tours_Tickets::store_progress($ticketId, $progress);

        return array(
            'ticket_progress' => $progress[$stepId],
            'xp' => array('created' => false, 'pending_claim' => true, 'amount' => (int) ($challenge['xp_reward'] ?? 0)),
            'collectibles' => array(),
            'next_unlock' => sanitize_key((string) ($challenge['next_unlock'] ?? 'next_chapter')),
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
            'provider_mode' => FeatureFlags::providerMode(),
        );
    }

    /** @return float|null */
    private function decimalScore($score): ?float
    {
        return is_numeric($score) ? min(1, max(0, ((float) $score) / 100)) : null;
    }
}
