<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\Service;

use BSP\ExperienceBuilder\Module;
use WP_Error;

final class LegacyMigrationService
{
    public const BACKUP_KEY = '_sbdp_chapter_modules_legacy_backup_v1';

    /** @return array<string,mixed>|WP_Error */
    public function dryRun(int $chapterId)
    {
        if (get_post_type($chapterId) !== 'sbdp_tour_step') {
            return new WP_Error('invalid_chapter', 'Het hoofdstuk bestaat niet.', array('status' => 404));
        }
        $stored = get_post_meta($chapterId, ModuleDocumentService::META_KEY, true);
        $backup = get_post_meta($chapterId, self::BACKUP_KEY, true);
        $snapshot = $this->legacySnapshot($chapterId);
        $candidate = (new LegacyChapterAdapter())->virtualDocument($chapterId);
        unset($candidate['virtual']);
        $candidate['revision'] = 1;
        foreach ((array) ($candidate['modules'] ?? array()) as $index => $module) {
            if (is_array($module)) {
                $candidate['modules'][$index]['metadata'] = array(
                    'source' => 'legacy_migration',
                    'legacy_read_only' => false,
                );
            }
        }
        $validation = (new ModuleValidationService(Module::registry()))->normalize($candidate);
        $hasStored = is_array($stored) && $stored !== array();
        $canRollback = $hasStored
            && is_array($backup)
            && hash_equals((string) ($backup['migrated_document_checksum'] ?? ''), $this->checksum($stored));

        return array(
            'status' => $hasStored ? 'already_modular' : 'ready',
            'chapter_id' => $chapterId,
            'legacy_source_checksum' => $this->checksum($snapshot),
            'candidate' => $validation['document'],
            'module_summary' => array_map(static fn (array $module): array => array(
                'type' => (string) ($module['type'] ?? ''),
                'title' => (string) ($module['title'] ?? ''),
            ), (array) ($validation['document']['modules'] ?? array())),
            'errors' => $validation['errors'],
            'warnings' => $validation['warnings'],
            'legacy_preserved' => true,
            'can_migrate' => ! $hasStored
                && $validation['errors'] === array()
                && (array) ($validation['document']['modules'] ?? array()) !== array(),
            'can_rollback' => $canRollback,
            'confirmation' => $this->confirmation($chapterId, $snapshot, $stored),
        );
    }

    /** @return array<string,mixed>|WP_Error */
    public function migrate(int $chapterId, string $confirmation)
    {
        if (! current_user_can('edit_post', $chapterId)) {
            return new WP_Error('chapter_migration_forbidden', 'Je mag dit hoofdstuk niet migreren.', array('status' => 403));
        }
        $dryRun = $this->dryRun($chapterId);
        if ($dryRun instanceof WP_Error) {
            return $dryRun;
        }
        if (empty($dryRun['can_migrate']) || ! hash_equals((string) $dryRun['confirmation'], $confirmation)) {
            return new WP_Error('chapter_migration_conflict', 'De legacybron is gewijzigd. Voer de dry-run opnieuw uit.', array('status' => 409));
        }
        $snapshot = $this->legacySnapshot($chapterId);
        $service = new ModuleDocumentService(new ModuleValidationService(Module::registry()));
        $saved = $service->save($chapterId, $dryRun['candidate'], 0);
        if ($saved instanceof WP_Error) {
            return $saved;
        }
        $backup = array(
            'schema_version' => 1,
            'status' => 'migrated',
            'created_at' => gmdate('c'),
            'actor_user_id' => get_current_user_id(),
            'legacy_source_checksum' => $this->checksum($snapshot),
            'legacy_snapshot' => $snapshot,
            'migrated_document_checksum' => $this->checksum($saved['document']),
        );
        if (update_post_meta($chapterId, self::BACKUP_KEY, $backup) === false) {
            delete_post_meta($chapterId, ModuleDocumentService::META_KEY);
            return new WP_Error('chapter_migration_backup_failed', 'De rollback-backup kon niet worden opgeslagen.', array('status' => 500));
        }

        return array('migrated' => true, 'document' => $saved['document'], 'backup' => $backup);
    }

    /** @return array<string,mixed>|WP_Error */
    public function rollback(int $chapterId, string $confirmation)
    {
        if (! current_user_can('edit_post', $chapterId)) {
            return new WP_Error('chapter_migration_forbidden', 'Je mag dit hoofdstuk niet terugzetten.', array('status' => 403));
        }
        $dryRun = $this->dryRun($chapterId);
        if ($dryRun instanceof WP_Error) {
            return $dryRun;
        }
        if (empty($dryRun['can_rollback']) || ! hash_equals((string) $dryRun['confirmation'], $confirmation)) {
            return new WP_Error('chapter_rollback_conflict', 'Het module-document is na migratie gewijzigd en kan niet automatisch worden teruggezet.', array('status' => 409));
        }
        if (! delete_post_meta($chapterId, ModuleDocumentService::META_KEY)) {
            return new WP_Error('chapter_rollback_failed', 'Het gemigreerde module-document kon niet worden verwijderd.', array('status' => 500));
        }
        $backup = get_post_meta($chapterId, self::BACKUP_KEY, true);
        if (is_array($backup)) {
            $backup['status'] = 'rolled_back';
            $backup['rolled_back_at'] = gmdate('c');
            $backup['rolled_back_by'] = get_current_user_id();
            update_post_meta($chapterId, self::BACKUP_KEY, $backup);
        }

        return array('rolled_back' => true, 'legacy_preserved' => true);
    }

    /** @return array<string,mixed> */
    private function legacySnapshot(int $chapterId): array
    {
        $post = get_post($chapterId);
        $metaKeys = array(
            '_sbdp_step_image_url',
            '_sbdp_step_audio_url',
            '_sbdp_step_video_url',
            '_sbdp_step_quiz',
            '_sbdp_photo_challenge_v1',
            '_sbdp_step_type',
        );
        $meta = array();
        foreach ($metaKeys as $key) {
            $meta[$key] = get_post_meta($chapterId, $key, true);
        }

        return array(
            'post_content' => $post ? (string) $post->post_content : '',
            'meta' => $meta,
        );
    }

    /** @param mixed $value */
    private function checksum($value): string
    {
        return hash('sha256', (string) wp_json_encode($value));
    }

    /** @param mixed $stored */
    private function confirmation(int $chapterId, array $snapshot, $stored): string
    {
        return hash_hmac(
            'sha256',
            $chapterId . '|' . $this->checksum($snapshot) . '|' . $this->checksum($stored),
            wp_salt('nonce')
        );
    }
}
