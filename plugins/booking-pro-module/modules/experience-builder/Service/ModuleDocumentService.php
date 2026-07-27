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
        $hasStoredDocument = is_array($this->repository->get($chapterId));
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
        $normalized['document_id'] = 'chapter-' . $chapterId . '-modules';
        $normalized['revision'] = $currentRevision + 1;
        if (! $this->repository->update($chapterId, $normalized)) {
            return new WP_Error('chapter_modules_save_failed', 'Het module-document kon niet worden opgeslagen.', array('status' => 500));
        }

        return array('document' => $normalized, 'warnings' => $result['warnings']);
    }

    /** @param mixed $value @return array<string,mixed> */
    public function sanitizeForMeta($value): array
    {
        return $this->validator->normalize($value)['document'];
    }
}
