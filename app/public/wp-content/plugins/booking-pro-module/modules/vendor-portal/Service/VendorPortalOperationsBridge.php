<?php

declare(strict_types=1);

namespace BSP\VendorPortal\Service;

/**
 * Bridges vendor portal audit events to operations-facing hooks.
 */
final class VendorPortalOperationsBridge
{
    /**
     * @var callable
     */
    private $notificationsDispatcher;

    /**
     * @var callable
     */
    private $opsDispatcher;

    public function __construct(?callable $notificationsDispatcher = null, ?callable $opsDispatcher = null)
    {
        if ($notificationsDispatcher !== null) {
            $this->notificationsDispatcher = $notificationsDispatcher;
        } else {
            $this->notificationsDispatcher = static function (array $payload): void {
                if (function_exists('do_action')) {
                    do_action('sbdp/notifications/trigger', 'ops_vendor_portal_audit', $payload);
                }
            };
        }

        if ($opsDispatcher !== null) {
            $this->opsDispatcher = $opsDispatcher;
        } else {
            $this->opsDispatcher = static function (array $payload): void {
                if (function_exists('do_action')) {
                    do_action('sbdp/ops/vendor_portal_audit', $payload);
                }
            };
        }
    }

    public function register(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('sbdp/vendor_portal/audit_event', [$this, 'handle'], 20, 2);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function handle(string $event, array $context): void
    {
        $payload = array(
            'category'  => 'vendor_portal',
            'event'     => $event,
            'context'   => $context,
            'timestamp' => gmdate('c'),
        );

        ($this->notificationsDispatcher)($payload);
        ($this->opsDispatcher)($payload);
    }
}
