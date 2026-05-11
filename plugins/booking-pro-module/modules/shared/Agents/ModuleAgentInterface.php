<?php
declare(strict_types=1);

namespace BSPModule\Shared\Agents;

/**
 * Describes the public behaviour of module agent implementations.
 */
interface ModuleAgentInterface
{
    public function get_slug(): string;

    public function get_name(): string;

    /**
     * Execute boot-time hooks for the agent.
     */
    public function boot(): void;

    /**
     * Return diagnostics or status information for REST and CLI surfaces.
     *
     * @return array<string,mixed>
     */
    public function status(): array;
}
