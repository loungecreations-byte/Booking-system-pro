<?php
declare(strict_types=1);

namespace BSP\GeoDashboard\Admin;

final class Page
{
    private ?string $hookSuffix = null;

    public function register(): void
    {
        if (! function_exists('add_menu_page')) {
            return;
        }

        $this->hookSuffix = add_menu_page(
            __('Geo-overzicht', 'sbdp'),
            __('Geo-overzicht', 'sbdp'),
            'manage_options',
            'sbdp-geo-dashboard',
            [$this, 'render'],
            'dashicons-location-alt',
            58
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Je hebt geen toegang tot deze pagina.', 'sbdp'));
        }

        ?>
        <div class="wrap sbdp-geo-dashboard">
            <h1><?php echo esc_html__('Geo-overzicht', 'sbdp'); ?></h1>
            <div class="sbdp-geo-dashboard__filters" data-sbdp-filters>
                <label>
                    <span><?php esc_html_e('Partnerstatus', 'sbdp'); ?></span>
                    <select data-filter="vendorStatus">
                        <option value="all"><?php esc_html_e('Alle', 'sbdp'); ?></option>
                        <option value="active"><?php esc_html_e('Actief', 'sbdp'); ?></option>
                        <option value="pending"><?php esc_html_e('In afwachting', 'sbdp'); ?></option>
                        <option value="suspended"><?php esc_html_e('Gepauzeerd', 'sbdp'); ?></option>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e('Boekingsstatus', 'sbdp'); ?></span>
                    <select data-filter="bookingStatus">
                        <option value="all"><?php esc_html_e('Alle', 'sbdp'); ?></option>
                        <option value="created"><?php esc_html_e('Aangemaakt', 'sbdp'); ?></option>
                        <option value="requested"><?php esc_html_e('Aangevraagd', 'sbdp'); ?></option>
                        <option value="captured"><?php esc_html_e('Vastgelegd', 'sbdp'); ?></option>
                        <option value="paid"><?php esc_html_e('Betaald', 'sbdp'); ?></option>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e('Straal (km)', 'sbdp'); ?></span>
                    <input type="number" data-filter="radius" min="0" value="50" />
                </label>
                <label>
                    <span><?php esc_html_e('Startdatum', 'sbdp'); ?></span>
                    <input type="date" data-filter="startDate" />
                </label>
                <label>
                    <span><?php esc_html_e('Einddatum', 'sbdp'); ?></span>
                    <input type="date" data-filter="endDate" />
                </label>
            </div>
            <div class="sbdp-geo-dashboard__map" data-sbdp-map></div>
            <aside class="sbdp-geo-dashboard__sidebar" data-sbdp-sidebar>
                <h2><?php esc_html_e('Partnerdetails', 'sbdp'); ?></h2>
                <div data-sbdp-vendor-panel>
                    <p class="description"><?php esc_html_e('Selecteer een partner op de kaart om details te bekijken.', 'sbdp'); ?></p>
                </div>
                <h2><?php esc_html_e('Boekingen in de buurt', 'sbdp'); ?></h2>
                <ul data-sbdp-booking-list></ul>
            </aside>
        </div>
        <?php
    }

    public function getHookSuffix(): ?string
    {
        return $this->hookSuffix;
    }
}
