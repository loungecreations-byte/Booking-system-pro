<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\Service;

use BSP\ExperienceBuilder\Registry\ModuleRegistry;

final class ModuleValidationService
{
    private ModuleRegistry $registry;

    public function __construct(ModuleRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Unknown module types are preserved for forward compatibility, but reported.
     *
     * @param mixed $value
     * @return array{document:array<string,mixed>,errors:array<int,array<string,string>>,warnings:array<int,array<string,string>>}
     */
    public function normalize($value): array
    {
        $errors = array();
        $warnings = array();
        if (! is_array($value)) {
            return array(
                'document' => $this->emptyDocument(),
                'errors' => array($this->issue('', 'invalid_document', 'Het module-document moet een object zijn.')),
                'warnings' => array(),
            );
        }

        $schemaVersion = absint($value['schema_version'] ?? 1);
        if ($schemaVersion !== 1) {
            $errors[] = $this->issue('schema_version', 'unsupported_schema_version', 'Deze schema-versie wordt niet ondersteund.');
        }

        $rawModules = $value['modules'] ?? array();
        if (! is_array($rawModules)) {
            $errors[] = $this->issue('modules', 'invalid_modules', 'Modules moeten als lijst worden aangeleverd.');
            $rawModules = array();
        }
        if (count($rawModules) > 100) {
            $errors[] = $this->issue('modules', 'too_many_modules', 'Een hoofdstuk kan maximaal 100 modules bevatten.');
            $rawModules = array_slice($rawModules, 0, 100);
        }

        $ids = array();
        $typeCounts = array();
        $modules = array();
        foreach (array_values($rawModules) as $index => $rawModule) {
            $path = 'modules.' . $index;
            if (! is_array($rawModule)) {
                $errors[] = $this->issue($path, 'invalid_module', 'De module moet een object zijn.');
                continue;
            }

            $id = strtolower(trim((string) ($rawModule['id'] ?? '')));
            if (! $this->isUuid($id)) {
                $errors[] = $this->issue($path . '.id', 'invalid_module_id', 'De module-ID moet een geldige UUID zijn.');
                continue;
            }
            if (isset($ids[$id])) {
                $errors[] = $this->issue($path . '.id', 'duplicate_module_id', 'Iedere module-ID moet uniek zijn.');
                continue;
            }
            $ids[$id] = true;

            $type = sanitize_key((string) ($rawModule['type'] ?? ''));
            if ($type === '') {
                $errors[] = $this->issue($path . '.type', 'missing_module_type', 'Een moduletype is verplicht.');
                continue;
            }
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
            if (in_array($type, array('ai_photo_challenge', 'quiz'), true) && $typeCounts[$type] > 1) {
                $errors[] = $this->issue(
                    $path . '.type',
                    'duplicate_singleton_module',
                    'Dit moduletype kan maximaal één keer per hoofdstuk worden gebruikt.'
                );
            }

            $completion = is_array($rawModule['completion'] ?? null) ? $rawModule['completion'] : array();
            $normalized = array(
                'id' => $id,
                'type' => $type,
                'version' => max(1, absint($rawModule['version'] ?? 1)),
                'index' => count($modules),
                'enabled' => ! array_key_exists('enabled', $rawModule) || ! empty($rawModule['enabled']),
                'title' => sanitize_text_field((string) ($rawModule['title'] ?? '')),
                'settings' => is_array($rawModule['settings'] ?? null) ? $rawModule['settings'] : array(),
                'content' => is_array($rawModule['content'] ?? null) ? $rawModule['content'] : array(),
                'conditions' => $this->normalizeConditions($rawModule['conditions'] ?? array()),
                'completion' => array(
                    'mode' => sanitize_key((string) ($completion['mode'] ?? 'automatic')) ?: 'automatic',
                    'requirements' => is_array($completion['requirements'] ?? null) ? array_values($completion['requirements']) : array(),
                ),
                'visibility' => $this->normalizeVisibility($rawModule['visibility'] ?? array()),
                'metadata' => is_array($rawModule['metadata'] ?? null) ? $rawModule['metadata'] : array(),
            );

            $moduleType = $this->registry->get($type);
            if ($moduleType === null) {
                $warnings[] = $this->issue($path . '.type', 'unknown_module_type', 'Dit moduletype is niet geïnstalleerd en is ongewijzigd bewaard.');
                $normalized['settings'] = $rawModule['settings'] ?? array();
                $normalized['content'] = $rawModule['content'] ?? array();
            } else {
                $normalized = $moduleType->normalize($normalized);
                // Disabled modules are safe draft configuration. They are not
                // rendered or completable and may therefore be saved before
                // required media/model fields are filled.
                if (! empty($normalized['enabled'])) {
                    foreach ($moduleType->validate($normalized) as $error) {
                        $error['path'] = $path . ($error['path'] !== '' ? '.' . $error['path'] : '');
                        $errors[] = $error;
                    }
                }
                $definition = $moduleType->definition();
                if (! in_array($normalized['completion']['mode'], (array) $definition['completion_modes'], true)) {
                    $errors[] = $this->issue($path . '.completion.mode', 'unsupported_completion_mode', 'Deze completion rule wordt niet door de module ondersteund.');
                }
            }

            $modules[] = $normalized;
        }
        $positions = array();
        foreach ($modules as $index => $module) {
            $positions[(string) $module['id']] = $index;
        }
        $allowedConditions = array('module_completed', 'quiz_score_at_least', 'photo_approved', 'access_valid');
        foreach ($modules as $index => $module) {
            foreach ((array) ($module['conditions'] ?? array()) as $conditionIndex => $condition) {
                $conditionPath = 'modules.' . $index . '.conditions.' . $conditionIndex;
                $conditionType = (string) ($condition['type'] ?? '');
                if (! in_array($conditionType, $allowedConditions, true)) {
                    $errors[] = $this->issue($conditionPath . '.type', 'unsupported_condition', 'Deze voorwaarde wordt nog niet ondersteund.');
                    continue;
                }
                if ($conditionType === 'access_valid') {
                    continue;
                }
                $dependencyId = (string) ($condition['module_id'] ?? '');
                if (! isset($positions[$dependencyId]) || $positions[$dependencyId] >= $index) {
                    $errors[] = $this->issue(
                        $conditionPath . '.module_id',
                        'invalid_condition_dependency',
                        'Een voorwaarde mag alleen naar een eerdere module verwijzen.'
                    );
                }
                if (
                    $conditionType === 'quiz_score_at_least'
                    && (! is_numeric($condition['value'] ?? null) || (int) $condition['value'] < 0 || (int) $condition['value'] > 100)
                ) {
                    $errors[] = $this->issue($conditionPath . '.value', 'invalid_quiz_score_condition', 'Gebruik een score tussen 0 en 100.');
                }
            }
        }

        return array(
            'document' => array(
                'schema_version' => 1,
                'document_id' => sanitize_text_field((string) ($value['document_id'] ?? '')),
                'revision' => max(1, absint($value['revision'] ?? 1)),
                'modules' => $modules,
            ),
            'errors' => $errors,
            'warnings' => $warnings,
        );
    }

    /** @return array<string,mixed> */
    public function emptyDocument(): array
    {
        return array('schema_version' => 1, 'document_id' => '', 'revision' => 1, 'modules' => array());
    }

    /** @param mixed $conditions @return array<int,array<string,mixed>> */
    private function normalizeConditions($conditions): array
    {
        if (! is_array($conditions)) {
            return array();
        }

        $normalized = array();
        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }
            $type = sanitize_key((string) ($condition['type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $normalized[] = array(
                'type' => $type,
                'module_id' => sanitize_text_field((string) ($condition['module_id'] ?? '')),
                'operator' => sanitize_key((string) ($condition['operator'] ?? 'is')) ?: 'is',
                'value' => is_scalar($condition['value'] ?? null) ? sanitize_text_field((string) $condition['value']) : '',
            );
        }

        return $normalized;
    }

    /** @param mixed $visibility @return array<string,mixed> */
    private function normalizeVisibility($visibility): array
    {
        $visibility = is_array($visibility) ? $visibility : array();

        return array(
            'mode' => sanitize_key((string) ($visibility['mode'] ?? 'when_conditions_match')) ?: 'when_conditions_match',
        );
    }

    /** @return array<string,string> */
    private function issue(string $path, string $code, string $message): array
    {
        return array('path' => $path, 'code' => $code, 'message' => $message);
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value);
    }
}
