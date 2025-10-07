<?php

/**
 * Booking cart shortcode.
 *
 * @package Booking_Core
 */

namespace Booking_Core\Shortcodes;

final class Cart {

	/**
	 * Register shortcode handler.
	 */
	public static function register(): void {
		add_shortcode( 'sbdp_cart', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the booking cart container.
	 *
	 * @param array  $atts Shortcode attributes.
	 * @param string $content Content.
	 *
	 * @return string
	 */
	public static function render( array $atts = array(), string $content = '' ): string {  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		ob_start();
		?>
		<section class="sbdp-cart" data-component="cart" aria-live="polite">
			<div class="sbdp-cart__app" data-sbdp-app="cart"></div>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
