<?php
declare(strict_types=1);

namespace BSP\Core;

use BSP\Core\Interfaces\ModuleInterface;

/**
 * Runtime registry used to manage Booking System Pro modules.
 */
final class Modules
{
    /**
     * @var array<string, class-string<ModuleInterface>>
     */
    private static array $registry = [];

    /**
     * Register a module class under the specified key.
     */
    public static function register(string $key, string $class): void
    {
        self::$registry[$key] = $class;
    }

    /**
     * Determine whether a module key has been registered.
     */
    public static function isRegistered(string $key): bool
    {
        return array_key_exists($key, self::$registry);
    }

    /**
     * Instantiate and boot the module stored under the given key.
     */
    public static function load(string $key): void
    {
        if (! self::isRegistered($key)) {
            return;
        }

        $class = self::$registry[$key];
        if (! class_exists($class)) {
            return;
        }

        $instance = new $class();
        if ($instance instanceof ModuleInterface) {
            $instance->init();
        }
    }

    /**
     * Load every registered module in registration order.
     */
    public static function loadAll(): void
    {
        foreach (array_keys(self::$registry) as $key) {
            self::load($key);
        }
    }
}
