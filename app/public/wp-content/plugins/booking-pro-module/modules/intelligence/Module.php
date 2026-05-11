<?php
declare(strict_types=1);

namespace BSP\Intelligence;

use BSP\Core\CoreServiceProvider;
use BSP\Core\Interfaces\ModuleInterface;
use BSP\Intelligence\Rest\Controller as RestController;
use BSP\Intelligence\Rest\ReportsController;

if (! class_exists(__NAMESPACE__ . '\\Module', false)) {
    /**
     * Intelligence module delivering trend, anomaly, and recommendation tools.
     */
    final class Module implements ModuleInterface
    {
        private ReportsService $reports;

        public function __construct(?ReportsService $reports = null)
        {
            $this->reports = $reports ?? new ReportsService(CoreServiceProvider::logger());
        }

        /**
         * Register REST routes and log module initialisation.
         */
        public function init(): void
        {
            CoreServiceProvider::logger()->log('Intelligence module initialized');

            if (\function_exists('add_action')) {
                \add_action('rest_api_init', [RestController::class, 'register']);
                \add_action(
                    'rest_api_init',
                    function (): void {
                        (new ReportsController($this->reports))->register();
                    }
                );
                \add_action('init', [$this, 'maybeScheduleReports']);
                \add_filter('cron_schedules', [$this, 'registerCronSchedules']);
                \add_action('sbdp_reports_dispatch', [$this, 'dispatchScheduledReport']);
            }
        }

        /**
         * @param array<string, array<string, int|string>> $schedules
         *
         * @return array<string, array<string, int|string>>
         */
        public function registerCronSchedules(array $schedules): array
        {
            $dayInSeconds = \defined('DAY_IN_SECONDS') ? \DAY_IN_SECONDS : 86400;

            $schedules['sbdp_reports_weekly'] = array(
                'interval' => 7 * $dayInSeconds,
                'display'  => $this->translate('Once Weekly (SBDP Reports)'),
            );

            $schedules['sbdp_reports_monthly'] = array(
                'interval' => 30 * $dayInSeconds,
                'display'  => $this->translate('Once Monthly (SBDP Reports)'),
            );

            return $schedules;
        }

        public function maybeScheduleReports(): void
        {
            if (! \function_exists('wp_next_scheduled') || ! \function_exists('wp_schedule_event')) {
                return;
            }

            $config   = $this->reports->getConfiguration();
            $schedule = (string) ($config['schedule'] ?? 'weekly');
            $hook     = 'sbdp_reports_dispatch';

            $interval = $this->mapScheduleToCron($schedule);

            if (! \wp_next_scheduled($hook)) {
                $timestamp = \time() + 60;
                \wp_schedule_event($timestamp, $interval, $hook);
            }
        }

        private function mapScheduleToCron(string $schedule): string
        {
            return match ($schedule) {
                'daily'   => 'daily',
                'monthly' => 'sbdp_reports_monthly',
                default   => 'sbdp_reports_weekly',
            };
        }

        private function translate(string $text): string
        {
            return \function_exists('__') ? \__($text, 'sbdp') : $text;
        }

        public function dispatchScheduledReport(): void
        {
            $snapshot = $this->reports->generateSnapshot();
            CoreServiceProvider::logger()->log('[Reports] Dispatched snapshot with revenue ' . ($snapshot['revenue'] ?? 0));
        }

        /**
         * Return the top-k entries sorted by descending value.
         *
         * @param array<string, float|int> $kv
         *
         * @return array<string, float|int>
         */
        public function analyzeTrends(array $kv, int $k = 3): array
        {
            \arsort($kv);

            return \array_slice($kv, 0, $k, true);
        }

        /**
         * Detect series entries that exceed the threshold.
         *
         * @param array<string, float|int> $series
         *
         * @return array<string, float>
         */
        public function detectAnomalies(array $series, float $threshold): array
        {
            $anomalies = array();

            foreach ($series as $key => $value) {
                $numericValue = (float) $value;

                if ($numericValue >= $threshold) {
                    $anomalies[(string) $key] = $numericValue;
                }
            }

            return $anomalies;
        }

        /**
         * Produce moving-average forecasts over the provided window.
         *
         * @param array<string, float|int> $series
         *
         * @return array<string, float>
         */
        public function forecastDemand(array $series, int $window = 3): array
        {
            $keys      = \array_keys($series);
            $values    = \array_values($series);
            $count     = \count($values);
            $forecasts = array();

            for ($index = 0; $index < $count; $index++) {
                $start   = \max(0, $index - $window + 1);
                $slice   = \array_slice($values, $start, $index - $start + 1);
                $average = empty($slice) ? 0.0 : (\array_sum($slice) / \count($slice));
                $forecasts[(string) $keys[$index]] = \round((float) $average, 2);
            }

            return $forecasts;
        }

        /**
         * Suggest upsell SKUs based on catalog relationships.
         *
         * @param array<int, array<string, mixed>> $cart
         * @param array<int, array<string, mixed>> $catalog
         *
         * @return array<int, string>
         */
        public function recommendUpsell(array $cart, array $catalog): array
        {
            $inCart = array();
            foreach ($cart as $line) {
                $sku = (string) ($line['sku'] ?? '');
                if ($sku !== '') {
                    $inCart[$sku] = true;
                }
            }

            $suggested = array();
            foreach ($catalog as $item) {
                $related = $item['related'] ?? array();
                if (! \is_array($related)) {
                    continue;
                }

                foreach ($related as $sku) {
                    $candidate = (string) $sku;
                    if ($candidate === '' || isset($inCart[$candidate])) {
                        continue;
                    }

                    $suggested[$candidate] = true;
                }
            }

            return \array_keys($suggested);
        }

        /**
         * Compute KPI values such as revenue and average order value.
         *
         * @param array<string, float|int> $metrics
         *
         * @return array<string, float|int>
         */
        public function computeKPIs(array $metrics): array
        {
            $orders   = (int) ($metrics['orders'] ?? 0);
            $revenue  = (float) ($metrics['revenue'] ?? 0.0);
            $average  = $orders > 0 ? $revenue / $orders : 0.0;

            return array(
                'orders'  => $orders,
                'revenue' => \round($revenue, 2),
                'aov'     => \round($average, 2),
            );
        }

        /**
         * Segment customers by total spend into VIP, REGULAR, and NEW buckets.
         *
         * @param array<int, array<string, mixed>> $customers
         *
         * @return array<string, array<int, string>>
         */
        public function segmentCustomers(array $customers): array
        {
            $segments = array(
                'VIP'     => array(),
                'REGULAR' => array(),
                'NEW'     => array(),
            );

            foreach ($customers as $customer) {
                $identifier = (string) ($customer['id'] ?? '');
                $total      = (float) ($customer['total'] ?? 0.0);

                if ($identifier === '') {
                    continue;
                }

                if ($total >= 1000.0) {
                    $segments['VIP'][] = $identifier;
                } elseif ($total >= 100.0) {
                    $segments['REGULAR'][] = $identifier;
                } else {
                    $segments['NEW'][] = $identifier;
                }
            }

            return $segments;
        }
    }
}

