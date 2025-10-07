<?php

declare(strict_types=1);

namespace BSPModule\Shared\Agents;

final class CoreAgent {

	private static ?self $instance = null;

	/** @var array<string, ModuleAgentInterface> */
	private array $agents = array();

	private bool $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function registerAgent( ModuleAgentInterface $agent ): void {
		$slug                  = $agent->get_slug();
		$this->agents[ $slug ] = $agent;

		\do_action( 'bsp/agent/registered', $agent, $this );

		if ( $this->booted ) {
			$this->bootAgent( $agent );
		}
	}

	public function register_agent( ModuleAgentInterface $agent ): void {
		$this->registerAgent( $agent );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		foreach ( $this->agents as $agent ) {
			$this->bootAgent( $agent );
		}

		\do_action( 'bsp/agents/booted', $this );
	}

	/**
	 * @return ModuleAgentInterface[]
	 */
	public function getAgents(): array {
		return array_values( $this->agents );
	}

	public function getAgent( string $slug ): ?ModuleAgentInterface {
		return $this->agents[ $slug ] ?? null;
	}

	public function diagnostics(): array {
		$report = array();

		foreach ( $this->agents as $agent ) {
			$report[ $agent->get_slug() ] = $agent->status();
		}

		return \apply_filters( 'bsp/agents/status_report', $report, $this );
	}

	private function bootAgent( ModuleAgentInterface $agent ): void {
		$agent->boot();
		\do_action( 'bsp/agent/booted', $agent, $this );
	}
}
