(function(){
  'use strict';

  let boardWrapper = null;
  let resourceRailElement = null;
  let calendarHostElement = null;
  let timelineWrapperElement = null;

  if (!window.SBDP_CFG) {
    return;
  }

  const cfg = window.SBDP_CFG;
  const calendarRootElement = document.getElementById('sbdp-calendar');
  if (!calendarRootElement) {
    return;
  }

  const dateInput = document.getElementById('sbdp-date');
  const participantsInput = document.getElementById('sbdp-participants');
  const servicesBox = document.getElementById('sbdp-services');
  const summaryBox = document.getElementById('sbdp-summary-list');
  const totalElement = document.getElementById('sbdp-total-amount');
  const payButton = document.getElementById('sbdp-btn-pay');
  const requestButton = document.getElementById('sbdp-btn-request');
  const shareButton = document.getElementById('sbdp-btn-share');
  const messageArea = document.getElementById('sbdp-message-area');
  const toastArea = document.getElementById('sbdp-toast');
  const searchInput = document.getElementById('sbdp-filter-search');
  const durationSelect = document.getElementById('sbdp-filter-duration');
  const bundlesCard = document.getElementById('sbdp-bundles-card');
  const bundlesList = document.getElementById('sbdp-bundles-list');
  const clampParticipantsValue = function(value){
    var parsed = parseInt(value, 10);
    if (isNaN(parsed) || parsed < 1) {
      return 1;
    }
    return parsed;
  };

  const getPrefillFromQuery = function(){
    if (typeof URLSearchParams === 'undefined') {
      return { date: null, time: null, participants: null, combi: null, combiLabel: null };
    }

    var params;
    try {
      params = new URLSearchParams(window.location.search);
    } catch (error) {
      return { date: null, time: null, participants: null, combi: null, combiLabel: null };
    }

    var prefill = { date: null, time: null, participants: null, combi: null, combiLabel: null };

    var dateParam = params.get('sbdp_date');
    if (dateParam && /^\d{4}-\d{2}-\d{2}$/.test(dateParam)) {
      prefill.date = dateParam;
    }

    var timeParam = params.get('sbdp_time');
    if (timeParam && /^\d{2}:\d{2}$/.test(timeParam)) {
      prefill.time = timeParam;
    }

    var participantsParam = params.get('sbdp_participants');
    if (participantsParam) {
      prefill.participants = clampParticipantsValue(participantsParam);
    }

    var combiParam = params.get('sbdp_combi');
    if (combiParam) {
      prefill.combi = combiParam;
    }

    var combiLabelParam = params.get('sbdp_combi_label');
    if (combiLabelParam) {
      prefill.combiLabel = combiLabelParam;
    }

    return prefill;
  };







  const prefillFromQuery = getPrefillFromQuery();


  const todayISO = new Date().toISOString().slice(0, 10);
  if (dateInput) {
    if (prefillFromQuery.date) {
      dateInput.value = prefillFromQuery.date;
    } else if (!dateInput.value) {
      dateInput.value = todayISO;
    }
  }

  if (participantsInput && prefillFromQuery.participants !== null) {
    participantsInput.value = String(prefillFromQuery.participants);
  }


  const defaults = {
    price_pp: 'Price p.p.',
    duration: 'Duration',
    participants: 'participants',
    participant_single: 'participant',
    total: 'Total',
    pick_date: 'Pick a date first',
    remove_item: 'Remove "%s" from the planner?',
    no_items: 'No activities yet',
    generic_error: 'Something went wrong. Please try again.',
    success: 'Programme saved.',
    clamped: 'The activity was adjusted to the selected day. Please verify the times.',
    add_to_plan: 'Add to planner',
    no_availability: 'No available slot found for this day. Try another date or update availability.',
    conflict: 'Outside availability',
    capacity_warning: 'Participants exceed available capacity.',
    slot_conflict: 'This time conflicts with availability rules.',
    no_date_selected: 'Select a date first.',
    loading: 'Loading...',
    add_first_service: 'Add an activity to get started.',
    per_booking_label: 'per booking',
    per_participant_label: 'per participant',
    filter_search_placeholder: 'Search activities',
    filter_duration_label: 'Filter by duration',
    toast_added: '%s toegevoegd aan je planning',
    share_title: 'Mijn dagplanner',
    share_intro: 'Bekijk mijn planning voor',
    share_success: 'Planning gekopieerd naar het klembord.',
    share_error: 'Delen is mislukt. Probeer het opnieuw.',
    bundles_heading: 'Aanbevolen arrangementen',
    bundles_intro: 'Kies een samengesteld programma als startpunt.',
    bundle_apply: 'Gebruik arrangement',
    bundle_empty: 'Er zijn momenteel geen arrangementen beschikbaar.',
    bundle_placeholder: 'Arrangementselectie wordt binnenkort geactiveerd.'
  };

  const l10n = Object.assign({}, defaults, cfg.i18n || {});
  const currencyCode = cfg.currency || 'EUR';
  const locale = (cfg.locale || 'nl-NL').replace('_', '-');
  let currencyFormatter;
  try {
    currencyFormatter = new Intl.NumberFormat(locale, { style: 'currency', currency: currencyCode });
  } catch (error) {
    currencyFormatter = new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' });
  }

  const FIFTEEN_MINUTES = 15 * 60 * 1000;
  const MIN_EVENT_DURATION = 5 * 60 * 1000;

  const state = {
    calendar: null,
    date: prefillFromQuery.date ? prefillFromQuery.date : (dateInput ? (dateInput.value || todayISO) : todayISO),
    participants: prefillFromQuery.participants !== null ? prefillFromQuery.participants : (participantsInput ? Math.max(1, parseInt(participantsInput.value || '1', 10)) : 1),
    resources: [],
    resourceLookup: {},
    services: [],
    servicesRaw: [],
    events: [],
    availabilityCache: {},
    overlayBlocks: {},
    overlayEventIds: [],
    pricingCache: {},
    filters: {
      search: searchInput ? (searchInput.value || '').toLowerCase() : '',
      duration: durationSelect ? (durationSelect.value || 'all') : 'all'
    },
    prefill: {
      date: prefillFromQuery.date,
      time: prefillFromQuery.time,
      participants: prefillFromQuery.participants,
      combi: prefillFromQuery.combi,
      combiLabel: prefillFromQuery.combiLabel
    }
  };


  if (prefillFromQuery.combiLabel) {
    var combiSearch = prefillFromQuery.combiLabel.trim();
    if (combiSearch) {
      if (searchInput) {
        searchInput.value = combiSearch;
      }
      state.filters.search = combiSearch.toLowerCase();
    }
  } else if (prefillFromQuery.combi) {
    var normalizedCombi = prefillFromQuery.combi.replace(/[-_]+/g, ' ').trim();
    if (normalizedCombi) {
      if (searchInput && !searchInput.value) {
        searchInput.value = normalizedCombi;
      }
      var appliedSearch = searchInput ? (searchInput.value || normalizedCombi) : normalizedCombi;
      state.filters.search = appliedSearch.toLowerCase();
    }
  }


  const getResourceMeta = function(resourceId){
    if (!resourceId) {
      return null;
    }
    return state.resourceLookup[resourceId] || null;
  };

  const getResourceColor = function(resourceId){
    const resource = getResourceMeta(resourceId);
    if (!resource) {
      return resourceId ? '#2563eb' : '';
    }
    return resource.color || '#2563eb';
  };

  const decorateEventAppearance = function(event, hasConflict){
    if (!event) {
      return;
    }
    const resourceId = event.extendedProps ? (event.extendedProps.resourceId || 0) : 0;
    const color = getResourceColor(resourceId);
    const classNames = ['sbdp-event'];
    if (hasConflict) {
      classNames.push('sbdp-event--conflict');
    }
    event.setProp('classNames', classNames);
    if (!hasConflict && color) {
      event.setProp('backgroundColor', color);
      event.setProp('borderColor', color);
    } else {
      event.setProp('backgroundColor', '');
      event.setProp('borderColor', '');
    }
  };

  const refreshEventResourceStyles = function(){
    if (!state.calendar) {
      return;
    }
    state.calendar.getEvents().forEach(function(event){
      if (event.extendedProps && event.extendedProps.overlay) {
        return;
      }
      decorateEventAppearance(event, !!event.extendedProps.conflict);
    });
  };

  const buildPlannerBoard = function(){
    if (!calendarRootElement || boardWrapper) {
      return;
    }

    calendarRootElement.classList.add('sbdp-board');
    calendarRootElement.innerHTML = '';

    boardWrapper = document.createElement('div');
    boardWrapper.className = 'sbdp-board__inner';

    resourceRailElement = document.createElement('div');
    resourceRailElement.className = 'sbdp-board__rail';
    resourceRailElement.setAttribute('role', 'list');

    timelineWrapperElement = document.createElement('div');
    timelineWrapperElement.className = 'sbdp-board__timeline';

    calendarHostElement = document.createElement('div');
    calendarHostElement.className = 'sbdp-board__calendar';

    timelineWrapperElement.appendChild(calendarHostElement);
    boardWrapper.appendChild(resourceRailElement);
    boardWrapper.appendChild(timelineWrapperElement);

    calendarRootElement.appendChild(boardWrapper);
  };

  const renderResourceRail = function(){
    if (!resourceRailElement) {
      return;
    }

    resourceRailElement.innerHTML = '';

    if (!state.resources.length) {
      const empty = document.createElement('div');
      empty.className = 'sbdp-resource-pill sbdp-resource-pill--empty';
      empty.textContent = l10n.no_items || 'Geen resources beschikbaar';
      resourceRailElement.appendChild(empty);
      return;
    }

    state.resources.forEach(function(resource){
      const color = getResourceColor(resource.id);
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'sbdp-resource-pill';
      item.dataset.resourceId = String(resource.id);
      item.setAttribute('role', 'listitem');
      item.style.setProperty('--sbdp-resource-color', color || '#2563eb');

      const name = document.createElement('span');
      name.className = 'sbdp-resource-pill__name';

      const dot = document.createElement('span');
      dot.className = 'sbdp-resource-pill__dot';
      name.appendChild(dot);

      const label = document.createElement('span');
      label.textContent = resource.title || ('#' + resource.id);
      name.appendChild(label);

      const capacity = document.createElement('span');
      capacity.className = 'sbdp-resource-pill__capacity';
      const capacityValue = (resource.capacity === null || resource.capacity === undefined) ? String.fromCharCode(8734) : String(resource.capacity);
      capacity.textContent = capacityValue;

      item.appendChild(name);
      item.appendChild(capacity);

      resourceRailElement.appendChild(item);
    });
  };

  const loadResources = function(){
    state.resources = [];
    state.resourceLookup = {};

    if (!cfg.resources) {
      renderResourceRail();
      refreshEventResourceStyles();
      return Promise.resolve([]);
    }

    return request(cfg.resources).then(function(list){
      const items = Array.isArray(list) ? list : [];
      const sorted = items.slice().sort(function(a, b){
        const orderA = typeof a.order === 'number' ? a.order : (typeof a.position === 'number' ? a.position : (typeof a.menu_order === 'number' ? a.menu_order : 0));
        const orderB = typeof b.order === 'number' ? b.order : (typeof b.position === 'number' ? b.position : (typeof b.menu_order === 'number' ? b.menu_order : 0));
        if (orderA !== orderB) {
          return orderA - orderB;
        }
        return String(a.title || '').localeCompare(String(b.title || ''));
      });
      state.resources = sorted;
      state.resourceLookup = {};
      sorted.forEach(function(item){
        state.resourceLookup[item.id] = item;
      });
      renderResourceRail();
      refreshEventResourceStyles();
      return sorted;
    }).catch(function(error){
      renderResourceRail();
      refreshEventResourceStyles();
      if (error && error.message) {
        console.error('SBDP planner resources', error.message);
      }
      return [];
    });
  };

  const escapeHTML = function(value){
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  };

  const formatCurrency = function(amount){
    const value = typeof amount === 'number' ? amount : parseFloat(amount || '0');
    try {
      return currencyFormatter.format(isNaN(value) ? 0 : value);
    } catch (error) {
      return 'EUR ' + (isNaN(value) ? '0.00' : value.toFixed(2));
    }
  };

  const formatParticipants = function(count){
    return count + ' ' + (count === 1 ? l10n.participant_single : l10n.participants);
  };

  const parseJSON = function(value){
    try { return JSON.parse(value); } catch (error) { return null; }
  };

  const showMessage = function(message, tone){
    if (!messageArea) {
      return;
    }
    const toneClass = tone || 'info';
    messageArea.textContent = message || '';
    messageArea.className = 'sbdp-message' + (message ? ' sbdp-message--' + toneClass : '');
  };

  const clearMessage = function(){
    if (messageArea) {
      messageArea.textContent = '';
      messageArea.className = 'sbdp-message';
    }
  };

  let toastTimeout = null;

  const showToast = function(message){
    if (!toastArea || !message) {
      return;
    }
    toastArea.textContent = message;
    toastArea.classList.add('is-visible');
    if (toastTimeout) {
      clearTimeout(toastTimeout);
    }
    toastTimeout = window.setTimeout(function(){
      toastArea.classList.remove('is-visible');
    }, 3000);
  };

  const handleShare = function(){
    if (!state.events.length) {
      showMessage(l10n.no_items || 'Geen activiteiten geselecteerd.', 'warning');
      return;
    }
    const text = buildShareText();
    if (!text) {
      showMessage(l10n.share_error || l10n.generic_error, 'error');
      return;
    }
    const title = l10n.share_title || 'Mijn dagplanner';
    if (navigator.share) {
      navigator.share({ title: title, text: text }).catch(function(){
        showMessage(l10n.share_error || l10n.generic_error, 'error');
      });
      return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function(){
        showToast(l10n.share_success || 'Planning gekopieerd naar klembord.');
      }).catch(function(){
        showMessage(l10n.share_error || l10n.generic_error, 'error');
      });
      return;
    }
    try {
      const helper = document.createElement('textarea');
      helper.value = text;
      helper.setAttribute('readonly', '');
      helper.style.position = 'absolute';
      helper.style.left = '-9999px';
      document.body.appendChild(helper);
      helper.select();
      document.execCommand('copy');
      document.body.removeChild(helper);
      showToast(l10n.share_success || 'Planning gekopieerd naar klembord.');
    } catch (error) {
      showMessage(l10n.share_error || l10n.generic_error, 'error');
    }
  };

  const buildShareText = function(){
    if (!state.events.length) {
      return '';
    }
    const dateLabel = state.date ? new Date(state.date + 'T00:00:00').toLocaleDateString(locale, {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric'
    }) : '';
    const intro = (l10n.share_intro || 'Bekijk mijn planning voor') + (dateLabel ? ' ' + dateLabel : '');
    const lines = state.events.map(function(item){
      const startObj = item.start instanceof Date ? item.start : new Date(item.start);
      const endObj = item.end instanceof Date ? item.end : new Date(item.end);
      const start = startObj.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
      const end = endObj.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
      const participants = formatParticipants(state.participants);
      return '- ' + item.title + ' (' + start + ' - ' + end + ', ' + participants + ')';
    });
    const totalLine = (l10n.total || 'Totaal') + ': ' + (totalElement ? totalElement.textContent.trim() : '');
    return [intro, '', lines.join('\n'), '', totalLine].join('\n');
  };

  const request = function(url, options){
    const config = options || {};
    const headers = config.headers ? Object.assign({}, config.headers) : {};

    if (cfg.nonce) {
      headers['X-WP-Nonce'] = cfg.nonce;
    }

    if (config.body && !headers['Content-Type']) {
      headers['Content-Type'] = 'application/json';
    }

    return fetch(url, Object.assign({}, config, {
      headers: headers,
      credentials: 'same-origin'
    })).then(function(response){
      return response.text().then(function(body){
        if (!response.ok) {
          var message = l10n.generic_error;
          try {
            var data = JSON.parse(body);
            if (data && data.message) {
              message = data.message;
            }
          } catch (parseError) {
            // geen geldige JSON, behoud standaardmelding
          }
          throw new Error(message);
        }

        if (!body) {
          return null;
        }

        var cleaned = body.replace(/^\uFEFF/, '').trim();
        if (!cleaned) {
          return null;
        }

        try {
          return JSON.parse(cleaned);
        } catch (parseError) {
          console.error('SBDP JSON parse error', parseError, cleaned);
          throw parseError;
        }
      });
    });
  };

  const ensureCalendar = function(){
    if (state.calendar) {
      return state.calendar;
    }
    if (typeof FullCalendar === 'undefined' || !FullCalendar.Calendar) {
      return null;
    }

    buildPlannerBoard();
    if (!calendarHostElement) {
      return null;
    }
    const fcLocale = (locale || 'nl').split(/[_-]/)[0];
    let defaultScrollTime = '10:00:00';
    let defaultSlotMinTime = '10:00:00';
    if (state.prefill && state.prefill.time && /^\d{2}:\d{2}$/.test(state.prefill.time)) {
      defaultScrollTime = state.prefill.time + ':00';
      if (state.prefill.time < '10:00') {
        defaultSlotMinTime = state.prefill.time + ':00';
      }
    }
    state.calendar = new FullCalendar.Calendar(calendarHostElement, {
      height: 'auto',
      locale: fcLocale,
      initialView: 'timeGridDay',
      slotMinTime: defaultSlotMinTime,
      slotMaxTime: '23:00:00',
      scrollTime: defaultScrollTime,
      allDaySlot: false,
      snapDuration: '00:15:00',
      nowIndicator: true,
      droppable: true,
      editable: true,
      eventResizableFromStart: true,
      eventReceive: function(info){ handleExternalEvent(info); },
      eventDrop: function(info){ handleEventChange(info.event); },
      eventResize: function(info){ handleEventChange(info.event); },
      eventClick: function(info){ handleEventClick(info.event); }
    });
    state.calendar.render();
    if (state.date) {
      state.calendar.gotoDate(state.date);
    }
    return state.calendar;
  };

  const clampEventToCurrentDay = function(event, notify){
    if (!event || !state.date) {
      return;
    }
    const dayStart = new Date(state.date + 'T00:00:00');
    const dayEnd = new Date(state.date + 'T23:59:00');
    const start = event.start ? new Date(event.start) : new Date(dayStart);
    const duration = getEventDuration(event) * 60000;
    let end = event.end ? new Date(event.end) : new Date(start.getTime() + duration);
    let adjusted = false;

    if (start < dayStart) {
      const newStart = new Date(dayStart);
      const newEnd = new Date(newStart.getTime() + duration);
      event.setDates(newStart, newEnd);
      adjusted = true;
    }

    end = event.end ? new Date(event.end) : new Date(event.start.getTime() + duration);
    if (end > dayEnd) {
      const newEnd = new Date(dayEnd);
      if (newEnd <= event.start) {
        const fallbackEnd = new Date(event.start.getTime() + MIN_EVENT_DURATION);
        event.setDates(event.start, fallbackEnd);
      } else {
        event.setDates(event.start, newEnd);
      }
      adjusted = true;
    }

    if (adjusted && notify && l10n.clamped) {
      window.setTimeout(function(){ window.alert(l10n.clamped); }, 10);
    }
    event.setExtendedProp('duration', getEventDuration(event));
  };

  const getEventDuration = function(event){
    const start = event.start ? event.start.getTime() : Date.now();
    const end = event.end ? event.end.getTime() : start + (parseInt(event.extendedProps.duration || 60, 10) * 60000);
    const minutes = Math.max(MIN_EVENT_DURATION, end - start) / 60000;
    return Math.max(5, Math.round(minutes));
  };

  const getOverlayKey = function(event){
    const productId = event.extendedProps.productId || 0;
    const resourceId = event.extendedProps.resourceId || 0;
    const day = event.startStr ? event.startStr.slice(0, 10) : state.date;
    return [productId, resourceId, day].join('|');
  };

  const loadAvailability = function(productId, resourceId){
    if (!cfg.availability || !state.date) {
      return Promise.resolve(null);
    }
    const key = [productId, resourceId || 0, state.date].join('|');
    if (state.availabilityCache[key]) {
      return Promise.resolve(state.availabilityCache[key]);
    }
    const url = cfg.availability + '?product_id=' + productId + '&date=' + encodeURIComponent(state.date) + (resourceId ? '&resource_id=' + resourceId : '');
    return request(url).then(function(data){
      state.availabilityCache[key] = data || {};
      return state.availabilityCache[key];
    }).catch(function(){
      return null;
    });
  };

  const isRangeBlocked = function(start, end, blocks){
    if (!blocks || !blocks.length) {
      return false;
    }
    const startTime = start.getTime();
    const endTime = end.getTime();
    for (let i = 0; i < blocks.length; i += 1) {
      const block = blocks[i];
      const blockStart = block && block.start ? new Date(block.start).getTime() : 0;
      const blockEnd = block && block.end ? new Date(block.end).getTime() : 0;
      if (blockEnd > startTime && blockStart < endTime) {
        return true;
      }
    }
    return false;
  };

  const renderOverlay = function(){
    if (!state.calendar) {
      return;
    }
    state.overlayEventIds.forEach(function(id){
      const existing = state.calendar.getEventById(id);
      if (existing) {
        existing.remove();
      }
    });
    state.overlayEventIds = [];

    const unique = {};
    Object.keys(state.overlayBlocks).forEach(function(key){
      const blocks = state.overlayBlocks[key] || [];
      blocks.forEach(function(block){
        const mapKey = (block.start || '') + '|' + (block.end || '') + '|' + (block.color || '');
        unique[mapKey] = block;
      });
    });

    let index = 0;
    Object.keys(unique).forEach(function(mapKey){
      const block = unique[mapKey];
      if (!block.start || !block.end) {
        return;
      }
      const eventData = Object.assign({
        id: 'sbdp-overlay-' + index,
        display: 'background',
        extendedProps: { overlay: true }
      }, block);
      state.calendar.addEvent(eventData);
      state.overlayEventIds.push(eventData.id);
      index += 1;
    });
  };

  const updateOverlayForEvent = function(event, blocks){
    const key = getOverlayKey(event);
    state.overlayBlocks[key] = blocks || [];
    renderOverlay();
  };

  const removeOverlayForEvent = function(event){
    const key = getOverlayKey(event);
    if (!state.calendar) {
      delete state.overlayBlocks[key];
      renderOverlay();
      return;
    }
    const stillUsed = state.calendar.getEvents().some(function(other){
      if (other === event) {
        return false;
      }
      if (other.extendedProps && other.extendedProps.overlay) {
        return false;
      }
      return getOverlayKey(other) === key;
    });
    if (!stillUsed) {
      delete state.overlayBlocks[key];
      renderOverlay();
    }
  };

  const ensureAvailabilityForEvent = function(event){
    const productId = event.extendedProps.productId;
    if (!productId) {
      return Promise.resolve(null);
    }
    const resourceId = event.extendedProps.resourceId || 0;
    return loadAvailability(productId, resourceId).then(function(availability){
      const blocks = availability && availability.blocks ? availability.blocks : [];
      const rawCapacity = availability && typeof availability.capacity !== 'undefined' ? parseInt(availability.capacity, 10) : null;
      const capacity = rawCapacity && rawCapacity > 0 ? rawCapacity : null;
      const start = event.start ? new Date(event.start) : new Date(state.date + 'T00:00:00');
      const end = event.end ? new Date(event.end) : new Date(start.getTime() + getEventDuration(event) * 60000);
      const conflict = isRangeBlocked(start, end, blocks);
      const capacityIssue = capacity !== null && state.participants > capacity;
      const hasConflict = conflict || capacityIssue;
      event.setExtendedProp('conflict', hasConflict);
      event.setExtendedProp('capacityLimit', capacity);
      decorateEventAppearance(event, hasConflict);
      updateOverlayForEvent(event, blocks);
      return hasConflict ? false : availability || true;
    }).catch(function(){
      event.setExtendedProp('conflict', true);
      decorateEventAppearance(event, true);
      return false;
    });
  };

  const ensurePricingForEvent = function(event){
    if (!cfg.pricing_preview) {
      return Promise.resolve();
    }
    const productId = event.extendedProps.productId;
    if (!productId) {
      return Promise.resolve();
    }
    const resourceId = event.extendedProps.resourceId || 0;
    const startIso = event.start ? event.start.toISOString() : state.date + 'T00:00:00Z';
    const cacheKey = [productId, resourceId, state.participants, startIso].join('|');
    if (state.pricingCache[cacheKey]) {
      event.setExtendedProp('pricing', state.pricingCache[cacheKey]);
      return Promise.resolve();
    }
    const payload = {
      product_id: productId,
      resource_id: resourceId,
      participants: state.participants,
      start: startIso
    };
    return request(cfg.pricing_preview, {
      method: 'POST',
      body: JSON.stringify(payload)
    }).then(function(response){
      if (response) {
        state.pricingCache[cacheKey] = response;
        event.setExtendedProp('pricing', response);
      }
    }).catch(function(){
      return null;
    });
  };

  const validateEvent = function(event){
    return ensureAvailabilityForEvent(event).then(function(result){
      if (result === false) {
        return false;
      }
      return ensurePricingForEvent(event);
    }).then(function(){
      return !event.extendedProps.conflict;
    });
  };

  const ensureDateSelected = function(){
    if (!state.date) {
      showMessage(l10n.no_date_selected, 'warning');
      if (dateInput) {
        dateInput.focus();
      }
      return false;
    }
    return true;
  };

  const findAvailableSlot = function(service, availability){
    const durationMinutes = parseInt(service.duration || 60, 10);
    const duration = Math.max(durationMinutes, 10) * 60000;
    const dayStart = new Date(state.date + 'T10:00:00');
    const dayEnd = new Date(state.date + 'T23:00:00');
    const blocks = availability && availability.blocks ? availability.blocks : [];

    for (let cursor = new Date(dayStart.getTime()); cursor.getTime() + duration <= dayEnd.getTime(); cursor = new Date(cursor.getTime() + FIFTEEN_MINUTES)) {
      const slotEnd = new Date(cursor.getTime() + duration);
      if (!isRangeBlocked(cursor, slotEnd, blocks)) {
        return { start: cursor, end: slotEnd };
      }
    }
    return null;
  };

  const addServiceToCalendar = function(service){
    if (!ensureDateSelected()) {
      return;
    }
    const calendar = ensureCalendar();
    if (!calendar) {
      showMessage(l10n.generic_error, 'error');
      return;
    }
    loadAvailability(service.id, service.resource_id || 0).then(function(availability){
      const slot = findAvailableSlot(service, availability);
      if (!slot) {
        showMessage(l10n.no_availability, 'warning');
        return null;
      }
      const event = calendar.addEvent({
        title: service.name,
        start: slot.start,
        end: slot.end
      });
      event.setExtendedProp('productId', service.id);
      event.setExtendedProp('resourceId', service.resource_id || 0);
      event.setExtendedProp('price', parseFloat(service.price || 0));
      event.setExtendedProp('duration', parseInt(service.duration || 60, 10));
      return validateEvent(event).then(function(result){
        if (result !== false) {
          showToast((l10n.toast_added || '%s toegevoegd').replace('%s', service.name));
        }
        return syncFromCalendar();
      });
    }).catch(function(error){
      showMessage(error.message || l10n.generic_error, 'error');
    });
  };

  const applyFilters = function(){
    const list = state.servicesRaw || [];
    const search = state.filters.search;
    const duration = state.filters.duration;

    const filtered = list.filter(function(service){
      if (!service) {
        return false;
      }
      const name = (service.name || '').toLowerCase();
      const excerpt = (service.excerpt || '').toLowerCase();
      if (search && name.indexOf(search) === -1 && excerpt.indexOf(search) === -1) {
        return false;
      }

      if (duration && duration !== 'all') {
        const minutes = parseInt(service.duration || 0, 10);
        if (duration === 'short' && minutes > 60) {
          return false;
        }
        if (duration === 'medium' && (minutes < 61 || minutes > 120)) {
          return false;
        }
        if (duration === 'long' && minutes <= 120) {
          return false;
        }
      }
      return true;
    });

    state.services = filtered;
    renderServices();
  };

  const loadServices = function(){
    if (!cfg.services || !servicesBox) {
      return Promise.resolve();
    }
    showMessage(l10n.loading, 'info');
    return request(cfg.services).then(function(list){
      state.servicesRaw = Array.isArray(list) ? list : [];
      if (searchInput) {
        state.filters.search = (searchInput.value || '').toLowerCase();
      }
      state.filters.duration = durationSelect ? (durationSelect.value || 'all') : 'all';
      applyFilters();
      clearMessage();
    }).catch(function(error){
      state.servicesRaw = [];
      state.services = [];
      renderServices();
      showMessage(error.message || l10n.generic_error, 'error');
    });
  };

  const renderServices = function(){
    if (!servicesBox) {
      return;
    }
    servicesBox.innerHTML = '';
    if (!state.services.length) {
      const empty = document.createElement('p');
      empty.className = 'sbdp-empty';
      empty.textContent = l10n.add_first_service;
      servicesBox.appendChild(empty);
      return;
    }

    state.services.forEach(function(service){
      const card = document.createElement('article');
      card.className = 'sbdp-service';
      card.draggable = true;
      card.tabIndex = 0;

      const resource = getResourceMeta(service.resource_id || 0);
      const resourceColor = getResourceColor(service.resource_id || 0);
      card.style.setProperty('--sbdp-resource-color', resourceColor || 'transparent');

      const payload = {
        id: service.id,
        name: service.name,
        price: parseFloat(service.price || 0),
        duration: parseInt(service.duration || 60, 10),
        resource_id: service.resource_id || 0,
        resource_name: resource ? (resource.title || '') : '',
        resource_color: resourceColor,
        thumb: service.thumb || ''
      };
      card.dataset.service = JSON.stringify(payload);

      const body = document.createElement('div');
      body.className = 'sbdp-service__body';
      const resourceBadge = resource ? '<span class="sbdp-service__resource">' + escapeHTML(resource.title || '') + '</span>' : '';
      body.innerHTML = '' +
        (service.thumb ? '<img class="sbdp-service__thumb" src="' + escapeHTML(service.thumb) + '" alt="" />' : '') +
        '<div class="sbdp-service__text">' +
          resourceBadge +
          '<strong>' + escapeHTML(service.name) + '</strong>' +
          (service.excerpt ? '<p>' + escapeHTML(service.excerpt) + '</p>' : '') +
          '<div class="sbdp-service__meta"><span>' + escapeHTML(l10n.price_pp) + ': ' + formatCurrency(service.price || 0) + '</span><span>' + escapeHTML(l10n.duration) + ': ' + (service.duration || 60) + ' min</span></div>' +
        '</div>' +
        '<div class="sbdp-service__actions"><button type="button" class="sbdp-service__add" data-action="add-service" aria-label="' + escapeHTML(l10n.add_to_plan) + '">+</button></div>';

      card.appendChild(body);

      card.addEventListener('dragstart', function(event){
        event.dataTransfer.effectAllowed = 'copy';
        event.dataTransfer.setData('application/json', card.dataset.service);
        event.dataTransfer.setData('text/plain', card.dataset.service);
      });

      card.addEventListener('keydown', function(event){
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          addServiceToCalendar(payload);
        }
      });

      const addButton = card.querySelector('[data-action="add-service"]');
      if (addButton) {
        addButton.addEventListener('click', function(event){
          event.preventDefault();
          addServiceToCalendar(payload);
        });
      }

      servicesBox.appendChild(card);
    });
  };

  const renderSummary = function(){
    if (!summaryBox) {
      return;
    }
    summaryBox.innerHTML = '';
    if (!state.events.length) {
      const empty = document.createElement('p');
      empty.className = 'sbdp-empty';
      empty.textContent = l10n.add_first_service;
      summaryBox.appendChild(empty);
      totalElement.textContent = formatCurrency(0);
      return;
    }

    let total = 0;
    const fragment = document.createDocumentFragment();

    state.events.forEach(function(item){
      const participantsLabel = formatParticipants(state.participants);
      const timeOptions = { hour: '2-digit', minute: '2-digit' };
      const dateOptions = { weekday: 'long', day: 'numeric', month: 'long' };
      const dayLabel = item.start.toLocaleDateString(locale, dateOptions);
      const timeLabel = item.start.toLocaleTimeString(locale, timeOptions) + ' | ' + item.end.toLocaleTimeString(locale, timeOptions);
      const lineTotal = item.total;
      total += lineTotal;

      const metaParts = [dayLabel, timeLabel, participantsLabel];
      if (item.resource_name) {
        metaParts.push(item.resource_name);
      }
      const metaHtml = metaParts.map(function(part){
        return escapeHTML(part);
      }).join(' | ');

      const row = document.createElement('div');
      row.className = 'sbdp-line' + (item.conflict ? ' sbdp-line--warning' : '');
      row.innerHTML = '' +
        '<div class="sbdp-line__title"><strong>' + escapeHTML(item.title || '') + '</strong></div>' +
        '<div class="sbdp-line__meta">' + metaHtml + '</div>' +
        '<div class="sbdp-line__total">' + formatCurrency(lineTotal) + '</div>' +
        (item.conflict ? '<div class="sbdp-line__alert">' + escapeHTML(item.capacityLimit && item.capacityLimit > 0 && state.participants > item.capacityLimit ? l10n.capacity_warning : l10n.slot_conflict) + '</div>' : '');

      if (item.pricing && Array.isArray(item.pricing.applied_rules) && item.pricing.applied_rules.length){
        const ruleWrapper = document.createElement('div');
        ruleWrapper.className = 'sbdp-line__rules';
        const parts = item.pricing.applied_rules.map(function(rule){
          const scope = rule.scope === 'participant' ? l10n.per_participant_label : l10n.per_booking_label;
          return escapeHTML(rule.label + ' (' + scope + '): ' + formatCurrency(rule.amount || 0));
        });
        ruleWrapper.innerHTML = '<small>' + parts.join('<br>') + '</small>';
        row.appendChild(ruleWrapper);
      }

      fragment.appendChild(row);
    });

    summaryBox.appendChild(fragment);
    totalElement.textContent = formatCurrency(total);
  };

  const syncFromCalendar = function(){
    if (!state.calendar) {
      state.events = [];
      renderSummary();
      return Promise.resolve();
    }
    const events = state.calendar.getEvents().filter(function(event){
      return !(event.extendedProps && event.extendedProps.overlay);
    });
    const pricingPromises = events.map(function(event){
      return ensurePricingForEvent(event);
    });
    return Promise.all(pricingPromises).then(function(){
      state.events = events.map(function(event){
        const start = event.start ? new Date(event.start) : new Date(state.date + 'T00:00:00');
        const end = event.end ? new Date(event.end) : new Date(start.getTime() + getEventDuration(event) * 60000);
        const pricing = event.extendedProps.pricing || null;
        const unitPrice = pricing ? parseFloat(pricing.unit_price || 0) : parseFloat(event.extendedProps.price || 0);
        const total = pricing ? parseFloat(pricing.total || (unitPrice * state.participants)) : unitPrice * state.participants;
        return {
          id: event.id,
          title: event.title,
          product_id: event.extendedProps.productId,
          resource_id: event.extendedProps.resourceId || 0,
          resource_name: event.extendedProps.resourceName || '',
          pricing: pricing,
          price: unitPrice,
          total: total,
          start: start,
          end: end,
          conflict: !!event.extendedProps.conflict,
          capacityLimit: event.extendedProps.capacityLimit || null,
          startISO: start.toISOString(),
          endISO: end.toISOString()
        };
      }).sort(function(a, b){
        return a.start.getTime() - b.start.getTime();
      });
      renderSummary();
    });
  };

  const revalidateAllEvents = function(){
    if (!state.calendar) {
      state.events = [];
      renderSummary();
      return Promise.resolve();
    }
    const events = state.calendar.getEvents().filter(function(event){
      return !(event.extendedProps && event.extendedProps.overlay);
    });
    const tasks = events.map(function(event){
      return validateEvent(event);
    });
    return Promise.all(tasks).then(function(){
      return syncFromCalendar();
    });
  };

  const handleExternalEvent = function(info){
    const raw = info.draggedEl ? info.draggedEl.dataset.service : null;
    const service = raw ? parseJSON(raw) : null;
    if (!service) {
      info.event.remove();
      return;
    }

    if (!state.date && info.event.start) {
      setDate(info.event.start.toISOString().slice(0, 10));
    }

    if (!state.date) {
      showMessage(l10n.no_date_selected, 'warning');
      info.event.remove();
      return;
    }

    info.event.setProp('title', service.name);
    info.event.setExtendedProp('productId', service.id);
    info.event.setExtendedProp('resourceId', service.resource_id || 0);
    info.event.setExtendedProp('price', parseFloat(service.price || 0));
    info.event.setExtendedProp('duration', parseInt(service.duration || 60, 10));

    clampEventToCurrentDay(info.event, true);
    validateEvent(info.event).then(function(result){
      if (result !== false) {
        showToast((l10n.toast_added || '%s toegevoegd').replace('%s', service ? service.name : info.event.title));
      }
      return syncFromCalendar();
    });
  };

  const handleEventChange = function(event){
    clampEventToCurrentDay(event, false);
    validateEvent(event).then(function(){
      return syncFromCalendar();
    });
  };

  const handleEventClick = function(event){
    const message = (l10n.remove_item || '').replace('%s', event.title || '');
    if (window.confirm(message)) {
      removeOverlayForEvent(event);
      event.remove();
      syncFromCalendar();
    }
  };

  const setDate = function(value){
    state.date = value;
    state.availabilityCache = {};
    state.overlayBlocks = {};
    state.pricingCache = {};
    if (dateInput && dateInput.value !== value) {
      dateInput.value = value;
    }
    if (state.calendar) {
      state.calendar.removeAllEvents();
      state.calendar.gotoDate(value);
      renderOverlay();
    }
    state.events = [];
    renderSummary();
  };

  const handleDateChange = function(event){
    const value = event.target.value;
    if (!value) {
      return;
    }
    setDate(value);
    clearMessage();
  };

  const handleParticipantsChange = function(event){
    const value = Math.max(1, parseInt(event.target.value || '1', 10));
    state.participants = value;
    state.pricingCache = {};
    event.target.value = String(value);
    revalidateAllEvents();
  };

  const compose = function(mode){
    if (!ensureDateSelected()) {
      return;
    }
    if (!state.events.length) {
      showMessage(l10n.no_items, 'warning');
      return;
    }
    const hasConflict = state.events.some(function(item){ return item.conflict; });
    if (hasConflict) {
      showMessage(l10n.slot_conflict, 'error');
      return;
    }

    const payload = {
      mode: mode,
      participants: state.participants,
      items: state.events.map(function(item){
        return {
          product_id: item.product_id,
          start: item.startISO,
          end: item.endISO
        };
      })
    };

    request(cfg.compose, {
      method: 'POST',
      body: JSON.stringify(payload)
    }).then(function(response){
      if (response && response.redirect) {
        window.location.href = response.redirect;
        return;
      }
      showMessage(l10n.success, 'success');
    }).catch(function(error){
      showMessage(error.message || l10n.generic_error, 'error');
    });
  };

  const bindActions = function(){
    if (dateInput) {
      dateInput.addEventListener('change', handleDateChange);
    }
    if (participantsInput) {
      participantsInput.addEventListener('change', handleParticipantsChange);
      participantsInput.addEventListener('blur', handleParticipantsChange);
    }
    if (payButton) {
      payButton.addEventListener('click', function(event){
        event.preventDefault();
        compose('pay');
      });
    }
  if (requestButton) {
    requestButton.addEventListener('click', function(event){
      event.preventDefault();
      compose('request');
    });
  }
  if (shareButton) {
    shareButton.addEventListener('click', function(event){
      event.preventDefault();
      handleShare();
    });
  }
  if (searchInput) {
    if (l10n.filter_search_placeholder) {
      searchInput.placeholder = l10n.filter_search_placeholder;
    }
    searchInput.addEventListener('input', function(){
      state.filters.search = (this.value || '').toLowerCase();
      applyFilters();
    });
  }
  if (durationSelect) {
    durationSelect.addEventListener('change', function(){
      state.filters.duration = this.value || 'all';
      applyFilters();
    });
  }
  };

  const init = function(){
    ensureCalendar();
    bindActions();
    if (state.date) {
      setDate(state.date);
    }
    loadServices();
    renderSummary();
  };

  init();
})();











