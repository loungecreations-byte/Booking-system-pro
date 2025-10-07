<?php

declare(strict_types=1);

namespace BSPModule\Ops\Agent;

use BSPModule\Shared\Agents\ModuleAgentInterface;

final class OpsModuleAgent implements ModuleAgentInterface {

	public function get_slug(): string {
		return 'ops';
	}

	public function get_name(): string {
		return \__( 'Operations & Logistics', 'sbdp' );
	}

	public function boot(): void {
		\do_action( 'bsp/agent/ops/boot', $this );
	}

	public function status(): array {
		$status = array(
			'planner'   => 'ok',
			'resources' => 'ok',
			'scheduler' => 'ok',
		);

		return \apply_filters( 'bsp/agent/ops/status', $status, $this );
	}
}
