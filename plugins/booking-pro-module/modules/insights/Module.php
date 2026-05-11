<?php

declare(strict_types=1);

namespace BSP\Insights;

use BSP\Core\ModuleInterface;
use BSP\Intelligence\Module as IntelligenceModule;

if (! class_exists(__NAMESPACE__ . '\\Module', false)) {
    final class Module implements ModuleInterface
    {
        private static bool $booted = false;

        public function init(): void
        {
            if (self::$booted) {
                return;
            }

            $this->bootIntelligence();

            self::$booted = true;
        }

        private function bootIntelligence(): void
        {
            if (! class_exists(IntelligenceModule::class)) {
                return;
            }

            (new IntelligenceModule())->init();
        }
    }
}
