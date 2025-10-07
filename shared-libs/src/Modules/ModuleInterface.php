<?php

declare(strict_types=1);

namespace BSPModule\Shared\Modules;

interface ModuleInterface {

	public function moduleName(): string;

	public function register(): void;
}
