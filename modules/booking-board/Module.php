<?php

declare(strict_types=1);

namespace BSP\BookingBoard;

use BSP\BookingBoard\Rest\BookingsController;
use BSP\Core\CoreServiceProvider;
use BSP\Core\Interfaces\ModuleInterface;

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

final class Module implements ModuleInterface
{
    public function init(): void
    {
        CoreServiceProvider::logger()->log('Booking Board module initialized');

        if (\function_exists('add_action')) {
            \add_action('admin_menu', [$this, 'registerAdminPage']);
            \add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        }

        if (\function_exists('add_action')) {
            \add_action('rest_api_init', [BookingsController::class, 'register']);
        }

        if (\function_exists('add_filter')) {
            \add_filter('script_loader_tag', [$this, 'markScriptAsModule'], 10, 3);
        }
    }

    public function registerAdminPage(): void
    {
        \add_menu_page(
            __('Booking Board', 'sbdp'),
            __('Booking Board', 'sbdp'),
            'manage_woocommerce',
            'sbdp_booking_board',
            [$this, 'renderAdminPage'],
            'dashicons-calendar-alt',
            58
        );
    }

    public function renderAdminPage(): void
    {
        echo '<div class="wrap"><div id="sbdp-booking-board-root"></div></div>';
    }

    public function enqueueAssets(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (empty($_GET['page']) || $_GET['page'] !== 'sbdp_booking_board') {
            return;
        }

        $assetHandle = 'sbdp-booking-board-app';
        $assetData   = $this->resolveAsset('assets/js/admin/booking-board/index.jsx');
        $scriptUrl   = $assetData['script'];

        \wp_enqueue_script(
            $assetHandle,
            $scriptUrl,
            array('wp-element'),
            SBDP_VER,
            true
        );

        foreach ($assetData['styles'] as $styleHandle => $styleUrl) {
            \wp_enqueue_style($styleHandle, $styleUrl, array(), SBDP_VER);
        }

        \wp_localize_script(
            $assetHandle,
            'SBDP_BOOKING_BOARD',
            array(
                'restBase'        => \rest_url('bsp/v1/booking-board'),
                'nonce'           => \wp_create_nonce('wp_rest'),
                'pollInterval'    => 30,
                'aiEnabled'       => true,
                'filtersDefaults' => array(),
                'storage_key'     => 'sbdp_booking_board_view_mode',
            )
        );
    }

    /**
     * @return array{script:string,styles:array<string,string>}
     */
    private function resolveAsset(string $entry): array
    {
        $manifestPath = SBDP_DIR . 'build/.vite/manifest.json';

        if (! is_readable($manifestPath)) {
            return array(
                'script' => SBDP_URL . 'assets/js/admin/booking-board/index.jsx',
                'styles' => array(),
            );
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest) || ! isset($manifest[$entry])) {
            return array(
                'script' => SBDP_URL . 'assets/js/admin/booking-board/index.jsx',
                'styles' => array(),
            );
        }

        $entryData = $manifest[$entry];
        $script    = isset($entryData['file'])
            ? SBDP_URL . 'build/' . $entryData['file']
            : SBDP_URL . 'assets/js/admin/booking-board/index.jsx';
        $styles    = array();

        if (! empty($entryData['css']) && is_array($entryData['css'])) {
            foreach ($entryData['css'] as $index => $cssFile) {
                $handle = 'sbdp-booking-board-style-' . $index;
                $styles[$handle] = SBDP_URL . 'build/' . ltrim((string) $cssFile, '/');
            }
        }

        return array(
            'script' => $script,
            'styles' => $styles,
        );
    }

    /**
     * Ensure the admin bundle is executed as an ES module.
     */
    public function markScriptAsModule(string $tag, string $handle, string $src): string
    {
        unset($src);

        if ($handle !== 'sbdp-booking-board-app') {
            return $tag;
        }

        if (strpos($tag, 'type=') === false) {
            return str_replace('<script ', '<script type="module" ', $tag);
        }

        return (string) preg_replace('/type=(["\']).*?\\1/', 'type="module"', $tag, 1);
    }
}

if (! \class_exists('BSPModule\\BookingBoard\\Module', false)) {
    \class_alias(Module::class, 'BSPModule\\BookingBoard\\Module');
}

// phpcs:enable PSR1.Files.SideEffects.FoundWithSymbols
