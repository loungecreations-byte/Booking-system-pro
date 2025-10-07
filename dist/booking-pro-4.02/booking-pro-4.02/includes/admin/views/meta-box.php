<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @var array<string, mixed> $meta
 */
$meta = isset( $meta ) && is_array( $meta ) ? $meta : array();

$defaults = array(
    'booking_allowed_start_days'      => array(),
    'people_types'                   => array(),
    'extra_costs'                    => array(),
    'advanced_price_rules'           => array(),
    'default_availability'           => array(),
    'additional_rules'               => array(),
    'booking_duration_type'          => '',
    'booking_min_duration'           => '',
    'booking_max_duration'           => '',
    'booking_default_start_date'     => '',
    'booking_default_start_time'     => '',
    'booking_terms_max_per_unit'     => '',
    'booking_min_advance'            => '',
    'booking_max_advance'            => '',
    'booking_checkin'                => '',
    'booking_checkout'               => '',
    'booking_buffer_time'            => '',
    'booking_time_increment_based'   => '',
    'booking_requires_confirmation'  => '',
    'booking_allow_cancellation'     => '',
    'booking_sync_google_calendar'   => '',
    'booking_location'               => '',
    'people_enabled'                 => '',
    'people_count_as_booking'        => '',
    'people_type_enabled'            => '',
    'people_min'                     => '',
    'people_max'                     => '',
    'base_price'                     => '',
    'fixed_fee'                      => '',
    'last_minute_discount'           => '',
    'last_minute_days_before'        => '',
    'base_price_per_person'          => '',
    'fixed_fee_per_person'           => '',
    'exclusions'                     => '',
    'permalink_override'             => ''
);

if ( function_exists( 'wp_parse_args' ) ) {
    $meta = wp_parse_args( $meta, $defaults );
} else {
    $meta = array_merge( $defaults, $meta );
}

global $post;

$product_id = ( isset( $post ) && $post instanceof \WP_Post ) ? (int) $post->ID : 0;
$days_labels = array(
	'mon' => __( 'Monday', 'sbdp' ),
	'tue' => __( 'Tuesday', 'sbdp' ),
	'wed' => __( 'Wednesday', 'sbdp' ),
	'thu' => __( 'Thursday', 'sbdp' ),
	'fri' => __( 'Friday', 'sbdp' ),
	'sat' => __( 'Saturday', 'sbdp' ),
	'sun' => __( 'Sunday', 'sbdp' ),
);

$allowed_days = (array) $meta['booking_allowed_start_days'];
$people_types = is_array( $meta['people_types'] ) ? $meta['people_types'] : array();
$extra_costs  = is_array( $meta['extra_costs'] ) ? $meta['extra_costs'] : array();
$rules        = is_array( $meta['advanced_price_rules'] ) ? $meta['advanced_price_rules'] : array();
$availability = is_array( $meta['default_availability'] ) ? $meta['default_availability'] : array();
$additional   = is_array( $meta['additional_rules'] ) ? $meta['additional_rules'] : array();
?>
<div class="sbdp-bookable-meta" data-product-id="<?php echo esc_attr( $product_id ); ?>">
	<p class="description">
		<?php esc_html_e( 'Configure every aspect of your bookable product in one place. Tabs help you switch between booking behaviour, people, pricing, and availability rules.', 'sbdp' ); ?>
	</p>

	<nav class="nav-tab-wrapper sbdp-bookable-tabs" role="tablist">
		<button type="button" class="nav-tab nav-tab-active" data-panel="sbdp-booking-tab" aria-selected="true"><?php esc_html_e( 'Booking Settings', 'sbdp' ); ?></button>
		<button type="button" class="nav-tab" data-panel="sbdp-people-tab" aria-selected="false"><?php esc_html_e( 'People Settings', 'sbdp' ); ?></button>
		<button type="button" class="nav-tab" data-panel="sbdp-pricing-tab" aria-selected="false"><?php esc_html_e( 'Pricing & Discounts', 'sbdp' ); ?></button>
		<button type="button" class="nav-tab" data-panel="sbdp-availability-tab" aria-selected="false"><?php esc_html_e( 'Availability', 'sbdp' ); ?></button>
	</nav>

	<section id="sbdp-booking-tab" class="sbdp-tab-panel is-active" role="tabpanel">
		<div class="sbdp-field-grid">
			<div class="sbdp-field">
				<label for="sbdp-booking-duration-type"><?php esc_html_e( 'Duration units', 'sbdp' ); ?></label>
				<select name="sbdp_bookable[booking_duration_type]" id="sbdp-booking-duration-type">
					<?php
					$duration_types = array(
						'minutes' => __( 'Minutes', 'sbdp' ),
						'hours'   => __( 'Hours', 'sbdp' ),
						'days'    => __( 'Days', 'sbdp' ),
						'months'  => __( 'Months', 'sbdp' ),
					);
					foreach ( $duration_types as $value => $label ) :
						?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $meta['booking_duration_type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php
					endforeach;
					?>
				</select>
				<p class="description"><?php esc_html_e( 'Base time unit for bookings.', 'sbdp' ); ?></p>
			</div>
			<div class="sbdp-field">
				<label for="sbdp-booking-min-duration"><?php esc_html_e( 'Minimum duration', 'sbdp' ); ?></label>
				<input type="number" min="0" step="1" id="sbdp-booking-min-duration" name="sbdp_bookable[booking_min_duration]" value="<?php echo esc_attr( $meta['booking_min_duration'] ); ?>" />
			</div>
			<div class="sbdp-field">
				<label for="sbdp-booking-max-duration"><?php esc_html_e( 'Maximum duration', 'sbdp' ); ?></label>
				<input type="number" min="0" step="1" id="sbdp-booking-max-duration" name="sbdp_bookable[booking_max_duration]" value="<?php echo esc_attr( $meta['booking_max_duration'] ); ?>" />
			</div>
			<div class="sbdp-field">
				<label for="sbdp-booking-default-date"><?php esc_html_e( 'Default start date', 'sbdp' ); ?></label>
				<input type="date" id="sbdp-booking-default-date" name="sbdp_bookable[booking_default_start_date]" value="<?php echo esc_attr( $meta['booking_default_start_date'] ); ?>" />
			</div>
			<div class="sbdp-field">
				<label for="sbdp-booking-default-time"><?php esc_html_e( 'Default start time', 'sbdp' ); ?></label>
				<input type="time" id="sbdp-booking-default-time" name="sbdp_bookable[booking_default_start_time]" value="<?php echo esc_attr( $meta['booking_default_start_time'] ); ?>" />
			</div>
			<div class="sbdp-field">
				<label for="sbdp-booking-max-per-unit"><?php esc_html_e( 'Max terms per unit', 'sbdp' ); ?></label>
				<input type="number" min="0" step="1" id="sbdp-booking-max-per-unit" name="sbdp_bookable[booking_terms_max_per_unit]" value="<?php echo esc_attr( $meta['booking_terms_max_per_unit'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Leave 0 for unlimited bookings per slot.', 'sbdp' ); ?></p>
			</div>
			<div class="sbdp-field">
				<label for="sbdp-booking-min-advance"><?php esc_html_e( 'Minimum advance (days)', 'sbdp' ); ?></label>
				<input type="number" min="0" step="1" id="sbdp-booking-min-advance" name="sbdp_bookable[booking_min_advance]" value="<?php echo esc_attr( $meta['booking_min_advance'] ); ?>" />
			</div>
			<div class="sbdp-field">
				<label for="sbdp-booking-max-advance"><?php esc_html_e( 'Maximum advance (days)', 'sbdp' ); ?></label>
				<input type="number" min="0" step="1" id="sbdp-booking-max-advance" name="sbdp_bookable[booking_max_advance]" value="<?php echo esc_attr( $meta['booking_max_advance'] ); ?>" />
			</div>
			<div class="sbdp-field">
				<label for="sbdp-booking-checkin"><?php esc_html_e( 'Check-in time', 'sbdp' ); ?></label>
				<input type="time" id="sbdp-booking-checkin" name="sbdp_bookable[booking_checkin]" value="<?php echo esc_attr( $meta['booking_checkin'] ); ?>" />
			</div>
			<div class="sbdp-field">
				<label for="sbdp-booking-checkout"><?php esc_html_e( 'Check-out time', 'sbdp' ); ?></label>
				<input type="time" id="sbdp-booking-checkout" name="sbdp_bookable[booking_checkout]" value="<?php echo esc_attr( $meta['booking_checkout'] ); ?>" />
			</div>
			<div class="sbdp-field">
				<label for="sbdp-booking-buffer"><?php esc_html_e( 'Buffer time (hours)', 'sbdp' ); ?></label>
				<input type="number" min="0" step="1" id="sbdp-booking-buffer" name="sbdp_bookable[booking_buffer_time]" value="<?php echo esc_attr( $meta['booking_buffer_time'] ); ?>" />
			</div>
		</div>

		<fieldset class="sbdp-field">
			<legend><?php esc_html_e( 'Allowed start days', 'sbdp' ); ?></legend>
			<div class="sbdp-checkbox-grid">
				<?php foreach ( $days_labels as $day_key => $day_label ) : ?>
					<label>
						<input type="checkbox" name="sbdp_bookable[booking_allowed_start_days][]" value="<?php echo esc_attr( $day_key ); ?>" <?php checked( in_array( $day_key, $allowed_days, true ) ); ?> />
						<?php echo esc_html( $day_label ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>

		<div class="sbdp-toggle-list">
			<label>
				<input type="checkbox" name="sbdp_bookable[booking_time_increment_based]" value="yes" <?php checked( ! empty( $meta['booking_time_increment_based'] ) ); ?> />
				<?php esc_html_e( 'Use duration increments for slot selection', 'sbdp' ); ?>
			</label>
			<label>
				<input type="checkbox" name="sbdp_bookable[booking_requires_confirmation]" value="yes" <?php checked( ! empty( $meta['booking_requires_confirmation'] ) ); ?> />
				<?php esc_html_e( 'Require manual confirmation before payment', 'sbdp' ); ?>
			</label>
			<label>
				<input type="checkbox" name="sbdp_bookable[booking_allow_cancellation]" value="yes" <?php checked( ! empty( $meta['booking_allow_cancellation'] ) ); ?> />
				<?php esc_html_e( 'Allow customers to cancel online', 'sbdp' ); ?>
			</label>
			<label>
				<input type="checkbox" name="sbdp_bookable[booking_sync_google_calendar]" value="yes" <?php checked( ! empty( $meta['booking_sync_google_calendar'] ) ); ?> />
				<?php esc_html_e( 'Sync with Google Calendar (beta)', 'sbdp' ); ?>
			</label>
		</div>

		<div class="sbdp-field">
			<label for="sbdp-booking-location"><?php esc_html_e( 'Location (Google Maps)', 'sbdp' ); ?></label>
			<input type="text" id="sbdp-booking-location" name="sbdp_bookable[booking_location]" value="<?php echo esc_attr( $meta['booking_location'] ); ?>" placeholder="<?php esc_attr_e( 'Search address or place', 'sbdp' ); ?>" />
			<div id="sbdp-booking-location-map" class="sbdp-location-map" aria-hidden="true"></div>
		</div>
	</section>
	<section id="sbdp-people-tab" class="sbdp-tab-panel" role="tabpanel" hidden>
		<div class="sbdp-toggle-list">
			<label>
				<input type="checkbox" name="sbdp_bookable[people_enabled]" value="yes" <?php checked( ! empty( $meta['people_enabled'] ) ); ?> />
				<?php esc_html_e( 'Enable people counts', 'sbdp' ); ?>
			</label>
			<label>
				<input type="checkbox" name="sbdp_bookable[people_count_as_booking]" value="yes" <?php checked( ! empty( $meta['people_count_as_booking'] ) ); ?> />
				<?php esc_html_e( 'Count each person as a separate booking seat', 'sbdp' ); ?>
			</label>
			<label>
				<input type="checkbox" name="sbdp_bookable[people_type_enabled]" value="yes" <?php checked( ! empty( $meta['people_type_enabled'] ) ); ?> />
				<?php esc_html_e( 'Enable people types (adult/child etc.)', 'sbdp' ); ?>
			</label>
		</div>

		<div class="sbdp-field-grid">
			<div class="sbdp-field">
				<label for="sbdp-people-min"><?php esc_html_e( 'Minimum people', 'sbdp' ); ?></label>
				<input type="number" min="0" step="1" id="sbdp-people-min" name="sbdp_bookable[people_min]" value="<?php echo esc_attr( $meta['people_min'] ); ?>" />
			</div>
			<div class="sbdp-field">
				<label for="sbdp-people-max"><?php esc_html_e( 'Maximum people', 'sbdp' ); ?></label>
				<input type="number" min="0" step="1" id="sbdp-people-max" name="sbdp_bookable[people_max]" value="<?php echo esc_attr( $meta['people_max'] ); ?>" />
			</div>
		</div>

		<div class="sbdp-repeater" data-repeater="people-types" data-next-index="<?php echo esc_attr( count( $people_types ) ); ?>">
			<header class="sbdp-repeater__header">
				<h3><?php esc_html_e( 'People types & pricing', 'sbdp' ); ?></h3>
				<button type="button" class="button button-secondary" data-repeater-add><?php esc_html_e( 'Add type', 'sbdp' ); ?></button>
			</header>
			<div class="sbdp-repeater__rows">
				<?php foreach ( $people_types as $index => $row ) : ?>
					<div class="sbdp-repeater__row" data-index="<?php echo esc_attr( $index ); ?>">
						<span class="dashicons dashicons-move"></span>
						<label>
							<span><?php esc_html_e( 'Label', 'sbdp' ); ?></span>
							<input type="text" name="sbdp_bookable[people_types][<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" />
						</label>
						<label>
							<span><?php esc_html_e( 'Price adjustment', 'sbdp' ); ?></span>
							<input type="number" step="0.01" name="sbdp_bookable[people_types][<?php echo esc_attr( $index ); ?>][price]" value="<?php echo esc_attr( $row['price'] ?? '' ); ?>" />
						</label>
						<button type="button" class="button-link" data-repeater-remove aria-label="<?php esc_attr_e( 'Remove type', 'sbdp' ); ?>">&times;</button>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<section id="sbdp-pricing-tab" class="sbdp-tab-panel" role="tabpanel" hidden>
		<div class="sbdp-field-grid">
			<div class="sbdp-field">
				<label for="sbdp-base-price"><?php esc_html_e( 'Base price', 'sbdp' ); ?></label>
				<input type="number" step="0.01" id="sbdp-base-price" name="sbdp_bookable[base_price]" value="<?php echo esc_attr( $meta['base_price'] ); ?>" />
			</div>
			<div class="sbdp-field">
				<label for="sbdp-fixed-fee"><?php esc_html_e( 'Fixed fee', 'sbdp' ); ?></label>
				<input type="number" step="0.01" id="sbdp-fixed-fee" name="sbdp_bookable[fixed_fee]" value="<?php echo esc_attr( $meta['fixed_fee'] ); ?>" />
			</div>
			<div class="sbdp-field">
				<label for="sbdp-last-minute-discount"><?php esc_html_e( 'Last-minute discount (%)', 'sbdp' ); ?></label>
				<input type="number" step="0.01" id="sbdp-last-minute-discount" name="sbdp_bookable[last_minute_discount]" value="<?php echo esc_attr( $meta['last_minute_discount'] ); ?>" />
			</div>
			<div class="sbdp-field">
				<label for="sbdp-last-minute-days"><?php esc_html_e( 'Applies within (days before)', 'sbdp' ); ?></label>
				<input type="number" step="1" id="sbdp-last-minute-days" name="sbdp_bookable[last_minute_days_before]" value="<?php echo esc_attr( $meta['last_minute_days_before'] ); ?>" />
			</div>
		</div>

		<div class="sbdp-toggle-list">
			<label>
				<input type="checkbox" name="sbdp_bookable[base_price_per_person]" value="yes" <?php checked( ! empty( $meta['base_price_per_person'] ) ); ?> />
				<?php esc_html_e( 'Multiply base price by people count', 'sbdp' ); ?>
			</label>
			<label>
				<input type="checkbox" name="sbdp_bookable[fixed_fee_per_person]" value="yes" <?php checked( ! empty( $meta['fixed_fee_per_person'] ) ); ?> />
				<?php esc_html_e( 'Apply fixed fee per person', 'sbdp' ); ?>
			</label>
		</div>

		<div class="sbdp-repeater" data-repeater="extra-costs" data-next-index="<?php echo esc_attr( count( $extra_costs ) ); ?>">
			<header class="sbdp-repeater__header">
				<h3><?php esc_html_e( 'Extra costs', 'sbdp' ); ?></h3>
				<button type="button" class="button button-secondary" data-repeater-add><?php esc_html_e( 'Add cost', 'sbdp' ); ?></button>
			</header>
			<div class="sbdp-repeater__rows">
				<?php foreach ( $extra_costs as $index => $row ) : ?>
					<div class="sbdp-repeater__row" data-index="<?php echo esc_attr( $index ); ?>">
						<span class="dashicons dashicons-move"></span>
						<label>
							<span><?php esc_html_e( 'Label', 'sbdp' ); ?></span>
							<input type="text" name="sbdp_bookable[extra_costs][<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" />
						</label>
						<label>
							<span><?php esc_html_e( 'Amount', 'sbdp' ); ?></span>
							<input type="number" step="0.01" name="sbdp_bookable[extra_costs][<?php echo esc_attr( $index ); ?>][amount]" value="<?php echo esc_attr( $row['amount'] ?? '' ); ?>" />
						</label>
						<label>
							<span><?php esc_html_e( 'Multiply by', 'sbdp' ); ?></span>
							<select name="sbdp_bookable[extra_costs][<?php echo esc_attr( $index ); ?>][multiply_by]">
								<?php
								$options        = array(
									'booking'  => __( 'Per booking', 'sbdp' ),
									'duration' => __( 'Per duration unit', 'sbdp' ),
									'people'   => __( 'Per person', 'sbdp' ),
								);
								$selected_value = $row['multiply_by'] ?? 'booking';
								foreach ( $options as $key => $label ) :
									?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_value, $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php
								endforeach;
								?>
							</select>
						</label>
						<button type="button" class="button-link" data-repeater-remove aria-label="<?php esc_attr_e( 'Remove cost', 'sbdp' ); ?>">&times;</button>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="sbdp-repeater" data-repeater="advanced-rules" data-next-index="<?php echo esc_attr( count( $rules ) ); ?>">
			<header class="sbdp-repeater__header">
				<h3><?php esc_html_e( 'Advanced price rules', 'sbdp' ); ?></h3>
				<button type="button" class="button button-secondary" data-repeater-add><?php esc_html_e( 'Add rule', 'sbdp' ); ?></button>
			</header>
			<div class="sbdp-repeater__rows">
				<?php foreach ( $rules as $index => $row ) : ?>
					<div class="sbdp-repeater__row" data-index="<?php echo esc_attr( $index ); ?>">
						<span class="dashicons dashicons-move"></span>
						<label>
							<span><?php esc_html_e( 'Condition', 'sbdp' ); ?></span>
							<select name="sbdp_bookable[advanced_price_rules][<?php echo esc_attr( $index ); ?>][condition]">
								<?php
								$condition_options  = array(
									'date'     => __( 'Date range', 'sbdp' ),
									'weekday'  => __( 'Day of week', 'sbdp' ),
									'month'    => __( 'Month', 'sbdp' ),
									'duration' => __( 'Duration', 'sbdp' ),
									'people'   => __( 'People count', 'sbdp' ),
								);
								$selected_condition = $row['condition'] ?? 'date';
								foreach ( $condition_options as $key => $label ) :
									?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_condition, $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php
								endforeach;
								?>
							</select>
						</label>
						<label>
							<span><?php esc_html_e( 'Value', 'sbdp' ); ?></span>
							<input type="text" name="sbdp_bookable[advanced_price_rules][<?php echo esc_attr( $index ); ?>][value]" value="<?php echo esc_attr( $row['value'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. 2025-07-01>2025-08-31', 'sbdp' ); ?>" />
						</label>
						<label>
							<span><?php esc_html_e( 'New price', 'sbdp' ); ?></span>
							<input type="number" step="0.01" name="sbdp_bookable[advanced_price_rules][<?php echo esc_attr( $index ); ?>][price]" value="<?php echo esc_attr( $row['price'] ?? '' ); ?>" />
						</label>
						<button type="button" class="button-link" data-repeater-remove aria-label="<?php esc_attr_e( 'Remove rule', 'sbdp' ); ?>">&times;</button>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<section id="sbdp-availability-tab" class="sbdp-tab-panel" role="tabpanel" hidden>
		<p class="description"><?php esc_html_e( 'Configure weekly opening hours and add overrides for events, holidays, or closures.', 'sbdp' ); ?></p>

		<div class="sbdp-availability-week" data-repeater="availability">
			<?php
			foreach ( $days_labels as $day_key => $day_label ) :
				$day_slots  = $availability[ $day_key ] ?? array();
				$next_index = is_countable( $day_slots ) ? count( $day_slots ) : 0;
				?>
				<div class="sbdp-availability-day" data-day="<?php echo esc_attr( $day_key ); ?>" data-next-index="<?php echo esc_attr( $next_index ); ?>">
					<header>
						<strong><?php echo esc_html( $day_label ); ?></strong>
						<button type="button" class="button button-small" data-add-slot><?php esc_html_e( 'Add slot', 'sbdp' ); ?></button>
					</header>
					<div class="sbdp-availability-slots">
						<?php foreach ( $day_slots as $index => $slot ) : ?>
							<div class="sbdp-availability-slot" data-index="<?php echo esc_attr( $index ); ?>">
								<label>
									<span><?php esc_html_e( 'Start', 'sbdp' ); ?></span>
									<input type="time" name="sbdp_bookable[default_availability][<?php echo esc_attr( $day_key ); ?>][<?php echo esc_attr( $index ); ?>][start]" value="<?php echo esc_attr( $slot['start'] ?? '' ); ?>" />
								</label>
								<label>
									<span><?php esc_html_e( 'End', 'sbdp' ); ?></span>
									<input type="time" name="sbdp_bookable[default_availability][<?php echo esc_attr( $day_key ); ?>][<?php echo esc_attr( $index ); ?>][end]" value="<?php echo esc_attr( $slot['end'] ?? '' ); ?>" />
								</label>
								<button type="button" class="button-link" data-remove-slot aria-label="<?php esc_attr_e( 'Remove slot', 'sbdp' ); ?>">&times;</button>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="sbdp-repeater" data-repeater="availability-rules" data-next-index="<?php echo esc_attr( count( $additional ) ); ?>">
			<header class="sbdp-repeater__header">
				<h3><?php esc_html_e( 'Additional rules', 'sbdp' ); ?></h3>
				<button type="button" class="button button-secondary" data-repeater-add><?php esc_html_e( 'Add rule', 'sbdp' ); ?></button>
			</header>
			<div class="sbdp-repeater__rows">
				<?php foreach ( $additional as $index => $row ) : ?>
					<div class="sbdp-repeater__row" data-index="<?php echo esc_attr( $index ); ?>">
						<label>
							<span><?php esc_html_e( 'Type', 'sbdp' ); ?></span>
							<select name="sbdp_bookable[additional_rules][<?php echo esc_attr( $index ); ?>][type]">
								<option value="include" <?php selected( ( $row['type'] ?? '' ), 'include' ); ?>><?php esc_html_e( 'Include', 'sbdp' ); ?></option>
								<option value="exclude" <?php selected( ( $row['type'] ?? '' ), 'exclude' ); ?>><?php esc_html_e( 'Exclude', 'sbdp' ); ?></option>
							</select>
						</label>
						<label>
							<span><?php esc_html_e( 'From', 'sbdp' ); ?></span>
							<input type="date" name="sbdp_bookable[additional_rules][<?php echo esc_attr( $index ); ?>][from]" value="<?php echo esc_attr( $row['from'] ?? '' ); ?>" />
						</label>
						<label>
							<span><?php esc_html_e( 'To', 'sbdp' ); ?></span>
							<input type="date" name="sbdp_bookable[additional_rules][<?php echo esc_attr( $index ); ?>][to]" value="<?php echo esc_attr( $row['to'] ?? '' ); ?>" />
						</label>
						<label>
							<span><?php esc_html_e( 'Label', 'sbdp' ); ?></span>
							<input type="text" name="sbdp_bookable[additional_rules][<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" />
						</label>
						<button type="button" class="button-link" data-repeater-remove aria-label="<?php esc_attr_e( 'Remove rule', 'sbdp' ); ?>">&times;</button>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="sbdp-field">
			<label for="sbdp-availability-exclusions"><?php esc_html_e( 'Excluded products or URLs', 'sbdp' ); ?></label>
			<textarea id="sbdp-availability-exclusions" name="sbdp_bookable[exclusions]" rows="3" placeholder="<?php esc_attr_e( 'Comma separated product IDs or URLs', 'sbdp' ); ?>"><?php echo esc_textarea( $meta['exclusions'] ); ?></textarea>
		</div>

		<div class="sbdp-field">
			<label for="sbdp-permalink-override"><?php esc_html_e( 'Permalink override', 'sbdp' ); ?></label>
			<input type="text" id="sbdp-permalink-override" name="sbdp_bookable[permalink_override]" value="<?php echo esc_attr( $meta['permalink_override'] ); ?>" placeholder="<?php esc_attr_e( 'Optional custom slug', 'sbdp' ); ?>" />
		</div>
	</section>
	<div class="sbdp-meta-actions">
		<button type="button" class="button" id="sbdp-bookable-duplicate" data-product-id="<?php echo esc_attr( $product_id ); ?>">
			<?php esc_html_e( 'Duplicate booking settings from another product', 'sbdp' ); ?>
		</button>
	</div>

	<template id="sbdp-tpl-people-types">
		<div class="sbdp-repeater__row" data-index="__index__">
			<span class="dashicons dashicons-move"></span>
			<label>
				<span><?php esc_html_e( 'Label', 'sbdp' ); ?></span>
				<input type="text" name="sbdp_bookable[people_types][__index__][label]" value="" />
			</label>
			<label>
				<span><?php esc_html_e( 'Price adjustment', 'sbdp' ); ?></span>
				<input type="number" step="0.01" name="sbdp_bookable[people_types][__index__][price]" value="" />
			</label>
			<button type="button" class="button-link" data-repeater-remove aria-label="<?php esc_attr_e( 'Remove type', 'sbdp' ); ?>">&times;</button>
		</div>
	</template>

	<template id="sbdp-tpl-extra-costs">
		<div class="sbdp-repeater__row" data-index="__index__">
			<span class="dashicons dashicons-move"></span>
			<label>
				<span><?php esc_html_e( 'Label', 'sbdp' ); ?></span>
				<input type="text" name="sbdp_bookable[extra_costs][__index__][label]" value="" />
			</label>
			<label>
				<span><?php esc_html_e( 'Amount', 'sbdp' ); ?></span>
				<input type="number" step="0.01" name="sbdp_bookable[extra_costs][__index__][amount]" value="" />
			</label>
			<label>
				<span><?php esc_html_e( 'Multiply by', 'sbdp' ); ?></span>
				<select name="sbdp_bookable[extra_costs][__index__][multiply_by]">
					<option value="booking"><?php esc_html_e( 'Per booking', 'sbdp' ); ?></option>
					<option value="duration"><?php esc_html_e( 'Per duration unit', 'sbdp' ); ?></option>
					<option value="people"><?php esc_html_e( 'Per person', 'sbdp' ); ?></option>
				</select>
			</label>
			<button type="button" class="button-link" data-repeater-remove aria-label="<?php esc_attr_e( 'Remove cost', 'sbdp' ); ?>">&times;</button>
		</div>
	</template>

	<template id="sbdp-tpl-advanced-rules">
		<div class="sbdp-repeater__row" data-index="__index__">
			<span class="dashicons dashicons-move"></span>
			<label>
				<span><?php esc_html_e( 'Condition', 'sbdp' ); ?></span>
				<select name="sbdp_bookable[advanced_price_rules][__index__][condition]">
					<option value="date"><?php esc_html_e( 'Date range', 'sbdp' ); ?></option>
					<option value="weekday"><?php esc_html_e( 'Day of week', 'sbdp' ); ?></option>
					<option value="month"><?php esc_html_e( 'Month', 'sbdp' ); ?></option>
					<option value="duration"><?php esc_html_e( 'Duration', 'sbdp' ); ?></option>
					<option value="people"><?php esc_html_e( 'People count', 'sbdp' ); ?></option>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Value', 'sbdp' ); ?></span>
				<input type="text" name="sbdp_bookable[advanced_price_rules][__index__][value]" value="" placeholder="<?php esc_attr_e( 'e.g. 2025-12-24>2025-12-31', 'sbdp' ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'New price', 'sbdp' ); ?></span>
				<input type="number" step="0.01" name="sbdp_bookable[advanced_price_rules][__index__][price]" value="" />
			</label>
			<button type="button" class="button-link" data-repeater-remove aria-label="<?php esc_attr_e( 'Remove rule', 'sbdp' ); ?>">&times;</button>
		</div>
	</template>

	<template id="sbdp-tpl-availability-slot">
		<div class="sbdp-availability-slot" data-index="__index__">
			<label>
				<span><?php esc_html_e( 'Start', 'sbdp' ); ?></span>
				<input type="time" name="sbdp_bookable[default_availability][__day__][__index__][start]" value="" />
			</label>
			<label>
				<span><?php esc_html_e( 'End', 'sbdp' ); ?></span>
				<input type="time" name="sbdp_bookable[default_availability][__day__][__index__][end]" value="" />
			</label>
			<button type="button" class="button-link" data-remove-slot aria-label="<?php esc_attr_e( 'Remove slot', 'sbdp' ); ?>">&times;</button>
		</div>
	</template>

	<template id="sbdp-tpl-availability-rule">
		<div class="sbdp-repeater__row" data-index="__index__">
			<label>
				<span><?php esc_html_e( 'Type', 'sbdp' ); ?></span>
				<select name="sbdp_bookable[additional_rules][__index__][type]">
					<option value="include"><?php esc_html_e( 'Include', 'sbdp' ); ?></option>
					<option value="exclude"><?php esc_html_e( 'Exclude', 'sbdp' ); ?></option>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'From', 'sbdp' ); ?></span>
				<input type="date" name="sbdp_bookable[additional_rules][__index__][from]" value="" />
			</label>
			<label>
				<span><?php esc_html_e( 'To', 'sbdp' ); ?></span>
				<input type="date" name="sbdp_bookable[additional_rules][__index__][to]" value="" />
			</label>
			<label>
				<span><?php esc_html_e( 'Label', 'sbdp' ); ?></span>
				<input type="text" name="sbdp_bookable[additional_rules][__index__][label]" value="" />
			</label>
			<button type="button" class="button-link" data-repeater-remove aria-label="<?php esc_attr_e( 'Remove rule', 'sbdp' ); ?>">&times;</button>
		</div>
	</template>
</div>


