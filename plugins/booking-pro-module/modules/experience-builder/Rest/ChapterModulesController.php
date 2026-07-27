<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\Rest;

use BSP\ExperienceBuilder\Module;
use BSP\ExperienceBuilder\Service\LegacyChapterAdapter;
use BSP\ExperienceBuilder\Service\LegacyMigrationService;
use BSP\ExperienceBuilder\Service\ModuleDocumentService;
use BSP\ExperienceBuilder\Service\ModuleCompletionService;
use BSP\ExperienceBuilder\Service\ModuleValidationService;
use BSP\Experience\Service\ExperienceAccessPolicy;
use WP_Error;
use WP_REST_Request;

final class ChapterModulesController
{
    public static function register(): void
    {
        register_rest_route('bsp/v1', '/experience-builder/chapters/(?P<chapter_id>\d+)/modules', array(
            array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'read'),
                'permission_callback' => array(__CLASS__, 'authorize'),
            ),
            array(
                'methods' => 'PUT',
                'callback' => array(__CLASS__, 'save'),
                'permission_callback' => array(__CLASS__, 'authorize'),
            ),
        ));
        register_rest_route('bsp/v1', '/experience-builder/tours/(?P<tour_id>\d+)/chapters/(?P<chapter_id>\d+)/modules/(?P<module_id>[a-f0-9-]{36})/complete', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'complete'),
            'permission_callback' => array(__CLASS__, 'authorizeCompletion'),
        ));
        register_rest_route('bsp/v1', '/experience-builder/chapters/(?P<chapter_id>\d+)/migration', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'migration'),
            'permission_callback' => array(__CLASS__, 'authorize'),
        ));
    }

    public static function authorizeCompletion(WP_REST_Request $request)
    {
        $tourId = absint($request['tour_id']);
        $chapterId = absint($request['chapter_id']);
        if (get_post_type($tourId) !== 'sbdp_private_tour' || get_post_type($chapterId) !== 'sbdp_tour_step' || (int) wp_get_post_parent_id($chapterId) !== $tourId) {
            return new WP_Error('invalid_tour_chapter', 'Dit hoofdstuk hoort niet bij de tour.', array('status' => 404));
        }
        $session = sanitize_text_field((string) $request->get_header('X-DDB-Tour-Session'));
        if ($session !== '' && class_exists('\SBDP_Private_Tours_Tickets')) {
            $ticket = \SBDP_Private_Tours_Tickets::get_ticket_by_session($session);
            if (is_array($ticket) && (int) ($ticket['tour_id'] ?? 0) === $tourId && in_array((string) ($ticket['status'] ?? ''), array('active', 'preview'), true)) {
                $request->set_param('ddb_module_ticket_id', absint($ticket['id'] ?? 0));
                return true;
            }
        }
        if (is_user_logged_in()) {
            foreach ((new ExperienceAccessPolicy())->forUser(wp_get_current_user()) as $access) {
                if ((int) ($access['tour_id'] ?? 0) === $tourId && ! empty($access['allowed'])) {
                    return true;
                }
            }
        }

        return new WP_Error('tour_access_required', 'Voor deze tour is geldige toegang vereist.', array('status' => 403));
    }

    public static function complete(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();

        return (new ModuleCompletionService())->complete(
            absint($request['tour_id']),
            absint($request['chapter_id']),
            sanitize_text_field((string) $request['module_id']),
            is_user_logged_in() ? get_current_user_id() : 0,
            absint($request->get_param('ddb_module_ticket_id')),
            is_array($payload['evidence'] ?? null) ? $payload['evidence'] : array()
        );
    }

    public static function authorize(WP_REST_Request $request)
    {
        $chapterId = absint($request['chapter_id']);
        if ($chapterId <= 0 || get_post_type($chapterId) !== 'sbdp_tour_step') {
            return new WP_Error('invalid_chapter', 'Het hoofdstuk bestaat niet.', array('status' => 404));
        }

        return current_user_can('edit_post', $chapterId)
            ? true
            : new WP_Error('chapter_modules_forbidden', 'Je mag dit hoofdstuk niet bewerken.', array('status' => 403));
    }

    public static function read(WP_REST_Request $request)
    {
        $chapterId = absint($request['chapter_id']);
        $service = self::service();
        $stored = get_post_meta($chapterId, ModuleDocumentService::META_KEY, true);
        $document = $service->get($chapterId);
        $response = rest_ensure_response(array(
            'document' => $document,
            'has_modular_document' => is_array($stored) && $stored !== array(),
            'legacy_preview' => (new LegacyChapterAdapter())->virtualDocument($chapterId),
            'definitions' => Module::registry()->definitions(),
            'migration' => (new LegacyMigrationService())->dryRun($chapterId),
        ));
        $response->header('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    public static function migration(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        $action = sanitize_key((string) ($payload['action'] ?? ''));
        $confirmation = sanitize_text_field((string) ($payload['confirmation'] ?? ''));
        if (! in_array($action, array('migrate', 'rollback'), true) || $confirmation === '') {
            return new WP_Error('invalid_migration_request', 'Actie en bevestiging zijn verplicht.', array('status' => 400));
        }
        $service = new LegacyMigrationService();
        $result = $action === 'migrate'
            ? $service->migrate(absint($request['chapter_id']), $confirmation)
            : $service->rollback(absint($request['chapter_id']), $confirmation);
        if (is_wp_error($result)) {
            return $result;
        }
        $response = rest_ensure_response($result);
        $response->header('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    public static function save(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload) || ! is_array($payload['document'] ?? null)) {
            return new WP_Error('invalid_chapter_modules', 'Een module-document is verplicht.', array('status' => 400));
        }
        $encoded = wp_json_encode($payload['document']);
        if (! is_string($encoded) || strlen($encoded) > 512 * KB_IN_BYTES) {
            return new WP_Error('chapter_modules_too_large', 'Het module-document is te groot.', array('status' => 413));
        }

        $result = self::service()->save(
            absint($request['chapter_id']),
            $payload['document'],
            absint($payload['expected_revision'] ?? 0)
        );
        if (is_wp_error($result)) {
            return $result;
        }

        $response = rest_ensure_response($result);
        $response->header('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    private static function service(): ModuleDocumentService
    {
        return new ModuleDocumentService(new ModuleValidationService(Module::registry()));
    }
}
