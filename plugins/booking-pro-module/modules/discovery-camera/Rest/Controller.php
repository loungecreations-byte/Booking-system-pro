<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Rest;

use BSP\DiscoveryCamera\Content\PhotoChallengeMeta;
use BSP\DiscoveryCamera\Domain\PhotoChallenge;
use BSP\DiscoveryCamera\Service\PhotoAttemptService;
use BSP\DiscoveryCamera\Support\FeatureFlags;
use BSP\Experience\Service\ExperienceAccessPolicy;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class Controller
{
    public static function register(): void
    {
        register_rest_route('bsp/v1', '/tours/(?P<tour_id>\d+)/chapters/(?P<step_id>\d+)/photo-challenge', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'challenge'),
            'permission_callback' => array(__CLASS__, 'authorize'),
        ));
        register_rest_route('bsp/v1', '/photo-attempts', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'createAttempt'),
            'permission_callback' => array(__CLASS__, 'authorize'),
        ));
        register_rest_route('bsp/v1', '/photo-attempts/(?P<uuid>[a-f0-9-]{36})', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'attempt'),
            'permission_callback' => array(__CLASS__, 'authorize'),
        ));
        register_rest_route('bsp/v1', '/photo-attempts/(?P<uuid>[a-f0-9-]{36})/complete-upload', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'completeUpload'),
            'permission_callback' => array(__CLASS__, 'authorize'),
        ));
    }

    public static function authorize()
    {
        return is_user_logged_in()
            ? true
            : new WP_Error('rest_forbidden', 'Log in om de Discovery Camera te gebruiken.', array('status' => 401));
    }

    public static function challenge(WP_REST_Request $request)
    {
        $context = self::resolveContext(absint($request['tour_id']), absint($request['step_id']));
        if (is_wp_error($context)) {
            return $context;
        }

        $response = rest_ensure_response(array('challenge' => $context['challenge']));
        $response->header('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    public static function createAttempt(WP_REST_Request $request)
    {
        $payload = (array) $request->get_json_params();
        $tourId = absint($payload['tour_id'] ?? 0);
        $stepId = absint($payload['step_id'] ?? 0);
        $context = self::resolveContext($tourId, $stepId);
        if (is_wp_error($context)) {
            return $context;
        }

        $key = trim($request->get_header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 191) {
            return new WP_Error('invalid_idempotency_key', 'Een geldige Idempotency-Key is verplicht.', array('status' => 400));
        }

        $result = (new PhotoAttemptService())->create(
            get_current_user_id(),
            $tourId,
            $stepId,
            $context['challenge'],
            $key,
            strtolower(sanitize_text_field((string) ($payload['upload_hash'] ?? '')))
        );
        if (isset($result['error'])) {
            return new WP_Error((string) $result['error'], 'De fotopoging kon niet worden gestart.', array('status' => 500));
        }

        $status = ! empty($result['replayed']) ? 200 : 201;
        $response = new WP_REST_Response($result, $status);
        $response->header('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    public static function attempt(WP_REST_Request $request)
    {
        $attempt = (new PhotoAttemptService())->findForUser(
            sanitize_text_field((string) $request['uuid']),
            get_current_user_id()
        );
        if ($attempt === null) {
            return new WP_Error('photo_attempt_not_found', 'Fotopoging niet gevonden.', array('status' => 404));
        }

        $response = rest_ensure_response($attempt);
        $response->header('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    public static function completeUpload(WP_REST_Request $request)
    {
        $service = new PhotoAttemptService();
        $attempt = $service->findForUser(
            sanitize_text_field((string) $request['uuid']),
            get_current_user_id()
        );
        if ($attempt === null) {
            return new WP_Error('photo_attempt_not_found', 'Fotopoging niet gevonden.', array('status' => 404));
        }

        $context = self::resolveContext((int) $attempt['tour_id'], (int) $attempt['step_id']);
        if (is_wp_error($context)) {
            return $context;
        }
        $file = isset($_FILES['photo']) && is_array($_FILES['photo']) ? $_FILES['photo'] : array();
        $result = $service->completeUpload(
            (string) $request['uuid'],
            get_current_user_id(),
            $file,
            $context['challenge']
        );
        if (is_wp_error($result)) {
            return $result;
        }

        $response = rest_ensure_response($result);
        $response->header('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    /** @return array<string,mixed>|WP_Error */
    private static function resolveContext(int $tourId, int $stepId)
    {
        if (! FeatureFlags::enabledForTour($tourId)) {
            return new WP_Error('discovery_camera_disabled', 'Discovery Camera is niet beschikbaar voor deze tour.', array('status' => 404));
        }

        $step = get_post($stepId);
        if (! $step || $step->post_type !== 'sbdp_tour_step' || (int) $step->post_parent !== $tourId || $step->post_status !== 'publish') {
            return new WP_Error('photo_challenge_not_found', 'Photo Challenge niet gevonden.', array('status' => 404));
        }
        if ((string) get_post_meta($stepId, '_sbdp_step_type', true) !== 'photo_challenge') {
            return new WP_Error('invalid_chapter_type', 'Dit hoofdstuk is geen Photo Challenge.', array('status' => 409));
        }
        if (! self::canAccessTour($tourId)) {
            return new WP_Error('experience_forbidden', 'Geen actieve toegang tot deze tour.', array('status' => 403));
        }

        $challenge = PhotoChallengeMeta::forStep($stepId);
        $errors = PhotoChallenge::validationErrors($challenge);
        if ($errors !== array()) {
            return new WP_Error('invalid_photo_challenge', 'Deze Photo Challenge is nog niet publiceerbaar.', array(
                'status' => 409,
                'validation_errors' => $errors,
            ));
        }

        return array('challenge' => $challenge, 'step' => $step);
    }

    private static function canAccessTour(int $tourId): bool
    {
        foreach ((new ExperienceAccessPolicy())->forUser(wp_get_current_user()) as $access) {
            if ((int) ($access['tour_id'] ?? 0) === $tourId && ! empty($access['allowed'])) {
                return true;
            }
        }

        return false;
    }
}
