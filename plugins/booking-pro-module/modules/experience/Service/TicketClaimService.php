<?php
declare(strict_types=1);

namespace BSP\Experience\Service;

use WP_Error;
use WP_User;
use wpdb;

final class TicketClaimService
{
    private wpdb $db;
    public function __construct(?wpdb $db=null) { global $wpdb; $this->db=$db??$wpdb; }
    public function claim(WP_User $user,string $token)
    {
        if (! $user->exists() || $token==='') return new WP_Error('invalid_claim','Ongeldige claim.',array('status'=>400));
        $tickets=$this->db->prefix.'sbdp_private_tour_tickets';
        $ticket=$this->db->get_row($this->db->prepare("SELECT id,tour_id,email,status,progress FROM {$tickets} WHERE token=%s LIMIT 1",$token),ARRAY_A);
        if (!is_array($ticket) || !hash_equals(strtolower(trim((string)$ticket['email'])),strtolower(trim((string)$user->user_email)))) return new WP_Error('claim_forbidden','Dit ticket hoort niet bij dit account.',array('status'=>403));
        if ((string)$ticket['status']!=='active') return new WP_Error('claim_inactive','Dit ticket is niet actief.',array('status'=>409));
        $result=$this->db->query($this->db->prepare("INSERT IGNORE INTO {$this->db->prefix}bsp_experience_access_claims (user_id,ticket_id,claimed_at) VALUES (%d,%d,UTC_TIMESTAMP())",$user->ID,$ticket['id']));
        if ($result!==1) {
            $owner=(int)$this->db->get_var($this->db->prepare("SELECT user_id FROM {$this->db->prefix}bsp_experience_access_claims WHERE ticket_id=%d AND revoked_at IS NULL",$ticket['id']));
            if ($owner!==$user->ID) return new WP_Error('already_claimed','Dit ticket is al door een ander account geclaimd.',array('status'=>409));
        }
        $progress=class_exists('\SBDP_Private_Tours_Tickets')
            ? \SBDP_Private_Tours_Tickets::decode_progress($ticket['progress']??null)
            : (json_decode((string)($ticket['progress']??''),true)?:array());
        $steps=array();
        foreach ((array)$progress as $stepId=>$entry) {
            if (!is_array($entry) || empty($entry['completed']) || absint($stepId)<=0) continue;
            $stepId=absint($stepId);
            $steps[]=$stepId;
            $payload=is_array($entry['payload']??null)?$entry['payload']:array();
            $pendingXp=absint($payload['pending_xp']??0);
            $pendingBadge=sanitize_key((string)($payload['pending_badge']??''));
            if ($pendingXp<=0 && $pendingBadge==='') continue;
            $xp=(new \BSP\Gamification\Service\XpLedgerService())->award(
                (int)$user->ID,
                'photo_challenge.passed',
                'ticket_claim',
                (string)$ticket['id'].':'.$stepId,
                array('tour_id'=>(int)$ticket['tour_id'],'step_id'=>$stepId,'badge_reward'=>$pendingBadge),
                $pendingXp
            );
            $eventId=(int)($xp['event_id']??0);
            if ($eventId>0) {
                (new \BSP\Gamification\Service\CollectibleUnlockService())->consume(
                    'photo_challenge.passed',
                    (int)$user->ID,
                    (int)$ticket['tour_id'],
                    (string)$stepId,
                    $eventId,
                    array('source'=>'ticket_claim','ticket_id'=>(int)$ticket['id'])
                );
            }
        }
        (new ExperienceProgressService($this->db))->merge((int)$user->ID,(int)$ticket['tour_id'],array_values(array_unique($steps)));
        return array('success'=>true,'tour_id'=>(int)$ticket['tour_id']);
    }
}
