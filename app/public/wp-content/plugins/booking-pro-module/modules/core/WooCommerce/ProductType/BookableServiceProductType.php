<?php

declare(strict_types=1);

namespace BSPModule\Core\WooCommerce\ProductType;

use BSPModule\Core\Product\ProductMeta;
use BSPModule\Core\Resource\ResourceMeta;
use WC_Product;
use WP_Post;

final class BookableServiceProductType {

	public const PRODUCT_TYPE = 'bookable_service';

	private static bool $booted = false;

	private const SEGMENTS = array(
		'corporate' => 'Bedrijfsuitje & Teamuitje',
		'school'    => 'School & Jeugd',
		'friends'   => 'Familie & Vrienden',
		'romantic'  => 'Romantisch & Duo',
		'kids'      => 'Kinderfeest',
		'solo'      => 'Individueel',
	);

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_filter( 'product_type_selector', array( __CLASS__, 'register_type_selector' ) );
		add_action( 'woocommerce_add_to_cart_handler_' . self::PRODUCT_TYPE, array( __CLASS__, 'handle_add_to_cart_request' ) );

		if ( did_action( 'init' ) ) {
			self::register_wc_product_class();
		} else {
			add_action( 'init', array( __CLASS__, 'register_wc_product_class' ) );
		}
		if ( self::use_legacy_admin_forms() ) {
			add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_general_section' ) );
			add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'register_settings_tab' ) );
			add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_settings_panel' ) );
			add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_meta' ) );
		}
	}

	/**
	 * @param array<string,string> $types
	 * @return array<string,string>
	 */
	public static function register_type_selector( array $types ): array {
		$types[ self::PRODUCT_TYPE ] = __( 'Bookable product', 'sbdp' );

		return $types;
	}

	/**
	 * Handle product-page add-to-cart posts for bookable_service products.
	 *
	 * WooCommerce only redirects after its built-in handlers return a success flag.
	 * Custom type handlers are action-based, so this method must perform the cart
	 * add itself while keeping the normal validation and cart-item-data filters as
	 * the source of booking truth.
	 *
	 * @param string|false $url Redirect URL from WooCommerce form handler.
	 */
	public static function handle_add_to_cart_request( $url = false ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'De winkelwagen is niet beschikbaar. Probeer het opnieuw.', 'sbdp' ), 'error' );
			}

			return;
		}

		$product_id = isset( $_REQUEST['add-to-cart'] )
			? absint( wp_unslash( $_REQUEST['add-to-cart'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 0;
		$product = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof WC_Product || $product->get_type() !== self::PRODUCT_TYPE ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'Deze activiteit kon niet worden gevonden.', 'sbdp' ), 'error' );
			}

			return;
		}

		$quantity = self::resolve_add_to_cart_quantity();
		$passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity );

		if ( ! $passed_validation ) {
			if ( function_exists( 'wc_notice_count' ) && function_exists( 'wc_add_notice' ) && wc_notice_count( 'error' ) === 0 ) {
				wc_add_notice( __( 'Deze activiteit mist datum, tijdslot of deelnemers en kan niet aan de winkelwagen worden toegevoegd.', 'sbdp' ), 'error' );
			}

			return;
		}

		$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity );
		if ( false === $cart_item_key ) {
			if ( function_exists( 'wc_notice_count' ) && function_exists( 'wc_add_notice' ) && wc_notice_count( 'error' ) === 0 ) {
				wc_add_notice( __( 'Deze activiteit kon niet aan de winkelwagen worden toegevoegd. Controleer datum, tijdslot en deelnemers.', 'sbdp' ), 'error' );
			}

			return;
		}

		if ( function_exists( 'wc_add_to_cart_message' ) ) {
			wc_add_to_cart_message( array( $product_id => $quantity ), true );
		}

		do_action( 'internal_woocommerce_cart_item_added_from_user_request', $product_id, $quantity );

		if ( function_exists( 'wc_notice_count' ) && wc_notice_count( 'error' ) > 0 ) {
			return;
		}

		$redirect = apply_filters( 'woocommerce_add_to_cart_redirect', $url, $product );
		if ( $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) && function_exists( 'wc_get_cart_url' ) ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
	}

	private static function resolve_add_to_cart_quantity(): int {
		$candidates = array(
			$_REQUEST['quantity'] ?? null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$_REQUEST['sbdp_summary_participants'] ?? null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$_REQUEST['sbdp_participants'] ?? null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);

		foreach ( $candidates as $candidate ) {
			if ( null === $candidate || is_array( $candidate ) ) {
				continue;
			}

			$quantity = function_exists( 'wc_stock_amount' )
				? wc_stock_amount( wp_unslash( $candidate ) )
				: (int) wp_unslash( $candidate );
			if ( $quantity > 0 ) {
				return (int) $quantity;
			}
		}

		return 1;
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

		$product_id   = $post ? (int) $post->ID : 0;
		$raw_duration = (int) get_post_meta( $product_id, '_sbdp_duration', true );
		$duration     = $raw_duration > 0 ? $raw_duration : 90;

		woocommerce_wp_text_input(
			array(
				'id'                => '_sbdp_duration',
				'label'             => __( 'Duration (minutes)', 'sbdp' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
				'value'             => $duration,
				'description'       => __( 'Geef de standaardduur in minuten op. Laat leeg voor de standaardwaarde van 90 minuten.', 'sbdp' ),
				'desc_tip'          => true,
			)
		);

		$selected_resources = (array) get_post_meta( $product_id, '_sbdp_resource_ids', true );
		$resources          = self::getResources();

		echo '<p class="form-field">';
		echo '<label for="_sbdp_resource_ids">' . esc_html__( 'Linked resources', 'sbdp' ) . '</label>';
		printf(
			'<select id="_sbdp_resource_ids" name="_sbdp_resource_ids[]" class="wc-enhanced-select" multiple="multiple" style="width:100%%" data-placeholder="%s">',
			esc_attr__( 'Select resources', 'sbdp' )
		);

		foreach ( $resources as $resource ) {
			$resource_label = ResourceMeta::get_admin_label( (int) $resource->ID );
			printf(
				'<option value="%1$s" %3$s>%2$s</option>',
				esc_attr( (string) $resource->ID ),
				esc_html( $resource_label ),
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
		$tax_class    = $product_id > 0 ? (string) get_post_meta( $product_id, '_tax_class', true ) : '';
		$tax_classes  = \WC_Tax::get_tax_classes();
		$tax_options  = array( '' => __( 'Standaard (volgens winkelinstellingen)', 'sbdp' ) );
		foreach ( $tax_classes as $class ) {
			$tax_options[ $class ] = $class;
		}
		?>
		<div id="sbdp_booking_settings" class="panel woocommerce_options_panel hidden">
			<h3><?php esc_html_e( 'Boekingsinstellingen', 'sbdp' ); ?></h3>
			<?php
			woocommerce_wp_select(
				array(
					'id'      => '_tax_class',
					'label'   => __( 'Tax class', 'sbdp' ),
					'options' => $tax_options,
					'value'   => $tax_class,
					'desc_tip'=> true,
					'description' => __( 'Kies 21% of 9% (of een aangepaste class) voor dit bookable product.', 'sbdp' ),
					'class'   => 'select short',
				)
			);

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

			$combi_deals   = array_map( 'intval', (array) get_post_meta( $product_id, '_sbdp_combi_deals', true ) );
			$combi_choices = self::get_bookable_products( $product_id );

			echo '<p class="form-field">';
			echo '<label for="_sbdp_combi_deals">' . esc_html__( 'Combinatie deals', 'sbdp' ) . '</label>';
			printf(
				'<select id="_sbdp_combi_deals" name="_sbdp_combi_deals[]" class="wc-enhanced-select" multiple="multiple" style="width:100%%" data-placeholder="%s">',
				esc_attr__( 'Selecteer aanvullende producten', 'sbdp' )
			);

			foreach ( $combi_choices as $choice_id => $choice_label ) {
				printf(
					'<option value="%1$s" %3$s>%2$s</option>',
					esc_attr( (string) $choice_id ),
					esc_html( $choice_label ),
					selected( in_array( $choice_id, $combi_deals, true ), true, false )
				);
			}

			echo '</select>';
			echo '<span class="description">' . esc_html__( 'Koppel aanvullende activiteiten als combi-deal om upsells op de productpagina te tonen.', 'sbdp' ) . '</span>';
			echo '</p>';

			$selected_segments = array_map(
				'sanitize_key',
				(array) get_post_meta( $product_id, '_sbdp_segments', true )
			);

			echo '<p class="form-field">';
			echo '<label for="_sbdp_segments">' . esc_html__( 'Segment doelgroepen', 'sbdp' ) . '</label>';
			printf(
				'<select id="_sbdp_segments" name="_sbdp_segments[]" class="wc-enhanced-select" multiple="multiple" style="width:100%%" data-placeholder="%s">',
				esc_attr__( 'Selecteer één of meerdere segmenten', 'sbdp' )
			);

			foreach ( self::SEGMENTS as $segment_key => $segment_label ) {
				printf(
					'<option value="%1$s" %3$s>%2$s</option>',
					esc_attr( $segment_key ),
					esc_html( $segment_label ),
					selected( in_array( $segment_key, $selected_segments, true ), true, false )
				);
			}

			echo '</select>';
			echo '<span class="description">' . esc_html__( 'Gebruik segmenten om filters in de planner en productpagina te voeden.', 'sbdp' ) . '</span>';
			echo '</p>';

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
		if ( ! self::use_legacy_admin_forms() ) {
			return;
		}

		if ( $product->get_type() !== self::PRODUCT_TYPE ) {
			return;
		}

		$post_data = \filter_input_array( \INPUT_POST, \FILTER_UNSAFE_RAW ) ?: array();
		$post_data = is_array( $post_data ) ? wp_unslash( $post_data ) : array();
		$bookable_payload = isset( $post_data['sbdp_bookable'] ) && is_array( $post_data['sbdp_bookable'] )
			? $post_data['sbdp_bookable']
			: array();

		$tax_class = isset( $post_data['_tax_class'] ) ? sanitize_text_field( $post_data['_tax_class'] ) : '';
		if ( empty( $tax_class ) && isset( $post_data['sbdp_bookable']['tax_class'] ) ) {
			$tax_class = sanitize_text_field( $post_data['sbdp_bookable']['tax_class'] );
		}
		$product->set_tax_status( 'taxable' );
		$product->set_tax_class( $tax_class );

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
            $value = self::resolve_legacy_field_value( $key, $post_data, $bookable_payload );
            if ( $value === self::LEGACY_FIELD_SKIP ) {
                continue;
            }
            $product->update_meta_data( $key, wc_clean( $value ) );
        }

        $raw_resource_ids = $post_data['_sbdp_resource_ids'] ?? array();

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

		$raw_combi = $post_data['_sbdp_combi_deals'] ?? array();
		$bookable_combi = $post_data['sbdp_bookable']['combi_deals'] ?? array();
		$merged_combi = array_merge(
			is_array($raw_combi) ? $raw_combi : array($raw_combi),
			is_array($bookable_combi) ? $bookable_combi : array($bookable_combi)
		);
		$combi_ids = array_values(
			array_filter(
				array_map(
					static fn( $value ) => (int) $value,
					$merged_combi
				),
				static fn( int $value ): bool => $value > 0
			)
		);

		if ( ! empty( $combi_ids ) ) {
			$product->update_meta_data( '_sbdp_combi_deals', $combi_ids );
		} else {
			$product->delete_meta_data( '_sbdp_combi_deals' );
		}

		$raw_segments = $post_data['_sbdp_segments'] ?? array();
		$segments     = array_values(
			array_filter(
				array_map(
					static fn( $value ) => sanitize_key( $value ),
					is_array( $raw_segments ) ? $raw_segments : array( $raw_segments )
				),
				static fn( $value ): bool => $value !== ''
			)
		);

		if ( ! empty( $segments ) ) {
			$product->update_meta_data( '_sbdp_segments', $segments );
		} else {
			$product->delete_meta_data( '_sbdp_segments' );
		}
	}

	private static function use_legacy_admin_forms(): bool {
		return (bool) apply_filters( 'sbdp_use_legacy_bookable_product_panel', false );
	}

	private const LEGACY_FIELD_SKIP = '__sbdp_skip_legacy_field__';

	/**
	 * Prevent the legacy Woo panel saver from blanking values that were posted via the
	 * newer `sbdp_bookable[...]` meta box contract.
	 *
	 * @param array<string, mixed> $post_data
	 * @param array<string, mixed> $bookable_payload
	 * @return mixed
	 */
	private static function resolve_legacy_field_value( string $key, array $post_data, array $bookable_payload ) {
		if ( array_key_exists( $key, $post_data ) ) {
			return $post_data[ $key ];
		}

		if ( $bookable_payload === array() ) {
			return '';
		}

		$map = array(
			'_sbdp_duration'             => 'booking_min_duration',
			'_sbdp_duration_unit'        => 'booking_duration_type',
			'_sbdp_default_start_date'   => 'booking_default_start_date',
			'_sbdp_default_start_time'   => 'booking_default_start_time',
			'_sbdp_allowed_start_days'   => 'booking_allowed_start_days',
			'_sbdp_enable_people'        => 'people_enabled',
			'_sbdp_min_people'           => 'people_min',
			'_sbdp_max_people'           => 'people_max',
			'_sbdp_people_as_bookings'   => 'people_count_as_booking',
			'_sbdp_enable_person_types'  => 'people_type_enabled',
			'_sbdp_base_price'           => 'base_price',
			'_sbdp_base_fee'             => 'fixed_fee',
			'_sbdp_last_minute_discount' => 'last_minute_discount',
			'_sbdp_extra_costs'          => 'extra_costs',
			'_sbdp_default_hours'        => 'default_availability',
			'_sbdp_availability_rules'   => 'additional_rules',
		);

		if ( ! array_key_exists( $key, $map ) ) {
			return self::LEGACY_FIELD_SKIP;
		}

		$payload_key = $map[ $key ];
		if ( ! array_key_exists( $payload_key, $bookable_payload ) ) {
			return self::LEGACY_FIELD_SKIP;
		}

		$value = $bookable_payload[ $payload_key ];

		if ( in_array( $key, array( '_sbdp_default_hours', '_sbdp_availability_rules', '_sbdp_extra_costs' ), true ) ) {
			return wp_json_encode( $value );
		}

		if ( in_array( $key, array( '_sbdp_enable_people', '_sbdp_people_as_bookings', '_sbdp_enable_person_types' ), true ) ) {
			return ! empty( $value ) ? 'yes' : 'no';
		}

		if ( $key === '_sbdp_allowed_start_days' && is_array( $value ) ) {
			return array_values( array_map( 'sanitize_text_field', $value ) );
		}

		return $value;
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

	/**
	 * @return array<int,string>
	 */
	private static function get_bookable_products( int $exclude_id = 0 ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$products = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => -1,
				'type'   => self::PRODUCT_TYPE,
				'exclude'=> $exclude_id > 0 ? array( $exclude_id ) : array(),
				'orderby'=> 'title',
				'order'  => 'ASC',
				'return' => 'objects',
			)
		);

		if ( ! is_array( $products ) || empty( $products ) ) {
			return array();
		}

		$choices = array();

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$product_id = $product->get_id();
			$title      = $product->get_name();
			if ( ! $title ) {
				continue;
			}

			$price = $product->get_price_html();
			if ( $price ) {
				/* translators: %1$s: product title, %2$s: formatted price */
				$title = sprintf( __( '%1$s — %2$s', 'sbdp' ), $title, wp_strip_all_tags( $price ) );
			}

			$choices[ (int) $product_id ] = $title;
		}

		return $choices;
	}
}
