<?php

declare(strict_types=1);

namespace BSPModule\Core\Admin;

use BSPModule\Core\Admin\AdminMenu;
use BSPModule\Core\WooCommerce\ProductType\BookableServiceProductType;
use SBDP\Admin\Bookable\SBDP_Admin_Bookable_Meta;
use WP_Error;
use WC_Product;

/**
 * Booking suite setup helper.
 */
final class SetupWizard
{
	private const ACTION = 'sbdp_booking_suite_setup';

	/**
	 * Register hooks.
	 */
    public static function init(): void
    {
        if (! is_admin()) {
            return;
        }

        add_action('admin_menu', [__CLASS__, 'register_page']);
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle_post']);
    }

	/**
	 * Register the menu item beneath Bookings.
	 */
	public static function register_page(): void
	{
        if (! function_exists('add_submenu_page')) {
            return;
        }

        add_submenu_page(
            'sbdp_bookings',
            esc_html__('Booking Suite Setup', 'sbdp'),
            esc_html__('Booking Suite Setup', 'sbdp'),
            AdminMenu::capability(),
            'sbdp_booking_suite',
            [__CLASS__, 'render_page']
		);
	}

	/**
	 * Return preset configuration.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function get_presets(): array
	{
		return array(
			'dagje_den_bosch'      => array(
				'label'        => __( 'Dagje Den Bosch', 'sbdp' ),
				'description'  => __( 'Compleet dagarrangement met gids en e-chopper, ideaal voor groepen van 10+ personen.', 'sbdp' ),
				'pricing'      => array(
					'regular_price' => '89',
					'price'         => '89',
				),
				'meta'         => array(
					'booking_duration_type'        => 'day',
					'booking_min_duration'         => 1,
					'booking_max_duration'         => 1,
					'booking_time_increment_based' => false,
					'booking_requires_confirmation'=> false,
					'booking_allow_cancellation'   => true,
					'booking_buffer_time'          => 0,
					'booking_default_start_time'   => '09:00',
					'booking_checkin'              => '09:00',
					'booking_checkout'             => '21:00',
					'booking_allowed_start_days'   => array( 'fri', 'sat', 'sun' ),
					'people_enabled'               => true,
					'people_count_as_booking'      => false,
					'people_type_enabled'          => true,
					'people_min'                   => 1,
					'people_max'                   => 10,
					'people_types'                 => array(
						array(
							'label' => __( 'Volwassene (18+)', 'sbdp' ),
							'price' => 0.0,
						),
						array(
							'label' => __( 'Kind (t/m 12 jaar)', 'sbdp' ),
							'price' => -20.0,
						),
						array(
							'label' => __( '2-persoons kano', 'sbdp' ),
							'price' => 50.0,
						),
					),
					'base_price'                   => 89.0,
					'base_price_per_person'        => false,
					'fixed_fee'                    => 15.0,
					'fixed_fee_per_person'         => false,
					'extra_costs'                  => array(
						array(
							'label'       => __( 'Boekingskosten', 'sbdp' ),
							'amount'      => 4.95,
							'multiply_by' => 'booking',
						),
					),
					'last_minute_discount'         => 5.0,
					'last_minute_days_before'      => 2,
				),
				'availability' => self::build_default_availability(),
				'additional_rules' => self::build_additional_rules(),
				'price_rules' => self::build_price_rules(),
				'resources'   => self::get_default_resources_config(),
				'highlights'  => array(
					__( 'Weekendbeschikbaarheid met vaste tijdsloten', 'sbdp' ),
					__( 'Gids Tiny en e-chopper set worden automatisch klaargezet', 'sbdp' ),
					__( 'Laatste-minuut korting van 5% (48 uur)', 'sbdp' ),
				),
			),
			'binnendieze_avondtour' => array(
				'label'        => __( 'Binnendieze Avondtour', 'sbdp' ),
				'description'  => __( 'Sfeervolle avondvaart met gids en beperkte capaciteit. Inclusief avondtoeslag en aangepaste beschikbaarheid.', 'sbdp' ),
				'pricing'      => array(
					'regular_price' => '59',
					'price'         => '59',
				),
				'meta'         => array(
					'booking_duration_type'        => 'hours',
					'booking_min_duration'         => 2,
					'booking_max_duration'         => 2,
					'booking_time_increment_based' => true,
					'booking_requires_confirmation'=> true,
					'booking_allow_cancellation'   => false,
					'booking_buffer_time'          => 1,
					'booking_default_start_time'   => '19:00',
					'booking_checkin'              => '18:30',
					'booking_checkout'             => '22:30',
					'booking_allowed_start_days'   => array( 'thu', 'fri', 'sat' ),
					'people_enabled'               => true,
					'people_count_as_booking'      => true,
					'people_type_enabled'          => false,
					'people_min'                   => 2,
					'people_max'                   => 12,
					'people_types'                 => array(
						array(
							'label' => __( 'Volwassene (13+)', 'sbdp' ),
							'price' => 0.0,
						),
						array(
							'label' => __( 'Kind (t/m 12 jaar)', 'sbdp' ),
							'price' => -10.0,
						),
					),
					'base_price'                   => 59.0,
					'base_price_per_person'        => false,
					'fixed_fee'                    => 7.5,
					'fixed_fee_per_person'         => false,
					'extra_costs'                  => array(),
					'last_minute_discount'         => 0.0,
					'last_minute_days_before'      => 0,
				),
				'availability'     => self::build_evening_availability(),
				'additional_rules' => self::build_evening_rules(),
				'price_rules'      => array(
					array(
						'label'    => __( 'Avondtoeslag per boeking', 'sbdp' ),
						'type'     => 'fixed',
						'amount'   => 12.5,
						'apply_to' => 'booking',
					),
				),
				'resources'        => self::get_evening_resources_config(),
				'highlights'       => array(
					__( 'Avondtours op donderdag t/m zaterdag', 'sbdp' ),
					__( 'Avondtoeslag wordt automatisch toegevoegd', 'sbdp' ),
					__( 'Beperkte capaciteit (12 personen)', 'sbdp' ),
				),
			),
		);
	}

    /**
     * Render the setup form.
     */
    public static function render_page(): void
    {
        if (! current_user_can(AdminMenu::capability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'sbdp'));
        }

        $status        = isset($_GET['sbdp_suite_status']) ? sanitize_text_field(wp_unslash((string) $_GET['sbdp_suite_status'])) : '';
        $message       = '';
        $message_type  = 'updated';
        $presets       = self::get_presets();
        $default_preset = array_key_first($presets);

        if ('success' === $status) {
            $preset_key = isset($_GET['sbdp_suite_preset']) ? sanitize_key((string) $_GET['sbdp_suite_preset']) : '';
            if ($preset_key && isset($presets[ $preset_key ]['label'])) {
                $message = sprintf(
                    /* translators: %s preset label */
                    __('Preset "%s" applied successfully.', 'sbdp'),
                    $presets[ $preset_key ]['label']
                );
            } else {
                $message = esc_html__('Booking suite preset applied successfully.', 'sbdp');
            }
        } elseif ('error' === $status) {
            $error_msg = isset($_GET['sbdp_suite_error']) ? sanitize_text_field(wp_unslash((string) $_GET['sbdp_suite_error'])) : '';
            $message = $error_msg ? $error_msg : esc_html__('The preset could not be applied.', 'sbdp');
            $message_type = 'error';
        }

		$products = self::get_bookable_products();

		echo '<div class="wrap">';
		echo '<style>.sbdp-suite-preset{margin-bottom:16px;padding:12px 0;border-bottom:1px solid #eef2ff}.sbdp-suite-preset:last-of-type{border-bottom:0}.sbdp-suite-preset__highlights{margin:4px 0 0 20px;list-style:disc}.sbdp-suite-preset__highlights li{margin:0 0 2px}</style>';
		echo '<h1>' . esc_html__('Booking Suite Setup', 'sbdp') . '</h1>';
		echo '<p class="description">' . esc_html__('Choose a preset and apply it to a Bookable Service product in one click. Presets configure availability, pricing, participant rules, resources, and planner defaults.', 'sbdp') . '</p>';

        if ($message) {
            printf(
                '<div class="notice notice-%1$s"><p>%2$s</p></div>',
                esc_attr($message_type),
                esc_html($message)
            );
        }

        if (empty($products)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('No bookable products found. Create a product with the “Bookable service” type first.', 'sbdp') . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="sbdp-suite-form">';
        wp_nonce_field(self::ACTION, 'sbdp_suite_nonce');
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';

		echo '<table class="form-table" role="presentation">';
		echo '<tbody>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Preset', 'sbdp' ) . '</th>';
		echo '<td>';
		foreach ( $presets as $key => $preset ) {
			$preset_id    = 'sbdp_suite_preset_' . esc_attr( $key );
			$is_default   = $default_preset === $key;
			echo '<div class="sbdp-suite-preset">';
			printf(
				'<label for="%1$s"><input type="radio" id="%1$s" name="preset_key" value="%2$s" %3$s /> <strong>%4$s</strong></label>',
				$preset_id,
				esc_attr( $key ),
				checked( $is_default, true, false ),
				esc_html( $preset['label'] ?? $key )
			);
			if ( ! empty( $preset['description'] ) ) {
				echo '<p class="description">' . esc_html( $preset['description'] ) . '</p>';
			}
			if ( ! empty( $preset['highlights'] ) && is_array( $preset['highlights'] ) ) {
				echo '<ul class="sbdp-suite-preset__highlights">';
				foreach ( $preset['highlights'] as $highlight ) {
					echo '<li>' . esc_html( $highlight ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</div>';
		}
		echo '</td>';
		echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="sbdp_suite_product">' . esc_html__('Select product', 'sbdp') . '</label></th>';
        echo '<td>';
        echo '<select id="sbdp_suite_product" name="product_id" class="regular-text">';
        foreach ($products as $product) {
            printf(
                '<option value="%1$d">%2$s</option>',
                esc_attr($product->get_id()),
                esc_html($product->get_name())
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Choose the Bookable Service product that should receive the Dagje Den Bosch configuration.', 'sbdp') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Planner resources', 'sbdp') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="create_resources" value="1" checked="checked" /> ' . esc_html__('Create and assign default resources (Gids Tiny, E-chopper set).', 'sbdp') . '</label>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Calendar sync', 'sbdp') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="enable_sync" value="1" checked="checked" /> ' . esc_html__('Enable Google Calendar & iCal sync for this preset.', 'sbdp') . '</label>';
        echo '</td>';
        echo '</tr>';

        echo '</tbody>';
        echo '</table>';

        submit_button(__('Apply preset', 'sbdp'));

        echo '</form>';
        echo '</div>';
    }

    /**
     * Handle the preset application.
     */
    public static function handle_post(): void
    {
        if (! current_user_can(AdminMenu::capability())) {
            wp_die(esc_html__('You do not have permission to apply this preset.', 'sbdp'));
        }

        check_admin_referer(self::ACTION, 'sbdp_suite_nonce');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$presets = self::get_presets();
		$preset_key = isset($_POST['preset_key']) ? sanitize_key((string) $_POST['preset_key']) : array_key_first($presets);
		if (! isset($presets[$preset_key])) {
			$preset_key = array_key_first($presets);
		}

		$create_resources = ! empty($_POST['create_resources']);
		$enable_sync = ! empty($_POST['enable_sync']);

        $redirect_url = add_query_arg(
            array(
                'page' => 'sbdp_booking_suite',
            ),
            admin_url('admin.php')
        );

		$result = self::apply_preset($product_id, $preset_key, array(
			'create_resources' => $create_resources,
			'enable_sync'      => $enable_sync,
		));

        if (is_wp_error($result)) {
            $redirect_url = add_query_arg(
                array(
                    'sbdp_suite_status' => 'error',
                    'sbdp_suite_error'  => rawurlencode($result->get_error_message()),
                ),
                $redirect_url
            );
        } else {
            $redirect_url = add_query_arg(
				array(
					'sbdp_suite_status' => 'success',
					'sbdp_suite_preset' => $preset_key,
				),
				$redirect_url
			);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
	 * Apply preset to selected product.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $preset_key Preset key.
	 * @param array  $options    Additional options.
	 *
	 * @return true|WP_Error
	 */
	private static function apply_preset(int $product_id, string $preset_key, array $options)
	{
		if (! function_exists('wc_get_product')) {
			return new WP_Error('sbdp_missing_wc', __('WooCommerce is required to apply this preset.', 'sbdp'));
		}

		$presets = self::get_presets();
		if (! isset($presets[$preset_key])) {
			return new WP_Error('sbdp_invalid_preset', __('Select a valid preset.', 'sbdp'));
		}
		$preset = $presets[$preset_key];

        $product = wc_get_product($product_id);
        if (! $product instanceof WC_Product) {
            return new WP_Error('sbdp_invalid_product', __('Select a valid Bookable Service product.', 'sbdp'));
        }

        if ($product->get_type() !== BookableServiceProductType::PRODUCT_TYPE) {
            wp_set_object_terms($product_id, BookableServiceProductType::PRODUCT_TYPE, 'product_type');
            $product = wc_get_product($product_id);
            if (! $product instanceof WC_Product) {
                return new WP_Error('sbdp_invalid_product_type', __('Unable to switch the product to “Bookable service”.', 'sbdp'));
            }
        }

		// Update WooCommerce price based on preset.
		$pricing = $preset['pricing'] ?? array();
		if (! empty($pricing['regular_price'])) {
			$product->set_regular_price((string) $pricing['regular_price']);
		}
		if (! empty($pricing['price'])) {
			$product->set_price((string) $pricing['price']);
		} elseif (! empty($pricing['regular_price'])) {
			$product->set_price((string) $pricing['regular_price']);
		}
		$product->save();

        // Retrieve current booking meta (defaults when empty).
        if (! class_exists(SBDP_Admin_Bookable_Meta::class)) {
            require_once SBDP_DIR . 'includes/admin/class-sbdp-admin-bookable-meta.php';
        }

		$meta = SBDP_Admin_Bookable_Meta::get_meta($product_id);
		$meta = self::apply_meta_defaults($meta, $preset, ! empty($options['enable_sync']));

		self::persist_meta($product_id, $meta);
		self::store_price_rules($product_id, $preset['price_rules'] ?? array());

		if (! empty($options['create_resources']) && ! empty($preset['resources'])) {
			$resource_ids = self::ensure_resources($preset['resources']);
			if (! empty($resource_ids)) {
				self::assign_resources_to_product($product_id, $resource_ids);
			}
		}

        return true;
    }

    /**
     * Apply Dagje Den Bosch defaults to metadata array.
     *
     * @param array $meta
     * @param bool  $enable_sync
     * @return array
     */
	private static function apply_meta_defaults(array $meta, array $preset, bool $enable_sync): array
	{
		$overrides = $preset['meta'] ?? array();

		if ($enable_sync) {
			$overrides['booking_sync_google_calendar'] = true;
		}

		foreach ($overrides as $key => $value) {
			$meta[ $key ] = $value;
		}

		if (isset($preset['availability'])) {
			$meta['default_availability'] = $preset['availability'];
		}

		if (isset($preset['additional_rules'])) {
			$meta['additional_rules'] = $preset['additional_rules'];
		}

		return $meta;
	}

    /**
     * Persist booking metadata to post meta.
     *
     * @param int   $product_id
     * @param array $meta
     */
    private static function persist_meta(int $product_id, array $meta): void
    {
        foreach ($meta as $key => $value) {
            $meta_key = '_sbdp_' . $key;
            if ($value === '' || $value === null || $value === array()) {
                delete_post_meta($product_id, $meta_key);
            } else {
                update_post_meta($product_id, $meta_key, $value);
            }
        }

        self::sync_legacy_meta($product_id, $meta);
    }

    /**
     * Sync legacy meta keys.
     *
     * @param int   $product_id
     * @param array $meta
     */
    private static function sync_legacy_meta(int $product_id, array $meta): void
    {
        $map = array(
            'booking_duration_type'        => '_sbdp_duration_unit',
            'booking_default_start_date'   => '_sbdp_default_start_date',
            'booking_default_start_time'   => '_sbdp_default_start_time',
            'people_enabled'               => '_sbdp_enable_people',
            'people_min'                   => '_sbdp_min_people',
            'people_max'                   => '_sbdp_max_people',
            'people_count_as_booking'      => '_sbdp_people_as_bookings',
            'people_type_enabled'          => '_sbdp_enable_person_types',
            'base_price'                   => '_sbdp_base_price',
            'base_price_per_person'        => '_sbdp_price_per_person',
            'fixed_fee'                    => '_sbdp_base_fee',
            'last_minute_discount'         => '_sbdp_last_minute_discount',
            'extra_costs'                  => '_sbdp_extra_costs',
        );

        foreach ($map as $source => $legacy) {
            if (! array_key_exists($source, $meta)) {
                continue;
            }

            $value = $meta[$source];
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }

            if ($value === '' || $value === array() || $value === null) {
                delete_post_meta($product_id, $legacy);
            } else {
                update_post_meta($product_id, $legacy, $value);
            }
        }

        if (isset($meta['booking_min_duration'])) {
            update_post_meta($product_id, '_sbdp_duration', absint($meta['booking_min_duration']));
        }

        if (isset($meta['default_availability'])) {
            update_post_meta($product_id, '_sbdp_default_hours', wp_json_encode($meta['default_availability']));
        }

        if (isset($meta['additional_rules'])) {
            update_post_meta($product_id, '_sbdp_availability_rules', wp_json_encode($meta['additional_rules']));
        }
    }

    /**
     * Store price rules helper.
     *
     * @param int   $product_id
     * @param array $rules
     */
    private static function store_price_rules(int $product_id, array $rules): void
    {
        update_post_meta($product_id, '_sbdp_price_rules', self::sanitize_price_rules($rules));
    }

    /**
     * Build default price rules for the preset.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function build_price_rules(): array
    {
        return array(
            array(
                'label'    => __('Extra per persoon', 'sbdp'),
                'type'     => 'fixed',
                'amount'   => 10.0,
                'apply_to' => 'participant',
            ),
            array(
                'label'    => __('Boekingskosten per reservering', 'sbdp'),
                'type'     => 'fixed',
                'amount'   => 15.0,
                'apply_to' => 'booking',
            ),
        );
    }

    /**
     * Sanitize price rules (mirrors RestService).
     *
     * @param array<int,array<string,mixed>> $rules
     * @return array<int,array<string,mixed>>
     */
    private static function sanitize_price_rules(array $rules): array
    {
        $out = array();

        foreach ($rules as $rule) {
            $clean = array(
                'label'     => sanitize_text_field($rule['label'] ?? ''),
                'type'      => sanitize_text_field($rule['type'] ?? 'fixed'),
                'amount'    => (float) ($rule['amount'] ?? 0),
                'apply_to'  => sanitize_text_field($rule['apply_to'] ?? 'booking'),
                'weekdays'  => array(),
                'time_from' => '',
                'time_to'   => '',
                'date_from' => '',
                'date_to'   => '',
            );

            if (! in_array($clean['type'], array('fixed', 'percent'), true)) {
                $clean['type'] = 'fixed';
            }

            if (! in_array($clean['apply_to'], array('booking', 'participant'), true)) {
                $clean['apply_to'] = 'booking';
            }

            $out[] = $clean;
        }

        return $out;
    }

    /**
     * Build default availability structure.
     *
     * @return array<string,array<int,array{start:string,end:string}>>
     */
    private static function build_default_availability(): array
    {
        $days = array('mon','tue','wed','thu','fri','sat','sun');
        $availability = array();

        foreach ($days as $day) {
            $availability[$day] = array();
        }

        $default_slot = array(
            array(
                'start' => '09:00',
                'end'   => '21:00',
            ),
        );

        $availability['fri'] = $default_slot;
        $availability['sat'] = $default_slot;
        $availability['sun'] = $default_slot;

        return $availability;
    }

    /**
     * Compose additional availability rules (closures & ranges).
     *
     * @return array<int,array<string,string>>
     */
    private static function build_additional_rules(): array
	{
		$rules = array(
			array(
				'type'  => 'closed',
				'from'  => '2025-12-25',
                'to'    => '2025-12-25',
                'label' => __('Gesloten (Eerste kerstdag)', 'sbdp'),
            ),
            array(
                'type'  => 'closed',
                'from'  => '2025-12-26',
                'to'    => '2025-12-26',
                'label' => __('Gesloten (Tweede kerstdag)', 'sbdp'),
            ),
            array(
                'type'  => 'closed',
                'from'  => '2026-01-01',
                'to'    => '2026-01-01',
                'label' => __('Gesloten (Nieuwjaarsdag)', 'sbdp'),
            ),
            array(
                'type'  => 'open',
                'from'  => '2025-06-01',
                'to'    => '2025-09-01',
                'label' => __('Zomerperiode', 'sbdp'),
            ),
        );

		return $rules;
	}

	private static function build_evening_availability(): array
	{
		$days = array('mon','tue','wed','thu','fri','sat','sun');
		$availability = array();

		foreach ($days as $day) {
			$availability[$day] = array();
		}

		$slot = array(
			array(
				'start' => '19:00',
				'end'   => '22:00',
			),
		);

		$availability['thu'] = $slot;
		$availability['fri'] = $slot;
		$availability['sat'] = $slot;

		return $availability;
	}

	private static function build_evening_rules(): array
	{
		return array(
			array(
				'type'  => 'open',
				'from'  => '2025-04-01',
				'to'    => '2025-09-30',
				'label' => __('Zomerseizoen avondtour', 'sbdp'),
			),
			array(
				'type'  => 'closed',
				'from'  => '2025-12-24',
				'to'    => '2025-12-26',
				'label' => __('Gesloten tijdens kerst', 'sbdp'),
			),
			array(
				'type'  => 'closed',
				'from'  => '2025-12-31',
				'to'    => '2026-01-01',
				'label' => __('Gesloten tijdens jaarwisseling', 'sbdp'),
			),
		);
	}

    /**
     * Create or retrieve default planner resources.
     *
     * @return array<int>
     */
	private static function ensure_resources(array $defaults): array
	{
		$ids = array();

		foreach ($defaults as $resource) {
			$title = $resource['title'] ?? '';
			if ('' === trim($title)) {
				continue;
			}

			$post = get_page_by_title($title, OBJECT, 'bookable_resource');
			if ($post && $post instanceof \WP_Post) {
				$resource_id = (int) $post->ID;
			} else {
				$resource_id = wp_insert_post(
					array(
						'post_title'  => $title,
						'post_type'   => 'bookable_resource',
						'post_status' => 'publish',
					)
				);
			}

			if ($resource_id && ! is_wp_error($resource_id)) {
				update_post_meta($resource_id, '_sbdp_resource_capacity', isset($resource['capacity']) ? (int) $resource['capacity'] : 0);
				if (isset($resource['color'])) {
					update_post_meta($resource_id, '_sbdp_resource_color', sanitize_hex_color($resource['color']) ?: '#2563eb');
				}
				if (isset($resource['order'])) {
					update_post_meta($resource_id, '_sbdp_resource_order', (int) $resource['order']);
				}
				$ids[] = (int) $resource_id;
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * Default resources for Dagje Den Bosch.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_default_resources_config(): array
	{
		return array(
			array(
				'title'    => __('Gids Tiny', 'sbdp'),
				'capacity' => 1,
				'color'    => '#dc2626',
				'order'    => 10,
			),
			array(
				'title'    => __('E-chopper set', 'sbdp'),
				'capacity' => 10,
				'color'    => '#2563eb',
				'order'    => 20,
			),
		);
	}

	/**
	 * Default resources for the evening tour preset.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_evening_resources_config(): array
	{
		return array(
			array(
				'title'    => __('Gids Avondtour', 'sbdp'),
				'capacity' => 1,
				'color'    => '#f97316',
				'order'    => 10,
			),
			array(
				'title'    => __('Binnendieze boot', 'sbdp'),
				'capacity' => 12,
				'color'    => '#0ea5e9',
				'order'    => 20,
			),
		);
	}

    /**
     * Attach resources to product.
     *
     * @param int   $product_id
     * @param array $resource_ids
     */
    private static function assign_resources_to_product(int $product_id, array $resource_ids): void
    {
        if (empty($resource_ids)) {
            return;
        }

        update_post_meta($product_id, '_sbdp_resource_ids', $resource_ids);
        update_post_meta($product_id, '_sbdp_resource_id', $resource_ids[0]);
    }

    /**
     * Fetch bookable products.
     *
     * @return array<WC_Product>
     */
    private static function get_bookable_products(): array
    {
        if (! function_exists('wc_get_products')) {
            return array();
        }

        $products = wc_get_products(
            array(
                'type'     => BookableServiceProductType::PRODUCT_TYPE,
                'limit'    => -1,
                'orderby'  => 'title',
                'order'    => 'ASC',
            )
        );

        return is_array($products) ? $products : array();
    }
}
