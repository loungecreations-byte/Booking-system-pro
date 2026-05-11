<?php

declare(strict_types=1);

namespace BSPModule\Core\Notifications;

use function add_action;
use function array_slice;
use function esc_attr;
use function in_array;
use function time;
use function current_user_can;
use function function_exists;
use function get_option;
use function is_array;
use function trim;
use function update_option;
use function wp_kses_post;

final class NotificationCenter
{
    private const OPTION_KEY = 'sbdp_notifications';
    private const MAX_ITEMS = 20;

    /** @var array<int,array<string,mixed>>|null */
    private static $buffer = null;

    public static function init(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('admin_notices', [__CLASS__, 'render']);
        add_action('network_admin_notices', [__CLASS__, 'render']);
    }

    /**
     * Queue a notification for the next admin page load.
     */
    public static function notify(string $message, string $type = 'info', array $options = array()): void
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }

        $queue = get_option(self::OPTION_KEY, array());
        if (! is_array($queue)) {
            $queue = array();
        }

        $queue[] = array(
            'message'     => wp_kses_post($message),
            'type'        => self::normaliseType($type),
            'capability'  => isset($options['capability']) ? (string) $options['capability'] : 'manage_options',
            'dismissible' => array_key_exists('dismissible', $options) ? (bool) $options['dismissible'] : true,
            'created_at'  => time(),
        );

        if (count($queue) > self::MAX_ITEMS) {
            $queue = array_slice($queue, -1 * self::MAX_ITEMS);
        }

        update_option(self::OPTION_KEY, $queue, false);
    }

    public static function render(): void
    {
        if (self::$buffer === null) {
            self::$buffer = self::pull();
        }

        if (self::$buffer === array()) {
            return;
        }

        $remaining = array();

        foreach (self::$buffer as $notice) {
            $capability = isset($notice['capability']) ? (string) $notice['capability'] : 'manage_options';
            if ($capability && ! current_user_can($capability)) {
                $remaining[] = $notice;
                continue;
            }

            $class = 'notice notice-' . self::normaliseType($notice['type'] ?? 'info');
            if (! empty($notice['dismissible'])) {
                $class .= ' is-dismissible';
            }

            printf(
                '<div class="%s"><p>%s</p></div>',
                esc_attr($class),
                $notice['message']
            );
        }

        self::$buffer = array();

        if ($remaining !== array()) {
            update_option(self::OPTION_KEY, $remaining, false);
        } else {
            update_option(self::OPTION_KEY, array(), false);
        }
    }

    private static function pull(): array
    {
        $queue = get_option(self::OPTION_KEY, array());
        if (! is_array($queue)) {
            $queue = array();
        }

        if ($queue !== array()) {
            update_option(self::OPTION_KEY, array(), false);
        }

        return $queue;
    }

    private static function normaliseType(string $type): string
    {
        $value = strtolower(trim($type));
        $allowed = array('success', 'warning', 'error', 'info');

        return in_array($value, $allowed, true) ? $value : 'info';
    }
}










