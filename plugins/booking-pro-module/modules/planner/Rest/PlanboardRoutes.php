<?php

declare(strict_types=1);

namespace SBDP\Modules\Planner\Rest;

use BSP\Bookings\Service\BookingManager;
use BSP\Planner\Services\Planboard\PlanboardBookingService;
use BSP\Planner\Services\Planboard\PlanboardRulesService;
use BSP\Planner\Services\Planboard\PlanboardSnapshotService;
use BSP\Planner\Services\Planboard\PlanboardProductService;
use BSP\Planner\Services\Planboard\PlanboardCache;
use BSP\Planner\Services\Planboard\PlanboardFeature;
use BSP\Planner\Rest\Planboard\PlanboardBookingController;
use BSP\Planner\Rest\Planboard\PlanboardOverviewController;
use BSP\Planner\Rest\Planboard\PlanboardRulesController;
use BSP\Planner\Rest\Planboard\PlanboardSnapshotController;
use BSP\Planner\Rest\Planboard\PlanboardProductsController;
use BSP\Planner\Rest\Planboard\PlanboardPricingController;

final class PlanboardRoutes
{
    private PlanboardSnapshotController $snapshotController;
    private PlanboardBookingController $bookingController;
    private PlanboardRulesController $rulesController;
    private PlanboardOverviewController $overviewController;
    private PlanboardProductsController $productsController;
    private PlanboardPricingController $pricingController;

    public function __construct(?\BSP\Planner\Module $plannerModule = null)
    {
        $rulesService = new PlanboardRulesService();
        $manager = BookingManager::createDefault(null, $plannerModule);

        $this->snapshotController = new PlanboardSnapshotController(
            new PlanboardSnapshotService($manager, $rulesService)
        );
        $this->bookingController = new PlanboardBookingController(
            new PlanboardBookingService($manager, $rulesService)
        );
        $this->rulesController = new PlanboardRulesController($rulesService);
        $this->overviewController = new PlanboardOverviewController();
        $this->productsController = new PlanboardProductsController(new PlanboardProductService());
        $this->pricingController = new PlanboardPricingController();
    }

    public function register(): void
    {
        if (! PlanboardFeature::isEnabled()) {
            return;
        }

        PlanboardCache::registerHooks();

        $this->snapshotController->register_routes();
        $this->overviewController->register_routes();
        $this->bookingController->register_routes();
        $this->rulesController->register_routes();
        $this->productsController->register_routes();
        $this->pricingController->register_routes();
    }
}
