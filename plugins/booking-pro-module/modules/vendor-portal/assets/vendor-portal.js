(function () {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }

    function bootstrap() {
        const root = document.querySelector('[data-sbdp-vendor-portal]');
        if (!root) {
            return;
        }

        const elements = {
            root,
            loginForm: root.querySelector('#sbdp-vendor-portal-login'),
            dashboard: root.querySelector('.sbdp-vendor-portal__dashboard'),
            errorBox: root.querySelector('.sbdp-vendor-portal__error'),
            logoutButton: root.querySelector('.sbdp-vendor-portal__logout'),
            refreshButton: root.querySelector('[data-sbdp-refresh]'),
            noticeBox: root.querySelector('[data-sbdp-notice]'),
            confirmationsSection: root.querySelector('[data-sbdp-confirmations-section]'),
            confirmationsCount: root.querySelector('[data-sbdp-confirmations-count]'),
            confirmationsList: root.querySelector('[data-sbdp-confirmations-list]'),
            vendorSection: root.querySelector('[data-sbdp-vendor]'),
            vendorName: root.querySelector('[data-sbdp-vendor-name]'),
            vendorStatus: root.querySelector('[data-sbdp-vendor-status]'),
            vendorContact: root.querySelector('[data-sbdp-vendor-contact]'),
            sessionLabel: root.querySelector('[data-sbdp-session-label]'),
            filterSearch: root.querySelector('[data-sbdp-filter-search]'),
            filterStatus: root.querySelector('[data-sbdp-filter-status]'),
            filterResource: root.querySelector('[data-sbdp-filter-resource]'),
            resultsCount: root.querySelector('[data-sbdp-results-count]'),
            scheduleSection: root.querySelector('.sbdp-vendor-portal__schedule'),
            scheduleTableBody: root.querySelector('[data-sbdp-schedule] tbody'),
            cardsContainer: root.querySelector('[data-sbdp-cards]'),
            loadingOverlay: root.querySelector('[data-sbdp-loading]'),
            toggleViewBtn: root.querySelector('[data-sbdp-toggle-view]'),
            downloadCsvBtn: root.querySelector('[data-sbdp-download-csv]'),
            kpiTotalBookings: root.querySelector('[data-sbdp-kpi-total-bookings]'),
            kpiMonthRevenue: root.querySelector('[data-sbdp-kpi-month-revenue]'),
            kpiMonthRange: root.querySelector('[data-sbdp-kpi-month-range]'),
            kpiPending: root.querySelector('[data-sbdp-kpi-pending]'),
            kpiAverageSize: root.querySelector('[data-sbdp-kpi-average-size]'),
            googlePanel: root.querySelector('[data-sbdp-google-panel]'),
            googleStatus: root.querySelector('[data-sbdp-google-status]'),
            googleMessage: root.querySelector('[data-sbdp-google-message]'),
            googleMeta: root.querySelector('[data-sbdp-google-meta]'),
            googleRefresh: root.querySelector('[data-sbdp-google-refresh]'),
            googleSync: root.querySelector('[data-sbdp-google-sync]'),
            dietarySection: root.querySelector('[data-sbdp-dietary-section]'),
            dietaryCount: root.querySelector('[data-sbdp-dietary-count]'),
            dietaryList: root.querySelector('[data-sbdp-dietary-list]')
        };

        const defaults = {
            loginError: 'Aanmelding mislukt. Controleer uw gegevens.',
            networkError: 'Netwerkfout. Probeer het later opnieuw.',
            refreshSuccess: 'Dashboard bijgewerkt.',
            filterResourceAll: 'Alle resources',
            noBookings: 'Geen boekingen gevonden.',
            cardDate: 'Datum',
            cardTime: 'Tijd',
            cardParticipants: 'Deelnemers',
            cardResource: 'Resource',
            cardTotal: 'Totaal',
            resultSingular: 'boeking',
            resultPlural: 'boekingen',
            googleStatusRefreshed: 'Google-status vernieuwd.',
            statusConnected: 'Verbonden',
            statusDisconnected: 'Niet verbonden',
            statusUnavailable: 'Niet beschikbaar',
            statusError: 'Fout',
            googleUnavailable: 'Synchronisatie niet beschikbaar.',
            googleConnected: 'Synchronisatie actief.',
            googleDisconnected: 'Nog niet gekoppeld.',
            lastSynced: 'Laatste sync:',
            lastError: 'Laatste fout:',
            googleSyncSuccess: 'Synchronisatie voltooid.',
            googleSyncError: 'Synchronisatie mislukt:',
            sessionLabel: 'Vendor ID',
            sessionExpires: 'sessie verloopt om',
            viewTable: 'Toon tabel',
            viewCards: 'Toon kaarten',
            downloadEmpty: 'Geen gegevens om te exporteren.',
            downloadReady: 'CSV-download gestart.',
            logoutLabel: 'Uitloggen',
            vendorStatus: 'Status',
            vendorContact: 'Contact',
            contactNameLabel: 'Contactpersoon',
            contactEmailLabel: 'E-mail',
            contactPhoneLabel: 'Telefoon',
            vendorFallbackName: 'Onbekende aanbieder',
            confirmationsTitle: 'Open partnerbevestigingen',
            confirmationsEmpty: 'Geen open partnerbevestigingen.',
            confirmAction: 'Bevestigen',
            declineAction: 'Afwijzen',
            alternativeAction: 'Alternatief voorstellen',
            confirmationCustomer: 'Gastgroep',
            confirmationStatus: 'Bevestigingsstatus',
            confirmationResponded: 'Reactie verwerkt.',
            confirmationDeclinePrompt: 'Waarom kun je deze stop niet bevestigen?',
            confirmationAlternativePrompt: 'Welk alternatief stel je voor?',
            dietaryTitle: 'Allergieën te beoordelen',
            dietaryEmpty: 'Geen openstaande allergie-beoordelingen.',
            dietaryAccept: 'Allergieën accepteren',
            dietaryReject: 'Allergieën afwijzen',
            dietaryRejectPrompt: 'Waarom kun je de allergieën niet accommoderen?',
            dietaryAllergens: 'Allergenen',
            dietarySeverity: 'Ernst',
            dietaryGuestCount: 'Gasten',
            dietaryStatus: 'Status',
            dietaryResponded: 'Allergie-beoordeling verwerkt.',
            dietaryStatusPending: 'In afwachting',
            dietaryStatusCleared: 'Akkoord'
        };

        const localized = (window.SBDP_VENDOR_PORTAL && window.SBDP_VENDOR_PORTAL.i18n) || {};
        const i18n = Object.assign({}, defaults, localized);
        const restUrl = (window.SBDP_VENDOR_PORTAL && window.SBDP_VENDOR_PORTAL.restUrl) || '/wp-json/bsp/v1/vendor-portal';
        const config = {
            restBase: String(restUrl).replace(/\/$/, '')
        };

        const state = {
            session: loadSession(),
            bookings: [],
            confirmations: [],
            filtered: [],
            financial: null,
            calendar: null,
            vendor: null,
            view: 'table',
            currency: 'EUR',
            dietaryPending: []
        };

        initialise();

        function initialise() {
            hideError();
            clearNotice();
            ensurePlaceholder();
            registerEvents();
            updateViewButton();

            if (state.session) {
                showDashboard(true);
                updateSessionLabel();
                refreshDashboard({ silent: true }).catch(function () {
                    // Errors are surfaced inside refreshDashboard.
                });
            } else {
                showDashboard(false);
            }
        }

        function registerEvents() {
            if (elements.loginForm) {
                elements.loginForm.addEventListener('submit', handleLogin);
            }

            if (elements.logoutButton) {
                elements.logoutButton.addEventListener('click', handleLogout);
            }

            if (elements.refreshButton) {
                elements.refreshButton.addEventListener('click', function () {
                    refreshDashboard().catch(function () {
                        // Errors are handled downstream.
                    });
                });
            }

            if (elements.confirmationsList) {
                elements.confirmationsList.addEventListener('click', handleConfirmationClick);
            }

            if (elements.dietaryList) {
                elements.dietaryList.addEventListener('click', handleDietaryClick);
            }

            if (elements.filterSearch) {
                elements.filterSearch.addEventListener('input', debounce(function () {
                    applyFilters();
                }, 200));
            }

            if (elements.filterStatus) {
                elements.filterStatus.addEventListener('change', applyFilters);
            }

            if (elements.filterResource) {
                elements.filterResource.addEventListener('change', applyFilters);
            }

            if (elements.toggleViewBtn) {
                elements.toggleViewBtn.addEventListener('click', function () {
                    state.view = state.view === 'table' ? 'cards' : 'table';
                    if (elements.scheduleSection) {
                        elements.scheduleSection.dataset.sbdpView = state.view;
                    }
                    updateViewButton();
                });
            }

            if (elements.downloadCsvBtn) {
                elements.downloadCsvBtn.addEventListener('click', downloadCsv);
            }

            if (elements.googleRefresh) {
                elements.googleRefresh.addEventListener('click', function () {
                    refreshGoogleStatus().catch(function () {
                        // notice already shown
                    });
                });
            }

            if (elements.googleSync) {
                elements.googleSync.addEventListener('click', function () {
                    syncGoogleCalendar().catch(function () {
                        // notice already shown
                    });
                });
            }
        }

        function handleLogin(event) {
            event.preventDefault();
            hideError();
            clearNotice();

            if (!elements.loginForm) {
                return;
            }

            const formData = new FormData(elements.loginForm);
            const vendorId = parseInt(formData.get('vendor_id'), 10) || 0;
            const accessKey = String(formData.get('access_key') || '').trim();

            if (!vendorId || !accessKey) {
                showError(i18n.loginError);
                return;
            }

            setLoading(true);

            api('/login', {
                method: 'POST',
                body: {
                    vendor_id: vendorId,
                    access_key: accessKey
                },
                includeToken: false
            })
                .then(function (response) {
                    state.session = {
                        token: String(response.token || ''),
                        vendor_id: response.vendor_id,
                        expires_in: response.expires_in,
                        stored_at: Date.now()
                    };
                    storeSession(state.session);
                    updateSessionLabel();
                    showDashboard(true);
                    return refreshDashboard({ silent: true, manageLoading: false });
                })
                .then(function () {
                    showNotice(i18n.refreshSuccess);
                })
                .catch(function (error) {
                    showError(error);
                })
                .finally(function () {
                    setLoading(false);
                });
        }

        function handleLogout() {
            clearNotice();

            if (!state.session) {
                resetSession(true);
                return;
            }

            setLoading(true);

            api('/logout', {
                method: 'POST',
                body: {
                    token: state.session.token || ''
                }
            })
                .catch(function () {
                    // ignore logout failures
                })
                .finally(function () {
                    setLoading(false);
                    resetSession(true);
                });
        }

        function refreshDashboard(options) {
            const settings = Object.assign({ silent: false, manageLoading: true }, options);

            if (!state.session || !state.session.token) {
                return Promise.resolve();
            }

            if (settings.manageLoading) {
                setLoading(true);
            }

            return api(withToken('/dashboard'))
                .then(function (response) {
                    const dashboard = response && typeof response === 'object' ? response.dashboard : null;
                    state.bookings = Array.isArray(dashboard && dashboard.bookings) ? dashboard.bookings.slice() : [];
                    state.confirmations = Array.isArray(dashboard && dashboard.confirmations) ? dashboard.confirmations.slice() : [];
                    // Use dedicated dietary summary from server; fallback to filtering confirmations for backwards compat
                    if (dashboard && dashboard.dietary && typeof dashboard.dietary === 'object') {
                        state.dietaryPending = Object.values(dashboard.dietary);
                    } else {
                        state.dietaryPending = (state.confirmations).filter(function (c) {
                            return c && c.partner_card && c.partner_card.allergy_risk_status === 'pending_review';
                        });
                    }
                    state.financial = dashboard && dashboard.financial ? dashboard.financial : null;
                    state.calendar = dashboard && dashboard.calendar ? dashboard.calendar : null;
                    state.vendor = dashboard && dashboard.vendor ? dashboard.vendor : null;

                    const sessionData = response && typeof response.session === 'object' ? response.session : {};
                    state.session = Object.assign({}, state.session || {}, sessionData, {
                        token: state.session ? state.session.token : null,
                        stored_at: Date.now()
                    });
                    storeSession(state.session);
                    updateSessionLabel();
                    renderDashboard();

                    if (!settings.silent) {
                        showNotice(i18n.refreshSuccess);
                    }
                })
                .catch(function (error) {
                    if (error && error.status === 403) {
                        handleUnauthorized(error.message);
                        return;
                    }
                    showNotice(error && error.message ? error.message : i18n.networkError, true);
                })
                .finally(function () {
                    if (settings.manageLoading) {
                        setLoading(false);
                    }
                });
        }

        function refreshGoogleStatus() {
            if (!state.session || !state.session.token) {
                return Promise.resolve();
            }

            setGoogleLoading(true);

            return api(withToken('/google-status'))
                .then(function (response) {
                    if (response && response.status) {
                        renderGoogle(response.status);
                        showNotice(i18n.googleStatusRefreshed);
                    }
                })
                .catch(function (error) {
                    if (error && error.status === 403) {
                        handleUnauthorized(error.message);
                        return;
                    }
                    showNotice(error && error.message ? error.message : i18n.networkError, true);
                })
                .finally(function () {
                    setGoogleLoading(false);
                });
        }

        function syncGoogleCalendar() {
            if (!state.session || !state.session.token) {
                return Promise.resolve();
            }

            setGoogleLoading(true);

            return api('/google-sync', {
                method: 'POST',
                body: {
                    token: state.session.token
                }
            })
                .then(function (response) {
                    if (response && response.status) {
                        renderGoogle(response.status);
                    }
                    showNotice(i18n.googleSyncSuccess);
                })
                .catch(function (error) {
                    if (error && error.status === 403) {
                        handleUnauthorized(error.message);
                        return;
                    }
                    const message = i18n.googleSyncError + ' ' + (error && error.message ? error.message : i18n.networkError);
                    showNotice(message.trim(), true);
                })
                .finally(function () {
                    setGoogleLoading(false);
                });
        }

        function renderDashboard() {
            renderVendorProfile();
            renderConfirmations();
            renderDietary();
            renderFinancial();
            renderResources();
            applyFilters();
            renderGoogle(state.calendar);
        }

        function renderVendorProfile() {
            if (!elements.vendorSection) {
                return;
            }

            const vendor = state.vendor;
            if (!vendor) {
                elements.vendorSection.hidden = true;
                if (elements.vendorName) {
                    elements.vendorName.textContent = '';
                }
                if (elements.vendorStatus) {
                    elements.vendorStatus.textContent = '';
                }
                if (elements.vendorContact) {
                    elements.vendorContact.textContent = '';
                    elements.vendorContact.hidden = true;
                }
                return;
            }

            const name = (vendor.name && String(vendor.name).trim()) || i18n.vendorFallbackName;
            if (elements.vendorName) {
                elements.vendorName.textContent = name;
            }

            if (elements.vendorStatus) {
                const status = vendor.status ? String(vendor.status).trim() : '';
                const formatted = formatVendorStatus(status);
                elements.vendorStatus.textContent = formatted === '' ? '' : i18n.vendorStatus + ': ' + formatted;
            }

            if (elements.vendorContact) {
                const parts = [];
                if (vendor.contact_name) {
                    parts.push(i18n.contactNameLabel + ': ' + String(vendor.contact_name));
                }
                if (vendor.contact_email) {
                    parts.push(i18n.contactEmailLabel + ': ' + String(vendor.contact_email));
                }
                if (vendor.contact_phone) {
                    parts.push(i18n.contactPhoneLabel + ': ' + String(vendor.contact_phone));
                }

                if (parts.length) {
                    elements.vendorContact.textContent = i18n.vendorContact + ': ' + parts.join(' • ');
                    elements.vendorContact.hidden = false;
                } else {
                    elements.vendorContact.textContent = '';
                    elements.vendorContact.hidden = true;
                }
            }

            elements.vendorSection.hidden = false;
        }

        function renderConfirmations() {
            if (!elements.confirmationsSection || !elements.confirmationsList) {
                return;
            }

            elements.confirmationsList.innerHTML = '';

            if (!Array.isArray(state.confirmations) || !state.confirmations.length) {
                elements.confirmationsSection.hidden = true;
                if (elements.confirmationsCount) {
                    elements.confirmationsCount.textContent = '0';
                }
                return;
            }

            const fragment = document.createDocumentFragment();
            state.confirmations.forEach(function (confirmation) {
                fragment.appendChild(createConfirmationCard(confirmation));
            });
            elements.confirmationsList.appendChild(fragment);
            elements.confirmationsSection.hidden = false;

            if (elements.confirmationsCount) {
                elements.confirmationsCount.textContent = String(state.confirmations.length);
            }
        }

        function renderDietary() {
            if (!elements.dietarySection || !elements.dietaryList) {
                return;
            }

            elements.dietaryList.innerHTML = '';

            if (!Array.isArray(state.dietaryPending) || !state.dietaryPending.length) {
                elements.dietarySection.hidden = true;
                if (elements.dietaryCount) {
                    elements.dietaryCount.textContent = '0';
                }
                return;
            }

            const fragment = document.createDocumentFragment();
            state.dietaryPending.forEach(function (confirmation) {
                fragment.appendChild(createDietaryCard(confirmation));
            });
            elements.dietaryList.appendChild(fragment);
            elements.dietarySection.hidden = false;

            if (elements.dietaryCount) {
                elements.dietaryCount.textContent = String(state.dietaryPending.length);
            }
        }

        function createDietaryCard(dietaryEntry) {
            const card = document.createElement('article');
            card.className = 'sbdp-vendor-portal__card sbdp-vendor-portal__dietary-card';

            const legKey = String(dietaryEntry.leg_key || dietaryEntry.booking_reference || '');
            const profiles = Array.isArray(dietaryEntry.profiles) ? dietaryEntry.profiles : [];

            const header = document.createElement('header');
            const title = document.createElement('h4');
            title.textContent = String(dietaryEntry.booking_reference || '');
            const badge = document.createElement('span');
            badge.className = 'sbdp-vendor-portal__badge sbdp-vendor-portal__badge--warning';
            badge.textContent = i18n.dietaryStatusPending;
            header.appendChild(title);
            header.appendChild(badge);
            card.appendChild(header);

            // Per-guest profiles table
            if (profiles.length > 0) {
                const guestList = document.createElement('div');
                guestList.className = 'sbdp-dietary-guest-list';
                profiles.forEach(function (p) {
                    const row = document.createElement('div');
                    row.className = 'sbdp-dietary-guest-row severity--' + String(p.severity || 'none');

                    const nameEl = document.createElement('strong');
                    nameEl.textContent = p.guest_name || 'Gast';
                    row.appendChild(nameEl);

                    if (Array.isArray(p.allergen_flags) && p.allergen_flags.length) {
                        const allergenEl = document.createElement('span');
                        allergenEl.className = 'sbdp-dietary-allergens';
                        allergenEl.textContent = i18n.dietaryAllergens + ': ' + p.allergen_flags.join(', ');
                        row.appendChild(allergenEl);
                    }

                    if (p.severity && p.severity !== 'none') {
                        const sevEl = document.createElement('span');
                        sevEl.className = 'sbdp-dietary-severity sbdp-dietary-severity--' + p.severity;
                        sevEl.textContent = i18n.dietarySeverity + ': ' + p.severity;
                        row.appendChild(sevEl);
                    }

                    if (p.menu_choice) {
                        const menuEl = document.createElement('span');
                        menuEl.className = 'sbdp-dietary-menu';
                        menuEl.textContent = 'Menu: ' + p.menu_choice;
                        row.appendChild(menuEl);
                    }

                    if (p.notes) {
                        const notesEl = document.createElement('p');
                        notesEl.className = 'sbdp-dietary-notes';
                        notesEl.textContent = p.notes;
                        row.appendChild(notesEl);
                    }

                    guestList.appendChild(row);
                });
                card.appendChild(guestList);
            }

            // Inline rejection textarea (hidden by default)
            const rejectForm = document.createElement('div');
            rejectForm.className = 'sbdp-dietary-reject-form';
            rejectForm.hidden = true;
            const rejectLabel = document.createElement('label');
            rejectLabel.textContent = i18n.dietaryRejectPrompt;
            const rejectTextarea = document.createElement('textarea');
            rejectTextarea.className = 'sbdp-vendor-portal__reject-note';
            rejectTextarea.placeholder = 'Toelichting vereist...';
            rejectTextarea.rows = 3;
            const rejectConfirmBtn = document.createElement('button');
            rejectConfirmBtn.type = 'button';
            rejectConfirmBtn.className = 'sbdp-vendor-portal__button sbdp-vendor-portal__button--danger';
            rejectConfirmBtn.textContent = 'Bevestig afwijzing';
            rejectConfirmBtn.setAttribute('data-sbdp-dietary-action', 'reject');
            rejectConfirmBtn.setAttribute('data-sbdp-leg-key', legKey);
            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'sbdp-vendor-portal__button sbdp-vendor-portal__button--ghost';
            cancelBtn.textContent = 'Annuleren';
            cancelBtn.addEventListener('click', function () { rejectForm.hidden = true; });
            rejectLabel.appendChild(rejectTextarea);
            rejectForm.appendChild(rejectLabel);
            rejectForm.appendChild(rejectConfirmBtn);
            rejectForm.appendChild(cancelBtn);

            const actions = document.createElement('div');
            actions.className = 'sbdp-vendor-portal__header-actions';

            const acceptBtn = document.createElement('button');
            acceptBtn.type = 'button';
            acceptBtn.className = 'sbdp-vendor-portal__button';
            acceptBtn.textContent = i18n.dietaryAccept;
            acceptBtn.setAttribute('data-sbdp-dietary-action', 'accept');
            acceptBtn.setAttribute('data-sbdp-leg-key', legKey);
            actions.appendChild(acceptBtn);

            const rejectBtn = document.createElement('button');
            rejectBtn.type = 'button';
            rejectBtn.className = 'sbdp-vendor-portal__button sbdp-vendor-portal__button--ghost';
            rejectBtn.textContent = i18n.dietaryReject;
            rejectBtn.addEventListener('click', function () {
                rejectForm.hidden = false;
                rejectTextarea.focus();
            });
            actions.appendChild(rejectBtn);

            card.appendChild(actions);
            card.appendChild(rejectForm);
            return card;
        }

        function handleDietaryClick(event) {
            const button = event.target && event.target.closest ? event.target.closest('[data-sbdp-dietary-action]') : null;
            if (!button) {
                return;
            }

            const legKey = String(button.getAttribute('data-sbdp-leg-key') || '').trim();
            const action = String(button.getAttribute('data-sbdp-dietary-action') || '').trim();
            if (!legKey || !action || !state.session || !state.session.token) {
                return;
            }

            // Only accept goes here directly; reject is handled via inline form (rejectForm)
            let note = '';
            if (action === 'reject') {
                // Look for inline textarea within the same card
                const card = button.closest('.sbdp-vendor-portal__dietary-card');
                const textarea = card ? card.querySelector('.sbdp-vendor-portal__reject-note') : null;
                note = textarea ? textarea.value.trim() : '';
                if (!note) {
                    return;
                }
            }

            setLoading(true);

            api('/dietary/respond', {
                method: 'POST',
                body: {
                    token: state.session.token,
                    leg_key: legKey,
                    action: action,
                    note: note
                }
            })
                .then(function () {
                    showNotice(i18n.dietaryResponded);
                    return refreshDashboard({ silent: true, manageLoading: false });
                })
                .catch(function (error) {
                    if (error && error.status === 403) {
                        handleUnauthorized(error.message);
                        return;
                    }
                    showNotice(error && error.message ? error.message : i18n.networkError, true);
                })
                .finally(function () {
                    setLoading(false);
                });
        }

        function renderFinancial() {
            const financial = state.financial || null;
            const currency = financial && financial.currency ? String(financial.currency) : 'EUR';
            state.currency = currency;

            if (elements.kpiTotalBookings) {
                const total = financial && typeof financial.total_bookings === 'number' ? financial.total_bookings : state.bookings.length;
                elements.kpiTotalBookings.textContent = String(total);
            }

            if (elements.kpiMonthRevenue) {
                const paid = financial && typeof financial.paid_revenue === 'number' ? financial.paid_revenue : 0;
                elements.kpiMonthRevenue.textContent = formatCurrency(paid, currency);
            }

            if (elements.kpiPending) {
                const pending = financial && typeof financial.pending_revenue === 'number' ? financial.pending_revenue : 0;
                elements.kpiPending.textContent = formatCurrency(pending, currency);
            }

            if (elements.kpiMonthRange) {
                const range = getCurrentMonthRange();
                elements.kpiMonthRange.textContent = formatMonthRange(range.start, range.end);
            }

            if (elements.kpiAverageSize) {
                const now = Date.now();
                const upcoming = state.bookings.filter(function (booking) {
                    const ms = normaliseTimestamp(booking.timestamp);
                    return ms >= now;
                });

                const totalParticipants = upcoming.reduce(function (total, booking) {
                    const participants = typeof booking.participants === 'number' ? booking.participants : parseInt(booking.participants, 10) || 0;
                    return total + participants;
                }, 0);

                const average = upcoming.length ? (totalParticipants / upcoming.length) : 0;
                elements.kpiAverageSize.textContent = average ? average.toFixed(1) : '-';
            }
        }

        function renderResources() {
            if (!elements.filterResource) {
                return;
            }

            const previous = elements.filterResource.value;
            const values = new Set();

            state.bookings.forEach(function (booking) {
                const resource = booking && booking.resource ? String(booking.resource).trim() : '';
                if (resource !== '') {
                    values.add(resource);
                }
            });

            const sorted = Array.from(values).sort();
            elements.filterResource.innerHTML = '';

            const allOption = document.createElement('option');
            allOption.value = 'all';
            allOption.textContent = i18n.filterResourceAll;
            elements.filterResource.appendChild(allOption);

            sorted.forEach(function (value) {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = value;
                elements.filterResource.appendChild(option);
            });

            if (previous && (previous === 'all' || values.has(previous))) {
                elements.filterResource.value = previous;
            }
        }

        function applyFilters() {
            const searchTerm = elements.filterSearch ? String(elements.filterSearch.value || '').trim().toLowerCase() : '';
            const statusFilter = elements.filterStatus ? String(elements.filterStatus.value || 'all') : 'all';
            const resourceFilter = elements.filterResource ? String(elements.filterResource.value || 'all') : 'all';
            const now = Date.now();

            state.filtered = state.bookings.filter(function (booking) {
                if (!booking || typeof booking !== 'object') {
                    return false;
                }

                const ms = normaliseTimestamp(booking.timestamp);

                if (statusFilter === 'upcoming' && ms < now) {
                    return false;
                }

                if (statusFilter !== 'all' && statusFilter !== 'upcoming') {
                    const status = String(booking.status || '').toLowerCase();
                    if (status !== statusFilter) {
                        return false;
                    }
                }

                if (resourceFilter !== 'all') {
                    const resource = String(booking.resource || '').trim().toLowerCase();
                    if (resource !== resourceFilter.toLowerCase()) {
                        return false;
                    }
                }

                if (searchTerm !== '') {
                    const haystack = [
                        booking.customer,
                        booking.resource,
                        booking.notes,
                        booking.status
                    ]
                        .map(function (value) {
                            return String(value || '').toLowerCase();
                        })
                        .join(' ');

                    if (haystack.indexOf(searchTerm) === -1) {
                        return false;
                    }
                }

                return true;
            });

            renderResults();
        }

        function renderResults() {
            renderTable();
            renderCards();

            if (elements.resultsCount) {
                elements.resultsCount.textContent = formatResultsCount(state.filtered.length);
            }
        }

        function renderTable() {
            if (!elements.scheduleTableBody) {
                return;
            }

            elements.scheduleTableBody.innerHTML = '';

            if (!state.filtered.length) {
                elements.scheduleTableBody.appendChild(createPlaceholderRow());
                return;
            }

            const fragment = document.createDocumentFragment();

            state.filtered.forEach(function (booking) {
                fragment.appendChild(createTableRow(booking));
            });

            elements.scheduleTableBody.appendChild(fragment);
        }

        function renderCards() {
            if (!elements.cardsContainer) {
                return;
            }

            elements.cardsContainer.innerHTML = '';

            if (!state.filtered.length) {
                const empty = document.createElement('div');
                empty.className = 'sbdp-vendor-portal__card-empty';
                empty.textContent = i18n.noBookings;
                elements.cardsContainer.appendChild(empty);
                return;
            }

            const fragment = document.createDocumentFragment();
            state.filtered.forEach(function (booking) {
                fragment.appendChild(createCard(booking));
            });
            elements.cardsContainer.appendChild(fragment);
        }

        function handleConfirmationClick(event) {
            const button = event.target && event.target.closest ? event.target.closest('[data-sbdp-confirm-action]') : null;
            if (!button) {
                return;
            }

            const legKey = String(button.getAttribute('data-sbdp-leg-key') || '').trim();
            const action = String(button.getAttribute('data-sbdp-confirm-action') || '').trim();
            if (!legKey || !action || !state.session || !state.session.token) {
                return;
            }

            let note = '';
            if (action === 'decline') {
                note = window.prompt(i18n.confirmationDeclinePrompt, '') || '';
                if (!note.trim()) {
                    return;
                }
            }

            if (action === 'alternative') {
                note = window.prompt(i18n.confirmationAlternativePrompt, '') || '';
                if (!note.trim()) {
                    return;
                }
            }

            setLoading(true);

            api('/confirmations/respond', {
                method: 'POST',
                body: {
                    token: state.session.token,
                    leg_key: legKey,
                    action: action,
                    note: note
                }
            })
                .then(function () {
                    showNotice(i18n.confirmationResponded);
                    return refreshDashboard({ silent: true, manageLoading: false });
                })
                .catch(function (error) {
                    if (error && error.status === 403) {
                        handleUnauthorized(error.message);
                        return;
                    }
                    showNotice(error && error.message ? error.message : i18n.networkError, true);
                })
                .finally(function () {
                    setLoading(false);
                });
        }
        function renderGoogle(calendar) {
            if (!elements.googlePanel) {
                return;
            }

            const hasData = calendar && typeof calendar === 'object' && Object.keys(calendar).length > 0;

            if (!hasData) {
                elements.googlePanel.hidden = true;
                return;
            }

            state.calendar = calendar;
            elements.googlePanel.hidden = false;

            const connected = !!calendar.connected;
            const hasError = calendar.last_error && calendar.last_error.message;
            const hasCalendarId = calendar.calendar_id && calendar.calendar_id !== '';

            if (elements.googleStatus) {
                let label = i18n.statusUnavailable;

                if (connected) {
                    label = i18n.statusConnected;
                } else if (hasError) {
                    label = i18n.statusError;
                } else if (hasCalendarId) {
                    label = i18n.statusDisconnected;
                }

                elements.googleStatus.textContent = label;
            }

            if (elements.googleMessage) {
                let message = i18n.googleUnavailable;
                if (connected) {
                    message = i18n.googleConnected;
                } else if (hasCalendarId) {
                    message = i18n.googleDisconnected;
                }
                elements.googleMessage.textContent = message;
            }

            if (elements.googleMeta) {
                const lines = [];

                const lastSync = calendar.last_sync && calendar.last_sync.synced_at ? calendar.last_sync.synced_at : calendar.last_synced_at;
                if (lastSync) {
                    lines.push(i18n.lastSynced + ' ' + formatDateTime(lastSync));
                }

                if (hasError) {
                    lines.push(i18n.lastError + ' ' + String(calendar.last_error.message));
                }

                if (calendar.last_event_push) {
                    lines.push(String(calendar.last_event_push));
                }

                elements.googleMeta.textContent = lines.join(' • ');
                elements.googleMeta.hidden = !lines.length;
            }
        }

        function downloadCsv() {
            if (!state.filtered.length) {
                showNotice(i18n.downloadEmpty, true);
                return;
            }

            const headers = ['ID', 'Date', 'Time', 'Customer', 'Participants', 'Resource', 'Status', 'Total', 'Currency'];
            const rows = state.filtered.map(function (booking) {
                return [
                    booking.id || '',
                    booking.date || '',
                    booking.time || '',
                    booking.customer || '',
                    booking.participants || '',
                    booking.resource || '',
                    booking.status || '',
                    booking.total || '',
                    booking.currency || state.currency || 'EUR'
                ];
            });

            const csv = [headers].concat(rows).map(function (row) {
                return row.map(formatCsvValue).join(',');
            }).join('\r\n');

            try {
                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = 'vendor-portal-' + Date.now() + '.csv';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
                showNotice(i18n.downloadReady);
            } catch (error) {
                showNotice(i18n.networkError, true);
            }
        }

        function api(path, options) {
            const settings = Object.assign({ method: 'GET', includeToken: true }, options || {});
            const headers = Object.assign({}, settings.headers || {});

            if (settings.includeToken !== false && state.session && state.session.token) {
                headers['X-SBDP-Vendor-Token'] = state.session.token;
            }

            const fetchOptions = {
                method: settings.method,
                headers: headers,
                credentials: 'same-origin'
            };

            if (settings.body !== undefined && settings.body !== null) {
                if (typeof settings.body === 'string') {
                    fetchOptions.body = settings.body;
                } else {
                    fetchOptions.body = JSON.stringify(settings.body);
                    if (!headers['Content-Type']) {
                        headers['Content-Type'] = 'application/json';
                    }
                }
            }

            return fetch(config.restBase + path, fetchOptions).then(function (response) {
                const contentType = response.headers.get('Content-Type') || '';

                if (contentType.indexOf('application/json') !== -1) {
                    return response.json().catch(function () {
                        return {};
                    }).then(function (payload) {
                        if (!response.ok) {
                            const message = payload && payload.message ? payload.message : response.statusText || i18n.networkError;
                            const error = new Error(String(message));
                            error.status = response.status;
                            error.body = payload;
                            throw error;
                        }

                        return payload;
                    });
                }

                if (!response.ok) {
                    const error = new Error(i18n.networkError);
                    error.status = response.status;
                    throw error;
                }

                return {};
            });
        }

        function withToken(path) {
            if (!state.session || !state.session.token) {
                return path;
            }

            const separator = path.indexOf('?') === -1 ? '?' : '&';
            return path + separator + 'token=' + encodeURIComponent(state.session.token);
        }

        function showDashboard(isAuthenticated) {
            if (elements.loginForm) {
                elements.loginForm.hidden = !!isAuthenticated;
            }
            if (elements.dashboard) {
                elements.dashboard.hidden = !isAuthenticated;
            }
        }

        function setLoading(isLoading) {
            if (isLoading) {
                root.classList.add('is-loading');
            } else {
                root.classList.remove('is-loading');
            }

            if (elements.loadingOverlay) {
                elements.loadingOverlay.hidden = !isLoading;
            }
        }

        function setGoogleLoading(isLoading) {
            if (!elements.googlePanel) {
                return;
            }

            elements.googlePanel.classList.toggle('is-loading', !!isLoading);
        }

        function updateViewButton() {
            if (!elements.toggleViewBtn) {
                return;
            }

            if (state.view === 'table') {
                elements.toggleViewBtn.textContent = i18n.viewCards;
            } else {
                elements.toggleViewBtn.textContent = i18n.viewTable;
            }
        }

        function updateSessionLabel() {
            if (!elements.sessionLabel) {
                return;
            }

            if (!state.session || !state.session.vendor_id) {
                elements.sessionLabel.textContent = '';
                return;
            }

            const parts = [];
            if (state.vendor && state.vendor.name) {
                parts.push(String(state.vendor.name));
            }
            parts.push(i18n.sessionLabel + ' ' + state.session.vendor_id);

            if (state.session.expires_in) {
                const base = state.session.stored_at || Date.now();
                const expiresAt = base + Number(state.session.expires_in) * 1000;
                parts.push(i18n.sessionExpires + ' ' + formatDateTime(expiresAt));
            }

            elements.sessionLabel.textContent = parts.join(' — ');
        }

        function showError(error) {
            if (!elements.errorBox) {
                return;
            }

            const message = extractMessage(error) || i18n.loginError;
            elements.errorBox.textContent = message;
            elements.errorBox.hidden = false;
        }

        function hideError() {
            if (!elements.errorBox) {
                return;
            }

            elements.errorBox.hidden = true;
            elements.errorBox.textContent = '';
        }

        function showNotice(message, isError) {
            if (!elements.noticeBox) {
                return;
            }

            elements.noticeBox.textContent = message;
            elements.noticeBox.hidden = false;
            elements.noticeBox.classList.toggle('is-error', !!isError);
        }

        function clearNotice() {
            if (!elements.noticeBox) {
                return;
            }

            elements.noticeBox.hidden = true;
            elements.noticeBox.classList.remove('is-error');
            elements.noticeBox.textContent = '';
        }
        function handleUnauthorized(message) {
            showNotice(message || i18n.loginError, true);
            resetSession(true);
            showDashboard(false);
        }

        function resetSession(focusLogin) {
            storeSession(null);
            state.session = null;
            state.bookings = [];
            state.confirmations = [];
            state.dietaryPending = [];
            state.filtered = [];
            state.financial = null;
            state.calendar = null;
            state.vendor = null;
            state.view = 'table';
            state.currency = 'EUR';

            updateViewButton();
            renderVendorProfile();
            renderConfirmations();
            renderDietary();
            renderFinancial();
            renderTable();
            renderCards();
            renderGoogle(null);

            if (elements.filterSearch) {
                elements.filterSearch.value = '';
            }
            if (elements.filterStatus) {
                elements.filterStatus.value = 'all';
            }
            if (elements.filterResource) {
                elements.filterResource.innerHTML = '';
                const option = document.createElement('option');
                option.value = 'all';
                option.textContent = i18n.filterResourceAll;
                elements.filterResource.appendChild(option);
            }
            if (elements.resultsCount) {
                elements.resultsCount.textContent = formatResultsCount(0);
            }
            ensurePlaceholder();

            if (focusLogin && elements.loginForm) {
                try {
                    elements.loginForm.reset();
                    const vendorInput = elements.loginForm.querySelector('input[name="vendor_id"]');
                    if (vendorInput) {
                        vendorInput.focus();
                    }
                } catch (error) {
                    // ignore focus errors
                }
            }
        }

        function ensurePlaceholder() {
            if (elements.scheduleTableBody && !elements.scheduleTableBody.childElementCount) {
                elements.scheduleTableBody.appendChild(createPlaceholderRow());
            }
            if (elements.cardsContainer && !elements.cardsContainer.childElementCount) {
                const empty = document.createElement('div');
                empty.className = 'sbdp-vendor-portal__card-empty';
                empty.textContent = i18n.noBookings;
                elements.cardsContainer.appendChild(empty);
            }
        }

        function createPlaceholderRow() {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 6;
            cell.textContent = i18n.noBookings;
            row.appendChild(cell);
            return row;
        }

        function createTableRow(booking) {
            const row = document.createElement('tr');

            [
                booking.date || '',
                booking.time || '',
                booking.customer || '',
                booking.participants || '',
                booking.resource || '',
                booking.status || ''
            ].forEach(function (value) {
                const cell = document.createElement('td');
                cell.textContent = String(value || '');
                row.appendChild(cell);
            });

            return row;
        }

        function createCard(booking) {
            const card = document.createElement('article');
            card.className = 'sbdp-vendor-portal__card';

            const header = document.createElement('header');
            const title = document.createElement('h4');
            title.textContent = formatCardTitle(booking);
            const badge = document.createElement('span');
            badge.className = 'sbdp-vendor-portal__badge';
            badge.textContent = String(booking.status || '');
            header.appendChild(title);
            header.appendChild(badge);
            card.appendChild(header);

            const list = document.createElement('ul');
            list.appendChild(createCardItem(i18n.cardDate, booking.date || ''));
            list.appendChild(createCardItem(i18n.cardTime, booking.time || ''));
            list.appendChild(createCardItem(i18n.cardParticipants, booking.participants || ''));
            list.appendChild(createCardItem(i18n.cardResource, booking.resource || ''));
            list.appendChild(createCardItem(i18n.cardTotal, formatCurrency(booking.total || 0, booking.currency || state.currency)));
            card.appendChild(list);

            return card;
        }

        function createConfirmationCard(confirmation) {
            const card = document.createElement('article');
            card.className = 'sbdp-vendor-portal__card sbdp-vendor-portal__confirmation-card';

            const header = document.createElement('header');
            const title = document.createElement('h4');
            title.textContent = String(confirmation.title || confirmation.booking_reference || '');
            const badge = document.createElement('span');
            badge.className = 'sbdp-vendor-portal__badge';
            badge.textContent = String(confirmation.status || '');
            header.appendChild(title);
            header.appendChild(badge);
            card.appendChild(header);

            const list = document.createElement('ul');
            list.appendChild(createCardItem(i18n.cardDate, confirmation.scheduled_date || ''));
            list.appendChild(createCardItem(i18n.cardTime, formatTimeRange(confirmation.scheduled_time, confirmation.scheduled_end_time)));
            list.appendChild(createCardItem(i18n.cardParticipants, confirmation.participants || ''));
            list.appendChild(createCardItem(i18n.confirmationCustomer, confirmation.customer_name || ''));
            list.appendChild(createCardItem(i18n.confirmationStatus, confirmation.status || ''));
            if (confirmation.partner_note) {
                list.appendChild(createCardItem('Notitie', confirmation.partner_note));
            }
            card.appendChild(list);

            if (['awaiting_partner', 'draft'].indexOf(String(confirmation.status || '')) !== -1) {
                const actions = document.createElement('div');
                actions.className = 'sbdp-vendor-portal__header-actions';
                actions.appendChild(createConfirmationButton(i18n.confirmAction, 'confirm', confirmation.leg_key));
                actions.appendChild(createConfirmationButton(i18n.declineAction, 'decline', confirmation.leg_key));
                actions.appendChild(createConfirmationButton(i18n.alternativeAction, 'alternative', confirmation.leg_key));
                card.appendChild(actions);
            }

            return card;
        }

        function createCardItem(label, value) {
            const item = document.createElement('li');
            const term = document.createElement('strong');
            term.textContent = label;
            const span = document.createElement('span');
            span.textContent = String(value || '');
            item.appendChild(term);
            item.appendChild(span);
            return item;
        }

        function createConfirmationButton(label, action, legKey) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'sbdp-vendor-portal__button sbdp-vendor-portal__button--ghost';
            button.textContent = label;
            button.setAttribute('data-sbdp-confirm-action', action);
            button.setAttribute('data-sbdp-leg-key', String(legKey || ''));
            return button;
        }

        function formatCardTitle(booking) {
            const date = booking && booking.date ? booking.date : '';
            const time = booking && booking.time ? booking.time : '';
            return (date && time) ? date + ' • ' + time : date || time;
        }

        function loadSession() {
            if (typeof window.sessionStorage === 'undefined') {
                return null;
            }
            try {
                const raw = window.sessionStorage.getItem('sbdpVendorPortalSession');
                return raw ? JSON.parse(raw) : null;
            } catch (error) {
                return null;
            }
        }

        function storeSession(value) {
            if (typeof window.sessionStorage === 'undefined') {
                return;
            }
            try {
                if (!value) {
                    window.sessionStorage.removeItem('sbdpVendorPortalSession');
                    return;
                }
                window.sessionStorage.setItem('sbdpVendorPortalSession', JSON.stringify(value));
            } catch (error) {
                // ignore storage errors
            }
        }

        function debounce(fn, wait) {
            let timeout = null;
            return function () {
                const context = this;
                const args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(function () {
                    fn.apply(context, args);
                }, wait);
            };
        }

        function extractMessage(input) {
            if (!input) {
                return '';
            }
            if (typeof input === 'string') {
                return input;
            }
            if (input instanceof Error && input.message) {
                return input.message;
            }
            if (input.message) {
                return String(input.message);
            }
            return '';
        }

        function formatVendorStatus(value) {
            if (!value) {
                return '';
            }

            const label = String(value).replace(/_/g, ' ').trim();
            if (label === '') {
                return '';
            }

            return label.charAt(0).toUpperCase() + label.slice(1);
        }

        function formatResultsCount(count) {
            if (count === 1) {
                return '1 ' + i18n.resultSingular;
            }
            return String(count) + ' ' + i18n.resultPlural;
        }

        function formatCurrency(amount, currency) {
            const value = Number(amount);
            if (!Number.isFinite(value)) {
                return '-';
            }

            const code = currency || state.currency || 'EUR';

            try {
                return new Intl.NumberFormat(undefined, {
                    style: 'currency',
                    currency: code,
                    minimumFractionDigits: 2
                }).format(value);
            } catch (error) {
                return value.toFixed(2) + ' ' + code;
            }
        }

        function formatTimeRange(start, end) {
            const left = String(start || '').trim();
            const right = String(end || '').trim();

            if (!left && !right) {
                return '';
            }

            if (left && right && left !== right) {
                return left + ' - ' + right;
            }

            return left || right;
        }

        function formatMonthRange(start, end) {
            try {
                const formatter = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short' });
                return formatter.format(start) + ' - ' + formatter.format(end);
            } catch (error) {
                return start.toISOString().slice(0, 10) + ' - ' + end.toISOString().slice(0, 10);
            }
        }

        function getCurrentMonthRange() {
            const now = new Date();
            const start = new Date(now.getFullYear(), now.getMonth(), 1);
            const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            return { start: start, end: end };
        }

        function formatDateTime(value) {
            if (value === null || value === undefined || value === '') {
                return '';
            }

            let date;
            if (value instanceof Date) {
                date = value;
            } else if (typeof value === 'number') {
                date = new Date(value < 1e12 ? value * 1000 : value);
            } else {
                date = new Date(String(value));
            }

            if (Number.isNaN(date.getTime())) {
                return String(value);
            }

            return date.toLocaleString();
        }

        function formatCsvValue(value) {
            const stringValue = value === null || value === undefined ? '' : String(value);
            if (/[",\r\n]/.test(stringValue)) {
                return '"' + stringValue.replace(/"/g, '""') + '"';
            }
            return stringValue;
        }

        function normaliseTimestamp(value) {
            const number = Number(value);
            if (!Number.isFinite(number) || number <= 0) {
                return 0;
            }
            return number < 1e12 ? number * 1000 : number;
        }
    }
})();
