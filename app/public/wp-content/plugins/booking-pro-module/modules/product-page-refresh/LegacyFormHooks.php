<?php
/**
 * LegacyFormHooks — extracted from sbdp-single-product-planner.css
 *
 * This file preserves PHP code that was accidentally embedded in a .css file.
 * It is NOT registered in any autoloader or hook system.
 * The functionality is already handled by includes/bootstrap/sbdp-single-product-planner.php.
 *
 * DO NOT require or include this file. It exists for archival reference only.
 * When the bootstrap shim is fully removed, this file can be safely deleted.
 *
 * @package BookingProModule
 * @since   extracted 2026-04-02
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// ORPHANED TAIL CODE — was the closing portion of a render function.
// The function body lives in includes/bootstrap/sbdp-single-product-planner.php.
// Do not uncomment or call directly.
// ---------------------------------------------------------------------------
//
//         return ob_get_clean();
//     }
// }
// ---------------------------------------------------------------------------

// Shortcode for Elementor/widgets: [sbdp_product_planner] or [sbdp_product_planner product_id=123]

// Keep original WooCommerce hook for backward compatibility
// NOTE: This is a duplicate of the hook registered in includes/bootstrap/sbdp-single-product-planner.php.
// This registration is intentionally NOT active (file is not loaded).
add_action( 'woocommerce_single_product_summary', function () {
	// Robuuste Elementor Theme Builder detectie
	$is_elementor = false;
	// Elementor plugin actief?
	if ( defined( 'ELEMENTOR_VERSION' ) ) {
		// Elementor preview, Theme Builder, AJAX, of the_content filter actief
		if (
			isset( $_GET['elementor-preview'] ) ||
			did_action( 'elementor/theme/register_locations' ) ||
			( function_exists( 'elementor_theme_do_location' ) && elementor_theme_do_location( 'single' ) ) ||
			( defined( 'ELEMENTOR_PRO_VERSION' ) && isset( $_REQUEST['action'] ) && strpos( $_REQUEST['action'], 'elementor' ) !== false ) ||
			doing_filter( 'the_content' )
		) {
			$is_elementor = true;
		}
	}
	if ( $is_elementor ) {
		// Elementor regelt zelf de content via the_content en shortcode
		return;
	}
	echo sbdp_render_product_planner_form();
}, 5 );

/**
 * Hide ALL duplicate forms with CSS.
 *
 * NOTE: The inline style block this function would output has been moved
 * into sbdp-single-product-planner.css as a static stylesheet rule set.
 * This hook registration is intentionally NOT active (file is not loaded).
 */
add_action( 'wp_head', function () {
	global $sbdp_planner_shortcode_rendered;
	if ( ! sbdp_should_output_product_planner_overrides() ) {
		return;
	}
	// Extra: Elementor header verbergen als planner via shortcode zichtbaar is
	// Verberg de header niet meer, navigatie moet altijd zichtbaar zijn
	if ( ! empty( $sbdp_planner_shortcode_rendered ) ) {
		// NIET laden van agressieve CSS als via shortcode/Elementor
		return;
	}
	// Inline style block intentionally omitted — rules are now in the static CSS file.
} );
