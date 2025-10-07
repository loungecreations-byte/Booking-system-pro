<?php

/**
 * Dayplanner shortcode output.
 *
 * @package Booking_Core
 */

namespace Booking_Core\Shortcodes;

final class Dayplanner {

	/**
	 * Register shortcode handler.
	 */
	public static function register(): void {
		add_shortcode( 'sbdp_dayplanner', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render shortcode output.
	 *
	 * @param array  $atts Shortcode attributes.
	 * @param string $content Content.
	 *
	 * @return string
	 */
	public static function render( array $atts = array(), string $content = '' ): string {  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		ob_start();
		?>
		<section class="sbdp-dayplanner" data-component="dayplanner" aria-live="polite">
			<div class="sbdp-dayplanner__app" data-sbdp-app="dayplanner"></div>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
