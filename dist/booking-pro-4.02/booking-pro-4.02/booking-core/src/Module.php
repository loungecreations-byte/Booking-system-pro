<?php

declare(strict_types=1);

namespace BSPModule\Core;

use BSPModule\Core\Admin\AdminMenu;
use BSPModule\Core\Admin\AdminScheduler;
use BSPModule\Core\Agent\CoreModuleAgent;
use BSPModule\Core\Assets\EnqueueService;
use BSPModule\Core\Emails\EmailsService;
use BSPModule\Core\PostType\BookablePostTypes;
use BSPModule\Core\Resource\ResourceMeta;
use BSPModule\Core\Rest\RestService;
use BSPModule\Core\Services\LegacyBootstrap;
use BSPModule\Core\Shortcodes\Shortcodes;
use BSPModule\Core\WooCommerce\Display\MetaDisplay;
use BSPModule\Core\WooCommerce\Display\ProductForm;
use BSPModule\Core\WooCommerce\ProductType\BookableServiceProductType;
use BSPModule\Shared\Modules\ModuleInterface;

final class Module implements ModuleInterface {

	public function moduleName(): string {
		return 'booking-core';
	}

	public function register(): void {
		BookablePostTypes::init();
		BookableServiceProductType::init();
		AdminMenu::init();
		AdminScheduler::init();
		Shortcodes::init();
		EnqueueService::init();
		EmailsService::init();
		ResourceMeta::init();
		MetaDisplay::init();
		ProductForm::init();
		RestService::init();
		LegacyBootstrap::boot();
		if ( class_exists( 'BSP_Core_Agent' ) ) {
			\BSP_Core_Agent::instance()->register_agent( new CoreModuleAgent() );
		}

		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		do_action( 'bsp/module/register', $this->moduleName(), $this );
	}
}







