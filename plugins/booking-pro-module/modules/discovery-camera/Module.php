<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera;

use BSP\DiscoveryCamera\Admin\PhotoChallengeMetaBox;
use BSP\DiscoveryCamera\Admin\SettingsPage;
use BSP\DiscoveryCamera\Assets\AssetService;
use BSP\Core\Interfaces\ModuleInterface;
use BSP\DiscoveryCamera\Content\PhotoChallengeMeta;
use BSP\DiscoveryCamera\Privacy\PrivacyService;
use BSP\DiscoveryCamera\Rest\Controller;
use BSP\DiscoveryCamera\Rest\CommunityController;
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
        add_action('rest_api_init', array(CommunityController::class, 'register'));
        add_action('admin_post_ddb_community_photo', array(CommunityController::class, 'serveImage'));
        add_action('admin_post_nopriv_ddb_community_photo', array(CommunityController::class, 'serveImage'));
        PhotoChallengeMetaBox::register();
        SettingsPage::register();
        AssetService::register();
        PrivacyService::register();
    }
}
