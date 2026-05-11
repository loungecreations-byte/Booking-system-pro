<?php

declare(strict_types=1);

namespace SBDP;

use BSP\Core\Interfaces\ModuleInterface as CoreModuleInterface;
use BSPModule\Shared\Modules\ModuleInterface as SharedModuleInterface;
use SBDP\Contracts\ModuleInterface as EngineModuleInterface;

final class BookingEngine
{
    /**
     * @var array<int, object>
     */
    private array $modules;

    private BookingDispatcher $dispatcher;

    /**
     * @param array<int, object> $modules
     */
    public function __construct(array $modules = array())
    {
        $this->modules = array_values(
            array_filter(
                $modules,
                static fn ($module): bool => is_object($module)
            )
        );

        $this->dispatcher = new BookingDispatcher();
    }

    public function bootstrap(): void
    {
        foreach ($this->modules as $module) {
            $this->bootstrapModule($module);
        }
    }

    /**
     * @return array<int, object>
     */
    public function getModules(): array
    {
        return $this->modules;
    }

    public function getDispatcher(): BookingDispatcher
    {
        return $this->dispatcher;
    }

    /**
     * Basic availability check hook.
     *
     * @param mixed ...$args
     */
    public function checkAvailability(...$args): bool
    {
        $payload = $this->normalizeAvailabilityPayload($args);
        $result  = $this->dispatcher->dispatch('planner.check_availability', $payload);

        if (is_array($result) && isset($result['available'])) {
            return (bool) $result['available'];
        }

        if (is_bool($result)) {
            return $result;
        }

        return true;
    }

    private function bootstrapModule(object $module): void
    {
        try {
            if ($module instanceof EngineModuleInterface) {
                $module->register($this);

                return;
            }

            if ($module instanceof CoreModuleInterface) {
                $module->init();

                return;
            }

            if ($module instanceof SharedModuleInterface) {
                $module->register();

                return;
            }

            if (method_exists($module, 'register')) {
                $module->register($this);

                return;
            }

            if (method_exists($module, 'init')) {
                $module->init();
            }
        } catch (\Throwable $exception) {
            if (function_exists('error_log')) {
                error_log('[SBDP][engine] module bootstrap failed: ' . $exception->getMessage());
            }
        }
    }

    private function normalizeAvailabilityPayload(array $args): array
    {
        if (isset($args[0]) && is_array($args[0])) {
            return $args[0];
        }

        return array(
            'product_id'   => isset($args[0]) ? (int) $args[0] : 0,
            'date'         => $args[1] ?? null,
            'start'        => $args[2] ?? null,
            'end'          => $args[3] ?? null,
            'participants' => isset($args[4]) ? max(1, (int) $args[4]) : 1,
        );
    }
}
