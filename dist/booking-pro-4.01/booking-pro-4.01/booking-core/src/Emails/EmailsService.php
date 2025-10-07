<?php

declare(strict_types=1);

namespace BSPModule\Core\Emails;

use WC_Order;

final class EmailsService {

	/**
	 * Hook email related actions.
	 */
	public static function init() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'carry_meta' ), 10, 4 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'append_program' ), 20, 4 );
	}

	/**
	 * Make sure cart metadata is stored on the order items.
	 */
	public static function carry_meta( $item, $cart_item_key, $values, $order ) {  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( isset( $values['sbdp_meta'] ) && is_array( $values['sbdp_meta'] ) ) {
			foreach ( $values['sbdp_meta'] as $key => $value ) {
				$item->add_meta_data( $key, $value, true );
			}
		}
		if ( isset( $values['sbdp_pricing'] ) && is_array( $values['sbdp_pricing'] ) ) {
			$item->add_meta_data( '_sbdp_pricing', $values['sbdp_pricing'], true );
		}
	}

	/**
	 * Append an itinerary overview to WooCommerce emails.
	 */
	public static function append_program( $order, $sent_to_admin, $plain_text, $email ) {  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$rows = self::collect_program_rows( $order );
		if ( empty( $rows ) ) {
			return;
		}

		$summary = self::build_summary( $rows, $order );

		if ( $plain_text ) {
			self::render_plain_email( $rows, $summary, $order, $sent_to_admin );
			return;
		}

		self::render_html_email( $rows, $summary, $order, $sent_to_admin );
	}

	/**
	 * Build row data for each order item.
	 *
	 * @param WC_Order $order Order instance.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function collect_program_rows( WC_Order $order ) {
		$rows = array();

		foreach ( $order->get_items() as $item ) {
			$start = wc_get_order_item_meta( $item->get_id(), 'sbdp_start' );
			$end   = wc_get_order_item_meta( $item->get_id(), 'sbdp_end' );
			if ( ! $start || ! $end ) {
				continue;
			}

			$participants = (int) wc_get_order_item_meta( $item->get_id(), 'sbdp_participants' );
			if ( $participants < 1 ) {
				$participants = 1;
			}

			$pricing = wc_get_order_item_meta( $item->get_id(), '_sbdp_pricing' );
			$total   = (float) $item->get_total() + (float) $item->get_total_tax();

			$resource_id    = wc_get_order_item_meta( $item->get_id(), 'sbdp_resource_id' );
			$resource_label = wc_get_order_item_meta( $item->get_id(), 'sbdp_resource_label' );
			if ( ! $resource_label && $resource_id ) {
				$resource_label = get_the_title( $resource_id );
			}

			$rows[] = array(
				'name'         => $item->get_name(),
				'start'        => $start,
				'end'          => $end,
				'participants' => $participants,
				'pricing'      => is_array( $pricing ) ? $pricing : null,
				'total'        => $total,
				'resource'     => array(
					'id'    => $resource_id ? (int) $resource_id : 0,
					'label' => $resource_label ? sanitize_text_field( $resource_label ) : '',
				),
			);
		}

		return $rows;
	}

	/**
	 * Calculate summary stats for the program.
	 *
	 * @param array<int,array<string,mixed>> $rows Program rows.
	 * @param WC_Order                       $order Order instance.
	 *
	 * @return array<string,mixed>
	 */
	private static function build_summary( array $rows, WC_Order $order ) {
		$items_count        = count( $rows );
		$total_participants = array_sum(
			array_map(
				static function ( $row ) {
					return (int) $row['participants'];
					
				},
				$rows
			)
		);
		$grand_total        = array_sum(
			array_map(
				static function ( $row ) {
					return (float) $row['total'];
				},
				$rows
			)
		);

		return array(
			'items_count'        => $items_count,
			'total_participants' => $total_participants,
			'grand_total'        => $grand_total,
			'grand_total_label'  => self::format_amount( $grand_total, $order ),
		);
	}

	/**
	 * Render plain text email block.
	 */
	private static function render_plain_email( array $rows, array $summary, WC_Order $order, $sent_to_admin ) {
		echo PHP_EOL . '=== ' . esc_html__( 'Planner overzicht', 'sbdp' ) . ' ===' . PHP_EOL;
		echo sprintf(
			/* translators: 1: number of items, 2: participants, 3: total */
			__( 'Items: %1$d | Deelnemers: %2$d | Totaal: %3$s', 'sbdp' ),
			(int) $summary['items_count'],
			(int) $summary['total_participants'],
			wp_strip_all_tags( $summary['grand_total_label'] )
		) . PHP_EOL;
		echo str_repeat( '-', 40 ) . PHP_EOL;

		foreach ( $rows as $row ) {
			$participants_label = sprintf(
				'%d %s',
				(int) $row['participants'],
				1 === (int) $row['participants'] ? __( 'persoon', 'sbdp' ) : __( 'personen', 'sbdp' )
			);
			echo sprintf(
				'%s: %s - %s (%s)',
				$row['name'],
				self::format_datetime( $row['start'], $order ),
				self::format_datetime( $row['end'], $order ),
				$participants_label
			) . PHP_EOL;

			if ( ! empty( $row['resource']['label'] ) ) {
				echo '  ' . sprintf( '%s: %s', __( 'Resource', 'sbdp' ), $row['resource']['label'] ) . PHP_EOL;
			}

			if ( ! empty( $row['pricing']['applied_rules'] ) ) {
				foreach ( $row['pricing']['applied_rules'] as $rule ) {
					$scope = ( 'participant' === ( $rule['scope'] ?? '' ) ) ? __( 'per deelnemer', 'sbdp' ) : __( 'per boeking', 'sbdp' );
					echo '  - ' . sprintf(
						'%s (%s): %s',
						$rule['label'],
						$scope,
						wp_strip_all_tags( self::format_amount( $rule['amount'], $order ) )
					) . PHP_EOL;
				}
			}

			echo '  ' . sprintf( '%s: %s', __( 'Totaal', 'sbdp' ), wp_strip_all_tags( self::format_amount( $row['total'], $order ) ) ) . PHP_EOL . PHP_EOL;
		}

		$cta = $sent_to_admin ? admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) : $order->get_view_order_url();
		if ( $cta ) {
			echo __( 'Bekijk order:', 'sbdp' ) . ' ' . $cta . PHP_EOL;
		}
	}

	/**
	 * Render HTML email block.
	 */
	private static function render_html_email( array $rows, array $summary, WC_Order $order, $sent_to_admin ) {
		echo '<div style="margin:20px 0;padding:16px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc">';
		echo '<p style="margin:0 0 12px;font-weight:600;color:#0f172a">' . esc_html__( 'Programma samenvatting', 'sbdp' ) . '</p>';
		echo '<div style="display:flex;flex-wrap:wrap;gap:12px;">';
		printf( '<div style="flex:1 1 140px"><strong>%s</strong> %d</div>', esc_html__( 'Items:', 'sbdp' ) . ' ', (int) $summary['items_count'] );
		printf( '<div style="flex:1 1 180px"><strong>%s</strong> %d</div>', esc_html__( 'Deelnemers:', 'sbdp' ) . ' ', (int) $summary['total_participants'] );
		printf( '<div style="flex:1 1 200px"><strong>%s</strong> %s</div>', esc_html__( 'Totaal:', 'sbdp' ) . ' ', esc_html( $summary['grand_total_label'] ) );
		echo '</div>';
		echo '</div>';

		echo '<table class="sbdp-email-table" cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #e2e8f0;border-collapse:collapse">';
		echo '<thead><tr>';
		echo '<th style="text-align:left;border-bottom:1px solid #e2e8f0">' . esc_html__( 'Activiteit', 'sbdp' ) . '</th>';
		echo '<th style="text-align:left;border-bottom:1px solid #e2e8f0">' . esc_html__( 'Wanneer', 'sbdp' ) . '</th>';
		echo '<th style="text-align:left;border-bottom:1px solid #e2e8f0">' . esc_html__( 'Resource', 'sbdp' ) . '</th>';
		echo '<th style="text-align:center;border-bottom:1px solid #e2e8f0">' . esc_html__( 'Deelnemers', 'sbdp' ) . '</th>';
		echo '<th style="text-align:right;border-bottom:1px solid #e2e8f0">' . esc_html__( 'Totaal', 'sbdp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$participants_label = sprintf(
				'%d %s',
				(int) $row['participants'],
				1 === (int) $row['participants'] ? __( 'persoon', 'sbdp' ) : __( 'personen', 'sbdp' )
			);
			$time_label         = sprintf(
				'%s<br><small style="color:#475569">%s - %s</small>',
				esc_html( self::format_date( $row['start'], $order ) ),
				esc_html( self::format_time( $row['start'], $order ) ),
				esc_html( self::format_time( $row['end'], $order ) )
			);
			$resource_label     = $row['resource']['label'] ? esc_html( $row['resource']['label'] ) : esc_html__( 'Niet toegewezen', 'sbdp' );

			echo '<tr style="border-bottom:1px solid #e2e8f0">';
			echo '<td style="padding:8px 6px">' . esc_html( $row['name'] );
			if ( ! empty( $row['pricing']['applied_rules'] ) ) {
				echo '<br><small style="color:#475569">';
				$rule_bits = array();
				foreach ( $row['pricing']['applied_rules'] as $rule ) {
					$scope       = ( 'participant' === ( $rule['scope'] ?? '' ) ) ? __( 'per deelnemer', 'sbdp' ) : __( 'per boeking', 'sbdp' );
					$rule_bits[] = sprintf(
						'%s (%s: %s)',
						esc_html( $rule['label'] ),
						esc_html( $scope ),
						esc_html( self::format_amount( $rule['amount'], $order ) )
					);
				}
				echo implode( '<br>', $rule_bits );
				echo '</small>';
			}
			echo '</td>';
			echo '<td style="padding:8px 6px">' . $time_label . '</td>';
			echo '<td style="padding:8px 6px">' . $resource_label . '</td>';
			echo '<td style="padding:8px 6px;text-align:center">' . esc_html( $participants_label ) . '</td>';
			echo '<td style="padding:8px 6px;text-align:right;font-weight:600">' . esc_html( self::format_amount( $row['total'], $order ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$cta      = $sent_to_admin ? admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) : $order->get_view_order_url();
		$cta_text = $sent_to_admin ? __( 'Open order in beheer', 'sbdp' ) : __( 'Bekijk je bestelling', 'sbdp' );
		if ( $cta ) {
			printf(
				'<p style="margin-top:16px"><a href="%1$s" style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;border-radius:4px;text-decoration:none">%2$s</a></p>',
				esc_url( $cta ),
				esc_html( $cta_text )
			);
		}
	}

	/**
	 * Format ISO string using store timezone.
	 */
	private static function format_datetime( $iso, WC_Order $order ) {
		$timestamp = strtotime( $iso );
		if ( ! $timestamp ) {
			return $iso;
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp, self::get_order_timezone( $order ) );
	}

	/**
	 * Format date portion.
	 */
	private static function format_date( $iso, WC_Order $order ) {
		$timestamp = strtotime( $iso );
		if ( ! $timestamp ) {
			return $iso;
		}
		return wp_date( get_option( 'date_format' ), $timestamp, self::get_order_timezone( $order ) );
	}

	/**
	 * Format time portion.
	 */
	private static function format_time( $iso, WC_Order $order ) {
		$timestamp = strtotime( $iso );
		if ( ! $timestamp ) {
			return '';
		}
		return wp_date( get_option( 'time_format' ), $timestamp, self::get_order_timezone( $order ) );
	}

	/**
	 * Format a currency amount safely.
	 */
	private static function format_amount( $amount, WC_Order $order ) {
		$total = (float) $amount;
		if ( function_exists( 'wc_price' ) ) {
			return wc_price( $total, array( 'currency' => $order->get_currency() ) );
		}
		return number_format_i18n( $total, 2 );
	}

	/**
	 * Determine the timezone for formatting.
	 */
	private static function get_order_timezone( WC_Order $order ) {
		if ( method_exists( $order, 'get_date_created' ) && $order->get_date_created() ) {
			return $order->get_date_created()->getTimezone();
		}
		return wp_timezone();
	}
}

