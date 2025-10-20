<?php

declare(strict_types=1);

namespace BSPModule\Finance\Agent;

use BSPModule\Shared\Agents\ModuleAgentInterface;

final class FinanceModuleAgent implements ModuleAgentInterface {

	public function get_slug(): string {
		return 'finance';
	}

	public function get_name(): string {
		return \__( 'Finance & Compliance', 'sbdp' );
	}

	public function boot(): void {
		\do_action( 'bsp/agent/finance/boot', $this );
	}

	public function status(): array {
		$status = array(
			'invoicing' => 'ok',
			'payouts'   => 'ok',
			'tax_rules' => 'ok',
		);

		return \apply_filters( 'bsp/agent/finance/status', $status, $this );
	}
}
