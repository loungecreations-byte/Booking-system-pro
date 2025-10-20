<?php

declare(strict_types=1);

namespace BSP\Sales\CLI;

use BSP\Sales\Channels\ChannelManager;
use BSP\Sales\Pricing\YieldEngine;
use WP_CLI;
use WP_CLI_Command;

use function absint;
use function current_time;
use function is_wp_error;
use function number_format_i18n;
use function sprintf;

use const ARRAY_A;

final class Commands {

	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command(
			'bsp-sales',
			new class() extends WP_CLI_Command {
				public function yield( array $args, array $assocArgs ): void {
					$action = $args[0] ?? null;
					if ( $action !== 'run' ) {
						WP_CLI::error( 'Usage: wp bsp-sales yield run' );
						return;
					}

					YieldEngine::rebuildCache();
					WP_CLI::success( 'Yield cache rebuilt.' );
				}

				public function channels( array $args, array $assocArgs ): void {
					$action = $args[0] ?? null;
					if ( $action !== 'sync' ) {
						WP_CLI::error( 'Usage: wp bsp-sales channels sync [--channel=<id|all>]' );
						return;
					}

					$channel = $assocArgs['channel'] ?? null;
					if ( $channel === 'all' ) {
						$channel = null;
					}

					$result = ChannelManager::cliSync( $channel );
					if ( is_wp_error( $result ) ) {
						WP_CLI::error( $result->get_error_message() );
						return;
					}

					foreach ( $result['details'] as $detail ) {
						WP_CLI::log( sprintf( '[Channel %d] %s (synced:%d errors:%d)', $detail['id'], $detail['status'], $detail['synced'], $detail['errors'] ) );
					}

					WP_CLI::success( sprintf( 'Channel sync processed %d channel(s).', $result['count'] ) );
				}

				public function analytics( array $args, array $assocArgs ): void {
					$action = $args[0] ?? null;
					if ( $action !== 'report' ) {
						WP_CLI::error( 'Usage: wp bsp-sales analytics report --range=<week|month>' );
						return;
					}

					$range  = $assocArgs['range'] ?? 'month';
					$report = Analytics::generate( $range );

					WP_CLI::log( 'Booking Sales Analytics Report' );
					WP_CLI::log( 'Range: ' . $report['range'] );
					WP_CLI::log( 'Generated: ' . $report['generated_at'] );
					WP_CLI::log( sprintf( 'Quotes generated: %d', $report['quotes'] ) );
					WP_CLI::log( sprintf( 'Average adjusted price: %s', number_format_i18n( $report['avg_price'], 2 ) ) );
					WP_CLI::log( sprintf( 'Channel status success: %d | failed: %d', $report['channel_success'], $report['channel_failed'] ) );
				}
			}
		);
	}
}

final class Analytics {

	public static function generate( string $range ): array {
		global $wpdb;

		$range  = $range ?: 'month';
		$window = match ( $range ) {
			'week'  => '7 DAY',
			'month' => '30 DAY',
			default => '30 DAY',
		};

		$quotes   = 0;
		$avgPrice = 0.0;
		$success  = 0;
		$failed   = 0;

		if ( $wpdb instanceof \wpdb ) {
			$table    = $wpdb->prefix . 'bsp_price_log';
			$quotes   = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table} WHERE logged_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$window})" );
			$avgPrice = (float) $wpdb->get_var( "SELECT AVG(adjusted_price) FROM {$table} WHERE logged_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$window})" );

			$channelTable = $wpdb->prefix . 'bsp_channels';
			$statuses     = $wpdb->get_results( "SELECT sync_status, COUNT(id) AS cnt FROM {$channelTable} GROUP BY sync_status", ARRAY_A ) ?: array();
			foreach ( $statuses as $row ) {
				if ( ( $row['sync_status'] ?? '' ) === 'failed' ) {
					$failed += (int) $row['cnt'];
				} else {
					$success += (int) $row['cnt'];
				}
			}
		}

		return array(
			'range'           => $range,
			'generated_at'    => current_time( 'mysql', true ),
			'quotes'          => $quotes,
			'avg_price'       => $avgPrice,
			'channel_success' => $success,
			'channel_failed'  => $failed,
		);
	}
}
