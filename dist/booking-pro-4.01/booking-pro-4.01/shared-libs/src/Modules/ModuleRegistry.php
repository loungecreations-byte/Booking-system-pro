<?php

declare(strict_types=1);

namespace BSPModule\Shared\Modules;

final class ModuleRegistry {

	/**
	 * @var array<string, ModuleInterface>
	 */
	private array $modules = array();

	public function add( ModuleInterface $module ): void {
		$slug = $module->moduleName();

		if ( isset( $this->modules[ $slug ] ) ) {
			return;
		}

		$this->modules[ $slug ] = $module;
	}

	/**
	 * @return ModuleInterface[]
	 */
	public function all(): array {
		return array_values( $this->modules );
	}

	public function boot(): void {
		foreach ( $this->modules as $module ) {
			$module->register();
		}
	}
}
