<?php
declare(strict_types=1);
namespace BSP\Gamification;
use BSP\Core\Interfaces\ModuleInterface;
use BSP\Gamification\Account\AccountPage;
use BSP\Gamification\Admin\AdminPage;
use BSP\Gamification\Events\EventSubscriber;
use BSP\Gamification\Privacy\PrivacyService;
use BSP\Gamification\Rest\Controller;
use BSP\Gamification\Rest\CollectiblesController;
use BSP\Gamification\Rest\TourCompletionController;
use BSP\Gamification\Admin\CollectiblesAdminPage;
use BSP\Gamification\Support\Installer;

final class Module implements ModuleInterface
{
    private static bool $booted=false;
    public function init(): void
    {
        if (self::$booted) { return; } self::$booted=true;
        add_action('init',array(Installer::class,'maybeInstall'),5);
        add_action('rest_api_init',array(Controller::class,'register'));
        add_action('rest_api_init',array(CollectiblesController::class,'register'));
        add_action('rest_api_init',array(TourCompletionController::class,'register'));
        AdminPage::register(); CollectiblesAdminPage::register(); AccountPage::register(); PrivacyService::register(); (new EventSubscriber())->register();
    }
}
