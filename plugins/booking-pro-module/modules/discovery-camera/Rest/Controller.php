<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Rest;

use BSP\DiscoveryCamera\Content\PhotoChallengeMeta;
use BSP\DiscoveryCamera\Domain\PhotoChallenge;
use BSP\DiscoveryCamera\Service\PhotoAttemptService;
use BSP\DiscoveryCamera\Support\FeatureFlags;
use BSP\Experience\Service\ExperienceAccessPolicy;
use BSP\ExperienceBuilder\Service\ModuleCompletionService;
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

    public static function authorize(WP_REST_Request $request)
    {
        if (is_user_logged_in()) {
            return true;
        }

        $session = sanitize_text_field((string) $request->get_header('X-DDB-Tour-Session'));
        if ($session === '' || ! class_exists('\SBDP_Private_Tours_Tickets')) {
            return new WP_Error('rest_forbidden', 'Open deze foto-opdracht via je persoonlijke tourlink.', array('status' => 401));
        }

        $ticket = \SBDP_Private_Tours_Tickets::get_ticket_by_session($session);
        if (! is_array($ticket) || (string) ($ticket['status'] ?? '') === 'disabled') {
            return new WP_Error('rest_forbidden', 'Je toursessie is ongeldig of verlopen.', array('status' => 401));
        }

        $userId = 0;
        if (preg_match('/^user:(\d+)$/', (string) ($ticket['issued_to'] ?? ''), $match)) {
            $candidate = absint($match[1]);
            $userId = $candidate > 0 && get_user_by('id', $candidate) ? $candidate : 0;
        }

        $request->set_param('ddb_ticket_record', $ticket);
        $request->set_param('ddb_actor_user_id', $userId);
        $request->set_param('ddb_actor_ticket_id', absint($ticket['id'] ?? 0));
        return true;
    }

    public static function challenge(WP_REST_Request $request)
    {
        $context = self::resolveContext(absint($request['tour_id']), absint($request['step_id']), $request);
        if (is_wp_error($context)) {
            return $context;
        }

        $challenge = $context['challenge'];
        $referenceId = absint($challenge['reference_image_id'] ?? 0);
        $voiceId = absint($challenge['voice_intro']['attachment_id'] ?? 0);
        $challenge['reference_image_url'] = $referenceId > 0 ? (string) wp_get_attachment_image_url($referenceId, 'large') : '';
        $challenge['voice_intro']['url'] = $voiceId > 0 ? (string) wp_get_attachment_url($voiceId) : '';
        $bossProgress = (string) ($challenge['interaction_type'] ?? '') === 'boss'
            ? (new \BSP\DiscoveryCamera\Service\BossProgressService())->get(
                self::actorUserId($request),
                absint($request['step_id']),
                self::actorTicketId($request)
            )
            : array();
        if (
            (string) ($challenge['interaction_type'] ?? '') === 'boss'
            && empty($bossProgress['targets'])
        ) {
            $bossProgress = array(
                'targets' => array_map(static fn (array $target): array => array(
                    'key' => sanitize_title(remove_accents((string) ($target['label'] ?? ''))),
                    'label' => sanitize_text_field((string) ($target['label'] ?? '')),
                    'required' => max(1, absint($target['count'] ?? 1)),
                    'found' => 0,
                    'completed' => false,
                ), (array) ($challenge['boss_targets'] ?? array())),
                'completed' => false,
            );
        }
        $response = rest_ensure_response(array('challenge' => $challenge, 'boss_progress' => $bossProgress));
        $response->header('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    public static function createAttempt(WP_REST_Request $request)
    {
        $payload = (array) $request->get_json_params();
        if (empty($payload['consent'])) {
            return new WP_Error('photo_consent_required', 'Toestemming voor privéfotoanalyse is verplicht.', array('status' => 400));
        }
        $tourId = absint($payload['tour_id'] ?? 0);
        $stepId = absint($payload['step_id'] ?? 0);
        $context = self::resolveContext($tourId, $stepId, $request);
        if (is_wp_error($context)) {
            return $context;
        }
        $userId = self::actorUserId($request);
        $rate = self::consumeRateLimit($userId);
        if (is_wp_error($rate)) {
            return $rate;
        }

        $key = trim($request->get_header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 191) {
            return new WP_Error('invalid_idempotency_key', 'Een geldige Idempotency-Key is verplicht.', array('status' => 400));
        }

        $result = (new PhotoAttemptService())->create(
            $userId,
            $tourId,
            $stepId,
            $context['challenge'],
            $key,
            strtolower(sanitize_text_field((string) ($payload['upload_hash'] ?? ''))),
            self::actorTicketId($request)
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
        $attempt = (new PhotoAttemptService())->resultForUser(
            sanitize_text_field((string) $request['uuid']),
            self::actorUserId($request),
            self::actorTicketId($request)
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
            self::actorUserId($request),
            self::actorTicketId($request)
        );
        if ($attempt === null) {
            return new WP_Error('photo_attempt_not_found', 'Fotopoging niet gevonden.', array('status' => 404));
        }

        $context = self::resolveContext((int) $attempt['tour_id'], (int) $attempt['step_id'], $request);
        if (is_wp_error($context)) {
            return $context;
        }
        $file = isset($_FILES['photo']) && is_array($_FILES['photo']) ? $_FILES['photo'] : array();
        $result = $service->completeUpload(
            (string) $request['uuid'],
            self::actorUserId($request),
            $file,
            $context['challenge'],
            self::actorTicketId($request)
        );
        if (is_wp_error($result)) {
            return $result;
        }

        $response = rest_ensure_response($result);
        $response->header('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    /** @return array<string,mixed>|WP_Error */
    private static function resolveContext(int $tourId, int $stepId, WP_REST_Request $request)
    {
        if (! FeatureFlags::enabledForTour($tourId)) {
            return new WP_Error('discovery_camera_disabled', 'Discovery Camera is niet beschikbaar voor deze tour.', array('status' => 404));
        }

        $step = get_post($stepId);
        if (! $step || $step->post_type !== 'sbdp_tour_step' || (int) $step->post_parent !== $tourId || $step->post_status !== 'publish') {
            return new WP_Error('photo_challenge_not_found', 'Photo Challenge niet gevonden.', array('status' => 404));
        }
        $legacyChallenge = (string) get_post_meta($stepId, '_sbdp_step_type', true) === 'photo_challenge';
        $moduleChallenge = class_exists(ModuleCompletionService::class)
            && ModuleCompletionService::firstModuleIdForType($stepId, 'ai_photo_challenge') !== '';
        if (! $legacyChallenge && ! $moduleChallenge) {
            return new WP_Error('invalid_chapter_type', 'Dit hoofdstuk is geen Photo Challenge.', array('status' => 409));
        }
        if (! self::canAccessTour($tourId, $request)) {
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

    private static function canAccessTour(int $tourId, WP_REST_Request $request): bool
    {
        $ticket = $request->get_param('ddb_ticket_record');
        if (is_array($ticket)) {
            return (int) ($ticket['tour_id'] ?? 0) === $tourId;
        }
        if (current_user_can('edit_post', $tourId)) {
            return true;
        }
        foreach ((new ExperienceAccessPolicy())->forUser(wp_get_current_user()) as $access) {
            if ((int) ($access['tour_id'] ?? 0) === $tourId && ! empty($access['allowed'])) {
                return true;
            }
        }

        return false;
    }

    private static function actorUserId(WP_REST_Request $request): int
    {
        return is_user_logged_in()
            ? get_current_user_id()
            : absint($request->get_param('ddb_actor_user_id'));
    }

    private static function actorTicketId(WP_REST_Request $request): int
    {
        return is_user_logged_in() ? 0 : absint($request->get_param('ddb_actor_ticket_id'));
    }

    private static function consumeRateLimit(int $userId)
    {
        $window = (int) floor(time() / MINUTE_IN_SECONDS);
        $key = 'ddb_photo_rate_' . hash('sha256', $userId . '|' . $window);
        $count = (int) get_transient($key);
        if ($count >= 10) {
            return new WP_Error('photo_rate_limited', 'Even rustig aan: probeer het over een minuut opnieuw.', array(
                'status' => 429,
                'retry_after' => MINUTE_IN_SECONDS - (time() % MINUTE_IN_SECONDS),
            ));
        }
        set_transient($key, $count + 1, 2 * MINUTE_IN_SECONDS);
        return true;
    }
}
