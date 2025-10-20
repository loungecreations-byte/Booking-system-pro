(function () {
    const portal = document.querySelector('[data-sbdp-vendor-portal]');
    if (!portal) {
        return;
    }

    const loginForm = portal.querySelector('#sbdp-vendor-portal-login');
    const dashboard = portal.querySelector('.sbdp-vendor-portal__dashboard');
    const errorBox = portal.querySelector('.sbdp-vendor-portal__error');
    const logoutButton = portal.querySelector('.sbdp-vendor-portal__logout');
    const scheduleTable = portal.querySelector('[data-sbdp-schedule] tbody');
    const placeholderRow = portal.querySelector('[data-sbdp-placeholder]');
    const totalRevenue = portal.querySelector('[data-sbdp-total-revenue]');
    const paidRevenue = portal.querySelector('[data-sbdp-paid-revenue]');
    const pendingRevenue = portal.querySelector('[data-sbdp-pending-revenue]');

    const restBase = (window.SBDP_VENDOR_PORTAL && window.SBDP_VENDOR_PORTAL.restUrl)
        ? String(window.SBDP_VENDOR_PORTAL.restUrl).replace(/\/$/, '')
        : '/wp-json/bsp/v1/vendor-portal';

    let session = loadSession();

    if (session) {
        showDashboard(true);
        refreshDashboard();
    }

    if (loginForm) {
        loginForm.addEventListener('submit', function (event) {
            event.preventDefault();
            hideError();

            const formData = new FormData(loginForm);
            const payload = {
                vendor_id: parseInt(formData.get('vendor_id'), 10) || 0,
                access_key: String(formData.get('access_key') || ''),
            };

            fetch(restBase + '/login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            })
                .then(assertResponse)
                .then(function (json) {
                    session = {
                        token: json.token,
                        vendor_id: json.vendor_id,
                        expires_in: json.expires_in,
                        stored_at: Date.now(),
                    };
                    storeSession(session);
                    showDashboard(true);
                    refreshDashboard();
                })
                .catch(showError);
        });
    }

    if (logoutButton) {
        logoutButton.addEventListener('click', function () {
            if (!session) {
                return;
            }

            fetch(restBase + '/logout', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ token: session.token }),
            }).finally(function () {
                session = null;
                storeSession(null);
                showDashboard(false);
                if (loginForm) {
                    loginForm.reset();
                }
            });
        });
    }

    function refreshDashboard() {
        if (!session) {
            return;
        }

        const url = restBase + '/dashboard?token=' + encodeURIComponent(session.token);

        fetch(url, {
            headers: {
                'Accept': 'application/json',
            },
        })
            .then(assertResponse)
            .then(renderDashboard)
            .catch(function (error) {
                showError(error);
            });
    }

    function renderDashboard(response) {
        if (!response || !response.dashboard) {
            return;
        }

        const dashboardData = response.dashboard;
        const financial = dashboardData.financial || {};
        const upcoming = dashboardData.upcoming || [];

        if (totalRevenue) {
            totalRevenue.textContent = formatCurrency(financial.total_revenue, financial.currency);
        }
        if (paidRevenue) {
            paidRevenue.textContent = formatCurrency(financial.paid_revenue, financial.currency);
        }
        if (pendingRevenue) {
            pendingRevenue.textContent = formatCurrency(financial.pending_revenue, financial.currency);
        }

        if (scheduleTable) {
            scheduleTable.innerHTML = '';

            if (!upcoming.length && placeholderRow) {
                scheduleTable.appendChild(placeholderRow.cloneNode(true));
            } else {
                upcoming.forEach(function (booking) {
                    const row = document.createElement('tr');

                    row.innerHTML = [
                        escapeHtml(booking.date || ''),
                        escapeHtml(booking.time || ''),
                        escapeHtml(booking.customer || ''),
                        String(booking.participants || ''),
                        escapeHtml(booking.resource || ''),
                        escapeHtml(booking.status || ''),
                    ]
                        .map(function (cell) {
                            return '<td>' + cell + '</td>';
                        })
                        .join('');

                    scheduleTable.appendChild(row);
                });
            }
        }
    }

    function assertResponse(response) {
        if (!response.ok) {
            return response.json().then(function (payload) {
                const message = (payload && payload.message) || response.statusText;
                throw new Error(message || 'Request failed');
            });
        }

        return response.json();
    }

    function showDashboard(visible) {
        if (!dashboard || !loginForm) {
            return;
        }

        dashboard.hidden = !visible;
        loginForm.hidden = visible;
    }

    function showError(error) {
        if (!errorBox) {
            return;
        }

        const message = error instanceof Error ? error.message : String(error);
        errorBox.textContent = message || (window.SBDP_VENDOR_PORTAL && window.SBDP_VENDOR_PORTAL.i18n && window.SBDP_VENDOR_PORTAL.i18n.networkError) || 'Er is een fout opgetreden.';
        errorBox.hidden = false;
    }

    function hideError() {
        if (errorBox) {
            errorBox.hidden = true;
            errorBox.textContent = '';
        }
    }

    function formatCurrency(amount, currency) {
        const value = typeof amount === 'number' ? amount : parseFloat(amount || '0');
        const curr = currency || 'EUR';

        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: curr,
            }).format(value);
        } catch (error) {
            return value.toFixed(2) + ' ' + curr;
        }
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }

    function storeSession(value) {
        if (typeof window.sessionStorage === 'undefined') {
            return;
        }

        if (!value) {
            sessionStorage.removeItem('sbdpVendorPortalSession');
            return;
        }

        sessionStorage.setItem('sbdpVendorPortalSession', JSON.stringify(value));
    }

    function loadSession() {
        if (typeof window.sessionStorage === 'undefined') {
            return null;
        }

        const raw = sessionStorage.getItem('sbdpVendorPortalSession');
        if (!raw) {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch (error) {
            return null;
        }
    }
})();
