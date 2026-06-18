(function () {
    'use strict';

    var settings = window.SBDP_ProductSummarySettings || {};
    var cards = document.querySelectorAll('[data-sbdp-summary]');
    var PREFILL_QUEUE_KEY = 'sbdpPlannerPrefillQueue';
    var dayPlannerHelpers =
        typeof window !== 'undefined' && window.SBDP_DAY_PLANNER_HELPERS
            ? window.SBDP_DAY_PLANNER_HELPERS
            : {};
    if (!document.getElementById('sbdp-summary-feedback-styles')) {
        var style = document.createElement('style');
        style.id = 'sbdp-summary-feedback-styles';
        style.textContent =
            '.sbdp-summary__feedback{margin-top:8px;padding:8px 10px;border-radius:8px;background:#f7f9fb;border:1px solid #d9e3ec;font-size:13px;}' +
            '.sbdp-summary__feedback--warning{background:#fff6e5;border-color:#f7d488;}' +
            '.sbdp-summary__feedback--error{background:#fdecee;border-color:#f5a5ad;}' +
            '.sbdp-summary__feedback--success{background:#e6f7ef;border-color:#9bd5b3;}';
        document.head.appendChild(style);
    }
    if (!cards.length) {
        return;
    }

    var controllerMap = new WeakMap();
    var eliioControllerMap = new WeakMap();
    var eliioRequestMap = new WeakMap();

    cards.forEach(function (card) {
        initialiseCard(card);
    });

    function initialiseCard(card) {
        var config = parseConfig(card);
        if (!config || !config.productId) {
            return;
        }

        var dateInput = card.querySelector('[data-sbdp-summary-date]');
        var timeInput = card.querySelector('[data-sbdp-summary-time]');
        var participantsInput = card.querySelector('[data-sbdp-summary-participants]');
        var resourceSelect = card.querySelector('[data-sbdp-summary-resource]');
        var resourceInput = card.querySelector('[data-sbdp-summary-resource-input]');
        var combiInput = card.querySelector('[data-sbdp-summary-combi]');
        var combiLabelInput = card.querySelector('[data-sbdp-summary-combi-label]');
        var plannerInputHidden = card.querySelector('[data-sbdp-summary-planner-input]');
        var planItemHidden = card.querySelector('[data-sbdp-summary-plan-item]');
        var totalNode = card.querySelector('[data-sbdp-summary-total]');
        var baseNode = card.querySelector('[data-sbdp-summary-base]');
        var perPersonNode = card.querySelector('[data-sbdp-summary-per-person]');
        var breakdown = card.querySelector('[data-sbdp-summary-breakdown]');
        var bookButton = card.querySelector('[data-sbdp-summary-book]');
        var planButton = card.querySelector('[data-sbdp-summary-plan]');
        var sticky = document.querySelector('[data-sbdp-sticky][data-product-id="' + config.productId + '"]');
        var stickyBook = sticky ? sticky.querySelector('[data-sbdp-sticky-book]') : null;
        var stickyTime = sticky ? sticky.querySelector('[data-sbdp-sticky-time]') : null;
        var stickyPeople = sticky ? sticky.querySelector('[data-sbdp-sticky-people]') : null;
        var stickyPrice = sticky ? sticky.querySelector('[data-sbdp-sticky-price]') : null;
        var feedback = ensureCardFeedback(card);
        var debugPanel = ensureCardDebug(card, config);
        var eliioProduct = isEliioProduct(config);

        if (!dateInput || !timeInput || !participantsInput || !totalNode || !bookButton) {
            return;
        }

        if (!eliioProduct) {
            hydrateTimeOptions(config, timeInput);
        }
        applyDefaults(config, dateInput, timeInput, participantsInput);
        ensureSelectedTime(config, timeInput);
        if (resourceSelect && resourceInput) {
            resourceInput.value = resourceSelect.value || config.resourceDefault || '';
        }

        var form = card;
        form.addEventListener('submit', function (event) {
            if (eliioProduct) {
                event.preventDefault();
                forceRequestOnly(bookButton, stickyBook);
                showCardFeedback(feedback, getEliioMessage('unknown'), 'warning');
                return;
            }
            if (!readyToQuote(dateInput, timeInput, participantsInput)) {
                event.preventDefault();
        showCardFeedback(feedback, settings.strings ? settings.strings.selectDate : 'Completeer de velden.', 'warning');
                return;
            }
            showCardFeedback(feedback, '', '');
        });

        attachChangeHandler(dateInput, function () {
            refreshTimeSlots(config, dateInput, timeInput, resourceSelect);
            onFormChange();
        });
        attachChangeHandler(timeInput, onFormChange);
        attachChangeHandler(participantsInput, onFormChange);
        if (resourceSelect) {
            attachChangeHandler(resourceSelect, onResourceChange);
        }
        if (combiInput) {
            attachChangeHandler(combiInput, onCombiChange);
            onCombiChange();
        }

        if (planButton) {
            planButton.addEventListener('click', function (event) {
                event.preventDefault();
                handlePlan(config, dateInput, timeInput, participantsInput, combiInput, combiLabelInput, feedback, resourceInput);
            });
        }

        if (stickyBook) {
            stickyBook.addEventListener('click', function () {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(bookButton);
                } else {
                    bookButton.click();
                }
            });
        }

        onFormChange();

        function onFormChange() {
            if (eliioProduct && !hasValidParticipants(participantsInput)) {
                clearEliioAvailabilityForMissingParticipants(feedback);
                forceRequestOnly(bookButton, stickyBook);
                hideSticky(sticky, stickyTime, stickyPeople, stickyPrice);
                showCardFeedback(feedback, settings.strings ? settings.strings.eliioSelectParticipants : 'Vul eerst het aantal deelnemers in.', 'warning');
                return;
            }

            sanitiseParticipants(participantsInput);
            if (resourceSelect && resourceInput) {
                resourceInput.value = resourceSelect.value || '';
            }

            if (eliioProduct) {
                forceRequestOnly(bookButton, stickyBook);
                refreshEliioAvailability(config, dateInput, timeInput, participantsInput, feedback);

                if (!readyToQuote(dateInput, timeInput, participantsInput)) {
                    totalNode.textContent = '-';
                    hideSticky(sticky, stickyTime, stickyPeople, stickyPrice);
                    return;
                }

                refreshPricing(config, dateInput, timeInput, participantsInput, totalNode, baseNode, perPersonNode, breakdown, sticky, stickyTime, stickyPeople, stickyPrice, resourceInput, combiInput, plannerInputHidden, planItemHidden);
                forceRequestOnly(bookButton, stickyBook);
                return;
            }

            if (!readyToQuote(dateInput, timeInput, participantsInput)) {
                totalNode.textContent = '-';
                bookButton.disabled = true;
                hideSticky(sticky, stickyTime, stickyPeople, stickyPrice);
                showCardFeedback(feedback, settings.strings ? settings.strings.selectDate : 'Completeer de velden.', 'info');
                return;
            }

            bookButton.disabled = false;
            showCardFeedback(feedback, '', '');
            refreshPricing(config, dateInput, timeInput, participantsInput, totalNode, baseNode, perPersonNode, breakdown, sticky, stickyTime, stickyPeople, stickyPrice, resourceInput, combiInput, plannerInputHidden, planItemHidden);
        }

        function onCombiChange() {
            syncCombiLabel(combiInput, combiLabelInput);
        }

        function onResourceChange() {
            if (resourceSelect && resourceInput) {
                resourceInput.value = resourceSelect.value || '';
            }
            if (!eliioProduct) {
                refreshTimeSlots(config, dateInput, timeInput, resourceSelect);
            }
        }

        if (!eliioProduct) {
            refreshTimeSlots(config, dateInput, timeInput, resourceSelect);
        }
    }

    function ensureCardFeedback(card) {
        var existing = card.querySelector('[data-sbdp-summary-feedback]');
        if (existing) {
            existing.setAttribute('role', 'status');
            existing.setAttribute('aria-live', 'polite');
            return existing;
        }
        var node = document.createElement('div');
        node.className = 'sbdp-summary__feedback';
        node.setAttribute('data-sbdp-summary-feedback', 'true');
        node.setAttribute('role', 'status');
        node.setAttribute('aria-live', 'polite');
        card.appendChild(node);
        return node;
    }

    function ensureCardDebug(card, config) {
        var showDebug = typeof window !== 'undefined' && window.location && window.location.search && window.location.search.indexOf('sbdp_summary_debug=1') >= 0;
        if (!showDebug) {
            return null;
        }
        var existing = card.querySelector('[data-sbdp-summary-debug]');
        if (existing) {
            updateCardDebug(existing, config);
            return existing;
        }
        var node = document.createElement('pre');
        node.className = 'sbdp-summary__debug';
        node.setAttribute('data-sbdp-summary-debug', 'true');
        node.style.fontSize = '11px';
        node.style.maxHeight = '200px';
        node.style.overflow = 'auto';
        node.style.marginTop = '10px';
        node.textContent = '';
        updateCardDebug(node, config);
        card.appendChild(node);
        return node;
    }

    function updateCardDebug(node, config) {
        if (!node || !config) {
            return;
        }
        try {
            node.textContent = JSON.stringify({
                resources: config.resources || [],
                resourceDefault: config.resourceDefault || null,
                config: config,
            }, null, 2);
        } catch (error) {
            node.textContent = 'Debug data unavailable';
        }
    }

    function showCardFeedback(node, text, tone) {
        if (!node) {
            return;
        }
        var base = 'sbdp-summary__feedback';
        node.className = base;
        if (tone) {
            node.classList.add(base + '--' + tone);
        }
        node.textContent = text || '';
        if (text) {
            node.setAttribute('aria-label', text);
        } else {
            node.removeAttribute('aria-label');
        }
    }

    function attachChangeHandler(input, handler) {
        input.addEventListener('change', handler);
        input.addEventListener('input', handler);
    }

    function parseConfig(card) {
        var raw = card.getAttribute('data-sbdp-summary-config');
        if (!raw) {
            return null;
        }

        try {
            var parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object') {
                return parsed;
            }
        } catch (error) {
            console.warn('[SBDP][Summary] Failed to parse config', error); // eslint-disable-line no-console
        }

        return null;
    }

    function applyDefaults(config, dateInput, timeInput, participantsInput) {
        var defaults = (config && config.defaults) || {};
        var constraints = (config && config.constraints) || {};
        var min = Number.isFinite(constraints.min) ? constraints.min : 1;
        var max = Number.isFinite(constraints.max) && constraints.max > 0 ? constraints.max : null;
        if (defaults.date && !dateInput.value) {
            dateInput.value = defaults.date;
        }
        if (defaults.time && timeInput instanceof HTMLSelectElement) {
            selectOption(timeInput, defaults.time);
        }
        if (defaults.participants && !participantsInput.value) {
            var preset = parseInt(defaults.participants, 10);
            if (!Number.isFinite(preset) || preset <= 0) {
                preset = min > 0 ? min : 1;
            }
            if (max !== null && preset > max) {
                preset = max;
            }
            participantsInput.value = String(preset);
        }
        if (participantsInput.min === '' || parseInt(participantsInput.min, 10) < min) {
            participantsInput.min = String(Math.max(1, min));
        }
        if (max !== null) {
            participantsInput.max = String(max);
        }
    }

    function hydrateTimeOptions(config, timeInput) {
        if (!(timeInput instanceof HTMLSelectElement)) {
            return;
        }

        var options = Array.isArray(config.timeSlots) ? config.timeSlots : [];
        if (!options.length) {
            return;
        }

        updateTimeOptions(timeInput, options.map(function (slot) {
            return { start: slot.start, end: slot.end || '' };
        }));
    }

    function updateTimeOptions(timeInput, slots) {
        if (!(timeInput instanceof HTMLSelectElement)) {
            return;
        }
        while (timeInput.firstChild) {
            timeInput.removeChild(timeInput.firstChild);
        }
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = settings.strings ? settings.strings.selectTime || 'Selecteer een tijd' : 'Selecteer een tijd';
        timeInput.appendChild(placeholder);

        if (!Array.isArray(slots) || slots.length === 0) {
            return;
        }

        slots.forEach(function (slot) {
            if (!slot || typeof slot.start !== 'string') {
                return;
            }
            var opt = document.createElement('option');
            opt.value = slot.start;
            opt.textContent = slot.end ? slot.start + ' - ' + slot.end : slot.start;
            timeInput.appendChild(opt);
        });
    }

    function parseTimeToMinutes(value) {
        if (!value || typeof value !== 'string') {
            return null;
        }
        var parts = value.split(':');
        if (parts.length < 2) {
            return null;
        }
        var hours = parseInt(parts[0], 10);
        var minutes = parseInt(parts[1], 10);
        if (!Number.isFinite(hours) || !Number.isFinite(minutes)) {
            return null;
        }
        return hours * 60 + minutes;
    }

    function minutesToTime(minutes) {
        if (!Number.isFinite(minutes)) {
            return '';
        }
        var safe = Math.max(0, Math.min(23 * 60 + 59, Math.round(minutes)));
        var hours = String(Math.floor(safe / 60)).padStart(2, '0');
        var mins = String(safe % 60).padStart(2, '0');
        return hours + ':' + mins;
    }

    function resolveSlotMinutes(slots) {
        if (!Array.isArray(slots) || slots.length === 0) {
            return 30;
        }
        var start = parseTimeToMinutes(slots[0].start);
        var end = parseTimeToMinutes(slots[0].end);
        if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) {
            return 30;
        }
        return end - start;
    }

    function filterSlotsByDuration(slots, durationMinutes) {
        if (!Array.isArray(slots) || slots.length === 0) {
            return [];
        }
        var slotMinutes = resolveSlotMinutes(slots);
        var required = Math.max(1, Math.ceil(durationMinutes / slotMinutes));
        var sorted = slots
            .map(function (slot) {
                return {
                    start: slot.start,
                    end: slot.end,
                    startMinutes: parseTimeToMinutes(slot.start)
                };
            })
            .filter(function (slot) { return Number.isFinite(slot.startMinutes); })
            .sort(function (a, b) { return a.startMinutes - b.startMinutes; });
        var startSet = new Set(sorted.map(function (slot) { return slot.startMinutes; }));
        var results = [];

        sorted.forEach(function (slot) {
            var ok = true;
            for (var step = 0; step < required; step += 1) {
                if (!startSet.has(slot.startMinutes + step * slotMinutes)) {
                    ok = false;
                    break;
                }
            }
            if (ok) {
                results.push({
                    start: slot.start,
                    end: minutesToTime(slot.startMinutes + durationMinutes)
                });
            }
        });

        return results;
    }

    function fetchAvailableSlots(config, dateValue, resourceId) {
        if (!settings.availabilityUrl || !config || !config.productId || !dateValue || !resourceId) {
            return Promise.resolve([]);
        }
        var url;
        try {
            url = new URL(settings.availabilityUrl, window.location.origin);
        } catch (error) {
            return Promise.resolve([]);
        }
        url.searchParams.set('product_id', String(config.productId));
        url.searchParams.set('date', dateValue);
        url.searchParams.set('resource_id', String(resourceId));

        var fetchOptions = {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        };
        if (settings.nonce) {
            fetchOptions.headers['X-WP-Nonce'] = settings.nonce;
            fetchOptions.headers['x-sbdp-nonce'] = settings.nonce;
        }

        return window.fetch(url.toString(), fetchOptions)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('slots_failed');
                }
                return response.json();
            })
            .then(function (payload) {
                var slots = payload && Array.isArray(payload.slots) ? payload.slots : [];
                var duration = parseInt(config.durationMinutes, 10);
                if (!Number.isFinite(duration) || duration <= 0) {
                    duration = 90;
                }
                return filterSlotsByDuration(slots, duration);
            })
            .catch(function () { return []; });
    }

    function refreshTimeSlots(config, dateInput, timeInput, resourceSelect) {
        if (isEliioProduct(config)) {
            return;
        }
        if (!resourceSelect || !settings.availabilityUrl) {
            return;
        }
        var dateValue = dateInput ? dateInput.value : '';
        var resourceId = resourceSelect.value || '';
        if (!dateValue || !resourceId) {
            updateTimeOptions(timeInput, []);
            return;
        }
        fetchAvailableSlots(config, dateValue, resourceId).then(function (slots) {
            updateTimeOptions(timeInput, slots);
            ensureSelectedTime(config, timeInput);
            if (timeInput) {
                var event = new Event('change', { bubbles: true });
                timeInput.dispatchEvent(event);
            }
        });
    }
    function optionExists(select, value) {
        for (var i = 0; i < select.options.length; i += 1) {
            if (select.options[i].value === value) {
                return true;
            }
        }
        return false;
    }

    function selectOption(select, value) {
        for (var i = 0; i < select.options.length; i += 1) {
            if (select.options[i].value === value) {
                select.selectedIndex = i;
                return;
            }
        }
    }

    function readyToQuote(dateInput, timeInput, participantsInput) {
        var date = dateInput.value;
        var time = timeInput.value;
        var participants = parseInt(participantsInput.value, 10);
        return Boolean(date && time && participants > 0);
    }

    function hasValidParticipants(participantsInput) {
        if (!participantsInput || String(participantsInput.value || '').trim() === '') {
            return false;
        }
        var participants = parseInt(participantsInput.value, 10);
        return Number.isFinite(participants) && participants > 0;
    }

    function sanitiseParticipants(input) {
        var min = parseInt(input.min, 10);
        var max = parseInt(input.max, 10);
        var value = parseInt(input.value, 10);

        if (!Number.isFinite(min) || min <= 0) {
            min = 1;
        }

        if (!Number.isFinite(value) || value < min) {
            value = min;
        }

        if (Number.isFinite(max) && max > 0 && value > max) {
            value = max;
        }

        input.value = String(value);
    }

    function hideSticky(sticky, stickyTime, stickyPeople, stickyPrice) {
        if (!sticky) {
            return;
        }
        sticky.setAttribute('hidden', 'hidden');
        if (stickyTime) {
            stickyTime.textContent = '--:--';
        }
        if (stickyPeople) {
            stickyPeople.textContent = '0';
        }
        if (stickyPrice) {
            stickyPrice.textContent = '—';
        }
    }

    function forceRequestOnly(bookButton, stickyBook) {
        if (bookButton) {
            bookButton.disabled = true;
            bookButton.setAttribute('aria-disabled', 'true');
        }
        if (stickyBook) {
            stickyBook.disabled = true;
            stickyBook.setAttribute('aria-disabled', 'true');
        }
    }

    function isEliioProduct(config) {
        var supplier = config && config.supplier && typeof config.supplier === 'object' ? config.supplier : {};
        var provider = typeof supplier.provider === 'string' ? supplier.provider.toLowerCase() : '';
        var productId = parseInt(config && config.productId, 10);
        return provider === 'eliio' || productId === 115;
    }

    function getEliioMessage(status) {
        var strings = settings.strings || {};
        if (status === 'available') {
            return strings.eliioAvailable || 'Beschikbaarheidscheck geslaagd. Definitieve bevestiging volgt via de aanbieder.';
        }
        if (status === 'unavailable') {
            return strings.eliioUnavailable || 'Niet beschikbaar voor dit aantal personen. Kies een ander tijdstip of vraag een alternatief aan.';
        }
        if (status === 'error') {
            return strings.eliioError || 'Beschikbaarheid kan nu niet live gecontroleerd worden. Wij controleren dit handmatig.';
        }
        return strings.eliioUnknown || 'Beschikbaarheid kan nog niet live gecontroleerd worden.';
    }

    function refreshEliioAvailability(config, dateInput, timeInput, participantsInput, feedback) {
        if (!settings.eliioAvailabilityUrl || !config || !config.productId || !dateInput || !dateInput.value || !hasValidParticipants(participantsInput)) {
            clearEliioAvailabilityForMissingParticipants(feedback);
            return;
        }

        var url;
        try {
            url = new URL(settings.eliioAvailabilityUrl, window.location.origin);
        } catch (error) {
            showCardFeedback(feedback, getEliioMessage('error'), 'error');
            return;
        }

        var participants = parseInt(participantsInput.value, 10);
        abortExistingEliio(feedback);
        var requestId = (eliioRequestMap.get(feedback) || 0) + 1;
        eliioRequestMap.set(feedback, requestId);
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        url.searchParams.set('product_id', String(config.productId));
        url.searchParams.set('date', dateInput.value);
        url.searchParams.set('participants', String(participants));
        if (timeInput && timeInput.value) {
            url.searchParams.set('start_time', timeInput.value);
        }

        var options = {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        };
        if (controller) {
            eliioControllerMap.set(feedback, controller);
            options.signal = controller.signal;
        }

        window.fetch(url.toString(), options)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('eliio_availability_failed');
                }
                return response.json();
            })
            .then(function (payload) {
                if ((eliioRequestMap.get(feedback) || 0) !== requestId || !hasValidParticipants(participantsInput) || parseInt(participantsInput.value, 10) !== participants) {
                    return;
                }
                var status = payload && typeof payload.status === 'string' ? payload.status : 'unknown';
                var tone = status === 'available' ? 'success' : (status === 'unavailable' ? 'warning' : 'error');
                showCardFeedback(feedback, getEliioMessage(status), tone);
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                if ((eliioRequestMap.get(feedback) || 0) !== requestId) {
                    return;
                }
                showCardFeedback(feedback, getEliioMessage('error'), 'error');
            });
    }

    function abortExistingEliio(key) {
        var controller = eliioControllerMap.get(key);
        if (controller && typeof controller.abort === 'function') {
            controller.abort();
        }
    }

    function clearEliioAvailabilityForMissingParticipants(feedback) {
        if (!feedback) {
            return;
        }
        eliioRequestMap.set(feedback, (eliioRequestMap.get(feedback) || 0) + 1);
        abortExistingEliio(feedback);
        showCardFeedback(feedback, settings.strings ? settings.strings.eliioSelectParticipants : 'Vul eerst het aantal deelnemers in voor de beschikbaarheidscheck.', 'warning');
    }

    function ensureSelectedTime(config, timeInput) {
        if (!(timeInput instanceof HTMLSelectElement)) {
            return;
        }

        if (timeInput.value) {
            return;
        }

        var defaults = (config && config.defaults) || {};
        if (defaults.time) {
            selectOption(timeInput, defaults.time);
        }

        if (!timeInput.value && timeInput.options.length > 1) {
            timeInput.selectedIndex = 1;
        } else if (!timeInput.value && timeInput.options.length > 0) {
            timeInput.selectedIndex = 0;
        }
    }

    function refreshPricing(config, dateInput, timeInput, participantsInput, totalNode, baseNode, perPersonNode, breakdown, sticky, stickyTime, stickyPeople, stickyPrice, resourceInput, combiInput, plannerInputHidden, planItemHidden) {
        var productId = config.productId;
        var date = dateInput.value;
        var time = timeInput.value;
        var participants = parseInt(participantsInput.value, 10);
        var restUrl = settings.restUrl || '';

        if (!restUrl || !productId) {
            return;
        }

        totalNode.textContent = settings.strings ? settings.strings.loading || '...' : '...';

        if (sticky) {
            sticky.setAttribute('hidden', 'hidden');
        }

        abortExisting(totalNode);

        var url;
        try {
            url = new URL(restUrl, window.location.origin);
        } catch (error) {
            console.warn('[SBDP][Summary] Invalid REST url', error); // eslint-disable-line no-console
            totalNode.textContent = '—';
            return;
        }

        url.searchParams.set('product_id', String(productId));
        url.searchParams.set('date', date);
        url.searchParams.set('time', time);
        url.searchParams.set('participants', String(Math.max(1, participants)));
        if (resourceInput && resourceInput.value) {
            url.searchParams.set('resource_id', resourceInput.value);
        }
        if (combiInput && combiInput.value) {
            url.searchParams.set('combi_id', combiInput.value);
        }

        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var requestController = controller;
        if (controller) {
            controllerMap.set(totalNode, controller);
        }

        var fetchOptions = {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        };

        if (settings.nonce) {
            fetchOptions.headers['X-WP-Nonce'] = settings.nonce;
            fetchOptions.headers['x-sbdp-nonce'] = settings.nonce;
        }

        if (controller) {
            fetchOptions.signal = controller.signal;
        }

        var requestPromise = window.fetch(url.toString(), fetchOptions)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Price request failed');
                }
                return response.json();
            });

        requestPromise
            .then(function (payload) {
                if (!payload || typeof payload !== 'object') {
                    throw new Error('Empty payload');
                }
                var summary = payload.summary || {};
                var pricing = payload.pricing || {};
                var displayTotal = typeof pricing.display_total === 'number' && pricing.display_total > 0
                    ? pricing.display_total
                    : typeof summary.displayTotal === 'number' && summary.displayTotal > 0
                        ? summary.displayTotal
                        : typeof payload.display_total === 'number' && payload.display_total > 0
                            ? payload.display_total
                            : null;
                var formatted = typeof summary.totalFormatted === 'string' ? summary.totalFormatted : (typeof payload.formatted === 'string' ? payload.formatted : null);
                var totalRaw = displayTotal !== null
                    ? displayTotal
                    : typeof summary.total === 'number' ? summary.total : (typeof pricing.total === 'number' ? pricing.total : (typeof payload.total === 'number' ? payload.total : 0));
                var roundedTotal = roundUsingHelpers(totalRaw);
                var currency = typeof pricing.currency === 'string' ? pricing.currency : (typeof payload.currency === 'string' ? payload.currency : 'EUR');
                if (!formatted) {
                    formatted = formatUsingHelpers(roundedTotal, currency);
                }

                if (plannerInputHidden) {
                    plannerInputHidden.value = JSON.stringify(payload.normalizedInput || buildPlannerInput(config, dateInput, timeInput, participantsInput, resourceInput, combiInput));
                }
                if (planItemHidden) {
                    planItemHidden.value = JSON.stringify(payload.planItem || {});
                }

                totalNode.textContent = formatted || '-';
                updateBreakdown({
                    line_item: payload.line_item || { pricing: pricing },
                    pricing: pricing,
                    participants: payload.normalizedInput && payload.normalizedInput.participants ? payload.normalizedInput.participants : participants,
                    total: totalRaw,
                    currency: currency,
                }, baseNode, perPersonNode, breakdown, currency);
                if (sticky) {
                    sticky.removeAttribute('hidden');
                    if (stickyTime) {
                        stickyTime.textContent = time || '--:--';
                    }
                    if (stickyPeople) {
                        stickyPeople.textContent = participants > 0 ? participants + ' ' + pluralize(settings, participants) : '0';
                    }
                    if (stickyPrice) {
                        stickyPrice.textContent = formatted || '—';
                    }
                }
            })
            .catch(function (error) {
                if (isIgnorableFetchError(error, requestController)) {
                    return;
                }
                console.warn('[SBDP][Summary] Pricing failed', error); // eslint-disable-line no-console
                totalNode.textContent = settings.strings ? settings.strings.unavailable : '—';
                hideSticky(sticky, stickyTime, stickyPeople, stickyPrice);
            })
            .finally(function () {
                controllerMap.delete(totalNode);
            });
    }

    function abortExisting(key) {
        var controller = controllerMap.get(key);
        if (controller && typeof controller.abort === 'function') {
            controller.abort();
        }
    }

    function isIgnorableFetchError(error, controller) {
        if (controller && controller.signal && controller.signal.aborted) {
            return true;
        }
        if (!error) {
            return false;
        }
        if (error.name === 'AbortError') {
            return true;
        }
        if (error.name === 'TypeError') {
            var message = String(error.message || '');
            if (/NetworkError|Failed to fetch|Load failed/i.test(message)) {
                return true;
            }
        }
        return false;
    }

    function handlePlan(config, dateInput, timeInput, participantsInput, combiInput, combiLabelInput, feedback, resourceInput) {
        if (!readyToQuote(dateInput, timeInput, participantsInput)) {
            showCardFeedback(feedback, settings.strings ? settings.strings.selectDate : 'Completeer de velden.', 'warning');
            return;
        }

        var plannerUrl = config.plannerUrl || settings.plannerUrl || '';
        if (!plannerUrl) {
            showCardFeedback(feedback, settings.strings ? settings.strings.planError : 'Planner niet beschikbaar.', 'error');
            return;
        }

        persistPrefillForPlanner(config, dateInput, timeInput, participantsInput, combiInput, combiLabelInput, resourceInput);

        var url;
        try {
            url = new URL(plannerUrl, window.location.origin);
        } catch (error) {
            url = null;
        }

        if (!url) {
            window.location.href = plannerUrl;
            return;
        }

        url.searchParams.set('product_id', String(config.productId));
        url.searchParams.set('sbdp_date', dateInput.value);
        url.searchParams.set('sbdp_time', timeInput.value);
        url.searchParams.set('sbdp_participants', participantsInput.value);
        if (resourceInput && resourceInput.value) {
            url.searchParams.set('sbdp_resource', resourceInput.value);
        }
        if (combiInput && combiInput.value) {
            url.searchParams.set('sbdp_combi', combiInput.value);
        }
        if (combiLabelInput && combiLabelInput.value) {
            url.searchParams.set('sbdp_combi_label', combiLabelInput.value);
        }

        window.location.href = url.toString();
    }

    function formatCurrency(amount, currency) {
        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: currency || 'EUR'
            }).format(amount);
        } catch (error) {
            var symbol = currency === 'USD' ? '$' : '€';
            return symbol + amount.toFixed(2);
        }
    }


    function formatUsingHelpers(amount, currency) {
        if (typeof dayPlannerHelpers.formatCurrency === 'function') {
            return dayPlannerHelpers.formatCurrency(amount, currency);
        }

        return formatCurrency(amount, currency);
    }

    function roundUsingHelpers(value) {
        if (typeof dayPlannerHelpers.roundCurrency === 'function') {
            return dayPlannerHelpers.roundCurrency(value);
        }

        return value;
    }

    function readPrefillQueue() {
        if (typeof window === 'undefined' || typeof window.sessionStorage === 'undefined') {
            return [];
        }

        try {
            var raw = window.sessionStorage.getItem(PREFILL_QUEUE_KEY);
            var parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            console.warn('[SBDP][Summary] Failed to read planner prefill queue', error); // eslint-disable-line no-console
            return [];
        }
    }

    function writePrefillQueue(queue) {
        if (typeof window === 'undefined' || typeof window.sessionStorage === 'undefined') {
            return;
        }

        try {
            window.sessionStorage.setItem(PREFILL_QUEUE_KEY, JSON.stringify(queue || []));
        } catch (error) {
            console.warn('[SBDP][Summary] Failed to persist planner prefill queue', error); // eslint-disable-line no-console
        }
    }

    function enqueuePrefillEntry(entry) {
        if (!entry || typeof entry !== 'object') {
            return;
        }

        var queue = readPrefillQueue();
        queue.push(entry);
        writePrefillQueue(queue);

        if (typeof window !== 'undefined' && typeof window.dispatchEvent === 'function') {
            try {
                window.dispatchEvent(
                    new CustomEvent('sbdp:planner/prefill', {
                        detail: entry
                    })
                );
            } catch (error) {
                console.warn('[SBDP][Summary] Failed to dispatch planner prefill event', error); // eslint-disable-line no-console
            }
        }
    }

    function derivePricingUsingHelpers(pricing, participants, total) {
        if (
            typeof dayPlannerHelpers.deriveSlotPricing !== 'function' ||
            !Number.isFinite(participants) ||
            participants <= 0
        ) {
            return null;
        }

        try {
            return dayPlannerHelpers.deriveSlotPricing(pricing || {}, participants, {
                totalCost: typeof total === 'number' ? total : undefined,
            });
        } catch (error) {
            console.warn('[SBDP][Summary] Failed to derive slot pricing', error); // eslint-disable-line no-console
            return null;
        }
    }

    function buildPlannerInput(config, dateInput, timeInput, participantsInput, resourceInput, combiInput) {
        var plannerDomain = typeof window !== 'undefined' && window.SBDPPlannerDomain ? window.SBDPPlannerDomain : null;
        var base = {
            productId: config.productId,
            date: dateInput && dateInput.value ? dateInput.value : '',
            time: timeInput && timeInput.value ? timeInput.value : '',
            participants: participantsInput && participantsInput.value ? parseInt(participantsInput.value, 10) : 1,
            resourceId: resourceInput && resourceInput.value ? parseInt(resourceInput.value, 10) : 0,
            source: 'product-summary',
            options: {
                combiItems: [],
            },
        };

        if (combiInput && combiInput.value) {
            var selected = combiInput.options && combiInput.selectedIndex >= 0 ? combiInput.options[combiInput.selectedIndex] : null;
            base.options.combiItems.push({
                id: parseInt(combiInput.value, 10),
                label: selected && selected.getAttribute ? (selected.getAttribute('data-name') || selected.textContent || '').trim() : '',
                timing: 'before',
                duration: selected && selected.getAttribute ? parseInt(selected.getAttribute('data-duration') || '0', 10) : 0,
                durationMinutes: selected && selected.getAttribute ? parseInt(selected.getAttribute('data-duration') || '0', 10) : 0,
                supportsPersons: resolveCombiSupportsPersons(selected && selected.getAttribute ? selected.getAttribute('data-supports-persons') : ''),
            });
        }

        if (plannerDomain && typeof plannerDomain.normalizeInput === 'function') {
            return plannerDomain.normalizeInput(base);
        }

        return base;
    }

    function resolvePlannerWindow(config) {
        var openHours = settings && settings.plannerOpenHours && typeof settings.plannerOpenHours === 'object'
            ? settings.plannerOpenHours
            : {};
        var startMinutes = parseTimeToMinutes(openHours.start);
        var endMinutes = parseTimeToMinutes(openHours.end);

        if (!Number.isFinite(startMinutes)) {
            startMinutes = 8 * 60;
        }
        if (!Number.isFinite(endMinutes) || endMinutes <= startMinutes) {
            endMinutes = 22 * 60;
        }

        if (Array.isArray(config && config.timeSlots) && config.timeSlots.length > 0) {
            var slotStarts = config.timeSlots
                .map(function (slot) { return parseTimeToMinutes(slot && slot.start); })
                .filter(Number.isFinite);
            var slotEnds = config.timeSlots
                .map(function (slot) { return parseTimeToMinutes(slot && slot.end); })
                .filter(Number.isFinite);

            if (slotStarts.length > 0) {
                startMinutes = Math.min(startMinutes, Math.min.apply(Math, slotStarts));
            }
            if (slotEnds.length > 0) {
                endMinutes = Math.max(endMinutes, Math.max.apply(Math, slotEnds));
            }
        }

        return {
            startMinutes: startMinutes,
            endMinutes: endMinutes,
        };
    }

    function resolvePlannerAnchorTime(config, baseTime, combiItems) {
        var anchorMinutes = parseTimeToMinutes(baseTime);
        if (!Number.isFinite(anchorMinutes)) {
            return baseTime || '';
        }

        var window = resolvePlannerWindow(config);
        var mainDurationMinutes = parseInt(config && config.durationMinutes, 10);
        if (!Number.isFinite(mainDurationMinutes) || mainDurationMinutes <= 0) {
            mainDurationMinutes = 90;
        }

        var preferredBufferMinutes = 30;
        var items = Array.isArray(combiItems) ? combiItems : [];
        var totalBeforeMinutes = items
            .filter(function (item) { return item && item.timing === 'before'; })
            .reduce(function (total, item) {
                return total + (parseInt(item.durationMinutes || item.duration, 10) || 0);
            }, 0);
        var totalAfterMinutes = items
            .filter(function (item) { return item && item.timing === 'after'; })
            .reduce(function (total, item) {
                return total + (parseInt(item.durationMinutes || item.duration, 10) || 0);
            }, 0);

        var resolvedAnchorMinutes = anchorMinutes;
        var earliestAnchorMinutes = window.startMinutes + totalBeforeMinutes + preferredBufferMinutes;
        var latestAnchorMinutes = window.endMinutes - mainDurationMinutes - totalAfterMinutes - preferredBufferMinutes;

        if (Number.isFinite(earliestAnchorMinutes)) {
            resolvedAnchorMinutes = Math.max(resolvedAnchorMinutes, earliestAnchorMinutes);
        }
        if (Number.isFinite(latestAnchorMinutes) && latestAnchorMinutes >= window.startMinutes) {
            resolvedAnchorMinutes = Math.min(resolvedAnchorMinutes, latestAnchorMinutes);
        }

        return minutesToTime(resolvedAnchorMinutes);
    }

    function createPrefillEntry(config, dateInput, timeInput, participantsInput, combiInput, combiLabelInput, resourceInput) {
        if (!config || !config.productId) {
            return null;
        }

        var productId = parseInt(config.productId, 10);
        if (!Number.isFinite(productId) || productId <= 0) {
            return null;
        }

        var participants = parseInt(participantsInput.value, 10);
        var normalizedParticipants =
            Number.isFinite(participants) && participants > 0 ? participants : null;
        var timezoneOffsetMinutes = typeof Date === 'function' ? -new Date().getTimezoneOffset() : null;

        var selectedResourceId = resourceInput && resourceInput.value
            ? parseInt(resourceInput.value, 10)
            : (
                config.resourceId != null
                    ? parseInt(config.resourceId, 10)
                    : (
                        config.resource_id != null
                            ? parseInt(config.resource_id, 10)
                            : null
                    )
            );

        var entry = {
            source: 'product-summary',
            product_id: productId,
            productId: productId,
            date: dateInput.value || null,
            time: timeInput.value || null,
            participants: normalizedParticipants,
            people: normalizedParticipants,
            timezone_offset_minutes: timezoneOffsetMinutes,
            resource_id:
                Number.isFinite(selectedResourceId) && selectedResourceId > 0 ? selectedResourceId : null,
            lock_first_slot: config.lockFirstSlot !== false,
            append: true,
        };

        if (combiInput && combiInput.value) {
            entry.combi = combiInput.value;
        }
        if (combiLabelInput && combiLabelInput.value) {
            entry.combi_label = combiLabelInput.value;
        }

        var card = dateInput && dateInput.closest ? dateInput.closest('[data-sbdp-summary]') : null;
        var plannerInputHidden = card ? card.querySelector('[data-sbdp-summary-planner-input]') : null;
        var planItemHidden = card ? card.querySelector('[data-sbdp-summary-plan-item]') : null;
        if (plannerInputHidden && plannerInputHidden.value) {
            try {
                entry.plannerInput = JSON.parse(plannerInputHidden.value);
            } catch (error) {
                entry.plannerInput = null;
            }
        }
        if (planItemHidden && planItemHidden.value) {
            try {
                entry.planItem = JSON.parse(planItemHidden.value);
            } catch (error) {
                entry.planItem = null;
            }
        }

        var structuredCombiItems = [];
        if (entry.planItem && entry.planItem.options && Array.isArray(entry.planItem.options.combiItems)) {
            structuredCombiItems = entry.planItem.options.combiItems;
        } else if (entry.planItem && Array.isArray(entry.planItem.combiItems)) {
            structuredCombiItems = entry.planItem.combiItems;
        } else if (entry.plannerInput && entry.plannerInput.options && Array.isArray(entry.plannerInput.options.combiItems)) {
            structuredCombiItems = entry.plannerInput.options.combiItems;
        } else if (entry.plannerInput && Array.isArray(entry.plannerInput.combiItems)) {
            structuredCombiItems = entry.plannerInput.combiItems;
        } else if (combiInput && combiInput.value) {
            var selectedOption = combiInput.options && combiInput.selectedIndex >= 0 ? combiInput.options[combiInput.selectedIndex] : null;
            var rawDuration = selectedOption && selectedOption.getAttribute ? selectedOption.getAttribute('data-duration') : '';
            var parsedDuration = parseInt(rawDuration || '0', 10);
            structuredCombiItems = [{
                id: parseInt(combiInput.value, 10),
                label: selectedOption && selectedOption.getAttribute ? (selectedOption.getAttribute('data-name') || selectedOption.textContent || '').trim() : '',
                timing: 'before',
                duration: Number.isFinite(parsedDuration) && parsedDuration > 0 ? parsedDuration : null,
                durationMinutes: Number.isFinite(parsedDuration) && parsedDuration > 0 ? parsedDuration : null,
                supportsPersons: resolveCombiSupportsPersons(selectedOption && selectedOption.getAttribute ? selectedOption.getAttribute('data-supports-persons') : ''),
            }];
        }

        if (Array.isArray(structuredCombiItems) && structuredCombiItems.length > 0) {
            entry.combiItems = structuredCombiItems;
            entry.options = entry.options && typeof entry.options === 'object' ? entry.options : {};
            entry.options.combiItems = structuredCombiItems;
        }

        var normalizedPlannerTime = resolvePlannerAnchorTime(config, entry.time, structuredCombiItems);
        if (normalizedPlannerTime) {
            entry.time = normalizedPlannerTime;
        }

        if (entry.plannerInput && typeof entry.plannerInput === 'object') {
            entry.plannerInput = Object.assign({}, entry.plannerInput, {
                date: entry.date || entry.plannerInput.date || null,
                time: normalizedPlannerTime || entry.plannerInput.time || null,
                participants: normalizedParticipants || null,
                resource_id: entry.resource_id || entry.plannerInput.resource_id || null,
                resourceId: entry.resource_id || entry.plannerInput.resourceId || null,
                source: 'product-summary',
                options: Object.assign(
                    {},
                    entry.plannerInput.options && typeof entry.plannerInput.options === 'object'
                        ? entry.plannerInput.options
                        : {},
                    structuredCombiItems.length > 0 ? { combiItems: structuredCombiItems } : {}
                ),
            });
        }

        if (entry.planItem && typeof entry.planItem === 'object') {
            entry.planItem = Object.assign({}, entry.planItem, {
                date: entry.date || entry.planItem.date || null,
                startTime: normalizedPlannerTime || entry.planItem.startTime || entry.planItem.time || null,
                resourceId: entry.resource_id || entry.planItem.resourceId || entry.planItem.resource_id || null,
                resource_id: entry.resource_id || entry.planItem.resource_id || entry.planItem.resourceId || null,
                participants: normalizedParticipants || null,
                options: Object.assign(
                    {},
                    entry.planItem.options && typeof entry.planItem.options === 'object'
                        ? entry.planItem.options
                        : {},
                    structuredCombiItems.length > 0 ? { combiItems: structuredCombiItems } : {}
                ),
            });
        }

        return entry;
    }

    function persistPrefillForPlanner(config, dateInput, timeInput, participantsInput, combiInput, combiLabelInput, resourceInput) {
        var entry = createPrefillEntry(config, dateInput, timeInput, participantsInput, combiInput, combiLabelInput, resourceInput);
        if (!entry) {
            return;
        }

        enqueuePrefillEntry(entry);
    }

    function syncCombiLabel(combiInput, combiLabelInput) {
        if (!combiInput || !combiLabelInput) {
            return;
        }

        var label = '';
        if (combiInput instanceof HTMLSelectElement) {
            var selected = combiInput.options[combiInput.selectedIndex];
            label = selected && selected.textContent ? selected.textContent.trim() : '';
        }

        combiLabelInput.value = label;
    }

    function updateBreakdown(payload, baseNode, perPersonNode, breakdown, currency) {
        if (!breakdown) {
            return;
        }

        var lineItem = payload.line_item || {};
        var pricing = lineItem.pricing || {};
        var participantCount = Number.isFinite(lineItem.participants)
            ? lineItem.participants
            : Number.isFinite(payload.participants)
                ? payload.participants
                : null;
        var helperPricing = derivePricingUsingHelpers(
            pricing,
            participantCount !== null ? participantCount : 1,
            typeof payload.total === 'number' ? payload.total : null
        );
        var basePrice = typeof pricing.display_base_price === 'number' && pricing.display_base_price > 0
            ? pricing.display_base_price
            : helperPricing && helperPricing.fixedCost > 0
            ? helperPricing.fixedCost
            : typeof pricing.base_price === 'number'
                ? pricing.base_price
                : null;
        var perPerson = typeof pricing.display_per_person === 'number' && pricing.display_per_person > 0
            ? pricing.display_per_person
            : helperPricing && helperPricing.perPerson > 0
            ? helperPricing.perPerson
            : typeof pricing.per_person === 'number'
                ? pricing.per_person
                : null;
        if (baseNode) {
            baseNode.textContent =
                basePrice !== null ? formatUsingHelpers(roundUsingHelpers(basePrice), currency) : '-';
        }
        if (perPersonNode) {
            perPersonNode.textContent =
                perPerson !== null ? formatUsingHelpers(roundUsingHelpers(perPerson), currency) : '-';
        }

        if ((basePrice !== null && basePrice > 0) || (perPerson !== null && perPerson > 0)) {
            breakdown.removeAttribute('hidden');
        } else {
            breakdown.setAttribute('hidden', 'hidden');
        }
    }

    function pluralize(settings, participants) {
        var plural = settings.strings && settings.strings.participantsPlural
            ? settings.strings.participantsPlural
            : 'personen';
        var singular = settings.strings && settings.strings.participantsSingular
            ? settings.strings.participantsSingular
            : 'persoon';
        return participants === 1 ? singular : plural;
    }
})();
