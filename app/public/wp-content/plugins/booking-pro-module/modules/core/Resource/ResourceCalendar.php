<?php

declare(strict_types=1);

namespace BSPModule\Core\Resource;

use BSPModule\Core\Resource\ResourceCalendarSyncService;
use DateTimeImmutable;
use WP_Post;

if (class_exists(__NAMESPACE__ . '\\ResourceCalendar', false)) {
    return;
}

final class ResourceCalendar
{
    private const META_PREFIX = '_sbdp_resource_calendar_';

    public const KEY_CALENDAR_ID   = 'calendar_id';
    public const KEY_ACCESS_TOKEN  = 'access_token';
    public const KEY_REFRESH_TOKEN = 'refresh_token';
    public const KEY_EXPIRES_AT    = 'expires_at';
    public const KEY_TIMEZONE      = 'timezone';
    public const KEY_LAST_SYNC     = 'last_sync';
    public const KEY_STATUS        = 'status';
    public const KEY_BLOCKS        = 'blocks';

    private const STATUS_DISCONNECTED = 'disconnected';
    private const STATUS_CONNECTED    = 'connected';
    private const STATUS_ERROR        = 'error';

    public static function init(): void
    {
        ResourceCalendarSyncService::init();
    }

    public static function get_calendar_id(int $resource_id): ?string
    {
        return self::get_meta($resource_id, self::KEY_CALENDAR_ID);
    }

    public static function set_calendar_id(int $resource_id, ?string $calendar_id): void
    {
        self::update_meta($resource_id, self::KEY_CALENDAR_ID, $calendar_id);
    }

    public static function get_timezone(int $resource_id): ?string
    {
        return self::get_meta($resource_id, self::KEY_TIMEZONE);
    }

    public static function set_timezone(int $resource_id, ?string $timezone): void
    {
        self::update_meta($resource_id, self::KEY_TIMEZONE, $timezone);
    }

    public static function get_access_token(int $resource_id): ?string
    {
        return self::get_meta($resource_id, self::KEY_ACCESS_TOKEN);
    }

    public static function get_refresh_token(int $resource_id): ?string
    {
        return self::get_meta($resource_id, self::KEY_REFRESH_TOKEN);
    }

    public static function get_expires_at(int $resource_id): ?int
    {
        $value = self::get_meta($resource_id, self::KEY_EXPIRES_AT);
        return is_numeric($value) ? (int) $value : null;
    }

    public static function set_tokens(int $resource_id, array $tokens): void
    {
        if (isset($tokens['access_token'])) {
            self::update_meta($resource_id, self::KEY_ACCESS_TOKEN, trim((string) $tokens['access_token']));
        }
        if (isset($tokens['refresh_token'])) {
            self::update_meta($resource_id, self::KEY_REFRESH_TOKEN, trim((string) $tokens['refresh_token']));
        }
        if (isset($tokens['expires_in'])) {
            $expires_in = (int) $tokens['expires_in'];
            self::update_meta($resource_id, self::KEY_EXPIRES_AT, time() + $expires_in);
        } elseif (isset($tokens['expires_at'])) {
            $expires_at = (int) $tokens['expires_at'];
            self::update_meta($resource_id, self::KEY_EXPIRES_AT, $expires_at);
        }
    }

    public static function mark_connected(int $resource_id): void
    {
        self::update_meta($resource_id, self::KEY_STATUS, self::STATUS_CONNECTED);
    }

    public static function mark_disconnected(int $resource_id): void
    {
        self::update_meta($resource_id, self::KEY_STATUS, self::STATUS_DISCONNECTED);
    }

    public static function mark_error(int $resource_id, ?string $message = null): void
    {
        $label = self::STATUS_ERROR;
        if ($message) {
            $label .= ':' . sanitize_text_field($message);
        }
        self::update_meta($resource_id, self::KEY_STATUS, $label);
    }

    public static function get_status(int $resource_id): string
    {
        $status = self::get_meta($resource_id, self::KEY_STATUS);
        if (!is_string($status) || $status === '') {
            return self::STATUS_DISCONNECTED;
        }
        return $status;
    }

    public static function get_last_sync(int $resource_id): ?int
    {
        $value = self::get_meta($resource_id, self::KEY_LAST_SYNC);
        return is_numeric($value) ? (int) $value : null;
    }

    public static function set_last_sync(int $resource_id, int $timestamp): void
    {
        self::update_meta($resource_id, self::KEY_LAST_SYNC, $timestamp);
    }

    public static function get_calendar_blocks(int $resource_id): array
    {
        $raw = self::get_meta($resource_id, self::KEY_BLOCKS);
        if (!is_array($raw)) {
            $serialized = maybe_unserialize($raw);
            if (is_array($serialized)) {
                $raw = $serialized;
            } else {
                $raw = array();
            }
        }
        $blocks = array();
        foreach ($raw as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (empty($block['start']) || empty($block['end'])) {
                continue;
            }
            $blocks[] = array(
                'start'       => (string) $block['start'],
                'end'         => (string) $block['end'],
                'summary'     => $block['summary'] ?? '',
                'description' => $block['description'] ?? '',
            );
        }
        return $blocks;
    }

    public static function set_calendar_blocks(int $resource_id, array $blocks): void
    {
        self::update_meta($resource_id, self::KEY_BLOCKS, $blocks);
    }

    public static function get_connected_resources(): array
    {
        $args = array(
            'post_type'      => array('bookable_resource', 'bsp_city_guide'),
            'post_status'    => 'publish',
            'meta_query'     => array(
                array(
                    'key'     => self::META_PREFIX . self::KEY_CALENDAR_ID,
                    'compare' => 'EXISTS',
                ),
            ),
            'fields' => 'ids',
            'nopaging' => true,
        );
        $query = new \WP_Query($args);

        if (!is_array($query->posts)) {
            return array();
        }

        $connected = array();
        foreach ($query->posts as $resource_id) {
            if (!self::get_access_token((int) $resource_id) || !self::get_calendar_id((int) $resource_id)) {
                continue;
            }
            $connected[] = (int) $resource_id;
        }
        wp_reset_postdata();

        return $connected;
    }

    private static function get_meta(int $resource_id, string $key)
    {
        $value = get_post_meta($resource_id, self::META_PREFIX . $key, true);
        return $value === '' ? null : $value;
    }

    private static function update_meta(int $resource_id, string $key, $value): void
    {
        if ($value === null || $value === '') {
            delete_post_meta($resource_id, self::META_PREFIX . $key);
            return;
        }
        update_post_meta($resource_id, self::META_PREFIX . $key, $value);
    }
}
