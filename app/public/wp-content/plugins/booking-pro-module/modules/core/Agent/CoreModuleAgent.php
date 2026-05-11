<?php

declare(strict_types=1);

namespace BSPModule\Core\Agent;

use BSPModule\Core\Rest\AgentRestController;
use BSPModule\Shared\Agents\ModuleAgentInterface;
use WP_CLI;
use wpdb;

use function add_action;
use function apply_filters;
use function class_exists;
use function count;
use function defined;
use function do_action;
use function function_exists;
use function gmdate;
use function ini_get;
use function is_array;
use function memory_get_usage;
use function size_format;
use function sprintf;
use function wp_json_encode;
use function __;
use function WP_CLI\Utils\format_items;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class CoreModuleAgent implements ModuleAgentInterface {

	private const DEFAULT_CLI_FORMAT = 'table';

	private bool $rest_routes_hooked = false;

	public function __construct() {
		if ( did_action( 'init' ) ) {
			$this->ensure_rest_routes();
		} else {
			add_action( 'init', array( $this, 'ensure_rest_routes' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'bsp', $this );
		}
	}

	public function get_slug(): string {
		return 'core';
	}

	public function get_name(): string {
		return __( 'Core Services', 'sbdp' );
	}

	public function boot(): void {
		do_action( 'bsp/agent/core/boot', $this );
	}

	public function status(): array {
		$status = array(
			'database' => 'ok',
			'rest'     => 'ok',
		);

		return apply_filters( 'bsp/agent/core/status', $status, $this );
	}

	public function ensure_rest_routes(): void {
		if ( $this->rest_routes_hooked ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		$this->rest_routes_hooked = true;
	}

	public function register_rest_routes(): void {
		( new AgentRestController() )->register_routes();
	}

	/**
	 * Default WP-CLI command handler.
	 *
	 * @param array<int|string,mixed> $args Positional CLI arguments.
	 * @param array<string,mixed>     $assoc_args Named CLI arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! class_exists( '\\WP_CLI' ) ) {
			return;
		}

		$this->output_cli_report( $assoc_args );
	}

	/**
	 * Explicit WP-CLI subcommand handler for `bsp check`.
	 *
	 * @param array<int|string,mixed> $args Positional CLI arguments.
	 * @param array<string,mixed>     $assoc_args Named CLI arguments.
	 */
	public function check( array $args, array $assoc_args ): void {
		if ( ! class_exists( '\\WP_CLI' ) ) {
			return;
		}

		$this->output_cli_report( $assoc_args );
	}

	/**
	 * Render CLI diagnostics for registered module agents and environment metadata.
	 *
	 * @param array<string,mixed> $assoc_args Associative CLI arguments.
	 */
	private function output_cli_report( array $assoc_args ): void {
		$format  = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : self::DEFAULT_CLI_FORMAT;
		$payload = $this->build_diagnostics_payload();

		if ( 'json' === $format ) {
			WP_CLI::line(
				wp_json_encode(
					$payload,
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				)
			);

			return;
		}

		WP_CLI::line( "\u{1F680} Checking Booking System Pro core..." );
		WP_CLI::success(
			sprintf(
				'%d BSP tables detected.',
				(int) $payload['database']['table_count']
			)
		);

		$environment_rows = array(
			array(
				'metric' => 'PHP Version',
				'value'  => $payload['environment']['php_version'],
			),
			array(
				'metric' => 'Memory Limit',
				'value'  => $payload['environment']['memory_limit'],
			),
			array(
				'metric' => 'Current Usage',
				'value'  => $payload['environment']['memory_usage_human'],
			),
		);
		format_items( 'table', $environment_rows, array( 'metric', 'value' ) );

		$items = array();
		foreach ( $payload['agents'] as $slug => $status ) {
			$items[] = array(
				'agent'  => (string) $slug,
				'status' => wp_json_encode( $status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			);
		}

		format_items( 'table', $items, array( 'agent', 'status' ) );
	}

	/**
	 * Gather diagnostic payload shared by CLI and REST surfaces.
	 */
	private function build_diagnostics_payload(): array {
		global $wpdb;

		$report = \BSP_Core_Agent::instance()->diagnostics();

		$payload = array(
			'environment' => self::environment_snapshot(),
			'database'    => array(
				'table_count' => 0,
			),
			'agents'      => is_array( $report ) ? $report : array(),
			'timestamp'   => gmdate( 'c' ),
		);

		if ( $wpdb instanceof wpdb ) {
			$like                               = $wpdb->esc_like( $wpdb->prefix . 'bsp_' ) . '%';
			$tables                             = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
			$payload['database']['table_count'] = count( $tables );
		}

		if ( ! isset( $payload['agents']['core'] ) || ! is_array( $payload['agents']['core'] ) ) {
			$payload['agents']['core'] = array();
		}

		$payload['agents']['core']['tables'] = $payload['database']['table_count'];

		return $payload;
	}

	/**
	 * Provide consistent environment metrics for diagnostics.
	 */
	public static function environment_snapshot(): array {
		$memory_usage = memory_get_usage( true );
		$memory_label = function_exists( 'size_format' )
			? size_format( $memory_usage )
			: sprintf( '%d bytes', $memory_usage );

		return array(
			'php_version'        => PHP_VERSION,
			'memory_limit'       => (string) ini_get( 'memory_limit' ),
			'memory_usage_bytes' => $memory_usage,
			'memory_usage_human' => $memory_label,
		);
	}
}
