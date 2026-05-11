<?php
/**
 * Vendor Portal front-end container.
 */
?>
<div class="sbdp-vendor-portal" data-sbdp-vendor-portal>
    <form id="sbdp-vendor-portal-login" class="sbdp-vendor-portal__login ui-card">
        <h2><?php echo esc_html__('Partnerportaal', 'sbdp'); ?></h2>
        <label class="sbdp-vendor-portal__field">
            <span><?php echo esc_html__('Partner-ID', 'sbdp'); ?></span>
            <input type="number" name="vendor_id" min="1" required />
        </label>
        <label class="sbdp-vendor-portal__field">
            <span><?php echo esc_html__('Toegangssleutel', 'sbdp'); ?></span>
            <input type="password" name="access_key" autocomplete="current-password" required />
        </label>
        <button type="submit" class="sbdp-vendor-portal__button ui-btn ui-btn--primary"><?php echo esc_html__('Inloggen', 'sbdp'); ?></button>
        <p class="sbdp-vendor-portal__hint">
            <?php echo esc_html__('Gebruik de toegangssleutel die door beheer is uitgegeven.', 'sbdp'); ?>
        </p>
        <div class="sbdp-vendor-portal__error" role="alert" hidden></div>
    </form>

    <div class="sbdp-vendor-portal__dashboard" hidden>
        <header class="sbdp-vendor-portal__header">
            <div>
                <h2><?php echo esc_html__('Partnerdashboard', 'sbdp'); ?></h2>
                <p class="sbdp-vendor-portal__subheading" data-sbdp-session-label></p>
            </div>
            <div class="sbdp-vendor-portal__header-actions">
                <button type="button" class="sbdp-vendor-portal__button sbdp-vendor-portal__button--ghost ui-btn ui-btn--secondary" data-sbdp-refresh>
                    <?php echo esc_html__('Vernieuwen', 'sbdp'); ?>
                </button>
                <button type="button" class="sbdp-vendor-portal__button sbdp-vendor-portal__logout ui-btn ui-btn--ghost">
                    <?php echo esc_html__('Uitloggen', 'sbdp'); ?>
                </button>
            </div>
        </header>

        <section class="sbdp-vendor-portal__vendor" data-sbdp-vendor hidden>
            <h3 class="sbdp-vendor-portal__vendor-name" data-sbdp-vendor-name></h3>
            <p class="sbdp-vendor-portal__vendor-status" data-sbdp-vendor-status></p>
            <p class="sbdp-vendor-portal__vendor-contact" data-sbdp-vendor-contact hidden></p>
        </section>

        <section class="sbdp-vendor-portal__alerts" aria-live="polite">
            <div class="sbdp-vendor-portal__notice" data-sbdp-notice hidden></div>
        </section>

        <section class="sbdp-vendor-portal__confirmations" data-sbdp-confirmations-section hidden>
            <div class="sbdp-vendor-portal__schedule-header">
                <h3><?php echo esc_html__('Open partnerbevestigingen', 'sbdp'); ?></h3>
                <span data-sbdp-confirmations-count>0</span>
            </div>
            <div class="sbdp-vendor-portal__cards" data-sbdp-confirmations-list></div>
        </section>

        <section class="sbdp-vendor-portal__dietary" data-sbdp-dietary-section hidden>
            <div class="sbdp-vendor-portal__schedule-header">
                <h3><?php echo esc_html__('Allergieën te beoordelen', 'sbdp'); ?></h3>
                <span data-sbdp-dietary-count>0</span>
            </div>
            <div class="sbdp-vendor-portal__cards" data-sbdp-dietary-list></div>
        </section>

        <section class="sbdp-vendor-portal__kpis" data-sbdp-kpis>
            <article class="sbdp-vendor-portal__kpi">
                <h3><?php echo esc_html__('Boekingen (totaal)', 'sbdp'); ?></h3>
                <p data-sbdp-kpi-total-bookings>–</p>
                <span><?php echo esc_html__('Inclusief komende & afgeronde boekingen', 'sbdp'); ?></span>
            </article>
            <article class="sbdp-vendor-portal__kpi">
                <h3><?php echo esc_html__('Omzet deze maand', 'sbdp'); ?></h3>
                <p data-sbdp-kpi-month-revenue>–</p>
                <span data-sbdp-kpi-month-range></span>
            </article>
            <article class="sbdp-vendor-portal__kpi">
                <h3><?php echo esc_html__('Openstaand bedrag', 'sbdp'); ?></h3>
                <p data-sbdp-kpi-pending>–</p>
                <span><?php echo esc_html__('Nog te innen betalingen', 'sbdp'); ?></span>
            </article>
            <article class="sbdp-vendor-portal__kpi">
                <h3><?php echo esc_html__('Gemiddelde groepsgrootte', 'sbdp'); ?></h3>
                <p data-sbdp-kpi-average-size>–</p>
                <span><?php echo esc_html__('Gebaseerd op komende boekingen', 'sbdp'); ?></span>
            </article>
        </section>

        <section class="sbdp-vendor-portal__filters">
            <div class="sbdp-vendor-portal__filter-group">
                <label>
                    <span><?php echo esc_html__('Zoekopdracht', 'sbdp'); ?></span>
                    <input type="search" placeholder="<?php echo esc_attr__('Zoek op klant of resource…', 'sbdp'); ?>" data-sbdp-filter-search />
                </label>
            </div>
            <div class="sbdp-vendor-portal__filter-group">
                <label>
                    <span><?php echo esc_html__('Status', 'sbdp'); ?></span>
                    <select data-sbdp-filter-status>
                        <option value="all"><?php echo esc_html__('Alle statussen', 'sbdp'); ?></option>
                        <option value="upcoming"><?php echo esc_html__('Alleen komende', 'sbdp'); ?></option>
                        <option value="paid"><?php echo esc_html__('Betaald', 'sbdp'); ?></option>
                        <option value="pending"><?php echo esc_html__('Openstaand', 'sbdp'); ?></option>
                        <option value="cancelled"><?php echo esc_html__('Geannuleerd', 'sbdp'); ?></option>
                    </select>
                </label>
            </div>
            <div class="sbdp-vendor-portal__filter-group">
                <label>
                    <span><?php echo esc_html__('Resource', 'sbdp'); ?></span>
                    <select data-sbdp-filter-resource>
                        <option value="all"><?php echo esc_html__('Alle resources', 'sbdp'); ?></option>
                    </select>
                </label>
            </div>
        </section>

        <section class="sbdp-vendor-portal__google" data-sbdp-google-panel hidden>
            <header>
                <h3><?php echo esc_html__('Google Calendar synchronisatie', 'sbdp'); ?></h3>
                <span class="sbdp-vendor-portal__badge" data-sbdp-google-status></span>
            </header>
            <p data-sbdp-google-message></p>
            <div class="sbdp-vendor-portal__google-actions">
                <button type="button" class="sbdp-vendor-portal__button sbdp-vendor-portal__button--ghost ui-btn ui-btn--secondary" data-sbdp-google-refresh>
                    <?php echo esc_html__('Status verversen', 'sbdp'); ?>
                </button>
                <button type="button" class="sbdp-vendor-portal__button ui-btn ui-btn--primary" data-sbdp-google-sync>
                    <?php echo esc_html__('Synchroniseer beschikbaarheid', 'sbdp'); ?>
                </button>
            </div>
            <p class="sbdp-vendor-portal__google-meta" data-sbdp-google-meta hidden></p>
        </section>

        <section class="sbdp-vendor-portal__actions">
            <button type="button" class="sbdp-vendor-portal__button ui-btn ui-btn--secondary" data-sbdp-download-csv>
                <?php echo esc_html__('Download CSV', 'sbdp'); ?>
            </button>
            <button type="button" class="sbdp-vendor-portal__button sbdp-vendor-portal__button--ghost ui-btn ui-btn--ghost" data-sbdp-toggle-view>
                <?php echo esc_html__('Wissel tabel/kaarten', 'sbdp'); ?>
            </button>
        </section>

        <section class="sbdp-vendor-portal__schedule" data-sbdp-view="table">
            <div class="sbdp-vendor-portal__schedule-header">
                <h3><?php echo esc_html__('Boekingen', 'sbdp'); ?></h3>
                <span data-sbdp-results-count>0</span>
            </div>
            <div class="sbdp-vendor-portal__table-wrapper" data-sbdp-table-wrapper>
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
            </div>
            <div class="sbdp-vendor-portal__cards" data-sbdp-cards hidden></div>
        </section>

        <div class="sbdp-vendor-portal__loading" data-sbdp-loading hidden>
            <span class="sbdp-vendor-portal__spinner" aria-hidden="true"></span>
            <p><?php echo esc_html__('Gegevens laden…', 'sbdp'); ?></p>
        </div>
    </div>
</div>
