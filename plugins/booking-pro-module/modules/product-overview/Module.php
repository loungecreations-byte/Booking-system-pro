<?php

declare(strict_types=1);

namespace SBDP\Modules\ProductOverview;

use SBDP\BookingEngine;
use SBDP\Contracts\ModuleInterface;

$componentClass = __NAMESPACE__ . '\\ProductOverviewComponent';
$componentFile  = __DIR__ . '/product-overview.class.php';

if (! class_exists($componentClass, false) && is_readable($componentFile)) {
    require_once $componentFile;
}

final class Module implements ModuleInterface
{
    private ProductOverviewComponent $component;

    public function __construct(?ProductOverviewComponent $component = null)
    {
        $this->component = $component ?? new ProductOverviewComponent();
    }

    public function register(BookingEngine $engine): void
    {
        unset($engine);

        $this->component->bootstrap();
    }
}
