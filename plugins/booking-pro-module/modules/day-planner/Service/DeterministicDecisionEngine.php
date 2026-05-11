<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

final class DeterministicDecisionEngine
{
    private const POLICY_OPTION = 'sbdp_dayplanner_decision_policy';

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $offers
     * @param array<int, array<string, mixed>> $spots
     *
     * @return array<string, mixed>
     */
    public function decide(array $input, array $context, array $offers, array $spots = []): array
    {
        $context = $this->normaliseContext($context);
        $constraints = $this->mergeConstraints($context, $input);
        $intent = $this->detectIntent($input);
        $policy = $this->decisionPolicy($intent, $constraints, $input);

        if ($intent === 'CHANGE_INTENT') {
            $context['sticky_primary_type'] = null;
            $context['sticky_primary_id'] = null;
        }

        $rankedOffers = $this->rankOffers($offers, $intent, $constraints, $policy);
        $rankedSpots = $this->rankSpots($spots, $intent, $constraints, $policy);
        $ranked = array_merge($rankedOffers, $rankedSpots);
        usort(
            $ranked,
            static function (array $left, array $right): int {
                $leftScore = (float) ($left['score'] ?? 0.0);
                $rightScore = (float) ($right['score'] ?? 0.0);
                if ($leftScore === $rightScore) {
                    $leftPriority = (float) ($left['breakdown']['manual_priority'] ?? 0.0);
                    $rightPriority = (float) ($right['breakdown']['manual_priority'] ?? 0.0);
                    if ($leftPriority === $rightPriority) {
                        return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
                    }

                    return $rightPriority <=> $leftPriority;
                }

                return $rightScore <=> $leftScore;
            }
        );

        $primary = $this->resolveStickyPrimary($context, $ranked, $intent);
        if ($primary === null && $ranked !== []) {
            $primary = $ranked[0];
        }

        $alternatives = $this->resolveAlternatives($primary, $ranked);
        $questions = [];
        $action = 'BUILD_PLAN';
        $missing = $this->missingConstraints($constraints);

        if ($intent === 'SUPPORT_INTENT' && $primary === null) {
            $action = 'SUPPORT_ONLY';
        } elseif ($intent === 'BOOKING_INTENT') {
            $action = 'SELL_PRIMARY';
        }

        if ($primary !== null) {
            $confidence = (float) ($primary['score'] ?? 0.0);
            $confidenceThreshold = (float) ($policy['confidence_threshold'] ?? 0.45);
            $maxQuestions = max(1, (int) ($policy['max_questions'] ?? 1));
            if ($confidence < $confidenceThreshold && $missing !== []) {
                $action = 'GUIDED_FLOW';
                foreach (array_slice($missing, 0, $maxQuestions) as $missingConstraint) {
                    $questions[] = $this->questionForConstraint((string) $missingConstraint);
                }
            }
            $context['sticky_primary_type'] = (string) ($primary['kind'] ?? 'offer');
            $context['sticky_primary_id'] = $primary['id'] ?? null;
        }

        $context['constraints'] = $constraints;
        $context['last_candidates_shown'] = array_values(
            array_map(
                static fn(array $candidate): array => [
                    'kind' => (string) ($candidate['kind'] ?? ''),
                    'id'   => $candidate['id'] ?? null,
                ],
                array_slice($ranked, 0, 6)
            )
        );

        $decisionTrace = [
            'policy_version'   => 'v1-deterministic',
            'intent'           => $intent,
            'selected_primary' => $primary !== null
                ? ['kind' => (string) ($primary['kind'] ?? ''), 'id' => $primary['id'] ?? null]
                : ['kind' => null, 'id' => null],
            'policy'           => [
                'confidence_threshold' => (float) ($policy['confidence_threshold'] ?? 0.45),
                'max_questions'        => (int) ($policy['max_questions'] ?? 1),
                'offer_bias'           => (float) ($policy['offer_bias'] ?? 1.0),
                'spot_bias'            => (float) ($policy['spot_bias'] ?? 1.0),
            ],
            'score_breakdown'  => $this->buildBreakdownMap($ranked),
            'discarded'        => $this->buildDiscarded($primary, $ranked),
        ];

        return [
            'intent'         => $intent,
            'action'         => $action,
            'primary'        => $primary,
            'alternatives'   => $alternatives,
            'questions'      => $questions,
            'decision_trace' => $decisionTrace,
            'context'        => $context,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function detectIntent(array $input): string
    {
        $message = strtolower(trim((string) ($input['message'] ?? $input['query'] ?? '')));
        $intentHint = strtolower(trim((string) ($input['intent_hint'] ?? '')));

        if ($intentHint !== '') {
            return strtoupper($intentHint);
        }

        if ($message !== '') {
            if (preg_match('/\b(change|wissel|ander|andere)\b/u', $message)) {
                return 'CHANGE_INTENT';
            }
            if (preg_match('/\b(book|boek|reserveer|reserveren|checkout)\b/u', $message)) {
                return 'BOOKING_INTENT';
            }
            if (preg_match('/\b(plan|planning|dagindeling|schema)\b/u', $message)) {
                return 'PLANNING_INTENT';
            }
            if (preg_match('/\b(help|faq|support|vraag|probleem)\b/u', $message)) {
                return 'SUPPORT_INTENT';
            }
        }

        return 'DISCOVERY_INTENT';
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function mergeConstraints(array $context, array $input): array
    {
        $base = is_array($context['constraints'] ?? null) ? $context['constraints'] : [];
        $incoming = is_array($input['constraints'] ?? null) ? $input['constraints'] : [];

        if (isset($input['date']) && ! isset($incoming['date'])) {
            $incoming['date'] = (string) $input['date'];
        }
        if (isset($input['start_time']) && ! isset($incoming['start_time'])) {
            $incoming['start_time'] = (string) $input['start_time'];
        }
        if (isset($input['duration']) && ! isset($incoming['duration_minutes'])) {
            $incoming['duration_minutes'] = $this->durationToMinutes((string) $input['duration']);
        }
        if (isset($input['duration_minutes']) && ! isset($incoming['duration_minutes'])) {
            $incoming['duration_minutes'] = (int) $input['duration_minutes'];
        }
        if (isset($input['participants']) && ! isset($incoming['pax'])) {
            $incoming['pax'] = (int) $input['participants'];
        }
        if (isset($input['pax']) && ! isset($incoming['pax'])) {
            $incoming['pax'] = (int) $input['pax'];
        }

        if (isset($input['vibe']) && ! isset($incoming['vibe_tags'])) {
            $tokens = preg_split('/[\s,]+/', strtolower((string) $input['vibe'])) ?: [];
            $incoming['vibe_tags'] = array_values(array_filter($tokens));
        }

        $constraints = array_merge($base, $incoming);
        if (! is_array($constraints['vibe_tags'] ?? null)) {
            $constraints['vibe_tags'] = [];
        }

        return $constraints;
    }

    private function durationToMinutes(string $duration): int
    {
        return match (strtolower(trim($duration))) {
            'ochtend'  => 180,
            'middag'   => 300,
            'avond'    => 300,
            'hele-dag' => 600,
            'weekend'  => 720,
            default    => 240,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $offers
     * @param array<string, mixed>             $constraints
     *
     * @return array<int, array<string, mixed>>
     */
    private function rankOffers(array $offers, string $intent, array $constraints, array $policy): array
    {
        $weights = $this->normaliseWeights(
            array(
                'intent_match'       => 0.30,
                'availability_match' => 0.25,
                'duration_fit'       => 0.15,
                'margin_priority'    => 0.15,
                'seasonality'        => 0.10,
                'manual_priority'    => 0.05,
            ),
            is_array($policy['offer_weights'] ?? null) ? $policy['offer_weights'] : array()
        );
        $bias = $this->normaliseBias($policy['offer_bias'] ?? 1.0);

        $ranked = [];
        foreach ($offers as $offer) {
            $intentMatch = $this->offerIntentMatch($offer, $intent, $constraints);
            $availabilityMatch = $this->availabilityMatch($offer, $constraints);
            $durationFit = $this->durationFit($offer, $constraints);
            $marginPriority = $this->normalisePriority($offer['margin_priority'] ?? null, 0.50);
            $seasonality = $this->seasonality($offer, $constraints);
            $manualPriority = $this->normalisePriority($offer['manual_priority'] ?? null, 0.40);

            $score = (
                ($weights['intent_match'] * $intentMatch)
                + ($weights['availability_match'] * $availabilityMatch)
                + ($weights['duration_fit'] * $durationFit)
                + ($weights['margin_priority'] * $marginPriority)
                + ($weights['seasonality'] * $seasonality)
                + ($weights['manual_priority'] * $manualPriority)
            ) * $bias;

            $ranked[] = [
                'kind'      => 'offer',
                'id'        => $offer['id'] ?? null,
                'title'     => (string) ($offer['title'] ?? $offer['name'] ?? 'Aanbod'),
                'url'       => (string) ($offer['permalink'] ?? ''),
                'location'  => (string) ($offer['location'] ?? 'Den Bosch'),
                'score'     => round($score, 4),
                'raw'       => $offer,
                'breakdown' => [
                    'intent_match'      => round($intentMatch, 4),
                    'availability_match'=> round($availabilityMatch, 4),
                    'duration_fit'      => round($durationFit, 4),
                    'margin_priority'   => round($marginPriority, 4),
                    'seasonality'       => round($seasonality, 4),
                    'manual_priority'   => round($manualPriority, 4),
                    'bias'              => round($bias, 4),
                ],
            ];
        }

        return $ranked;
    }

    /**
     * @param array<int, array<string, mixed>> $spots
     * @param array<string, mixed>             $constraints
     *
     * @return array<int, array<string, mixed>>
     */
    private function rankSpots(array $spots, string $intent, array $constraints, array $policy): array
    {
        $weights = $this->normaliseWeights(
            array(
                'type_match'         => 0.35,
                'suitability_match'  => 0.25,
                'distance_heuristic' => 0.20,
                'duration_fit'       => 0.10,
                'manual_priority'    => 0.10,
            ),
            is_array($policy['spot_weights'] ?? null) ? $policy['spot_weights'] : array()
        );
        $bias = $this->normaliseBias($policy['spot_bias'] ?? 1.0);

        $ranked = [];
        foreach ($spots as $spot) {
            $typeMatch = $this->normalisePriority($spot['type_match'] ?? null, $intent === 'DISCOVERY_INTENT' ? 0.75 : 0.55);
            $suitability = $this->normalisePriority($spot['suitability_match'] ?? null, 0.60);
            $distance = $this->normalisePriority($spot['distance_heuristic'] ?? null, 0.60);
            $durationFit = $this->durationFit($spot, $constraints);
            $manualPriority = $this->normalisePriority($spot['manual_priority'] ?? null, 0.40);

            $score = (
                ($weights['type_match'] * $typeMatch)
                + ($weights['suitability_match'] * $suitability)
                + ($weights['distance_heuristic'] * $distance)
                + ($weights['duration_fit'] * $durationFit)
                + ($weights['manual_priority'] * $manualPriority)
            ) * $bias;

            $ranked[] = [
                'kind'      => 'spot',
                'id'        => $spot['id'] ?? null,
                'title'     => (string) ($spot['title'] ?? $spot['name'] ?? 'Spot'),
                'url'       => (string) ($spot['url'] ?? ''),
                'location'  => (string) ($spot['location'] ?? 'Den Bosch'),
                'score'     => round($score, 4),
                'raw'       => $spot,
                'breakdown' => [
                    'type_match'         => round($typeMatch, 4),
                    'suitability_match'  => round($suitability, 4),
                    'distance_heuristic' => round($distance, 4),
                    'duration_fit'       => round($durationFit, 4),
                    'manual_priority'    => round($manualPriority, 4),
                    'bias'               => round($bias, 4),
                ],
            ];
        }

        return $ranked;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $constraints
     */
    private function offerIntentMatch(array $candidate, string $intent, array $constraints): float
    {
        $base = match ($intent) {
            'BOOKING_INTENT'   => 1.0,
            'PLANNING_INTENT'  => 0.85,
            'DISCOVERY_INTENT' => 0.75,
            'SUPPORT_INTENT'   => 0.20,
            default            => 0.60,
        };

        $vibes = is_array($constraints['vibe_tags'] ?? null) ? $constraints['vibe_tags'] : [];
        if ($vibes === []) {
            return $base;
        }

        $categorySlugs = is_array($candidate['category_slugs'] ?? null) ? $candidate['category_slugs'] : [];
        $hits = array_intersect(array_map('strtolower', $vibes), array_map('strtolower', $categorySlugs));
        if ($hits !== []) {
            return min(1.0, $base + 0.15);
        }

        return max(0.0, $base - 0.10);
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $constraints
     */
    private function availabilityMatch(array $candidate, array $constraints): float
    {
        $windows = is_array($candidate['availability_windows'] ?? null) ? $candidate['availability_windows'] : [];
        $defaultWindows = is_array($windows['default'] ?? null) ? $windows['default'] : [];
        if ($defaultWindows === []) {
            return 0.45;
        }

        $date = isset($constraints['date']) ? (string) $constraints['date'] : '';
        if ($date === '') {
            return 0.80;
        }

        $weekday = strtolower((string) date('D', strtotime($date)));
        foreach ($defaultWindows as $window) {
            if (! is_array($window)) {
                continue;
            }
            $day = strtolower((string) ($window['day'] ?? ''));
            if ($day === '' || str_starts_with($weekday, $day)) {
                return 0.95;
            }
        }

        return 0.35;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $constraints
     */
    private function durationFit(array $candidate, array $constraints): float
    {
        $candidateMinutes = (int) ($candidate['duration_minutes'] ?? 90);
        $targetMinutes = (int) ($constraints['duration_minutes'] ?? 0);
        if ($targetMinutes <= 0) {
            return 0.70;
        }

        $delta = abs($candidateMinutes - $targetMinutes);
        $ratio = min(1.0, $delta / max(1, $targetMinutes));
        return max(0.10, 1.0 - $ratio);
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $constraints
     */
    private function seasonality(array $candidate, array $constraints): float
    {
        $rules = [];
        if (is_array($candidate['availability_windows']['rules'] ?? null)) {
            $rules = $candidate['availability_windows']['rules'];
        } elseif (is_array($candidate['availability']['rules'] ?? null)) {
            $rules = $candidate['availability']['rules'];
        }

        if ($rules === []) {
            return 0.60;
        }

        $date = isset($constraints['date']) ? (string) $constraints['date'] : '';
        return $date !== '' ? 0.85 : 0.70;
    }

    /**
     * @param mixed $value
     */
    private function normalisePriority($value, float $fallback): float
    {
        if (is_numeric($value)) {
            $num = (float) $value;
            if ($num > 1.0) {
                $num = $num / 100.0;
            }
            return max(0.0, min(1.0, $num));
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $constraints
     *
     * @return array<int, string>
     */
    private function missingConstraints(array $constraints): array
    {
        $missing = [];
        if (empty($constraints['date'])) {
            $missing[] = 'date';
        }
        if (empty($constraints['pax'])) {
            $missing[] = 'pax';
        }
        if (empty($constraints['duration_minutes'])) {
            $missing[] = 'duration_minutes';
        }

        return $missing;
    }

    private function questionForConstraint(string $constraint): string
    {
        return match ($constraint) {
            'date'             => 'Voor welke datum wil je plannen?',
            'pax'              => 'Met hoeveel personen zijn jullie?',
            'duration_minutes' => 'Hoe lang wil je ongeveer op pad?',
            default            => 'Kun je je voorkeur iets verder specificeren?',
        };
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $ranked
     */
    private function resolveStickyPrimary(array $context, array $ranked, string $intent): ?array
    {
        if ($intent === 'CHANGE_INTENT') {
            return null;
        }

        $stickyType = isset($context['sticky_primary_type']) ? (string) $context['sticky_primary_type'] : '';
        $stickyId = $context['sticky_primary_id'] ?? null;
        if ($stickyType === '' || $stickyId === null) {
            return null;
        }

        foreach ($ranked as $candidate) {
            if ((string) ($candidate['kind'] ?? '') !== $stickyType) {
                continue;
            }
            if ((string) ($candidate['id'] ?? '') === (string) $stickyId) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $primary
     * @param array<int, array<string, mixed>> $ranked
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveAlternatives(?array $primary, array $ranked): array
    {
        $alternatives = [];
        foreach ($ranked as $candidate) {
            if ($primary !== null && (string) ($candidate['id'] ?? '') === (string) ($primary['id'] ?? '')) {
                continue;
            }
            $alternatives[] = $candidate;
            if (count($alternatives) >= 3) {
                break;
            }
        }

        return $alternatives;
    }

    /**
     * @param array<int, array<string, mixed>> $ranked
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildBreakdownMap(array $ranked): array
    {
        $map = [];
        foreach ($ranked as $candidate) {
            $id = (string) ($candidate['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $map[$id] = [
                'kind'      => (string) ($candidate['kind'] ?? ''),
                'score'     => (float) ($candidate['score'] ?? 0.0),
                'breakdown' => is_array($candidate['breakdown'] ?? null) ? $candidate['breakdown'] : [],
            ];
        }

        return $map;
    }

    /**
     * @param array<string, mixed>|null $primary
     * @param array<int, array<string, mixed>> $ranked
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDiscarded(?array $primary, array $ranked): array
    {
        $discarded = [];
        foreach ($ranked as $candidate) {
            if ($primary !== null && (string) ($candidate['id'] ?? '') === (string) ($primary['id'] ?? '')) {
                continue;
            }
            $discarded[] = [
                'id'          => $candidate['id'] ?? null,
                'kind'        => (string) ($candidate['kind'] ?? ''),
                'reason_code' => 'lower_score',
                'score'       => (float) ($candidate['score'] ?? 0.0),
            ];
            if (count($discarded) >= 8) {
                break;
            }
        }

        return $discarded;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function normaliseContext(array $context): array
    {
        $constraints = is_array($context['constraints'] ?? null) ? $context['constraints'] : [];
        $context['constraints'] = $constraints;
        $context['sticky_primary_type'] = $context['sticky_primary_type'] ?? null;
        $context['sticky_primary_id'] = $context['sticky_primary_id'] ?? null;

        return $context;
    }

    /**
     * @param array<string, mixed> $constraints
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function decisionPolicy(string $intent, array $constraints, array $input): array
    {
        $defaults = array(
            'offer_bias'           => 1.00,
            'spot_bias'            => 1.00,
            'confidence_threshold' => 0.45,
            'max_questions'        => 1,
            'offer_weights'        => array(
                'intent_match'       => 0.30,
                'availability_match' => 0.25,
                'duration_fit'       => 0.15,
                'margin_priority'    => 0.15,
                'seasonality'        => 0.10,
                'manual_priority'    => 0.05,
            ),
            'spot_weights'         => array(
                'type_match'         => 0.35,
                'suitability_match'  => 0.25,
                'distance_heuristic' => 0.20,
                'duration_fit'       => 0.10,
                'manual_priority'    => 0.10,
            ),
        );

        $stored = array();
        if (function_exists('get_option')) {
            $option = get_option(self::POLICY_OPTION, array());
            if (is_array($option)) {
                $stored = $option;
            }
        }

        $policy = array_replace_recursive($defaults, $stored);
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('sbdp/day_planner/decision_policy', $policy, $intent, $constraints, $input);
            if (is_array($filtered)) {
                $policy = $filtered;
            }
        }

        $policy['offer_bias'] = $this->normaliseBias($policy['offer_bias'] ?? 1.00);
        $policy['spot_bias'] = $this->normaliseBias($policy['spot_bias'] ?? 1.00);
        $policy['confidence_threshold'] = $this->normaliseThreshold($policy['confidence_threshold'] ?? 0.45);
        $policy['max_questions'] = max(1, min(3, (int) ($policy['max_questions'] ?? 1)));
        $policy['offer_weights'] = $this->normaliseWeights($defaults['offer_weights'], is_array($policy['offer_weights'] ?? null) ? $policy['offer_weights'] : array());
        $policy['spot_weights'] = $this->normaliseWeights($defaults['spot_weights'], is_array($policy['spot_weights'] ?? null) ? $policy['spot_weights'] : array());

        return $policy;
    }

    private function normaliseBias($value): float
    {
        $bias = is_numeric($value) ? (float) $value : 1.00;
        return max(0.50, min(1.50, $bias));
    }

    private function normaliseThreshold($value): float
    {
        $threshold = is_numeric($value) ? (float) $value : 0.45;
        return max(0.10, min(0.95, $threshold));
    }

    /**
     * @param array<string, float> $defaults
     * @param array<string, mixed> $incoming
     *
     * @return array<string, float>
     */
    private function normaliseWeights(array $defaults, array $incoming): array
    {
        $weights = $defaults;
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $incoming)) {
                continue;
            }

            $value = $incoming[$key];
            $weights[$key] = is_numeric($value) ? max(0.0, (float) $value) : (float) $default;
        }

        $sum = array_sum($weights);
        if ($sum <= 0.0) {
            return $defaults;
        }

        foreach ($weights as $key => $value) {
            $weights[$key] = $value / $sum;
        }

        return $weights;
    }
}
