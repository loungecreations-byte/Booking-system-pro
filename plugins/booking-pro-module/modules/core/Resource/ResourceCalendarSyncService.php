<?php

declare(strict_types=1);

namespace BSPModule\Core\Resource;

use DateTime;
use DateTimeImmutable;
use Exception;
use WP_Error;

if (class_exists(__NAMESPACE__ . '\\ResourceCalendarSyncService', false)) {
    return;
}

final class ResourceCalendarSyncService
{
    private const CRON_HOOK = 'sbdp_resource_calendar_sync';
    private const GOOGLE_EVENTS_ENDPOINT = 'https://www.googleapis.com/calendar/v3/calendars/%s/events';
    private const GOOGLE_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const SYNC_WINDOW_DAYS = 60;

    public static function init(): void
    {
        if (!self::is_hourly_scheduled()) {
            self::schedule_hourly();
        }
        add_action(self::CRON_HOOK, [__CLASS__, 'run_scheduled']);
    }

    public static function run_scheduled(): void
    {
        self::sync_all();
    }

    public static function sync_all(): void
    {
        foreach (ResourceCalendar::get_connected_resources() as $resource_id) {
            try {
                self::sync_resource((int) $resource_id);
            } catch (\Throwable $throwable) {
                ResourceCalendar::mark_error((int) $resource_id, $throwable->getMessage());
            }
        }
    }

    public static function sync_resource(int $resource_id)
    {
        if ($resource_id <= 0) {
            return new WP_Error('invalid_resource', __('Invalid resource supplied.', 'sbdp'));
        }

        $calendar_id = ResourceCalendar::get_calendar_id($resource_id);
        $access_token = ResourceCalendar::get_access_token($resource_id);
        $refresh_token = ResourceCalendar::get_refresh_token($resource_id);

        if (empty($calendar_id) || empty($access_token)) {
            ResourceCalendar::mark_disconnected($resource_id);
            return new WP_Error('missing_credentials', __('Google Calendar credentials missing.', 'sbdp'));
        }

        $access_token = self::ensure_access_token($resource_id, $access_token, $refresh_token);
        if (is_wp_error($access_token)) {
            ResourceCalendar::mark_error($resource_id, $access_token->get_error_message());
            return $access_token;
        }

        $time_min = (new DateTime())->format(DateTime::ATOM);
        $time_max = (new DateTime())->modify('+' . self::SYNC_WINDOW_DAYS . ' days')->format(DateTime::ATOM);
        $request_url = sprintf(self::GOOGLE_EVENTS_ENDPOINT, rawurlencode($calendar_id));
        $request_url = \add_query_arg(array(
            'timeMin'      => $time_min,
            'timeMax'      => $time_max,
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'showDeleted'  => 'false',
        ), $request_url);

        $response = wp_remote_get(
            $request_url,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                ),
                'timeout' => 20,
            )
        );

        if (is_wp_error($response)) {
            ResourceCalendar::mark_error($resource_id, $response->get_error_message());
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            ResourceCalendar::mark_error($resource_id, __('Invalid Google response.', 'sbdp'));
            return new WP_Error('invalid_response', __('Invalid data returned by Google.', 'sbdp'));
        }

        $events = $payload['items'] ?? array();
        $blocks = array();
        foreach ($events as $event) {
            $start = self::resolve_date($event['start'] ?? array());
            $end = self::resolve_date($event['end'] ?? array());
            if (!$start || !$end) {
                continue;
            }
            $blocks[] = array(
                'start'       => $start->format(DateTime::ATOM),
                'end'         => $end->format(DateTime::ATOM),
                'summary'     => isset($event['summary']) ? (string) $event['summary'] : '',
                'description' => isset($event['description']) ? (string) $event['description'] : '',
            );
        }

        ResourceCalendar::set_calendar_blocks($resource_id, $blocks);
        ResourceCalendar::set_last_sync($resource_id, time());
        ResourceCalendar::mark_connected($resource_id);

        return $blocks;
    }

    private static function resolve_date(array $candidate): ?DateTimeImmutable
    {
        $value = $candidate['dateTime'] ?? $candidate['date'] ?? '';
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Exception $exception) {
            return null;
        }
    }

    private static function ensure_access_token(int $resource_id, ?string $access_token, ?string $refresh_token)
    {
        $expires_at = ResourceCalendar::get_expires_at($resource_id);
        if ($expires_at && time() < $expires_at - 60) {
            return $access_token;
        }

        if (!$refresh_token) {
            return new WP_Error('expired_token', __('Access token expired and no refresh token stored.', 'sbdp'));
        }

        $credentials = self::get_client_credentials();
        if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
            return new WP_Error('missing_client', __('Google OAuth client credentials are not configured.', 'sbdp'));
        }

        $response = wp_remote_post(self::GOOGLE_TOKEN_ENDPOINT, array(
            'body' => array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
                'client_id'     => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $payload = json_decode($body, true);
        if (!is_array($payload) || empty($payload['access_token'])) {
            return new WP_Error('token_refresh_failed', __('Unable to refresh Google token.', 'sbdp'));
        }

        $payload['refresh_token'] = $refresh_token;
        ResourceCalendar::set_tokens($resource_id, $payload);

        return $payload['access_token'];
    }

    private static function get_client_credentials(): array
    {
        $client_id = defined('SBDP_RESOURCE_CALENDAR_CLIENT_ID') ? SBDP_RESOURCE_CALENDAR_CLIENT_ID : get_option('sbdp_resource_calendar_client_id', '');
        $client_secret = defined('SBDP_RESOURCE_CALENDAR_CLIENT_SECRET') ? SBDP_RESOURCE_CALENDAR_CLIENT_SECRET : get_option('sbdp_resource_calendar_client_secret', '');
        return array(
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        );
    }

    private static function schedule_hourly(): void
    {
        wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
    }

    private static function is_hourly_scheduled(): bool
    {
        return false !== wp_next_scheduled(self::CRON_HOOK);
    }
}
