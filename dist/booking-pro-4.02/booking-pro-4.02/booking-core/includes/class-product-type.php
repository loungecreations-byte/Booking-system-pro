<?php

/**
 * WooCommerce product type integration.
 *
 * @package Booking_Core
 */

namespace Booking_Core;

use WC_Product;

final class Product_Type {

	public const TYPE = 'bookable_service';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'product_type_selector', array( __CLASS__, 'register_selector' ) );
		add_filter( 'woocommerce_product_class', array( __CLASS__, 'map_product_class' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'ensure_product_class' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_general_section' ) );
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'register_tabs' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_settings_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_meta' ) );
	}

	/**
	 * Add our product type to the selector dropdown.
	 */
	public static function register_selector( array $types ): array {
		$types[ self::TYPE ] = __( 'Bookable service', 'booking-core' );

		return $types;
	}

	/**
	 * Ensure WooCommerce loads our product class.
	 */
	public static function ensure_product_class(): void {
		require_once BOOKING_CORE_PATH . 'includes/class-wc-product-bookable-service.php';
	}

	/**
	 * Map WooCommerce product class.
	 */
	public static function map_product_class( string $classname, string $product_type ): string {
		if ( self::TYPE === $product_type ) {
			return 'WC_Product_Bookable_Service';
		}

		return $classname;
	}

	/**
	 * Render additional fields in the General tab.
	 */
	public static function render_general_section(): void {
		global $post;
		$product_id = $post ? (int) $post->ID : 0;

		echo '<div class="options_group show_if_' . esc_attr( self::TYPE ) . '">';

		woocommerce_wp_text_input(
			array(
				'id'                => '_sbdp_duration',
				'label'             => __( 'Boekingsduur', 'booking-core' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'value'             => get_post_meta( $product_id, '_sbdp_duration', true ),
				'desc_tip'          => true,
				'description'       => __( 'Aantal units dat standaard in het boekingsformulier is ingesteld.', 'booking-core' ),
			)
		);

		woocommerce_wp_select(
			array(
				'id'          => '_sbdp_duration_unit',
				'label'       => __( 'Boekingsduur eenheid', 'booking-core' ),
				'value'       => get_post_meta( $product_id, '_sbdp_duration_unit', true ) ?: 'hours',
				'options'     => array(
					'minutes' => __( 'Minuten', 'booking-core' ),
					'hours'   => __( 'Uren', 'booking-core' ),
					'days'    => __( 'Dagen', 'booking-core' ),
					'months'  => __( 'Maanden', 'booking-core' ),
				),
				'desc_tip'    => true,
				'description' => __( 'Bepaal of je klanten minuten, uren, dagen of maanden kunnen boeken.', 'booking-core' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => '_sbdp_default_start_date',
				'label'       => __( 'Default startdatum in boekingsformulier', 'booking-core' ),
				'type'        => 'date',
				'value'       => get_post_meta( $product_id, '_sbdp_default_start_date', true ),
				'desc_tip'    => true,
				'description' => __( 'Dag die standaard wordt ingevuld in het startdatumveld. Laat leeg voor vandaag.', 'booking-core' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => '_sbdp_default_start_time',
				'label'       => __( 'Default starttijd in boekingsformulier', 'booking-core' ),
				'type'        => 'time',
				'value'       => get_post_meta( $product_id, '_sbdp_default_start_time', true ),
				'placeholder' => 'HH:MM',
				'desc_tip'    => true,
				'description' => __( 'Tijd die standaard wordt ingevuld in het tijdveld.', 'booking-core' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => '_sbdp_allowed_start_days',
				'label'       => __( 'Toegestane start dagen', 'booking-core' ),
				'value'       => get_post_meta( $product_id, '_sbdp_allowed_start_days', true ),
				'placeholder' => 'Mo,Tu,We',
				'desc_tip'    => true,
				'description' => __( 'Bepaal op welke dagen een boeking kan starten (bijv. Mo,Tu,We). Laat leeg voor alle dagen.', 'booking-core' ),
			)
		);

		echo '</div>';
	}

	/**
	 * Register custom product data tab.
	 */
	public static function register_tabs( array $tabs ): array {
		$tabs['sbdp_booking'] = array(
			'label'    => __( 'Boekingsinstellingen', 'booking-core' ),
			'target'   => 'sbdp_booking_settings',
			'class'    => array( 'show_if_' . self::TYPE ),
			'priority' => 60,
		);

		return $tabs;
	}

	/**
	 * Render settings panel content.
	 */
	public static function render_settings_panel(): void {
		global $post;
		$product_id = $post ? (int) $post->ID : 0;
		?>
		<div id="sbdp_booking_settings" class="panel woocommerce_options_panel hidden">
			<h2><?php esc_html_e( 'Boekingsinstellingen', 'booking-core' ); ?></h2>

			<?php
			woocommerce_wp_text_input(
				array(
					'id'                => '_sbdp_max_bookings_per_unit',
					'label'             => __( 'Maximaal aantal boekingen per unit', 'booking-core' ),
					'type'              => 'number',
					'value'             => get_post_meta( $product_id, '_sbdp_max_bookings_per_unit', true ),
					'custom_attributes' => array(
						'min'  => '0',
						'step' => '1',
					),
					'desc_tip'          => true,
					'description'       => __( 'Zet op 0 voor ongelimiteerd.', 'booking-core' ),
				)
			);
			?>

			<p class="form-field">
				<label><?php esc_html_e( 'Min/Max booking duration', 'booking-core' ); ?></label>
				<span class="wrap">
					<input type="number" class="short" name="_sbdp_min_duration_value" value="<?php echo esc_attr( get_post_meta( $product_id, '_sbdp_min_duration_value', true ) ); ?>" min="0" step="1" placeholder="<?php esc_attr_e( 'Min', 'booking-core' ); ?>" />
					<input type="number" class="short" name="_sbdp_max_duration_value" value="<?php echo esc_attr( get_post_meta( $product_id, '_sbdp_max_duration_value', true ) ); ?>" min="0" step="1" placeholder="<?php esc_attr_e( 'Max', 'booking-core' ); ?>" />
					<span class="description"><?php esc_html_e( 'hour(s)', 'booking-core' ); ?></span>
				</span>
			</p>

			<p class="form-field">
				<label><?php esc_html_e( 'Min/Max advance reservation', 'booking-core' ); ?></label>
				<span class="wrap">
					<input type="number" class="short" name="_sbdp_min_advance_reservation" value="<?php echo esc_attr( get_post_meta( $product_id, '_sbdp_min_advance_reservation', true ) ); ?>" min="0" step="1" placeholder="<?php esc_attr_e( 'Min (uren)', 'booking-core' ); ?>" />
					<input type="number" class="short" name="_sbdp_max_advance_reservation" value="<?php echo esc_attr( get_post_meta( $product_id, '_sbdp_max_advance_reservation', true ) ); ?>" min="0" step="1" placeholder="<?php esc_attr_e( 'Max (uren)', 'booking-core' ); ?>" />
				</span>
				<span class="description"><?php esc_html_e( 'Minimaal en maximaal aantal uren vooraf dat er geboekt kan worden.', 'booking-core' ); ?></span>
			</p>

			<p class="form-field">
				<label><?php esc_html_e( 'Check-in/Check-out time', 'booking-core' ); ?></label>
				<span class="wrap">
					<input type="time" class="short" name="_sbdp_checkin_time" value="<?php echo esc_attr( get_post_meta( $product_id, '_sbdp_checkin_time', true ) ); ?>" />
					<input type="time" class="short" name="_sbdp_checkout_time" value="<?php echo esc_attr( get_post_meta( $product_id, '_sbdp_checkout_time', true ) ); ?>" />
				</span>
			</p>

			<?php
			woocommerce_wp_text_input(
				array(
					'id'                => '_sbdp_buffer_time',
					'label'             => __( 'Buffer tijd (uren)', 'booking-core' ),
					'type'              => 'number',
					'value'             => get_post_meta( $product_id, '_sbdp_buffer_time', true ),
					'custom_attributes' => array(
						'min'  => '0',
						'step' => '0.25',
					),
					'desc_tip'          => true,
					'description'       => __( 'Voorbereidings- of opruimtijd tussen boekingen.', 'booking-core' ),
				)
			);
			?>

			<?php
			woocommerce_wp_checkbox(
				array(
					'id'          => '_sbdp_time_increment_duration',
					'label'       => __( 'Tijdstoename gebaseerd op duur', 'booking-core' ),
					'description' => __( 'Indien ingeschakeld wordt de tijdstoename gelijk aan de duur van de boeking.', 'booking-core' ),
					'cbvalue'     => 'yes',
					'value'       => get_post_meta( $product_id, '_sbdp_time_increment_duration', true ),
				)
			);
			?>

			<?php
			woocommerce_wp_checkbox(
				array(
					'id'          => '_sbdp_requires_confirmation',
					'label'       => __( 'Bevestiging noodzakelijk', 'booking-core' ),
					'description' => __( 'Beheerder moet boekingen bevestigen vóór acceptatie.', 'booking-core' ),
					'cbvalue'     => 'yes',
					'value'       => get_post_meta( $product_id, '_sbdp_requires_confirmation', true ),
				)
			);
			?>

			<?php
			woocommerce_wp_checkbox(
				array(
					'id'          => '_sbdp_allow_cancellation',
					'label'       => __( 'Annulering toestaan', 'booking-core' ),
					'description' => __( 'Sta klanten toe om hun boeking te annuleren.', 'booking-core' ),
					'cbvalue'     => 'yes',
					'value'       => get_post_meta( $product_id, '_sbdp_allow_cancellation', true ),
				)
			);
			?>

			<p class="form-field">
				<label><?php esc_html_e( 'Boeking kan worden geannuleerd tot', 'booking-core' ); ?></label>
				<span class="wrap">
					<input type="number" class="short" name="_sbdp_cancellation_limit_value" value="<?php echo esc_attr( get_post_meta( $product_id, '_sbdp_cancellation_limit_value', true ) ); ?>" min="0" step="1" />
					<select class="select short" name="_sbdp_cancellation_limit_unit">
						<?php
						$unit = get_post_meta( $product_id, '_sbdp_cancellation_limit_unit', true ) ?: 'hours';
						printf( '<option value="hours" %s>%s</option>', selected( 'hours', $unit, false ), esc_html__( 'uren', 'booking-core' ) );
						printf( '<option value="days" %s>%s</option>', selected( 'days', $unit, false ), esc_html__( 'dagen', 'booking-core' ) );
						?>
					</select>
					<span class="description"><?php esc_html_e( 'voor de startdatum.', 'booking-core' ); ?></span>
				</span>
			</p>

			<?php
			woocommerce_wp_text_input(
				array(
					'id'          => '_sbdp_google_maps_location',
					'label'       => __( 'Google Maps locatie', 'booking-core' ),
					'value'       => get_post_meta( $product_id, '_sbdp_google_maps_location', true ),
					'placeholder' => __( 'Bijv. Markt 1, Den Bosch', 'booking-core' ),
					'desc_tip'    => true,
					'description' => __( 'Voer het adres in voor weergave in de [booking_map] shortcode.', 'booking-core' ),
				)
			);
			?>
		</div>
		<?php
	}

	/**
	 * Persist meta values.
	 */
	public static function save_product_meta( WC_Product $product ): void {
		if ( $product->get_type() !== self::TYPE ) {
			return;
		}

		$checkbox_fields = array(
			'_sbdp_time_increment_duration',
			'_sbdp_requires_confirmation',
			'_sbdp_allow_cancellation',
		);

		foreach ( $checkbox_fields as $field ) {
			$value = isset( $_POST[ $field ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$product->update_meta_data( $field, $value );
		}

		$int_fields = array(
			'_sbdp_duration',
			'_sbdp_max_bookings_per_unit',
			'_sbdp_min_duration_value',
			'_sbdp_max_duration_value',
			'_sbdp_min_advance_reservation',
			'_sbdp_max_advance_reservation',
			'_sbdp_cancellation_limit_value',
		);

		foreach ( $int_fields as $field ) {
			$value = isset( $_POST[ $field ] ) && '' !== $_POST[ $field ] ? absint( wp_unslash( $_POST[ $field ] ) ) : '';// phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::maybe_update_meta( $product, $field, $value );
		}

		$buffer_time = isset( $_POST['_sbdp_buffer_time'] ) && '' !== $_POST['_sbdp_buffer_time'] ? floatval( wp_unslash( $_POST['_sbdp_buffer_time'] ) ) : '';// phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::maybe_update_meta( $product, '_sbdp_buffer_time', $buffer_time );

		$duration_unit = isset( $_POST['_sbdp_duration_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['_sbdp_duration_unit'] ) ) : '';// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $duration_unit, array( 'minutes', 'hours', 'days', 'months' ), true ) ) {
			$duration_unit = 'hours';
		}
		$product->update_meta_data( '_sbdp_duration_unit', $duration_unit );

		$cancel_unit = isset( $_POST['_sbdp_cancellation_limit_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['_sbdp_cancellation_limit_unit'] ) ) : 'hours';// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $cancel_unit, array( 'hours', 'days' ), true ) ) {
			$cancel_unit = 'hours';
		}
		$product->update_meta_data( '_sbdp_cancellation_limit_unit', $cancel_unit );

		$text_fields = array(
			'_sbdp_default_start_date',
			'_sbdp_default_start_time',
			'_sbdp_allowed_start_days',
			'_sbdp_checkin_time',
			'_sbdp_checkout_time',
		);

		foreach ( $text_fields as $field ) {
			$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';// phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::maybe_update_meta( $product, $field, $value );
		}

		$maps_location = isset( $_POST['_sbdp_google_maps_location'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_sbdp_google_maps_location'] ) ) : '';// phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::maybe_update_meta( $product, '_sbdp_google_maps_location', $maps_location );
	}

	/**
	 * Helper to update or delete meta when empty.
	 */
	private static function maybe_update_meta( WC_Product $product, string $key, $value ): void {
		if ( '' === $value || null === $value ) {
			$product->delete_meta_data( $key );
			return;
		}

		$product->update_meta_data( $key, $value );
	}
}
