<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\Service;

use BSP\ExperienceBuilder\Contract\ChapterModuleRepositoryInterface;
use BSP\ExperienceBuilder\Repository\WordPressChapterModuleRepository;
use WP_Error;

final class ModuleDocumentService
{
    public const META_KEY = '_sbdp_chapter_modules_v1';

    private ModuleValidationService $validator;
    private ChapterModuleRepositoryInterface $repository;

    public function __construct(
        ModuleValidationService $validator,
        ?ChapterModuleRepositoryInterface $repository = null
    )
    {
        $this->validator = $validator;
        $this->repository = $repository ?? new WordPressChapterModuleRepository();
    }

    /** @return array<string,mixed> */
    public function get(int $chapterId): array
    {
        $stored = $this->repository->get($chapterId);
        if (! is_array($stored) || $stored === array()) {
            return $this->validator->emptyDocument();
        }

        return $this->validator->normalize($stored)['document'];
    }

    /**
     * @param mixed $document
     * @return array<string,mixed>|WP_Error
     */
    public function save(int $chapterId, $document, int $expectedRevision = 0)
    {
        if ($this->repository->postType($chapterId) !== 'sbdp_tour_step') {
            return new WP_Error('invalid_chapter', 'Het hoofdstuk bestaat niet.', array('status' => 404));
        }
        if (! current_user_can('edit_post', $chapterId)) {
            return new WP_Error('chapter_modules_forbidden', 'Je mag dit hoofdstuk niet bewerken.', array('status' => 403));
        }

        $current = $this->get($chapterId);
        // Registered post meta can return its default empty array even when no
        // row has ever been stored. Treat only a non-empty array as an existing
        // revision, matching the REST read boundary.
        $stored = $this->repository->get($chapterId);
        $hasStoredDocument = is_array($stored) && $stored !== array();
        $currentRevision = $hasStoredDocument ? max(1, absint($current['revision'] ?? 1)) : 0;
        if ($expectedRevision !== $currentRevision) {
            return new WP_Error(
                'chapter_modules_conflict',
                'Het hoofdstuk is intussen gewijzigd. Vernieuw de editor en probeer opnieuw.',
                array('status' => 409, 'current_revision' => $currentRevision)
            );
        }

        $result = $this->validator->normalize($document);
        if ($result['errors'] !== array()) {
            return new WP_Error(
                'invalid_chapter_modules',
                'Het module-document bevat validatiefouten.',
                array('status' => 422, 'errors' => $result['errors'], 'warnings' => $result['warnings'])
            );
        }

        $normalized = $result['document'];
        $adapterErrors = $this->validateChapterAdapters($chapterId, $normalized);
        if ($adapterErrors !== array()) {
            return new WP_Error(
                'invalid_chapter_modules',
                'Een gekoppelde module is nog niet volledig ingesteld.',
                array('status' => 422, 'errors' => $adapterErrors, 'warnings' => $result['warnings'])
            );
        }
        $normalized['document_id'] = 'chapter-' . $chapterId . '-modules';
        $normalized['revision'] = $currentRevision + 1;
        if (! $this->repository->update($chapterId, $normalized)) {
            return new WP_Error('chapter_modules_save_failed', 'Het module-document kon niet worden opgeslagen.', array('status' => 500));
        }

        return array('document' => $normalized, 'warnings' => $result['warnings']);
    }

    /**
     * Validate configuration owned by an existing chapter-level adapter.
     *
     * @param array<string,mixed> $document
     * @return array<int,array<string,string>>
     */
    private function validateChapterAdapters(int $chapterId, array $document): array
    {
        if (
            ! class_exists('\BSP\DiscoveryCamera\Content\PhotoChallengeMeta')
            || ! class_exists('\BSP\DiscoveryCamera\Domain\PhotoChallenge')
        ) {
            return array();
        }

        $messages = array(
            'missing_title' => 'Vul eerst de titel van de camera-opdracht in.',
            'missing_mission' => 'Vul eerst de missie van de camera-opdracht in.',
            'missing_required_object' => 'Kies eerst wat de bezoeker moet fotograferen.',
            'missing_validation_type' => 'Kies minimaal één AI-validatietype.',
        );
        $challenge = \BSP\DiscoveryCamera\Content\PhotoChallengeMeta::forStep($chapterId);
        $validationErrors = \BSP\DiscoveryCamera\Domain\PhotoChallenge::validationErrors($challenge);
        if ($validationErrors === array()) {
            return array();
        }

        $errors = array();
        foreach ((array) ($document['modules'] ?? array()) as $index => $module) {
            if (
                ! is_array($module)
                || empty($module['enabled'])
                || (string) ($module['type'] ?? '') !== 'ai_photo_challenge'
            ) {
                continue;
            }
            foreach ($validationErrors as $code) {
                $errors[] = array(
                    'path' => 'modules.' . $index . '.content',
                    'code' => (string) $code,
                    'message' => $messages[$code] ?? 'Maak de camera-opdracht eerst compleet.',
                );
            }
        }

        return $errors;
    }

    /** @param mixed $value @return array<string,mixed> */
    public function sanitizeForMeta($value): array
    {
        return $this->validator->normalize($value)['document'];
    }
}
