<?php

declare(strict_types=1);

namespace BSP\Integrations\Rest;

use BSP\Integrations\Eliio\EliioAvailabilityService;
use WP_Error;
use WP_REST_Request;

final class EliioAvailabilityController
{
    private EliioAvailabilityService $service;

    public function __construct(?EliioAvailabilityService $service = null)
    {
        $this->service = $service ?? new EliioAvailabilityService();
    }

    public function registerRoutes(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            'ddb/v1',
            '/supplier/eliio/availability',
            array(
                'methods'             => 'GET',
                'permission_callback' => '__return_true',
                'callback'            => array($this, 'handleAvailabilityRequest'),
                'args'                => array(
                    'product_id'   => array('required' => true),
                    'date'         => array('required' => true),
                    'participants' => array('required' => true),
                    'start_time'   => array('required' => false),
                ),
            )
        );
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function handleAvailabilityRequest(WP_REST_Request $request)
    {
        $productId = $this->parsePositiveInt($request->get_param('product_id'));
        $participants = $this->parsePositiveInt($request->get_param('participants'));
        $date = $this->sanitizeScalar($request->get_param('date'));
        $startTime = $this->sanitizeScalar($request->get_param('start_time'));

        if ($productId === null) {
            return new WP_Error('ddb_eliio_invalid_product', __('Ongeldig product.', 'sbdp'), array('status' => 400));
        }

        if ($participants === null) {
            return new WP_Error('ddb_eliio_invalid_participants', __('Ongeldig aantal deelnemers.', 'sbdp'), array('status' => 400));
        }

        return $this->service->check($productId, $date, $participants, $startTime);
    }

    /**
     * @param mixed $value
     */
    private function parsePositiveInt($value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $parsed = (int) $value;
        return $parsed > 0 ? $parsed : null;
    }

    /**
     * @param mixed $value
     */
    private function sanitizeScalar($value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);
        return function_exists('sanitize_text_field') ? sanitize_text_field($value) : preg_replace('/[^\w:\-]/', '', $value) ?? '';
    }
}
