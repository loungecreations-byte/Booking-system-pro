<?php
declare(strict_types=1);

namespace BSP\Experience\Rest;

use BSP\Experience\Repository\FavoriteRepository;
use BSP\Experience\Service\ExperienceDashboardQueryService;
use BSP\Experience\Service\ExperienceAccessPolicy;
use BSP\Experience\Service\ExperienceProgressService;
use BSP\Experience\Service\TicketClaimService;
use BSP\Experience\Service\CertificateService;
use WP_Error;
use WP_REST_Request;

final class Controller
{
    public static function register(): void
    {
        register_rest_route('bsp/v1','/me/experience',array('methods'=>'GET','callback'=>array(__CLASS__,'dashboard'),'permission_callback'=>array(__CLASS__,'authorize')));
        register_rest_route('bsp/v1','/me/experience/favorites',array('methods'=>'POST','callback'=>array(__CLASS__,'addFavorite'),'permission_callback'=>array(__CLASS__,'authorize')));
        register_rest_route('bsp/v1','/me/experience/favorites/(?P<type>[a-z_]+)/(?P<id>\d+)',array('methods'=>'DELETE','callback'=>array(__CLASS__,'removeFavorite'),'permission_callback'=>array(__CLASS__,'authorize')));
        register_rest_route('bsp/v1','/me/experience/claim',array('methods'=>'POST','callback'=>array(__CLASS__,'claim'),'permission_callback'=>array(__CLASS__,'authorize')));
        register_rest_route('bsp/v1','/me/experience/progress/(?P<tour_id>\d+)',array('methods'=>'POST','callback'=>array(__CLASS__,'mergeProgress'),'permission_callback'=>array(__CLASS__,'authorize')));
        register_rest_route('bsp/v1','/me/experience/certificates/(?P<tour_id>\d+)',array('methods'=>'POST','callback'=>array(__CLASS__,'certificate'),'permission_callback'=>array(__CLASS__,'authorize')));
    }

    public static function authorize()
    {
        return is_user_logged_in() ? true : new WP_Error('rest_forbidden','Log in om je experiencegegevens te bekijken.',array('status'=>401));
    }

    public static function dashboard()
    {
        $user = wp_get_current_user();
        $response = rest_ensure_response((new ExperienceDashboardQueryService())->forUser($user));
        $response->header('Cache-Control','private, no-store, max-age=0');
        return $response;
    }

    public static function addFavorite(WP_REST_Request $request)
    {
        $payload = (array) $request->get_json_params();
        $ok = (new FavoriteRepository())->add(get_current_user_id(), sanitize_key((string)($payload['type'] ?? '')), absint($payload['id'] ?? 0));
        return $ok ? rest_ensure_response(array('success'=>true)) : new WP_Error('invalid_favorite','Dit item kan niet als favoriet worden opgeslagen.',array('status'=>400));
    }

    public static function removeFavorite(WP_REST_Request $request)
    {
        return rest_ensure_response(array('success'=>(new FavoriteRepository())->remove(get_current_user_id(),sanitize_key((string)$request['type']),absint($request['id']))));
    }

    public static function claim(WP_REST_Request $request)
    {
        $payload=(array)$request->get_json_params();
        $result=(new TicketClaimService())->claim(wp_get_current_user(),sanitize_text_field((string)($payload['ticket_token']??'')));
        return is_wp_error($result)?$result:rest_ensure_response($result);
    }

    public static function mergeProgress(WP_REST_Request $request)
    {
        $tourId=absint($request['tour_id']); if (!self::canAccessTour($tourId)) return new WP_Error('experience_forbidden','Geen actieve toegang tot deze tour.',array('status'=>403));
        $payload=(array)$request->get_json_params(); $steps=is_array($payload['completed_steps']??null)?$payload['completed_steps']:array();
        return rest_ensure_response((new ExperienceProgressService())->merge(get_current_user_id(),$tourId,$steps,absint($payload['last_step_id']??0)));
    }

    public static function certificate(WP_REST_Request $request)
    {
        $tourId=absint($request['tour_id']); if (!self::canAccessTour($tourId)) return new WP_Error('experience_forbidden','Geen actieve toegang tot deze tour.',array('status'=>403));
        $certificate=(new CertificateService())->issueIfEligible(get_current_user_id(),$tourId);
        return $certificate?rest_ensure_response(array('certificate'=>$certificate)):new WP_Error('certificate_not_ready','Voltooi eerst alle gepubliceerde tourstappen.',array('status'=>409));
    }

    private static function canAccessTour(int $tourId): bool
    {
        foreach ((new ExperienceAccessPolicy())->forUser(wp_get_current_user()) as $access) if ((int)$access['tour_id']===$tourId && !empty($access['allowed'])) return true;
        return false;
    }
}
