(function () {
    const mapContainer = document.querySelector('[data-sbdp-map]');
    const vendorPanel = document.querySelector('[data-sbdp-vendor-panel]');
    const bookingList = document.querySelector('[data-sbdp-booking-list]');
    const filters = document.querySelector('[data-sbdp-filters]');

    if (!mapContainer || !window.L || !window.SBDP_GEODASHBOARD) {
        return;
    }

    const map = L.map(mapContainer).setView([52.370216, 4.895168], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    const vendorCluster = L.markerClusterGroup({ disableClusteringAtZoom: 9 });
    const bookingCluster = L.markerClusterGroup({ disableClusteringAtZoom: 11 });
    map.addLayer(vendorCluster);
    map.addLayer(bookingCluster);

    let geoData = { vendors: [], bookings: [] };

    const state = {
        vendorStatus: 'all',
        bookingStatus: 'all',
        radius: 50,
        startDate: '',
        endDate: '',
    };

    fetchData();

    if (filters) {
        filters.addEventListener('input', debounce(handleFilterChange, 200));
    }

    function handleFilterChange(event) {
        const target = event.target;
        const filterName = target.getAttribute('data-filter');

        if (!filterName) {
            return;
        }

        state[filterName] = target.value;
        fetchData();
    }

    function fetchData() {
        const params = new URLSearchParams();
        params.append('vendor_status', state.vendorStatus);
        params.append('booking_status', state.bookingStatus);
        params.append('radius', String(state.radius || 0));
        if (state.startDate) {
            params.append('start_date', state.startDate);
        }
        if (state.endDate) {
            params.append('end_date', state.endDate);
        }

        fetch(window.SBDP_GEODASHBOARD.restUrl + '?' + params.toString(), {
            headers: {
                'X-WP-Nonce': window.SBDP_GEODASHBOARD.nonce || '',
            },
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Failed to load GeoDashboard data');
                }
                return response.json();
            })
            .then(function (data) {
                geoData = data;
                renderVendors();
                renderBookings();
            })
            .catch(console.error);
    }

    function renderVendors() {
        vendorCluster.clearLayers();
        const bounds = [];

        if (!Array.isArray(geoData.vendors)) {
            return;
        }

        geoData.vendors.forEach(function (vendor) {
            if (!vendor.location || vendor.location.lat === null || vendor.location.lng === null) {
                return;
            }

            const marker = L.marker([vendor.location.lat, vendor.location.lng]);
            marker.bindPopup('<strong>' + escapeHtml(vendor.name) + '</strong><br/>' + escapeHtml(vendor.status || ''));
            marker.on('click', function () {
                displayVendorDetails(vendor);
            });
            vendorCluster.addLayer(marker);
            bounds.push([vendor.location.lat, vendor.location.lng]);
        });

        adjustMapBounds(bounds);
    }

    function renderBookings() {
        bookingCluster.clearLayers();
        if (bookingList) {
            bookingList.innerHTML = '';
        }

        if (!Array.isArray(geoData.bookings)) {
            return;
        }

        const bounds = [];

        geoData.bookings.forEach(function (booking) {
            if (!booking.location || booking.location.lat === null || booking.location.lng === null) {
                return;
            }

            const marker = L.circleMarker([booking.location.lat, booking.location.lng], {
                radius: 6,
                color: '#2563eb',
                fillColor: '#2563eb',
                fillOpacity: 0.65,
            });

            marker.bindPopup(
                '<strong>' + escapeHtml(booking.status || '') + '</strong><br/>' +
                escapeHtml(booking.customer || '') + '<br/>' +
                escapeHtml(booking.date || '') + ' ' + escapeHtml(booking.time || '')
            );

            bookingCluster.addLayer(marker);
            bounds.push([booking.location.lat, booking.location.lng]);

            if (bookingList) {
                const item = document.createElement('li');
                item.innerHTML = '<strong>' + escapeHtml(booking.customer || '') + '</strong><br/>' +
                    escapeHtml(booking.date || '') + ' ' + escapeHtml(booking.time || '') + '<br/>' +
                    escapeHtml(booking.status || '') + ' · ' + escapeHtml(formatCurrency(booking.total, booking.currency));
                bookingList.appendChild(item);
            }
        });

        if (bookingList && bookingList.children.length === 0) {
            const empty = document.createElement('li');
            empty.textContent = 'Geen boekingen binnen de filters.';
            bookingList.appendChild(empty);
        }

        adjustMapBounds(bounds);
    }

    function adjustMapBounds(points) {
        if (!points.length) {
            return;
        }

        const latLngBounds = L.latLngBounds(points);
        map.fitBounds(latLngBounds, { padding: [20, 20] });
    }

    function displayVendorDetails(vendor) {
        if (!vendorPanel) {
            return;
        }

        vendorPanel.innerHTML = '';

        const title = document.createElement('h3');
        title.textContent = vendor.name || 'Vendor';
        vendorPanel.appendChild(title);

        const status = document.createElement('p');
        status.textContent = 'Status: ' + (vendor.status || 'unknown');
        vendorPanel.appendChild(status);

        if (vendor.rating !== null && vendor.rating !== undefined) {
            const rating = document.createElement('p');
            rating.textContent = 'Rating: ' + vendor.rating;
            vendorPanel.appendChild(rating);
        }

        if (vendor.workload !== null && vendor.workload !== undefined) {
            const workload = document.createElement('p');
            workload.textContent = 'Workload: ' + vendor.workload;
            vendorPanel.appendChild(workload);
        }
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function formatCurrency(amount, currency) {
        const numeric = typeof amount === 'number' ? amount : parseFloat(amount || '0');
        const curr = currency || 'EUR';
        try {
            return new Intl.NumberFormat(undefined, { style: 'currency', currency: curr }).format(numeric);
        } catch (error) {
            return numeric.toFixed(2) + ' ' + curr;
        }
    }

    function debounce(fn, wait) {
        let timeout;
        return function () {
            clearTimeout(timeout);
            const args = arguments;
            timeout = setTimeout(function () {
                fn.apply(null, args);
            }, wait);
        };
    }
})();
