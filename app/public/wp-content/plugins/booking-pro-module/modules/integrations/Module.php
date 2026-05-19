<?php

declare(strict_types=1);

namespace BSP\Integrations;

use BSP\Core\Interfaces\ModuleInterface;
use BSP\Integrations\Rest\EliioAvailabilityController;

final class Module implements ModuleInterface
{
    public function init(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('rest_api_init', static function (): void {
            (new EliioAvailabilityController())->registerRoutes();
        });
    }
}
