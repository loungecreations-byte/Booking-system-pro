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

