<?php
declare(strict_types=1);

namespace BSP\GeoDashboard;

use BSP\Core\CoreServiceProvider;
use BSP\Core\Interfaces\ModuleInterface;
use BSP\GeoDashboard\Admin\Page;
use BSP\GeoDashboard\Rest\Controller;

final class Module implements ModuleInterface
{
    public function __construct(private ?Page $page = null)
    {
        $this->page = $page ?? new Page();
    }

    public function init(): void
    {
        CoreServiceProvider::logger()->log('GeoDashboard module initialized');

        if (function_exists('add_action')) {
            add_action('admin_menu', [$this, 'registerAdminPage']);
            add_action('rest_api_init', 'BSP\\GeoDashboard\\Rest\\Controller::register');
            add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        }
    }


    public function registerAdminPage(): void
    {
        $this->page->register();
    }
    public function enqueueAssets(string $hook): void
    {
        if ($hook !== $this->page->getHookSuffix()) {
            return;
        }

        $version = defined('SBDP_VER') ? SBDP_VER : '1.0.0';
        $baseUrl = $this->getAssetsBaseUrl();

        if (function_exists('wp_register_style')) {
            wp_register_style('sbdp-geodashboard', $baseUrl . 'geodashboard.css', [], $version);
            wp_register_style('sbdp-geodashboard-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
            wp_register_style('sbdp-geodashboard-markercluster', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css', ['sbdp-geodashboard-leaflet'], '1.5.3');
        }

        if (function_exists('wp_register_script')) {
            wp_register_script('sbdp-geodashboard-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
            wp_register_script('sbdp-geodashboard-markercluster', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js', ['sbdp-geodashboard-leaflet'], '1.5.3', true);
            wp_register_script('sbdp-geodashboard', $baseUrl . 'geodashboard.js', ['sbdp-geodashboard-markercluster'], $version, true);
        }

        wp_enqueue_style('sbdp-geodashboard-markercluster');
        wp_enqueue_style('sbdp-geodashboard');

        wp_enqueue_script('sbdp-geodashboard');

        if (function_exists('wp_localize_script')) {
            wp_localize_script(
                'sbdp-geodashboard',
                'SBDP_GEODASHBOARD',
                [
                    'restUrl' => function_exists('rest_url') ? rest_url('bsp/v1/geodashboard') : '/wp-json/bsp/v1/geodashboard',
                    'nonce'   => function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '',
                ]
            );
        }
    }

    private function getAssetsBaseUrl(): string
    {
        if (defined('SBDP_FILE') && function_exists('plugins_url')) {
            return rtrim(plugins_url('modules/geo-dashboard/assets/', SBDP_FILE), '/') . '/';
        }

        if (function_exists('plugin_dir_url')) {
            return rtrim(plugin_dir_url(__FILE__) . 'assets', '/') . '/';
        }

        return 'modules/geo-dashboard/assets/';
    }
}


