
(function () {
    'use strict';

    var root = document.getElementById('bpm-planner');
    if (! root) {
        return;
    }

    var restBase = root.getAttribute('data-rest') || root.getAttribute('data-rest-base') || '/wp-json/booking/v1/planner';
    var restNonce = root.getAttribute('data-nonce') || '';
    var publicNonce = root.getAttribute('data-public') || root.getAttribute('data-public-nonce') || '';
    var pricingEndpoint = root.getAttribute('data-pricing') || root.getAttribute('data-pricing-preview') || '';

    if (typeof window !== 'undefined' && typeof window.BPMPlannerConfig === 'object' && window.BPMPlannerConfig) {
        var plannerConfigBootstrap = window.BPMPlannerConfig;
        if (! restBase && plannerConfigBootstrap.rest_base) {
            restBase = plannerConfigBootstrap.rest_base;
        }
        if (! restNonce && plannerConfigBootstrap.nonce) {
            restNonce = plannerConfigBootstrap.nonce;
        }
        if (! publicNonce && plannerConfigBootstrap.public_nonce) {
            publicNonce = plannerConfigBootstrap.public_nonce;
        }
        if (! pricingEndpoint && plannerConfigBootstrap.pricing_preview) {
            pricingEndpoint = plannerConfigBootstrap.pricing_preview;
        }
    }

    if (restBase.slice(-1) === '/') {
        restBase = restBase.slice(0, -1);
    }

    var endpoints = {
        config: restBase + '/config',
        products: restBase + '/products',
        state: restBase + '/state',
        schedule: restBase + '/schedule',
    };

    var state = {
        ready: false,
        error: null,
        config: null,
        products: [],
        filteredProducts: [],
        filters: {
            search: '',
            outlet: 'all',
            channel: 'all',
        },
        scenarioId: null,
        scenarios: [],
        scenarioMeta: {
            label: '',
            customer: {
                name: '',
                email: '',
                phone: '',
                company: '',
                billing: {
                    address_1: '',
                    address_2: '',
                    postcode: '',
                    city: '',
                    country: 'NL',
                },
            },
            window: {
                start: '',
                end: '',
            },
            notes: '',
            is_primary: false,
        },
        itinerary: [],
        timeline: [],
        windows: [],
        conflicts: [],
        conflictIndexMap: {},
        composer: null,
        saving: false,
        scheduling: false,
        dirty: false,
        lastSavedAt: null,
        saveTimer: null,
        scheduleTimer: null,
        productIndex: Object.create(null),
        productsVersion: 0,
        filtersHash: '',
        calendar: [],
    };
    var pricingCache = Object.create(null);
    var pricingRequests = Object.create(null);
    var pricingControllers = Object.create(null);
    var itineraryPricingTimers = Object.create(null);
    var composerPricingTimer = null;
    var composerPricingController = null;

    var DATE_TIME_FORMAT = new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
    var DAY_LABEL_FORMAT = new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    });
    var PREFILL_SESSION_KEY = 'sbdpPlannerPrefillQueue';
    var pendingPrefillEntries = [];
    var processingPrefillEntry = false;
    var prefillInitialized = false;
    var productFetchPromises = Object.create(null);

    function fetchJson(url, options) {
        var opts = options || {};
        var headers = opts.headers || {};

        if (opts.body && ! headers['Content-Type']) {
            headers['Content-Type'] = 'application/json';
        }

        opts.headers = headers;

        return fetch(url, opts).then(function (response) {
            if (! response.ok) {
                return response.text().then(function (text) {
                    var message = text || response.statusText || 'Request failed';
                    throw new Error(message);
                });
            }

            if (response.status === 204) {
                return {};
            }

            return response.json();
        });
    }

    function getTimeStepMinutes() {
        var configured = state.config && state.config.time_step_minutes ? parseInt(state.config.time_step_minutes, 10) : 30;
        return Number.isFinite(configured) && configured > 0 ? configured : 30;
    }

    function getTimeStepSeconds() {
        return getTimeStepMinutes() * 60;
    }

    function getDefaultQuantity(product) {
        var limits = getProductLimits(product);
        return limits.default;
    }

    function applyConfig(configPayload) {
        if (! configPayload || typeof configPayload !== 'object') {
            return;
        }

        state.config = configPayload;

        if (configPayload.rest_endpoints && typeof configPayload.rest_endpoints === 'object') {
            if (! pricingEndpoint && typeof configPayload.rest_endpoints.pricing_preview === 'string') {
                pricingEndpoint = configPayload.rest_endpoints.pricing_preview;
            } else if (typeof configPayload.rest_endpoints.pricing_preview === 'string') {
                pricingEndpoint = configPayload.rest_endpoints.pricing_preview;
            }
        }

        if (configPayload.rest && typeof configPayload.rest === 'object' && typeof configPayload.rest.pricing_preview === 'string') {
            pricingEndpoint = configPayload.rest.pricing_preview;
        }

        if (configPayload.security && typeof configPayload.security === 'object') {
            if (typeof configPayload.security.nonce === 'string' && configPayload.security.nonce !== '') {
                restNonce = configPayload.security.nonce;
            }
            if (typeof configPayload.security.public_nonce === 'string' && configPayload.security.public_nonce !== '') {
                publicNonce = configPayload.security.public_nonce;
            }
        }
    }

    function applyProducts(productsPayload) {
        var products = Array.isArray(productsPayload) ? productsPayload : [];
        state.products = products.map(function (product) {
            return Object.assign({}, product, {
                outlets: Array.isArray(product.outlets) ? product.outlets : [],
                channels: Array.isArray(product.channels) ? product.channels : [],
                resources: product.resources && product.resources.items ? product.resources.items : (product.resources || []),
                combos: Array.isArray(product.combos) ? product.combos : [],
            });
        });
        state.productsVersion += 1;
        state.productIndex = Object.create(null);
        state.filtersHash = '';
        for (var i = 0; i < state.products.length; i += 1) {
            state.productIndex[String(state.products[i].id)] = i;
        }
        applyFilters(true);
    }

    function applyFilters(force) {
        var search = state.filters.search.trim().toLowerCase();
        var outlet = state.filters.outlet;
        var channel = state.filters.channel;

        var hash = state.productsVersion + '|' + search + '|' + outlet + '|' + channel;
        if (! force && state.filtersHash === hash) {
            return;
        }

        state.filtersHash = hash;

        state.filteredProducts = state.products.filter(function (product) {
            var matchesSearch = true;
            if (search !== '') {
                matchesSearch =
                    product.name.toLowerCase().indexOf(search) !== -1 ||
                    product.slug.toLowerCase().indexOf(search) !== -1;
            }

            var matchesOutlet = outlet === 'all';
            if (! matchesOutlet) {
                matchesOutlet = product.outlets.some(function (entry) {
                    return entry.slug === outlet || String(entry.id) === outlet;
                });
            }

            var matchesChannel = channel === 'all';
            if (! matchesChannel) {
                matchesChannel = product.channels.some(function (entry) {
                    return entry.key === channel || entry.label === channel;
                });
            }

            return matchesSearch && matchesOutlet && matchesChannel;
        });
    }
    function applyScenario(payload) {
        if (! payload || typeof payload !== 'object') {
            return;
        }

        if (payload.scenarios && Array.isArray(payload.scenarios)) {
            state.scenarios = payload.scenarios;
        }

        if (typeof payload.scenario_id === 'string' && payload.scenario_id !== '') {
            state.scenarioId = payload.scenario_id;
        } else if (! state.scenarioId) {
            state.scenarioId = 'default';
        }

        var scenario = payload.scenario && typeof payload.scenario === 'object'
            ? payload.scenario
            : null;
        var itinerary = Array.isArray(payload.state) ? payload.state : [];

        if (scenario) {
            if (Array.isArray(scenario.itinerary)) {
                itinerary = scenario.itinerary;
            }

            var meta = scenario.meta && typeof scenario.meta === 'object' ? scenario.meta : {};
            var customer = Object.assign({
                name: '',
                email: '',
                phone: '',
                company: '',
            }, meta.customer || {});
            customer.billing = Object.assign({
                address_1: '',
                address_2: '',
                postcode: '',
                city: '',
                country: 'NL',
            }, customer.billing || {});
            state.scenarioMeta = {
                label: scenario.label || '',
                customer: customer,
                window: Object.assign({
                    start: '',
                    end: '',
                }, meta.window || {}),
                notes: meta.notes || '',
                is_primary: !! scenario.is_primary,
            };
        } else if (! state.scenarioMeta.label) {
            state.scenarioMeta.label = state.scenarioId === 'default' ? 'Planner' : capitalise(state.scenarioId);
        }

        state.itinerary = itinerary.map(function (item, index) {
            return normalizeItineraryItem(item, index);
        });

        normalizeItinerary();
        rebuildConflictIndexMap();
        state.itinerary.forEach(function (entry) {
            scheduleItineraryPricing(entry);
        });
    }

    function normalizeItineraryItem(item, index) {
        if (! item || typeof item !== 'object') {
            item = {};
        }

        var start = sanitizeIso(item.start || item.time || '');
        var defaultStep = getTimeStepMinutes();
        var duration = toPositiveInt(item.duration_minutes || item.duration || 0, defaultStep);
        if (duration <= 0) {
            duration = defaultStep;
        }

        var end = sanitizeIso(item.end || computeEnd(start, duration));

        var productId = toPositiveInt(item.product_id || item.id || 0, 0);
        var product = findProduct(productId);

        var currency = typeof item.pricing_currency === 'string' && item.pricing_currency !== ''
            ? item.pricing_currency
            : (product && product.pricing && product.pricing.currency) || getPlannerCurrency();
        var pricingTotal = typeof item.pricing_total === 'number' ? item.pricing_total : null;
        var pricingUnit = typeof item.pricing_unit === 'number' ? item.pricing_unit : null;
        var pricingStatus = item.pricing_status || (pricingTotal !== null ? 'ready' : null);
        var pricingFormattedTotal = item.pricing_formatted_total || (pricingTotal !== null ? formatMoney(pricingTotal, currency) : null);
        var pricingFormattedUnit = item.pricing_formatted_unit || (pricingUnit !== null ? formatMoney(pricingUnit, currency) : null);

        return {
            uid: item.uid || item.id || generateUid(index),
            product_id: productId,
            product_name: item.product_name || (product ? product.name : '') || '',
            resource: item.resource || '',
            resource_label: item.resource_label || lookupResourceLabel(product, item.resource || ''),
            channel: item.channel || '',
            start: start,
            end: end,
            duration_minutes: duration,
            quantity: toPositiveInt(item.quantity, toPositiveInt(item.people, 1)),
            capacity: toPositiveInt(item.capacity, toPositiveInt(item.people, toPositiveInt(item.quantity, 0))),
            buffer_before: toPositiveInt(item.buffer_before || item.buffer || 0, 0),
            buffer_after: toPositiveInt(item.buffer_after || 0, 0),
            notes: item.notes || '',
            pricing_status: pricingStatus,
            pricing_total: pricingTotal,
            pricing_unit: pricingUnit,
            pricing_currency: currency,
            pricing_formatted_total: pricingFormattedTotal,
            pricing_formatted_unit: pricingFormattedUnit,
            pricing_breakdown: item.pricing_breakdown || null,
        };
    }

    function normalizeItinerary() {
        state.itinerary.sort(function (left, right) {
            return (left.start || '').localeCompare(right.start || '');
        });
    }

    function rebuildConflictIndexMap() {
        var map = {};
        state.conflicts.forEach(function (conflict) {
            if (conflict && conflict.segment) {
                map[conflict.segment.index] = true;
            }
            if (conflict && Array.isArray(conflict.overlapping)) {
                conflict.overlapping.forEach(function (segment) {
                    map[segment.index] = true;
                });
            }
        });
        state.conflictIndexMap = map;
    }

    function loadInitial() {
        Promise.all([
            fetchJson(endpoints.config),
            fetchJson(endpoints.products),
            fetchJson(endpoints.state),
        ])
            .then(function (results) {
                applyConfig(results[0] && results[0].config ? results[0].config : results[0]);
                applyProducts(results[1] && results[1].products ? results[1].products : []);
                applyScenario(results[2]);

                state.ready = true;
                state.error = null;
                state.itinerary.forEach(function (entry) {
                    scheduleItineraryPricing(entry);
                });
                render();
                scheduleItinerary();
                initPrefillIntegration();
                processPendingPrefill();
            })
            .catch(function (error) {
                state.error = error && error.message ? error.message : 'Kan planner niet laden.';
                state.ready = true;
                render();
                initPrefillIntegration();
                processPendingPrefill();
            });
    }

    function render() {
        root.className = 'bpm-planner';
        root.innerHTML = '';

        if (! state.ready) {
            root.appendChild(el('div', { className: 'bpm-planner__loading' }, textNode('Planner laden...')));
            return;
        }

        if (state.error) {
            root.appendChild(el('div', { className: 'bpm-planner__error' }, textNode(state.error)));
        }

        var header = renderHeader();
        var layout = renderLayout();

        root.appendChild(header);
        root.appendChild(layout);
    }
    function renderHeader() {
        var savedLabel = '';
        if (state.saving) {
            savedLabel = 'Bezig met opslaan...';
        } else if (state.dirty) {
            savedLabel = 'Wijzigingen nog niet opgeslagen';
        } else if (state.lastSavedAt) {
            savedLabel = 'Laatst opgeslagen: ' + formatDateTime(state.lastSavedAt);
        } else {
            savedLabel = 'Laden voltooid';
        }

        var scenarioOptions = state.scenarios.map(function (scenario) {
            return el('option', {
                value: scenario.scenario_id,
                selected: scenario.scenario_id === state.scenarioId,
            }, textNode(scenario.label || scenario.scenario_id));
        });

        var scenarioSelect = el('select', {
            className: 'bpm-planner__scenario-select',
            onChange: function (event) {
                var value = event.target.value;
                if (value && value !== state.scenarioId) {
                    loadScenario(value);
                }
            },
        }, scenarioOptions);

        var newButton = el('button', {
            type: 'button',
            className: 'bpm-planner__scenario-new',
            onClick: createScenario,
        }, textNode('Nieuw scenario'));

        var header = el('header', { className: 'bpm-planner__header' },
            el('div', { className: 'bpm-planner__header-left' },
                el('h1', { className: 'bpm-planner__title' }, textNode(state.scenarioMeta.label || 'Planner')),
                scenarioSelect,
                newButton
            ),
            el('div', { className: 'bpm-planner__header-right' },
                el('span', {
                    className: 'bpm-planner__status' + (state.dirty ? ' is-dirty' : '') + (state.saving ? ' is-saving' : ''),
                }, textNode(savedLabel))
            )
        );

        return header;
    }

    function renderLayout() {
        var layout = el('div', { className: 'bpm-planner__layout' },
            renderCatalog(),
            renderPlan()
        );

        return layout;
    }

    function renderCatalog() {
        var filtersBar = el('div', { className: 'bpm-planner__filters' },
            el('input', {
                type: 'search',
                className: 'bpm-planner__filter-input',
                placeholder: 'Zoek producten...',
                value: state.filters.search,
                onInput: function (event) {
                    state.filters.search = event.target.value;
                    applyFilters(true);
                    render();
                },
            }),
            renderOutletFilter(),
            renderChannelFilter()
        );

        var list = state.filteredProducts.length === 0
            ? el('div', { className: 'bpm-planner__product-empty', role: 'status', 'aria-live': 'polite' },
                el('p', { className: 'bpm-planner__product-empty-title' }, textNode('Geen resultaten')),
                el('p', { className: 'bpm-planner__product-empty-body' }, textNode('We konden geen activiteiten vinden die passen bij je filters. Wis de filters of pas ze aan voor meer opties.'))
            )
            : el('div', { className: 'bpm-planner__product-list', role: 'list', onDragOver: allowDropToCatalog },
                state.filteredProducts.map(renderProductCard)
            );

        return el('section', { className: 'bpm-planner__catalog-section' },
            el('h2', { className: 'bpm-planner__section-title' }, textNode('Producten')),
            filtersBar,
            list
        );
    }

    function renderOutletFilter() {
        var options = [el('option', { value: 'all', selected: state.filters.outlet === 'all' }, textNode('Alle outlets'))];
        var seen = {};

        state.products.forEach(function (product) {
            product.outlets.forEach(function (outlet) {
                if (! seen[outlet.slug]) {
                    seen[outlet.slug] = true;
                    options.push(el('option', {
                        value: outlet.slug,
                        selected: outlet.slug === state.filters.outlet,
                    }, textNode(outlet.name)));
                }
            });
        });

        return el('select', {
            className: 'bpm-planner__filter-select',
            onChange: function (event) {
                state.filters.outlet = event.target.value;
                applyFilters(true);
                render();
            },
        }, options);
    }

    function renderChannelFilter() {
        var options = [el('option', { value: 'all', selected: state.filters.channel === 'all' }, textNode('Alle kanalen'))];
        var seen = {};

        state.products.forEach(function (product) {
            product.channels.forEach(function (channel) {
                if (! seen[channel.key]) {
                    seen[channel.key] = true;
                    options.push(el('option', {
                        value: channel.key,
                        selected: channel.key === state.filters.channel,
                    }, textNode(channel.label || channel.key)));
                }
            });
        });

        return el('select', {
            className: 'bpm-planner__filter-select',
            onChange: function (event) {
                state.filters.channel = event.target.value;
                applyFilters(true);
                render();
            },
        }, options);
    }

    function renderProductCard(product) {
        var duration = product.duration && product.duration.minutes ? product.duration.minutes : null;
        var durationLabel = duration ? duration + ' min' : '';
        var priceLabel = getDisplayPrice(product);

        return el('article', {
            className: 'bpm-planner__product-card',
            draggable: true,
            role: 'listitem',
            tabIndex: 0,
            onDragStart: function (event) {
                handleDragStart(event, product);
            },
            onKeyDown: function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openComposer(product);
                }
            },
        },
            el('div', { className: 'bpm-planner__product-header' },
                el('h3', { className: 'bpm-planner__product-name' }, textNode(product.name)),
                durationLabel ? el('span', { className: 'bpm-planner__product-duration' }, textNode(durationLabel)) : null
            ),
            priceLabel ? el('div', { className: 'bpm-planner__product-price' }, textNode(priceLabel)) : null,
            product.outlets.length ? el('div', { className: 'bpm-planner__product-meta' },
                textNode(product.outlets.map(function (outlet) { return outlet.name; }).join(', '))
            ) : null,
            el('button', {
                type: 'button',
                className: 'bpm-planner__product-add',
                'aria-label': 'Plan ' + product.name,
                onClick: function () {
                    openComposer(product);
                },
            }, textNode('Inplannen'))
        );
    }
    function renderPlan() {
        var planBody = el('div', { className: 'bpm-planner__plan-body', onDragOver: allowDropToPlan, onDrop: handleDropOnPlan },
            state.composer ? renderComposer() : null,
            renderScenarioForm(),
            renderConflicts(),
            renderItinerary(),
            renderScheduleBoard(),
            renderTimeline()
        );

        return el('section', { className: 'bpm-planner__plan-section' },
            el('h2', { className: 'bpm-planner__section-title' }, textNode('Plan je dag')),
            planBody
        );
    }

    function renderScenarioForm() {
        return el('div', { className: 'bpm-planner__scenario-form' },
            el('div', { className: 'bpm-planner__form-row' },
                el('label', { className: 'bpm-planner__label' }, textNode('Naam scenario')),
                el('input', {
                    type: 'text',
                    className: 'bpm-planner__input',
                    value: state.scenarioMeta.label,
                    onBlur: function (event) {
                        state.scenarioMeta.label = event.target.value;
                        state.dirty = true;
                        scheduleAutosave();
                        render();
                    },
                })
            ),
            el('div', { className: 'bpm-planner__form-row' },
                el('label', { className: 'bpm-planner__label' }, textNode('Contactpersoon')),
                el('input', {
                    type: 'text',
                    className: 'bpm-planner__input',
                    value: state.scenarioMeta.customer.name || '',
                    onBlur: function (event) {
                        state.scenarioMeta.customer.name = event.target.value;
                        touchScenarioMeta();
                    },
                })
            ),
            el('div', { className: 'bpm-planner__form-row' },
                el('label', { className: 'bpm-planner__label' }, textNode('E-mail')),
                el('input', {
                    type: 'email',
                    className: 'bpm-planner__input',
                    value: state.scenarioMeta.customer.email || '',
                    onBlur: function (event) {
                        state.scenarioMeta.customer.email = event.target.value;
                        touchScenarioMeta();
                    },
                })
            ),
            el('div', { className: 'bpm-planner__form-row' },
                el('label', { className: 'bpm-planner__label' }, textNode('Telefoon')),
                el('input', {
                    type: 'tel',
                    className: 'bpm-planner__input',
                    value: state.scenarioMeta.customer.phone || '',
                    onBlur: function (event) {
                        state.scenarioMeta.customer.phone = event.target.value;
                        touchScenarioMeta();
                    },
                })
            ),
            el('div', { className: 'bpm-planner__form-row' },
                el('label', { className: 'bpm-planner__label' }, textNode('Bedrijf')),
                el('input', {
                    type: 'text',
                    className: 'bpm-planner__input',
                    value: state.scenarioMeta.customer.company || '',
                    onBlur: function (event) {
                        state.scenarioMeta.customer.company = event.target.value;
                        touchScenarioMeta();
                    },
                })
            ),
            el('div', { className: 'bpm-planner__form-row' },
                el('label', { className: 'bpm-planner__label' }, textNode('Straat en huisnummer')),
                el('input', {
                    type: 'text',
                    className: 'bpm-planner__input',
                    value: (state.scenarioMeta.customer.billing && state.scenarioMeta.customer.billing.address_1) || '',
                    onBlur: function (event) {
                        state.scenarioMeta.customer.billing = state.scenarioMeta.customer.billing || {};
                        state.scenarioMeta.customer.billing.address_1 = event.target.value;
                        touchScenarioMeta();
                    },
                })
            ),
            el('div', { className: 'bpm-planner__form-row bpm-planner__form-row--split' },
                el('div', { className: 'bpm-planner__form-field' },
                    el('label', { className: 'bpm-planner__label' }, textNode('Postcode')),
                    el('input', {
                        type: 'text',
                        className: 'bpm-planner__input',
                        value: (state.scenarioMeta.customer.billing && state.scenarioMeta.customer.billing.postcode) || '',
                        onBlur: function (event) {
                            state.scenarioMeta.customer.billing = state.scenarioMeta.customer.billing || {};
                            state.scenarioMeta.customer.billing.postcode = event.target.value;
                            touchScenarioMeta();
                        },
                    })
                ),
                el('div', { className: 'bpm-planner__form-field' },
                    el('label', { className: 'bpm-planner__label' }, textNode('Plaats')),
                    el('input', {
                        type: 'text',
                        className: 'bpm-planner__input',
                        value: (state.scenarioMeta.customer.billing && state.scenarioMeta.customer.billing.city) || '',
                        onBlur: function (event) {
                            state.scenarioMeta.customer.billing = state.scenarioMeta.customer.billing || {};
                            state.scenarioMeta.customer.billing.city = event.target.value;
                            touchScenarioMeta();
                        },
                    })
                )
            ),
            el('div', { className: 'bpm-planner__form-row bpm-planner__form-row--split' },
                el('div', { className: 'bpm-planner__form-field' },
                    el('label', { className: 'bpm-planner__label' }, textNode('Startdatum')),
                    el('input', {
                        type: 'date',
                        className: 'bpm-planner__input',
                        value: extractDate(state.scenarioMeta.window.start),
                        onChange: function (event) {
                            var date = event.target.value;
                            if (date) {
                                var time = extractTime(state.scenarioMeta.window.start) || '08:00';
                                state.scenarioMeta.window.start = date + 'T' + time;
                            } else {
                                state.scenarioMeta.window.start = '';
                            }
                            touchScenarioMeta();
                        },
                    })
                ),
                el('div', { className: 'bpm-planner__form-field' },
                    el('label', { className: 'bpm-planner__label' }, textNode('Einddatum')),
                    el('input', {
                        type: 'date',
                        className: 'bpm-planner__input',
                        value: extractDate(state.scenarioMeta.window.end),
                        onChange: function (event) {
                            var date = event.target.value;
                            if (date) {
                                var time = extractTime(state.scenarioMeta.window.end) || '23:00';
                                state.scenarioMeta.window.end = date + 'T' + time;
                            } else {
                                state.scenarioMeta.window.end = '';
                            }
                            touchScenarioMeta();
                        },
                    })
                )
            ),
            el('div', { className: 'bpm-planner__form-row' },
                el('label', { className: 'bpm-planner__label' }, textNode('Notities')),
                el('textarea', {
                    className: 'bpm-planner__textarea',
                    value: state.scenarioMeta.notes || '',
                    onBlur: function (event) {
                        state.scenarioMeta.notes = event.target.value;
                        touchScenarioMeta();
                    },
                })
            )
        );
    }

    function renderConflicts() {
        if (! state.conflicts.length) {
            return el('div', { className: 'bpm-planner__conflicts bpm-planner__conflicts--empty', role: 'status', 'aria-live': 'polite' },
                el('p', null, textNode('Geen conflicten gedetecteerd.'))
            );
        }

        var items = state.conflicts.map(function (conflict, index) {
            return el('li', { className: 'bpm-planner__conflict-item', role: 'listitem' },
                el('div', { className: 'bpm-planner__conflict-indicator', 'aria-hidden': 'true' }),
                el('div', { className: 'bpm-planner__conflict-copy' },
                    el('strong', null, textNode(conflictTitle(conflict))),
                    el('div', null, textNode(conflictDescription(conflict))),
                    el('button', {
                        type: 'button',
                        className: 'bpm-planner__conflict-scroll',
                        onClick: function () {
                            focusConflictSource(conflict);
                        },
                        'aria-label': 'Ga naar conflicterende activiteit ' + (index + 1),
                    }, textNode('Bekijk in planning'))
                )
            );
        });

        return el('div', { className: 'bpm-planner__conflicts' },
            el('h3', { className: 'bpm-planner__conflicts-title' }, textNode('Conflicten (' + state.conflicts.length + ')')),
            el('p', { className: 'bpm-planner__conflicts-intro' }, textNode('Los conflicten op door de betreffende activiteiten te verplaatsen of hun capaciteit aan te passen.')),
            el('ul', { className: 'bpm-planner__conflict-list', role: 'alert', 'aria-live': 'assertive' }, items)
        );
}

    function focusConflictSource(conflict) {
        if (! conflict) {
            return;
        }

        var targetIndex = null;
        if (conflict.segment && typeof conflict.segment.index === 'number') {
            targetIndex = conflict.segment.index;
        } else if (Array.isArray(conflict.overlapping)) {
            for (var i = 0; i < conflict.overlapping.length; i += 1) {
                var candidate = conflict.overlapping[i];
                if (candidate && typeof candidate.index === 'number') {
                    targetIndex = candidate.index;
                    break;
                }
            }
        }

        if (targetIndex === null) {
            return;
        }

        var selector = '[data-planner-index="' + targetIndex + '"]';
        var target = root.querySelector(selector);
        if (! target) {
            return;
        }

        var highlightClass = 'is-highlighted';
        target.classList.add(highlightClass);
        if (typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        if (typeof target.focus === 'function') {
            try {
                target.focus({ preventScroll: true });
            } catch (error) {
                target.focus();
            }
        }

        if (typeof window !== 'undefined') {
            window.setTimeout(function () {
                target.classList.remove(highlightClass);
            }, 1600);
        }
    }

    function renderItinerary() {
        if (! state.itinerary.length) {
            return el('div', { className: 'bpm-planner__itinerary-empty' },
                textNode('Sleep producten naar deze zone of gebruik de knop "Inplannen".')
            );
        }

        var items = state.itinerary.map(function (item, index) {
            return renderItineraryItem(item, index);
        });

        return el('div', { className: 'bpm-planner__itinerary' }, items);
    }

    function renderScheduleBoard() {
        var buckets = Array.isArray(state.calendar) ? state.calendar : [];
        if (! buckets.length) {
            return el('div', { className: 'bpm-planner__calendar bpm-planner__calendar--empty', role: 'status', 'aria-live': 'polite' },
                textNode('Nog geen kalenderweergave beschikbaar.')
            );
        }

        var columns = buckets.map(function (bucket) {
            var gridLines = bucket.markers.map(function (marker) {
                return el('div', {
                    className: 'bpm-planner__calendar-marker',
                    style: {
                        top: marker.position + '%',
                    },
                }, textNode(marker.label));
            });

            var events = bucket.events.map(function (event) {
                var parts = [event.time];
                if (event.label) {
                    parts.push(event.label);
                }
                if (event.resource) {
                    parts.push(event.resource);
                }
                var description = parts.join(' - ');
                return el('div', {
                    className: 'bpm-planner__calendar-event',
                    style: {
                        top: event.position + '%',
                        height: event.span + '%',
                    },
                    title: description,
                    'aria-label': description,
                    role: 'listitem',
                },
                el('div', { className: 'bpm-planner__calendar-event-time' }, textNode(event.time)),
                el('div', { className: 'bpm-planner__calendar-event-label' }, textNode(event.label)),
                event.resource ? el('div', { className: 'bpm-planner__calendar-event-resource' }, textNode(event.resource)) : null
                );
            });

            return el('div', { className: 'bpm-planner__calendar-column', role: 'listitem' },
                el('div', { className: 'bpm-planner__calendar-column-header' }, textNode(bucket.label)),
                el('div', { className: 'bpm-planner__calendar-track', role: 'list' },
                    gridLines,
                    events
                )
            );
        });

        return el('div', { className: 'bpm-planner__calendar', role: 'list' }, columns);
    }

    function renderItineraryPrice(item) {
        var status = item.pricing_status;
        var className = 'bpm-planner__itinerary-price';
        if (status === 'loading') {
            return el('span', { className: className + ' is-loading' }, textNode(messageLookup('pricing_loading', 'Prijs wordt berekend.')));
        }

        if (status === 'error') {
            return el('span', { className: className + ' is-error' }, textNode(messageLookup('pricing_unavailable', 'Prijs niet beschikbaar.')));
        }

        if (status === 'ready' && item.pricing_formatted_total) {
            return el('span', { className: className }, textNode(item.pricing_formatted_total));
        }

        return null;
    }

    function renderItineraryItem(item, index) {
        var product = findProduct(item.product_id);
        var hasConflict = !! state.conflictIndexMap[index];
        var timeStepSeconds = getTimeStepSeconds();
        var timeStepMinutes = getTimeStepMinutes();
        var limits = getProductLimits(product);
        var normalizedQuantity = clampQuantity(item.quantity, limits);
        if (normalizedQuantity !== item.quantity) {
            item.quantity = normalizedQuantity;
            item.capacity = normalizedQuantity;
            state.itinerary[index] = item;
        }

        var priceElement = renderItineraryPrice(item);

        return el('article', {
            className: 'bpm-planner__itinerary-item' + (hasConflict ? ' has-conflict' : ''),
            dataset: { plannerIndex: String(index) },
            tabIndex: 0,
            role: 'group',
            'aria-label': (item.product_name || 'Activiteit') + ' gepland om ' + extractTime(item.start || ''),
        },
            el('header', { className: 'bpm-planner__itinerary-header' },
                el('div', { className: 'bpm-planner__itinerary-title-group' },
                    el('h3', { className: 'bpm-planner__itinerary-title' }, textNode(item.product_name || 'Activiteit')),
                    priceElement
                ),
                el('button', {
                    type: 'button',
                    className: 'bpm-planner__itinerary-remove',
                    onClick: function () {
                        clearItineraryPricing(item);
                        state.itinerary.splice(index, 1);
                        state.dirty = true;
                        scheduleAutosave();
                        scheduleItinerary();
                        render();
                    },
                }, textNode('Verwijderen'))
            ),
            el('div', { className: 'bpm-planner__itinerary-grid' },
                el('label', { className: 'bpm-planner__field' },
                    el('span', { className: 'bpm-planner__field-label' }, textNode('Start')),
                    el('input', {
                        type: 'datetime-local',
                        className: 'bpm-planner__input',
                        value: toLocalInputValue(item.start),
                        step: String(timeStepSeconds),
                        onChange: function (event) {
                            item.start = toIso(event.target.value);
                            item.end = computeEnd(item.start, item.duration_minutes);
                            state.itinerary[index] = item;
                            onItineraryChange();
                            scheduleItineraryPricing(item);
                        },
                    })
                ),
                el('label', { className: 'bpm-planner__field' },
                    el('span', { className: 'bpm-planner__field-label' }, textNode('Duur (min)')),
                    el('input', {
                        type: 'number',
                        min: String(timeStepMinutes),
                        step: String(timeStepMinutes),
                        className: 'bpm-planner__input',
                        value: item.duration_minutes,
                        onChange: function (event) {
                            var value = toPositiveInt(event.target.value, item.duration_minutes);
                            item.duration_minutes = value;
                            item.end = computeEnd(item.start, value);
                            state.itinerary[index] = item;
                            onItineraryChange();
                            scheduleItineraryPricing(item);
                        },
                    })
                ),
                el('label', { className: 'bpm-planner__field' },
                    el('span', { className: 'bpm-planner__field-label' }, textNode('Resource')),
                    renderResourceSelect(product, item, index)
                ),
                renderQuantityControl(product, item, index),
                el('label', { className: 'bpm-planner__field' },
                    el('span', { className: 'bpm-planner__field-label' }, textNode('Kanaal')),
                    el('input', {
                        type: 'text',
                        className: 'bpm-planner__input',
                        value: item.channel,
                        onBlur: function (event) {
                            item.channel = event.target.value;
                            state.itinerary[index] = item;
                            scheduleAutosave();
                        },
                    })
                ),
                el('label', { className: 'bpm-planner__field' },
                    el('span', { className: 'bpm-planner__field-label' }, textNode('Buffer (min)')),
                    el('input', {
                        type: 'number',
                        min: '0',
                        className: 'bpm-planner__input',
                        value: item.buffer_after,
                        onChange: function (event) {
                            item.buffer_after = toPositiveInt(event.target.value, item.buffer_after);
                            state.itinerary[index] = item;
                            onItineraryChange();
                        },
                    })
                )
            ),
            el('div', { className: 'bpm-planner__itinerary-notes' },
                el('label', { className: 'bpm-planner__field' },
                    el('span', { className: 'bpm-planner__field-label' }, textNode('Notities')),
                    el('textarea', {
                        className: 'bpm-planner__textarea',
                        value: item.notes || '',
                        onBlur: function (event) {
                            item.notes = event.target.value;
                            state.itinerary[index] = item;
                            scheduleAutosave();
                        },
                    })
                )
            )
        );
    }

    function renderResourceSelect(product, item, index) {
        var options = [el('option', { value: '' }, textNode('Selecteer resource'))];

        if (product && product.resources) {
            product.resources.forEach(function (resource) {
                var value = String(resource.id);
                options.push(el('option', {
                    value: value,
                    selected: value === item.resource,
                }, textNode(resource.label || resource.name || value)));
            });
        }

        return el('select', {
            className: 'bpm-planner__input',
            value: item.resource,
            onChange: function (event) {
                item.resource = event.target.value;
                item.resource_label = lookupResourceLabel(product, item.resource);
                state.itinerary[index] = item;
                onItineraryChange();
                scheduleItineraryPricing(item);
            },
        }, options);
    }

    function renderQuantityControl(product, item, index) {
        var limits = getProductLimits(product);
        var normalized = clampQuantity(item.quantity, limits);
        if (normalized !== item.quantity || item.capacity !== normalized) {
            item.quantity = normalized;
            item.capacity = normalized;
            state.itinerary[index] = item;
            scheduleItineraryPricing(item);
        }

        return el('div', { className: 'bpm-planner__field' },
            el('span', { className: 'bpm-planner__field-label' }, textNode('Aantal personen')),
            createQuantityControl(item.quantity, limits, function (nextValue) {
                var clamped = clampQuantity(nextValue, limits);
                if (clamped !== item.quantity || item.capacity !== clamped) {
                    item.quantity = clamped;
                    item.capacity = clamped;
                    state.itinerary[index] = item;
                    onItineraryChange();
                    scheduleItineraryPricing(item);
                }
            })
        );
    }

    function renderComposerQuantityControl(product) {
        var limits = getProductLimits(product);
        state.composer.quantity = clampQuantity(state.composer.quantity, limits);

        return el('div', { className: 'bpm-planner__field' },
            el('span', { className: 'bpm-planner__field-label' }, textNode('Aantal personen')),
            createQuantityControl(state.composer.quantity, limits, function (nextValue) {
                var clamped = clampQuantity(nextValue, limits);
                state.composer.quantity = clamped;
                scheduleComposerPricingUpdate();
            })
        );
    }

    function createQuantityControl(currentValue, limits, onCommit) {
        currentValue = clampQuantity(currentValue, limits);

        var input = el('input', {
            type: 'number',
            className: 'bpm-planner__quantity-input',
            value: currentValue,
            min: String(typeof limits.min === 'number' ? limits.min : limits.default),
            step: '1',
            'aria-label': 'Aantal personen',
        });

        if (limits.max !== null) {
            input.max = String(limits.max);
        }

        function commit(value) {
            var clamped = clampQuantity(value, limits);
            currentValue = clamped;
            input.value = clamped;
            onCommit(clamped);
        }

        input.addEventListener('change', function (event) {
            var numeric = parseInt(event.target.value, 10);
            if (isNaN(numeric)) {
                numeric = currentValue;
            }
            commit(numeric);
        });

        return el('div', { className: 'bpm-planner__quantity-control', role: 'group', 'aria-label': 'Aantal personen' },
            el('button', {
                type: 'button',
                className: 'bpm-planner__quantity-button',
                'aria-label': 'Verlaag aantal personen',
                onClick: function () {
                    var next = clampQuantity(parseInt(input.value, 10) - 1, limits);
                    commit(next);
                },
            }, textNode('-')),
            input,
            el('button', {
                type: 'button',
                className: 'bpm-planner__quantity-button bpm-planner__quantity-button--plus',
                'aria-label': 'Verhoog aantal personen',
                onClick: function () {
                    var base = parseInt(input.value, 10);
                    if (isNaN(base)) {
                        base = limits.default;
                    }
                    var next = clampQuantity(base + 1, limits);
                    commit(next);
                },
            }, textNode('+'))
        );
    }

    function renderTimeline() {
        if (! state.timeline.length) {
            return el('div', { className: 'bpm-planner__timeline bpm-planner__timeline--empty' },
                textNode('Nog geen tijdlijn beschikbaar.')
            );
        }

        var items = state.timeline.map(function (entry) {
            return el('li', { className: 'bpm-planner__timeline-item' },
                el('span', { className: 'bpm-planner__timeline-slot' }, textNode(entry.slot)),
                el('span', { className: 'bpm-planner__timeline-resource' }, textNode(entry.resource || '')),
                el('span', { className: 'bpm-planner__timeline-label' }, textNode(entry.label || ''))
            );
        });

        return el('div', { className: 'bpm-planner__timeline' },
            el('h3', { className: 'bpm-planner__timeline-title' }, textNode('Tijdlijn')),
            el('ul', { className: 'bpm-planner__timeline-list' }, items)
        );
    }
    function renderComposer() {
        var composer = state.composer;
        var product = composer ? findProduct(composer.productId) : null;

        if (! composer || ! product) {
            return null;
        }

        var timeStepSeconds = getTimeStepSeconds();
        var timeStepMinutes = getTimeStepMinutes();

        return el('div', { className: 'bpm-planner__composer' },
            el('div', { className: 'bpm-planner__composer-header' },
                el('h3', { className: 'bpm-planner__composer-title' }, textNode('Activiteit inplannen: ' + product.name)),
                el('button', {
                    type: 'button',
                    className: 'bpm-planner__composer-close',
                    onClick: closeComposer,
                }, textNode('Annuleren'))
            ),
            el('div', { className: 'bpm-planner__composer-grid' },
                el('label', { className: 'bpm-planner__field' },
                    el('span', { className: 'bpm-planner__field-label' }, textNode('Start')),
                    el('input', {
                        type: 'datetime-local',
                        className: 'bpm-planner__input',
                        value: composer.start,
                        step: String(timeStepSeconds),
                        onChange: function (event) {
                            composer.start = event.target.value;
                            scheduleComposerPricingUpdate();
                        },
                    })
                ),
                el('label', { className: 'bpm-planner__field' },
                    el('span', { className: 'bpm-planner__field-label' }, textNode('Duur (min)')),
                    el('input', {
                        type: 'number',
                        min: String(timeStepMinutes),
                        step: String(timeStepMinutes),
                        className: 'bpm-planner__input',
                        value: composer.duration,
                        onChange: function (event) {
                            composer.duration = toPositiveInt(event.target.value, composer.duration);
                            scheduleComposerPricingUpdate();
                        },
                    })
                ),
                el('label', { className: 'bpm-planner__field' },
                    el('span', { className: 'bpm-planner__field-label' }, textNode('Resource')),
                    renderComposerResourceSelect(product, composer)
                ),
                renderComposerQuantityControl(product),
                el('label', { className: 'bpm-planner__field' },
                    el('span', { className: 'bpm-planner__field-label' }, textNode('Kanaal')),
                    el('input', {
                        type: 'text',
                        className: 'bpm-planner__input',
                        value: composer.channel,
                        onChange: function (event) {
                            composer.channel = event.target.value;
                        },
                    })
                ),
                el('label', { className: 'bpm-planner__field' },
                    el('span', { className: 'bpm-planner__field-label' }, textNode('Notities')),
                    el('textarea', {
                        className: 'bpm-planner__textarea',
                        value: composer.notes,
                        onChange: function (event) {
                            composer.notes = event.target.value;
                        },
                    })
                )
            ),
            renderComposerPricingSummary(),
            renderComposerCombos(product),
            el('div', { className: 'bpm-planner__composer-actions' },
                el('button', {
                    type: 'button',
                    className: 'bpm-planner__composer-save',
                    onClick: commitComposer,
                }, textNode('Toevoegen aan planning'))
            )
        );
    }
    function renderComposerCombos(product) {
        if (! product || ! Array.isArray(product.combos) || product.combos.length === 0) {
            return null;
        }

        var items = product.combos.map(function (combo) {
            var priceLabel = getDisplayPrice(combo) || null;

            return el('li', { className: 'bpm-planner__composer-combos-item' },
                el('div', { className: 'bpm-planner__composer-combos-content' },
                    el('div', { className: 'bpm-planner__composer-combos-name' }, textNode(combo.name)),
                    priceLabel ? el('div', { className: 'bpm-planner__composer-combos-price' }, textNode(priceLabel)) : null
                ),
                el('button', {
                    type: 'button',
                    className: 'bpm-planner__composer-combos-button',
                    onClick: function () {
                        openComboDeal(combo.id);
                    },
                    'aria-label': 'Plan combideal ' + combo.name,
                }, textNode('Plan combideal'))
            );
        });

        return el('div', { className: 'bpm-planner__composer-combos' },
            el('h4', { className: 'bpm-planner__composer-combos-title' }, textNode('Combineer met')),
            el('p', { className: 'bpm-planner__composer-combos-intro' }, textNode('Voeg aanvullende activiteiten direct toe vanuit deze combideals.')),
            el('ul', { className: 'bpm-planner__composer-combos-list', role: 'list' }, items)
        );
    }

    function renderComposerResourceSelect(product, composer) {
        var options = [el('option', { value: '' }, textNode('Geen voorkeur'))];

        if (product && product.resources) {
            product.resources.forEach(function (resource) {
                var value = String(resource.id);
                options.push(el('option', {
                    value: value,
                    selected: value === composer.resource,
                }, textNode(resource.label || resource.name || value)));
            });
        }

        return el('select', {
            className: 'bpm-planner__input',
            value: composer.resource,
            onChange: function (event) {
                composer.resource = event.target.value;
                scheduleComposerPricingUpdate();
            },
        }, options);
    }

    function handleDragStart(event, product) {
        try {
            event.dataTransfer.setData('application/json', JSON.stringify({ productId: product.id }));
        } catch (error) {
            event.dataTransfer.setData('text/plain', String(product.id));
        }
        event.dataTransfer.effectAllowed = 'copy';
    }

    function allowDropToCatalog(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'none';
    }

    function allowDropToPlan(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
    }

    function handleDropOnPlan(event) {
        event.preventDefault();

        var payload = null;
        try {
            var json = event.dataTransfer.getData('application/json');
            payload = JSON.parse(json);
        } catch (error) {
            var id = event.dataTransfer.getData('text/plain');
            if (id) {
                payload = { productId: parseInt(id, 10) };
            }
        }

        if (! payload || ! payload.productId) {
            return;
        }

        var product = findProduct(parseInt(payload.productId, 10));
        if (! product) {
            return;
        }

        openComposer(product);
    }

    function openComposer(product) {
        var limits = getProductLimits(product);
        var composerQuantity = clampQuantity(limits.default, limits);
        var defaultDuration = product.duration && product.duration.minutes
            ? product.duration.minutes
            : getTimeStepMinutes();

        if (composerPricingController && typeof composerPricingController.abort === 'function') {
            composerPricingController.abort();
            composerPricingController = null;
        }
        if (composerPricingTimer) {
            clearTimeout(composerPricingTimer);
            composerPricingTimer = null;
        }

        state.composer = {
            productId: product.id,
            start: defaultComposerStart(),
            duration: defaultDuration,
            resource: '',
            quantity: composerQuantity,
            channel: '',
            notes: '',
            pricing_status: 'idle',
            pricing_total: null,
            pricing_currency: getPlannerCurrency(),
            pricing_formatted_total: null,
            pricing_unit: null,
            pricing_formatted_unit: null,
            pricing_breakdown: null,
        };

        scheduleComposerPricingUpdate();
        render();
    }

    function openComboDeal(productId) {
        ensureProductLoaded(productId).then(function (comboProduct) {
            if (comboProduct) {
                openComposer(comboProduct);
            }
        });
    }

    function closeComposer() {
        if (composerPricingController && typeof composerPricingController.abort === 'function') {
            composerPricingController.abort();
            composerPricingController = null;
        }
        if (composerPricingTimer) {
            clearTimeout(composerPricingTimer);
            composerPricingTimer = null;
        }
        state.composer = null;
        render();
    }

    function commitComposer() {
        if (! state.composer) {
            return;
        }

        var composer = state.composer;
        var product = findProduct(composer.productId);
        if (! product) {
            return;
        }

        var startIso = toIso(composer.start);
        var item = {
            uid: generateUid(state.itinerary.length + 1),
            product_id: product.id,
            product_name: product.name,
            resource: composer.resource,
            resource_label: lookupResourceLabel(product, composer.resource),
            channel: composer.channel,
            start: startIso,
            end: computeEnd(startIso, composer.duration),
            duration_minutes: composer.duration,
            quantity: composer.quantity,
            capacity: composer.quantity,
            buffer_before: 0,
            buffer_after: 0,
            notes: composer.notes,
        };

        if (composer.pricing_status === 'ready' && typeof composer.pricing_total === 'number') {
            item.pricing_status = 'ready';
            item.pricing_total = composer.pricing_total;
            item.pricing_currency = composer.pricing_currency || getPlannerCurrency();
            item.pricing_formatted_total = composer.pricing_formatted_total || formatMoney(composer.pricing_total, item.pricing_currency);
            item.pricing_unit = composer.pricing_unit;
            item.pricing_formatted_unit = composer.pricing_formatted_unit;
            item.pricing_breakdown = composer.pricing_breakdown || null;
        } else {
            item.pricing_status = null;
            item.pricing_total = null;
            item.pricing_currency = getPlannerCurrency();
            item.pricing_formatted_total = null;
            item.pricing_unit = null;
            item.pricing_formatted_unit = null;
            item.pricing_breakdown = null;
        }

        state.itinerary.push(item);
        scheduleItineraryPricing(item);

        if (composerPricingController && typeof composerPricingController.abort === 'function') {
            composerPricingController.abort();
            composerPricingController = null;
        }
        if (composerPricingTimer) {
            clearTimeout(composerPricingTimer);
            composerPricingTimer = null;
        }

        state.composer = null;
        onItineraryChange();
        render();
    }

    function onItineraryChange() {
        normalizeItinerary();
        state.dirty = true;
        scheduleAutosave();
        scheduleItinerary();
    }

    function touchScenarioMeta() {
        state.dirty = true;
        scheduleAutosave();
    }

    function scheduleAutosave() {
        if (state.saveTimer) {
            clearTimeout(state.saveTimer);
        }

        state.saveTimer = setTimeout(saveScenario, 1200);
        render();
    }
    function saveScenario() {
        if (! state.dirty) {
            return;
        }

        state.saving = true;
        render();

        var payload = buildScenarioPayload();

        fetchJson(endpoints.state, {
            method: 'POST',
            body: JSON.stringify(payload),
        })
            .then(function (response) {
                state.dirty = false;
                state.saving = false;
                state.error = null;
                state.lastSavedAt = new Date().toISOString();

                if (response) {
                    if (response.scenario_id) {
                        state.scenarioId = response.scenario_id;
                    }
                    if (Array.isArray(response.scenarios)) {
                        state.scenarios = response.scenarios;
                    }
                    if (response.scenario) {
                        applyScenario(response);
                    }
                }

                render();
            })
            .catch(function (error) {
                state.saving = false;
                state.error = error && error.message ? error.message : 'Opslaan mislukt.';
                render();
            });
    }

    function buildScenarioPayload() {
        return {
            scenario_id: state.scenarioId,
            label: state.scenarioMeta.label,
            customer: state.scenarioMeta.customer,
            window: state.scenarioMeta.window,
            notes: state.scenarioMeta.notes,
            is_primary: state.scenarioMeta.is_primary,
            itinerary: state.itinerary.map(function (item) {
                return {
                    uid: item.uid,
                    product_id: item.product_id,
                    product_name: item.product_name,
                    resource: item.resource,
                    resource_label: item.resource_label,
                    channel: item.channel,
                    start: item.start,
                    end: item.end,
                    duration_minutes: item.duration_minutes,
                    quantity: item.quantity,
                    capacity: item.capacity,
                    buffer_before: item.buffer_before,
                    buffer_after: item.buffer_after,
                    notes: item.notes,
                    time: item.start,
                };
            }),
        };
    }

    function scheduleItinerary(options) {
        var immediate = options && options.immediate;

        if (state.scheduleTimer) {
            clearTimeout(state.scheduleTimer);
            state.scheduleTimer = null;
        }

        if (immediate) {
            performScheduleUpdate();
            return;
        }

        state.scheduleTimer = setTimeout(performScheduleUpdate, 220);
    }

    function performScheduleUpdate() {
        state.scheduleTimer = null;

        if (! state.itinerary.length) {
            state.timeline = [];
            state.windows = [];
            state.calendar = [];
            state.conflicts = [];
            rebuildConflictIndexMap();
            state.scheduling = false;
            render();
            return;
        }

        state.scheduling = true;
        render();

        fetchJson(endpoints.schedule, {
            method: 'POST',
            body: JSON.stringify({
                bookings: state.itinerary.map(function (item) {
                    return {
                        label: item.product_name,
                        resource: item.resource,
                        resource_label: item.resource_label,
                        time: item.start,
                        start: item.start,
                        end: item.end,
                        duration: item.duration_minutes,
                        quantity: item.quantity,
                        buffer_before: item.buffer_before,
                        buffer_after: item.buffer_after,
                        channel: item.channel,
                    };
                }),
            }),
        })
            .then(function (response) {
                state.timeline = response.timeline || [];
                state.windows = response.windows || [];
                state.calendar = buildCalendarBuckets(state.windows);
                state.conflicts = response.conflicts || [];
                rebuildConflictIndexMap();
                state.scheduling = false;
                render();
            })
            .catch(function (error) {
                state.error = error && error.message ? error.message : 'Kon planning niet vernieuwen.';
                state.scheduling = false;
                render();
            });
    }

    function loadScenario(scenarioId) {
        fetchJson(endpoints.state + '?scenario_id=' + encodeURIComponent(scenarioId))
            .then(function (response) {
                applyScenario(response);
                state.scenarioId = response.scenario_id || scenarioId;
                state.dirty = false;
                state.error = null;
                state.lastSavedAt = response.scenario && response.scenario.updated_at ? response.scenario.updated_at : state.lastSavedAt;
                render();
                scheduleItinerary({ immediate: true });
            })
            .catch(function (error) {
                state.error = error && error.message ? error.message : 'Kon scenario niet laden.';
                render();
            });
    }

    function createScenario() {
        var name = window.prompt('Naam voor nieuw scenario', '');
        if (name === null) {
            return;
        }

        name = name.trim();
        if (name === '') {
            name = 'Nieuw scenario';
        }

        var id = slugify(name) + '-' + Date.now();

        state.scenarioId = id;
        state.scenarioMeta = {
            label: name,
            customer: {
                name: '',
                email: '',
                phone: '',
                company: '',
            },
            window: {
                start: '',
                end: '',
            },
            notes: '',
            is_primary: false,
        };
        state.itinerary = [];
        state.timeline = [];
        state.windows = [];
        state.calendar = [];
        state.conflicts = [];
        state.conflictIndexMap = {};
        state.dirty = true;
        if (state.scheduleTimer) {
            clearTimeout(state.scheduleTimer);
            state.scheduleTimer = null;
        }

        state.scenarios.push({
            scenario_id: id,
            label: name,
            updated_at: new Date().toISOString(),
        });

        render();
        scheduleItinerary({ immediate: true });
    }

    function conflictTitle(conflict) {
        if (! conflict || ! conflict.type) {
            return 'Conflict';
        }

        switch (conflict.type) {
            case 'capacity':
                return 'Capaciteitsconflict';
            case 'time_overlap':
            default:
                return 'Tijdslijnconflict';
        }
    }

    function conflictDescription(conflict) {
        if (! conflict) {
            return '';
        }

        if (conflict.type === 'capacity') {
            return 'Bezetting (' + conflict.capacity_used + ') overschrijdt capaciteit (' + conflict.capacity_limit + ') voor resource ' + (conflict.resource_label || conflict.resource || '');
        }

        if (conflict.overlapping && conflict.overlapping.length) {
            var labels = conflict.overlapping.map(function (segment) {
                return (segment.label || 'activiteit') + ' (' + segment.start + ')';
            });
            return 'Overlap met: ' + labels.join(', ');
        }

        return 'Overlap gedetecteerd voor resource ' + (conflict.resource_label || conflict.resource || '');
    }
    function getProductLimits(product) {
        var min = 1;
        var max = null;
        var defaults = 1;

        if (product && product.people) {
            if (product.people.min && product.people.min > 0) {
                min = product.people.min;
            }
            if (product.people.max && product.people.max > 0) {
                max = product.people.max;
            }
            if (product.people.default && product.people.default > 0) {
                defaults = product.people.default;
            }
        }

        if (defaults < min) {
            defaults = min;
        }
        if (max !== null && defaults > max) {
            defaults = max;
        }

        return { min: min, max: max, default: defaults };
    }

    function clampQuantity(value, limits) {
        var hasMin = limits && typeof limits.min === 'number' && ! isNaN(limits.min);
        var hasDefault = limits && typeof limits.default === 'number' && ! isNaN(limits.default);
        var floor = hasMin ? limits.min : (hasDefault ? limits.default : 1);
        var numeric = parseInt(value, 10);
        if (isNaN(numeric) || numeric < floor) {
            numeric = floor;
        }

        if (limits && limits.max !== null && typeof limits.max === 'number' && ! isNaN(limits.max) && numeric > limits.max) {
            numeric = limits.max;
        }

        return numeric;
    }

    function buildCalendarBuckets(windows) {
        if (! Array.isArray(windows) || windows.length === 0) {
            return [];
        }

        var buckets = {};

        windows.forEach(function (window) {
            if (! window || ! window.start) {
                return;
            }

            var startDate = new Date(window.start);
            if (isNaN(startDate.getTime())) {
                return;
            }

            var endDate = window.end ? new Date(window.end) : new Date(window.start);
            if (isNaN(endDate.getTime())) {
                endDate = new Date(startDate.getTime() + 60 * 60000);
            }

            var dayKey = [
                startDate.getFullYear(),
                pad(startDate.getMonth() + 1),
                pad(startDate.getDate()),
            ].join('-');

            if (! buckets[dayKey]) {
                buckets[dayKey] = {
                    label: DAY_LABEL_FORMAT.format(startDate),
                    events: [],
                    min: 1440,
                    max: 0,
                };
            }

            var bucket = buckets[dayKey];
            var startMinutes = startDate.getHours() * 60 + startDate.getMinutes();
            var endMinutes = endDate.getHours() * 60 + endDate.getMinutes();

            if (endMinutes <= startMinutes) {
                endMinutes = startMinutes + 15;
            }

            bucket.min = Math.min(bucket.min, startMinutes);
            bucket.max = Math.max(bucket.max, endMinutes);

            bucket.events.push({
                startMinutes: startMinutes,
                endMinutes: endMinutes,
                label: window.label || '',
                resource: window.resource_label || window.resource || '',
            });
        });

        return Object.keys(buckets).sort().map(function (dayKey) {
            var bucket = buckets[dayKey];
            if (bucket.events.length === 0) {
                return null;
            }

            var rangeStart = Math.max(0, bucket.min - 30);
            var rangeEnd = Math.min(1440, bucket.max + 30);
            if (rangeEnd <= rangeStart) {
                rangeEnd = rangeStart + 60;
            }

            var duration = rangeEnd - rangeStart;
            if (duration <= 0) {
                duration = 60;
            }

            var markers = [];
            var firstHour = Math.floor(rangeStart / 60) * 60;
            var lastHour = Math.ceil(rangeEnd / 60) * 60;

            for (var minute = firstHour; minute <= lastHour; minute += 60) {
                var position = ((minute - rangeStart) / duration) * 100;
                if (position >= 0 && position <= 100) {
                    markers.push({
                        position: position,
                        label: formatTimeLabel(minute),
                    });
                }
            }

            var events = bucket.events.map(function (event) {
                var startOffset = event.startMinutes - rangeStart;
                var endOffset = event.endMinutes - rangeStart;
                var height = Math.max(4, ((endOffset - startOffset) / duration) * 100);

                return Object.assign({}, event, {
                    position: (startOffset / duration) * 100,
                    span: height,
                    time: formatWindowTime(event.startMinutes, event.endMinutes),
                });
            });

            return {
                label: bucket.label,
                events: events,
                markers: markers,
            };
        }).filter(Boolean);
    }

    function formatTimeLabel(minutes) {
        var hour = Math.floor(minutes / 60);
        var minute = minutes % 60;

        return pad(hour) + ':' + pad(minute);
    }

    function formatWindowTime(startMinutes, endMinutes) {
        return formatTimeLabel(startMinutes) + ' - ' + formatTimeLabel(endMinutes);
    }

    function initPrefillIntegration() {
        if (prefillInitialized) {
            return;
        }

        prefillInitialized = true;

        var stored = readPrefillQueue();
        for (var i = 0; i < stored.length; i += 1) {
            queuePrefill(stored[i]);
        }

        if (typeof window !== 'undefined') {
            window.addEventListener('sbdp:planner/prefill', function (event) {
                if (! event || ! event.detail) {
                    return;
                }

                queuePrefill(event.detail);
            });
        }
    }

    function readPrefillQueue() {
        if (typeof window === 'undefined' || typeof window.sessionStorage === 'undefined') {
            return [];
        }

        try {
            var raw = window.sessionStorage.getItem(PREFILL_SESSION_KEY);
            if (! raw) {
                return [];
            }

            var parsed = JSON.parse(raw);
            window.sessionStorage.removeItem(PREFILL_SESSION_KEY);

            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            console.warn('[Planner] Kon prefill-queue niet lezen', error);
            return [];
        }
    }

    function queuePrefill(rawEntry) {
        var entry = normalizePrefillEntry(rawEntry);
        if (! entry) {
            return;
        }

        pendingPrefillEntries.push(entry);
        processPendingPrefill();
    }

    function processPendingPrefill() {
        if (! state.ready) {
            return;
        }

        if (processingPrefillEntry) {
            return;
        }

        if (pendingPrefillEntries.length === 0) {
            return;
        }

        processingPrefillEntry = true;

        var entry = pendingPrefillEntries.shift();
        applyPrefillEntry(entry)
            .catch(function (error) {
                console.warn('[Planner] Prefill mislukt', error);
            })
            .finally(function () {
                processingPrefillEntry = false;
                processPendingPrefill();
            });
    }

    function normalizePrefillEntry(raw) {
        if (! raw || typeof raw !== 'object') {
            return null;
        }

        var productId = raw.product_id;
        if (productId === undefined) {
            productId = raw.productId !== undefined ? raw.productId : raw.id;
        }

        if (productId === undefined || productId === null || productId === '') {
            return null;
        }

        var numericProductId = parseInt(productId, 10);
        if (Number.isFinite(numericProductId) && numericProductId > 0) {
            productId = numericProductId;
        } else {
            productId = String(productId).trim();
        }

        if (productId === '' || productId === 0) {
            return null;
        }

        var date = typeof raw.date === 'string' ? raw.date.trim() : '';
        if (date && ! /^\d{4}-\d{2}-\d{2}$/.test(date)) {
            date = '';
        }

        var time = typeof raw.time === 'string' ? raw.time.trim() : '';
        if (time && ! /^\d{2}:\d{2}$/.test(time)) {
            time = '';
        }

        var quantity = null;
        if (raw.participants !== undefined && raw.participants !== null && raw.participants !== '') {
            var parsedQuantity = parseInt(raw.participants, 10);
            if (Number.isFinite(parsedQuantity) && parsedQuantity > 0) {
                quantity = parsedQuantity;
            }
        }

        var resourceId = raw.resource_id;
        if (resourceId === undefined) {
            resourceId = raw.resourceId;
        }

        if (resourceId !== undefined && resourceId !== null && resourceId !== '') {
            var parsedResourceId = parseInt(resourceId, 10);
            if (Number.isFinite(parsedResourceId) && parsedResourceId > 0) {
                resourceId = parsedResourceId;
            } else {
                resourceId = String(resourceId).trim();
            }
        } else {
            resourceId = null;
        }

        return {
            productId: productId,
            date: date,
            time: time,
            participants: quantity,
            resourceId: resourceId,
        };
    }

    function applyPrefillEntry(entry) {
        return ensureProductLoaded(entry.productId).then(function (product) {
            if (! product) {
                return;
            }

            var limits = getProductLimits(product);
            var quantity = entry.participants !== null ? entry.participants : limits.default;
            quantity = clampQuantity(quantity, limits);

            var resourceId = entry.resourceId;
            if (! resourceId && product.resources && product.resources.length) {
                resourceId = product.resources[0].id;
            }

            var startIso = buildPrefillStart(entry);
            if (itineraryHasEntry(product.id, startIso, resourceId)) {
                return;
            }

            var duration = product.duration && product.duration.minutes
                ? product.duration.minutes
                : getTimeStepMinutes();

            var itemPayload = {
                uid: generateUid(state.itinerary.length + 1),
                product_id: product.id,
                product_name: product.name,
                resource: resourceId ? String(resourceId) : '',
                resource_label: lookupResourceLabel(product, resourceId ? String(resourceId) : ''),
                channel: '',
                start: startIso,
                end: computeEnd(startIso, duration),
                duration_minutes: duration,
                quantity: quantity,
                capacity: quantity,
                buffer_before: 0,
                buffer_after: 0,
                notes: '',
            };

            var normalizedItem = normalizeItineraryItem(itemPayload, state.itinerary.length);
            state.itinerary.push(normalizedItem);
            scheduleItineraryPricing(normalizedItem);

            if (! state.scenarioMeta.window.start) {
                state.scenarioMeta.window.start = startIso;
            }

            if (! state.scenarioMeta.window.end || state.scenarioMeta.window.end < startIso) {
                state.scenarioMeta.window.end = startIso;
            }

            onItineraryChange();
            render();
        });
    }

    function ensureProductLoaded(productId) {
        var resolved = findProduct(productId);
        if (resolved) {
            return Promise.resolve(resolved);
        }

        var key = String(productId);
        if (productFetchPromises[key]) {
            return productFetchPromises[key];
        }

        var url = endpoints.products + '?ids=' + encodeURIComponent(key) + '&limit=1';
        var request = fetchJson(url)
            .then(function (response) {
                var products = response && Array.isArray(response.products) ? response.products : [];
                if (products.length === 0) {
                    throw new Error('Product ' + key + ' niet gevonden');
                }

                products.forEach(function (product) {
                    upsertProduct(product);
                });

                applyFilters(true);
                render();

                return findProduct(productId);
            })
            .finally(function () {
                delete productFetchPromises[key];
            });

        productFetchPromises[key] = request;

        return request;
    }

    function upsertProduct(product) {
        if (! product) {
            return;
        }

        var normalised = Object.assign({}, product, {
            outlets: Array.isArray(product.outlets) ? product.outlets : [],
            channels: Array.isArray(product.channels) ? product.channels : [],
            resources: product.resources && product.resources.items ? product.resources.items : (product.resources || []),
            combos: Array.isArray(product.combos) ? product.combos : [],
        });

        var key = String(normalised.id);
        var existingIndex = -1;

        for (var i = 0; i < state.products.length; i += 1) {
            if (String(state.products[i].id) === key) {
                existingIndex = i;
                break;
            }
        }

        if (! state.productIndex || typeof state.productIndex !== 'object') {
            state.productIndex = Object.create(null);
        }

        if (existingIndex >= 0) {
            state.products[existingIndex] = normalised;
            state.productIndex[key] = existingIndex;
        } else {
            state.products.push(normalised);
            existingIndex = state.products.length - 1;
            state.productIndex[key] = existingIndex;
        }

        state.productsVersion += 1;
    }

    function itineraryHasEntry(productId, startIso, resourceId) {
        var normalisedStart = sanitizeIso(startIso);

        return state.itinerary.some(function (item) {
            if (String(item.product_id) !== String(productId)) {
                return false;
            }

            var itemStart = sanitizeIso(item.start);
            if (normalisedStart && itemStart && itemStart !== normalisedStart) {
                return false;
            }

            if (resourceId && String(item.resource) !== String(resourceId)) {
                return false;
            }

            return true;
        });
    }

    function buildPrefillStart(entry) {
        var date = entry.date || extractDate(state.scenarioMeta.window.start) || getDefaultPlannerDate();
        var time = entry.time || extractTime(state.scenarioMeta.window.start) || getDefaultPlannerTime();

        return toIso(date + 'T' + time);
    }

    function getDefaultPlannerDate() {
        if (state.scenarioMeta && state.scenarioMeta.window) {
            var existing = extractDate(state.scenarioMeta.window.start);
            if (existing) {
                return existing;
            }
        }

        var now = new Date();

        return [
            now.getFullYear(),
            pad(now.getMonth() + 1),
            pad(now.getDate()),
        ].join('-');
    }

    function getDefaultPlannerTime() {
        if (state.scenarioMeta && state.scenarioMeta.window) {
            var existing = extractTime(state.scenarioMeta.window.start);
            if (existing) {
                return existing;
            }
        }

        if (state.config && state.config.open_hours && state.config.open_hours.start) {
            return state.config.open_hours.start;
        }

        return '09:00';
    }

    function findProduct(productId) {
        var key = String(productId);
        if (state.productIndex && Object.prototype.hasOwnProperty.call(state.productIndex, key)) {
            var index = state.productIndex[key];
            if (typeof index === 'number' && state.products[index] && String(state.products[index].id) === key) {
                return state.products[index];
            }
        }

        for (var i = 0; i < state.products.length; i += 1) {
            if (String(state.products[i].id) === key) {
                if (! state.productIndex || typeof state.productIndex !== 'object') {
                    state.productIndex = Object.create(null);
                }
                state.productIndex[key] = i;
                return state.products[i];
            }
        }

        return null;
    }

    function lookupResourceLabel(product, resourceId) {
        if (! product || ! product.resources) {
            return '';
        }

        var entry = product.resources.find(function (resource) {
            return String(resource.id) === String(resourceId);
        });

        return entry ? (entry.label || entry.name || String(entry.id)) : '';
    }

    function defaultComposerStart() {
        var now = new Date();
        var iso = toLocalInputValue(now.toISOString());
        return iso;
    }

    function computeEnd(startIso, durationMinutes) {
        if (! startIso) {
            return '';
        }

        var startDate = new Date(startIso);
        if (isNaN(startDate.getTime())) {
            return '';
        }

        var endDate = new Date(startDate.getTime() + durationMinutes * 60000);
        return formatLocalIso(endDate);
    }

    function sanitizeIso(value) {
        if (! value) {
            return '';
        }

        var date = new Date(value);
        if (isNaN(date.getTime())) {
            return value;
        }

        return formatLocalIso(date);
    }

    function toIso(value) {
        if (! value) {
            return '';
        }

        if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) {
            var exact = new Date(value);
            if (! isNaN(exact.getTime())) {
                return formatLocalIso(exact);
            }
            return value;
        }

        if (/^\d{2}:\d{2}$/.test(value)) {
            var base = new Date();
            var parts = value.split(':');
            base.setHours(parseInt(parts[0], 10), parseInt(parts[1], 10), 0, 0);
            return formatLocalIso(base);
        }

        var parsed = new Date(value);
        if (! isNaN(parsed.getTime())) {
            return formatLocalIso(parsed);
        }

        return value;
    }

    function toLocalInputValue(iso) {
        if (! iso) {
            return '';
        }

        if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(iso)) {
            return iso;
        }

        var date = new Date(iso);
        if (isNaN(date.getTime())) {
            return '';
        }

        return [
            date.getFullYear(),
            pad(date.getMonth() + 1),
            pad(date.getDate()),
        ].join('-') + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    function pad(value) {
        value = String(value);
        return value.length === 1 ? '0' + value : value;
    }

    function extractDate(value) {
        if (! value) {
            return '';
        }

        if (/^\d{4}-\d{2}-\d{2}/.test(value)) {
            return value.substring(0, 10);
        }

        var date = new Date(value);
        if (isNaN(date.getTime())) {
            return '';
        }

        return [
            date.getFullYear(),
            pad(date.getMonth() + 1),
            pad(date.getDate()),
        ].join('-');
    }

    function extractTime(value) {
        if (! value) {
            return '';
        }

        if (/T\d{2}:\d{2}/.test(value)) {
            return value.substring(value.indexOf('T') + 1, value.indexOf('T') + 6);
        }

        if (/^\d{2}:\d{2}$/.test(value)) {
            return value;
        }

        var date = new Date(value);
        if (isNaN(date.getTime())) {
            return '';
        }

        return pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    function formatLocalIso(date) {
        return [
            date.getFullYear(),
            pad(date.getMonth() + 1),
            pad(date.getDate()),
        ].join('-') + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + formatOffset(date);
    }

    function formatOffset(date) {
        var offsetMinutes = -date.getTimezoneOffset();
        var sign = offsetMinutes >= 0 ? '+' : '-';
        var abs = Math.abs(offsetMinutes);
        var hours = pad(Math.floor(abs / 60));
        var minutes = pad(abs % 60);
        return sign + hours + ':' + minutes;
    }

    function toPositiveInt(value, fallback) {
        var num = parseInt(value, 10);
        if (isNaN(num) || num <= 0) {
            return fallback;
        }

        return num;
    }

    function toCurrencyValue(value) {
        var numeric = parseFloat(value);
        return isNaN(numeric) ? 0 : numeric;
    }

    function formatDateTime(value) {
        if (! value) {
            return '';
        }

        var date = new Date(value);
        if (isNaN(date.getTime())) {
            return value;
        }

        return DATE_TIME_FORMAT.format(date);
    }

    function formatMoney(amount, currency) {
        return currency + ' ' + Number(amount || 0).toFixed(2);
    }

    function getPlannerCurrency() {
        if (state.config && state.config.currency) {
            return state.config.currency;
        }

        return 'EUR';
    }

    function normalizeResourceId(value) {
        if (value === null || value === undefined || value === '') {
            return 0;
        }

        var numeric = parseInt(value, 10);
        if (Number.isFinite(numeric) && numeric > 0) {
            return numeric;
        }

        return 0;
    }

    function buildPricingKey(productId, startIso, quantity, resourceId) {
        return [
            String(productId || ''),
            startIso || '',
            String(quantity || 0),
            String(resourceId || 0),
        ].join('|');
    }

    function quotePricing(productId, startIso, quantity, resourceId, controller) {
        if (! pricingEndpoint || ! productId || ! startIso) {
            return Promise.resolve(null);
        }

        var normalizedQuantity = parseInt(quantity, 10);
        if (! Number.isFinite(normalizedQuantity) || normalizedQuantity <= 0) {
            normalizedQuantity = 1;
        }

        var normalizedResourceId = normalizeResourceId(resourceId);
        var cacheKey = buildPricingKey(productId, startIso, normalizedQuantity, normalizedResourceId);
        if (pricingCache[cacheKey] && pricingCache[cacheKey].value) {
            return Promise.resolve(pricingCache[cacheKey].value);
        }

        if (pricingRequests[cacheKey]) {
            return pricingRequests[cacheKey];
        }

        var headers = {
            'Content-Type': 'application/json',
        };

        if (restNonce) {
            headers['X-WP-Nonce'] = restNonce;
        }

        var body = {
            product_id: parseInt(productId, 10),
            participants: normalizedQuantity,
            start: startIso,
            channel: 'day_planner',
        };

        if (normalizedResourceId > 0) {
            body.resource_id = normalizedResourceId;
        }

        if (publicNonce) {
            body.public_nonce = publicNonce;
        }

        var request = fetch(pricingEndpoint, {
            method: 'POST',
            headers: headers,
            credentials: 'same-origin',
            body: JSON.stringify(body),
            signal: controller && typeof controller.signal !== 'undefined' ? controller.signal : undefined,
        })
            .then(function (response) {
                if (! response.ok) {
                    return response.text().then(function (text) {
                        var message = text || response.statusText || 'pricing_preview_failed';
                        throw new Error(message);
                    });
                }

                return response.json();
            })
            .then(function (payload) {
                if (! payload || typeof payload !== 'object') {
                    return null;
                }

                var currency = typeof payload.currency === 'string' && payload.currency !== ''
                    ? payload.currency
                    : getPlannerCurrency();

                var unit = typeof payload.unit_price === 'number' ? payload.unit_price : null;
                var total = typeof payload.total === 'number' ? payload.total : null;
                if (total === null && unit !== null) {
                    total = unit * normalizedQuantity;
                }

                if (total === null) {
                    return null;
                }

                var formattedTotal = formatMoney(total, currency);
                var formattedUnit = unit !== null ? formatMoney(unit, currency) : null;

                var result = {
                    total: total,
                    unit: unit,
                    currency: currency,
                    formatted_total: formattedTotal,
                    formatted_unit: formattedUnit,
                    breakdown: payload,
                };

                pricingCache[cacheKey] = {
                    value: result,
                    timestamp: Date.now(),
                };

                return result;
            })
            .catch(function (error) {
                if (controller && controller.signal && controller.signal.aborted) {
                    return null;
                }

                console.warn('[Planner] Pricing quote failed', error);
                return null;
            })
            .finally(function () {
                delete pricingRequests[cacheKey];
            });

        pricingRequests[cacheKey] = request;

        return request;
    }

    function scheduleComposerPricingUpdate() {
        if (! state.composer) {
            return;
        }

        if (composerPricingTimer) {
            clearTimeout(composerPricingTimer);
        }

        composerPricingTimer = setTimeout(function () {
            composerPricingTimer = null;
            refreshComposerPricing();
        }, 200);
    }

    function refreshComposerPricing() {
        if (! state.composer) {
            return;
        }

        var product = findProduct(state.composer.productId);
        if (! product || ! pricingEndpoint) {
            state.composer.pricing_status = pricingEndpoint ? 'idle' : 'unavailable';
            return;
        }

        var startIso = toIso(state.composer.start);
        if (! startIso) {
            state.composer.pricing_status = 'pending';
            state.composer.pricing_total = null;
            state.composer.pricing_formatted_total = null;
            render();
            return;
        }

        var limits = getProductLimits(product);
        state.composer.quantity = clampQuantity(state.composer.quantity, limits);

        if (composerPricingController && typeof composerPricingController.abort === 'function') {
            composerPricingController.abort();
        }

        composerPricingController = typeof AbortController !== 'undefined' ? new AbortController() : null;
        state.composer.pricing_status = 'loading';
        render();

        quotePricing(
            product.id,
            startIso,
            state.composer.quantity,
            state.composer.resource,
            composerPricingController
        ).then(function (quote) {
            if (composerPricingController && composerPricingController.signal && composerPricingController.signal.aborted) {
                return;
            }

            if (! quote) {
                state.composer.pricing_status = 'error';
                state.composer.pricing_total = null;
                state.composer.pricing_currency = null;
                state.composer.pricing_formatted_total = null;
                state.composer.pricing_unit = null;
                state.composer.pricing_formatted_unit = null;
                state.composer.pricing_breakdown = null;
            } else {
                state.composer.pricing_status = 'ready';
                state.composer.pricing_total = quote.total;
                state.composer.pricing_currency = quote.currency;
                state.composer.pricing_formatted_total = quote.formatted_total;
                state.composer.pricing_unit = quote.unit;
                state.composer.pricing_formatted_unit = quote.formatted_unit;
                state.composer.pricing_breakdown = quote.breakdown;
            }

            render();
        }).catch(function () {
            if (composerPricingController && composerPricingController.signal && composerPricingController.signal.aborted) {
                return;
            }

            state.composer.pricing_status = 'error';
            state.composer.pricing_total = null;
            state.composer.pricing_currency = null;
            state.composer.pricing_formatted_total = null;
            state.composer.pricing_unit = null;
            state.composer.pricing_formatted_unit = null;
            state.composer.pricing_breakdown = null;
            render();
        }).finally(function () {
            composerPricingController = null;
        });
    }

    function renderComposerPricingSummary() {
        if (! state.composer) {
            return null;
        }

        var status = state.composer.pricing_status;
        if (! status || status === 'idle' || status === 'pending' || status === 'unavailable') {
            return null;
        }

        if (status === 'loading') {
            return el('div', { className: 'bpm-planner__composer-pricing' },
                el('span', { className: 'bpm-planner__composer-pricing-label' }, textNode('Prijsindicatie')),
                el('span', { className: 'bpm-planner__composer-pricing-meta' }, textNode(messageLookup('pricing_loading', 'Prijs wordt berekend.')))
            );
        }

        if (status === 'error') {
            return el('div', { className: 'bpm-planner__composer-pricing' },
                el('span', { className: 'bpm-planner__composer-pricing-label' }, textNode('Prijsindicatie')),
                el('span', { className: 'bpm-planner__composer-pricing-meta' }, textNode(messageLookup('pricing_unavailable', 'Prijs niet beschikbaar.')))
            );
        }

        if (status === 'ready' && state.composer.pricing_formatted_total) {
            var metaPieces = [];
            if (state.composer.pricing_formatted_unit) {
                metaPieces.push(state.composer.pricing_formatted_unit + ' per persoon');
            }
            metaPieces.push(state.composer.quantity + ' personen');

            return el('div', { className: 'bpm-planner__composer-pricing' },
                el('span', { className: 'bpm-planner__composer-pricing-label' }, textNode('Prijsindicatie')),
                el('span', { className: 'bpm-planner__composer-pricing-value' }, textNode(state.composer.pricing_formatted_total)),
                el('span', { className: 'bpm-planner__composer-pricing-meta' }, textNode(metaPieces.join(' \u00b7 ')))
            );
        }

        return null;
    }

    function scheduleItineraryPricing(item) {
        if (! item || ! item.uid) {
            return;
        }

        if (itineraryPricingTimers[item.uid]) {
            clearTimeout(itineraryPricingTimers[item.uid]);
        }

        itineraryPricingTimers[item.uid] = setTimeout(function () {
            delete itineraryPricingTimers[item.uid];
            refreshItineraryPricing(item);
        }, 200);
    }

    function refreshItineraryPricing(item) {
        if (! item || ! item.uid) {
            return;
        }

        var index = state.itinerary.indexOf(item);
        if (index === -1) {
            return;
        }

        var product = findProduct(item.product_id);
        if (! product || ! pricingEndpoint) {
            return;
        }

        var startIso = sanitizeIso(item.start);
        if (! startIso) {
            item.pricing_status = 'pending';
            state.itinerary[index] = item;
            render();
            return;
        }

        var limits = getProductLimits(product);
        var normalizedQuantity = clampQuantity(item.quantity, limits);
        if (normalizedQuantity !== item.quantity || item.capacity !== normalizedQuantity) {
            item.quantity = normalizedQuantity;
            item.capacity = normalizedQuantity;
        }

        if (pricingControllers[item.uid] && typeof pricingControllers[item.uid].abort === 'function') {
            pricingControllers[item.uid].abort();
        }

        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        if (controller) {
            pricingControllers[item.uid] = controller;
        }

        item.pricing_status = 'loading';
        state.itinerary[index] = item;
        render();

        quotePricing(
            item.product_id,
            startIso,
            item.quantity,
            item.resource,
            controller
        ).then(function (quote) {
            if (controller && controller.signal && controller.signal.aborted) {
                return;
            }

            if (! quote) {
                item.pricing_status = 'error';
                item.pricing_total = null;
                item.pricing_currency = null;
                item.pricing_formatted_total = null;
                item.pricing_unit = null;
                item.pricing_formatted_unit = null;
                item.pricing_breakdown = null;
            } else {
                item.pricing_status = 'ready';
                item.pricing_total = quote.total;
                item.pricing_currency = quote.currency;
                item.pricing_formatted_total = quote.formatted_total;
                item.pricing_unit = quote.unit;
                item.pricing_formatted_unit = quote.formatted_unit;
                item.pricing_breakdown = quote.breakdown;
            }

            state.itinerary[index] = item;
            render();
        }).catch(function () {
            if (controller && controller.signal && controller.signal.aborted) {
                return;
            }

            item.pricing_status = 'error';
            item.pricing_total = null;
            item.pricing_currency = null;
            item.pricing_formatted_total = null;
            item.pricing_unit = null;
            item.pricing_formatted_unit = null;
            item.pricing_breakdown = null;
            state.itinerary[index] = item;
            render();
        }).finally(function () {
            if (pricingControllers[item.uid] === controller) {
                delete pricingControllers[item.uid];
            }
        });
    }

    function clearItineraryPricing(item) {
        if (! item || ! item.uid) {
            return;
        }

        if (itineraryPricingTimers[item.uid]) {
            clearTimeout(itineraryPricingTimers[item.uid]);
            delete itineraryPricingTimers[item.uid];
        }

        if (pricingControllers[item.uid]) {
            try {
                pricingControllers[item.uid].abort();
            } catch (error) {
                // ignore
            }
            delete pricingControllers[item.uid];
        }
    }

    function getDisplayPrice(product) {
        if (! product || ! product.pricing) {
            return null;
        }

        var pricing = product.pricing;
        var currency = pricing.dynamic && pricing.dynamic.currency
            ? pricing.dynamic.currency
            : (pricing.currency
                || (state.config && state.config.currency)
                || (state.config && state.config.ui && state.config.ui.currency_symbol)
                || 'EUR');

        var dynamicTotal = null;
        if (pricing.dynamic && typeof pricing.dynamic === 'object') {
            if (typeof pricing.dynamic.total_adjusted === 'number') {
                dynamicTotal = toCurrencyValue(pricing.dynamic.total_adjusted);
            } else if (typeof pricing.dynamic.total === 'number') {
                dynamicTotal = toCurrencyValue(pricing.dynamic.total);
            } else if (Array.isArray(pricing.dynamic.quotes)) {
                dynamicTotal = pricing.dynamic.quotes.reduce(function (sum, quote) {
                    var candidate = null;
                    if (quote && typeof quote === 'object') {
                        if (typeof quote.total_adjusted === 'number') {
                            candidate = quote.total_adjusted;
                        } else if (typeof quote.total === 'number') {
                            candidate = quote.total;
                        } else if (typeof quote.adjusted_price === 'number') {
                            candidate = quote.adjusted_price;
                        } else if (typeof quote.base_price === 'number') {
                            candidate = quote.base_price;
                        }
                    }
                    return sum + toCurrencyValue(candidate);
                }, 0);
            }
        }

        if (dynamicTotal !== null && dynamicTotal > 0) {
            return formatMoney(dynamicTotal, currency);
        }

        var limits = getProductLimits(product);
        var quantity = limits.default;
        var base = toCurrencyValue(pricing.base);
        var perPerson = toCurrencyValue(pricing.per_person);
        var fixed = toCurrencyValue(pricing.fixed_fee);
        var total = 0;

        if (pricing.supports_persons) {
            if (perPerson > 0) {
                total = perPerson * quantity;
            } else if (base > 0) {
                total = base * quantity;
            } else {
                total = 0;
            }
            total += fixed;
        } else {
            total = base + fixed;
        }

        if (total > 0) {
            return formatMoney(total, currency);
        }

        if (! pricing.supports_persons && base > 0) {
            return formatMoney(base, currency);
        }

        return null;
    }

    function slugify(value) {
        return value
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function generateUid(index) {
        return 'plan-' + Date.now() + '-' + index;
    }

    function capitalise(value) {
        if (! value) {
            return '';
        }

        return value.charAt(0).toUpperCase() + value.slice(1);
    }

    function el(tag, props) {
        var element = document.createElement(tag);
        var properties = props || {};
        var children = Array.prototype.slice.call(arguments, 2);

        Object.keys(properties).forEach(function (key) {
            var value = properties[key];
            if (value === null || value === undefined) {
                return;
            }

            if (key === 'className') {
                element.className = value;
            } else if (key === 'textContent') {
                element.textContent = value;
            } else if (key === 'html') {
                element.innerHTML = value;
            } else if (key === 'dataset') {
                Object.keys(value).forEach(function (dataKey) {
                    element.dataset[dataKey] = value[dataKey];
                });
            } else if (key.slice(0, 2) === 'on' && typeof value === 'function') {
                element.addEventListener(key.slice(2).toLowerCase(), value);
            } else if (key in element) {
                element[key] = value;
            } else {
                element.setAttribute(key, value);
            }
        });

        children.forEach(function (child) {
            if (child === null || child === undefined) {
                return;
            }

            if (Array.isArray(child)) {
                child.forEach(function (nested) {
                    if (nested !== null && nested !== undefined) {
                        element.appendChild(typeof nested === 'string' ? textNode(nested) : nested);
                    }
                });
            } else {
                element.appendChild(typeof child === 'string' ? textNode(child) : child);
            }
        });

        return element;
    }

    function textNode(value) {
        return document.createTextNode(value === undefined || value === null ? '' : String(value));
    }

    initPrefillIntegration();
    loadInitial();
})();

