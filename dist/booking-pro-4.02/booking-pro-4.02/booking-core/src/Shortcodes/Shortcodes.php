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
		<section class="sbdp-wrap" aria-label="<?php echo esc_attr__( 'Dagplanner', 'sbdp' ); ?>">
			<div class="sbdp-left" role="complementary">
				<header class="sbdp-header">
					<h2 class="sbdp-heading">
						<?php esc_html_e( 'Stel jouw dagje Den Bosch samen', 'sbdp' ); ?>
						<span><?php esc_html_e( 'Kies activiteiten, bepaal tijden en bekijk direct de totaalprijs.', 'sbdp' ); ?></span>
					</h2>
				</header>

				<form class="sbdp-card sbdp-card--controls" aria-label="<?php echo esc_attr__( 'Basisinstellingen', 'sbdp' ); ?>" action="#" method="post" onsubmit="return false;">
					<div class="sbdp-control">
						<label for="sbdp-date"><?php esc_html_e( 'Kies datum', 'sbdp' ); ?></label>
						<input type="date" id="sbdp-date" name="sbdp-date" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required />
					</div>
					<div class="sbdp-control">
						<label for="sbdp-participants"><?php esc_html_e( 'Aantal deelnemers', 'sbdp' ); ?></label>
						<input type="number" id="sbdp-participants" name="sbdp-participants" min="1" step="1" value="1" required />
					</div>
				</form>

				<div class="sbdp-card sbdp-card--filters" aria-label="<?php echo esc_attr__( 'Filters', 'sbdp' ); ?>">
					<div class="sbdp-filter">
						<label for="sbdp-filter-search"><?php esc_html_e( 'Zoek activiteit', 'sbdp' ); ?></label>
						<input type="search" id="sbdp-filter-search" placeholder="<?php echo esc_attr__( 'Bijv. high tea of lunch', 'sbdp' ); ?>" />
					</div>
					<div class="sbdp-filter">
						<label for="sbdp-filter-duration"><?php esc_html_e( 'Filter op duur', 'sbdp' ); ?></label>
						<select id="sbdp-filter-duration">
							<option value="all"><?php esc_html_e( 'Alle duurtes', 'sbdp' ); ?></option>
							<option value="short"><?php esc_html_e( 'Tot 60 min', 'sbdp' ); ?></option>
							<option value="medium"><?php esc_html_e( '61-120 min', 'sbdp' ); ?></option>
							<option value="long"><?php esc_html_e( 'Lang (120+ min)', 'sbdp' ); ?></option>
						</select>
					</div>
				</div>

				<div class="sbdp-card sbdp-card--bundles" id="sbdp-bundles-card" data-has-bundles="0" aria-live="polite">
					<h3><?php esc_html_e( 'Aanbevolen arrangementen', 'sbdp' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Kies een samengesteld programma als startpunt.', 'sbdp' ); ?></p>
					<div class="sbdp-bundles" id="sbdp-bundles-list" role="list"></div>
				</div>

				<div class="sbdp-card" aria-live="polite">
					<h3><?php esc_html_e( 'Beschikbare activiteiten', 'sbdp' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Sleep naar de kalender of klik op het plus-icoon.', 'sbdp' ); ?></p>
					<div id="sbdp-services" class="sbdp-services" role="list"></div>
				</div>
			</div>

			<div class="sbdp-right" role="region" aria-label="<?php echo esc_attr__( 'Planner en samenvatting', 'sbdp' ); ?>">
				<div id="sbdp-toast" class="sbdp-toast" aria-live="assertive"></div>
				<div id="sbdp-message-area" class="sbdp-message" aria-live="polite"></div>
				<div id="sbdp-calendar" class="sbdp-calendar-shell" aria-label="<?php echo esc_attr__( 'Dagplanner', 'sbdp' ); ?>"></div>

				<aside class="sbdp-summary" aria-label="<?php echo esc_attr__( 'Samenvatting', 'sbdp' ); ?>">
					<h3><?php esc_html_e( 'Samenvatting', 'sbdp' ); ?></h3>
					<div id="sbdp-summary-list" role="list"></div>
					<div class="sbdp-total">
						<span><?php esc_html_e( 'Totaal (incl. btw)', 'sbdp' ); ?>:</span>
						<strong id="sbdp-total-amount">&euro;0,00</strong>
					</div>
					<div class="sbdp-actions">
						<button id="sbdp-btn-pay" class="button button-primary" type="button"><?php esc_html_e( 'Boek &amp; Betaal', 'sbdp' ); ?></button>
						<button id="sbdp-btn-request" class="button" type="button"><?php esc_html_e( 'Doe aanvraag', 'sbdp' ); ?></button>
						<button id="sbdp-btn-share" class="button button-secondary" type="button"><?php esc_html_e( 'Deel programma', 'sbdp' ); ?></button>
					</div>
				</aside>
			</div>
		</section>
		<?php
		return trim( ob_get_clean() );
	}
}


