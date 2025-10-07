<?php

declare(strict_types=1);

namespace BSPModule\Core\WooCommerce\ProductType;

use BSPModule\Core\Product\ProductMeta;
use WC_Product;
use WP_Post;

final class BookableServiceProductType {

	public const PRODUCT_TYPE = 'bookable_service';

	private static bool $booted = false;

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_filter( 'product_type_selector', array( __CLASS__, 'register_type_selector' ) );

		if ( did_action( 'init' ) ) {
			self::register_wc_product_class();
		} else {
			add_action( 'init', array( __CLASS__, 'register_wc_product_class' ) );
		}
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_general_section' ) );
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'register_settings_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_settings_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_meta' ) );
	}

	/**
	 * @param array<string,string> $types
	 * @return array<string,string>
	 */
	public static function register_type_selector( array $types ): array {
		$types[ self::PRODUCT_TYPE ] = __( 'Bookable product', 'sbdp' );

		return $types;
	}

	public static function register_wc_product_class(): void {
		if ( class_exists( 'WC_Product_Bookable_Service', false ) ) {
			return;
		}

		if ( ! class_exists( 'WC_Product', false ) ) {
			add_action( 'woocommerce_loaded', array( __CLASS__, 'register_wc_product_class' ), 5 );

			return;
		}

		require_once SBDP_DIR . 'includes/class-wc-product-bookable-service.php';
	}

	public static function render_general_section(): void {
		global $post;

		echo '<div class="options_group show_if_' . esc_attr( self::PRODUCT_TYPE ) . '">';

		woocommerce_wp_text_input(
			array(
				'id'                => '_sbdp_duration',
				'label'             => __( 'Duration (minutes)', 'sbdp' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
			)
		);

		$product_id         = $post ? (int) $post->ID : 0;
		$selected_resources = (array) get_post_meta( $product_id, '_sbdp_resource_ids', true );
		$resources          = self::getResources();

		echo '<p class="form-field">';
		echo '<label for="_sbdp_resource_ids">' . esc_html__( 'Linked resources', 'sbdp' ) . '</label>';
		printf(
			'<select id="_sbdp_resource_ids" name="_sbdp_resource_ids[]" class="wc-enhanced-select" multiple="multiple" style="width:100%%" data-placeholder="%s">',
			esc_attr__( 'Select resources', 'sbdp' )
		);

		foreach ( $resources as $resource ) {
			printf(
				'<option value="%1$s" %3$s>%2$s</option>',
				esc_attr( (string) $resource->ID ),
				esc_html( get_the_title( $resource ) ),
				selected( in_array( $resource->ID, $selected_resources, true ), true, false )
			);
		}

		echo '</select>';
		echo '<span class="description">' . esc_html__( 'Select which resources are available for this service.', 'sbdp' ) . '</span>';
		echo '</p>';
		echo '</div>';
	}

	/**
	 * @param array<string,array> $tabs
	 * @return array<string,array>
	 */
	public static function register_settings_tab( array $tabs ): array {
		$tabs['sbdp_booking'] = array(
			'label'    => __( 'Boekingsinstellingen', 'sbdp' ),
			'target'   => 'sbdp_booking_settings',
			'class'    => array( 'show_if_' . self::PRODUCT_TYPE ),
			'priority' => 60,
		);

		return $tabs;
	}

	public static function render_settings_panel(): void {
		global $post;

		$product_id = $post ? (int) $post->ID : 0;
		?>
		<div id="sbdp_booking_settings" class="panel woocommerce_options_panel hidden">
			<h3><?php esc_html_e( 'Boekingsinstellingen', 'sbdp' ); ?></h3>
			<?php
			woocommerce_wp_select(
				array(
					'id'      => '_sbdp_duration_unit',
					'label'   => __( 'Duration unit', 'sbdp' ),
					'options' => array(
						'minutes' => __( 'Minutes', 'sbdp' ),
						'hours'   => __( 'Hours', 'sbdp' ),
					),
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'          => '_sbdp_default_start_date',
					'label'       => __( 'Default start date', 'sbdp' ),
					'description' => __( 'Fallback start date for scheduler rules (YYYY-MM-DD).', 'sbdp' ),
					'desc_tip'    => true,
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'          => '_sbdp_default_start_time',
					'label'       => __( 'Default start time', 'sbdp' ),
					'description' => __( '24h format, e.g. 10:00.', 'sbdp' ),
					'desc_tip'    => true,
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'          => '_sbdp_allowed_start_days',
					'label'       => __( 'Allowed start days', 'sbdp' ),
					'description' => __( 'Comma separated weekday slugs (mon,tue,wed).', 'sbdp' ),
					'desc_tip'    => true,
				)
			);

			woocommerce_wp_checkbox(
				array(
					'id'          => '_sbdp_enable_people',
					'label'       => __( 'Enable participants field', 'sbdp' ),
					'description' => __( 'Allow customers to specify number of participants per booking.', 'sbdp' ),
					'desc_tip'    => true,
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'                => '_sbdp_min_people',
					'label'             => __( 'Minimum participants', 'sbdp' ),
					'type'              => 'number',
					'custom_attributes' => array( 'min' => 0 ),
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'                => '_sbdp_max_people',
					'label'             => __( 'Maximum participants', 'sbdp' ),
					'type'              => 'number',
					'custom_attributes' => array( 'min' => 0 ),
				)
			);

			woocommerce_wp_checkbox(
				array(
					'id'          => '_sbdp_people_as_bookings',
					'label'       => __( 'Count participants as bookings', 'sbdp' ),
					'description' => __( 'When enabled, each participant consumes a single availability slot.', 'sbdp' ),
					'desc_tip'    => true,
				)
			);

			woocommerce_wp_checkbox(
				array(
					'id'          => '_sbdp_enable_person_types',
					'label'       => __( 'Enable person types', 'sbdp' ),
					'description' => __( 'Expose child/adult or other person-type segmentation.', 'sbdp' ),
					'desc_tip'    => true,
				)
			);

			echo '<hr><h3>' . esc_html__( 'Pricing & Costs', 'sbdp' ) . '</h3>';

			woocommerce_wp_text_input(
				array(
					'id'    => '_sbdp_base_price',
					'label' => __( 'Base price (2 hours)', 'sbdp' ),
					'type'  => 'price',
				)
			);

			woocommerce_wp_checkbox(
				array(
					'id'    => '_sbdp_price_per_person',
					'label' => __( 'Multiply by participants', 'sbdp' ),
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'    => '_sbdp_base_fee',
					'label' => __( 'Fixed base fee', 'sbdp' ),
					'type'  => 'price',
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'    => '_sbdp_last_minute_discount',
					'label' => __( 'Last-minute discount (%)', 'sbdp' ),
					'type'  => 'number',
				)
			);

			echo '<p class="form-field"><label for="_sbdp_extra_costs">' . esc_html__( 'Extra costs', 'sbdp' ) . '</label>';
			printf(
				'<textarea id="_sbdp_extra_costs" name="_sbdp_extra_costs" rows="4" style="width:100%%">%s</textarea>',
				esc_textarea( (string) get_post_meta( $product_id, '_sbdp_extra_costs', true ) )
			);
			echo '</p>';

			echo '<hr><h3>' . esc_html__( 'Availability', 'sbdp' ) . '</h3>';

			woocommerce_wp_text_input(
				array(
					'id'          => '_sbdp_default_hours',
					'label'       => __( 'Default availability (e.g. 10:00-18:00)', 'sbdp' ),
					'placeholder' => '10:00-18:00',
				)
			);

			echo '<p class="form-field"><label for="_sbdp_availability_rules">' . esc_html__( 'Additional rules', 'sbdp' ) . '</label>';
			printf(
				'<textarea id="_sbdp_availability_rules" name="_sbdp_availability_rules" rows="4" style="width:100%%">%s</textarea>',
				esc_textarea( (string) get_post_meta( $product_id, '_sbdp_availability_rules', true ) )
			);
			echo '<span class="description">' . esc_html__( 'JSON or line-based rules for additional availability logic.', 'sbdp' ) . '</span>';
			echo '</p>';

			echo '<hr><h3>' . esc_html__( 'Planner labels', 'sbdp' ) . '</h3>';

			woocommerce_wp_textarea_input(
				array(
					'id'          => '_sbdp_label_map_start',
					'label'       => __( 'Start label translations', 'sbdp' ),
					'description' => __( 'Format: nl_NL=Starttijd | en_US=Start time', 'sbdp' ),
					'desc_tip'    => true,
				)
			);

			woocommerce_wp_textarea_input(
				array(
					'id'          => '_sbdp_label_map_end',
					'label'       => __( 'End label translations', 'sbdp' ),
					'description' => __( 'Format: nl_NL=Eindtijd | en_US=End time', 'sbdp' ),
					'desc_tip'    => true,
				)
			);

			woocommerce_wp_textarea_input(
				array(
					'id'          => '_sbdp_label_map_participants',
					'label'       => __( 'Participants label translations', 'sbdp' ),
					'description' => __( 'Format: nl_NL=Deelnemers | en_US=Participants', 'sbdp' ),
					'desc_tip'    => true,
				)
			);

			woocommerce_wp_textarea_input(
				array(
					'id'          => '_sbdp_label_map_resource',
					'label'       => __( 'Resource label translations', 'sbdp' ),
					'description' => __( 'Format: nl_NL=Resource | en_US=Resource', 'sbdp' ),
					'desc_tip'    => true,
				)
			);
			?>
		</div>
		<?php
	}

	public static function save_product_meta( WC_Product $product ): void {
		if ( $product->get_type() !== self::PRODUCT_TYPE ) {
			return;
		}

		$fields = array(
			'_sbdp_duration',
			'_sbdp_duration_unit',
			'_sbdp_default_start_date',
			'_sbdp_default_start_time',
			'_sbdp_allowed_start_days',
			'_sbdp_enable_people',
			'_sbdp_min_people',
			'_sbdp_max_people',
			'_sbdp_people_as_bookings',
			'_sbdp_enable_person_types',
			'_sbdp_base_price',
			'_sbdp_price_per_person',
			'_sbdp_base_fee',
			'_sbdp_last_minute_discount',
			'_sbdp_extra_costs',
			'_sbdp_default_hours',
			'_sbdp_availability_rules',
			'_sbdp_label_map_start',
			'_sbdp_label_map_end',
			'_sbdp_label_map_participants',
			'_sbdp_label_map_resource',
		);

		foreach ( $fields as $key ) {
			$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			$product->update_meta_data( $key, wc_clean( $value ) );
		}

		$raw_resource_ids = isset( $_POST['_sbdp_resource_ids'] ) ? wp_unslash( $_POST['_sbdp_resource_ids'] ) : array();

		if ( class_exists( ProductMeta::class ) ) {
			$resource_ids = ProductMeta::sanitize_resource_ids( $raw_resource_ids );
		} elseif ( class_exists( 'SBDP_Product_Meta' ) ) {
			$resource_ids = \SBDP_Product_Meta::sanitize_resource_ids( $raw_resource_ids );
		} else {
			$resource_ids = array();
		}

		if ( ! empty( $resource_ids ) ) {
			$product->update_meta_data( '_sbdp_resource_ids', $resource_ids );
			$product->update_meta_data( '_sbdp_resource_id', (int) $resource_ids[0] );
		} else {
			$product->delete_meta_data( '_sbdp_resource_ids' );
			$product->delete_meta_data( '_sbdp_resource_id' );
		}
	}

	/**
	 * @return WP_Post[]
	 */
	private static function getResources(): array {
		$posts = get_posts(
			array(
				'post_type'      => 'bookable_resource',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( ! is_array( $posts ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$posts,
				static fn( $post ): bool => $post instanceof WP_Post
			)
		);
	}
}

