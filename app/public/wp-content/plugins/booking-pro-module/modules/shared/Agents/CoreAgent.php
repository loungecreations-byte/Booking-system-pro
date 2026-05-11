<?php
declare(strict_types=1);

namespace BSPModule\Shared\Agents;

use function array_values;
use function do_action;
use function spl_object_hash;

/**
 * Collects module agents and exposes diagnostics for REST/CLI surfaces.
 */
final class CoreAgent
{
    private static ?self $instance = null;

    /**
     * @var array<string, ModuleAgentInterface>
     */
    private array $agents = [];

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function register_agent(ModuleAgentInterface $agent): void
    {
        $slug = $agent->get_slug();
        if ('' === $slug) {
            $slug = spl_object_hash($agent);
        }

        $this->agents[$slug] = $agent;

        if (\function_exists('do_action')) { // @codeCoverageIgnore
            do_action('bsp/agent/registered', $slug, $agent);
        }
    }

    /**
     * Execute boot routines for all registered agents.
     */
    public function boot(): void
    {
        foreach ($this->agents as $agent) {
            $agent->boot();
        }

        if (\function_exists('do_action')) { // @codeCoverageIgnore
            do_action('bsp/agent/booted', array_values($this->agents));
        }
    }

    /**
     * Gather diagnostic payload from registered agents.
     *
     * @return array<string,array<string,mixed>>
     */
    public function diagnostics(): array
    {
        $report = [];

        foreach ($this->agents as $slug => $agent) {
            $status        = $agent->status();
            $report[$slug] = is_array($status) ? $status : [];
        }

        return $report;
    }
}
