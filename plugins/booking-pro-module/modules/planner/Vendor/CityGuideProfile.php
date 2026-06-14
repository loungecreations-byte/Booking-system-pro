<?php

declare(strict_types=1);

namespace BSP\Planner\Vendor;

final class CityGuideProfile
{
    /**
     * @param array<int, string> $languages
     * @param array<int, string> $protectedLanguages
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $status = 'active',
        public string $timezone = 'Europe/Amsterdam',
        public bool $allowNlTours = false,
        public string $icalUrl = '',
        public string $note = '',
        public string $lastSync = '',
        public array $languages = array('nl'),
        public array $protectedLanguages = array()
    ) {
    }
}

