<?php

declare(strict_types=1);

namespace BSPModule\Sales\Agent;

use BSPModule\Shared\Agents\ModuleAgentInterface;

final class SalesModuleAgent implements ModuleAgentInterface {

	public function get_slug(): string {
		return 'sales';
	}

	public function get_name(): string {
		return \__( 'Sales & Channels', 'sbdp' );
	}

	public function boot(): void {
		\do_action( 'bsp/agent/sales/boot', $this );
	}

	public function status(): array {
		$status = array(
			'channels' => 'ok',
			'pricing'  => 'ok',
		);

		return \apply_filters( 'bsp/agent/sales/status', $status, $this );
	}
}
