<?php

declare(strict_types=1);

namespace SBDP\Legacy;

if (! class_exists(SalesLegacyService::class, false)) {
    final class SalesLegacyService
    {
        public static function init(): void
        {
            // Legacy bridge placeholder to preserve backwards compatibility.
        }
    }
}

if (! class_exists('\BSPModule\Sales_Legacy_Service', false)) {
    class_alias(SalesLegacyService::class, 'BSPModule\Sales_Legacy_Service');
}
