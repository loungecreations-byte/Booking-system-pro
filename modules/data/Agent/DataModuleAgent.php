<?php

declare(strict_types=1);

namespace BSPModule\Data\Agent;

use BSPModule\Shared\Agents\ModuleAgentInterface;

final class DataModuleAgent implements ModuleAgentInterface {

	public function get_slug(): string {
		return 'data';
	}

	public function get_name(): string {
		return \__( 'Data & Analytics', 'sbdp' );
	}

	public function boot(): void {
		\do_action( 'bsp/agent/data/boot', $this );
	}

	public function status(): array {
		$status = array(
			'event_bus'  => 'ok',
			'dashboards' => 'ok',
		);

		return \apply_filters( 'bsp/agent/data/status', $status, $this );
	}
}
