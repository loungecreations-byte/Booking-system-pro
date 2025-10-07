<?php

declare(strict_types=1);

namespace BSPModule\Core\Resource;

/**
 * Resource post type meta management.
 *
 * @package SBDP
 */

final class ResourceMeta {

	/**
	 * Hook resource meta boxes and columns.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_box' ) );
		add_action( 'save_post_bookable_resource', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_bookable_resource_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_bookable_resource_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	/**
	 * Register the resource meta box.
	 */
	public static function register_box() {
		add_meta_box(
			'sbdp-resource-details',
			__( 'Resource details', 'sbdp' ),
			array( __CLASS__, 'render_box' ),
			'bookable_resource',
			'side'
		);
	}

	/**
	 * Render resource controls.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_box( $post ) {
		wp_nonce_field( 'sbdp_resource_meta', 'sbdp_resource_meta_nonce' );

		$capacity = get_post_meta( $post->ID, '_sbdp_resource_capacity', true );
		$capacity = ( '' === $capacity || null === $capacity ) ? '' : max( 0, (int) $capacity );

		$color = (string) get_post_meta( $post->ID, '_sbdp_resource_color', true );
		$color = '' === trim( $color ) ? '#2563eb' : $color;

		$order = get_post_meta( $post->ID, '_sbdp_resource_order', true );
		$order = ( '' === $order || null === $order ) ? '' : (int) $order;

		echo '<p>' . esc_html__( 'Configure how this resource appears in the planner.', 'sbdp' ) . '</p>';

		echo '<label for="sbdp_resource_capacity" style="font-weight:600;display:block;margin-bottom:4px;">' . esc_html__( 'Capacity', 'sbdp' ) . '</label>';
		printf(
			'<input type="number" min="0" step="1" class="widefat" id="sbdp_resource_capacity" name="sbdp_resource_capacity" value="%s" />',
			esc_attr( $capacity )
		);

		echo '<label for="sbdp_resource_color" style="font-weight:600;display:block;margin:12px 0 4px;">' . esc_html__( 'Planner colour', 'sbdp' ) . '</label>';
		printf(
			'<input type="color" id="sbdp_resource_color" name="sbdp_resource_color" value="%s" class="widefat" style="height:38px;padding:0;" />',
			esc_attr( $color )
		);

		echo '<label for="sbdp_resource_order" style="font-weight:600;display:block;margin:12px 0 4px;">' . esc_html__( 'Planner order', 'sbdp' ) . '</label>';
		printf(
			'<input type="number" step="1" class="widefat" id="sbdp_resource_order" name="sbdp_resource_order" value="%s" />',
			esc_attr( $order )
		);

		echo '<p style="margin-top:12px;color:#475569;font-size:12px;">' . esc_html__( 'Use colour and order to mirror the timeline board.', 'sbdp' ) . '</p>';
	}

	/**
	 * Persist resource settings.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['sbdp_resource_meta_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['sbdp_resource_meta_nonce'] ), 'sbdp_resource_meta' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( 'bookable_resource' !== $post->post_type ) {
			return;
		}

		if ( isset( $_POST['sbdp_resource_capacity'] ) && '' !== $_POST['sbdp_resource_capacity'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$capacity = (int) wp_unslash( $_POST['sbdp_resource_capacity'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$capacity = max( 0, $capacity );
			update_post_meta( $post_id, '_sbdp_resource_capacity', $capacity );
		} else {
			delete_post_meta( $post_id, '_sbdp_resource_capacity' );
		}

		if ( isset( $_POST['sbdp_resource_color'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$color = sanitize_hex_color( wp_unslash( $_POST['sbdp_resource_color'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $color ) {
				update_post_meta( $post_id, '_sbdp_resource_color', $color );
			} else {
				delete_post_meta( $post_id, '_sbdp_resource_color' );
			}
		}

		if ( isset( $_POST['sbdp_resource_order'] ) && '' !== $_POST['sbdp_resource_order'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$order = (int) wp_unslash( $_POST['sbdp_resource_order'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_post_meta( $post_id, '_sbdp_resource_order', $order );
		} else {
			delete_post_meta( $post_id, '_sbdp_resource_order' );
		}
	}

	/**
	 * Insert planner columns.
	 *
	 * @param array<string,string> $columns Column map.
	 *
	 * @return array<string,string>
	 */
	public static function columns( $columns ) {
		$updated = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$updated['sbdp_color']    = __( 'Colour', 'sbdp' );
				$updated['sbdp_capacity'] = __( 'Capacity', 'sbdp' );
				$updated['sbdp_order']    = __( 'Order', 'sbdp' );
			}
			$updated[ $key ] = $label;
		}
		if ( ! isset( $updated['sbdp_color'] ) ) {
			$updated['sbdp_color'] = __( 'Colour', 'sbdp' );
		}
		if ( ! isset( $updated['sbdp_capacity'] ) ) {
			$updated['sbdp_capacity'] = __( 'Capacity', 'sbdp' );
		}
		if ( ! isset( $updated['sbdp_order'] ) ) {
			$updated['sbdp_order'] = __( 'Order', 'sbdp' );
		}
		return $updated;
	}

	/**
	 * Render column values.
	 *
	 * @param string $column  Column ID.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'sbdp_capacity' === $column ) {
			$capacity = get_post_meta( $post_id, '_sbdp_resource_capacity', true );
			if ( '' === $capacity || null === $capacity ) {
				echo esc_html__( 'Unlimited', 'sbdp' );
				return;
			}
			echo esc_html( (int) $capacity );
			return;
		}

		if ( 'sbdp_color' === $column ) {
			$color = (string) get_post_meta( $post_id, '_sbdp_resource_color', true );
			$color = '' === trim( $color ) ? '#2563eb' : $color;
			printf(
				'<span style="display:inline-block;width:18px;height:18px;border-radius:50%%;background:%1$s;border:1px solid rgba(15,23,42,0.15);margin-right:6px;"></span>%2$s',
				esc_attr( $color ),
				esc_html( strtoupper( ltrim( $color, '#' ) ) )
			);
			return;
		}

		if ( 'sbdp_order' === $column ) {
			$order = get_post_meta( $post_id, '_sbdp_resource_order', true );
			if ( '' === $order || null === $order ) {
				echo '&#8211;';
				return;
			}
			echo esc_html( (int) $order );
		}
	}
}

