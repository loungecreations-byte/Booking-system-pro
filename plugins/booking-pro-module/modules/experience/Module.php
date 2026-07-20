<?php
declare(strict_types=1);

namespace BSP\Experience;

use BSP\Core\Interfaces\ModuleInterface;
use BSP\Experience\Account\AccountPage;
use BSP\Experience\Privacy\PrivacyService;
use BSP\Experience\Rest\Controller;
use BSP\Experience\Support\Installer;

final class Module implements ModuleInterface
{
    private static bool $booted = false;

    public function init(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('init', array(Installer::class, 'maybeInstall'), 5);
        add_action('rest_api_init', array(Controller::class, 'register'));
        AccountPage::register();
        PrivacyService::register();
    }
}
