<?php

declare(strict_types=1);

namespace BSPModule\Core\Resource;

use WP_Post;
use BSPModule\Core\Resource\ResourceCalendar;

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
		add_action( 'save_post_ddb_spot', array( __CLASS__, 'sync_linked_resources_for_spot' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
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
		$linked_spot_id = self::get_linked_spot_id( (int) $post->ID );
		$spot_options   = self::get_spot_options();

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

		echo '<label for="sbdp_resource_linked_spot_id" style="font-weight:600;display:block;margin:12px 0 4px;">' . esc_html__( 'Linked spot', 'sbdp' ) . '</label>';
		echo '<select id="sbdp_resource_linked_spot_id" name="sbdp_resource_linked_spot_id" class="widefat">';
		echo '<option value="">' . esc_html__( 'No linked spot', 'sbdp' ) . '</option>';
		foreach ( $spot_options as $spot_option ) {
			printf(
				'<option value="%1$s" %3$s>%2$s</option>',
				esc_attr( (string) $spot_option['id'] ),
				esc_html( $spot_option['title'] ),
				selected( $linked_spot_id === (int) $spot_option['id'], true, false )
			);
		}
		echo '</select>';
		echo '<p style="margin-top:8px;color:#475569;font-size:12px;">' . esc_html__( 'Use this to connect the operational resource to the content/location spot. The spot is not booking truth; the resource is.', 'sbdp' ) . '</p>';

		echo '<p style="margin-top:12px;color:#475569;font-size:12px;">' . esc_html__( 'Use colour and order to mirror the timeline board.', 'sbdp' ) . '</p>';

		$calendar_id   = ResourceCalendar::get_calendar_id( $post->ID );
		$timezone      = ResourceCalendar::get_timezone( $post->ID );
		$last_sync     = ResourceCalendar::get_last_sync( $post->ID );
		$status        = ResourceCalendar::get_status( $post->ID );
		$access_token  = ResourceCalendar::get_access_token( $post->ID );
		$refresh_token = ResourceCalendar::get_refresh_token( $post->ID );

		echo '<hr style="margin:24px 0 12px;" />';
		echo '<p style="margin:0;padding:0;font-weight:600;">' . esc_html__( 'Google Calendar sync', 'sbdp' ) . '</p>';
		printf(
			'<label for="sbdp_resource_calendar_id" style="font-weight:600;display:block;margin-top:8px;">%s</label>',
			esc_html__( 'Calendar ID', 'sbdp' )
		);
		printf(
			'<input type="text" id="sbdp_resource_calendar_id" name="sbdp_resource_calendar_id" class="widefat" value="%s" />',
			esc_attr( (string) $calendar_id )
		);
		printf(
			'<label for="sbdp_resource_calendar_timezone" style="font-weight:600;display:block;margin-top:12px;">%s</label>',
			esc_html__( 'Time zone (optional)', 'sbdp' )
		);
		printf(
			'<input type="text" id="sbdp_resource_calendar_timezone" name="sbdp_resource_calendar_timezone" class="widefat" value="%s" placeholder="%s" />',
			esc_attr( (string) $timezone ),
			esc_attr__( 'Europe/Amsterdam', 'sbdp' )
		);
		echo '<label style="display:block;margin-top:12px;font-weight:600;">' . esc_html__( 'Access & refresh tokens', 'sbdp' ) . '</label>';
		printf(
			'<textarea name="sbdp_resource_calendar_access_token" rows="2" class="widefat" placeholder="%s">%s</textarea>',
			esc_attr__( 'Paste your Google access token here', 'sbdp' ),
			esc_textarea( $access_token ?? '' )
		);
		printf(
			'<textarea name="sbdp_resource_calendar_refresh_token" rows="2" class="widefat" placeholder="%s">%s</textarea>',
			esc_attr__( 'Paste your refresh token here', 'sbdp' ),
			esc_textarea( $refresh_token ?? '' )
		);

		echo '<div style="margin-top:12px;display:flex;gap:12px;align-items:center;">';
		echo '<button type="button" class="button button-secondary" id="sbdp-calendar-sync-now" data-resource-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Sync now', 'sbdp' ) . '</button>';
		printf(
			'<span id="sbdp-calendar-sync-status" style="font-size:12px;color:#475569;">%s %s</span>',
			esc_html__( 'Status:', 'sbdp' ),
			esc_html( (string) $status )
		);
		echo '</div>';
		if ( $last_sync ) {
			printf(
				'<p style="margin:8px 0 0;font-size:11px;color:#94a3b8;">%s %s</p>',
				esc_html__( 'Last sync:', 'sbdp' ),
				esc_html( date_i18n( __( 'Y-m-d H:i', 'sbdp' ), (int) $last_sync ) )
			);
		}
	}

	/**
	 * Persist resource settings.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save( int $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$input = filter_input_array(
			INPUT_POST,
			array(
				'sbdp_resource_meta_nonce' => array(
					'filter' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
					'flags'  => FILTER_REQUIRE_SCALAR,
				),
				'sbdp_resource_capacity'        => array(
					'filter' => FILTER_VALIDATE_INT,
					'flags'  => FILTER_REQUIRE_SCALAR,
				),
				'sbdp_resource_color'           => array(
					'filter' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
					'flags'  => FILTER_REQUIRE_SCALAR,
				),
				'sbdp_resource_order'           => array(
					'filter' => FILTER_VALIDATE_INT,
					'flags'  => FILTER_REQUIRE_SCALAR,
				),
				'sbdp_resource_linked_spot_id'  => array(
					'filter' => FILTER_VALIDATE_INT,
					'flags'  => FILTER_REQUIRE_SCALAR,
				),
				'sbdp_resource_calendar_id'     => array(
					'filter' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
					'flags'  => FILTER_REQUIRE_SCALAR,
				),
				'sbdp_resource_calendar_timezone' => array(
					'filter' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
					'flags'  => FILTER_REQUIRE_SCALAR,
				),
				'sbdp_resource_calendar_access_token'  => array(
					'filter' => FILTER_UNSAFE_RAW,
					'flags'  => FILTER_REQUIRE_SCALAR,
				),
				'sbdp_resource_calendar_refresh_token' => array(
					'filter' => FILTER_UNSAFE_RAW,
					'flags'  => FILTER_REQUIRE_SCALAR,
				),
			)
		);

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$nonce = $input['sbdp_resource_meta_nonce'] ?? null;
		if ( ! $nonce || ! wp_verify_nonce( (string) $nonce, 'sbdp_resource_meta' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'bookable_resource' !== $post->post_type ) {
			return;
		}

		$capacity = $input['sbdp_resource_capacity'] ?? null;
		if ( null !== $capacity && false !== $capacity ) {
			$capacity = max( 0, (int) $capacity );
			update_post_meta( $post_id, '_sbdp_resource_capacity', $capacity );
		} else {
			delete_post_meta( $post_id, '_sbdp_resource_capacity' );
		}

		$color = $input['sbdp_resource_color'] ?? null;
		if ( null !== $color && '' !== $color ) {
			$color = sanitize_hex_color( (string) $color );
			if ( $color ) {
				update_post_meta( $post_id, '_sbdp_resource_color', $color );
			} else {
				delete_post_meta( $post_id, '_sbdp_resource_color' );
			}
		}

		$order = $input['sbdp_resource_order'] ?? null;
		if ( null !== $order && false !== $order ) {
			update_post_meta( $post_id, '_sbdp_resource_order', (int) $order );
		} else {
			delete_post_meta( $post_id, '_sbdp_resource_order' );
		}

		$linked_spot_id = $input['sbdp_resource_linked_spot_id'] ?? null;
		if ( null !== $linked_spot_id && false !== $linked_spot_id && $linked_spot_id > 0 && self::is_valid_spot( (int) $linked_spot_id ) ) {
			update_post_meta( $post_id, '_ddb_linked_spot_id', (int) $linked_spot_id );
			self::sync_resource_media_from_linked_spot( $post_id, (int) $linked_spot_id );
		} else {
			delete_post_meta( $post_id, '_ddb_linked_spot_id' );
			delete_post_meta( $post_id, '_sbdp_resource_thumbnail_source' );
			delete_post_meta( $post_id, '_sbdp_resource_thumbnail_spot_id' );
		}

		$calendar_id   = trim( (string) ( $input['sbdp_resource_calendar_id'] ?? '' ) );
		$timezone      = trim( (string) ( $input['sbdp_resource_calendar_timezone'] ?? '' ) );
		$access_token  = trim( (string) ( $input['sbdp_resource_calendar_access_token'] ?? '' ) );
		$refresh_token = trim( (string) ( $input['sbdp_resource_calendar_refresh_token'] ?? '' ) );

		ResourceCalendar::set_calendar_id( $post_id, $calendar_id !== '' ? $calendar_id : null );
		ResourceCalendar::set_timezone( $post_id, $timezone !== '' ? $timezone : null );

		$tokens = array();
		if ( $access_token !== '' ) {
			$tokens['access_token'] = $access_token;
		}
		if ( $refresh_token !== '' ) {
			$tokens['refresh_token'] = $refresh_token;
		}

		if ( $tokens !== array() ) {
			ResourceCalendar::set_tokens( $post_id, $tokens );
		}

		if ( $calendar_id !== '' && ! empty( $tokens ) ) {
			ResourceCalendar::mark_connected( $post_id );
		} else {
			ResourceCalendar::mark_disconnected( $post_id );
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
				$updated['sbdp_spot']     = __( 'Linked spot', 'sbdp' );
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
		if ( ! isset( $updated['sbdp_spot'] ) ) {
			$updated['sbdp_spot'] = __( 'Linked spot', 'sbdp' );
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
			return;
		}

		if ( 'sbdp_spot' === $column ) {
			$spot_label = self::get_linked_spot_label( (int) $post_id );
			if ( '' === $spot_label ) {
				echo '&#8211;';
				return;
			}
			echo esc_html( $spot_label );
		}
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'post.php' && $hook_suffix !== 'post-new.php' ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'bookable_resource' !== $screen->post_type ) {
			return;
		}

		$handle = 'sbdp-resource-calendar-admin';
		$src    = defined( 'SBDP_URL' )
			? SBDP_URL . 'assets/js/resource-calendar-admin.js'
			: plugins_url( 'assets/js/resource-calendar-admin.js', SBDP_PLUGIN_FILE );
		$deps   = array( 'wp-api-fetch' );
		$ver    = defined( 'SBDP_VER' ) ? SBDP_VER : time();

		wp_enqueue_script( $handle, $src, $deps, $ver, true );
		wp_localize_script( $handle, 'SBDP_RESOURCE_CALENDAR', array(
			'restUrl'    => esc_url_raw( rest_url( 'sbdp/v1/resource-calendar' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'resourceId' => $screen->post_id ?? 0,
		) );
	}

	public static function get_linked_spot_id( int $resource_id ): int {
		if ( $resource_id <= 0 ) {
			return 0;
		}

		return (int) get_post_meta( $resource_id, '_ddb_linked_spot_id', true );
	}

	public static function get_linked_spot_label( int $resource_id ): string {
		$spot_id = self::get_linked_spot_id( $resource_id );
		if ( $spot_id <= 0 ) {
			return '';
		}

		$spot = get_post( $spot_id );
		if ( ! $spot instanceof WP_Post || 'ddb_spot' !== $spot->post_type ) {
			return '';
		}

		return get_the_title( $spot );
	}

	public static function get_admin_label( int $resource_id ): string {
		$title = get_the_title( $resource_id );
		if ( ! is_string( $title ) || '' === trim( $title ) ) {
			$title = (string) $resource_id;
		}

		$spot_label = self::get_linked_spot_label( $resource_id );
		if ( '' === $spot_label ) {
			return $title;
		}

		return sprintf(
			/* translators: 1: resource title, 2: linked spot title */
			__( '%1$s -> %2$s', 'sbdp' ),
			$title,
			$spot_label
		);
	}

	/**
	 * @return array<int,array{id:int,title:string}>
	 */
	private static function get_spot_options(): array {
		if ( ! post_type_exists( 'ddb_spot' ) ) {
			return array();
		}

		$spots = get_posts(
			array(
				'post_type'      => 'ddb_spot',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( ! is_array( $spots ) ) {
			return array();
		}

		$options = array();
		foreach ( $spots as $spot ) {
			if ( ! $spot instanceof WP_Post ) {
				continue;
			}

			$options[] = array(
				'id'    => (int) $spot->ID,
				'title' => get_the_title( $spot ),
			);
		}

		return $options;
	}

	private static function is_valid_spot( int $spot_id ): bool {
		$spot = get_post( $spot_id );

		return $spot instanceof WP_Post && 'ddb_spot' === $spot->post_type;
	}

	public static function sync_linked_resources_for_spot( int $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'ddb_spot' !== $post->post_type ) {
			return;
		}

		$resources = get_posts(
			array(
				'post_type'      => 'bookable_resource',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_ddb_linked_spot_id',
						'value' => (int) $post_id,
					),
				),
			)
		);

		if ( ! is_array( $resources ) ) {
			return;
		}

		foreach ( $resources as $resource_id ) {
			self::sync_resource_media_from_linked_spot( (int) $resource_id, (int) $post_id );
		}
	}

	private static function sync_resource_media_from_linked_spot( int $resource_id, int $spot_id ): void {
		if ( $resource_id <= 0 || $spot_id <= 0 ) {
			return;
		}

		$spot_thumbnail_id = self::get_spot_thumbnail_id( $spot_id );
		if ( $spot_thumbnail_id <= 0 ) {
			return;
		}

		$current_thumbnail_id = (int) get_post_thumbnail_id( $resource_id );
		$current_source       = (string) get_post_meta( $resource_id, '_sbdp_resource_thumbnail_source', true );
		$current_source_spot  = (int) get_post_meta( $resource_id, '_sbdp_resource_thumbnail_spot_id', true );

		$can_overwrite = $current_thumbnail_id <= 0 || ( $current_source === 'linked_spot' && $current_source_spot === $spot_id );
		if ( ! $can_overwrite ) {
			return;
		}

		set_post_thumbnail( $resource_id, $spot_thumbnail_id );
		update_post_meta( $resource_id, '_sbdp_resource_thumbnail_source', 'linked_spot' );
		update_post_meta( $resource_id, '_sbdp_resource_thumbnail_spot_id', $spot_id );
	}

	private static function get_spot_thumbnail_id( int $spot_id ): int {
		$thumbnail_id = (int) get_post_thumbnail_id( $spot_id );
		if ( $thumbnail_id > 0 ) {
			return $thumbnail_id;
		}

		$hero_id = (int) get_post_meta( $spot_id, '_ddb_image_hero_id', true );
		if ( $hero_id > 0 ) {
			return $hero_id;
		}

		$gallery_csv = (string) get_post_meta( $spot_id, '_ddb_gallery_ids', true );
		if ( '' === trim( $gallery_csv ) ) {
			return 0;
		}

		$gallery_ids = array_values(
			array_filter(
				array_map( 'intval', array_map( 'trim', explode( ',', $gallery_csv ) ) )
			)
		);

		return $gallery_ids[0] ?? 0;
	}
}



