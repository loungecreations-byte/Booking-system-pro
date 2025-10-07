<?php

declare(strict_types=1);

namespace BSP\Tests\Unit\Shared;

use BSPModule\Shared\Modules\ModuleInterface;
use BSPModule\Shared\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryTest extends TestCase
{
    public function testAddStoresModuleOnce(): void
    {
        $registry = new ModuleRegistry();

        $module = new class() implements ModuleInterface {
            public bool $registered = false;

            public function moduleName(): string
            {
                return 'stub-module';
            }

            public function register(): void
            {
                $this->registered = true;
            }
        };

        $registry->add($module);
        $registry->add($module);

        $this->assertCount(1, $registry->all());

        $registry->boot();
        $this->assertTrue($module->registered, 'Module::register was not called during boot');
    }

    public function testDuplicateModuleNamesAreIgnored(): void
    {
        $registry = new ModuleRegistry();

        $first = new class() implements ModuleInterface {
            public function moduleName(): string
            {
                return 'duplicate';
            }

            public function register(): void
            {
            }
        };

        $second = new class() implements ModuleInterface {
            public function moduleName(): string
            {
                return 'duplicate';
            }

            public function register(): void
            {
            }
        };

        $registry->add($first);
        $registry->add($second);

        $this->assertSame([$first], $registry->all());
    }
}