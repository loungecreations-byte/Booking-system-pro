<?php

declare(strict_types=1);

namespace BSP\Sales;

use BSP\Core\Helpers\Logger;

/**
 * Provides AI-inspired dynamic pricing helpers.
 */
final class DynamicPricingService
{
    private const OPTION_KEY = 'sbdp_dynamic_pricing';

    private Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Store configuration for AI pricing features.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function configure(array $payload): array
    {
        $config = $this->defaults();

        if (\array_key_exists('features', $payload) && \is_array($payload['features'])) {
            $config['features'] = $this->sanitizeFeatureFlags($payload['features']);
        }

        if (\array_key_exists('training_source', $payload)) {
            $config['training_source'] = $this->sanitizeText((string) $payload['training_source']);
        }

        $config['logging'] = (bool) ($payload['logging'] ?? false);

        if (\function_exists('update_option')) {
            \update_option(self::OPTION_KEY, $config);
        }

        $this->logger->log('[Sales] Dynamic pricing features updated: ' . \implode(', ', \array_keys(\array_filter($config['features']))));

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        if (! \function_exists('get_option')) {
            return $this->defaults();
        }

        $stored = \get_option(self::OPTION_KEY, []);

        if (! \is_array($stored)) {
            return $this->defaults();
        }

        return \array_merge($this->defaults(), $stored);
    }

    /**
     * Calculate the dynamic price and explain adjustments.
     *
     * @param array<string, mixed> $signals
     *
     * @return array{price: float, adjustments: array<int, array<string, mixed>>}
     */
    public function calculate(float $basePrice, array $signals = []): array
    {
        $config      = $this->getConfiguration();
        $features    = $config['features'];
        $adjustments = [];
        $price       = $basePrice;

        if (! empty($features['dynamic_pricing'])) {
            $result = $this->applyDynamicRules($price, $signals);
            $price  = $result['price'];
            $adjustments = \array_merge($adjustments, $result['adjustments']);
        }

        if (! empty($features['smart_suggestions'])) {
            $adjustments[] = [
                'type'        => 'suggestion',
                'message'     => $this->suggestAddOn($signals),
                'confidence'  => 0.72,
            ];
        }

        if (! empty($features['review_analysis'])) {
            $adjustments[] = [
                'type'       => 'sentiment',
                'sentiment'  => $this->analyzeReviewSentiment($signals['reviews'] ?? []),
                'source'     => 'recent_reviews',
            ];
        }

        if (! empty($features['ai_day_planner'])) {
            $adjustments[] = [
                'type'    => 'planner',
                'message' => 'AI day planner recommends staggering start times by 15 minutes.',
            ];
        }

        $price = \round(\max(0.0, $price), 2);

        if (! empty($config['logging'])) {
            $this->logger->log('[Sales] Dynamic pricing calculated at ' . $price);
        }

        return [
            'price'       => $price,
            'adjustments' => $adjustments,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $reviews
     */
    public function analyzeReviewSentiment(array $reviews): string
    {
        if ([] === $reviews) {
            return 'neutral';
        }

        $score = 0.0;

        foreach ($reviews as $review) {
            $rating = (float) ($review['rating'] ?? 0.0);
            $score += $rating - 3.0;
        }

        $average = $score / \max(1, \count($reviews));

        if ($average > 0.5) {
            return 'positive';
        }

        if ($average < -0.5) {
            return 'negative';
        }

        return 'neutral';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function applyDynamicRules(float $basePrice, array $signals): array
    {
        $price       = $basePrice;
        $adjustments = [];

        $occupancy = isset($signals['occupancy_rate']) ? (float) $signals['occupancy_rate'] : null;
        if (null !== $occupancy) {
            if ($occupancy >= 0.85) {
                $delta    = $price * 0.15;
                $price   += $delta;
                $adjustments[] = [
                    'type'       => 'occupancy',
                    'direction'  => 'increase',
                    'percentage' => 15,
                    'reason'     => 'High occupancy rate',
                ];
            } elseif ($occupancy <= 0.30) {
                $delta    = $price * 0.10;
                $price   -= $delta;
                $adjustments[] = [
                    'type'       => 'occupancy',
                    'direction'  => 'decrease',
                    'percentage' => 10,
                    'reason'     => 'Low occupancy rate',
                ];
            }
        }

        $demand = isset($signals['demand_index']) ? (float) $signals['demand_index'] : null;
        if (null !== $demand) {
            if ($demand >= 1.2) {
                $delta    = $price * 0.12;
                $price   += $delta;
                $adjustments[] = [
                    'type'       => 'demand',
                    'direction'  => 'increase',
                    'percentage' => 12,
                    'reason'     => 'Demand index indicates surge',
                ];
            } elseif ($demand <= 0.8) {
                $delta    = $price * 0.08;
                $price   -= $delta;
                $adjustments[] = [
                    'type'       => 'demand',
                    'direction'  => 'decrease',
                    'percentage' => 8,
                    'reason'     => 'Demand softening detected',
                ];
            }
        }

        $leadTime = isset($signals['lead_time_days']) ? (int) $signals['lead_time_days'] : null;
        if (null !== $leadTime && $leadTime <= 2) {
            $delta    = $price * 0.05;
            $price   += $delta;
            $adjustments[] = [
                'type'       => 'lead_time',
                'direction'  => 'increase',
                'percentage' => 5,
                'reason'     => 'Last-minute booking premium applied',
            ];
        }

        return [
            'price'       => $price,
            'adjustments' => $adjustments,
        ];
    }

    /**
     * Provide a simple upsell suggestion.
     *
     * @param array<string, mixed> $signals
     */
    private function suggestAddOn(array $signals): string
    {
        $weather = (string) ($signals['weather'] ?? '');
        if ('rain' === $weather || 'storm' === $weather) {
            return 'Consider promoting indoor experiences due to expected rain.';
        }

        $audience = (string) ($signals['audience'] ?? '');
        if ('families' === $audience) {
            return 'Bundle tickets with kids-friendly add-ons for higher conversion.';
        }

        return 'Promote premium add-ons for peak slots to maximise yield.';
    }

    /**
     * @param array<string, mixed> $features
     *
     * @return array<string, bool>
     */
    private function sanitizeFeatureFlags(array $features): array
    {
        $allowed = [
            'dynamic_pricing',
            'smart_suggestions',
            'ai_day_planner',
            'review_analysis',
        ];

        $out = [];
        foreach ($allowed as $feature) {
            $out[$feature] = (bool) ($features[$feature] ?? false);
        }

        return $out;
    }

    private function defaults(): array
    {
        return [
            'features' => [
                'dynamic_pricing'   => true,
                'smart_suggestions' => false,
                'ai_day_planner'    => false,
                'review_analysis'   => true,
            ],
            'training_source' => 'booking_history',
            'logging'         => false,
        ];
    }

    private function sanitizeText(string $value): string
    {
        $value = \strip_tags($value);
        $value = \preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value;

        return \trim($value);
    }
}
