<?php

declare(strict_types=1);

namespace BSP\Integrations\Eliio;

use WP_Error;

class EliioAvailabilityClient
{
    private const BASE_URL = 'https://app-be-booking.eliio.com';
    private const TIMEOUT = 5;

    /**
     * @param array{productId:string,resourceId:string,branchId:string,bookingDate:string,participants:int} $query
     * @return array<string, mixed>|WP_Error
     */
    public function fetchAvailability(array $query)
    {
        $url = function_exists('add_query_arg')
            ? add_query_arg(
                array(
                    'productId'   => $query['productId'],
                    'resourceId'  => $query['resourceId'],
                    'branchId'    => $query['branchId'],
                    'bookingDate' => $query['bookingDate'],
                    'participants'=> (string) $query['participants'],
                ),
                self::BASE_URL . '/availability/widget'
            )
            : self::BASE_URL . '/availability/widget?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        if (! function_exists('wp_remote_get')) {
            return new WP_Error('ddb_eliio_http_unavailable', __('Eliio HTTP client is niet beschikbaar.', 'sbdp'), array('status' => 500));
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => self::TIMEOUT,
                'headers' => array(
                    'Accept' => 'application/json',
                ),
            )
        );

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return new WP_Error('ddb_eliio_request_failed', __('Eliio beschikbaarheidscheck mislukt.', 'sbdp'), array('status' => 502));
        }

        $statusCode = function_exists('wp_remote_retrieve_response_code')
            ? (int) wp_remote_retrieve_response_code($response)
            : 0;
        if ($statusCode < 200 || $statusCode >= 300) {
            return new WP_Error('ddb_eliio_bad_response', __('Eliio beschikbaarheidscheck gaf geen geldige response.', 'sbdp'), array('status' => 502));
        }

        $body = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($response) : '';
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return new WP_Error('ddb_eliio_invalid_json', __('Eliio beschikbaarheidscheck gaf ongeldige JSON terug.', 'sbdp'), array('status' => 502));
        }

        return $decoded;
    }
}
