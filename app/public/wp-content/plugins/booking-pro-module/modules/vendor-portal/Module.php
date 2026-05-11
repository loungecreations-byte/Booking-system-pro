<?php

declare(strict_types=1);

namespace BSP\VendorPortal;

use BSP\Core\CoreServiceProvider;
use BSP\Core\Interfaces\ModuleInterface;
use BSP\VendorPortal\Service\VendorPortalOperationsBridge;
use BSP\VendorPortal\Rest\PortalController;
use BSP\VendorPortal\Rest\AdminController;
use Throwable;

if (! class_exists(__NAMESPACE__ . '\\Module', false)) {
    final class Module implements ModuleInterface
    {
        private static ?self $instance = null;
        private static bool $bootstrapped = false;

        private VendorPortalOperationsBridge $operationsBridge;
        private string $adminPageSlug = 'sbdp_vendor_portal';

        public function __construct(?VendorPortalOperationsBridge $operationsBridge = null)
        {
            $this->operationsBridge = $operationsBridge ?? new VendorPortalOperationsBridge();

            if (self::$instance === null) {
                self::$instance = $this;
            }
        }

        public function init(): void
        {
            if (self::$bootstrapped) {
                return;
            }

            self::$bootstrapped = true;

            CoreServiceProvider::logger()->log('Vendor Portal module initialized');

            if (function_exists('add_action')) {
                add_action('rest_api_init', [PortalController::class, 'register']);
                add_action('rest_api_init', [AdminController::class, 'register']);
                add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
                add_action('admin_menu', [$this, 'registerAdminPage']);
                add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
            }

            if (function_exists('add_shortcode')) {
                $callback = [$this, 'renderShortcode'];
                add_shortcode('bsp_vendor_portal', $callback);
                if (! function_exists('shortcode_exists') || ! shortcode_exists('sbdp_vendor_portal')) {
                    add_shortcode('sbdp_vendor_portal', $callback);
                }
            }

            $this->operationsBridge->register();

            if (function_exists('add_action')) {
                add_action('sbdp/ops/vendor_portal_audit', [$this, 'forwardOpsAudit'], 20, 1);
            }
        }

        public static function ensureBooted(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }

            self::$instance->init();

            return self::$instance;
        }

        public function renderShortcode(): string
        {
            $this->ensureAssetsRegistered();

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
                            'loginError'            => __('Aanmelding mislukt. Controleer uw gegevens.', 'sbdp'),
                            'networkError'          => __('Netwerkfout. Probeer het later opnieuw.', 'sbdp'),
                            'refreshSuccess'        => __('Dashboard bijgewerkt.', 'sbdp'),
                            'filterResourceAll'     => __('Alle resources', 'sbdp'),
                            'noBookings'            => __('Geen boekingen gevonden.', 'sbdp'),
                            'cardDate'              => __('Datum', 'sbdp'),
                            'cardTime'              => __('Tijd', 'sbdp'),
                            'cardParticipants'      => __('Deelnemers', 'sbdp'),
                            'cardResource'          => __('Resource', 'sbdp'),
                            'cardTotal'             => __('Totaal', 'sbdp'),
                            'resultSingular'        => __('boeking', 'sbdp'),
                            'resultPlural'          => __('boekingen', 'sbdp'),
                            'googleStatusRefreshed' => __('Google-status vernieuwd.', 'sbdp'),
                            'statusConnected'       => __('Verbonden', 'sbdp'),
                            'statusDisconnected'    => __('Niet verbonden.', 'sbdp'),
                            'statusUnavailable'     => __('Niet beschikbaar.', 'sbdp'),
                            'statusError'           => __('Fout', 'sbdp'),
                            'googleUnavailable'     => __('Synchronisatie niet beschikbaar.', 'sbdp'),
                            'googleConnected'       => __('Synchronisatie actief.', 'sbdp'),
                            'googleDisconnected'    => __('Nog niet gekoppeld.', 'sbdp'),
                            'lastSynced'            => __('Laatste sync:', 'sbdp'),
                            'lastError'             => __('Laatste fout:', 'sbdp'),
                            'googleSyncSuccess'     => __('Synchronisatie voltooid.', 'sbdp'),
                            'googleSyncError'       => __('Synchronisatie mislukt:', 'sbdp'),
                            'sessionLabel'          => __('Vendor ID', 'sbdp'),
                            'sessionExpires'        => __('sessie verloopt om', 'sbdp'),
                            'viewTable'             => __('Toon tabel', 'sbdp'),
                            'viewCards'             => __('Toon kaarten', 'sbdp'),
                            'downloadEmpty'         => __('Geen gegevens om te exporteren.', 'sbdp'),
                            'downloadReady'         => __('CSV-download gestart.', 'sbdp'),
                            'logoutLabel'           => __('Uitloggen', 'sbdp'),
                            'vendorStatus'          => __('Status', 'sbdp'),
                            'vendorContact'         => __('Contact', 'sbdp'),
                            'contactNameLabel'      => __('Contactpersoon', 'sbdp'),
                            'contactEmailLabel'     => __('E-mail', 'sbdp'),
                            'contactPhoneLabel'     => __('Telefoon', 'sbdp'),
                            'vendorFallbackName'    => __('Onbekende aanbieder', 'sbdp'),
                            'confirmationsTitle'    => __('Open partnerbevestigingen', 'sbdp'),
                            'confirmationsEmpty'    => __('Geen open partnerbevestigingen.', 'sbdp'),
                            'confirmAction'         => __('Bevestigen', 'sbdp'),
                            'declineAction'         => __('Afwijzen', 'sbdp'),
                            'alternativeAction'     => __('Alternatief voorstellen', 'sbdp'),
                            'confirmationCustomer'  => __('Gastgroep', 'sbdp'),
                            'confirmationStatus'    => __('Bevestigingsstatus', 'sbdp'),
                            'confirmationResponded' => __('Reactie verwerkt.', 'sbdp'),
                            'confirmationDeclinePrompt' => __('Waarom kun je deze stop niet bevestigen?', 'sbdp'),
                            'confirmationAlternativePrompt' => __('Welk alternatief stel je voor?', 'sbdp'),
                        ),
                    )
                );
            }
        }

        private function ensureAssetsRegistered(): void
        {
            if (
                ! function_exists('wp_register_script')
                || ! function_exists('wp_register_style')
            ) {
                return;
            }

            $scriptRegistered = function_exists('wp_script_is')
                && wp_script_is('sbdp-vendor-portal', 'registered');
            $styleRegistered  = function_exists('wp_style_is')
                && wp_style_is('sbdp-vendor-portal', 'registered');

            if ($scriptRegistered && $styleRegistered) {
                return;
            }

            $this->registerAssets();
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

        public function registerAdminPage(): void
        {
            add_menu_page(
                __('Partnerportaal', 'sbdp'),
                __('Partnerportaal', 'sbdp'),
                'manage_woocommerce',
                $this->adminPageSlug,
                [$this, 'renderAdminPage'],
                'dashicons-admin-site',
                59
            );
        }

        public function renderAdminPage(): void
        {
            echo '<div class="wrap"><h1>' . esc_html__('Partnerportaal', 'sbdp') . '</h1><div id="sbdp-vendor-portal-admin"></div></div>';
        }

        public function enqueueAdminAssets(string $hook): void
        {
            if ($hook !== 'toplevel_page_' . $this->adminPageSlug) {
                return;
            }

            $styleHandle  = 'sbdp-vendor-portal-admin';
            $scriptHandle = 'sbdp-vendor-portal-admin';

            $stylePath = SBDP_DIR . 'assets/css/admin/vendor-portal.css';
            if (is_readable($stylePath)) {
                wp_enqueue_style(
                    $styleHandle,
                    SBDP_URL . 'assets/css/admin/vendor-portal.css',
                    array(),
                    SBDP_VER
                );
            }

            wp_enqueue_script(
                $scriptHandle,
                SBDP_URL . 'assets/js/admin/vendor-portal.js',
                array(),
                SBDP_VER,
                true
            );

            wp_localize_script(
                $scriptHandle,
                'SBDP_VENDOR_PORTAL_ADMIN',
                array(
                    'restUrl'    => rest_url('bsp/v1/vendor-portal/admin'),
                    'nonce'      => wp_create_nonce('wp_rest'),
                    'portalUrl'  => apply_filters('sbdp/vendor_portal/admin/portal_url', site_url('/vendor-portal')),
                )
            );
        }

        /**
         * @param array<string, mixed> $payload
         */
        public function forwardOpsAudit(array $payload): void
        {
            $this->recordOpsAudit($payload);
            $this->logOpsAudit($payload);

            if (function_exists('do_action')) {
                do_action('sbdp/ops/vendor_portal_audit/forwarded', $payload);
            }
        }

        /**
         * @param array<string, mixed> $payload
         */
        private function recordOpsAudit(array $payload): void
        {
            if (! class_exists('\SBDP_Audit_Trail_Service')) {
                return;
            }

            try {
                \SBDP_Audit_Trail_Service::record('vendor_portal.audit', $payload);
            } catch (Throwable $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            }
        }

        /**
         * @param array<string, mixed> $payload
         */
        private function logOpsAudit(array $payload): void
        {
            $event    = isset($payload['event']) ? (string) $payload['event'] : 'unknown';
            $context  = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : array();
            $vendorId = isset($context['vendor_id']) ? (string) $context['vendor_id'] : 'n/a';

            $message = sprintf('Vendor Portal ops audit event %s for vendor %s', $event, $vendorId);

                CoreServiceProvider::logger()->log($message);
        }
    }
}

if (function_exists('add_action')) {
    add_action(
        'init',
        static function (): void {
            if (! function_exists('shortcode_exists')) {
                return;
            }

            if (
                shortcode_exists('bsp_vendor_portal')
                || shortcode_exists('sbdp_vendor_portal')
            ) {
                return;
            }

            Module::ensureBooted();
        },
        1
    );
}
