<?php
/**
 * Vendor Portal front-end container.
 */
?>
<div class="sbdp-vendor-portal" data-sbdp-vendor-portal>
    <form id="sbdp-vendor-portal-login" class="sbdp-vendor-portal__login ui-card">
        <div class="sbdp-vendor-portal__login-brand">
            <span class="sbdp-vendor-portal__login-icon" aria-hidden="true">◈</span>
            <h2><?php echo esc_html__('Partnerportaal', 'sbdp'); ?></h2>
            <p><?php echo esc_html__('Login met uw Partner-ID en toegangssleutel.', 'sbdp'); ?></p>
        </div>
        <label class="sbdp-vendor-portal__field">
            <span><?php echo esc_html__('Partner-ID', 'sbdp'); ?></span>
            <input type="number" name="vendor_id" min="1" required autocomplete="username" />
        </label>
        <label class="sbdp-vendor-portal__field">
            <span><?php echo esc_html__('Toegangssleutel', 'sbdp'); ?></span>
            <input type="password" name="access_key" autocomplete="current-password" required />
        </label>
        <label class="sbdp-vendor-portal__field sbdp-vendor-portal__field--inline">
            <input type="checkbox" name="remember_me" value="1" />
            <span><?php echo esc_html__('Onthoud mij (7 dagen)', 'sbdp'); ?></span>
        </label>
        <button type="submit" class="sbdp-vendor-portal__button ui-btn ui-btn--primary"><?php echo esc_html__('Inloggen', 'sbdp'); ?></button>
        <p class="sbdp-vendor-portal__hint">
            <?php echo esc_html__('Uw Partner-ID en toegangssleutel zijn verstrekt door DagjeDenBosch.', 'sbdp'); ?>
            <a href="mailto:info@dagjedenbosch.nl?subject=Partnerportaal%20toegang" class="sbdp-vendor-portal__hint-link"><?php echo esc_html__('Hulp nodig?', 'sbdp'); ?></a>
        </p>
        <div class="sbdp-vendor-portal__error" role="alert" hidden></div>
    </form>

    <div class="sbdp-vendor-portal__dashboard" hidden>
        <header class="sbdp-vendor-portal__header">
            <div class="sbdp-vendor-portal__header-identity">
                <h2><?php echo esc_html__('Partnerdashboard', 'sbdp'); ?></h2>
                <p class="sbdp-vendor-portal__subheading" data-sbdp-session-label></p>
            </div>
            <div class="sbdp-vendor-portal__header-actions">
                <button type="button" class="sbdp-vendor-portal__button sbdp-vendor-portal__button--ghost ui-btn ui-btn--secondary" data-sbdp-refresh>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    <?php echo esc_html__('Vernieuwen', 'sbdp'); ?>
                </button>
                <button type="button" class="sbdp-vendor-portal__button sbdp-vendor-portal__logout ui-btn ui-btn--ghost">
                    <?php echo esc_html__('Uitloggen', 'sbdp'); ?>
                </button>
            </div>
        </header>

        <section class="sbdp-vendor-portal__alerts" aria-live="polite">
            <div class="sbdp-vendor-portal__notice" data-sbdp-notice hidden></div>
        </section>

        <!-- Tabs -->
        <nav class="sbdp-vendor-portal__tabs" role="tablist" aria-label="<?php echo esc_attr__('Portaal navigatie', 'sbdp'); ?>">
            <button id="sbdp-tab-control-overview" class="sbdp-vp-tab is-active" type="button" role="tab" aria-selected="true" tabindex="0" data-sbdp-tab="overview" aria-controls="sbdp-tab-overview"><?php echo esc_html__('Overzicht', 'sbdp'); ?></button>
            <button id="sbdp-tab-control-bookings" class="sbdp-vp-tab" type="button" role="tab" aria-selected="false" tabindex="-1" data-sbdp-tab="bookings" aria-controls="sbdp-tab-bookings"><?php echo esc_html__('Boekingen', 'sbdp'); ?> <span class="sbdp-vp-tab__badge" data-sbdp-results-count></span></button>
            <button id="sbdp-tab-control-actions" class="sbdp-vp-tab" type="button" role="tab" aria-selected="false" tabindex="-1" data-sbdp-tab="actions" aria-controls="sbdp-tab-actions"><?php echo esc_html__('Acties', 'sbdp'); ?> <span class="sbdp-vp-tab__badge sbdp-vp-tab__badge--alert" data-sbdp-actions-count hidden></span></button>
            <button id="sbdp-tab-control-settings" class="sbdp-vp-tab" type="button" role="tab" aria-selected="false" tabindex="-1" data-sbdp-tab="settings" aria-controls="sbdp-tab-settings"><?php echo esc_html__('Instellingen', 'sbdp'); ?></button>
        </nav>

        <!-- Tab: Overzicht -->
        <div id="sbdp-tab-overview" role="tabpanel" aria-labelledby="sbdp-tab-control-overview" class="sbdp-vp-panel is-active" data-sbdp-panel="overview">

            <section class="sbdp-vendor-portal__kpis" data-sbdp-kpis>
                <article class="sbdp-vendor-portal__kpi">
                    <span class="sbdp-vp-kpi__label"><?php echo esc_html__('Boekingen YTD', 'sbdp'); ?></span>
                    <p class="sbdp-vp-kpi__value" data-sbdp-kpi-total-bookings>–</p>
                    <span class="sbdp-vp-kpi__sub" data-sbdp-kpi-ytd-year></span>
                </article>
                <article class="sbdp-vendor-portal__kpi">
                    <span class="sbdp-vp-kpi__label"><?php echo esc_html__('Omzet YTD', 'sbdp'); ?></span>
                    <p class="sbdp-vp-kpi__value" data-sbdp-kpi-ytd-revenue>–</p>
                    <span class="sbdp-vp-kpi__sub" data-sbdp-kpi-month-range></span>
                </article>
                <article class="sbdp-vendor-portal__kpi">
                    <span class="sbdp-vp-kpi__label"><?php echo esc_html__('Openstaand', 'sbdp'); ?></span>
                    <p class="sbdp-vp-kpi__value" data-sbdp-kpi-pending>–</p>
                    <span class="sbdp-vp-kpi__sub"><?php echo esc_html__('Nog te innen', 'sbdp'); ?></span>
                </article>
                <article class="sbdp-vendor-portal__kpi">
                    <span class="sbdp-vp-kpi__label"><?php echo esc_html__('Gem. groepsgrootte', 'sbdp'); ?></span>
                    <p class="sbdp-vp-kpi__value" data-sbdp-kpi-average-size>–</p>
                    <span class="sbdp-vp-kpi__sub"><?php echo esc_html__('Komende boekingen', 'sbdp'); ?></span>
                </article>
            </section>

            <section class="sbdp-vp-chart-section">
                <h3 class="sbdp-vp-section-title"><?php echo esc_html__('Omzetontwikkeling', 'sbdp'); ?></h3>
                <div class="sbdp-vp-chart" data-sbdp-chart-wrap>
                    <svg class="sbdp-vp-chart__svg" data-sbdp-chart aria-label="<?php echo esc_attr__('Omzet per maand', 'sbdp'); ?>" role="img"></svg>
                    <p class="sbdp-vp-chart__empty" data-sbdp-chart-empty hidden><?php echo esc_html__('Geen omzetdata beschikbaar.', 'sbdp'); ?></p>
                </div>
            </section>

            <!-- Recente boekingen (top 5) -->
            <section class="sbdp-vp-recent">
                <div class="sbdp-vp-section-header">
                    <h3 class="sbdp-vp-section-title"><?php echo esc_html__('Recente boekingen', 'sbdp'); ?></h3>
                    <button type="button" class="sbdp-vp-link" data-sbdp-tab-goto="bookings"><?php echo esc_html__('Alle boekingen →', 'sbdp'); ?></button>
                </div>
                <div class="sbdp-vendor-portal__cards" data-sbdp-recent-cards></div>
            </section>

        </div><!-- /tab: Overzicht -->

        <!-- Tab: Boekingen -->
        <div id="sbdp-tab-bookings" role="tabpanel" aria-labelledby="sbdp-tab-control-bookings" class="sbdp-vp-panel" data-sbdp-panel="bookings" hidden>

            <section class="sbdp-vendor-portal__filters">
                <div class="sbdp-vendor-portal__filter-group">
                    <label>
                        <span><?php echo esc_html__('Zoeken', 'sbdp'); ?></span>
                        <input type="search" placeholder="<?php echo esc_attr__('Klant of resource…', 'sbdp'); ?>" data-sbdp-filter-search />
                    </label>
                </div>
                <div class="sbdp-vendor-portal__filter-group">
                    <label>
                        <span><?php echo esc_html__('Status', 'sbdp'); ?></span>
                        <select data-sbdp-filter-status>
                            <option value="all"><?php echo esc_html__('Alle statussen', 'sbdp'); ?></option>
                            <option value="upcoming"><?php echo esc_html__('Komende', 'sbdp'); ?></option>
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

            <section class="sbdp-vendor-portal__actions">
                <button type="button" class="sbdp-vendor-portal__button ui-btn ui-btn--secondary" data-sbdp-download-csv>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <?php echo esc_html__('Download CSV', 'sbdp'); ?>
                </button>
                <button type="button" class="sbdp-vendor-portal__button sbdp-vendor-portal__button--ghost ui-btn ui-btn--ghost" data-sbdp-toggle-view>
                    <?php echo esc_html__('Toon kaarten', 'sbdp'); ?>
                </button>
            </section>

            <section class="sbdp-vendor-portal__schedule" data-sbdp-view="cards">
                <div class="sbdp-vendor-portal__schedule-header">
                    <h3><?php echo esc_html__('Boekingen', 'sbdp'); ?></h3>
                </div>
                <div class="sbdp-vendor-portal__table-wrapper" data-sbdp-table-wrapper hidden>
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
                <div class="sbdp-vendor-portal__cards" data-sbdp-cards></div>
            </section>

        </div><!-- /tab: Boekingen -->

        <!-- Tab: Acties -->
        <div id="sbdp-tab-actions" role="tabpanel" aria-labelledby="sbdp-tab-control-actions" class="sbdp-vp-panel" data-sbdp-panel="actions" hidden>

            <section class="sbdp-vp-action-center" aria-labelledby="sbdp-actions-title">
                <div class="sbdp-vp-action-summary">
                    <div>
                        <h3 id="sbdp-actions-title" class="sbdp-vp-section-title"><?php echo esc_html__('Actiecentrum', 'sbdp'); ?></h3>
                        <p><strong data-sbdp-action-open-count>0</strong> <?php echo esc_html__('open acties', 'sbdp'); ?> <span aria-hidden="true">·</span> <span data-sbdp-action-urgent-count>0</span> <?php echo esc_html__('urgent', 'sbdp'); ?></p>
                    </div>
                    <button type="button" class="sbdp-vp-icon-button" data-sbdp-action-refresh aria-label="<?php echo esc_attr__('Acties vernieuwen', 'sbdp'); ?>" title="<?php echo esc_attr__('Acties vernieuwen', 'sbdp'); ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    </button>
                </div>
                <div class="sbdp-vp-action-filters" role="group" aria-label="<?php echo esc_attr__('Filter acties', 'sbdp'); ?>">
                    <button type="button" class="sbdp-vp-filter is-active" data-sbdp-action-filter="all" aria-pressed="true"><?php echo esc_html__('Alles', 'sbdp'); ?></button>
                    <button type="button" class="sbdp-vp-filter" data-sbdp-action-filter="confirmations" aria-pressed="false"><?php echo esc_html__('Bevestigingen', 'sbdp'); ?></button>
                    <button type="button" class="sbdp-vp-filter" data-sbdp-action-filter="dietary" aria-pressed="false"><?php echo esc_html__('Allergieën', 'sbdp'); ?></button>
                </div>
            </section>

            <div class="sbdp-vp-action-empty" data-sbdp-action-empty hidden>
                <h3><?php echo esc_html__('Alles is bijgewerkt', 'sbdp'); ?></h3>
                <p><?php echo esc_html__('Nieuwe partnerbevestigingen en controles verschijnen hier automatisch.', 'sbdp'); ?></p>
            </div>

            <section class="sbdp-vendor-portal__confirmations" data-sbdp-confirmations-section>
                <div class="sbdp-vp-section-header">
                    <h3 class="sbdp-vp-section-title"><?php echo esc_html__('Open partnerbevestigingen', 'sbdp'); ?></h3>
                    <span class="sbdp-vp-tab__badge sbdp-vp-tab__badge--alert" data-sbdp-confirmations-count>0</span>
                </div>
                <div class="sbdp-vendor-portal__cards" data-sbdp-confirmations-list></div>
            </section>

            <section class="sbdp-vendor-portal__dietary" data-sbdp-dietary-section>
                <div class="sbdp-vp-section-header">
                    <h3 class="sbdp-vp-section-title"><?php echo esc_html__('Allergieën te beoordelen', 'sbdp'); ?></h3>
                    <span class="sbdp-vp-tab__badge sbdp-vp-tab__badge--alert" data-sbdp-dietary-count>0</span>
                </div>
                <div class="sbdp-vendor-portal__cards" data-sbdp-dietary-list></div>
            </section>

            <dialog class="sbdp-vp-action-dialog" data-sbdp-action-dialog aria-labelledby="sbdp-action-dialog-title">
                <form method="dialog" class="sbdp-vp-action-dialog__surface" data-sbdp-action-dialog-form>
                    <div class="sbdp-vp-action-dialog__header">
                        <div>
                            <span class="sbdp-vp-action-dialog__eyebrow" data-sbdp-action-dialog-type></span>
                            <h3 id="sbdp-action-dialog-title" data-sbdp-action-dialog-title></h3>
                        </div>
                        <button type="button" class="sbdp-vp-icon-button" data-sbdp-action-dialog-close aria-label="<?php echo esc_attr__('Sluiten', 'sbdp'); ?>" title="<?php echo esc_attr__('Sluiten', 'sbdp'); ?>">×</button>
                    </div>
                    <p data-sbdp-action-dialog-copy></p>
                    <label class="sbdp-vendor-portal__field">
                        <span><?php echo esc_html__('Toelichting', 'sbdp'); ?></span>
                        <textarea rows="4" data-sbdp-action-dialog-note required></textarea>
                    </label>
                    <p class="sbdp-vp-action-dialog__error" data-sbdp-action-dialog-error role="alert" hidden></p>
                    <div class="sbdp-vp-action-dialog__actions">
                        <button type="button" class="sbdp-vendor-portal__button sbdp-vendor-portal__button--ghost" data-sbdp-action-dialog-cancel><?php echo esc_html__('Annuleren', 'sbdp'); ?></button>
                        <button type="submit" class="sbdp-vendor-portal__button" data-sbdp-action-dialog-submit></button>
                    </div>
                </form>
            </dialog>

        </div><!-- /tab: Acties -->

        <!-- Tab: Instellingen -->
        <div id="sbdp-tab-settings" role="tabpanel" aria-labelledby="sbdp-tab-control-settings" class="sbdp-vp-panel" data-sbdp-panel="settings" hidden>

            <section class="sbdp-vendor-portal__vendor" data-sbdp-vendor>
                <div class="sbdp-vp-section-header">
                    <h3 class="sbdp-vp-section-title"><?php echo esc_html__('Partnerprofiel', 'sbdp'); ?></h3>
                </div>
                <div class="sbdp-vp-profile-card">
                    <p class="sbdp-vendor-portal__vendor-name" data-sbdp-vendor-name></p>
                    <p class="sbdp-vendor-portal__vendor-status" data-sbdp-vendor-status></p>
                    <p class="sbdp-vendor-portal__vendor-contact" data-sbdp-vendor-contact hidden></p>
                </div>
            </section>

            <section class="sbdp-vendor-portal__google" data-sbdp-google-panel hidden>
                <div class="sbdp-vp-section-header">
                    <h3 class="sbdp-vp-section-title"><?php echo esc_html__('Google Calendar', 'sbdp'); ?></h3>
                    <span class="sbdp-vendor-portal__badge" data-sbdp-google-status></span>
                </div>
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

        </div><!-- /tab: Instellingen -->

        <div class="sbdp-vendor-portal__loading" data-sbdp-loading hidden>
            <span class="sbdp-vendor-portal__spinner" aria-hidden="true"></span>
            <p><?php echo esc_html__('Gegevens laden…', 'sbdp'); ?></p>
        </div>
    </div>
</div>
