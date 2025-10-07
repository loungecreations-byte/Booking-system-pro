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
	 * Register the capacity meta box.
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
	 * Render capacity controls.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_box( $post ) {
		wp_nonce_field( 'sbdp_resource_meta', 'sbdp_resource_meta_nonce' );
		$capacity = get_post_meta( $post->ID, '_sbdp_resource_capacity', true );
		$capacity = ( '' === $capacity || null === $capacity ) ? '' : max( 0, (int) $capacity );

		echo '<p>' . esc_html__( 'Set the maximum number of participants this resource can host. Leave empty for no limit.', 'sbdp' ) . '</p>';
		echo '<label for="sbdp_resource_capacity">' . esc_html__( 'Capacity', 'sbdp' ) . '</label>';
		printf(
			'<input type="number" min="0" step="1" class="widefat" id="sbdp_resource_capacity" name="sbdp_resource_capacity" value="%s" />',
			esc_attr( $capacity )
		);
	}

	/**
	 * Persist capacity.
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
	}

	/**
	 * Insert capacity column.
	 *
	 * @param array<string,string> $columns Column map.
	 *
	 * @return array<string,string>
	 */
	public static function columns( $columns ) {
		$updated = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$updated['sbdp_capacity'] = __( 'Capacity', 'sbdp' );
			}
			$updated[ $key ] = $label;
		}
		if ( ! isset( $updated['sbdp_capacity'] ) ) {
			$updated['sbdp_capacity'] = __( 'Capacity', 'sbdp' );
		}
		return $updated;
	}

	/**
	 * Render capacity value.
	 *
	 * @param string $column  Column ID.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'sbdp_capacity' !== $column ) {
			return;
		}
		$capacity = get_post_meta( $post_id, '_sbdp_resource_capacity', true );
		if ( '' === $capacity || null === $capacity ) {
			echo esc_html__( 'Unlimited', 'sbdp' );
			return;
		}
		echo esc_html( (int) $capacity );
	}
}
