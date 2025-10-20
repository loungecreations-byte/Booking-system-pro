<?php
/**
 * WooCommerce product type integration.
 *
 * @package SBDP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBDP_Product_Type {

    const PRODUCT_TYPE = 'bookable_service';

    /**
     * Bootstrap hooks.
     */
    public static function init() {
        add_filter( 'product_type_selector', [ __CLASS__, 'register_type_selector' ] );
        add_action( 'init', [ __CLASS__, 'register_wc_product_class' ] );
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'render_general_section' ] );
        add_filter( 'woocommerce_product_data_tabs', [ __CLASS__, 'register_settings_tab' ] );
        add_action( 'woocommerce_product_data_panels', [ __CLASS__, 'render_settings_panel' ] );
        add_action( 'woocommerce_admin_process_product_object', [ __CLASS__, 'save_product_meta' ] );
    }

    /**
     * Add our product type to the selector dropdown.
     *
     * @param array<string,string> $types Existing types.
     *
     * @return array<string,string>
     */
    public static function register_type_selector( $types ) {
        $types[ self::PRODUCT_TYPE ] = __( 'Bookable product', 'sbdp' );
        return $types;
    }

    /**
     * Ensure the WooCommerce product class exists.
     */
    public static function register_wc_product_class() {
        if ( class_exists( 'WC_Product_Bookable_Service', false ) ) {
            return;
        }

        if ( ! class_exists( 'WC_Product', false ) ) {
            add_action( 'woocommerce_loaded', [ __CLASS__, 'register_wc_product_class' ], 5 );
            return;
        }

        require_once __DIR__ . '/class-wc-product-bookable-service.php';
    }

    /**
     * Render the general section additions.
     */
    public static function render_general_section() {
        global $post;

        echo '<div class="options_group show_if_' . esc_attr( self::PRODUCT_TYPE ) . '">';

        woocommerce_wp_text_input(
            [
                'id'                => '_sbdp_duration',
                'label'             => __( 'Duration (minutes)', 'sbdp' ),
                'type'              => 'number',
                'custom_attributes' => [ 'min' => 0 ],
            ]
        );

        $product_id         = $post ? (int) $post->ID : 0;
        $selected_resources = (array) get_post_meta( $product_id, '_sbdp_resource_ids', true );
        $resources          = self::get_resources();

        echo '<p class="form-field">';
        echo '<label for="_sbdp_resource_ids">' . esc_html__( 'Linked resources', 'sbdp' ) . '</label>';
        printf(
            '<select id="_sbdp_resource_ids" name="_sbdp_resource_ids[]" class="wc-enhanced-select" multiple="multiple" style="width:100%%" data-placeholder="%s">',
            esc_attr__( 'Select resources', 'sbdp' )
        );

        foreach ( $resources as $resource ) {
            printf(
                '<option value="%1$s" %3$s>%2$s</option>',
                esc_attr( $resource->ID ),
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
     * Register a dedicated settings tab for booking options.
     *
     * @param array<string,array> $tabs Existing tabs.
     *
     * @return array<string,array>
     */
    public static function register_settings_tab( $tabs ) {
        $tabs['sbdp_booking'] = [
            'label'    => __( 'Boekingsinstellingen', 'sbdp' ),
            'target'   => 'sbdp_booking_settings',
            'class'    => [ 'show_if_' . self::PRODUCT_TYPE ],
            'priority' => 60,
        ];
        return $tabs;
    }

    /**
     * Render the product data panel.
     */
    public static function render_settings_panel() {
        global $post;
        $product_id = $post ? (int) $post->ID : 0;
        ?>
        <div id="sbdp_booking_settings" class="panel woocommerce_options_panel hidden">
            <h3><?php esc_html_e( 'Boekingsinstellingen', 'sbdp' ); ?></h3>
            <?php
            woocommerce_wp_select(
                [
                    'id'      => '_sbdp_duration_unit',
                    'label'   => __( 'Boekingsduur eenheid', 'sbdp' ),
                    'options' => [
                        'minutes' => __( 'Minuten', 'sbdp' ),
                        'hours'   => __( 'Uren', 'sbdp' ),
                        'days'    => __( 'Dagen', 'sbdp' ),
                        'months'  => __( 'Maanden', 'sbdp' ),
                    ],
                ]
            );
            woocommerce_wp_text_input( [ 'id' => '_sbdp_default_start_date', 'label' => __( 'Default startdatum', 'sbdp' ), 'placeholder' => 'YYYY-MM-DD' ] );
            woocommerce_wp_text_input( [ 'id' => '_sbdp_default_start_time', 'label' => __( 'Default starttijd', 'sbdp' ), 'placeholder' => 'HH:MM' ] );
            woocommerce_wp_text_input( [
                'id'          => '_sbdp_allowed_start_days',
                'label'       => __( 'Toegestane startdagen', 'sbdp' ),
                'placeholder' => 'Mo,Tu,We,...',
                'description' => __( 'Comma separated list of weekdays using two-letter abbreviations.', 'sbdp' ),
                'desc_tip'    => true,
            ] );

            echo '<hr><h3>' . esc_html__( 'People settings', 'sbdp' ) . '</h3>';
            woocommerce_wp_checkbox( [ 'id' => '_sbdp_enable_people', 'label' => __( 'Inschakelen personen optie', 'sbdp' ) ] );
            woocommerce_wp_text_input( [ 'id' => '_sbdp_min_people', 'label' => __( 'Min personen', 'sbdp' ), 'type' => 'number', 'custom_attributes' => [ 'min' => 0 ] ] );
            woocommerce_wp_text_input( [ 'id' => '_sbdp_max_people', 'label' => __( 'Max personen', 'sbdp' ), 'type' => 'number', 'custom_attributes' => [ 'min' => 0 ] ] );
            woocommerce_wp_checkbox( [ 'id' => '_sbdp_people_as_bookings', 'label' => __( 'Tel personen als aparte boekingen', 'sbdp' ) ] );
            woocommerce_wp_checkbox( [ 'id' => '_sbdp_enable_person_types', 'label' => __( 'Type personen inschakelen', 'sbdp' ) ] );

            echo '<hr><h3>' . esc_html__( 'Prijzen & Kosten', 'sbdp' ) . '</h3>';
            woocommerce_wp_text_input( [ 'id' => '_sbdp_base_price', 'label' => __( 'Basisprijs (2 uur)', 'sbdp' ), 'type' => 'price' ] );
            woocommerce_wp_checkbox( [ 'id' => '_sbdp_price_per_person', 'label' => __( 'Vermenigvuldig met aantal personen', 'sbdp' ) ] );
            woocommerce_wp_text_input( [ 'id' => '_sbdp_base_fee', 'label' => __( 'Vaste basis kosten', 'sbdp' ), 'type' => 'price' ] );
            woocommerce_wp_text_input( [ 'id' => '_sbdp_last_minute_discount', 'label' => __( 'Last minute korting (%)', 'sbdp' ), 'type' => 'number' ] );
            echo '<p class="form-field"><label for="_sbdp_extra_costs">' . esc_html__( 'Extra kosten', 'sbdp' ) . '</label>';
            printf(
                '<textarea id="_sbdp_extra_costs" name="_sbdp_extra_costs" rows="4" style="width:100%%">%s</textarea>',
                esc_textarea( get_post_meta( $product_id, '_sbdp_extra_costs', true ) )
            );
            echo '</p>';

            echo '<hr><h3>' . esc_html__( 'Beschikbaarheid', 'sbdp' ) . '</h3>';
            woocommerce_wp_text_input( [ 'id' => '_sbdp_default_hours', 'label' => __( 'Standaard beschikbaarheid (bijv 10:00-18:00)', 'sbdp' ), 'placeholder' => '10:00-18:00' ] );
            echo '<p class="form-field"><label for="_sbdp_availability_rules">' . esc_html__( 'Aanvullende regels', 'sbdp' ) . '</label>';
            printf(
                '<textarea id="_sbdp_availability_rules" name="_sbdp_availability_rules" rows="4" style="width:100%%">%s</textarea>',
                esc_textarea( get_post_meta( $product_id, '_sbdp_availability_rules', true ) )
            );
            echo '<span class="description">' . esc_html__( 'JSON or line-based rules for additional availability logic.', 'sbdp' ) . '</span>';
            echo '</p>';

            echo '<hr><h3>' . esc_html__( 'Planner labels', 'sbdp' ) . '</h3>';
            woocommerce_wp_textarea_input( [
                'id'          => '_sbdp_label_map_start',
                'label'       => __( 'Start label vertalingen', 'sbdp' ),
                'description' => __( 'Gebruik het formaat: nl_NL=Starttijd | en_US=Start time', 'sbdp' ),
                'desc_tip'    => true,
            ] );
            woocommerce_wp_textarea_input( [
                'id'          => '_sbdp_label_map_end',
                'label'       => __( 'Einde label vertalingen', 'sbdp' ),
                'description' => __( 'Gebruik het formaat: nl_NL=Eindtijd | en_US=End time', 'sbdp' ),
                'desc_tip'    => true,
            ] );
            woocommerce_wp_textarea_input( [
                'id'          => '_sbdp_label_map_participants',
                'label'       => __( 'Deelnemers label vertalingen', 'sbdp' ),
                'description' => __( 'Gebruik het formaat: nl_NL=Deelnemers | en_US=Participants', 'sbdp' ),
                'desc_tip'    => true,
            ] );
            woocommerce_wp_textarea_input( [
                'id'          => '_sbdp_label_map_resource',
                'label'       => __( 'Resource label vertalingen', 'sbdp' ),
                'description' => __( 'Gebruik het formaat: nl_NL=Resource | en_US=Resource', 'sbdp' ),
                'desc_tip'    => true,
            ] );
            ?>
        </div>
        <?php
    }

    /**
     * Persist product metadata on save.
     *
     * @param WC_Product $product Product instance.
     */
    public static function save_product_meta( $product ) {
        if ( $product->get_type() !== self::PRODUCT_TYPE ) {
            return;
        }

        $fields = [
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
        ];

        foreach ( $fields as $key ) {
            $value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';// phpcs:ignore WordPress.Security.NonceVerification.Missing
            $product->update_meta_data( $key, wc_clean( $value ) );
        }

        $resource_ids = isset( $_POST['_sbdp_resource_ids'] ) ? SBDP_Product_Meta::sanitize_resource_ids( wp_unslash( $_POST['_sbdp_resource_ids'] ) ) : [];// phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! empty( $resource_ids ) ) {
            $product->update_meta_data( '_sbdp_resource_ids', $resource_ids );
            $product->update_meta_data( '_sbdp_resource_id', (int) $resource_ids[0] );
        } else {
            $product->delete_meta_data( '_sbdp_resource_ids' );
            $product->delete_meta_data( '_sbdp_resource_id' );
        }
    }

    /**
     * Fetch published resources for the selector.
     *
     * @return WP_Post[]
     */
    private static function get_resources() {
        return get_posts(
            [
                'post_type'      => 'bookable_resource',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]
        );
    }
}

