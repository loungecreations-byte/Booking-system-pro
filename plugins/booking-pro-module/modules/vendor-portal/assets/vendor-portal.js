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
            actionOpenCount: root.querySelector('[data-sbdp-action-open-count]'),
            actionUrgentCount: root.querySelector('[data-sbdp-action-urgent-count]'),
            actionEmpty: root.querySelector('[data-sbdp-action-empty]'),
            actionRefresh: root.querySelector('[data-sbdp-action-refresh]'),
            actionDialog: root.querySelector('[data-sbdp-action-dialog]'),
            actionDialogForm: root.querySelector('[data-sbdp-action-dialog-form]'),
            actionDialogType: root.querySelector('[data-sbdp-action-dialog-type]'),
            actionDialogTitle: root.querySelector('[data-sbdp-action-dialog-title]'),
            actionDialogCopy: root.querySelector('[data-sbdp-action-dialog-copy]'),
            actionDialogNote: root.querySelector('[data-sbdp-action-dialog-note]'),
            actionDialogError: root.querySelector('[data-sbdp-action-dialog-error]'),
            actionDialogSubmit: root.querySelector('[data-sbdp-action-dialog-submit]'),
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
            kpiYtdRevenue: root.querySelector('[data-sbdp-kpi-ytd-revenue]'),
            kpiMonthRevenue: root.querySelector('[data-sbdp-kpi-month-revenue]'),
            kpiYtdYear: root.querySelector('[data-sbdp-kpi-ytd-year]'),
            kpiMonthRange: root.querySelector('[data-sbdp-kpi-month-range]'),
            kpiPending: root.querySelector('[data-sbdp-kpi-pending]'),
            kpiAverageSize: root.querySelector('[data-sbdp-kpi-average-size]'),
            chartSvg: root.querySelector('[data-sbdp-chart]'),
            chartEmpty: root.querySelector('[data-sbdp-chart-empty]'),
            recentCards: root.querySelector('[data-sbdp-recent-cards]'),
            actionsCount: root.querySelector('[data-sbdp-actions-count]'),
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
            noBookings: 'Nog geen boekingen voor deze partner. Nieuwe bevestigingen en boekingen verschijnen hier zodra ze aan deze vendor gekoppeld zijn.',
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
            actionConfirmationType: 'Partnerbevestiging',
            actionNeedsResponse: 'Reactie nodig',
            actionWithin48Hours: 'Binnen 48 uur',
            actionConfirmCopy: 'Bevestig dat deze activiteit op het voorgestelde moment kan doorgaan.',
            actionNoteRequired: 'Een toelichting is verplicht.',
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
            view: window.innerWidth < 768 ? 'cards' : 'table',
            activeTab: 'overview',
            currency: 'EUR',
            dietaryPending: [],
            actionFilter: 'all',
            pendingConfirmationAction: null
        };

        initialise();

        function initialise() {
            hideError();
            clearNotice();
            ensurePlaceholder();
            registerEvents();
            initTabs();
            if (elements.scheduleSection) {
                elements.scheduleSection.dataset.sbdpView = state.view;
            }
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

            root.querySelectorAll('[data-sbdp-action-filter]').forEach(function (button) {
                button.addEventListener('click', function () {
                    setActionFilter(button.getAttribute('data-sbdp-action-filter'));
                });
            });

            if (elements.actionRefresh) {
                elements.actionRefresh.addEventListener('click', function () {
                    refreshDashboard().catch(function () {});
                });
            }

            if (elements.actionDialogForm) {
                elements.actionDialogForm.addEventListener('submit', handleActionDialogSubmit);
            }
            root.querySelectorAll('[data-sbdp-action-dialog-close], [data-sbdp-action-dialog-cancel]').forEach(function (button) {
                button.addEventListener('click', closeActionDialog);
            });

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

            // Tab-goto buttons (e.g. "Alle boekingen →")
            root.addEventListener('click', function (event) {
                const btn = event.target && event.target.closest ? event.target.closest('[data-sbdp-tab-goto]') : null;
                if (!btn) { return; }
                const target = btn.getAttribute('data-sbdp-tab-goto');
                if (target) { switchTab(target); }
            });
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
            const rememberMe = !!formData.get('remember_me');

            if (!vendorId || !accessKey) {
                showError(i18n.loginError);
                return;
            }

            setLoading(true);

            api('/login', {
                method: 'POST',
                body: {
                    vendor_id: vendorId,
                    access_key: accessKey,
                    remember_me: rememberMe
                },
                includeToken: false
            })
                .then(function (response) {
                    state.session = {
                        vendor_id: response.vendor_id,
                        expires_in: response.expires_in,
                        remember_me: rememberMe,
                        stored_at: Date.now()
                    };
                    storeSession(state.session, rememberMe);
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
                body: {}
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

            if (!state.session) {
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
            if (!state.session) {
                return Promise.resolve();
            }

            setGoogleLoading(true);

            return api('/google-status')
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
            if (!state.session) {
                return Promise.resolve();
            }

            setGoogleLoading(true);

            return api('/google-sync', {
                method: 'POST',
                body: {}
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
            renderRecentCards();
            updateActionsCount();
            applyActionFilter();
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

            const confirmations = Array.isArray(state.confirmations)
                ? state.confirmations.filter(function (item) { return isConfirmationActionable(item.status); }).sort(compareActionDate)
                : [];

            if (!confirmations.length) {
                elements.confirmationsSection.hidden = true;
                if (elements.confirmationsCount) {
                    elements.confirmationsCount.textContent = '0';
                }
                return;
            }

            const fragment = document.createDocumentFragment();
            confirmations.forEach(function (confirmation) {
                fragment.appendChild(createConfirmationCard(confirmation));
            });
            elements.confirmationsList.appendChild(fragment);
            elements.confirmationsSection.hidden = false;

            if (elements.confirmationsCount) {
                elements.confirmationsCount.textContent = String(confirmations.length);
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
            card.className = 'sbdp-vendor-portal__card sbdp-vendor-portal__dietary-card sbdp-vp-action-card';

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
            actions.className = 'sbdp-vp-action-card__actions';

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
            if (!legKey || !action || !state.session) {
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
                const total = financial && typeof financial.ytd_bookings === 'number' ? financial.ytd_bookings : 0;
                elements.kpiTotalBookings.textContent = String(total);
            }

            // YTD revenue
            if (elements.kpiYtdRevenue) {
                const ytd = financial && typeof financial.ytd_revenue === 'number' ? financial.ytd_revenue : (financial && typeof financial.paid_revenue === 'number' ? financial.paid_revenue : 0);
                elements.kpiYtdRevenue.textContent = formatCurrency(ytd, currency);
            }

            if (elements.kpiYtdYear) {
                const yr = financial && financial.ytd_year ? String(financial.ytd_year) : String(new Date().getFullYear());
                elements.kpiYtdYear.textContent = yr;
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
                    return ms >= now && !isTerminalBookingStatus(booking.status);
                });

                const participantCounts = upcoming.map(function (booking) {
                    return canonicalParticipants(booking.participants);
                }).filter(function (participants) { return participants !== null; });
                const totalParticipants = participantCounts.reduce(function (total, participants) {
                    return total + participants;
                }, 0);

                const average = participantCounts.length ? (totalParticipants / participantCounts.length) : 0;
                elements.kpiAverageSize.textContent = average ? average.toFixed(1) : '-';
            }

            // Render revenue chart
            const monthly = financial && Array.isArray(financial.monthly_breakdown) ? financial.monthly_breakdown : [];
            renderRevenueChart(monthly, currency);
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
            if (!legKey || !action || !state.session) {
                return;
            }

            const confirmation = state.confirmations.find(function (item) {
                return String(item.leg_key || '') === legKey;
            }) || {};

            if (action === 'alternative' || action === 'decline') {
                openActionDialog(action, confirmation);
                return;
            }

            submitConfirmationAction(legKey, action, '', button);
        }

        function submitConfirmationAction(legKey, action, note, trigger) {
            if (!state.session || !legKey || !action) { return Promise.resolve(); }
            if (trigger) {
                trigger.disabled = true;
                trigger.setAttribute('aria-busy', 'true');
            }

            return api('/confirmations/respond', {
                method: 'POST',
                body: {
                    leg_key: legKey,
                    action: action,
                    note: note
                }
            })
                .then(function () {
                    showNotice(i18n.confirmationResponded);
                    closeActionDialog();
                    return refreshDashboard({ silent: true, manageLoading: false });
                })
                .catch(function (error) {
                    if (error && error.status === 403) {
                        handleUnauthorized(error.message);
                        return;
                    }
                    const message = error && error.message ? error.message : i18n.networkError;
                    if (elements.actionDialog && elements.actionDialog.open) {
                        elements.actionDialogError.textContent = message;
                        elements.actionDialogError.hidden = false;
                    } else {
                        showNotice(message, true);
                    }
                })
                .finally(function () {
                    if (trigger) {
                        trigger.disabled = false;
                        trigger.removeAttribute('aria-busy');
                    }
                });
        }

        function openActionDialog(action, confirmation) {
            if (!elements.actionDialog) { return; }
            state.pendingConfirmationAction = {
                action: action,
                legKey: String(confirmation.leg_key || '')
            };
            elements.actionDialogType.textContent = i18n.actionConfirmationType;
            elements.actionDialogTitle.textContent = String(confirmation.title || confirmation.booking_reference || i18n.actionNeedsResponse);
            elements.actionDialogCopy.textContent = action === 'decline'
                ? i18n.confirmationDeclinePrompt
                : i18n.confirmationAlternativePrompt;
            elements.actionDialogSubmit.textContent = action === 'decline' ? i18n.declineAction : i18n.alternativeAction;
            elements.actionDialogSubmit.classList.toggle('sbdp-vendor-portal__button--danger', action === 'decline');
            elements.actionDialogNote.value = '';
            elements.actionDialogError.hidden = true;
            if (typeof elements.actionDialog.showModal === 'function') { elements.actionDialog.showModal(); }
            else { elements.actionDialog.setAttribute('open', ''); }
            elements.actionDialogNote.focus();
        }

        function closeActionDialog() {
            state.pendingConfirmationAction = null;
            if (!elements.actionDialog) { return; }
            if (typeof elements.actionDialog.close === 'function' && elements.actionDialog.open) { elements.actionDialog.close(); }
            else { elements.actionDialog.removeAttribute('open'); }
        }

        function handleActionDialogSubmit(event) {
            event.preventDefault();
            const pending = state.pendingConfirmationAction;
            const note = String(elements.actionDialogNote.value || '').trim();
            if (!pending || !note) {
                elements.actionDialogError.textContent = i18n.actionNoteRequired;
                elements.actionDialogError.hidden = false;
                return;
            }
            elements.actionDialogError.hidden = true;
            submitConfirmationAction(pending.legKey, pending.action, note, elements.actionDialogSubmit);
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
                    canonicalParticipants(booking.participants),
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
            return path;
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
            storeSession(null, false);
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
                canonicalParticipants(booking.participants),
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
            list.appendChild(createCardItem(i18n.cardParticipants, canonicalParticipants(booking.participants)));
            list.appendChild(createCardItem(i18n.cardResource, booking.resource || ''));
            list.appendChild(createCardItem(i18n.cardTotal, formatCurrency(booking.total || 0, booking.currency || state.currency)));
            card.appendChild(list);

            return card;
        }

        function createConfirmationCard(confirmation) {
            const card = document.createElement('article');
            card.className = 'sbdp-vendor-portal__card sbdp-vendor-portal__confirmation-card sbdp-vp-action-card';

            const header = document.createElement('header');
            const heading = document.createElement('div');
            heading.className = 'sbdp-vp-action-card__heading';
            const eyebrow = document.createElement('span');
            eyebrow.className = 'sbdp-vp-action-card__eyebrow';
            eyebrow.textContent = i18n.actionConfirmationType;
            const title = document.createElement('h4');
            title.textContent = String(confirmation.title || confirmation.booking_reference || i18n.actionNeedsResponse);
            const badge = document.createElement('span');
            badge.className = 'sbdp-vendor-portal__badge sbdp-vp-action-card__status';
            badge.textContent = i18n.actionNeedsResponse;
            heading.appendChild(eyebrow);
            heading.appendChild(title);
            header.appendChild(heading);
            header.appendChild(badge);
            card.appendChild(header);

            const meta = document.createElement('p');
            meta.className = 'sbdp-vp-action-card__meta';
            meta.textContent = [
                confirmation.scheduled_date || '',
                formatTimeRange(confirmation.scheduled_time, confirmation.scheduled_end_time),
                confirmation.participants ? String(confirmation.participants) + ' ' + i18n.cardParticipants.toLowerCase() : '',
                confirmation.booking_reference || ''
            ].filter(Boolean).join(' · ');
            card.appendChild(meta);

            const urgency = getActionUrgency(confirmation);
            if (urgency) {
                const urgencyEl = document.createElement('span');
                urgencyEl.className = 'sbdp-vp-action-card__urgency';
                urgencyEl.textContent = urgency;
                card.appendChild(urgencyEl);
            }

            const list = document.createElement('dl');
            if (confirmation.customer_name) {
                list.appendChild(createActionDetail(i18n.confirmationCustomer, confirmation.customer_name));
            }
            if (confirmation.partner_note) {
                list.appendChild(createActionDetail('Notitie', confirmation.partner_note));
            }
            card.appendChild(list);

            if (isConfirmationActionable(confirmation.status)) {
                const actions = document.createElement('div');
                actions.className = 'sbdp-vp-action-card__actions';
                actions.appendChild(createConfirmationButton(i18n.confirmAction, 'confirm', confirmation.leg_key));
                actions.appendChild(createConfirmationButton(i18n.alternativeAction, 'alternative', confirmation.leg_key));
                actions.appendChild(createConfirmationButton(i18n.declineAction, 'decline', confirmation.leg_key));
                card.appendChild(actions);
            }

            return card;
        }

        function isConfirmationActionable(status) {
            return [
                'awaiting_partner',
                'draft',
                'supplier_confirmation_required',
                'supplier_option_requested',
                'supplier_option_held'
            ].indexOf(String(status || '')) !== -1;
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
            button.className = 'sbdp-vendor-portal__button';
            if (action === 'alternative') { button.classList.add('sbdp-vendor-portal__button--ghost'); }
            if (action === 'decline') { button.classList.add('sbdp-vp-action-card__decline'); }
            button.textContent = label;
            button.setAttribute('data-sbdp-confirm-action', action);
            button.setAttribute('data-sbdp-leg-key', String(legKey || ''));
            return button;
        }

        function createActionDetail(label, value) {
            const wrapper = document.createElement('div');
            const term = document.createElement('dt');
            const description = document.createElement('dd');
            term.textContent = label;
            description.textContent = String(value || '');
            wrapper.appendChild(term);
            wrapper.appendChild(description);
            return wrapper;
        }

        function compareActionDate(left, right) {
            return actionTimestamp(left) - actionTimestamp(right);
        }

        function actionTimestamp(item) {
            const value = String(item && item.scheduled_date || '').trim();
            const timestamp = value ? new Date(value + 'T00:00:00').getTime() : Number.MAX_SAFE_INTEGER;
            return Number.isFinite(timestamp) ? timestamp : Number.MAX_SAFE_INTEGER;
        }

        function getActionUrgency(item) {
            const timestamp = actionTimestamp(item);
            if (!Number.isFinite(timestamp) || timestamp === Number.MAX_SAFE_INTEGER) { return ''; }
            const difference = timestamp - Date.now();
            if (difference < 0) { return 'Datum verstreken'; }
            if (difference <= 48 * 60 * 60 * 1000) { return i18n.actionWithin48Hours; }
            return '';
        }

        function formatCardTitle(booking) {
            const date = booking && booking.date ? booking.date : '';
            const time = booking && booking.time ? booking.time : '';
            return (date && time) ? date + ' • ' + time : date || time;
        }

        function loadSession() {
            const key = 'sbdpVendorPortalSession';
            try {
                // Try localStorage first (remember me), then sessionStorage
                const lsRaw = typeof window.localStorage !== 'undefined' ? window.localStorage.getItem(key) : null;
                if (lsRaw) {
                    const parsed = JSON.parse(lsRaw);
                    // Validate stored_at + expires_in still valid
                    if (parsed && parsed.stored_at && parsed.expires_in) {
                        const expiresAt = parsed.stored_at + (parsed.expires_in * 1000);
                        if (Date.now() < expiresAt) { return parsed; }
                        window.localStorage.removeItem(key);
                    }
                }
            } catch (e) { /* ignore */ }
            try {
                const ssRaw = typeof window.sessionStorage !== 'undefined' ? window.sessionStorage.getItem(key) : null;
                return ssRaw ? JSON.parse(ssRaw) : null;
            } catch (e) { return null; }
        }

        function storeSession(value, rememberMe) {
            const key = 'sbdpVendorPortalSession';
            try {
                if (!value) {
                    if (typeof window.localStorage !== 'undefined') { window.localStorage.removeItem(key); }
                    if (typeof window.sessionStorage !== 'undefined') { window.sessionStorage.removeItem(key); }
                    return;
                }
                const persistent = rememberMe === undefined ? !!value.remember_me : !!rememberMe;
                const serialized = JSON.stringify({
                    vendor_id: value.vendor_id,
                    expires_in: value.expires_in,
                    remember_me: persistent,
                    stored_at: value.stored_at
                });
                if (persistent && typeof window.localStorage !== 'undefined') {
                    window.localStorage.setItem(key, serialized);
                    if (typeof window.sessionStorage !== 'undefined') { window.sessionStorage.removeItem(key); }
                } else if (typeof window.sessionStorage !== 'undefined') {
                    window.sessionStorage.setItem(key, serialized);
                    if (typeof window.localStorage !== 'undefined') { window.localStorage.removeItem(key); }
                }
            } catch (e) { /* ignore storage errors */ }
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

        // --- TAB SYSTEM ---
        function initTabs() {
            const tabs = Array.from(root.querySelectorAll('[data-sbdp-tab]'));
            tabs.forEach(function (tab, index) {
                tab.addEventListener('click', function () {
                    switchTab(tab.getAttribute('data-sbdp-tab'), true);
                });
                tab.addEventListener('keydown', function (event) {
                    let nextIndex = index;
                    if (event.key === 'ArrowRight') { nextIndex = (index + 1) % tabs.length; }
                    else if (event.key === 'ArrowLeft') { nextIndex = (index - 1 + tabs.length) % tabs.length; }
                    else if (event.key === 'Home') { nextIndex = 0; }
                    else if (event.key === 'End') { nextIndex = tabs.length - 1; }
                    else { return; }

                    event.preventDefault();
                    switchTab(tabs[nextIndex].getAttribute('data-sbdp-tab'), true);
                });
            });
        }

        function isTerminalBookingStatus(status) {
            return ['cancelled', 'refunded', 'failed'].indexOf(String(status || '').toLowerCase()) !== -1;
        }

        function canonicalParticipants(value) {
            const participants = typeof value === 'number' ? value : Number.parseInt(String(value), 10);
            return Number.isInteger(participants) && participants > 0 ? participants : null;
        }

        function switchTab(tabId, moveFocus) {
            if (!tabId) { return; }
            state.activeTab = tabId;

            root.querySelectorAll('[data-sbdp-tab]').forEach(function (tab) {
                const active = tab.getAttribute('data-sbdp-tab') === tabId;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.setAttribute('tabindex', active ? '0' : '-1');
                if (active && moveFocus) { tab.focus(); }
            });

            root.querySelectorAll('[data-sbdp-panel]').forEach(function (panel) {
                const active = panel.getAttribute('data-sbdp-panel') === tabId;
                panel.hidden = !active;
                panel.classList.toggle('is-active', active);
            });
        }

        function updateActionsCount() {
            const confirmations = (state.confirmations || []).filter(function (item) {
                return isConfirmationActionable(item.status);
            });
            const total = confirmations.length + (state.dietaryPending ? state.dietaryPending.length : 0);
            const urgent = confirmations.filter(function (item) { return !!getActionUrgency(item); }).length;
            if (elements.actionsCount) {
                elements.actionsCount.textContent = String(total);
                elements.actionsCount.hidden = total === 0;
            }
            if (elements.actionOpenCount) { elements.actionOpenCount.textContent = String(total); }
            if (elements.actionUrgentCount) { elements.actionUrgentCount.textContent = String(urgent); }
        }

        function setActionFilter(filter) {
            state.actionFilter = ['all', 'confirmations', 'dietary'].indexOf(filter) !== -1 ? filter : 'all';
            root.querySelectorAll('[data-sbdp-action-filter]').forEach(function (button) {
                const active = button.getAttribute('data-sbdp-action-filter') === state.actionFilter;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            applyActionFilter();
        }

        function applyActionFilter() {
            const confirmationCount = (state.confirmations || []).filter(function (item) {
                return isConfirmationActionable(item.status);
            }).length;
            const dietaryCount = (state.dietaryPending || []).length;
            const showConfirmations = state.actionFilter !== 'dietary' && confirmationCount > 0;
            const showDietary = state.actionFilter !== 'confirmations' && dietaryCount > 0;

            if (elements.confirmationsSection) { elements.confirmationsSection.hidden = !showConfirmations; }
            if (elements.dietarySection) { elements.dietarySection.hidden = !showDietary; }
            if (elements.actionEmpty) { elements.actionEmpty.hidden = showConfirmations || showDietary; }
        }

        // --- REVENUE CHART (pure SVG, no dependencies) ---
        function renderRevenueChart(monthly, currency) {
            if (!elements.chartSvg) { return; }

            if (!monthly || !monthly.length) {
                elements.chartSvg.innerHTML = '';
                if (elements.chartEmpty) { elements.chartEmpty.hidden = false; }
                return;
            }

            if (elements.chartEmpty) { elements.chartEmpty.hidden = true; }

            const W = 600, H = 220, padL = 60, padR = 16, padT = 16, padB = 40;
            const chartW = W - padL - padR;
            const chartH = H - padT - padB;
            const maxVal = Math.max.apply(null, monthly.map(function (d) { return d.revenue; })) || 1;
            const barW = Math.floor(chartW / monthly.length) - 4;
            const ns = 'http://www.w3.org/2000/svg';

            elements.chartSvg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
            elements.chartSvg.setAttribute('width', '100%');
            elements.chartSvg.setAttribute('height', String(H));
            elements.chartSvg.innerHTML = '';

            // Y-axis gridlines + labels
            const steps = 4;
            for (let i = 0; i <= steps; i++) {
                const y = padT + chartH - (i / steps) * chartH;
                const val = (maxVal * i / steps);
                const line = document.createElementNS(ns, 'line');
                line.setAttribute('class', 'sbdp-vp-chart__gridline');
                line.setAttribute('x1', String(padL)); line.setAttribute('x2', String(W - padR));
                line.setAttribute('y1', String(y)); line.setAttribute('y2', String(y));
                line.setAttribute('stroke-width', '1');
                elements.chartSvg.appendChild(line);
                const label = document.createElementNS(ns, 'text');
                label.setAttribute('class', 'sbdp-vp-chart__label');
                label.setAttribute('x', String(padL - 8)); label.setAttribute('y', String(y + 4));
                label.setAttribute('text-anchor', 'end'); label.setAttribute('font-size', '10');
                label.textContent = val >= 1000 ? ('€' + Math.round(val / 1000) + 'k') : ('€' + Math.round(val));
                elements.chartSvg.appendChild(label);
            }

            // Bars
            monthly.forEach(function (d, idx) {
                const barH = Math.max(2, (d.revenue / maxVal) * chartH);
                const x = padL + idx * (chartW / monthly.length) + 2;
                const y = padT + chartH - barH;
                const rect = document.createElementNS(ns, 'rect');
                rect.setAttribute('class', 'sbdp-vp-chart__bar');
                rect.setAttribute('x', String(x)); rect.setAttribute('y', String(y));
                rect.setAttribute('width', String(barW)); rect.setAttribute('height', String(barH));
                rect.setAttribute('rx', '3');
                rect.setAttribute('opacity', '0.85');
                const title = document.createElementNS(ns, 'title');
                title.textContent = d.label + ': ' + formatCurrency(d.revenue, currency) + ' (' + d.count + ' boeking' + (d.count !== 1 ? 'en' : '') + ')';
                rect.appendChild(title);
                elements.chartSvg.appendChild(rect);

                // X-axis month label
                const xLabel = document.createElementNS(ns, 'text');
                xLabel.setAttribute('class', 'sbdp-vp-chart__label');
                xLabel.setAttribute('x', String(x + barW / 2));
                xLabel.setAttribute('y', String(H - padB + 14));
                xLabel.setAttribute('text-anchor', 'middle'); xLabel.setAttribute('font-size', '9');
                xLabel.textContent = d.label;
                elements.chartSvg.appendChild(xLabel);
            });
        }

        // --- RECENT CARDS (top 5 upcoming on overview tab) ---
        function renderRecentCards() {
            if (!elements.recentCards) { return; }
            elements.recentCards.innerHTML = '';
            const now = Date.now();
            const upcoming = state.bookings
                .filter(function (b) {
                    return normaliseTimestamp(b.timestamp) >= now && !isTerminalBookingStatus(b.status);
                })
                .slice(0, 5);

            if (!upcoming.length) {
                const empty = document.createElement('p');
                empty.className = 'sbdp-vp-empty';
                empty.textContent = i18n.noBookings;
                elements.recentCards.appendChild(empty);
                return;
            }

            const frag = document.createDocumentFragment();
            upcoming.forEach(function (booking) { frag.appendChild(createCard(booking)); });
            elements.recentCards.appendChild(frag);
        }
    }
})();
