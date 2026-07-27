<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\Repository;

use BSP\ExperienceBuilder\Contract\ChapterModuleRepositoryInterface;
use BSP\ExperienceBuilder\Service\ModuleDocumentService;

final class WordPressChapterModuleRepository implements ChapterModuleRepositoryInterface
{
    public function get(int $chapterId)
    {
        return get_post_meta($chapterId, ModuleDocumentService::META_KEY, true);
    }

    public function update(int $chapterId, array $document): bool
    {
        $result = update_post_meta($chapterId, ModuleDocumentService::META_KEY, $document);

        return $result !== false || $this->get($chapterId) === $document;
    }

    public function postType(int $chapterId): string
    {
        return (string) get_post_type($chapterId);
    }
}
