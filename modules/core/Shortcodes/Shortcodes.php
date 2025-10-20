<?php

declare(strict_types=1);

namespace BSPModule\Core\Shortcodes;

/**
 * Shortcode implementations.
 *
 * @package SBDP
 */

final class Shortcodes {

	/**
	 * Register shortcodes.
	 */
	public static function init() {
		add_shortcode( 'sbdp_dayplanner', array( __CLASS__, 'render_planner' ) );
	}

	/**
	 * Render the frontend day planner scaffold.
	 *
	 * @param array<string,string> $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public static function render_planner( $atts = array() ) {
		unset( $atts );

		ob_start();
		?>
		<section class="sbdp-day-planner-shell" aria-label="<?php echo esc_attr__( 'Dagplanner', 'sbdp' ); ?>">
			<div id="sbdp-day-planner-root" data-component="sbdp-day-planner"></div>
			<noscript>
				<p class="sbdp-day-planner__noscript">
					<?php esc_html_e( 'Schakel JavaScript in om de dagplanner te gebruiken.', 'sbdp' ); ?>
				</p>
			</noscript>
		</section>
		<?php
		return trim( ob_get_clean() );
	}
}

