(function () {
    'use strict';

    var storageKey = 'ddbPlannerItems';
    var addedLabel = (typeof window !== 'undefined' && window.DDB_CTA && window.DDB_CTA.addedLabel) ? String(window.DDB_CTA.addedLabel) : '+ Voeg toe aan Plan je dag';
    var selectTimeLabel = (typeof window !== 'undefined' && window.DDB_CTA && window.DDB_CTA.selectTimeLabel) ? String(window.DDB_CTA.selectTimeLabel) : 'Selecteer een starttijd';
    var selectParticipantsLabel = (typeof window !== 'undefined' && window.DDB_CTA && window.DDB_CTA.selectParticipantsLabel) ? String(window.DDB_CTA.selectParticipantsLabel) : 'Selecteer aantal personen';
    var noSlotsLabel = (typeof window !== 'undefined' && window.DDB_CTA && window.DDB_CTA.noSlotsLabel) ? String(window.DDB_CTA.noSlotsLabel) : 'Geen tijdsloten beschikbaar';
    var noCapacityLabel = (typeof window !== 'undefined' && window.DDB_CTA && window.DDB_CTA.noCapacityLabel) ? String(window.DDB_CTA.noCapacityLabel) : 'Geen capaciteit beschikbaar';
    var EN_DASH = '\u2014';

    var productForm = document.querySelector('[data-sbdp-product-form]');
    var productDateInput = productForm ? productForm.querySelector('input[name="sbdp_date"]') : null;
    var productTimeInput = productForm ? productForm.querySelector('input[name="sbdp_time"]') : null;
    var productParticipantsInput = productForm ? productForm.querySelector('input[name="sbdp_participants"]') : null;

    var CTA_SELECTOR = '.ddb-cta-block';
    var CTA_DETAILS_SELECTOR = '[data-ddb-cta-details]';
    var CTA_DATE_SELECTOR = '[data-ddb-cta-date]';
    var CTA_TIME_SELECTOR = '[data-ddb-cta-time]';
    var CTA_PARTICIPANTS_SELECTOR = '[data-ddb-cta-participants]';
    var PREFILL_SESSION_KEY = 'sbdpPlannerPrefillQueue';

    var syncScheduled = false;

    function pushSessionPrefill(entry) {
        if (typeof window === 'undefined' || typeof window.sessionStorage === 'undefined') {
            return;
        }

        try {
            var existingRaw = window.sessionStorage.getItem(PREFILL_SESSION_KEY);
            var queue = existingRaw ? JSON.parse(existingRaw) : [];
            if (!Array.isArray(queue)) {
                queue = [];
            }
            queue.push(entry);
            window.sessionStorage.setItem(PREFILL_SESSION_KEY, JSON.stringify(queue));
        } catch (err) {
            console.warn('[DDB][CTA] Failed to persist planner prefill queue', err);
        }
    }

    function broadcastPlannerPrefill(entry) {
        if (typeof window === 'undefined' || typeof window.CustomEvent !== 'function') {
            return;
        }

        try {
            window.dispatchEvent(new CustomEvent('sbdp:planner/prefill', { detail: entry }));
        } catch (err) {
            console.warn('[DDB][CTA] Failed to dispatch planner prefill event', err);
        }
    }

    function readPlanner() {
        try {
            var raw = window.localStorage.getItem(storageKey);
            if (!raw) {
                return [];
            }

            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (err) {
            console.warn('[DDB][CTA] Failed to read planner state', err);
            return [];
        }
    }

    function writePlanner(items) {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(items));
        } catch (err) {
            console.warn('[DDB][CTA] Failed to persist planner state', err);
        }
    }

    function normalizeDate(value) {
        if (!value) {
            return '';
        }
        var trimmed = String(value).trim();
        return /^\d{4}-\d{2}-\d{2}$/.test(trimmed) ? trimmed : '';
    }

    function normalizeTime(value) {
        if (!value) {
            return '';
        }
        var trimmed = String(value).trim();
        return /^\d{2}:\d{2}$/.test(trimmed) ? trimmed : '';
    }

    function normalizeParticipants(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        var parsed = parseInt(value, 10);
        if (isNaN(parsed) || parsed <= 0) {
            return null;
        }
        return parsed;
    }

    function buildPlannerTarget(baseUrl, details) {
        if (!baseUrl || typeof baseUrl !== 'string') {
            return '';
        }
        var trimmed = baseUrl.trim();
        if (trimmed === '') {
            return '';
        }
        var params = [];
        if (details && typeof details === 'object') {
            if (details.date) {
                params.push('sbdp_date=' + encodeURIComponent(details.date));
            }
            if (details.time) {
                params.push('sbdp_time=' + encodeURIComponent(details.time));
            }
            if (typeof details.participants === 'number' && details.participants > 0) {
                params.push('sbdp_participants=' + encodeURIComponent(String(details.participants)));
            }
        }
        if (params.length === 0) {
            return trimmed;
        }
        var separator = trimmed.indexOf('?') === -1 ? '?' : '&';
        return trimmed + separator + params.join('&');
    }

  function getCTAInputs(block) {
    var container = block.querySelector(CTA_DETAILS_SELECTOR);
    if (!container) {
      return {
        date: null,
                time: null,
                participants: null
            };
        }

        return {
            date: container.querySelector('[data-ddb-cta-input="date"]'),
            time: container.querySelector('[data-ddb-cta-select="time"]') || container.querySelector('[data-ddb-cta-input="time"]'),
            participants: container.querySelector('[data-ddb-cta-select="participants"]') || container.querySelector('[data-ddb-cta-input="participants"]')
        };
    }

    function copyInputAttributes(source, target, attributes) {
        if (!source || !target) {
            return;
        }

        for (var i = 0; i < attributes.length; i += 1) {
            var attr = attributes[i];
            if (!attr) {
                continue;
            }

            var value = source.getAttribute(attr);
            if (value !== null) {
                target.setAttribute(attr, value);
            } else {
                target.removeAttribute(attr);
            }
        }
    }

    function mirrorSelectOptions(source, target) {
        if (!target) {
            return;
        }

        if (!source || !source.options) {
            target.innerHTML = '';
            return;
        }

        var previous = target.value;
        target.innerHTML = source.innerHTML;

        if (previous && Array.prototype.some.call(target.options, function(option) { return option.value === previous; })) {
            target.value = previous;
        } else {
            target.value = source.value || '';
        }
    }

    function populateSelectWithOptions(select, options, placeholder, selectedValue) {
        if (!select) {
            return;
        }

        var doc = select.ownerDocument || document;
        var fragment = doc.createDocumentFragment();

        if (placeholder) {
            var placeholderOption = doc.createElement('option');
            placeholderOption.value = '';
            placeholderOption.textContent = placeholder;
            fragment.appendChild(placeholderOption);
        }

        var availableValues = {};

        if (Array.isArray(options)) {
            for (var i = 0; i < options.length; i += 1) {
                var option = options[i];
                if (!option || typeof option.value === 'undefined') {
                    continue;
                }
                var optionElement = doc.createElement('option');
                optionElement.value = option.value;
                optionElement.textContent = option.label || option.value;
                if (option.disabled) {
                    optionElement.disabled = true;
                }
                fragment.appendChild(optionElement);
                availableValues[option.value] = true;
            }
        }

        while (select.firstChild) {
            select.removeChild(select.firstChild);
        }
        select.appendChild(fragment);

        if (selectedValue && availableValues[selectedValue]) {
            select.value = selectedValue;
        } else if (select.options.length > 1) {
            select.selectedIndex = 1;
        } else if (select.options.length > 0) {
            select.selectedIndex = 0;
        }
    }

    function updateButtonContextFromInputs(block) {
        var button = block.querySelector('.ddb-add-to-plan');
        if (!button) {
            return {
                date: '',
                time: '',
                participants: null
            };
        }

        var inputs = getCTAInputs(block);
        var dateValue = inputs.date ? normalizeDate(inputs.date.value) : '';
        var timeValue = inputs.time ? normalizeTime(inputs.time.value) : '';
        var participantsValue = inputs.participants ? normalizeParticipants(inputs.participants.value) : null;

        if (dateValue) {
            button.setAttribute('data-date', dateValue);
        } else {
            button.removeAttribute('data-date');
        }

        if (timeValue) {
            button.setAttribute('data-time', timeValue);
        } else {
            button.removeAttribute('data-time');
        }

        if (typeof participantsValue === 'number' && participantsValue > 0) {
            button.setAttribute('data-participants', String(participantsValue));
        } else {
            button.removeAttribute('data-participants');
        }

        return {
            date: dateValue,
            time: timeValue,
            participants: participantsValue
        };
    }

    function bindCTAInput(input, type, block) {
        if (!input || input.getAttribute('data-ddb-cta-bound') === '1') {
            return;
        }

        input.setAttribute('data-ddb-cta-bound', '1');

        var commit = function () {
            var normalized;

            if (type === 'date') {
                normalized = normalizeDate(input.value) || '';
                if (input.value !== normalized) {
                    input.value = normalized;
                }
                if (productDateInput) {
                    productDateInput.value = normalized;
                    productDateInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            } else if (type === 'time') {
                normalized = normalizeTime(input.value) || '';
                if (input.tagName !== 'SELECT' && input.value !== normalized) {
                    input.value = normalized;
                }
                if (productTimeInput) {
                    productTimeInput.value = normalized;
                    productTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            } else if (type === 'participants') {
                var parsed = normalizeParticipants(input.value);
                var value = parsed === null ? '' : String(parsed);
                if (input.tagName !== 'SELECT' && input.value !== value) {
                    input.value = value;
                }
                if (productParticipantsInput) {
                    productParticipantsInput.value = value;
                    productParticipantsInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            updateButtonContextFromInputs(block);
            hydrateButtons();
        };

        if (input.tagName === 'SELECT') {
            input.addEventListener('change', commit);
        } else {
            input.addEventListener('change', commit);
            input.addEventListener('blur', commit);
            input.addEventListener('input', function () {
                updateButtonContextFromInputs(block);
            });
        }
    }

    function formatDateDisplay(value) {
        var normalized = normalizeDate(value);
        if (!normalized) {
            return EN_DASH;
        }
        var parts = normalized.split('-');
        if (parts.length !== 3) {
            return normalized;
        }
        return parts[2] + '-' + parts[1] + '-' + parts[0];
    }

    function formatTimeDisplay(value) {
        var normalized = normalizeTime(value);
        return normalized || EN_DASH;
    }

    function formatParticipantsDisplay(block, count) {
        if (typeof count !== 'number' || count <= 0) {
            return EN_DASH;
        }
        var single = block.getAttribute('data-label-participant-single') || 'persoon';
        var plural = block.getAttribute('data-label-participant-plural') || 'personen';
        return count + ' ' + (count === 1 ? single : plural);
    }

    function collectProductDetails() {
        return {
            date: normalizeDate(productDateInput ? productDateInput.value : ''),
            time: normalizeTime(productTimeInput ? productTimeInput.value : ''),
            participants: normalizeParticipants(productParticipantsInput ? productParticipantsInput.value : '')
        };
    }

    function applyProductOptions(detail) {
        var data = detail || {};
        var blocks = document.querySelectorAll(CTA_SELECTOR + '[data-requires-details="1"]');
        if (!blocks.length) {
            return;
        }

        var times = Array.isArray(data.times) ? data.times : [];
        var participants = Array.isArray(data.participants) ? data.participants : [];
        var placeholderTime = times.length > 0 ? selectTimeLabel : noSlotsLabel;
        var placeholderParticipants = participants.length > 0 ? selectParticipantsLabel : noCapacityLabel;

        for (var i = 0; i < blocks.length; i += 1) {
            var block = blocks[i];
            var inputs = getCTAInputs(block);

            if (inputs.date && data.date) {
                inputs.date.value = data.date;
            }

            if (inputs.time && inputs.time.tagName === 'SELECT') {
                populateSelectWithOptions(inputs.time, times, placeholderTime, data.selectedTime);
                inputs.time.disabled = times.length === 0;
                bindCTAInput(inputs.time, 'time', block);
            }

            if (inputs.participants && inputs.participants.tagName === 'SELECT') {
                populateSelectWithOptions(inputs.participants, participants, placeholderParticipants, data.selectedParticipants);
                inputs.participants.disabled = participants.length === 0;
                bindCTAInput(inputs.participants, 'participants', block);
            }

            updateButtonContextFromInputs(block);
        }

        hydrateButtons();
    }

    function applyDetailsToBlock(block, details) {
        var button = block.querySelector('.ddb-add-to-plan');
        if (!button) {
            return;
        }

        if (details.date) {
            button.setAttribute('data-date', details.date);
        } else {
            button.removeAttribute('data-date');
        }

        if (details.time) {
            button.setAttribute('data-time', details.time);
        } else {
            button.removeAttribute('data-time');
        }

        if (typeof details.participants === 'number') {
            button.setAttribute('data-participants', String(details.participants));
        } else {
            button.removeAttribute('data-participants');
        }

        var detailContainer = block.querySelector(CTA_DETAILS_SELECTOR);
        if (!detailContainer) {
            return;
        }

        var requiresInputs = detailContainer.getAttribute('data-requires-inputs') === '1';
        if (requiresInputs) {
            var inputs = getCTAInputs(block);

            if (inputs.date) {
                if (productDateInput) {
                    copyInputAttributes(productDateInput, inputs.date, ['min', 'max', 'step', 'required']);
                }
                inputs.date.value = details.date || (productDateInput ? productDateInput.value : '');
                bindCTAInput(inputs.date, 'date', block);
            }

            if (inputs.time) {
                if (inputs.time.tagName === 'SELECT') {
                    if (productTimeInput && productTimeInput.tagName === 'SELECT') {
                        mirrorSelectOptions(productTimeInput, inputs.time);
                    }
                    if (details.time && Array.prototype.some.call(inputs.time.options, function(option) { return option.value === details.time; })) {
                        inputs.time.value = details.time;
                    } else if (productTimeInput) {
                        inputs.time.value = productTimeInput.value;
                    }
                } else {
                    if (productTimeInput) {
                        copyInputAttributes(productTimeInput, inputs.time, ['min', 'max', 'step', 'required']);
                    }
                    inputs.time.value = details.time || (productTimeInput ? productTimeInput.value : '');
                }
                bindCTAInput(inputs.time, 'time', block);
            }

            if (inputs.participants) {
                if (inputs.participants.tagName === 'SELECT') {
                    if (productParticipantsInput && productParticipantsInput.tagName === 'SELECT') {
                        mirrorSelectOptions(productParticipantsInput, inputs.participants);
                    }
                    var participantValue = null;
                    if (typeof details.participants === 'number' && details.participants > 0) {
                        participantValue = String(details.participants);
                    } else if (productParticipantsInput && productParticipantsInput.value) {
                        participantValue = productParticipantsInput.value;
                    }
                    if (participantValue && Array.prototype.some.call(inputs.participants.options, function(option) { return option.value === participantValue; })) {
                        inputs.participants.value = participantValue;
                    }
                } else {
                    if (productParticipantsInput) {
                        copyInputAttributes(productParticipantsInput, inputs.participants, ['min', 'max', 'step', 'required']);
                    }
                    if (typeof details.participants === 'number' && details.participants > 0) {
                        inputs.participants.value = String(details.participants);
                    } else if (productParticipantsInput && productParticipantsInput.value) {
                        inputs.participants.value = productParticipantsInput.value;
                    } else {
                        inputs.participants.value = '';
                    }
                }

                bindCTAInput(inputs.participants, 'participants', block);
            }

            detailContainer.removeAttribute('hidden');
            updateButtonContextFromInputs(block);
            return;
        }

        var dateElement = detailContainer.querySelector(CTA_DATE_SELECTOR);
        var timeElement = detailContainer.querySelector(CTA_TIME_SELECTOR);
        var participantsElement = detailContainer.querySelector(CTA_PARTICIPANTS_SELECTOR);

        var hasData = false;

        if (dateElement) {
            var formattedDate = formatDateDisplay(details.date);
            dateElement.textContent = formattedDate;
            if (formattedDate !== EN_DASH) {
                hasData = true;
            }
        }

        if (timeElement) {
            var formattedTime = formatTimeDisplay(details.time);
            timeElement.textContent = formattedTime;
            if (formattedTime !== EN_DASH) {
                hasData = true;
            }
        }

        if (participantsElement) {
            var formattedParticipants = formatParticipantsDisplay(block, details.participants);
            participantsElement.textContent = formattedParticipants;
            if (formattedParticipants !== EN_DASH) {
                hasData = true;
            }
        }

        if (hasData) {
            detailContainer.removeAttribute('hidden');
        } else {
            detailContainer.setAttribute('hidden', 'hidden');
        }
    }

    function syncProductContext() {
        if (!productForm) {
            return;
        }

        var details = collectProductDetails();
        var blocks = document.querySelectorAll(CTA_SELECTOR + '[data-requires-details="1"]');
        for (var i = 0; i < blocks.length; i += 1) {
            applyDetailsToBlock(blocks[i], details);
        }
    }

    function scheduleProductSync() {
        if (!productForm) {
            return;
        }
        if (syncScheduled) {
            return;
        }

        syncScheduled = true;
        var raf = typeof window.requestAnimationFrame === 'function'
            ? window.requestAnimationFrame.bind(window)
            : function (cb) { return setTimeout(cb, 16); };

        raf(function () {
            syncScheduled = false;
            syncProductContext();
            hydrateButtons();
        });
    }

    document.addEventListener('sbdp:product-options', function (event) {
        applyProductOptions(event && event.detail ? event.detail : {});
    });

    function getButtonDetails(button) {
        return {
            date: normalizeDate(button.getAttribute('data-date') || ''),
            time: normalizeTime(button.getAttribute('data-time') || ''),
            participants: normalizeParticipants(button.getAttribute('data-participants'))
        };
    }

    function plannerHasItem(items, id, date, time) {
        for (var i = 0; i < items.length; i += 1) {
            var item = items[i];

            if (String(item.id) !== String(id)) {
                continue;
            }

            if (date) {
                if (!item.date || item.date !== date) {
                    continue;
                }
            }

            if (time) {
                if (!item.time || item.time !== time) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    function pushActivity(button) {
        var activityId = button.getAttribute('data-activity-id');
        if (!activityId) {
            return;
        }

        var activityTitle = button.getAttribute('data-activity-title') || '';
        var activityUrl = button.getAttribute('data-activity-url') || '';
        var details = getButtonDetails(button);

        var plannerItems = readPlanner();
        if (!plannerHasItem(plannerItems, activityId, details.date, details.time)) {
            var entry = {
                id: activityId,
                title: activityTitle,
                url: activityUrl,
                addedAt: new Date().toISOString()
            };

            if (details.date) {
                entry.date = details.date;
            }

            if (details.time) {
                entry.time = details.time;
            }

            if (typeof details.participants === 'number') {
                entry.participants = details.participants;
            }

            plannerItems.push(entry);
            writePlanner(plannerItems);
        }

        button.classList.add('ddb-in-planner');
        button.setAttribute('aria-pressed', 'true');
        if (button.hasAttribute('data-added-label')) {
            button.textContent = button.getAttribute('data-added-label');
        } else {
            button.textContent = addedLabel;
        }

        if (typeof window.CustomEvent === 'function') {
            var detail = {
                id: activityId,
                title: activityTitle,
                url: activityUrl,
                date: details.date || null,
                time: details.time || null,
                participants: typeof details.participants === 'number' ? details.participants : null
            };
            window.dispatchEvent(new CustomEvent('ddb:planner:item-added', { detail: detail }));
        }

        var numericId = parseInt(activityId, 10);
        var plannerEntry = {
            product_id: Number.isFinite(numericId) && numericId > 0 ? numericId : activityId,
            date: details.date || null,
            time: details.time || null,
            participants: typeof details.participants === 'number' ? details.participants : null,
            resource_id: null,
            append: true,
            source: 'cta'
        };

        var rawResourceId = button.getAttribute('data-resource-id');
        if (rawResourceId) {
            var parsedResourceId = parseInt(rawResourceId, 10);
            if (Number.isFinite(parsedResourceId) && parsedResourceId > 0) {
                plannerEntry.resource_id = parsedResourceId;
            }
        }

        pushSessionPrefill(plannerEntry);
        broadcastPlannerPrefill(plannerEntry);

        var plannerTarget = buildPlannerTarget(button.getAttribute('data-planner-url'), details);
        if (plannerTarget) {
            window.location.href = plannerTarget;
        }
  }

    function hydrateButtons() {
        var plannerItems = readPlanner();
        var buttons = document.querySelectorAll('.ddb-add-to-plan');

        for (var i = 0; i < buttons.length; i += 1) {
            var button = buttons[i];
            var activityId = button.getAttribute('data-activity-id');
            var defaults = button.getAttribute('data-default-label') || '+ Voeg toe aan Plan je dag';
            var block = button.closest(CTA_SELECTOR);
            if (block) {
                updateButtonContextFromInputs(block);
            }
            var details = getButtonDetails(button);

            if (activityId && plannerHasItem(plannerItems, activityId, details.date, details.time)) {
                button.classList.add('ddb-in-planner');
                button.setAttribute('aria-pressed', 'true');
                if (button.hasAttribute('data-added-label')) {
                    button.textContent = button.getAttribute('data-added-label');
                } else {
                    button.textContent = addedLabel;
                }
            } else {
                button.classList.remove('ddb-in-planner');
                button.setAttribute('aria-pressed', 'false');
                button.textContent = defaults;
            }
        }
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        var button = target.closest('.ddb-add-to-plan');
        if (!button) {
            return;
        }

        event.preventDefault();
        pushActivity(button);
        hydrateButtons();
    });

    if (productDateInput) {
        productDateInput.addEventListener('change', scheduleProductSync);
        productDateInput.addEventListener('input', scheduleProductSync);
    }
    if (productTimeInput) {
        productTimeInput.addEventListener('change', scheduleProductSync);
        productTimeInput.addEventListener('input', scheduleProductSync);
    }
    if (productParticipantsInput) {
        productParticipantsInput.addEventListener('change', scheduleProductSync);
        productParticipantsInput.addEventListener('input', scheduleProductSync);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            syncProductContext();
            hydrateButtons();
        });
    } else {
        syncProductContext();
        hydrateButtons();
    }
})();
