<?php

declare(strict_types=1);

namespace BSPModule\Finance;

use BSPModule\Finance\Admin\Menu as AdminMenu;
use BSPModule\Finance\Agent\FinanceModuleAgent;
use BSPModule\Shared\Modules\ModuleInterface;

final class Module implements ModuleInterface {

	public function moduleName(): string {
		return 'booking-finance';
	}

	public function register(): void {
		AdminMenu::init();

		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		\do_action( 'bsp/module/register', $this->moduleName(), $this );

		if ( class_exists( 'BSP_Core_Agent' ) ) {
			\BSP_Core_Agent::instance()->register_agent( new FinanceModuleAgent() );
		}
	}
}
