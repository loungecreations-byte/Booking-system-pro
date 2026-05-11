<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

final class AssistantResponseValidator
{
    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    public function enforce(array $response): array
    {
        $response['type'] = 'assistant_response';
        $response['schema_version'] = isset($response['schema_version']) && is_string($response['schema_version'])
            ? $response['schema_version']
            : '1.0.0';
        $response['trace_id'] = isset($response['trace_id']) && is_string($response['trace_id'])
            ? $response['trace_id']
            : 'trace_unknown';

        $primary = is_array($response['primary'] ?? null) ? $response['primary'] : [];
        $response['primary'] = $this->normalisePrimary($primary);

        $alternatives = is_array($response['alternatives'] ?? null) ? array_values($response['alternatives']) : [];
        $alternatives = array_slice($alternatives, 0, 3);
        while (count($alternatives) < 3) {
            $alternatives[] = [
                'kind' => 'offer',
                'id' => 'n/a',
                'title' => 'Alternatief',
                'cta' => [],
            ];
        }
        $response['alternatives'] = array_map([$this, 'normaliseAlternative'], $alternatives);

        $plan = is_array($response['plan'] ?? null) ? $response['plan'] : [];
        $plan['timeline'] = is_array($plan['timeline'] ?? null) ? array_values($plan['timeline']) : [];
        $response['plan'] = $plan;

        $response['questions'] = is_array($response['questions'] ?? null) ? array_values($response['questions']) : [];
        $response['decision_trace'] = is_array($response['decision_trace'] ?? null) ? $response['decision_trace'] : [];

        $allCtas = [];
        $allCtas = array_merge($allCtas, $this->normaliseCtas(is_array($response['primary']['cta'] ?? null) ? $response['primary']['cta'] : []));
        foreach ($response['alternatives'] as $alternative) {
            $allCtas = array_merge($allCtas, $this->normaliseCtas(is_array($alternative['cta'] ?? null) ? $alternative['cta'] : []));
        }

        while (count($allCtas) < 12) {
            $allCtas[] = [
                'label' => 'Praat met expert',
                'url'   => function_exists('home_url') ? home_url('/contact/') : '/contact/',
                'kind'  => 'human',
            ];
        }
        $allCtas = array_slice($allCtas, 0, 12);

        $response['primary']['cta'] = array_slice($allCtas, 0, 4);
        $distribution = [3, 3, 2];
        $offset = 4;
        foreach ($response['alternatives'] as $idx => $alternative) {
            $take = $distribution[$idx] ?? 2;
            $response['alternatives'][$idx]['cta'] = array_slice($allCtas, $offset, $take);
            $offset += $take;
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $primary
     *
     * @return array<string, mixed>
     */
    private function normalisePrimary(array $primary): array
    {
        $kind = isset($primary['kind']) ? (string) $primary['kind'] : 'offer';
        if (! in_array($kind, ['offer', 'spot'], true)) {
            $kind = 'offer';
        }

        $reasonBullets = is_array($primary['reason_bullets'] ?? null) ? array_values($primary['reason_bullets']) : [];
        if ($reasonBullets === []) {
            $reasonBullets = ['Aanbeveling op basis van intent en haalbaarheid'];
        }

        return [
            'kind' => $kind,
            'id' => $primary['id'] ?? 'n/a',
            'title' => isset($primary['title']) ? (string) $primary['title'] : 'Aanbeveling',
            'reason_bullets' => $reasonBullets,
            'cta' => is_array($primary['cta'] ?? null) ? $primary['cta'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $alternative
     *
     * @return array<string, mixed>
     */
    private function normaliseAlternative(array $alternative): array
    {
        $kind = isset($alternative['kind']) ? (string) $alternative['kind'] : 'offer';
        if (! in_array($kind, ['offer', 'spot'], true)) {
            $kind = 'offer';
        }

        return [
            'kind' => $kind,
            'id' => $alternative['id'] ?? 'n/a',
            'title' => isset($alternative['title']) ? (string) $alternative['title'] : 'Alternatief',
            'cta' => is_array($alternative['cta'] ?? null) ? $alternative['cta'] : [],
        ];
    }

    /**
     * @param array<int, mixed> $ctas
     *
     * @return array<int, array<string, string>>
     */
    private function normaliseCtas(array $ctas): array
    {
        $out = [];
        foreach ($ctas as $cta) {
            if (! is_array($cta)) {
                continue;
            }

            $label = isset($cta['label']) ? trim((string) $cta['label']) : '';
            $url = isset($cta['url']) ? trim((string) $cta['url']) : '';
            $kind = isset($cta['kind']) ? trim((string) $cta['kind']) : 'refine';

            if ($label === '') {
                $label = 'Bekijk optie';
            }
            if ($url === '') {
                $url = function_exists('home_url') ? home_url('/plan-je-dag/') : '/plan-je-dag/';
            }
            if (! in_array($kind, ['book', 'refine', 'human'], true)) {
                $kind = 'refine';
            }

            $out[] = [
                'label' => $label,
                'url' => $url,
                'kind' => $kind,
            ];
        }

        return $out;
    }
}
