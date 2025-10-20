<?php
/**
 * Vendor Portal front-end container.
 */
?>
<div class="sbdp-vendor-portal" data-sbdp-vendor-portal>
    <form id="sbdp-vendor-portal-login" class="sbdp-vendor-portal__login">
        <h2><?php echo esc_html__('Vendor Portal', 'sbdp'); ?></h2>
        <label>
            <span><?php echo esc_html__('Vendor ID', 'sbdp'); ?></span>
            <input type="number" name="vendor_id" min="1" required />
        </label>
        <label>
            <span><?php echo esc_html__('Access key', 'sbdp'); ?></span>
            <input type="password" name="access_key" required />
        </label>
        <button type="submit" class="sbdp-vendor-portal__button"><?php echo esc_html__('Log in', 'sbdp'); ?></button>
        <p class="sbdp-vendor-portal__hint">
            <?php echo esc_html__('Gebruik toegangssleutel "demo" voor testdoeleinden.', 'sbdp'); ?>
        </p>
        <div class="sbdp-vendor-portal__error" role="alert" hidden></div>
    </form>

    <div class="sbdp-vendor-portal__dashboard" hidden>
        <header class="sbdp-vendor-portal__header">
            <h2><?php echo esc_html__('Vendor Dashboard', 'sbdp'); ?></h2>
            <button type="button" class="sbdp-vendor-portal__logout"><?php echo esc_html__('Uitloggen', 'sbdp'); ?></button>
        </header>

        <section class="sbdp-vendor-portal__financial">
            <h3><?php echo esc_html__('Financieel overzicht', 'sbdp'); ?></h3>
            <dl class="sbdp-vendor-portal__stats" data-sbdp-financial-stats>
                <div>
                    <dt><?php echo esc_html__('Totale omzet', 'sbdp'); ?></dt>
                    <dd data-sbdp-total-revenue>–</dd>
                </div>
                <div>
                    <dt><?php echo esc_html__('Betaald', 'sbdp'); ?></dt>
                    <dd data-sbdp-paid-revenue>–</dd>
                </div>
                <div>
                    <dt><?php echo esc_html__('Openstaand', 'sbdp'); ?></dt>
                    <dd data-sbdp-pending-revenue>–</dd>
                </div>
            </dl>
        </section>

        <section class="sbdp-vendor-portal__schedule">
            <h3><?php echo esc_html__('Komende boekingen', 'sbdp'); ?></h3>
            <table data-sbdp-schedule>
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Datum', 'sbdp'); ?></th>
                        <th><?php echo esc_html__('Tijd', 'sbdp'); ?></th>
                        <th><?php echo esc_html__('Klant', 'sbdp'); ?></th>
                        <th><?php echo esc_html__('Deelnemers', 'sbdp'); ?></th>
                        <th><?php echo esc_html__('Resource', 'sbdp'); ?></th>
                        <th><?php echo esc_html__('Status', 'sbdp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr data-sbdp-placeholder>
                        <td colspan="6"><?php echo esc_html__('Geen boekingen gevonden.', 'sbdp'); ?></td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</div>
