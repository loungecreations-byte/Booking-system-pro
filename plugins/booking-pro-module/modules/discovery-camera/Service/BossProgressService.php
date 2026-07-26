<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Service;

use wpdb;

final class BossProgressService
{
    private wpdb $db;

    public function __construct(?wpdb $db = null)
    {
        global $wpdb;
        $this->db = $db ?? $wpdb;
    }

    /** @param array<int,array<string,mixed>> $targets @param array<int,array<string,mixed>> $detected @return array<string,mixed> */
    public function record(int $userId, int $tourId, int $stepId, array $targets, array $detected, int $ticketId = 0): array
    {
        $detectedByKey = array();
        foreach ($detected as $item) {
            $key = $this->key((string) ($item['label'] ?? ''));
            if ($key !== '') {
                $detectedByKey[$key] = max($detectedByKey[$key] ?? 0, absint($item['count'] ?? 0));
            }
        }
        foreach ($targets as $target) {
            $label = sanitize_text_field((string) ($target['label'] ?? ''));
            $key = $this->key($label);
            if ($key === '') {
                continue;
            }
            $required = min(20, max(1, absint($target['count'] ?? 1)));
            $found = min($required, max(0, (int) ($detectedByKey[$key] ?? 0)));
            $this->db->query($this->db->prepare(
                "INSERT INTO {$this->db->prefix}bsp_photo_boss_progress "
                . "(user_id,ticket_id,tour_id,step_id,target_key,target_label,required_count,found_count,updated_at) "
                . "VALUES (%d,NULLIF(%d,0),%d,%d,%s,%s,%d,%d,UTC_TIMESTAMP()) "
                . "ON DUPLICATE KEY UPDATE target_label=VALUES(target_label),required_count=VALUES(required_count),"
                . "found_count=LEAST(required_count,GREATEST(found_count,VALUES(found_count))),updated_at=UTC_TIMESTAMP()",
                $userId,
                $ticketId,
                $tourId,
                $stepId,
                $key,
                $label,
                $required,
                $found
            ));
        }
        return $this->get($userId, $stepId, $ticketId);
    }

    /** @return array<string,mixed> */
    public function get(int $userId, int $stepId, int $ticketId = 0): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT target_key,target_label,required_count,found_count FROM {$this->db->prefix}bsp_photo_boss_progress "
            . "WHERE ((%d>0 AND ticket_id=%d) OR (%d=0 AND user_id=%d)) AND step_id=%d ORDER BY id ASC",
            $ticketId,
            $ticketId,
            $ticketId,
            $userId,
            $stepId
        ), ARRAY_A);
        $items = array_map(static fn (array $row): array => array(
            'key' => (string) $row['target_key'],
            'label' => (string) $row['target_label'],
            'required' => (int) $row['required_count'],
            'found' => (int) $row['found_count'],
            'completed' => (int) $row['found_count'] >= (int) $row['required_count'],
        ), is_array($rows) ? $rows : array());
        return array(
            'targets' => $items,
            'completed' => $items !== array() && count(array_filter($items, static fn (array $item): bool => $item['completed'])) === count($items),
        );
    }

    private function key(string $label): string
    {
        return sanitize_title(remove_accents($label));
    }
}
