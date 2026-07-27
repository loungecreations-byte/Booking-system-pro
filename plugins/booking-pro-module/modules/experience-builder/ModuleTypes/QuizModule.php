<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\ModuleTypes;

use BSP\ExperienceBuilder\Domain\ModuleDefinition;

final class QuizModule extends AbstractContentModule
{
    public function type(): string { return 'quiz'; }
    protected function label(): string { return 'Quiz'; }
    protected function icon(): string { return 'editor-help'; }

    public function definition(): array
    {
        $definition = parent::definition();
        $definition['category'] = 'interactive';
        $definition['completion_modes'] = array('quiz_passed');
        $definition['events'] = array('quiz_submitted', 'quiz_completed', 'module_completed');
        $definition['defaults'] = array(
            'settings' => array('source' => 'module'),
            'content' => array('required' => true, 'pass_percentage' => 70, 'questions' => array()),
            'completion' => array('mode' => 'quiz_passed', 'requirements' => array()),
        );
        return ModuleDefinition::normalize($definition);
    }

    public function normalize(array $module): array
    {
        $content = is_array($module['content'] ?? null) ? $module['content'] : array();
        $questions = array();
        foreach (array_slice((array) ($content['questions'] ?? array()), 0, 20) as $questionIndex => $question) {
            if (! is_array($question)) {
                continue;
            }
            $questionId = sanitize_key((string) ($question['id'] ?? ('q' . ($questionIndex + 1)))) ?: 'q' . ($questionIndex + 1);
            $answers = array();
            foreach (array_slice((array) ($question['answers'] ?? array()), 0, 8) as $answerIndex => $answer) {
                if (! is_array($answer)) {
                    continue;
                }
                $answerId = sanitize_key((string) ($answer['id'] ?? ('a' . ($answerIndex + 1)))) ?: 'a' . ($answerIndex + 1);
                $answers[] = array(
                    'id' => $answerId,
                    'label' => sanitize_text_field((string) ($answer['label'] ?? '')),
                );
            }
            $questions[] = array(
                'id' => $questionId,
                'question' => sanitize_text_field((string) ($question['question'] ?? '')),
                'answers' => $answers,
                'correct_answer_ids' => array_values(array_intersect(
                    array_column($answers, 'id'),
                    array_map('sanitize_key', (array) ($question['correct_answer_ids'] ?? array()))
                )),
                'hint' => sanitize_text_field((string) ($question['hint'] ?? '')),
                'explanation' => sanitize_textarea_field((string) ($question['explanation'] ?? '')),
            );
        }
        $module['settings'] = array('source' => $questions === array() ? 'chapter_meta' : 'module');
        $module['content'] = array(
            'required' => ! array_key_exists('required', $content) || ! empty($content['required']),
            'pass_percentage' => min(100, max(0, absint($content['pass_percentage'] ?? 70))),
            'questions' => $questions,
        );
        $module['completion'] = array('mode' => 'quiz_passed', 'requirements' => array());
        return $module;
    }

    public function validate(array $module): array
    {
        $questions = (array) ($module['content']['questions'] ?? array());
        // Empty inline content deliberately falls back to existing chapter meta.
        if ($questions === array()) {
            return array();
        }
        $errors = array();
        foreach ($questions as $index => $question) {
            if (trim((string) ($question['question'] ?? '')) === '') {
                $errors[] = $this->error('content.questions.' . $index . '.question', 'quiz_question_required', 'Vul de quizvraag in.');
            }
            if (count((array) ($question['answers'] ?? array())) < 2) {
                $errors[] = $this->error('content.questions.' . $index . '.answers', 'quiz_answers_required', 'Voeg minimaal twee antwoorden toe.');
            }
            foreach ((array) ($question['answers'] ?? array()) as $answerIndex => $answer) {
                if (trim((string) ($answer['label'] ?? '')) === '') {
                    $errors[] = $this->error(
                        'content.questions.' . $index . '.answers.' . $answerIndex . '.label',
                        'quiz_answer_label_required',
                        'Vul de antwoordtekst in.'
                    );
                }
            }
            if ((array) ($question['correct_answer_ids'] ?? array()) === array()) {
                $errors[] = $this->error('content.questions.' . $index . '.correct_answer_ids', 'quiz_correct_answer_required', 'Markeer een correct antwoord.');
            }
        }

        return $errors;
    }
}
