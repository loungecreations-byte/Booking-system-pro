<?php
declare(strict_types=1);

namespace BSP\Support\Agent;

use BSPModule\Shared\Agents\ModuleAgentInterface;

use function __;
use function do_action;
use function function_exists;

final class SupportModuleAgent implements ModuleAgentInterface
{
    public function get_slug(): string
    {
        return 'support';
    }

    public function get_name(): string
    {
        return __('Support', 'sbdp');
    }

    public function boot(): void
    {
        if (function_exists('do_action')) {
            do_action('bsp/support/agent/boot', $this);
        }
    }

    public function status(): array
    {
        return [
            'status' => 'ok',
        ];
    }
}

if (! class_exists('BSPModule\\Support\\Agent\\SupportModuleAgent', false)) {
    class_alias(SupportModuleAgent::class, 'BSPModule\\Support\\Agent\\SupportModuleAgent');
}
