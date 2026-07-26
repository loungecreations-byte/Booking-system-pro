<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Service;

use BSP\DiscoveryCamera\Content\PhotoChallengeMeta;
use WP_Error;
use wpdb;

final class CommunityService
{
    private wpdb $db;

    public function __construct(?wpdb $db = null)
    {
        global $wpdb;
        $this->db = $db ?? $wpdb;
    }

    /** @return array<string,mixed>|WP_Error */
    public function submit(string $uuid, int $userId, string $caption)
    {
        $attempt = $this->db->get_row($this->db->prepare(
            "SELECT id,user_id,tour_id,step_id,status,private_object_key FROM {$this->db->prefix}bsp_photo_attempts WHERE attempt_uuid=%s AND user_id=%d",
            $uuid,
            $userId
        ), ARRAY_A);
        if (! is_array($attempt) || (string) $attempt['status'] !== 'passed') {
            return new WP_Error('community_attempt_invalid', 'Alleen een geslaagde eigen foto kan worden ingestuurd.', array('status' => 409));
        }
        $challenge = PhotoChallengeMeta::forStep((int) $attempt['step_id']);
        if (empty($challenge['community_allowed'])) {
            return new WP_Error('community_not_allowed', 'Communitypublicatie staat voor deze opdracht uit.', array('status' => 403));
        }
        $this->db->query($this->db->prepare(
            "INSERT INTO {$this->db->prefix}bsp_photo_community (attempt_id,user_id,tour_id,step_id,status,caption,created_at) "
            . "VALUES (%d,%d,%d,%d,'pending',%s,UTC_TIMESTAMP()) "
            . "ON DUPLICATE KEY UPDATE caption=VALUES(caption),status=IF(status='rejected','pending',status)",
            (int) $attempt['id'],
            $userId,
            (int) $attempt['tour_id'],
            (int) $attempt['step_id'],
            mb_substr(sanitize_text_field($caption), 0, 280)
        ));
        $id = (int) $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->db->prefix}bsp_photo_community WHERE attempt_id=%d",
            (int) $attempt['id']
        ));
        return array('id' => $id, 'status' => 'pending');
    }

    /** @return array<int,array<string,mixed>> */
    public function feed(int $tourId = 0, int $limit = 24): array
    {
        $where = $tourId > 0 ? $this->db->prepare(' AND c.tour_id=%d', $tourId) : '';
        $rows = $this->db->get_results(
            "SELECT c.id,c.tour_id,c.step_id,c.caption,c.likes_count,c.favorites_count,c.views_count,c.created_at,u.display_name "
            . "FROM {$this->db->prefix}bsp_photo_community c LEFT JOIN {$this->db->users} u ON u.ID=c.user_id "
            . "WHERE c.status='published' {$where} ORDER BY c.likes_count DESC,c.created_at DESC LIMIT " . max(1, min(48, $limit)),
            ARRAY_A
        );
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['tour_id'] = (int) $row['tour_id'];
            $row['step_id'] = (int) $row['step_id'];
            $row['likes_count'] = (int) $row['likes_count'];
            $row['favorites_count'] = (int) $row['favorites_count'];
            $row['views_count'] = (int) $row['views_count'];
            $row['image_url'] = add_query_arg(array('action' => 'ddb_community_photo', 'photo_id' => $row['id']), admin_url('admin-post.php'));
            return $row;
        }, is_array($rows) ? $rows : array());
    }

    /** @return array<string,mixed>|WP_Error */
    public function react(int $postId, int $userId, string $type)
    {
        if (! in_array($type, array('like', 'favorite'), true)) {
            return new WP_Error('invalid_reaction', 'Ongeldige reactie.', array('status' => 400));
        }
        $published = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->db->prefix}bsp_photo_community WHERE id=%d AND status='published'",
            $postId
        ));
        if ($published !== 1) {
            return new WP_Error('community_photo_not_found', 'Communityfoto niet gevonden.', array('status' => 404));
        }
        $table = $this->db->prefix . 'bsp_photo_community_reactions';
        $exists = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE post_id=%d AND user_id=%d AND reaction_type=%s",
            $postId,
            $userId,
            $type
        ));
        if ($exists) {
            $this->db->delete($table, array('post_id' => $postId, 'user_id' => $userId, 'reaction_type' => $type), array('%d', '%d', '%s'));
            $active = false;
        } else {
            $this->db->insert($table, array('post_id' => $postId, 'user_id' => $userId, 'reaction_type' => $type, 'created_at' => gmdate('Y-m-d H:i:s')), array('%d', '%d', '%s', '%s'));
            $active = true;
        }
        $column = $type === 'like' ? 'likes_count' : 'favorites_count';
        $this->db->query($this->db->prepare(
            "UPDATE {$this->db->prefix}bsp_photo_community SET {$column}=(SELECT COUNT(*) FROM {$table} WHERE post_id=%d AND reaction_type=%s) WHERE id=%d",
            $postId,
            $type,
            $postId
        ));
        return array('active' => $active, 'type' => $type);
    }

    public function moderate(int $postId, bool $approved, int $reviewerId): bool
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT c.id,c.public_object_key,a.private_object_key FROM {$this->db->prefix}bsp_photo_community c "
            . "JOIN {$this->db->prefix}bsp_photo_attempts a ON a.id=c.attempt_id WHERE c.id=%d",
            $postId
        ), ARRAY_A);
        if (! is_array($row)) {
            return false;
        }
        $publicKey = (string) ($row['public_object_key'] ?? '');
        if ($approved && $publicKey === '') {
            $privateKey = sanitize_file_name((string) ($row['private_object_key'] ?? ''));
            $privateDir = (string) apply_filters('ddb/discovery_camera/private_directory', dirname(rtrim(ABSPATH, '/\\')) . DIRECTORY_SEPARATOR . 'ddb-private-media');
            $source = $privateKey !== '' && $privateKey === basename($privateKey) ? trailingslashit($privateDir) . $privateKey : '';
            $upload = wp_upload_dir();
            $communityDir = trailingslashit((string) $upload['basedir']) . 'ddb-community';
            if ($source === '' || ! is_readable($source) || ! wp_mkdir_p($communityDir)) {
                return false;
            }
            $publicKey = 'photo-' . $postId . '-' . wp_generate_password(12, false, false) . '.jpg';
            if (! copy($source, trailingslashit($communityDir) . $publicKey)) {
                return false;
            }
        }
        return false !== $this->db->update(
            $this->db->prefix . 'bsp_photo_community',
            array(
                'status' => $approved ? 'published' : 'rejected',
                'public_object_key' => $approved ? $publicKey : null,
                'moderated_at' => gmdate('Y-m-d H:i:s'),
                'moderated_by' => $reviewerId,
            ),
            array('id' => $postId),
            array('%s', '%s', '%s', '%d'),
            array('%d')
        );
    }

    public function imagePath(int $postId): string
    {
        $key = sanitize_file_name((string) $this->db->get_var($this->db->prepare(
            "SELECT public_object_key FROM {$this->db->prefix}bsp_photo_community WHERE id=%d AND status='published'",
            $postId
        )));
        if ($key === '' || $key !== basename($key)) {
            return '';
        }
        $upload = wp_upload_dir();
        $path = trailingslashit((string) $upload['basedir']) . 'ddb-community/' . $key;
        if (! is_readable($path)) {
            return '';
        }
        $this->db->query($this->db->prepare(
            "UPDATE {$this->db->prefix}bsp_photo_community SET views_count=views_count+1 WHERE id=%d",
            $postId
        ));
        return $path;
    }
}
