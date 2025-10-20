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
            __('Geo Dashboard', 'sbdp'),
            __('Geo Dashboard', 'sbdp'),
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
            wp_die(__('You are not allowed to access this page.', 'sbdp'));
        }

        ?>
        <div class="wrap sbdp-geo-dashboard">
            <h1><?php echo esc_html__('Geo Dashboard', 'sbdp'); ?></h1>
            <div class="sbdp-geo-dashboard__filters" data-sbdp-filters>
                <label>
                    <span><?php esc_html_e('Vendor status', 'sbdp'); ?></span>
                    <select data-filter="vendorStatus">
                        <option value="all"><?php esc_html_e('All', 'sbdp'); ?></option>
                        <option value="active"><?php esc_html_e('Active', 'sbdp'); ?></option>
                        <option value="pending"><?php esc_html_e('Pending', 'sbdp'); ?></option>
                        <option value="suspended"><?php esc_html_e('Suspended', 'sbdp'); ?></option>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e('Booking status', 'sbdp'); ?></span>
                    <select data-filter="bookingStatus">
                        <option value="all"><?php esc_html_e('All', 'sbdp'); ?></option>
                        <option value="created"><?php esc_html_e('Created', 'sbdp'); ?></option>
                        <option value="requested"><?php esc_html_e('Requested', 'sbdp'); ?></option>
                        <option value="captured"><?php esc_html_e('Captured', 'sbdp'); ?></option>
                        <option value="paid"><?php esc_html_e('Paid', 'sbdp'); ?></option>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e('Travel radius (km)', 'sbdp'); ?></span>
                    <input type="number" data-filter="radius" min="0" value="50" />
                </label>
                <label>
                    <span><?php esc_html_e('Start date', 'sbdp'); ?></span>
                    <input type="date" data-filter="startDate" />
                </label>
                <label>
                    <span><?php esc_html_e('End date', 'sbdp'); ?></span>
                    <input type="date" data-filter="endDate" />
                </label>
            </div>
            <div class="sbdp-geo-dashboard__map" data-sbdp-map></div>
            <aside class="sbdp-geo-dashboard__sidebar" data-sbdp-sidebar>
                <h2><?php esc_html_e('Vendor details', 'sbdp'); ?></h2>
                <div data-sbdp-vendor-panel>
                    <p class="description"><?php esc_html_e('Selecteer een vendor op de kaart om details te bekijken.', 'sbdp'); ?></p>
                </div>
                <h2><?php esc_html_e('Bookings nearby', 'sbdp'); ?></h2>
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
