<?php

/**
 * Plugin Name: SBDP Smoke Suite
 * Description: WP-CLI ondersteuning om planner smoke tests en cruciale checks soepel uit te voeren.
 * Version: 1.0.0
 * Author: DagjeDenBosch
 *
 * @package SBDP\SmokeSuite
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('WP_CLI') || ! WP_CLI) {
    return;
}

if (! class_exists('SBDP_Smoke_Suite_Command', false)) {
    /**
     * WP-CLI command om belangrijke REST smoketests te draaien.
     */
    final class SBDP_Smoke_Suite_Command
    {
        /**
         * Execute the smoke suite entrypoint.
         *
         * @param array<int, string> $args       Positional arguments.
         * @param array<string, mixed> $assocArgs Associative arguments.
         */
        public function __invoke(array $args, array $assocArgs): void
        {
            unset($args);

            $tests = $this->describeTests();
            if (isset($assocArgs['list'])) {
                $this->outputTestCatalog($tests);

                return;
            }

            $base = isset($assocArgs['base']) ? $this->sanitiseBase((string) $assocArgs['base']) : $this->sanitiseBase(rest_url());
            if ($base === '') {
                WP_CLI::error(__('Kon geen REST basis-URL bepalen. Gebruik --base=https://site.local/wp-json', 'sbdp'));
            }

            $requested = $this->resolveRequestedTests($assocArgs['tests'] ?? 'all');
            $selected  = $this->filterTests($tests, $requested);
            if ($selected === array()) {
                WP_CLI::error(__('Geen geldige tests gevonden. Gebruik --list om alle opties te tonen.', 'sbdp'));
            }

            $context = $this->buildContext($assocArgs);

            $failures = 0;
            foreach ($selected as $key => $definition) {
                if ($this->isMissingRequirements($definition, $context)) {
                    WP_CLI::warning(
                        sprintf(
                            __('Sla %s over (vereist parameters ontbreken).', 'sbdp'),
                            $definition['label']
                        )
                    );
                    continue;
                }

                WP_CLI::log(sprintf(__('Start %s...', 'sbdp'), $definition['label']));

                $result = call_user_func($definition['callback'], $base, $context);
                if (is_wp_error($result)) {
                    $failures++;
                    WP_CLI::warning(
                        sprintf(
                            __('%1$s mislukt: %2$s', 'sbdp'),
                            $definition['label'],
                            $result->get_error_message()
                        )
                    );
                    continue;
                }

                WP_CLI::success(sprintf(__('%s geslaagd.', 'sbdp'), $definition['label']));
            }

            if ($failures > 0) {
                WP_CLI::error(
                    sprintf(
                        _n('%d smoketest is mislukt.', '%d smoketests zijn mislukt.', $failures, 'sbdp'),
                        $failures
                    )
                );
            }

            WP_CLI::success(__('Alle geselecteerde smoketests zijn geslaagd.', 'sbdp'));
        }

        /**
         * Toon het overzicht van beschikbare tests.
         *
         * @param array<string, array<string, mixed>> $tests Beschikbare tests.
         */
        private function outputTestCatalog(array $tests): void
        {
            $rows = array();
            foreach ($tests as $key => $definition) {
                $rows[] = array(
                    'key'         => $key,
                    'label'       => $definition['label'],
                    'requires'    => implode(', ', $definition['requires'] ?? array()),
                    'description' => $definition['description'] ?? '',
                );
            }

            WP_CLI\Utils\format_items('table', $rows, array('key', 'label', 'requires', 'description'));
        }

        /**
         * Bepaal welke tests de gebruiker wil draaien.
         *
         * @param mixed $input Gebruik invoer voor --tests argument.
         *
         * @return array<int, string>
         */
        private function resolveRequestedTests($input): array
        {
            if (! is_string($input) || trim($input) === '' || strtolower($input) === 'all') {
                return array('all');
            }

            $segments = array();
            foreach (explode(',', strtolower($input)) as $segment) {
                $segment = trim($segment);
                if ($segment !== '') {
                    $segments[] = $segment;
                }
            }

            return $segments === array() ? array('all') : $segments;
        }

        /**
         * Filter tests gecombineerd met de gebruiker selectie.
         *
         * @param array<string, array<string, mixed>> $tests     Alle tests.
         * @param array<int, string>                  $requested Geselecteerde keys.
         *
         * @return array<string, array<string, mixed>>
         */
        private function filterTests(array $tests, array $requested): array
        {
            if (in_array('all', $requested, true)) {
                return $tests;
            }

            $matched = array();
            foreach ($requested as $key) {
                if (isset($tests[$key])) {
                    $matched[$key] = $tests[$key];
                } else {
                    WP_CLI::warning(sprintf(__('Onbekende test "%s" genegeerd.', 'sbdp'), $key));
                }
            }

            return $matched;
        }

        /**
         * Bouw de context met parameters voor alle tests.
         *
         * @param array<string, mixed> $assocArgs CLI arguments.
         *
         * @return array<string, mixed>
         */
        private function buildContext(array $assocArgs): array
        {
            $product = isset($assocArgs['product']) ? (int) $assocArgs['product'] : 0;
            $resource = isset($assocArgs['resource']) ? (int) $assocArgs['resource'] : 0;
            $participants = isset($assocArgs['participants']) ? max(1, (int) $assocArgs['participants']) : 1;
            $date = isset($assocArgs['date']) ? (string) $assocArgs['date'] : gmdate('Y-m-d');

            return array(
                'product'      => $product,
                'resource'     => $resource,
                'participants' => $participants,
                'date'         => $date,
            );
        }

        /**
         * Controleer of verplichte parameters aanwezig zijn.
         *
         * @param array<string, mixed> $definition Test definitie.
         * @param array<string, mixed> $context    CLI context.
         */
        private function isMissingRequirements(array $definition, array $context): bool
        {
            $requires = $definition['requires'] ?? array();
            foreach ($requires as $requirement) {
                if (! isset($context[$requirement])) {
                    return true;
                }

                $value = $context[$requirement];
                if (is_int($value) && $value <= 0) {
                    return true;
                }

                if (is_string($value) && trim($value) === '') {
                    return true;
                }
            }

            return false;
        }

        /**
         * Beschrijving van alle smoketests.
         *
         * @return array<string, array<string, mixed>>
         */
        private function describeTests(): array
        {
            return array(
                'planner-config'  => array(
                    'label'       => __('Planner configuratie', 'sbdp'),
                    'requires'    => array(),
                    'description' => __('Controleert of planner configuratie beschikbaar is.', 'sbdp'),
                    'callback'    => function (string $base): ?WP_Error {
                        $result = $this->request($base, 'GET', '/planner/v1/config');
                        if (is_wp_error($result)) {
                            return $result;
                        }

                        if (! isset($result['body']['config'])) {
                            return new WP_Error('sbdp_smoke_missing_config', __('Planner config ontbreekt in response.', 'sbdp'));
                        }

                        return null;
                    },
                ),
                'planner-products' => array(
                    'label'       => __('Planner producten', 'sbdp'),
                    'requires'    => array(),
                    'description' => __('Controleert of planner producten endpoint echte producten teruggeeft.', 'sbdp'),
                    'callback'    => function (string $base): ?WP_Error {
                        $result = $this->request($base, 'GET', '/planner/v1/products', array('limit' => 5));
                        if (is_wp_error($result)) {
                            return $result;
                        }

                        if (! isset($result['body']['products']) || ! is_array($result['body']['products'])) {
                            return new WP_Error('sbdp_smoke_missing_products', __('Response bevat geen products array.', 'sbdp'));
                        }

                        if ($result['body']['products'] === array()) {
                            return new WP_Error('sbdp_smoke_empty_products', __('Planner producten lijst is leeg.', 'sbdp'));
                        }

                        $first = $result['body']['products'][0];
                        if (! isset($first['pricing'], $first['availability'])) {
                            return new WP_Error('sbdp_smoke_product_shape', __('Product payload mist pricing of availability.', 'sbdp'));
                        }

                        return null;
                    },
                ),
                'pricing-quote'   => array(
                    'label'       => __('Pricing quote', 'sbdp'),
                    'requires'    => array('product'),
                    'description' => __('Valideert prijsberekening voor een opgegeven product.', 'sbdp'),
                    'callback'    => function (string $base, array $context): ?WP_Error {
                        $payload = array(
                            'items' => array(
                                array(
                                    'product_id' => $context['product'],
                                    'quantity'   => max(1, (int) $context['participants']),
                                ),
                            ),
                            'participants' => max(1, (int) $context['participants']),
                        );

                        $result = $this->request($base, 'POST', '/bsp/v1/pricing/quote', array(), $payload);
                        if (is_wp_error($result)) {
                            return $result;
                        }

                        if (! isset($result['body']['pricing'])) {
                            return new WP_Error('sbdp_smoke_quote_missing_pricing', __('Pricing veld ontbreekt in quote response.', 'sbdp'));
                        }

                        return null;
                    },
                ),
                'plan-availability' => array(
                    'label'       => __('Plan beschikbaarheid', 'sbdp'),
                    'requires'    => array('product', 'date'),
                    'description' => __('Controleert availability blokken voor een product op opgegeven datum.', 'sbdp'),
                    'callback'    => function (string $base, array $context): ?WP_Error {
                        $query = array(
                            'product_id' => $context['product'],
                            'date'       => $context['date'],
                        );

                        if (! empty($context['resource'])) {
                            $query['resource_id'] = (int) $context['resource'];
                        }

                        $result = $this->request($base, 'GET', '/sbdp/v1/availability/plan', $query);
                        if (is_wp_error($result)) {
                            return $result;
                        }

                        if (! isset($result['body']['blocks']) && ! isset($result['body']['capacity'])) {
                            return new WP_Error('sbdp_smoke_availability_shape', __('Availability response bevat geen blokken of capaciteit.', 'sbdp'));
                        }

                        return null;
                    },
                ),
            );
        }

        /**
         * Voer een HTTP verzoek uit en valideer de basis response.
         *
         * @param string               $base    Basis-URL voor REST.
         * @param string               $method  HTTP methode (GET, POST, ...).
         * @param string               $path    REST pad.
         * @param array<string, mixed> $query   Query parameters.
         * @param array<string, mixed> $payload JSON payload voor POST/PUT.
         *
         * @return array<string, mixed>|WP_Error
         */
        private function request(
            string $base,
            string $method,
            string $path,
            array $query = array(),
            array $payload = array()
        ) {
            $url = $this->buildUrl($base, $path, $query);

            $args = array(
                'method'  => $method,
                'timeout' => 20,
            );

            if ($payload !== array()) {
                $args['body']    = wp_json_encode($payload);
                $args['headers'] = array(
                    'Content-Type' => 'application/json',
                );
            }

            $response = wp_remote_request($url, $args);
            if (is_wp_error($response)) {
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                return new WP_Error(
                    'sbdp_smoke_http_error',
                    sprintf(
                        __('HTTP %1$d voor %2$s: %3$s', 'sbdp'),
                        $code,
                        $url,
                        trim(wp_remote_retrieve_body($response))
                    )
                );
            }

            $body = wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);
            if ($body !== '' && $decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error(
                    'sbdp_smoke_json_error',
                    sprintf(__('Kon JSON niet parsen voor %s: %s', 'sbdp'), $url, json_last_error_msg())
                );
            }

            return array(
                'code'     => $code,
                'raw_body' => $body,
                'body'     => is_array($decoded) ? $decoded : array(),
            );
        }

        /**
         * Bouw een REST URL.
         *
         * @param string               $base  Basis-URL.
         * @param string               $path  Endpoint pad.
         * @param array<string, mixed> $query Query parameters.
         */
        private function buildUrl(string $base, string $path, array $query = array()): string
        {
            $url = $base . ltrim($path, '/');
            if ($query !== array()) {
                $url = add_query_arg($query, $url);
            }

            return $url;
        }

        /**
         * Normaliseer REST base URL.
         *
         * @param string $value Mogelijke base waarde.
         */
        private function sanitiseBase(string $value): string
        {
            $value = trim($value);
            if ($value === '') {
                return '';
            }

            $value = untrailingslashit($value);

            return $value === '' ? '' : $value . '/';
        }
    }
}

WP_CLI::add_command('sbdp:smoke-suite', 'SBDP_Smoke_Suite_Command');
