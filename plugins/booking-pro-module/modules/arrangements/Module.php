<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements;

use BSP\Core\Interfaces\ModuleInterface;
use SBDP\Modules\Arrangements\Admin\Editor;
use SBDP\Modules\Arrangements\Admin\Menu;
use SBDP\Modules\Arrangements\PostType\ArrangementPostType;
use SBDP\Modules\Arrangements\Rest\Controller;

use function add_action;
use function class_exists;

final class Module implements ModuleInterface
{
    private static bool $booted = false;

    public function init(): void
    {
        if (self::$booted) {
            return;
        }

        add_action('init', array(ArrangementPostType::class, 'register'));

        (new Menu())->register();
        (new Editor())->register();
        add_action('rest_api_init', array(new Controller(), 'register'));

        self::$booted = true;
    }
}

if (! class_exists('BSPModule\\Arrangements\\Module', false)) {
    class_alias(Module::class, 'BSPModule\\Arrangements\\Module');
}
