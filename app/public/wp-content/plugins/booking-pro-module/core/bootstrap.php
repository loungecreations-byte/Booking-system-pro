<?php

declare(strict_types=1);

namespace BSP\Core;

require_once __DIR__ . '/../vendor/autoload.php';

use BSP\Core\Modules;

/**
 * Bootstrapper that registers all bundled modules with the module registry.
 */
final class Bootstrap
{
    /**
     * Register every module and invoke their init routines.
     */
    public static function init(): void
    {
        Modules::register('commerce', \BSP\Commerce\Module::class);
        Modules::register('planner', \BSP\Planner\Module::class);
        Modules::register('sales', \BSP\Sales\Module::class);
        Modules::register('intelligence', \BSP\Intelligence\Module::class);
        Modules::register('bookings', \BSP\Bookings\Module::class);
        Modules::register('vendor_portal', \BSP\VendorPortal\Module::class);

        Modules::loadAll();
    }
}

Bootstrap::init();
