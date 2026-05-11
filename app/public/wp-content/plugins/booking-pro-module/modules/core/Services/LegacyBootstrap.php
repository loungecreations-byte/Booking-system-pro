<?php

declare(strict_types=1);

namespace BSPModule\Core\Services;

final class LegacyBootstrap {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		self::requireFile( 'includes/class-product-meta.php' );
		self::initClass( 'includes/admin/class-sbdp-admin-bookable-meta.php', '\\SBDP\\Admin\\Bookable\\SBDP_Admin_Bookable_Meta' );
		self::initClass( 'includes/class-elementor.php', 'SBDP_Elementor_Integration' );

		// Booking Board v2 bootstrap (admin + REST) when legacy loader is bypassed.
		self::requireFile( 'includes/class-sbdp-booking-board-query.php' );
		self::requireFile( 'includes/class-sbdp-booking-board-metrics.php' );
		self::requireFile( 'includes/class-sbdp-booking-board-presets.php' );
		self::requireFile( 'includes/class-sbdp-booking-board-schema.php' );
		self::initClass( 'includes/class-sbdp-booking-board-hooks.php', 'SBDP_Booking_Board_Hooks' );
		self::initClass( 'includes/class-sbdp-booking-board-automation.php', 'SBDP_Booking_Board_Automation' );
		self::initClass( 'includes/class-sbdp-booking-board-page.php', 'SBDP_Booking_Board_Page' );
		self::initClass( 'includes/class-sbdp-booking-board-controller.php', 'SBDP_Booking_Board_Controller' );

		// All bookings overview bootstrap when the legacy loader is bypassed.
		self::requireFile( 'includes/class-sbdp-bookings-overview-service.php' );
		self::initClass( 'includes/class-sbdp-bookings-overview-page.php', '\\SBDP\\BookingsOverview\\BookingsOverviewPage' );
		self::initClass( 'includes/class-sbdp-bookings-overview-rest.php', '\\SBDP\\BookingsOverview\\BookingsOverviewRest' );

		// Harden privacy defaults for SBDP admin screens.
		self::initClass( 'includes/class-sbdp-privacy-guard.php', '\\SBDP\\Privacy\\PrivacyGuard' );
	}

	public static function isBooted(): bool {
		return self::$booted;
	}

	private static function requireFile( string $relativePath ): void {
		$path = SBDP_DIR . $relativePath;

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}

	private static function initClass( string $relativePath, string $class ): void {
		self::requireFile( $relativePath );

		if ( class_exists( $class ) && method_exists( $class, 'init' ) ) {
			$class::init();
		}
	}
}

