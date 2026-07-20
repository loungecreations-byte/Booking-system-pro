<?php
declare(strict_types=1);

namespace BSP\Experience\Service;

use BSP\Experience\Repository\FavoriteRepository;
use BSP\Gamification\Domain\LevelResolver;
use BSP\Gamification\Repository\CollectibleRepository;
use BSP\Gamification\Repository\ProgressRepository;
use WP_User;
use wpdb;

final class ExperienceDashboardQueryService
{
    private wpdb $db;
    public function __construct(?wpdb $db = null) { global $wpdb; $this->db = $db ?? $wpdb; }

    /** @return array<string,mixed> */
    public function forUser(WP_User $user): array
    {
        $access = (new ExperienceAccessPolicy($this->db))->forUser($user);
        $tours = array_map(fn(array $item): array => $this->presentTour($user->ID, $item), $access);
        $progressRepo = new ProgressRepository($this->db);
        $progress = $progressRepo->progress((int) $user->ID);
        $level = (new LevelResolver())->resolve((int) ($progress['lifetime_xp'] ?? 0));
        $badges = $progressRepo->badges((int) $user->ID);
        $collectibles = (new CollectibleRepository($this->db))->collection((int) $user->ID);
        $completedTours = array_values(array_filter($tours, static fn(array $tour): bool => $tour['completion_percent'] === 100));

        return array(
            'profile' => array('id'=>(int)$user->ID,'name'=>(string)$user->display_name,'public'=>false),
            'resume' => $this->resume($tours),
            'tours' => $tours,
            'favorites' => (new FavoriteRepository($this->db))->all((int) $user->ID),
            'progress' => array('xp'=>(int)$level['xp'],'level'=>$level,'completed_tours'=>count($completedTours)),
            'badges' => $badges,
            'collectibles' => $collectibles,
            'timeline' => $this->timeline((int) $user->ID),
            'certificates' => (new CertificateService($this->db))->all((int)$user->ID),
            'certificate_eligibility' => array_map(static fn(array $tour): array => array('tour_id'=>$tour['id'],'title'=>$tour['title'],'status'=>'eligible'), $completedTours),
            'rewards' => (new RewardService($this->db))->all((int)$user->ID),
            'discovery' => $this->discovery($tours),
            'recommendations' => $this->recommendations($tours),
            'capabilities' => array('community'=>false,'public_profile'=>false,'rewards_redemption'=>false),
        );
    }

    /** @param array<string,mixed> $access @return array<string,mixed> */
    private function presentTour(int $userId, array $access): array
    {
        $tourId = (int) $access['tour_id'];
        $stepIds = get_posts(array('post_type'=>'sbdp_tour_step','post_parent'=>$tourId,'post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1,'orderby'=>'menu_order','order'=>'ASC'));
        $completed = $this->db->get_col($this->db->prepare("SELECT step_id FROM {$this->db->prefix}bsp_tour_step_completions WHERE user_id=%d AND tour_id=%d", $userId, $tourId));
        $ticketCompleted = $this->ticketCompletedIds((array) ($access['progress'] ?? array()));
        $canonical=(new ExperienceProgressService($this->db))->get($userId,$tourId);
        $completedIds = array_unique(array_map('intval', array_merge((array)$completed, $ticketCompleted, $canonical['completed_steps'])));
        $total = count($stepIds);
        $count = count(array_intersect(array_map('intval', $stepIds), $completedIds));
        return array('id'=>$tourId,'title'=>(string)get_the_title($tourId),'url'=>(string)($access['portal_url'] ?: get_permalink($tourId)),'allowed'=>(bool)$access['allowed'],'access_reason'=>(string)$access['reason'],'expires_at'=>$access['expires_at'],'last_step_id'=>$canonical['last_step_id'],'completed_steps'=>$count,'total_steps'=>$total,'completion_percent'=>$total > 0 ? (int) floor(($count / $total) * 100) : 0);
    }

    /** @return array<int,int> */
    private function ticketCompletedIds(array $progress): array
    {
        foreach (array('completed_steps','completed','steps') as $key) {
            if (isset($progress[$key]) && is_array($progress[$key])) {
                return array_values(array_filter(array_map('intval', array_keys($progress[$key]) === range(0, count($progress[$key])-1) ? $progress[$key] : array_keys($progress[$key]))));
            }
        }
        return array();
    }

    /** @param array<int,array<string,mixed>> $tours */
    private function resume(array $tours): ?array
    {
        foreach ($tours as $tour) {
            if ($tour['allowed'] && $tour['completion_percent'] < 100) {
                return $tour;
            }
        }
        return null;
    }

    /** @return array<int,array<string,mixed>> */
    private function timeline(int $userId): array
    {
        $rows = $this->db->get_results($this->db->prepare("SELECT event_type,source_type,source_id,xp_delta,occurred_at FROM {$this->db->prefix}bsp_xp_events WHERE user_id=%d AND status='confirmed' ORDER BY occurred_at DESC,id DESC LIMIT 50", $userId), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /** @param array<int,array<string,mixed>> $tours @return array<string,mixed> */
    private function discovery(array $tours): array
    {
        $total = count($tours); $completed = count(array_filter($tours, static fn(array $tour): bool => $tour['completion_percent'] === 100));
        return array('scope'=>'owned_tours','completed'=>$completed,'total'=>$total,'percent'=>$total > 0 ? (int)floor(($completed/$total)*100) : 0);
    }

    /** @param array<int,array<string,mixed>> $tours @return array<int,array<string,mixed>> */
    private function recommendations(array $tours): array
    {
        return array_values(array_slice(array_filter($tours, static fn(array $tour): bool => $tour['allowed'] && $tour['completion_percent'] < 100), 0, 3));
    }
}
