<?php

declare(strict_types=1);

namespace BSP\VendorPortal;

use BSP\Core\CoreServiceProvider;
use BSP\Core\Interfaces\ModuleInterface;
use BSP\VendorPortal\Rest\PortalController;

final class Module implements ModuleInterface
{
    public function init(): void
    {
        CoreServiceProvider::logger()->log('Vendor Portal module initialized');

        if (function_exists('add_action')) {
            add_action('rest_api_init', [PortalController::class, 'register']);
            add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
        }

        if (function_exists('add_shortcode')) {
            add_shortcode('bsp_vendor_portal', [$this, 'renderShortcode']);
        }
    }

    public function renderShortcode(): string
    {
        if (function_exists('wp_enqueue_style')) {
            wp_enqueue_style('sbdp-vendor-portal');
        }

        if (function_exists('wp_enqueue_script')) {
            wp_enqueue_script('sbdp-vendor-portal');
        }

        $template = __DIR__ . '/Templates/dashboard.php';
        if (! file_exists($template)) {
            return '';
        }

        ob_start();
        include $template;
        return (string) ob_get_clean();
    }

    public function registerAssets(): void
    {
        if (! function_exists('wp_register_style')) {
            return;
        }

        $version = defined('SBDP_VER') ? SBDP_VER : '1.0.0';
        $baseUrl = $this->getAssetsBaseUrl();

        wp_register_style(
            'sbdp-vendor-portal',
            $baseUrl . 'vendor-portal.css',
            array(),
            $version
        );

        wp_register_script(
            'sbdp-vendor-portal',
            $baseUrl . 'vendor-portal.js',
            array(),
            $version,
            true
        );

        if (function_exists('wp_localize_script')) {
            wp_localize_script(
                'sbdp-vendor-portal',
                'SBDP_VENDOR_PORTAL',
                array(
                    'restUrl' => function_exists('rest_url') ? rest_url('bsp/v1/vendor-portal') : '/wp-json/bsp/v1/vendor-portal',
                    'i18n'    => array(
                        'loginError'      => __('Aanmelding mislukt. Controleer uw gegevens.', 'sbdp'),
                        'networkError'    => __('Netwerkfout. Probeer het later opnieuw.', 'sbdp'),
                        'scheduleHeading' => __('Komende boekingen', 'sbdp'),
                        'financeHeading'  => __('Financieel overzicht', 'sbdp'),
                        'logoutLabel'     => __('Uitloggen', 'sbdp'),
                    ),
                )
            );
        }
    }

    private function getAssetsBaseUrl(): string
    {
        $ensureTrailingSlash = static function (string $url): string {
            return rtrim($url, '/') . '/';
        };

        if (defined('SBDP_FILE') && function_exists('plugins_url')) {
            $url = plugins_url('modules/vendor-portal/assets/', SBDP_FILE);
            return $ensureTrailingSlash($url);
        }

        if (function_exists('plugin_dir_url')) {
            $url = plugin_dir_url(__FILE__) . 'assets';
            return $ensureTrailingSlash($url);
        }

        return 'modules/vendor-portal/assets/';
    }
}
