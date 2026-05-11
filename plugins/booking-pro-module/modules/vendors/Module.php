<?php

declare(strict_types=1);

namespace BSP\Vendors;

use BSP\Core\ModuleInterface;
use BSP\Sales\Module as SalesModule;
use BSP\Sales\Vendors\VendorRestController;
use BSP\Sales\Vendors\VendorScheduleRestController;
use BSP\Sales\Vendors\VendorService;

if (class_exists(__NAMESPACE__ . '\Module', false)) {
    return;
}


final class Module implements ModuleInterface
{
    private static bool $booted = false;

    public function init(): void
    {
        if (self::$booted) {
            return;
        }

        $this->bootSales();
        $this->bootVendorEndpoints();

        self::$booted = true;
    }

    private function bootSales(): void
    {
        if (! class_exists(SalesModule::class)) {
            return;
        }

        (new SalesModule())->init();
    }

    private function bootVendorEndpoints(): void
    {
        if (class_exists(VendorService::class)) {
            VendorService::init();
        }

        if (class_exists(VendorRestController::class)) {
            VendorRestController::init();
        }

        if (class_exists(VendorScheduleRestController::class)) {
            VendorScheduleRestController::init();
        }
    }
}
