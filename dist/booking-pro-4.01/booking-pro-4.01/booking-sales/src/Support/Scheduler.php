<?php

declare(strict_types=1);

namespace BSP\Sales\Support;

use BSP\Sales\Channels\ChannelManager;
use BSP\Sales\Pricing\YieldEngine;

use function add_action;
use function as_has_scheduled_action;
use function as_schedule_recurring_action;
use function as_unschedule_all_actions;
use function current_time;
use function function_exists;
use function is_wp_error;

use const DAY_IN_SECONDS;
use const HOUR_IN_SECONDS;

final class Scheduler {

	private const NIGHTLY_YIELD_HOOK       = 'bsp_sales/nightly_yield';
	private const HOURLY_CHANNEL_SYNC_HOOK = 'bsp_sales/hourly_channel_sync';

	public static function bootstrap(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::NIGHTLY_YIELD_HOOK ) ) {
			as_schedule_recurring_action( time(), DAY_IN_SECONDS, self::NIGHTLY_YIELD_HOOK, array(), 'bsp-sales' );
		}

		if ( ! as_has_scheduled_action( self::HOURLY_CHANNEL_SYNC_HOOK ) ) {
			as_schedule_recurring_action( time(), HOUR_IN_SECONDS, self::HOURLY_CHANNEL_SYNC_HOOK, array(), 'bsp-sales' );
		}

		add_action( self::NIGHTLY_YIELD_HOOK, array( self::class, 'runNightlyYield' ) );
		add_action( self::HOURLY_CHANNEL_SYNC_HOOK, array( self::class, 'runChannelSync' ) );
	}

	public static function runNightlyYield(): void {
		YieldEngine::rebuildCache();
	}

	public static function runChannelSync(): void {
		$result = ChannelManager::syncAll();
		if ( is_wp_error( $result ) ) {
			error_log( '[bsp-sales] Channel sync failed: ' . $result->get_error_message() );
		}
	}

	public static function clearScheduledActions(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::NIGHTLY_YIELD_HOOK );
		as_unschedule_all_actions( self::HOURLY_CHANNEL_SYNC_HOOK );
	}
}
