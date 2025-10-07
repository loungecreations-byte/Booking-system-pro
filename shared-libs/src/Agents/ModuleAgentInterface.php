<?php

declare(strict_types=1);

namespace BSPModule\Shared\Agents;

interface ModuleAgentInterface {

	public function get_slug(): string;

	public function get_name(): string;

	public function boot(): void;

	public function status(): array;
}
