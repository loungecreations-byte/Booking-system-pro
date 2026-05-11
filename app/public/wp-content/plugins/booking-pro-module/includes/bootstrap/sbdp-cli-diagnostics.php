<?php

if (! defined('ABSPATH')) {
    return;
}

if (! defined('WP_CLI') || ! WP_CLI) {
    return;
}

use BSPModule\Core\Rest\RestService;

if (! class_exists('SBDP_Diagnostics_Command', false)) {
    final class SBDP_Diagnostics_Command
    {
        public function modules(array $args, array $assoc_args): void
        {
            $probe = isset($assoc_args['probe']);
            $verbose = isset($assoc_args['verbose']);
            $include_registered_only = isset($assoc_args['include-registered-only']);

            $core_registry = $this->read_core_registry();
            $engine_classes = $this->read_engine_modules();

            $directories = function_exists('sbdp_module_directories') ? sbdp_module_directories() : array();
            $rows = array();
            $seen_classes = array();

            foreach ($directories as $folder => $path) {
                $module_file = $path . 'Module.php';
                $has_file = is_readable($module_file);
                $namespace = null;
                if ($has_file && function_exists('sbdp_detect_module_namespace')) {
                    $namespace = sbdp_detect_module_namespace($module_file);
                }

                $class = $namespace ? '\\' . ltrim($namespace, '\\') . '\\Module' : '';
                $class_exists = $class !== '' ? class_exists($class) : false;
                $implements_core = $class_exists && is_subclass_of($class, '\BSP\Core\Interfaces\ModuleInterface');
                $implements_engine = $class_exists && is_subclass_of($class, '\SBDP\Contracts\ModuleInterface');
                $implements_shared = $class_exists && is_subclass_of($class, '\BSPModule\Shared\Modules\ModuleInterface');
                $in_core_registry = $class_exists ? in_array($class, $core_registry, true) : false;
                $in_engine = $class_exists ? in_array($class, $engine_classes, true) : false;

                $status = 'ok';
                if (! $has_file) {
                    $status = 'missing Module.php';
                } elseif ($class === '') {
                    $status = 'namespace not found';
                } elseif (! $class_exists) {
                    $status = 'class missing';
                } elseif (! ($implements_core || $implements_engine || $implements_shared)) {
                    $status = 'no module interface';
                }

                if ($class !== '') {
                    $seen_classes[$class] = true;
                }

                if ($probe && $class_exists) {
                    $status = $this->probe_module($class, $status);
                }

                $rows[] = array(
                    'module'    => (string) $folder,
                    'class'     => $class !== '' ? $class : '-',
                    'core'      => $in_core_registry ? 'yes' : 'no',
                    'engine'    => $in_engine ? 'yes' : 'no',
                    'interfaces' => $this->format_interfaces($implements_core, $implements_engine, $implements_shared),
                    'status'    => $status,
                );
            }

            if ($include_registered_only) {
                foreach ($core_registry as $key => $class) {
                    if (isset($seen_classes[$class])) {
                        continue;
                    }

                    $class_exists = class_exists($class);
                    $implements_core = $class_exists && is_subclass_of($class, '\BSP\Core\Interfaces\ModuleInterface');
                    $implements_engine = $class_exists && is_subclass_of($class, '\SBDP\Contracts\ModuleInterface');
                    $implements_shared = $class_exists && is_subclass_of($class, '\BSPModule\Shared\Modules\ModuleInterface');

                    $status = $class_exists ? 'registered only' : 'class missing';
                    if ($probe && $class_exists) {
                        $status = $this->probe_module($class, $status);
                    }

                    $rows[] = array(
                        'module'    => (string) $key,
                        'class'     => $class,
                        'core'      => 'yes',
                        'engine'    => in_array($class, $engine_classes, true) ? 'yes' : 'no',
                        'interfaces' => $this->format_interfaces($implements_core, $implements_engine, $implements_shared),
                        'status'    => $status,
                    );
                }
            }

            if ($verbose) {
                WP_CLI::log(sprintf('Core registry: %d modules; Engine modules: %d modules.', count($core_registry), count($engine_classes)));
            }

            WP_CLI\Utils\format_items('table', $rows, array('module', 'class', 'core', 'engine', 'interfaces', 'status'));
        }

        public function check_data(array $args, array $assoc_args): void
        {
            $days = isset($assoc_args['days']) ? max(1, (int) $assoc_args['days']) : 14;

            $products = $this->get_bookable_products();
            if (empty($products)) {
                WP_CLI::warning('Geen bookable_service producten gevonden.');
                return;
            }

            WP_CLI::log(sprintf('Controleer %d producten voor de komende %d dagen...', count($products), $days));

            $rows = array();

            foreach ($products as $product_id) {
                $product_id  = (int) $product_id;
                $name        = get_the_title($product_id);
                $issues      = array();
                $resource_id = (int) get_post_meta($product_id, '_sbdp_resource_id', true);

                for ($offset = 0; $offset < $days; $offset++) {
                    $date = gmdate('Y-m-d', strtotime(sprintf('+%d day', $offset)));

                    $availability = $this->call_plan_availability($product_id, $resource_id, $date);
                    if (is_wp_error($availability)) {
                        $issues[] = sprintf('Availability %s: %s', $date, $availability->get_error_message());
                        continue;
                    }

                    if (empty($availability['blocks']) && empty($availability['capacity'])) {
                        $issues[] = sprintf('Availability %s leeg (geen blokken/capacity).', $date);
                    }

                    $pricing = $this->call_pricing_preview($product_id, $resource_id, $date);
                    if (is_wp_error($pricing)) {
                        $issues[] = sprintf('Pricing %s: %s', $date, $pricing->get_error_message());
                        continue;
                    }

                    if (empty($pricing['items'])) {
                        $issues[] = sprintf('Pricing %s retourneert geen items.', $date);
                    }
                }

                $rows[] = array(
                    'id'     => $product_id,
                    'name'   => $name,
                    'issues' => empty($issues) ? 'OK' : implode("\n", $issues),
                );
            }

            WP_CLI::success('Controle afgerond.');
            WP_CLI\Utils\format_items('table', $rows, array( 'id', 'name', 'issues' ));
        }

        private function get_bookable_products(): array
        {
            return get_posts(
                array(
                    'post_type'   => 'product',
                    'post_status' => 'publish',
                    'fields'      => 'ids',
                    'numberposts' => -1,
                    'tax_query'   => array(
                        array(
                            'taxonomy' => 'product_type',
                            'field'    => 'slug',
                            'terms'    => array( 'bookable_service' ),
                        ),
                    ),
                )
            );
        }

        private function call_plan_availability(int $product_id, int $resource_id, string $date)
        {
            $request = new WP_REST_Request('GET', '/sbdp/v1/availability/plan');
            $request->set_param('product_id', $product_id);
            $request->set_param('date', $date);
            if ($resource_id > 0) {
                $request->set_param('resource_id', $resource_id);
            }

            return RestService::plan_availability($request);
        }

        private function call_pricing_preview(int $product_id, int $resource_id, string $date)
        {
            $request = new WP_REST_Request('POST', '/sbdp/v1/pricing/preview');
            $request->set_json_params(
                array(
                    'items'        => array(
                        array(
                            'product_id'  => $product_id,
                            'resource_id' => $resource_id,
                            'start'       => $date . 'T10:00:00',
                            'end'         => $date . 'T12:00:00',
                        ),
                    ),
                    'participants' => 1,
                )
            );

            return RestService::preview_pricing($request);
        }

        /**
         * @return array<string, string>
         */
        private function read_core_registry(): array
        {
            if (! class_exists('\BSP\Core\Modules')) {
                return array();
            }

            try {
                $reflection = new ReflectionClass('\BSP\Core\Modules');
                if (! $reflection->hasProperty('registry')) {
                    return array();
                }

                $property = $reflection->getProperty('registry');
                $property->setAccessible(true);
                $value = $property->getValue();

                return is_array($value) ? $value : array();
            } catch (Throwable $exception) {
                return array();
            }
        }

        /**
         * @return array<int, string>
         */
        private function read_engine_modules(): array
        {
            if (! function_exists('sbdp_booking_engine')) {
                return array();
            }

            $engine = sbdp_booking_engine();
            if (! $engine || ! method_exists($engine, 'getModules')) {
                return array();
            }

            $classes = array();
            foreach ($engine->getModules() as $module) {
                if (is_object($module)) {
                    $classes[] = get_class($module);
                }
            }

            return array_values(array_unique($classes));
        }

        private function format_interfaces(bool $core, bool $engine, bool $shared): string
        {
            $labels = array();
            if ($core) {
                $labels[] = 'core';
            }
            if ($engine) {
                $labels[] = 'engine';
            }
            if ($shared) {
                $labels[] = 'shared';
            }

            return $labels ? implode(',', $labels) : '-';
        }

        private function probe_module(string $class, string $status): string
        {
            try {
                $instance = new $class();
                if ($instance instanceof \BSP\Core\Interfaces\ModuleInterface) {
                    $instance->init();
                } elseif ($instance instanceof \SBDP\Contracts\ModuleInterface) {
                    if (function_exists('sbdp_booking_engine') && sbdp_booking_engine()) {
                        $instance->register(sbdp_booking_engine());
                    }
                } elseif ($instance instanceof \BSPModule\Shared\Modules\ModuleInterface) {
                    $instance->register();
                }
            } catch (Throwable $exception) {
                return 'probe failed: ' . $exception->getMessage();
            }

            return $status;
        }
    }
}

if (class_exists('WP_CLI') && WP_CLI) {
    if (method_exists('WP_CLI', 'has_command') && WP_CLI::has_command('sbdp')) {
        return;
    }

    WP_CLI::add_command('sbdp', 'SBDP_Diagnostics_Command');
}
