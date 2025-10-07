<?php

declare(strict_types=1);

namespace BSP\Sales;

use BSP\Sales\Admin\Pages;
use BSP\Sales\CLI\Commands;
use BSP\Sales\Channels\ChannelManager;
use BSP\Sales\Channels\RestController as ChannelRestController;
use BSP\Sales\Funnels\FunnelTracker;
use BSP\Sales\Pricing\PricingRestController;
use BSP\Sales\Pricing\YieldEngine;
use BSP\Sales\Promotions\PromotionsRestController;
use BSP\Sales\Promotions\PromotionsService;
use BSP\Sales\ReviewManager;
use BSP\Sales\Support\Installer;
use BSP\Sales\Support\Scheduler;
use BSP\Sales\Vendors\VendorRepository;
use BSP\Sales\Vendors\VendorRestController;
use BSP\Sales\Vendors\VendorScheduleRestController;
use BSP\Sales\Vendors\VendorService;
use BSP\Sales\Vendors\VendorValidator;
use BSPModule\Shared\Modules\ModuleInterface;

use function class_exists;
use function defined;
use function dirname;
use function do_action;
use function function_exists;
use function is_readable;
use function register_activation_hook;
use function register_deactivation_hook;
use function register_uninstall_hook;
use function esc_html;
use function sprintf;

final class Module implements ModuleInterface {

	private static bool $hooksRegistered = false;

	/**
	 * Retrieve the module machine name.
	 * Haal de technische modulenaam op.
	 */
	public function moduleName(): string {
		return 'booking-sales';
	}

	/**
	 * Bootstrap the sales module within the shared registry.
	 * Start de verkoopmodule binnen het gedeelde register.
	 */
	public function register(): void {
		$this->ensureDependencies();
		self::registerLifecycleHooks();

		$this->maybeCallStatic( YieldEngine::class, 'init' );
		$this->maybeCallStatic( PricingRestController::class, 'init' );
		$this->maybeCallStatic( PromotionsService::class, 'init' );
		$this->maybeCallStatic( FunnelTracker::class, 'init' );
		$this->maybeCallStatic( PromotionsRestController::class, 'init' );
		$this->maybeCallStatic( ChannelManager::class, 'init' );
		$this->maybeCallStatic( ChannelRestController::class, 'init' );
		$this->maybeCallStatic( VendorService::class, 'init' );
		$this->maybeCallStatic( VendorRestController::class, 'init' );
		$this->maybeCallStatic( ReviewManager::class, 'init' );
		$this->maybeCallStatic( Scheduler::class, 'bootstrap' );
		$this->maybeCallStatic( Commands::class, 'register' );
		$this->maybeCallStatic( Pages::class, 'init' );

		if ( function_exists( 'do_action' ) ) {
			do_action( 'bsp/module/register', $this->moduleName(), $this );
		}
	}

	/**
	 * Make sure required class files are loaded before bootstrapping.
	 * Zorg dat vereiste klasses beschikbaar zijn voordat we starten.
	 */
	private function ensureDependencies(): void {
		$baseDir = defined( 'SBDP_DIR' ) ? rtrim( SBDP_DIR, '/\\' ) . '/' : dirname( __DIR__, 1 ) . '/';

		$classMap = array(
			YieldEngine::class              => 'booking-sales/src/Pricing/YieldEngine.php',
			PricingRestController::class    => 'booking-sales/src/Pricing/PricingRestController.php',
			ChannelManager::class           => 'booking-sales/src/Channels/ChannelManager.php',
			ChannelRestController::class    => 'booking-sales/src/Channels/RestController.php',
			VendorService::class            => 'booking-sales/src/Vendors/VendorService.php',
			VendorRestController::class     => 'booking-sales/src/Vendors/VendorRestController.php',
			VendorRepository::class         => 'booking-sales/src/Vendors/VendorRepository.php',
			VendorValidator::class          => 'booking-sales/src/Vendors/VendorValidator.php',
			FunnelTracker::class            => 'booking-sales/src/Funnels/FunnelTracker.php',
			PromotionsService::class        => 'booking-sales/src/Promotions/PromotionsService.php',
			PromotionsRestController::class => 'booking-sales/src/Promotions/PromotionsRestController.php',
			Pages::class                    => 'booking-sales/src/Admin/Pages.php',
			Scheduler::class                => 'booking-sales/src/Support/Scheduler.php',
			Installer::class                => 'booking-sales/src/Support/Installer.php',
			Commands::class                 => 'booking-sales/src/CLI/Commands.php',
			ReviewManager::class            => 'booking-sales/src/ReviewManager.php',
		);

		foreach ( $classMap as $class => $relativePath ) {
			if ( class_exists( $class ) ) {
				continue;
			}

			$path = $baseDir . $relativePath;
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Register activation, deactivation and uninstall hooks once.
	 * Registreer eenmalig de activatie-, deactivatie- en verwijderhooks.
	 */
	private static function registerLifecycleHooks(): void {
		if ( self::$hooksRegistered || ! defined( 'SBDP_FILE' ) ) {
			return;
		}

		self::$hooksRegistered = true;

		register_activation_hook( SBDP_FILE, array( Installer::class, 'install' ) );
		register_deactivation_hook( SBDP_FILE, array( Scheduler::class, 'clearScheduledActions' ) );
		register_uninstall_hook( SBDP_FILE, array( Installer::class, 'uninstall' ) );
	}

	/**
	 * Call a static bootstrap method when the class and method exist.
	 * Roep een statische bootstrapmethode aan als klasse en methode bestaan.
	 */
	private function maybeCallStatic( string $class, string $method ): void {
		if ( ! class_exists( $class ) ) {
			$this->logMissing( $class );
			return;
		}

		if ( ! method_exists( $class, $method ) ) {
			$this->logMissing( $class . '::' . $method );
			return;
		}

		$class::$method();
	}

	/**
	 * Log and surface missing dependencies to administrators.
	 * Log en toon ontbrekende afhankelijkheden aan beheerders.
	 */
	private function logMissing( string $identifier ): void {
		if ( function_exists( 'error_log' ) ) {
			error_log( sprintf( '[SBDP] Missing dependency during Sales module bootstrap: %s', $identifier ) );
		}

		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		$renderNotice = static function () use ( $identifier ): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( sprintf( __( 'Booking Sales module dependency missing: %s', 'sbdp' ), $identifier ) )
			);
		};

		add_action( 'admin_notices', $renderNotice );
		add_action( 'network_admin_notices', $renderNotice );
	}
}

if ( ! class_exists( 'BSPModule\\Sales\\Module' ) ) {
	class_alias( Module::class, 'BSPModule\\Sales\\Module' );
}















