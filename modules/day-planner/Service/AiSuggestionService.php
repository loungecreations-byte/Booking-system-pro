<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

final class AiSuggestionService
{
    /**
     * @param array<string, mixed> $preferences
     *
     * @return array<string, mixed>
     */
    public function suggest(array $preferences): array
    {
        return [
            'summary' => __('AI suggestions are not yet implemented. Please refine manually.', 'sbdp'),
            'activities' => [],
            'meta' => [
                'preferences' => $preferences,
            ],
        ];
    }
}
