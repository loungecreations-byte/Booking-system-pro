<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Domain;

final class ArrangementInstanceFactory
{
    public function createFromTemplate(array $template, array $overrides = array()): array
    {
        $base = array_merge($template, $overrides);
        $base['creation_mode'] = isset($overrides['creation_mode']) ? (string) $overrides['creation_mode'] : 'dynamic';
        $base['template_id'] = (int) ($template['id'] ?? 0);
        if (! isset($base['status'])) {
            $base['status'] = 'draft';
        }

        return $base;
    }

    public function customize(array $arrangement, array $overrides = array()): array
    {
        $base = array_merge($arrangement, $overrides);
        $base['creation_mode'] = 'customized';

        return $base;
    }
}
