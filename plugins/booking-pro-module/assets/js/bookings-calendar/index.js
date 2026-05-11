/* global wp */
(function (window, document) {
    'use strict';

    if (!window.Vue) {
        console.warn('Vue runtime is required for the bookings calendar module.');
        return;
    }

    var mountEl = document.getElementById('sbdp-bookings-calendar-app');
    if (!mountEl) {
        return;
    }

    var restEndpoint = (window.sbdpBookingsCalendar && window.sbdpBookingsCalendar.restEndpoint) || '';
    var restNonce = (window.sbdpBookingsCalendar && window.sbdpBookingsCalendar.nonce) || '';

    var createApp = window.Vue.createApp;
    var reactive = window.Vue.reactive;
    var computed = window.Vue.computed;
    var onMounted = window.Vue.onMounted;
    var onBeforeUnmount = window.Vue.onBeforeUnmount;
    var ref = window.Vue.ref;
    var watch = window.Vue.watch;

    var state = reactive({
        loading: false,
        events: [],
        capacity: [],
        filters: {
            status: [],
            products: [],
            date_range: { from: '', to: '' },
            search_email: ''
        },
        criteria: {
            status: [],
            product_id: [],
            date_from: '',
            date_to: '',
            search: ''
        },
        alerts: []
    });

    function buildQuery(params) {
        return Object.keys(params)
            .filter(function (key) {
                var value = params[key];
                if (Array.isArray(value)) {
                    return value.length > 0;
                }

                return value !== '' && value !== null && value !== undefined;
            })
            .map(function (key) {
                var value = params[key];
                if (Array.isArray(value)) {
                    return value
                        .map(function (entry) {
                            return encodeURIComponent(key + '[]') + '=' + encodeURIComponent(entry);
                        })
                        .join('&');
                }

                return encodeURIComponent(key) + '=' + encodeURIComponent(value);
            })
            .filter(Boolean)
            .join('&');
    }

    function fetchCalendarData() {
        if (!restEndpoint) {
            return;
        }

        state.loading = true;

        var url = restEndpoint;
        var query = buildQuery(state.criteria);
        if (query) {
            url += '?' + query;
        }

        var headers = {};
        if (restNonce) {
            headers['X-WP-Nonce'] = restNonce;
        }

        window.fetch(url, {
            credentials: 'same-origin',
            headers: headers
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Failed to load calendar data');
                }
                return response.json();
            })
            .then(function (payload) {
                state.events = payload.events || [];
                state.capacity = payload.capacity || [];
                state.filters = payload.filters || state.filters;
            })
            .catch(function (error) {
                console.error(error);
            })
            .finally(function () {
                state.loading = false;
            });
    }

    function fetchConflictAlerts() {
        if (!restEndpoint) {
            return;
        }

        var url = restEndpoint.replace('/calendar', '/calendar/conflicts');
        var query = buildQuery(state.criteria);
        if (query) {
            url += '?' + query;
        }

        var headers = {};
        if (restNonce) {
            headers['X-WP-Nonce'] = restNonce;
        }

        window.fetch(url, {
            credentials: 'same-origin',
            headers: headers
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Failed to load conflicts');
                }
                return response.json();
            })
            .then(function (payload) {
                state.alerts = payload.alerts || [];
            })
            .catch(function (error) {
                console.error(error);
            });
    }

    var CalendarBoard = {
        name: 'CalendarBoard',
        emits: ['select'],
        props: {
            events: { type: Array, default: function () { return []; } },
            loading: { type: Boolean, default: false },
            conflictIds: { type: Array, default: function () { return []; } }
        },
        setup: function (props, context) {
            var calendarEl = ref(null);
            var calendarRef = null;

            function hasConflict(eventId) {
                if (!Array.isArray(props.conflictIds)) {
                    return false;
                }

                return props.conflictIds.indexOf(eventId) !== -1;
            }

            function mapEvent(event) {
                return {
                    id: event.id,
                    title: event.title,
                    start: event.start,
                    end: event.end,
                    allDay: event.end === null,
                    className: [
                        'sbdp-calendar-event',
                        'sbdp-calendar-event--' + (event.status || 'unknown'),
                        hasConflict(event.id) ? 'sbdp-calendar-event--conflict' : ''
                    ],
                    extendedProps: {
                        original: event
                    }
                };
            }

            function renderEvents() {
                if (!calendarRef) {
                    return;
                }

                calendarRef.removeAllEvents();

                var rows = Array.isArray(props.events) ? props.events : [];
                if (rows.length === 0) {
                    return;
                }

                var entries = rows.map(mapEvent);
                calendarRef.addEventSource(entries);
            }

            onMounted(function () {
                if (!calendarEl.value) {
                    return;
                }

                if (!window.FullCalendar || !window.FullCalendar.Calendar) {
                    console.warn('FullCalendar runtime missing for bookings calendar.');
                    return;
                }

                calendarRef = new window.FullCalendar.Calendar(calendarEl.value, {
                    initialView: window.sbdpBookingsCalendar?.defaultView || 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
                    },
                    height: 'auto',
                    events: [],
                    eventClick: function (info) {
                        if (info.event && info.event.extendedProps && info.event.extendedProps.original) {
                            context.emit('select', info.event.extendedProps.original);
                        }
                    }
                });

                if (Array.isArray(props.events) && props.events.length > 0 && props.events[0].start) {
                    try {
                        calendarRef.gotoDate(props.events[0].start);
                    } catch (error) {
                        // ignore invalid date navigation
                    }
                }

                calendarRef.render();
                renderEvents();
            });

            window.Vue.watch(
                function () {
                    return (props.events || []).map(function (event) {
                        return [
                            event.id,
                            event.title,
                            event.start,
                            event.end,
                            event.status
                        ].join('|');
                    });
                },
                function () {
                    renderEvents();
                }
            );

            onBeforeUnmount(function () {
                if (calendarRef) {
                    calendarRef.destroy();
                    calendarRef = null;
                }
            });

            window.Vue.watch(
                function () {
                    return Array.isArray(props.conflictIds) ? props.conflictIds.slice().sort() : [];
                },
                function () {
                    renderEvents();
                }
            );

            return {
                calendarEl: calendarEl
            };
        },
        computed: {
            labels: function () {
                return {
                    loading: window.sbdpBookingsCalendar?.i18n?.loading || 'Loading…'
                };
            }
        },
        template:
            '<div class="sbdp-calendar-board">' +
            '  <div ref="calendarEl" class="sbdp-calendar-board__grid"></div>' +
            '  <div v-if="loading" class="sbdp-calendar-board__overlay">{{ labels.loading }}</div>' +
            '</div>'
    };

    var BookingFilters = {
        name: 'BookingFilters',
        emits: ['update:criteria', 'refresh'],
        props: {
            filters: { type: Object, default: function () { return {}; } },
            criteria: { type: Object, required: true }
        },
        template:
            '<div class="sbdp-booking-filters">' +
            '  <label>' +
            '    <span>Status</span>' +
            '    <select multiple v-model="localCriteria.status">' +
            '      <option v-for="option in filters.status" :value="option.id">{{ option.label }}</option>' +
            '    </select>' +
            '  </label>' +
            '  <label>' +
            '    <span>Product</span>' +
            '    <select multiple v-model="localCriteria.product_id">' +
            '      <option v-for="option in filters.products" :value="option.id">{{ option.label }}</option>' +
            '    </select>' +
            '  </label>' +
            '  <label>' +
            '    <span>From</span>' +
            '    <input type="date" v-model="localCriteria.date_from" />' +
            '  </label>' +
            '  <label>' +
            '    <span>To</span>' +
            '    <input type="date" v-model="localCriteria.date_to" />' +
            '  </label>' +
            '  <label>' +
            '    <span>Email</span>' +
            '    <input type="search" v-model="localCriteria.search" placeholder="customer@example.com" />' +
            '  </label>' +
            '  <button type="button" @click="apply">{{ labels.apply }}</button>' +
            '</div>',
        data: function () {
            return {
                localCriteria: Object.assign({}, this.criteria)
            };
        },
        computed: {
            labels: function () {
                return {
                    apply: window.sbdpBookingsCalendar?.i18n?.apply || 'Apply filters'
                };
            }
        },
        methods: {
            apply: function () {
                this.$emit('update:criteria', Object.assign({}, this.localCriteria));
                this.$emit('refresh');
            }
        }
    };

    var BookingPopup = {
        name: 'BookingPopup',
        emits: ['close'],
        props: {
            booking: { type: Object, default: null }
        },
        template:
            '<div v-if="booking" class="sbdp-booking-popup">' +
            '  <button class="sbdp-booking-popup__close" @click="$emit(\'close\')">×</button>' +
            '  <h3>{{ booking.title }}</h3>' +
            '  <dl>' +
            '    <dt>Status</dt><dd>{{ booking.status }}</dd>' +
            '    <dt>Customer</dt><dd>{{ booking.customer.name }} &lt;{{ booking.customer.email }}&gt;</dd>' +
            '    <dt>Schedule</dt><dd>{{ booking.start }} → {{ booking.end || "—" }}</dd>' +
            '    <dt>Participants</dt><dd>{{ booking.people }}</dd>' +
            '    <dt>Total</dt><dd>{{ booking.total }} {{ booking.currency }}</dd>' +
            '  </dl>' +
            '</div>'
    };

    var LegendStatus = {
        name: 'LegendStatus',
        props: {
            filters: { type: Object, default: function () { return {}; } }
        },
        template:
            '<ul class="sbdp-status-legend">' +
            '  <li v-for="option in filters.status" :key="option.id">' +
            '    <span :class="badgeClass(option.id)"></span>' +
            '    <span>{{ option.label }} ({{ option.count }})</span>' +
            '  </li>' +
            '</ul>',
        methods: {
            badgeClass: function (status) {
                return 'sbdp-status-legend__badge sbdp-status-legend__badge--' + status;
            }
        }
    };

    var CapacityHeatmap = {
        name: 'CapacityHeatmap',
        props: {
            capacity: { type: Array, default: function () { return []; } }
        },
        template:
            '<div class="sbdp-capacity-heatmap">' +
            '  <div v-for="bucket in capacity" :key="bucket.date" class="sbdp-capacity-heatmap__cell">' +
            '    <span>{{ bucket.date }}</span>' +
            '    <progress :value="bucket.booked" :max="bucket.max"></progress>' +
            '    <small>{{ bucket.booked }} / {{ bucket.max }}</small>' +
            '  </div>' +
            '</div>'
    };

    var ConflictAlerts = {
        name: 'ConflictAlerts',
        props: {
            alerts: { type: Array, default: function () { return []; } }
        },
        template:
            '<div class="sbdp-conflict-alerts" v-if="alerts.length">' +
            '  <h3>{{ labels.title }}</h3>' +
            '  <ul>' +
            '    <li v-for="alert in alerts" :key="alertKey(alert)" :class="alertClass(alert)">' +
            '      <strong>{{ formatType(alert.type) }}</strong> — {{ alert.message }}' +
            '    </li>' +
            '  </ul>' +
            '</div>',
        computed: {
            labels: function () {
                return {
                    title: window.sbdpBookingsCalendar?.i18n?.conflicts || 'Conflicts detected'
                };
            }
        },
        methods: {
            alertKey: function (alert) {
                var keyParts = [
                    alert.type || 'unknown',
                    alert.product_id || 'n/a',
                    alert.date || 'n/a'
                ];
                if (Array.isArray(alert.booking_ids)) {
                    keyParts.push(alert.booking_ids.join('-'));
                }
                return keyParts.join('|');
            },
            alertClass: function (alert) {
                var type = alert.type || 'unknown';
                return 'sbdp-conflict-alerts__item sbdp-conflict-alerts__item--' + type;
            },
            formatType: function (type) {
                var map = {
                    overlap: window.sbdpBookingsCalendar?.i18n?.overlap || 'Overlap',
                    capacity: window.sbdpBookingsCalendar?.i18n?.capacity || 'Capacity'
                };

                return map[type] || type;
            }
        }
    };

    var ExportMenu = {
        name: 'ExportMenu',
        props: {
            criteria: { type: Object, required: true }
        },
        template:
            '<div class="sbdp-export-menu">' +
            '  <button type="button" @click="exportData(\'csv\')" :disabled="!hasEndpoint">CSV</button>' +
            '  <button type="button" @click="exportData(\'pdf\')" :disabled="!hasPdf" title="PDF export komt later">PDF</button>' +
            '</div>',
        methods: {
            exportData: function (format) {
                var baseUrl = (window.sbdpBookingsCalendar && window.sbdpBookingsCalendar.exportEndpoint) || '';
                if (!baseUrl) {
                    console.warn('Missing export endpoint');
                    return;
                }

                var url = baseUrl + '?format=' + encodeURIComponent(format);
                var query = buildQuery(this.criteria);
                if (query) {
                    url += '&' + query;
                }
                window.open(url, '_blank');
            }
        },
        computed: {
            hasEndpoint: function () {
                return !!(window.sbdpBookingsCalendar && window.sbdpBookingsCalendar.exportEndpoint);
            },
            hasPdf: function () {
                return false;
            }
        }
    };

    var CalendarApp = {
        name: 'BookingsCalendarApp',
        components: {
            CalendarBoard: CalendarBoard,
            BookingFilters: BookingFilters,
            BookingPopup: BookingPopup,
            LegendStatus: LegendStatus,
            CapacityHeatmap: CapacityHeatmap,
            ExportMenu: ExportMenu,
            ConflictAlerts: ConflictAlerts
        },
        setup: function () {
            var selected = reactive({
                booking: null
            });

            function refreshAll() {
                fetchCalendarData();
                fetchConflictAlerts();
            }

            onMounted(function () {
                refreshAll();
            });

            watch(
                function () {
                    return state.criteria;
                },
                function () {
                    refreshAll();
                },
                { deep: true }
            );

            return {
                state: state,
                selected: selected,
                conflictIds: computed(function () {
                    var ids = [];
                    if (!Array.isArray(state.alerts)) {
                        return ids;
                    }

                    state.alerts.forEach(function (alert) {
                        if (Array.isArray(alert.booking_ids)) {
                            alert.booking_ids.forEach(function (id) {
                                var numericId = typeof id === 'number' ? id : parseInt(id, 10);
                                if (!Number.isNaN(numericId) && ids.indexOf(numericId) === -1) {
                                    ids.push(numericId);
                                }
                            });
                        }
                    });

                    return ids;
                }),
                setCriteria: function (criteria) {
                    state.criteria = criteria;
                },
                selectBooking: function (booking) {
                    selected.booking = booking;
                },
                closePopup: function () {
                    selected.booking = null;
                },
                refresh: refreshAll
            };
        },
        template:
            '<div class="sbdp-bookings-calendar">' +
            '  <booking-filters :filters="state.filters" :criteria="state.criteria" @update:criteria="setCriteria" @refresh="refresh" />' +
            '  <legend-status :filters="state.filters" />' +
            '  <calendar-board :events="state.events" :loading="state.loading" :conflict-ids="conflictIds" @select="selectBooking" />' +
            '  <conflict-alerts :alerts="state.alerts" />' +
            '  <capacity-heatmap :capacity="state.capacity" />' +
            '  <export-menu :criteria="state.criteria" />' +
            '  <booking-popup :booking="selected.booking" @close="closePopup" />' +
            '</div>'
    };

    var app = createApp(CalendarApp);
    app.mount(mountEl);
})(window, document);
