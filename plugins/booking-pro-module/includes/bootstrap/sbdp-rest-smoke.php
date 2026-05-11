<?php

/**
 * Plugin Name: SBDP REST Smoketest
 * Description: Eenvoudige WP-CLI smoketest voor SBDP/REST endpoints.
 * Version: 1.0.0
 * Author: DagjeDenBosch
 */

if (! defined('ABSPATH')) {
    exit;
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command(
        'sbdp:rest-smoke',
        function (array $args, array $assoc_args) {
            $base    = isset($assoc_args['base']) ? untrailingslashit((string) $assoc_args['base']) : '';
            $product = isset($assoc_args['product']) ? (int) $assoc_args['product'] : 0;

            if ('' === $base) {
                WP_CLI::error('Geef een --base parameter op, bijvoorbeeld --base=https://staging.domein.nl/wp-json');
            }

            if ($product <= 0) {
                WP_CLI::error('Geef een bestaande --product id op, bijvoorbeeld --product=1234');
            }

            $routes = array(
                array(
                    'path'   => '/sbdp/v1/services',
                    'method' => 'GET',
                    'label'  => 'Services',
                ),
                array(
                    'path'   => '/bsp/v1/channels',
                    'method' => 'GET',
                    'label'  => 'Channels',
                ),
                array(
                    'path'   => '/bsp/v1/pricing/quote',
                    'method' => 'POST',
                    'label'  => 'Pricing quote',
                    'body'   => array(
                        'product_id' => $product,
                    ),
                ),
            );

            foreach ($routes as $route) {
                $url = $base . $route['path'];
                $args = array(
                    'method'  => $route['method'],
                    'timeout' => 15,
                );

                if (isset($route['body'])) {
                    $args['body']    = wp_json_encode($route['body']);
                    $args['headers'] = array(
                        'Content-Type' => 'application/json',
                    );
                }

                $response = wp_remote_request($url, $args);

                if (is_wp_error($response)) {
                    WP_CLI::warning(
                        sprintf(
                            '%s (%s) -> fout: %s',
                            $route['label'],
                            $url,
                            $response->get_error_message()
                        )
                    );
                    continue;
                }

                $code = wp_remote_retrieve_response_code($response);
                if ($code >= 200 && $code < 300) {
                    WP_CLI::success(sprintf('%s (%s) -> HTTP %d', $route['label'], $url, $code));
                } else {
                    WP_CLI::warning(
                        sprintf(
                            '%s (%s) -> HTTP %d: %s',
                            $route['label'],
                            $url,
                            $code,
                            wp_remote_retrieve_body($response)
                        )
                    );
                }
            }
        }
    );
}
