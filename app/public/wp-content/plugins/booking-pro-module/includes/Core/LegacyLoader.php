<?php

declare(strict_types=1);

namespace SBDP\Core;

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

if (!defined('ABSPATH')) {
    exit;
}

final class LegacyLoader
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized || \class_exists(\BSPModule\Core\Module::class)) {
            return;
        }

        self::$initialized = true;

        self::bootstrapCore();
        self::bootstrapAdmin();
        self::bootstrapRest();
    }

    private static function bootstrapCore(): void
    {
        self::loadAndInit('class-cpt.php', 'SBDP_CPT');
        self::loadAndInit('class-product-type.php', 'SBDP_Product_Type');
        self::requireIfExists('class-product-meta.php');
        self::requireIfExists('class-meta-display.php');
        self::loadAndInit('class-resource-meta.php', 'SBDP_Resource_Meta');
    }

    private static function bootstrapAdmin(): void
    {
        self::loadAndInit('class-admin-menu.php', 'SBDP_Admin_Menu');
        self::loadAndInit('class-admin-scheduler.php', 'SBDP_Admin_Scheduler');

        $bookableAdmin = self::resolvePath('includes/admin/class-sbdp-admin-bookable-meta.php');
        if ($bookableAdmin !== null) {
            require_once $bookableAdmin;
            if (\class_exists('\\SBDP\\Admin\\Bookable\\SBDP_Admin_Bookable_Meta')) {
                \SBDP\Admin\Bookable\SBDP_Admin_Bookable_Meta::init();
            }
        }

        $elementorBootstrap = self::resolvePath('includes/class-elementor.php');
        if ($elementorBootstrap !== null) {
            require_once $elementorBootstrap;
            if (\class_exists('SBDP_Elementor_Integration')) {
                \SBDP_Elementor_Integration::init();
            }
        }
    }

    private static function bootstrapRest(): void
    {
        self::loadAndInit('class-rest.php', 'SBDP_REST');
        self::loadAndInit('class-shortcodes.php', 'SBDP_Shortcodes');
        self::loadAndInit('class-enqueue.php', 'SBDP_Enqueue');
        self::loadAndInit('class-emails.php', 'SBDP_Emails');
    }

    private static function loadAndInit(string $relativePath, string $className): void
    {
        self::requireIfExists($relativePath);
        if (\class_exists($className)) {
            $className::init();
        }
    }

    private static function requireIfExists(string $relativePath): void
    {
        $path = self::resolvePath('includes/' . ltrim($relativePath, '/'));
        if ($path !== null) {
            require_once $path;
        }
    }

    private static function resolvePath(string $relativePath): ?string
    {
        $baseDir = \defined('SBDP_DIR') ? \constant('SBDP_DIR') : (__DIR__ . '/../');
        $absolute = $baseDir . ltrim($relativePath, '/');

        return \is_readable($absolute) ? $absolute : null;
    }
}

if (!\class_exists('SBDP_Legacy_Loader', false)) {
    \class_alias(LegacyLoader::class, 'SBDP_Legacy_Loader');
}

// phpcs:enable PSR1.Files.SideEffects.FoundWithSymbols
