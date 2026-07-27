<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\Contract;

interface ChapterModuleRepositoryInterface
{
    /** @return mixed */
    public function get(int $chapterId);

    /** @param array<string,mixed> $document */
    public function update(int $chapterId, array $document): bool;

    public function postType(int $chapterId): string;
}
