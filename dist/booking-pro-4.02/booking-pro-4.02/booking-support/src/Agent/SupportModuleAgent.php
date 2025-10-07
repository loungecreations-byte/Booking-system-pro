<?php

declare(strict_types=1);

namespace BSPModule\Support\Agent;

use BSPModule\Shared\Agents\ModuleAgentInterface;

final class SupportModuleAgent implements ModuleAgentInterface {

	public function get_slug(): string {
		return 'support';
	}

	public function get_name(): string {
		return \__( 'Support & Service', 'sbdp' );
	}

	public function boot(): void {
		\do_action( 'bsp/agent/support/boot', $this );
	}

	public function status(): array {
		$status = array(
			'tickets'   => 'ok',
			'messaging' => 'ok',
		);

		return \apply_filters( 'bsp/agent/support/status', $status, $this );
	}
}
