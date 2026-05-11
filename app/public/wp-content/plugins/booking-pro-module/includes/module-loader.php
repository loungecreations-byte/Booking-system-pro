<?php

declare(strict_types=1);

namespace SBDP\Loader;

use DirectoryIterator;
use Throwable;

/**
 * Load module classes from the modules directory.
 *
 * @return array<int, object>
 */
function load_modules(string $modulesDir): array
{
    if (! is_dir($modulesDir)) {
        return array();
    }

    $instances = array();

    foreach (new DirectoryIterator($modulesDir) as $entry) {
        if (! $entry->isDir() || $entry->isDot()) {
            continue;
        }

        $moduleFile = $entry->getPathname() . DIRECTORY_SEPARATOR . 'Module.php';
        if (! is_readable($moduleFile)) {
            continue;
        }

        require_once $moduleFile;

        $namespaceSegment = toStudlyCase($entry->getFilename());
        $candidates = array(
            sprintf('SBDP\\Modules\\%s\\Module', $namespaceSegment),
            sprintf('BSPModule\\%s\\Module', $namespaceSegment),
            sprintf('BSP\\%s\\Module', $namespaceSegment),
        );

        foreach ($candidates as $className) {
            if (! class_exists($className)) {
                continue;
            }

            try {
                $instances[] = new $className();
            } catch (Throwable $exception) {
                if (function_exists('error_log')) {
                    error_log('[SBDP][module-loader] ' . $exception->getMessage());
                }
            }

            break;
        }
    }

    return array_filter(
        $instances,
        static fn ($module): bool => is_object($module)
    );
}

function toStudlyCase(string $value): string
{
    $value = str_replace(array('-', '_'), ' ', strtolower($value));

    return str_replace(' ', '', ucwords($value));
}
