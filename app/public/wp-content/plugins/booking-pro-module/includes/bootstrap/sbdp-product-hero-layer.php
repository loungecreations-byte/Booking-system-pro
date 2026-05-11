<?php
/**
 * DDB Product Hero Layer
 *
 * Renders the premium product hero section above WooCommerce's default layout:
 *   1. Social proof bar   — live viewer count (seeded per day+product for consistency)
 *   2. Hero banner        — full-width product image with gradient, title, badges
 *   3. Meta strip         — location | duration | capacity pills inside the hero
 *   4. Photo gallery row  — additional product images shown as a horizontal strip
 *
 * All output is scoped to bookable-service product pages where the SBDP planner
 * is active. Hooks into WooCommerce's standard single-product action points so
 * output works equally when Elementor or the default Woo template is in use.
 *
 * @package BookingProModule
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Returns true only on the front-end for a bookable-service product that has
 * the SBDP planner active (same condition used by the main planner bootstrap).
 */
function sbdp_hero_should_run(): bool {
    if ( is_admin() || wp_doing_ajax() ) {
        return false;
    }
    if ( ! function_exists( "is_product" ) || ! is_product() ) {
        return false;
    }
    if ( ! function_exists( "sbdp_should_output_product_planner_overrides" ) ) {
        return false;
    }
    return (bool) sbdp_should_output_product_planner_overrides();
}

// ─── 2. Hero Section ─────────────────────────────────────────────────────────

add_action( "woocommerce_before_single_product", "sbdp_render_product_hero", 5 );

function sbdp_render_product_hero(): void {
    if ( ! sbdp_hero_should_run() ) {
        return;
    }

    global $product;
    if ( ! $product instanceof WC_Product ) {
        $product = function_exists( "wc_get_product" ) ? wc_get_product( get_the_ID() ) : null;
    }
    if ( ! $product instanceof WC_Product ) {
        return;
    }

    $product_id  = (int) $product->get_id();

    // ── Hero image ────────────────────────────────────────────────────────────
    $image_id  = (int) $product->get_image_id();
    $image_url = $image_id ? wp_get_attachment_image_url( $image_id, "full" ) : "";

    if ( ! $image_url ) {
        // Try first gallery image
        $gallery_ids = $product->get_gallery_image_ids();
        if ( ! empty( $gallery_ids ) ) {
            $image_url = wp_get_attachment_image_url( (int) $gallery_ids[0], "full" ) ?: "";
        }
    }

    // ── Badges ────────────────────────────────────────────────────────────────
    $categories    = wp_get_post_terms( $product_id, "product_cat", [ "number" => 1 ] );
    $category_name = ( ! empty( $categories ) && ! is_wp_error( $categories ) )
        ? strtoupper( (string) $categories[0]->name )
        : "";

    $rating       = (float) $product->get_average_rating();
    $rating_count = (int) $product->get_rating_count();

    // "POPULAIR" if sold > 5 or rating high
    $total_sales = (int) $product->get_total_sales();
    $is_popular  = $total_sales > 5 || $rating >= 4.2;

    // ── Meta strip data ────────────────────────────────────────────────────────
    // Location
    $location_raw = get_post_meta( $product_id, "_sbdp_booking_location", true );
    if ( is_array( $location_raw ) ) {
        $location = (string) ( $location_raw["address"] ?? $location_raw["label"] ?? reset( $location_raw ) ?? "" );
    } else {
        $location = is_string( $location_raw ) ? trim( $location_raw ) : "";
    }

    // Duration
    $duration_value = (int) get_post_meta( $product_id, "_sbdp_duration", true );
    $duration_unit  = (string) ( get_post_meta( $product_id, "_sbdp_duration_unit", true ) ?: "minutes" );
    $duration_label = "";
    if ( $duration_value > 0 ) {
        if ( $duration_unit === "hours" ) {
            /* translators: %d: number of hours */
            $duration_label = sprintf( _n( "%d uur", "%d uur", $duration_value, "sbdp" ), $duration_value );
        } else {
            /* translators: %d: number of minutes */
            $duration_label = sprintf( __( "%d minuten", "sbdp" ), $duration_value );
        }
    }

    // Capacity
    $max_people = (int) get_post_meta( $product_id, "_sbdp_max_people", true );

    // ── Title ─────────────────────────────────────────────────────────────────
    $title = esc_html( $product->get_name() );

    // ── Render ────────────────────────────────────────────────────────────────
    $hero_style = $image_url
        ? " style=\"background-image: url(" . esc_url( $image_url ) . ")\""
        : "";
    ?>
    <div class="ddb-product-hero"<?php echo $hero_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
        <div class="ddb-product-hero__overlay" aria-hidden="true"></div>
        <div class="ddb-product-hero__content">

            <?php // ── Badge row ──────────────────────────────────────── ?>
            <div class="ddb-product-hero__badges" aria-label="Product labels">
                <?php if ( $category_name ) : ?>
                    <span class="ddb-hero-badge ddb-hero-badge--category">
                        <?php echo esc_html( $category_name ); ?>
                    </span>
                <?php endif; ?>

                <?php if ( $rating > 0 && $rating_count > 0 ) : ?>
                    <span class="ddb-hero-badge ddb-hero-badge--rating" aria-label="<?php echo esc_attr( number_format( $rating, 1 ) . ' sterren' ); ?>">
                        <?php
                        for ( $i = 1; $i <= 5; $i++ ) {
                            $filled = $i <= round( $rating );
                            echo '<svg class="ddb-hero-badge__star' . ( $filled ? ' ddb-hero-badge__star--filled' : '' ) . '" width="14" height="14" viewBox="0 0 24 24" fill="' . ( $filled ? 'currentColor' : 'none' ) . '" stroke="currentColor" stroke-width="2" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
                        }
                        ?>
                    </span>
                <?php endif; ?>

                <?php if ( $is_popular ) : ?>
                    <span class="ddb-hero-badge ddb-hero-badge--popular">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                        <?php esc_html_e( 'POPULAIR', 'sbdp' ); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php // ── Product title ─────────────────────────────────── ?>
            <h1 class="ddb-product-hero__title"><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h1>

            <?php // ── Meta strip ────────────────────────────────────── ?>
            <?php if ( $location || $duration_label || $max_people > 0 ) : ?>
                <ul class="ddb-product-hero__meta" aria-label="Product informatie">
                    <?php if ( $location ) : ?>
                        <li class="ddb-hero-meta-item">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            <span><?php echo esc_html( $location ); ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if ( $duration_label ) : ?>
                        <li class="ddb-hero-meta-item">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span><?php echo esc_html( $duration_label ); ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if ( $max_people > 0 ) : ?>
                        <li class="ddb-hero-meta-item">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <span>
                                <?php
                                /* translators: %d: max number of people */
                                echo esc_html( sprintf( __( 'Tot %d personen', 'sbdp' ), $max_people ) );
                                ?>
                            </span>
                        </li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>

        </div>
    </div>
    <?php
}

// ─── 3. Photo Gallery Row ─────────────────────────────────────────────────────
// Hooks into the summary after the short description (priority 26) but before
// the booking form. Shows additional product gallery images as a row.

add_action( 'woocommerce_single_product_summary', 'sbdp_render_product_photo_row', 26 );

function sbdp_render_product_photo_row(): void {
    if ( ! sbdp_hero_should_run() ) {
        return;
    }

    // Skip in Elementor preview/editor context
    if ( isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }

    global $product;
    if ( ! $product instanceof WC_Product ) {
        return;
    }

    $gallery_ids = $product->get_gallery_image_ids();
    if ( empty( $gallery_ids ) ) {
        return;
    }

    // Show max 3 images
    $show_ids = array_slice( $gallery_ids, 0, 3 );

    echo '<div class="ddb-product-photo-row" aria-label="' . esc_attr__( 'Foto\'s', 'sbdp' ) . '">';
    foreach ( $show_ids as $img_id ) {
        $img_id  = (int) $img_id;
        $img_url = wp_get_attachment_image_url( $img_id, 'medium_large' );
        $img_alt = trim( (string) get_post_meta( $img_id, '_wp_attachment_image_alt', true ) );
        if ( $img_url ) {
            echo '<div class="ddb-product-photo-row__item">';
            echo '<img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $img_alt ) . '" loading="lazy" decoding="async">';
            echo '</div>';
        }
    }
    echo '</div>';
}

