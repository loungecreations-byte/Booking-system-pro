<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

use BSPModule\Core\Rest\RestService;

final class SBDP_Diagnostics_Command {

	public function check_data( array $args, array $assoc_args ): void {
		$days = isset( $assoc_args['days'] ) ? max( 1, (int) $assoc_args['days'] ) : 14;

		$products = $this->get_bookable_products();
		if ( empty( $products ) ) {
			WP_CLI::warning( 'Geen bookable_service producten gevonden.' );
			return;
		}

		WP_CLI::log( sprintf( 'Controleer %d producten voor de komende %d dagen...', count( $products ), $days ) );

		$rows = array();

		foreach ( $products as $product_id ) {
			$product_id  = (int) $product_id;
			$name        = get_the_title( $product_id );
			$issues      = array();
			$resource_id = (int) get_post_meta( $product_id, '_sbdp_resource_id', true );

			for ( $offset = 0; $offset < $days; $offset++ ) {
				$date = gmdate( 'Y-m-d', strtotime( sprintf( '+%d day', $offset ) ) );

				$availability = $this->call_plan_availability( $product_id, $resource_id, $date );
				if ( is_wp_error( $availability ) ) {
					$issues[] = sprintf( 'Availability %s: %s', $date, $availability->get_error_message() );
					continue;
				}

				if ( empty( $availability['blocks'] ) && empty( $availability['capacity'] ) ) {
					$issues[] = sprintf( 'Availability %s leeg (geen blokken/capacity).', $date );
				}

				$pricing = $this->call_pricing_preview( $product_id, $resource_id, $date );
				if ( is_wp_error( $pricing ) ) {
					$issues[] = sprintf( 'Pricing %s: %s', $date, $pricing->get_error_message() );
					continue;
				}

				if ( empty( $pricing['items'] ) ) {
					$issues[] = sprintf( 'Pricing %s retourneert geen items.', $date );
				}
			}

			$rows[] = array(
				'id'     => $product_id,
				'name'   => $name,
				'issues' => empty( $issues ) ? 'OK' : implode( "\n", $issues ),
			);
		}

		WP_CLI::success( 'Controle afgerond.' );
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'name', 'issues' ) );
	}

	private function get_bookable_products(): array {
		return get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'fields'      => 'ids',
				'numberposts' => -1,
				'tax_query'   => array(
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => array( 'bookable_service' ),
					),
				),
			)
		);
	}

	private function call_plan_availability( int $product_id, int $resource_id, string $date ) {
		$request = new WP_REST_Request( 'GET', '/sbdp/v1/availability/plan' );
		$request->set_param( 'product_id', $product_id );
		$request->set_param( 'date', $date );
		if ( $resource_id > 0 ) {
			$request->set_param( 'resource_id', $resource_id );
		}

		return RestService::plan_availability( $request );
	}

	private function call_pricing_preview( int $product_id, int $resource_id, string $date ) {
		$request = new WP_REST_Request( 'POST', '/sbdp/v1/pricing/preview' );
		$request->set_json_params(
			array(
				'items'        => array(
					array(
						'product_id'  => $product_id,
						'resource_id' => $resource_id,
						'start'       => $date . 'T10:00:00',
						'end'         => $date . 'T12:00:00',
					),
				),
				'participants' => 1,
			)
		);

		return RestService::preview_pricing( $request );
	}
}

WP_CLI::add_command( 'sbdp', 'SBDP_Diagnostics_Command' );
