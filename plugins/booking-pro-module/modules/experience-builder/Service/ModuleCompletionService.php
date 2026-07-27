<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\Service;

use BSP\Experience\Service\ExperienceProgressService;
use WP_Error;
use wpdb;

final class ModuleCompletionService
{
    private wpdb $db;

    public function __construct(?wpdb $db = null)
    {
        global $wpdb;
        $this->db = $db ?? $wpdb;
    }

    /** @return array<string,mixed>|WP_Error */
    public function complete(int $tourId, int $chapterId, string $moduleId, int $userId = 0, int $ticketId = 0, array $evidence = array())
    {
        $document = get_post_meta($chapterId, ModuleDocumentService::META_KEY, true);
        $modules = is_array($document['modules'] ?? null) ? $document['modules'] : array();
        $active = array_values(array_filter($modules, static fn ($module): bool => is_array($module) && ! empty($module['enabled'])));
        $target = null;
        foreach ($active as $module) {
            if (hash_equals((string) ($module['id'] ?? ''), $moduleId)) {
                $target = $module;
                break;
            }
        }
        if (! is_array($target)) {
            return new WP_Error('invalid_experience_module', 'Deze module hoort niet bij het hoofdstuk.', array('status' => 404));
        }
        if (! $this->conditionsSatisfied($target, $chapterId, $userId, $ticketId)) {
            return new WP_Error(
                'module_conditions_not_met',
                'Dit onderdeel is nog niet beschikbaar.',
                array('status' => 409)
            );
        }
        $completionContext = array();
        if ((string) ($target['type'] ?? '') === 'quiz') {
            $quizResult = $this->evaluateQuiz($chapterId, $evidence);
            if ($quizResult instanceof WP_Error) {
                return $quizResult;
            }
            $completionContext['quiz'] = $quizResult;
            $evidence = array('event' => 'quiz_passed');
        }
        if (
            (string) ($target['type'] ?? '') === 'reward'
            && ! $this->priorModulesCompleted($active, $moduleId, $chapterId, $userId, $ticketId)
        ) {
            return new WP_Error(
                'reward_prerequisites_incomplete',
                'Voltooi eerst de voorgaande onderdelen.',
                array('status' => 409)
            );
        }
        $evidenceError = $this->validateEvidence($target, $evidence, $tourId, $chapterId, $userId, $ticketId);
        if ($evidenceError instanceof WP_Error) {
            return $evidenceError;
        }

        $requiredIds = array_values(array_filter(array_map(static fn (array $module): string => (string) ($module['id'] ?? ''), $active)));
        if ($ticketId > 0) {
            $result = $this->completeForTicket($ticketId, $chapterId, $moduleId, $requiredIds, $completionContext);
            if (! $result instanceof WP_Error && (string) ($target['type'] ?? '') === 'reward') {
                $result['reward'] = array(
                    'pending_claim' => true,
                    'xp_amount' => (int) ($target['content']['xp_amount'] ?? 0),
                );
            }
            return $result;
        }
        if ($userId <= 0) {
            return new WP_Error('experience_actor_required', 'Een geldige gebruiker of toursessie is verplicht.', array('status' => 401));
        }

        $key = hash('sha256', implode('|', array('module_completed', $userId, $tourId, $chapterId, $moduleId)));
        $sourceId = $chapterId . ':' . $moduleId;
        $this->db->query($this->db->prepare(
            "INSERT IGNORE INTO {$this->db->prefix}bsp_experience_timeline (user_id,event_type,source_type,source_id,idempotency_key,payload_json,occurred_at,created_at) VALUES (%d,'module_completed','tour_module',%s,%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            $userId,
            $sourceId,
            $key,
            wp_json_encode(array_merge(array('tour_id' => $tourId, 'chapter_id' => $chapterId), $completionContext))
        ));
        $completedIds = $this->db->get_col($this->db->prepare(
            "SELECT source_id FROM {$this->db->prefix}bsp_experience_timeline WHERE user_id=%d AND event_type='module_completed' AND source_type='tour_module' AND source_id LIKE %s",
            $userId,
            $this->db->esc_like($chapterId . ':') . '%'
        ));
        $completedIds = array_map(static fn ($value): string => (string) substr((string) $value, strpos((string) $value, ':') + 1), (array) $completedIds);
        $chapterCompleted = count(array_intersect($requiredIds, $completedIds)) === count($requiredIds);
        if ($chapterCompleted) {
            (new ExperienceProgressService($this->db))->merge($userId, $tourId, array($chapterId), $chapterId);
        }

        $result = array_merge(
            array('module_id' => $moduleId, 'module_completed' => true, 'chapter_completed' => $chapterCompleted),
            $completionContext
        );
        if (
            (string) ($target['type'] ?? '') === 'reward'
            && class_exists('\BSP\Gamification\Service\ExperienceModuleRewardService')
        ) {
            $result['reward'] = (new \BSP\Gamification\Service\ExperienceModuleRewardService())->grant(
                $userId,
                $tourId,
                $chapterId,
                $moduleId,
                (array) ($target['content'] ?? array())
            );
        }

        return $result;
    }

    public static function firstModuleIdForType(int $chapterId, string $type): string
    {
        $document = get_post_meta($chapterId, ModuleDocumentService::META_KEY, true);
        foreach ((array) ($document['modules'] ?? array()) as $module) {
            if (
                is_array($module)
                && ! empty($module['enabled'])
                && (string) ($module['type'] ?? '') === $type
            ) {
                return (string) ($module['id'] ?? '');
            }
        }

        return '';
    }

    /** @param array<string,mixed> $module @param array<string,mixed> $evidence */
    private function validateEvidence(
        array $module,
        array $evidence,
        int $tourId,
        int $chapterId,
        int $userId,
        int $ticketId
    ): ?WP_Error
    {
        $mode = sanitize_key((string) ($module['completion']['mode'] ?? 'automatic'));
        if ($mode === 'automatic') {
            return null;
        }
        $event = sanitize_key((string) ($evidence['event'] ?? ''));
        if ($mode === 'manual' && $event === 'manual_confirmed') {
            return null;
        }
        if ($mode === 'viewer_ready' && $event === 'viewer_ready') {
            return null;
        }
        if ($mode === 'quiz_passed' && $event === 'quiz_passed') {
            return null;
        }
        if ($mode === 'server_claim' && $event === 'reward_claimed') {
            return null;
        }
        if ($mode === 'photo_approved' && $event === 'photo_approved') {
            $attemptUuid = (string) ($evidence['attempt_uuid'] ?? '');
            if (preg_match('/^[a-f0-9-]{36}$/', $attemptUuid)) {
                $attemptId = $this->db->get_var($this->db->prepare(
                    "SELECT id FROM {$this->db->prefix}bsp_photo_attempts "
                    . 'WHERE attempt_uuid=%s AND tour_id=%d AND step_id=%d AND status=%s '
                    . 'AND ((%d>0 AND ticket_id=%d) OR (%d=0 AND user_id=%d)) LIMIT 1',
                    $attemptUuid,
                    $tourId,
                    $chapterId,
                    'passed',
                    $ticketId,
                    $ticketId,
                    $ticketId,
                    $userId
                ));
                if ((int) $attemptId > 0) {
                    return null;
                }
            }
        }
        $settings = is_array($module['settings'] ?? null) ? $module['settings'] : array();
        if ($mode === 'minimum_view_time') {
            $required = min(600, max(5, absint($settings['minimum_view_seconds'] ?? 15)));
            if ($event === 'minimum_view_time_elapsed' && (float) ($evidence['elapsed_seconds'] ?? 0) >= $required) {
                return null;
            }
        }
        $requiredAnnotations = array_values(array_unique(array_map(
            'absint',
            is_array($settings['required_annotations'] ?? null) ? $settings['required_annotations'] : array()
        )));
        if ($mode === 'annotation_opened' && $event === 'annotation_opened') {
            $hasIndex = array_key_exists('annotation_index', $evidence) && is_numeric($evidence['annotation_index']);
            $opened = $hasIndex ? (int) $evidence['annotation_index'] : -1;
            if ($opened >= 0 && in_array($opened, $requiredAnnotations, true)) {
                return null;
            }
        }
        if ($mode === 'all_required_annotations' && $event === 'annotation_opened') {
            $opened = array_values(array_unique(array_map(
                'absint',
                is_array($evidence['opened_annotations'] ?? null) ? $evidence['opened_annotations'] : array()
            )));
            if ($requiredAnnotations !== array() && count(array_diff($requiredAnnotations, $opened)) === 0) {
                return null;
            }
        }

        return new WP_Error(
            'invalid_module_completion_evidence',
            'De aangeleverde voltooiingsgegevens voldoen niet aan de ingestelde opdracht.',
            array('status' => 422)
        );
    }

    /** @param array<int,string> $requiredIds @param array<string,mixed> $context @return array<string,mixed>|WP_Error */
    private function completeForTicket(int $ticketId, int $chapterId, string $moduleId, array $requiredIds, array $context = array())
    {
        if (! class_exists('\SBDP_Private_Tours_Tickets')) {
            return new WP_Error('ticket_runtime_unavailable', 'De toursessie is tijdelijk niet beschikbaar.', array('status' => 503));
        }
        $ticket = \SBDP_Private_Tours_Tickets::get_ticket_by_id($ticketId);
        if (! is_array($ticket)) {
            return new WP_Error('invalid_tour_session', 'De toursessie is ongeldig.', array('status' => 403));
        }
        $progress = \SBDP_Private_Tours_Tickets::decode_progress($ticket['progress'] ?? null);
        $entry = is_array($progress[$chapterId] ?? null) ? $progress[$chapterId] : array();
        $payload = is_array($entry['payload'] ?? null) ? $entry['payload'] : array();
        $completed = is_array($payload['module_completions'] ?? null) ? $payload['module_completions'] : array();
        $completed[$moduleId] = gmdate('c');
        $payload['module_completions'] = $completed;
        if ($context !== array()) {
            $results = is_array($payload['module_results'] ?? null) ? $payload['module_results'] : array();
            $results[$moduleId] = $context;
            $payload['module_results'] = $results;
        }
        $chapterCompleted = count(array_intersect($requiredIds, array_keys($completed))) === count($requiredIds);
        $progress[$chapterId] = array('completed' => $chapterCompleted, 'updatedAt' => gmdate('c'), 'payload' => $payload);
        \SBDP_Private_Tours_Tickets::store_progress($ticketId, $progress);

        return array_merge(
            array('module_id' => $moduleId, 'module_completed' => true, 'chapter_completed' => $chapterCompleted, 'progress' => $progress[$chapterId]),
            $context
        );
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed>|WP_Error */
    private function evaluateQuiz(int $chapterId, array $evidence)
    {
        $quiz = get_post_meta($chapterId, '_sbdp_step_quiz', true);
        $quiz = is_array($quiz) ? $quiz : array();
        $questions = is_array($quiz['questions'] ?? null) ? array_values($quiz['questions']) : array();
        $answers = is_array($evidence['answers'] ?? null) ? $evidence['answers'] : array();
        if ($questions === array()) {
            return new WP_Error('quiz_not_configured', 'Deze quiz bevat nog geen geldige vragen.', array('status' => 409));
        }
        $correct = 0;
        foreach ($questions as $index => $question) {
            $questionId = sanitize_key((string) ($question['id'] ?? ('q' . ($index + 1))));
            $answerId = sanitize_key((string) ($answers[$questionId] ?? ''));
            if ($answerId === '') {
                return new WP_Error('quiz_incomplete', 'Beantwoord eerst alle vragen.', array('status' => 422));
            }
            $correctIds = array_map('sanitize_key', (array) ($question['correct_answer_ids'] ?? array()));
            if (in_array($answerId, $correctIds, true)) {
                $correct++;
            }
        }
        $score = (int) round(($correct / count($questions)) * 100);
        $pass = min(100, max(0, absint($quiz['pass_percentage'] ?? 100)));
        if ($score < $pass) {
            return new WP_Error(
                'quiz_not_passed',
                sprintf('Je behaalde %d%%. Voor deze quiz is %d%% nodig.', $score, $pass),
                array('status' => 422, 'score' => $score, 'pass_percentage' => $pass)
            );
        }

        return array('passed' => true, 'score' => $score, 'pass_percentage' => $pass);
    }

    /** @param array<int,array<string,mixed>> $active */
    private function priorModulesCompleted(array $active, string $targetId, int $chapterId, int $userId, int $ticketId): bool
    {
        $required = array();
        foreach ($active as $module) {
            $id = (string) ($module['id'] ?? '');
            if ($id === $targetId) {
                break;
            }
            if ($id !== '') {
                $required[] = $id;
            }
        }
        if ($required === array()) {
            return true;
        }
        if ($ticketId > 0 && class_exists('\SBDP_Private_Tours_Tickets')) {
            $ticket = \SBDP_Private_Tours_Tickets::get_ticket_by_id($ticketId);
            $progress = is_array($ticket) ? \SBDP_Private_Tours_Tickets::decode_progress($ticket['progress'] ?? null) : array();
            $completed = array_keys((array) ($progress[$chapterId]['payload']['module_completions'] ?? array()));
            return count(array_diff($required, $completed)) === 0;
        }
        if ($userId <= 0) {
            return false;
        }
        $sources = $this->db->get_col($this->db->prepare(
            "SELECT source_id FROM {$this->db->prefix}bsp_experience_timeline WHERE user_id=%d AND event_type='module_completed' AND source_type='tour_module' AND source_id LIKE %s",
            $userId,
            $this->db->esc_like($chapterId . ':') . '%'
        ));
        $completed = array_map(static fn ($value): string => substr((string) $value, strpos((string) $value, ':') + 1), (array) $sources);
        return count(array_diff($required, $completed)) === 0;
    }

    /** @param array<string,mixed> $module */
    private function conditionsSatisfied(array $module, int $chapterId, int $userId, int $ticketId): bool
    {
        $conditions = (array) ($module['conditions'] ?? array());
        if ($conditions === array()) {
            return true;
        }
        $completed = array();
        $results = array();
        if ($ticketId > 0 && class_exists('\SBDP_Private_Tours_Tickets')) {
            $ticket = \SBDP_Private_Tours_Tickets::get_ticket_by_id($ticketId);
            $progress = is_array($ticket) ? \SBDP_Private_Tours_Tickets::decode_progress($ticket['progress'] ?? null) : array();
            $payload = (array) ($progress[$chapterId]['payload'] ?? array());
            $completed = array_keys((array) ($payload['module_completions'] ?? array()));
            $results = (array) ($payload['module_results'] ?? array());
        } elseif ($userId > 0) {
            $rows = $this->db->get_results($this->db->prepare(
                "SELECT source_id,payload_json FROM {$this->db->prefix}bsp_experience_timeline WHERE user_id=%d AND event_type='module_completed' AND source_type='tour_module' AND source_id LIKE %s",
                $userId,
                $this->db->esc_like($chapterId . ':') . '%'
            ), ARRAY_A);
            foreach ((array) $rows as $row) {
                $source = (string) ($row['source_id'] ?? '');
                $dependencyId = substr($source, strpos($source, ':') + 1);
                $completed[] = $dependencyId;
                $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
                if (is_array($payload)) {
                    $results[$dependencyId] = $payload;
                }
            }
        }
        foreach ($conditions as $condition) {
            $type = (string) ($condition['type'] ?? '');
            $dependencyId = (string) ($condition['module_id'] ?? '');
            if ($type === 'access_valid') {
                continue;
            }
            if (in_array($type, array('module_completed', 'photo_approved'), true) && ! in_array($dependencyId, $completed, true)) {
                return false;
            }
            if ($type === 'quiz_score_at_least') {
                $score = (int) ($results[$dependencyId]['quiz']['score'] ?? -1);
                if (! in_array($dependencyId, $completed, true) || $score < (int) ($condition['value'] ?? 0)) {
                    return false;
                }
            }
        }
        return true;
    }
}
