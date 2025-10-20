<?php
declare(strict_types=1);

namespace BSPModule\Shared\Modules;

/**
 * Minimal module registry compatible with legacy BSP module loaders.
 */
final class ModuleRegistry
{
    /**
     * @var array<string, ModuleInterface>
     */
    private array $modules = [];

    public function add(ModuleInterface $module): void
    {
        $name = $module->moduleName();
        if ('' === $name || isset($this->modules[$name])) {
            return;
        }

        $this->modules[$name] = $module;
    }

    /**
     * Retrieve all registered modules.
     *
     * @return array<int, ModuleInterface>
     */
    public function all(): array
    {
        return array_values($this->modules);
    }

    /**
     * Execute the register hook for every stored module.
     */
    public function boot(): void
    {
        foreach ($this->modules as $module) {
            $module->register();
        }
    }
}
