<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera;

use BSP\Core\Interfaces\ModuleInterface;
use BSP\DiscoveryCamera\Content\PhotoChallengeMeta;
use BSP\DiscoveryCamera\Privacy\PrivacyService;
use BSP\DiscoveryCamera\Rest\Controller;
use BSP\DiscoveryCamera\Support\Installer;

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
        add_action('init', array(PhotoChallengeMeta::class, 'register'));
        add_action('rest_api_init', array(Controller::class, 'register'));
        PrivacyService::register();
    }
}
