<?php

declare(strict_types=1);

namespace BSP\Planner\Services\Planboard;

final class PlanboardFeature
{
    public static function isEnabled(): bool
    {
        $enabled = defined('SBDP_PLANBOARD_V2') ? (bool) SBDP_PLANBOARD_V2 : false;

        if (function_exists('apply_filters')) {
            $enabled = (bool) apply_filters('bsp/planboard/v2_enabled', $enabled);
        }

        return $enabled;
    }
}
