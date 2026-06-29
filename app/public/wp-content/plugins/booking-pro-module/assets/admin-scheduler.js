(function () {
  'use strict';

  const cfg = window.SBDP_ADMIN_SCHEDULER || null;
  const root = document.getElementById('sbdp-scheduler-app');
  if (!cfg || !root) {
    return;
  }
  const v2 = cfg.v2 || {};
  try {
    const endpointUrl = new URL(cfg.endpoint);
    endpointUrl.protocol = window.location.protocol;
    endpointUrl.hostname = window.location.hostname;
    endpointUrl.port = window.location.port || endpointUrl.port;
    cfg.endpoint = endpointUrl.toString();
  } catch (error) {
    // ignore invalid endpoint formats
  }

  const VIEWS = ['day', 'week', 'month'];
  const i18n = window.wp && window.wp.i18n && window.wp.i18n.__
    ? window.wp.i18n.__
    : function (text) {
        return text;
      };

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function hasEndpoint(key) {
    return !!(v2 && typeof v2[key] === 'string' && v2[key]);
  }

  function apiFetch(url, options) {
    const config = Object.assign(
      {
        headers: {
          'X-WP-Nonce': cfg.nonce || '',
        },
      },
      options || {}
    );

    if (!config.headers['X-WP-Nonce']) {
      delete config.headers['X-WP-Nonce'];
    }

    return fetch(url, config).then((response) => {
      if (!response.ok) {
        return response.json().catch(() => ({})).then((body) => {
          const message = body && body.message ? body.message : response.statusText;
          throw new Error(message || i18n('Onbekende fout.', 'sbdp'));
        });
      }
      return response.json();
    });
  }

  const state = {
    view: 'day',
    anchorDate: todayISO(),
    date: '',
    rangeStart: '',
    rangeEnd: '',
    loading: false,
    error: '',
    data: null,
    modal: null,
    dayTimelineCache: {},
    productCatalog: {
      items: [],
      resourceId: 0,
      loaded: false,
      loading: false,
    },
  };

  computeRange();
  render();
  fetchData(true);

  function todayISO() {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${now.getFullYear()}-${month}-${day}`;
  }

  function parseDate(value) {
    if (!value) {
      return null;
    }
    const parts = value.split('-').map(Number);
    if (parts.length !== 3 || parts.some(Number.isNaN)) {
      return null;
    }
    return new Date(parts[0], parts[1] - 1, parts[2]);
  }

  function formatDate(dateObject) {
    const month = String(dateObject.getMonth() + 1).padStart(2, '0');
    const day = String(dateObject.getDate()).padStart(2, '0');
    return `${dateObject.getFullYear()}-${month}-${day}`;
  }

  function formatHumanDate(value) {
    const dateObject = parseDate(value);
    if (!dateObject) {
      return value;
    }
    return dateObject.toLocaleDateString(undefined, {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    });
  }

  function parsePositiveInteger(value, fallback = null) {
    const normalized = typeof value === 'string' ? value.trim() : value;
    const parsed = parseInt(normalized, 10);
    if (Number.isFinite(parsed) && parsed > 0) {
      return parsed;
    }
    return Number.isFinite(fallback) && fallback > 0 ? fallback : null;
  }

  function normalizeColor(value, fallback = '#2563eb') {
    if (typeof value !== 'string') {
      return fallback;
    }
    const match = value.trim().match(/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/);
    if (!match) {
      return fallback;
    }
    const hex = match[1];
    if (hex.length === 3) {
      return `#${hex
        .split('')
        .map((char) => char + char)
        .join('')
        .toLowerCase()}`;
    }
    return `#${hex.toLowerCase()}`;
  }

  function hexToRgb(color) {
    const hex = normalizeColor(color);
    return {
      r: parseInt(hex.slice(1, 3), 16),
      g: parseInt(hex.slice(3, 5), 16),
      b: parseInt(hex.slice(5, 7), 16),
    };
  }

  function rgbToHex(r, g, b) {
    return `#${[r, g, b]
      .map((component) => Math.max(0, Math.min(255, component)).toString(16).padStart(2, '0'))
      .join('')}`;
  }

  function mixWithWhite(color, ratio) {
    const { r, g, b } = hexToRgb(color);
    const safeRatio = Math.max(0, Math.min(1, ratio));
    const mixed = {
      r: Math.round(r + (255 - r) * safeRatio),
      g: Math.round(g + (255 - g) * safeRatio),
      b: Math.round(b + (255 - b) * safeRatio),
    };
    return rgbToHex(mixed.r, mixed.g, mixed.b);
  }

  function pickTextColor(background) {
    const { r, g, b } = hexToRgb(background);
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return luminance > 0.6 ? '#0f172a' : '#ffffff';
  }

  function createMetaBadge(text) {
    const badge = document.createElement('span');
    badge.textContent = text;
    return badge;
  }

  function addDays(dateObject, days) {
    const clone = new Date(dateObject);
    clone.setDate(clone.getDate() + days);
    return clone;
  }

  function startOfWeek(dateObject) {
    const clone = new Date(dateObject);
    const day = clone.getDay(); // 0 (Sun) - 6 (Sat)
    const diff = (day === 0 ? -6 : 1) - day; // move to Monday
    return addDays(clone, diff);
  }

  function computeRange() {
    const anchor = parseDate(state.anchorDate) || parseDate(todayISO());
    if (!anchor) {
      return;
    }

    if (state.view === 'day') {
      state.date = formatDate(anchor);
      state.rangeStart = state.date;
      state.rangeEnd = state.date;
      return;
    }

    if (state.view === 'week') {
      const monday = startOfWeek(anchor);
      const sunday = addDays(monday, 6);
      state.rangeStart = formatDate(monday);
      state.rangeEnd = formatDate(sunday);
      return;
    }

    // month view
    const first = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
    const last = new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0);
    state.rangeStart = formatDate(first);
    state.rangeEnd = formatDate(last);
  }

  function setView(nextView) {
    if (state.view === nextView) {
      return;
    }
    state.view = nextView;
    computeRange();
    fetchData(true);
  }

  function setAnchorDate(value) {
    if (!value) {
      return;
    }
    state.anchorDate = value;
    computeRange();
    fetchData(true);
  }

  function fetchData(force) {
    if (state.loading && !force) {
      return;
    }
    state.loading = true;
    state.error = '';
    render();

    const params = new URLSearchParams();
    params.set('view', state.view);
    if (state.view === 'day') {
      params.set('date', state.date);
    } else {
      params.set('start', state.rangeStart);
      params.set('end', state.rangeEnd);
    }

    const url = `${cfg.endpoint}?${params.toString()}`;

    fetch(url, {
      headers: {
        'X-WP-Nonce': cfg.nonce || '',
      },
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error(response.statusText || i18n('Kan planboard-gegevens niet laden.', 'sbdp'));
        }
        return response.json();
      })
      .then((data) => {
        state.data = data || null;
        if (state.data && state.data.view === 'day' && state.data.date) {
          state.dayTimelineCache[state.data.date] = Array.isArray(state.data.timeline) ? state.data.timeline : [];
        }
      })
      .catch((error) => {
        state.error = error.message || i18n('Onbekende fout bij het ophalen van de planning.', 'sbdp');
      })
      .finally(() => {
        state.loading = false;
        render();
      });
  }

  function render() {
    root.innerHTML = '';
    root.appendChild(renderToolbar());

    if (state.error) {
      const error = document.createElement('div');
      error.className = 'notice notice-error sbdp-planboard-notice';
      error.textContent = state.error;
      root.appendChild(error);
    }

    if (state.loading) {
      const loading = document.createElement('p');
      loading.className = 'sbdp-planboard-loading';
      loading.textContent = i18n('Bezig met laden...', 'sbdp');
      root.appendChild(loading);
      return;
    }

    if (!state.data) {
      const empty = document.createElement('p');
      empty.className = 'sbdp-planboard-empty';
      empty.textContent = i18n('Geen planboard-gegevens beschikbaar.', 'sbdp');
      root.appendChild(empty);
      return;
    }

  if (state.view === 'day') {
    root.appendChild(renderDayTimeline(state.data));
  } else {
    root.appendChild(renderRangeOverview(state.data));
  }
}

  function renderToolbar() {
    const toolbar = document.createElement('div');
    toolbar.className = 'sbdp-planboard-toolbar';

    const viewToggle = document.createElement('div');
    viewToggle.className = 'sbdp-planboard-view-toggle';
    VIEWS.forEach((viewKey) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = state.view === viewKey ? 'button button-primary' : 'button button-secondary';
      button.textContent =
        viewKey === 'day'
          ? i18n('Per dag', 'sbdp')
          : viewKey === 'week'
          ? i18n('Per week', 'sbdp')
          : i18n('Per maand', 'sbdp');
      button.addEventListener('click', () => setView(viewKey));
      viewToggle.appendChild(button);
    });
    toolbar.appendChild(viewToggle);

    const dateLabel = document.createElement('label');
    dateLabel.className = 'sbdp-planboard-date';
    dateLabel.textContent = i18n('Kies datum', 'sbdp');
    const dateInput = document.createElement('input');
    dateInput.type = 'date';
    dateInput.value = state.anchorDate;
    dateInput.addEventListener('change', (event) => {
      if (event.target.value) {
        setAnchorDate(event.target.value);
      }
    });
    dateLabel.appendChild(dateInput);
    toolbar.appendChild(dateLabel);

    const rangeInfo = document.createElement('div');
    rangeInfo.className = 'sbdp-planboard-range';
    if (state.view === 'day') {
      rangeInfo.textContent = formatHumanDate(state.date);
    } else {
      rangeInfo.textContent = `${formatHumanDate(state.rangeStart)} – ${formatHumanDate(state.rangeEnd)}`;
    }
    toolbar.appendChild(rangeInfo);

    const refresh = document.createElement('button');
    refresh.type = 'button';
    refresh.className = 'button button-secondary';
    refresh.textContent = i18n('Vernieuw', 'sbdp');
    refresh.addEventListener('click', () => fetchData(true));
    toolbar.appendChild(refresh);

    if (hasEndpoint('create') || hasEndpoint('closures')) {
      const actions = document.createElement('div');
      actions.className = 'sbdp-planboard-actions';

      if (hasEndpoint('create')) {
        const createButton = document.createElement('button');
        createButton.type = 'button';
        createButton.className = 'button button-primary';
        createButton.textContent = i18n('Nieuwe boeking', 'sbdp');
        createButton.addEventListener('click', () => openCreateModal());
        actions.appendChild(createButton);
      }

      if (hasEndpoint('closures')) {
        const rulesButton = document.createElement('button');
        rulesButton.type = 'button';
        rulesButton.className = 'button button-secondary';
        rulesButton.textContent = i18n('Sluitregels', 'sbdp');
        rulesButton.addEventListener('click', () => openRulesModal());
        actions.appendChild(rulesButton);
      }

      toolbar.appendChild(actions);
    }

    return toolbar;
  }

  function renderDayTimeline(data) {
    const container = document.createElement('div');
    container.className = 'sbdp-planboard';

    const timeline = Array.isArray(data.timeline) ? data.timeline : [];
    const hasBookings = (data.events || []).length > 0;

    if (!timeline.length) {
      const empty = document.createElement('p');
      empty.className = 'sbdp-planboard-empty';
      empty.textContent = hasBookings
        ? i18n('Geen resources gevonden voor deze dag.', 'sbdp')
        : i18n('Geen activiteiten voor deze dag.', 'sbdp');
      container.appendChild(empty);
      return container;
    }

    const dayStart = new Date(`${state.date}T06:00:00`);
    const dayEnd = new Date(`${state.date}T22:00:00`);
    const totalMs = dayEnd - dayStart;

    const legend = buildLegend(timeline);
    if (legend) {
      container.appendChild(legend);
    }

    container.appendChild(renderDayScale(dayStart, dayEnd));

    timeline.forEach((entry) => {
      const card = document.createElement('section');
      card.className = 'sbdp-planboard__resource';

      const resource = entry.resource || {};
      const stats = entry.stats && typeof entry.stats === 'object' ? entry.stats : {};
      const resourceName = resource.name || i18n('Niet toegewezen', 'sbdp');
      const resourceColor = normalizeColor(resource.color || '#2563eb');
      const capacityNumber = Number(resource.capacity);
      const capacityValue =
        Number.isFinite(capacityNumber) && capacityNumber > 0 ? capacityNumber : i18n('n.v.t.', 'sbdp');

      const title = document.createElement('div');
      title.className = 'sbdp-planboard__resource-title';

      const heading = document.createElement('div');
      heading.className = 'sbdp-planboard__resource-heading';

      const dot = document.createElement('span');
      dot.className = 'sbdp-planboard__resource-dot';
      dot.style.backgroundColor = resourceColor;
      heading.appendChild(dot);

      const name = document.createElement('strong');
      name.textContent = resourceName;
      heading.appendChild(name);

      title.appendChild(heading);

      const meta = document.createElement('div');
      meta.className = 'sbdp-planboard__resource-meta';
      meta.appendChild(createMetaBadge(`${i18n('Capaciteit', 'sbdp')}: ${capacityValue}`));
      meta.appendChild(createMetaBadge(`${stats.bookings || 0} ${i18n('boekingen', 'sbdp')}`));
      meta.appendChild(createMetaBadge(`${stats.participants || 0} ${i18n('deelnemers', 'sbdp')}`));
      title.appendChild(meta);

      card.appendChild(title);

      const timelineRow = document.createElement('div');
      timelineRow.className = 'sbdp-planboard__timeline';
      timelineRow.setAttribute('aria-label', `${resourceName} ${i18n('planning', 'sbdp')}`);
      if (hasEndpoint('move')) {
        timelineRow.dataset.resourceId = String(resource.id || 0);
        timelineRow.addEventListener('dragover', (event) => {
          event.preventDefault();
        });
        timelineRow.addEventListener('drop', (event) => {
          event.preventDefault();
          handleDrop(event, resource);
        });
      }
      const segments = Array.isArray(entry.segments) ? entry.segments : [];

      segments.forEach((segment) => {
        timelineRow.appendChild(createSegmentElement(segment, dayStart, dayEnd, totalMs, resourceColor, resource));
      });

      card.appendChild(timelineRow);

      const slotWrap = document.createElement('div');
      slotWrap.className = 'sbdp-planboard__slots';
      const slotHeading = document.createElement('h4');
      slotHeading.textContent = i18n('Beschikbare sloten', 'sbdp');
      slotWrap.appendChild(slotHeading);

      const slotList = document.createElement('ul');
      slotList.className = 'sbdp-planboard__slots-list';
      const slotEntries = (entry.available_slots || []).slice(0, 18);
      if (!slotEntries.length) {
        const slotEmpty = document.createElement('li');
        slotEmpty.className = 'sbdp-planboard__slots-empty';
        slotEmpty.textContent = i18n('Geen vrije sloten op deze dag.', 'sbdp');
        slotList.appendChild(slotEmpty);
      } else {
        slotEntries.forEach((slot) => {
          const li = document.createElement('li');
          li.textContent = `${slot.start} – ${slot.end}`;
          slotList.appendChild(li);
        });
        if ((entry.available_slots || []).length > slotEntries.length) {
          const more = document.createElement('li');
          more.className = 'sbdp-planboard__slots-more';
          more.textContent = i18n('... meer sloten beschikbaar', 'sbdp');
          slotList.appendChild(more);
        }
      }
      slotWrap.appendChild(slotList);
      card.appendChild(slotWrap);

      container.appendChild(card);
    });

    return container;
  }

  function renderDayScale(dayStart, dayEnd) {
    const scale = document.createElement('div');
    scale.className = 'sbdp-planboard__scale';
    scale.setAttribute('aria-hidden', 'true');

    const startHour = dayStart.getHours();
    const endHour = dayEnd.getHours();
    for (let hour = startHour; hour <= endHour; hour += 2) {
      const marker = document.createElement('span');
      marker.textContent = `${String(hour).padStart(2, '0')}:00`;
      scale.appendChild(marker);
    }

    return scale;
  }

  function createSegmentElement(segment, dayStart, dayEnd, totalMs, fallbackColor = '#2563eb', resourceMeta) {
    const element = document.createElement('div');
    const type = segment.type === 'booking' ? 'booking' : 'available';
    element.className = `sbdp-planboard__segment sbdp-planboard__segment--${type}`;

    const baseColor =
      type === 'booking'
        ? segment.event && segment.event.resource && segment.event.resource.color
        : segment.resource_color;
    const resourceColor = normalizeColor(baseColor || fallbackColor);
    const backgroundColor = type === 'booking' ? mixWithWhite(resourceColor, 0.25) : mixWithWhite(resourceColor, 0.9);
    element.style.backgroundColor = backgroundColor;
    element.style.borderColor = mixWithWhite(resourceColor, 0.6);
    element.style.color = pickTextColor(backgroundColor);
    element.dataset.resourceColor = resourceColor;

    let label = formatTimeRange(segment.start, segment.end);
    if (!label && typeof segment.label === 'string') {
      label = segment.label;
    }

    if (type === 'booking' && segment.event) {
      const customer = segment.event.customer || '';
      const participants = segment.event.participants ? `${segment.event.participants} ${i18n('deelnemers', 'sbdp')}` : '';
      const orderStatus = segment.event.order_status || '';
      element.innerHTML = `
        <strong>${escapeHtml(segment.event.product_name || i18n('Activiteit', 'sbdp'))}</strong>
        <span class="sbdp-planboard__segment-time">${escapeHtml(label)}</span>
        <span class="sbdp-planboard__segment-meta">
          ${customer ? `<span>${escapeHtml(customer)}</span>` : ''}
          ${participants ? `<span>${escapeHtml(participants)}</span>` : ''}
          ${orderStatus ? `<span>${escapeHtml(orderStatus)}</span>` : ''}
        </span>
      `;
      const titleParts = [
        segment.event.product_name,
        segment.event.customer,
        segment.event.participants ? `${segment.event.participants} ${i18n('deelnemers', 'sbdp')}` : '',
      ].filter(Boolean);
      if (label) {
        titleParts.push(label);
      }
      element.title = titleParts.join(' | ');
      element.setAttribute('aria-label', titleParts.join(', '));

      const bookingPayload = buildBookingPayload(segment.event);
      if (bookingPayload) {
        element.classList.add('is-actionable');
        element.addEventListener('click', () => openBookingModal(segment.event));

        if (hasEndpoint('move')) {
          element.setAttribute('draggable', 'true');
          element.addEventListener('dragstart', (event) => {
            if (!event.dataTransfer) {
              return;
            }
            event.dataTransfer.setData('application/json', JSON.stringify(bookingPayload));
            event.dataTransfer.effectAllowed = 'move';
          });
        }
      }
    } else {
      const sections = [`<span>${i18n('Beschikbaar', 'sbdp')}</span>`];
      if (label) {
        sections.push(`<span>${label}</span>`);
      }
      if (segment.resource_capacity && segment.resource_capacity > 0) {
        sections.push(`<span>${i18n('Capaciteit', 'sbdp')}: ${segment.resource_capacity}</span>`);
      }
      element.innerHTML = sections.join('');
      const titleParts = [];
      if (label) {
        titleParts.push(`${i18n('Beschikbaar tussen', 'sbdp')} ${label}`);
      } else {
        titleParts.push(i18n('Beschikbaar', 'sbdp'));
      }
      if (segment.resource_capacity && segment.resource_capacity > 0) {
        titleParts.push(`${i18n('Capaciteit', 'sbdp')}: ${segment.resource_capacity}`);
      }
      element.title = titleParts.join(' | ');
    }

    if (segment.start && segment.end) {
      const segStart = clampDate(new Date(segment.start), dayStart, dayEnd);
      const segEnd = clampDate(new Date(segment.end), dayStart, dayEnd);
      const segmentMs = Math.max(0, segEnd - segStart);
      const width = totalMs > 0 ? Math.max((segmentMs / totalMs) * 100, 3) : 10;
      element.style.flexBasis = `${width}%`;
    } else {
      element.style.flex = '1 1 auto';
    }

    return element;
  }

  function clampDate(date, min, max) {
    if (date < min) {
      return new Date(min);
    }
    if (date > max) {
      return new Date(max);
    }
    return date;
  }

  function formatTime(value) {
    if (!value) {
      return '';
    }
    try {
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) {
        return value.slice(-8, -3);
      }
      return date.toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
      });
    } catch (error) {
      return value.slice(-8, -3);
    }
  }

  function formatTimeRange(startValue, endValue) {
    const startLabel = formatTime(startValue);
    const endLabel = formatTime(endValue);
    if (startLabel && endLabel) {
      return `${startLabel} - ${endLabel}`;
    }
    return startLabel || endLabel || '';
  }

  function formatCurrency(value) {
    const amount = Number(value || 0);
    if (!Number.isFinite(amount)) {
      return '€ 0,00';
    }
    return `€ ${amount.toFixed(2).replace('.', ',')}`;
  }

  function renderRangeOverview(data) {
    const calendar = document.createElement('div');
    calendar.className = `sbdp-planboard-calendar sbdp-planboard-calendar--${state.view}`;

    const days = Array.isArray(data.days) ? data.days : [];
    if (!days.length) {
      const empty = document.createElement('p');
      empty.className = 'sbdp-planboard-empty';
      empty.textContent = i18n('Geen activiteiten gevonden in dit bereik.', 'sbdp');
      calendar.appendChild(empty);
    return calendar;
  }

  days.forEach((day) => {
      const column = document.createElement('section');
      column.className = 'sbdp-planboard-calendar__day';

      const heading = document.createElement('header');
      heading.className = 'sbdp-planboard-calendar__day-header';

      const title = document.createElement('strong');
      title.textContent = formatHumanDate(day.date);
      heading.appendChild(title);

      const totals = document.createElement('span');
      totals.className = 'sbdp-planboard-calendar__day-meta';
      const totalEvents = typeof day.total_events === 'number' ? day.total_events : 0;
      const totalParticipants = typeof day.total_participants === 'number' ? day.total_participants : 0;
      totals.textContent = `${totalEvents} ${i18n('boekingen', 'sbdp')} | ${totalParticipants} ${i18n('deelnemers', 'sbdp')}`;
      heading.appendChild(totals);

      column.appendChild(heading);

      const eventList = document.createElement('ul');
      eventList.className = 'sbdp-planboard-calendar__events';
      const dayEvents = Array.isArray(day.events) ? day.events : [];

      if (!dayEvents.length) {
        const empty = document.createElement('li');
        empty.className = 'sbdp-planboard-calendar__events-empty';
        empty.textContent = i18n('Geen activiteiten gepland.', 'sbdp');
        eventList.appendChild(empty);
      } else {
        dayEvents.forEach((event) => {
          const item = document.createElement('li');
          item.className = 'sbdp-planboard-calendar__event';

          const resource = event.resource || {};
          const resourceColor = normalizeColor(resource.color || '#94a3b8');
          const resourceName = resource.name || i18n('Onbekende resource', 'sbdp');
          const timeRange = formatTimeRange(event.start, event.end);
          const participantsCount = event.participants && event.participants > 0 ? event.participants : 1;
          const participantsLabel = `${participantsCount} ${i18n('deelnemers', 'sbdp')}`;

          item.style.borderLeftColor = resourceColor;

          const header = document.createElement('div');
          header.className = 'sbdp-planboard-calendar__event-header';

          const dot = document.createElement('span');
          dot.className = 'sbdp-planboard-calendar__event-dot';
          dot.style.backgroundColor = resourceColor;
          header.appendChild(dot);

          const name = document.createElement('strong');
          name.textContent = event.product_name || i18n('Activiteit', 'sbdp');
          header.appendChild(name);

          item.appendChild(header);

          const meta = document.createElement('div');
          meta.className = 'sbdp-planboard-calendar__event-meta';

          [timeRange, resourceName, participantsLabel]
            .filter(Boolean)
            .forEach((detail) => {
              const span = document.createElement('span');
              span.textContent = detail;
              meta.appendChild(span);
            });

          item.appendChild(meta);

          if (buildBookingPayload(event)) {
            item.classList.add('is-actionable');
            item.addEventListener('click', () => openBookingModal(event));
          }

          eventList.appendChild(item);
        });
      }

      column.appendChild(eventList);
      calendar.appendChild(column);
    });

  return calendar;
}

  // Planboard v2 helpers.
  function buildBookingPayload(event) {
    if (!event || typeof event !== 'object') {
      return null;
    }
    const bookingId = Number(event.order_id || event.booking_id || 0);
    if (!Number.isFinite(bookingId) || bookingId <= 0) {
      return null;
    }

    return {
      booking_id: bookingId,
      start: event.start || '',
      end: event.end || '',
      resource_id: event.resource && event.resource.id ? Number(event.resource.id) : 0,
      product_name: event.product_name || '',
      customer: event.customer || '',
      participants: event.participants || 0,
      link: event.link || '',
      order_status: event.order_status || '',
    };
  }

  function openBookingModal(event) {
    const payload = buildBookingPayload(event);
    if (!payload) {
      return;
    }

    const body = document.createElement('div');
    body.className = 'sbdp-planboard-modal__content';

    const meta = document.createElement('div');
    meta.className = 'sbdp-planboard-detail';

    const header = document.createElement('strong');
    header.textContent = payload.product_name || i18n('Boeking', 'sbdp');
    meta.appendChild(header);

    const details = document.createElement('div');
    details.className = 'sbdp-planboard-detail__meta';
    const items = [
      payload.customer ? `${i18n('Klant', 'sbdp')}: ${payload.customer}` : '',
      payload.participants ? `${i18n('Deelnemers', 'sbdp')}: ${payload.participants}` : '',
      payload.start ? `${i18n('Start', 'sbdp')}: ${formatTime(payload.start)}` : '',
      payload.end ? `${i18n('Einde', 'sbdp')}: ${formatTime(payload.end)}` : '',
      payload.order_status ? `${i18n('Status', 'sbdp')}: ${payload.order_status}` : '',
      payload.booking_id ? `${i18n('Booking ID', 'sbdp')}: ${payload.booking_id}` : '',
    ];
    items.filter(Boolean).forEach((text) => {
      const row = document.createElement('span');
      row.textContent = text;
      details.appendChild(row);
    });

    meta.appendChild(details);
    body.appendChild(meta);

    if (payload.link) {
      const link = document.createElement('a');
      link.href = payload.link;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.className = 'button button-secondary';
      link.textContent = i18n('Open order', 'sbdp');
      body.appendChild(link);
    }

    const actions = [];
    if (hasEndpoint('move')) {
      actions.push({
        label: i18n('Verplaats', 'sbdp'),
        variant: 'primary',
        onClick: () => openMoveModal(payload),
      });
    }
    if (hasEndpoint('checkin')) {
      actions.push({
        label: i18n('Check-in', 'sbdp'),
        variant: 'secondary',
        onClick: () => openCheckinModal(payload),
      });
    }
    if (hasEndpoint('payment')) {
      actions.push({
        label: i18n('Betaling toevoegen', 'sbdp'),
        variant: 'secondary',
        onClick: () => openPaymentModal(payload),
      });
    }

    actions.push({
      label: i18n('Sluiten', 'sbdp'),
      variant: 'secondary',
      onClick: () => closeModal(),
    });

    createModal({
      title: i18n('Boeking details', 'sbdp'),
      body,
      actions,
    });
  }

  function openMoveModal(payload, targetResource) {
    if (!hasEndpoint('move')) {
      return;
    }

    const body = document.createElement('div');
    const notice = createNotice();
    const form = document.createElement('form');
    form.className = 'sbdp-planboard-form';

    const startInput = document.createElement('input');
    startInput.type = 'datetime-local';
    startInput.value = toInputDateTime(payload.start) || '';

    const endInput = document.createElement('input');
    endInput.type = 'datetime-local';
    const fallbackEnd = payload.end || addMinutes(payload.start, 60);
    endInput.value = toInputDateTime(fallbackEnd) || '';

    const resourceSelect = createResourceSelect(
      targetResource && targetResource.id ? Number(targetResource.id) : Number(payload.resource_id || 0)
    );

    const notes = document.createElement('textarea');
    notes.rows = 3;

    form.appendChild(buildFormRow(i18n('Start', 'sbdp'), startInput));
    form.appendChild(buildFormRow(i18n('Einde', 'sbdp'), endInput));
    form.appendChild(buildFormRow(i18n('Resource', 'sbdp'), resourceSelect));
    form.appendChild(buildFormRow(i18n('Notities', 'sbdp'), notes));

    const actions = document.createElement('div');
    actions.className = 'sbdp-planboard-form__actions';

    const submit = document.createElement('button');
    submit.type = 'submit';
    submit.className = 'button button-primary';
    submit.textContent = i18n('Opslaan', 'sbdp');
    actions.appendChild(submit);

    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'button button-secondary';
    cancel.textContent = i18n('Annuleren', 'sbdp');
    cancel.addEventListener('click', () => closeModal());
    actions.appendChild(cancel);

    form.appendChild(actions);

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      notice.textContent = '';

      const startIso = fromInputDateTime(startInput.value);
      const endIso = fromInputDateTime(endInput.value);
      if (!startIso || !endIso) {
        notice.textContent = i18n('Vul geldige start- en eindtijd in.', 'sbdp');
        return;
      }

      const resourceId = parseInt(resourceSelect.value || '0', 10) || 0;

      const data = {
        booking_id: payload.booking_id,
        start: startIso,
        end: endIso,
        resource_id: resourceId,
        notes: notes.value || '',
      };

      apiFetch(v2.move, {
        method: 'POST',
        headers: Object.assign({}, { 'Content-Type': 'application/json' }),
        body: JSON.stringify(data),
      })
        .then(() => {
          closeModal();
          fetchData(true);
        })
        .catch((error) => {
          notice.textContent = error.message || i18n('Verplaatsen mislukt.', 'sbdp');
        });
    });

    body.appendChild(notice);
    body.appendChild(form);

    createModal({
      title: i18n('Boeking verplaatsen', 'sbdp'),
      body,
    });
  }

  function openCheckinModal(payload) {
    if (!hasEndpoint('checkin')) {
      return;
    }

    const body = document.createElement('div');
    const notice = createNotice();
    const form = document.createElement('form');
    form.className = 'sbdp-planboard-form';

    const checkedAt = document.createElement('input');
    checkedAt.type = 'datetime-local';
    checkedAt.value = toInputDateTime(new Date().toISOString());

    const notes = document.createElement('textarea');
    notes.rows = 3;

    form.appendChild(buildFormRow(i18n('Check-in tijd', 'sbdp'), checkedAt));
    form.appendChild(buildFormRow(i18n('Notities', 'sbdp'), notes));

    const actions = document.createElement('div');
    actions.className = 'sbdp-planboard-form__actions';

    const submit = document.createElement('button');
    submit.type = 'submit';
    submit.className = 'button button-primary';
    submit.textContent = i18n('Bevestigen', 'sbdp');
    actions.appendChild(submit);

    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'button button-secondary';
    cancel.textContent = i18n('Annuleren', 'sbdp');
    cancel.addEventListener('click', () => closeModal());
    actions.appendChild(cancel);

    form.appendChild(actions);

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      notice.textContent = '';

      const data = {
        booking_id: payload.booking_id,
        checked_in_at: fromInputDateTime(checkedAt.value) || new Date().toISOString(),
        notes: notes.value || '',
      };

      apiFetch(v2.checkin, {
        method: 'POST',
        headers: Object.assign({}, { 'Content-Type': 'application/json' }),
        body: JSON.stringify(data),
      })
        .then(() => {
          closeModal();
          fetchData(true);
        })
        .catch((error) => {
          notice.textContent = error.message || i18n('Check-in mislukt.', 'sbdp');
        });
    });

    body.appendChild(notice);
    body.appendChild(form);

    createModal({
      title: i18n('Check-in registreren', 'sbdp'),
      body,
    });
  }

  function openPaymentModal(payload) {
    if (!hasEndpoint('payment')) {
      return;
    }

    const body = document.createElement('div');
    const notice = createNotice();
    const form = document.createElement('form');
    form.className = 'sbdp-planboard-form';

    const amount = document.createElement('input');
    amount.type = 'number';
    amount.step = '0.01';
    amount.min = '0';

    const currency = document.createElement('input');
    currency.type = 'text';
    currency.value = 'EUR';

    const method = document.createElement('input');
    method.type = 'text';
    method.value = 'manual';

    const reference = document.createElement('input');
    reference.type = 'text';

    const capturedAt = document.createElement('input');
    capturedAt.type = 'datetime-local';
    capturedAt.value = toInputDateTime(new Date().toISOString());

    const notes = document.createElement('textarea');
    notes.rows = 3;

    form.appendChild(buildFormRow(i18n('Bedrag', 'sbdp'), amount));
    form.appendChild(buildFormRow(i18n('Valuta', 'sbdp'), currency));
    form.appendChild(buildFormRow(i18n('Methode', 'sbdp'), method));
    form.appendChild(buildFormRow(i18n('Referentie', 'sbdp'), reference));
    form.appendChild(buildFormRow(i18n('Datum/tijd', 'sbdp'), capturedAt));
    form.appendChild(buildFormRow(i18n('Notities', 'sbdp'), notes));

    const actions = document.createElement('div');
    actions.className = 'sbdp-planboard-form__actions';

    const submit = document.createElement('button');
    submit.type = 'submit';
    submit.className = 'button button-primary';
    submit.textContent = i18n('Opslaan', 'sbdp');
    actions.appendChild(submit);

    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'button button-secondary';
    cancel.textContent = i18n('Annuleren', 'sbdp');
    cancel.addEventListener('click', () => closeModal());
    actions.appendChild(cancel);

    form.appendChild(actions);

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      notice.textContent = '';

      const data = {
        booking_id: payload.booking_id,
        amount: parseFloat(amount.value || '0'),
        currency: currency.value || 'EUR',
        method: method.value || 'manual',
        reference: reference.value || '',
        captured_at: fromInputDateTime(capturedAt.value) || null,
        notes: notes.value || '',
      };

      apiFetch(v2.payment, {
        method: 'POST',
        headers: Object.assign({}, { 'Content-Type': 'application/json' }),
        body: JSON.stringify(data),
      })
        .then(() => {
          closeModal();
          fetchData(true);
        })
        .catch((error) => {
          notice.textContent = error.message || i18n('Betaling opslaan mislukt.', 'sbdp');
        });
    });

    body.appendChild(notice);
    body.appendChild(form);

    createModal({
      title: i18n('Betaling toevoegen', 'sbdp'),
      body,
    });
  }

  function loadProducts(resourceId) {
    if (!hasEndpoint('products')) {
      return Promise.resolve([]);
    }

    const resourceKey = Number(resourceId || 0);
    if (state.productCatalog.loading) {
      return Promise.resolve(state.productCatalog.items);
    }
    if (state.productCatalog.loaded && state.productCatalog.resourceId === resourceKey) {
      return Promise.resolve(state.productCatalog.items);
    }

    state.productCatalog.loading = true;
    const params = new URLSearchParams();
    if (resourceKey > 0) {
      params.set('resource_id', String(resourceKey));
    }
    params.set('limit', '60');

    return apiFetch(`${v2.products}?${params.toString()}`, { method: 'GET' })
      .then((response) => {
        const products = response && Array.isArray(response.products) ? response.products : [];
        state.productCatalog.items = products;
        state.productCatalog.resourceId = resourceKey;
        state.productCatalog.loaded = true;
        return products;
      })
      .catch(() => state.productCatalog.items)
      .finally(() => {
        state.productCatalog.loading = false;
      });
  }

  function pickDefaultProductId(products, resourceId) {
    if (!Array.isArray(products) || products.length === 0) {
      return '';
    }
    const resourceKey = Number(resourceId || 0);
    if (resourceKey > 0) {
      const match = products.find((product) => productMatchesResource(product, resourceKey));
      if (match) {
        return String(match.id);
      }
    }
    return String(products[0].id || '');
  }

  function productMatchesResource(product, resourceId) {
    if (!product || resourceId <= 0) {
      return false;
    }
    if (Number(product.resource_id) === resourceId) {
      return true;
    }
    if (Array.isArray(product.resources)) {
      return product.resources.some((resource) => Number(resource.id) === resourceId);
    }
    return false;
  }

  function updateProductSelect(select, products, selectedId) {
    if (!select) {
      return;
    }
    select.innerHTML = '';
    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = i18n('Kies product', 'sbdp');
    select.appendChild(empty);
    (products || []).forEach((entry) => {
      const option = document.createElement('option');
      option.value = String(entry.id);
      option.textContent = entry.name || i18n('Product', 'sbdp');
      if (selectedId && Number(selectedId) === Number(entry.id)) {
        option.selected = true;
      }
      select.appendChild(option);
    });
  }

  function updateItemProductOptions(itemsList, products) {
    const selects = Array.from(itemsList.querySelectorAll('.sbdp-item-product'));
    selects.forEach((input) => {
      if (input.tagName !== 'SELECT') {
        return;
      }
      updateProductSelect(input, products, input.value);
    });
  }

  function buildStartIso(dateValue, timeValue) {
    if (!dateValue) {
      return '';
    }
    const time = timeValue || '00:00';
    const candidate = `${dateValue}T${time}:00`;
    const date = new Date(candidate);
    if (Number.isNaN(date.getTime())) {
      return '';
    }
    return date.toISOString();
  }

  function parseTimeToMinutes(value) {
    if (!value || typeof value !== 'string') {
      return null;
    }
    const parts = value.split(':').map((part) => parseInt(part, 10));
    if (parts.length < 2 || !Number.isFinite(parts[0]) || !Number.isFinite(parts[1])) {
      return null;
    }
    return parts[0] * 60 + parts[1];
  }

  function minutesToTime(minutes) {
    if (!Number.isFinite(minutes)) {
      return '';
    }
    const safe = Math.max(0, Math.min(23 * 60 + 59, Math.round(minutes)));
    const hours = String(Math.floor(safe / 60)).padStart(2, '0');
    const mins = String(safe % 60).padStart(2, '0');
    return `${hours}:${mins}`;
  }

  function resolveSlotMinutes(slots) {
    if (!Array.isArray(slots) || slots.length === 0) {
      return 30;
    }
    const start = parseTimeToMinutes(slots[0].start);
    const end = parseTimeToMinutes(slots[0].end);
    if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) {
      return 30;
    }
    return end - start;
  }

  function resolveProductDuration(product) {
    if (!product || typeof product !== 'object') {
      return 60;
    }
    const duration = product.duration && typeof product.duration === 'object'
      ? Number(product.duration.minutes || product.duration.value || 0)
      : 0;
    if (Number.isFinite(duration) && duration > 0) {
      return duration;
    }
    return 60;
  }

  function filterSlotsByDuration(slots, durationMinutes) {
    if (!Array.isArray(slots) || slots.length === 0) {
      return [];
    }
    const slotMinutes = resolveSlotMinutes(slots);
    const required = Math.max(1, Math.ceil(durationMinutes / slotMinutes));
    const sorted = slots
      .map((slot) => ({
        start: slot.start,
        end: slot.end,
        startMinutes: parseTimeToMinutes(slot.start),
      }))
      .filter((slot) => Number.isFinite(slot.startMinutes))
      .sort((a, b) => a.startMinutes - b.startMinutes);
    const startSet = new Set(sorted.map((slot) => slot.startMinutes));
    const results = [];

    sorted.forEach((slot) => {
      let ok = true;
      for (let step = 0; step < required; step += 1) {
        if (!startSet.has(slot.startMinutes + step * slotMinutes)) {
          ok = false;
          break;
        }
      }
      if (ok) {
        results.push({
          start: slot.start,
          end: minutesToTime(slot.startMinutes + durationMinutes),
        });
      }
    });

    return results;
  }

  function fetchDayTimeline(dateValue) {
    if (!dateValue) {
      return Promise.resolve([]);
    }
    if (state.data && state.data.view === 'day' && state.data.date === dateValue) {
      return Promise.resolve(Array.isArray(state.data.timeline) ? state.data.timeline : []);
    }
    if (state.dayTimelineCache[dateValue]) {
      return Promise.resolve(state.dayTimelineCache[dateValue]);
    }

    const params = new URLSearchParams();
    params.set('view', 'day');
    params.set('date', dateValue);

    return apiFetch(`${cfg.endpoint}?${params.toString()}`, { method: 'GET' })
      .then((data) => {
        const timeline = data && Array.isArray(data.timeline) ? data.timeline : [];
        state.dayTimelineCache[dateValue] = timeline;
        return timeline;
      })
      .catch(() => []);
  }

  function getAvailableSlots(dateValue, resourceId) {
    const resourceKey = Number(resourceId || 0);
    if (!dateValue || resourceKey <= 0) {
      return Promise.resolve([]);
    }
    return fetchDayTimeline(dateValue).then((timeline) => {
      const match = (timeline || []).find((entry) => Number(entry.resource && entry.resource.id) === resourceKey);
      return match && Array.isArray(match.available_slots) ? match.available_slots : [];
    });
  }

  function refreshRowPricing(row, context) {
    if (!row || !context) {
      return;
    }
    const productInput = row.querySelector('.sbdp-item-product');
    const quantityInput = row.querySelector('.sbdp-item-quantity');
    const priceInput = row.querySelector('.sbdp-item-price');
    const totalInput = row.querySelector('.sbdp-item-total');

    if (!productInput || !priceInput) {
      return;
    }

    const productId = parseInt(productInput.value || '0', 10);
    if (!productId) {
      return;
    }

    const product = findProductById(state.productCatalog.items, productId);
    const supportsPersons = productSupportsPersons(product);
    row.dataset.supportsPersons = supportsPersons ? 'true' : 'false';
    if (quantityInput && !supportsPersons) {
      quantityInput.dataset.sync = 'false';
    }

    const itemParticipants = supportsPersons && quantityInput
      ? parsePositiveInteger(quantityInput.value, context.participants)
      : context.participants;
    const quantityFallback = Number.isFinite(itemParticipants) && itemParticipants > 0 ? itemParticipants : 1;
    const quantity = quantityInput
      ? parsePositiveInteger(quantityInput.value, quantityFallback)
      : itemParticipants;

    const applyFallback = () => {
      const unitPrice = resolveUnitPrice(product, itemParticipants);
      if (unitPrice > 0) {
        priceInput.value = unitPrice.toFixed(2);
        if (totalInput) {
          totalInput.textContent = formatCurrency(unitPrice * quantity);
        }
      }
    };

    if (!hasEndpoint('pricing') || !context.startIso) {
      applyFallback();
      return;
    }

    const payload = {
      start: context.startIso,
      resource_id: context.resourceId,
      participants: context.participants,
      items: [
        {
          product_id: productId,
          resource_id: context.resourceId,
          participants: itemParticipants,
          quantity: quantity,
        },
      ],
    };

    apiFetch(v2.pricing, {
      method: 'POST',
      headers: Object.assign({}, { 'Content-Type': 'application/json' }),
      body: JSON.stringify(payload),
    })
      .then((response) => {
        const items = response && Array.isArray(response.items) ? response.items : [];
        if (items.length > 0 && typeof items[0].unit_price === 'number') {
          priceInput.value = items[0].unit_price.toFixed(2);
          if (totalInput) {
            totalInput.textContent = formatCurrency(items[0].unit_price * quantity);
          }
        } else {
          applyFallback();
        }
      })
      .catch(() => applyFallback());
  }

  function findProductById(products, id) {
    const numeric = Number(id || 0);
    if (!Array.isArray(products) || numeric <= 0) {
      return null;
    }
    return products.find((product) => Number(product.id) === numeric) || null;
  }

  function resolveUnitPrice(product, participants) {
    if (!product) {
      return 0;
    }
    const pricing = product.pricing || {};
    const supportsPersons = !!pricing.supports_persons;
    const perPerson = Number(pricing.per_person || 0);
    const base = Number(pricing.base || 0);
    if (supportsPersons && perPerson > 0) {
      return perPerson;
    }
    if (base > 0) {
      return base;
    }
    if (typeof product.price_pp === 'number' && product.price_pp > 0) {
      return product.price_pp;
    }
    return 0;
  }

  function productSupportsPersons(product) {
    if (!product || typeof product !== 'object') {
      return false;
    }
    const pricing = product.pricing || {};
    const supportsPersons = !!pricing.supports_persons;
    const perPerson = Number(pricing.per_person || 0);
    const pricePp = Number(product.price_pp || 0);
    return supportsPersons || perPerson > 0 || pricePp > 0;
  }

  function syncPrimaryItem(itemsList, product, participants) {
    const row = itemsList.querySelector('.sbdp-planboard-items__row');
    if (!row || !product) {
      return;
    }
    const productInput = row.querySelector('.sbdp-item-product');
    const priceInput = row.querySelector('.sbdp-item-price');
    const quantityInput = row.querySelector('.sbdp-item-quantity');
    const totalInput = row.querySelector('.sbdp-item-total');
    if (productInput) {
      productInput.value = String(product.id || '');
    }
    if (priceInput) {
      const price = resolveUnitPrice(product, participants);
      if (price > 0) {
        priceInput.value = price.toFixed(2);
        if (totalInput && quantityInput) {
          const quantity = parsePositiveInteger(quantityInput.value, participants) || participants;
          totalInput.textContent = formatCurrency(price * quantity);
        }
      }
    }
  }

  function openCreateModal() {
    if (!hasEndpoint('create')) {
      return;
    }

    const body = document.createElement('div');
    const notice = createNotice();
    const form = document.createElement('form');
    form.className = 'sbdp-planboard-form';

    const dateInput = document.createElement('input');
    dateInput.type = 'date';
    dateInput.value = state.date || state.anchorDate || todayISO();

    const timeInput = document.createElement('select');

    const dateEnd = document.createElement('input');
    dateEnd.type = 'date';
    dateEnd.value = dateInput.value;

    const timeEnd = document.createElement('input');
    timeEnd.type = 'time';
    timeEnd.value = '10:00';

    const participants = document.createElement('input');
    participants.type = 'number';
    participants.min = '1';
    participants.value = '1';

    const name = document.createElement('input');
    name.type = 'text';

    const email = document.createElement('input');
    email.type = 'email';

    const resourceSelect = createResourceSelect(0);

    const notes = document.createElement('textarea');
    notes.rows = 3;

    form.appendChild(buildFormRow(i18n('Datum', 'sbdp'), dateInput));
    form.appendChild(buildFormRow(i18n('Starttijd', 'sbdp'), timeInput));
    form.appendChild(buildFormRow(i18n('Deelnemers', 'sbdp'), participants));
    form.appendChild(buildFormRow(i18n('Klantnaam', 'sbdp'), name));
    form.appendChild(buildFormRow(i18n('E-mail', 'sbdp'), email));
    form.appendChild(buildFormRow(i18n('Resource', 'sbdp'), resourceSelect));

    const itemsWrap = document.createElement('div');
    itemsWrap.className = 'sbdp-planboard-items';

    const itemsHeader = document.createElement('strong');
    itemsHeader.textContent = i18n('Items', 'sbdp');
    itemsWrap.appendChild(itemsHeader);

    const itemsList = document.createElement('div');
    itemsList.className = 'sbdp-planboard-items__list';
    itemsWrap.appendChild(itemsList);

    const getContext = () => ({
      participants: parsePositiveInteger(participants.value, 1),
      resourceId: parseInt(resourceSelect.value || '0', 10) || 0,
      startIso: buildStartIso(dateInput.value, timeInput.value),
    });
    const getProductResourceId = (productId) => {
      const lookup = findProductById(state.productCatalog.items, productId);
      if (!lookup) {
        return 0;
      }
      if (Number(lookup.resource_id) > 0) {
        return Number(lookup.resource_id);
      }
      if (Array.isArray(lookup.resources) && lookup.resources.length) {
        const candidate = lookup.resources.find((entry) => Number(entry.id) > 0);
        return candidate ? Number(candidate.id) : 0;
      }
      return 0;
    };
    const syncResourceSelect = (productId) => {
      const target = getProductResourceId(productId);
      if (target > 0 && resourceSelect) {
        resourceSelect.value = String(target);
      }
    };

    let timeRequest = 0;
    const updateTimeOptions = () => {
      const requestId = (timeRequest += 1);
      const dateValue = dateInput.value;
      const resourceId = parseInt(resourceSelect.value || '0', 10) || 0;
      const primaryRow = itemsList.querySelector('.sbdp-planboard-items__row');
      const productInput = primaryRow ? primaryRow.querySelector('.sbdp-item-product') : null;
      const productId = productInput ? parseInt(productInput.value || '0', 10) : 0;
      const product = productId ? findProductById(state.productCatalog.items, productId) : null;
      const durationMinutes = resolveProductDuration(product);

      timeInput.innerHTML = '';

      if (!dateValue || resourceId <= 0) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = i18n('Selecteer eerst een resource', 'sbdp');
        timeInput.appendChild(option);
        timeInput.setAttribute('disabled', 'true');
        return;
      }

      getAvailableSlots(dateValue, resourceId).then((slots) => {
        if (requestId !== timeRequest) {
          return;
        }

        const options = filterSlotsByDuration(slots, durationMinutes);
        timeInput.innerHTML = '';

        if (!options.length) {
          const option = document.createElement('option');
          option.value = '';
          option.textContent = i18n('Geen tijdsloten beschikbaar', 'sbdp');
          timeInput.appendChild(option);
          timeInput.setAttribute('disabled', 'true');
          return;
        }

        options.forEach((slot) => {
          const option = document.createElement('option');
          option.value = slot.start;
          option.textContent = `${slot.start} - ${slot.end}`;
          option.dataset.end = slot.end || '';
          timeInput.appendChild(option);
        });
        timeInput.removeAttribute('disabled');

        if (options.some((slot) => slot.start === timeInput.value)) {
          return;
        }
        timeInput.value = options[0].start;
        if (timeInput.selectedOptions.length > 0) {
          const endValue = timeInput.selectedOptions[0].dataset.end;
          if (endValue) {
            timeEnd.value = endValue;
          }
        }
      });
    };

    const setupItemRow = (row) => {
      if (!row) {
        return;
      }
      const productInput = row.querySelector('.sbdp-item-product');
      const quantityInput = row.querySelector('.sbdp-item-quantity');
      const priceInput = row.querySelector('.sbdp-item-price');

      const applyQuantity = () => {
        const context = getContext();
        if (quantityInput && quantityInput.dataset.sync === 'true') {
          quantityInput.value = String(context.participants);
        }
      };

      if (productInput) {
        productInput.addEventListener('change', () => {
          const context = getContext();
          const productId = parseInt(productInput.value || '0', 10);
          const product = findProductById(state.productCatalog.items, productId);
          const supportsPersons = productSupportsPersons(product);
          row.dataset.supportsPersons = supportsPersons ? 'true' : 'false';
          if (quantityInput) {
            if (supportsPersons) {
              if (quantityInput.dataset.sync !== 'false') {
                quantityInput.dataset.sync = 'true';
                quantityInput.value = String(context.participants);
              }
            } else {
              quantityInput.dataset.sync = 'false';
            }
          }
          if (productId) {
            syncResourceSelect(productId);
          }
          refreshRowPricing(row, context);
          updateTimeOptions();
        });
      }
      if (quantityInput) {
        quantityInput.addEventListener('change', () => {
          const context = getContext();
          const quantity = parsePositiveInteger(quantityInput.value, context.participants) || context.participants;
          quantityInput.value = String(quantity);
          if (row.dataset.supportsPersons === 'true') {
            quantityInput.dataset.sync = quantity === context.participants ? 'true' : 'false';
          } else {
            quantityInput.dataset.sync = 'false';
          }
          refreshRowPricing(row, context);
        });
      }
      if (priceInput) {
        priceInput.addEventListener('change', () => {
          const quantity = quantityInput ? parsePositiveInteger(quantityInput.value, 1) : 1;
          const unit = parseFloat(priceInput.value || '0');
          const total = row.querySelector('.sbdp-item-total');
          if (total && Number.isFinite(unit)) {
            total.textContent = formatCurrency(unit * quantity);
          }
        });
      }

      applyQuantity();
      refreshRowPricing(row, getContext());
    };

    const addItemButton = document.createElement('button');
    addItemButton.type = 'button';
    addItemButton.className = 'button button-secondary';
    addItemButton.textContent = i18n('Item toevoegen', 'sbdp');
    addItemButton.addEventListener('click', () => {
      const row = addItemRow(itemsList, state.productCatalog.items);
      setupItemRow(row);
    });
    itemsWrap.appendChild(addItemButton);

    const initialRow = addItemRow(itemsList, state.productCatalog.items);
    setupItemRow(initialRow);

    form.appendChild(itemsWrap);

    const advancedWrap = document.createElement('div');
    advancedWrap.className = 'sbdp-planboard-advanced is-collapsed';
    advancedWrap.appendChild(buildFormRow(i18n('Einddatum', 'sbdp'), dateEnd));
    advancedWrap.appendChild(buildFormRow(i18n('Eindtijd', 'sbdp'), timeEnd));
    advancedWrap.appendChild(buildFormRow(i18n('Notities', 'sbdp'), notes));

    const advancedToggle = document.createElement('button');
    advancedToggle.type = 'button';
    advancedToggle.className = 'button button-secondary';
    advancedToggle.textContent = i18n('Meer opties', 'sbdp');
    advancedToggle.addEventListener('click', () => {
      const collapsed = advancedWrap.classList.toggle('is-collapsed');
      advancedToggle.textContent = collapsed ? i18n('Meer opties', 'sbdp') : i18n('Minder opties', 'sbdp');
    });

    form.appendChild(advancedToggle);
    form.appendChild(advancedWrap);

    const refreshProducts = () => {
      if (!hasEndpoint('products')) {
        return;
      }
      const context = getContext();
      loadProducts(context.resourceId).then((products) => {
        const firstProductInput = itemsList.querySelector('.sbdp-item-product');
        if (products.length > 0 && firstProductInput && firstProductInput.tagName === 'INPUT' && itemsList.children.length === 1) {
          itemsList.innerHTML = '';
          const row = addItemRow(itemsList, products);
          setupItemRow(row);
        }

        updateItemProductOptions(itemsList, products);

        const defaultProductId = pickDefaultProductId(products, context.resourceId);
        if (defaultProductId) {
          syncResourceSelect(Number(defaultProductId));
        }

        Array.from(itemsList.querySelectorAll('.sbdp-planboard-items__row')).forEach((row) => {
          const productSelect = row.querySelector('.sbdp-item-product');
          if (productSelect && productSelect.tagName === 'SELECT' && !productSelect.value) {
            const defaultId = pickDefaultProductId(products, context.resourceId);
            if (defaultId) {
              productSelect.value = defaultId;
            }
          }
          refreshRowPricing(row, getContext());
        });
        updateTimeOptions();
      });
    };
    resourceSelect.addEventListener('change', () => {
      refreshProducts();
      updateTimeOptions();
    });
    participants.addEventListener('change', () => {
      Array.from(itemsList.querySelectorAll('.sbdp-planboard-items__row')).forEach((row) => {
        const quantityInput = row.querySelector('.sbdp-item-quantity');
        if (quantityInput && quantityInput.dataset.sync === 'true') {
          quantityInput.value = String(parsePositiveInteger(participants.value, 1));
        }
        refreshRowPricing(row, getContext());
      });
    });
    dateInput.addEventListener('change', () => {
      dateEnd.value = dateInput.value || dateEnd.value;
      updateTimeOptions();
      Array.from(itemsList.querySelectorAll('.sbdp-planboard-items__row')).forEach((row) => refreshRowPricing(row, getContext()));
    });
    timeInput.addEventListener('change', () => {
      if (timeInput.selectedOptions && timeInput.selectedOptions.length > 0) {
        const endValue = timeInput.selectedOptions[0].dataset.end;
        if (endValue) {
          timeEnd.value = endValue;
        }
      }
      Array.from(itemsList.querySelectorAll('.sbdp-planboard-items__row')).forEach((row) => refreshRowPricing(row, getContext()));
    });

    refreshProducts();
    updateTimeOptions();

    const actions = document.createElement('div');
    actions.className = 'sbdp-planboard-form__actions';

    const submit = document.createElement('button');
    submit.type = 'submit';
    submit.className = 'button button-primary';
    submit.textContent = i18n('Boeking aanmaken', 'sbdp');
    actions.appendChild(submit);

    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'button button-secondary';
    cancel.textContent = i18n('Annuleren', 'sbdp');
    cancel.addEventListener('click', () => closeModal());
    actions.appendChild(cancel);

    form.appendChild(actions);

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      notice.textContent = '';

      const items = collectItems(itemsList);
      if (!items.length) {
        notice.textContent = i18n('Voeg minimaal 1 item toe.', 'sbdp');
        return;
      }

      if (!name.value || !email.value) {
        notice.textContent = i18n('Vul klantnaam en e-mail in.', 'sbdp');
        return;
      }

      if (!timeInput.value) {
        notice.textContent = i18n('Selecteer een starttijd.', 'sbdp');
        return;
      }

      const resourceId = parseInt(resourceSelect.value || '0', 10) || 0;

      const data = {
        date: dateInput.value,
        time: timeInput.value || '09:00',
        date_end: dateEnd.value || dateInput.value,
        time_end: timeEnd.value || timeInput.value,
        participants: parsePositiveInteger(participants.value, 1),
        customer: {
          name: name.value,
          email: email.value,
        },
        items: items,
        notes: notes.value || '',
        currency: 'EUR',
        channel: 'manual',
        resource_id: resourceId,
      };

      apiFetch(v2.create, {
        method: 'POST',
        headers: Object.assign({}, { 'Content-Type': 'application/json' }),
        body: JSON.stringify(data),
      })
        .then(() => {
          closeModal();
          fetchData(true);
        })
        .catch((error) => {
          notice.textContent = error.message || i18n('Boeking aanmaken mislukt.', 'sbdp');
        });
    });

    body.appendChild(notice);
    body.appendChild(form);

    createModal({
      title: i18n('Nieuwe boeking', 'sbdp'),
      body,
    });
  }

  function openRulesModal() {
    if (!hasEndpoint('closures')) {
      return;
    }

    const body = document.createElement('div');
    const notice = createNotice();
    const rulesContainer = document.createElement('div');
    rulesContainer.className = 'sbdp-planboard-rules';

    const list = document.createElement('div');
    list.className = 'sbdp-planboard-rules__list';

    const form = document.createElement('form');
    form.className = 'sbdp-planboard-form';

    const typeSelect = document.createElement('select');
    [
      { value: 'one_off', label: i18n('Eenmalig', 'sbdp') },
      { value: 'recurring', label: i18n('Terugkerend', 'sbdp') },
    ].forEach((optionData) => {
      const option = document.createElement('option');
      option.value = optionData.value;
      option.textContent = optionData.label;
      typeSelect.appendChild(option);
    });

    const resourceSelect = createResourceSelect(0);

    const oneOffWrap = document.createElement('div');
    const oneOffStart = document.createElement('input');
    oneOffStart.type = 'datetime-local';
    const oneOffEnd = document.createElement('input');
    oneOffEnd.type = 'datetime-local';
    oneOffWrap.appendChild(buildFormRow(i18n('Start', 'sbdp'), oneOffStart));
    oneOffWrap.appendChild(buildFormRow(i18n('Einde', 'sbdp'), oneOffEnd));

    const recurringWrap = document.createElement('div');
    const weekday = document.createElement('select');
    const weekdays = [
      i18n('Zondag', 'sbdp'),
      i18n('Maandag', 'sbdp'),
      i18n('Dinsdag', 'sbdp'),
      i18n('Woensdag', 'sbdp'),
      i18n('Donderdag', 'sbdp'),
      i18n('Vrijdag', 'sbdp'),
      i18n('Zaterdag', 'sbdp'),
    ];
    weekdays.forEach((label, index) => {
      const option = document.createElement('option');
      option.value = String(index);
      option.textContent = label;
      weekday.appendChild(option);
    });

    const startTime = document.createElement('input');
    startTime.type = 'time';
    startTime.value = '09:00';
    const endTime = document.createElement('input');
    endTime.type = 'time';
    endTime.value = '10:00';
    recurringWrap.appendChild(buildFormRow(i18n('Weekdag', 'sbdp'), weekday));
    recurringWrap.appendChild(buildFormRow(i18n('Starttijd', 'sbdp'), startTime));
    recurringWrap.appendChild(buildFormRow(i18n('Eindtijd', 'sbdp'), endTime));

    const notes = document.createElement('textarea');
    notes.rows = 3;

    const editState = { id: null };

    const updateVisibility = () => {
      const isRecurring = typeSelect.value === 'recurring';
      recurringWrap.style.display = isRecurring ? 'block' : 'none';
      oneOffWrap.style.display = isRecurring ? 'none' : 'block';
    };
    typeSelect.addEventListener('change', updateVisibility);
    updateVisibility();

    form.appendChild(buildFormRow(i18n('Type', 'sbdp'), typeSelect));
    form.appendChild(buildFormRow(i18n('Resource', 'sbdp'), resourceSelect));
    form.appendChild(oneOffWrap);
    form.appendChild(recurringWrap);
    form.appendChild(buildFormRow(i18n('Notities', 'sbdp'), notes));

    const actions = document.createElement('div');
    actions.className = 'sbdp-planboard-form__actions';

    const submit = document.createElement('button');
    submit.type = 'submit';
    submit.className = 'button button-primary';
    submit.textContent = i18n('Regel opslaan', 'sbdp');
    actions.appendChild(submit);

    const reset = document.createElement('button');
    reset.type = 'button';
    reset.className = 'button button-secondary';
    reset.textContent = i18n('Annuleer bewerken', 'sbdp');
    reset.addEventListener('click', () => {
      editState.id = null;
      typeSelect.value = 'one_off';
      resourceSelect.value = '';
      oneOffStart.value = '';
      oneOffEnd.value = '';
      weekday.value = '0';
      startTime.value = '09:00';
      endTime.value = '10:00';
      notes.value = '';
      updateVisibility();
    });
    actions.appendChild(reset);
    form.appendChild(actions);

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      notice.textContent = '';

      const resourceId = parseInt(resourceSelect.value || '0', 10) || 0;
      const payload = {
        type: typeSelect.value,
        resource_id: resourceId || null,
        notes: notes.value || '',
      };

      if (payload.type === 'one_off') {
        const startIso = fromInputDateTime(oneOffStart.value);
        const endIso = fromInputDateTime(oneOffEnd.value);
        if (!startIso || !endIso) {
          notice.textContent = i18n('Vul start- en eindtijd in.', 'sbdp');
          return;
        }
        payload.start = startIso;
        payload.end = endIso;
      } else {
        payload.weekday = parseInt(weekday.value || '0', 10);
        payload.start_time = startTime.value;
        payload.end_time = endTime.value;
      }

      const isUpdate = !!editState.id;
      const url = isUpdate ? `${v2.closures}/${encodeURIComponent(editState.id)}` : v2.closures;
      const method = isUpdate ? 'PUT' : 'POST';

      apiFetch(url, {
        method,
        headers: Object.assign({}, { 'Content-Type': 'application/json' }),
        body: JSON.stringify(payload),
      })
        .then(() => {
          editState.id = null;
          typeSelect.value = 'one_off';
          resourceSelect.value = '';
          oneOffStart.value = '';
          oneOffEnd.value = '';
          notes.value = '';
          updateVisibility();
          loadRules();
        })
        .catch((error) => {
          notice.textContent = error.message || i18n('Regel opslaan mislukt.', 'sbdp');
        });
    });

    const loadRules = () => {
      list.innerHTML = '';
      apiFetch(v2.closures, { method: 'GET' })
        .then((response) => {
          const rules = response && Array.isArray(response.rules) ? response.rules : [];
          if (!rules.length) {
            const empty = document.createElement('p');
            empty.textContent = i18n('Geen sluitregels gevonden.', 'sbdp');
            list.appendChild(empty);
            return;
          }

          rules.forEach((rule) => {
            const item = document.createElement('div');
            item.className = 'sbdp-planboard-rules__item';

            const summary = document.createElement('div');
            summary.className = 'sbdp-planboard-rules__summary';

            const typeLabel = rule.type === 'recurring' ? i18n('Terugkerend', 'sbdp') : i18n('Eenmalig', 'sbdp');
            const parts = [typeLabel];

            if (rule.type === 'one_off') {
              parts.push(`${formatTime(rule.start)} - ${formatTime(rule.end)}`);
            } else {
              const weekdayLabel = weekdays[rule.weekday] || '';
              parts.push(`${weekdayLabel} ${rule.start_time} - ${rule.end_time}`);
            }

            if (rule.resource_id) {
              const res = resolveResourceName(rule.resource_id);
              if (res) {
                parts.push(res);
              }
            }

            summary.textContent = parts.filter(Boolean).join(' | ');
            item.appendChild(summary);

            const actionsWrap = document.createElement('div');
            actionsWrap.className = 'sbdp-planboard-rules__actions';

            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'button button-secondary';
            edit.textContent = i18n('Bewerk', 'sbdp');
            edit.addEventListener('click', () => {
              editState.id = rule.id;
              typeSelect.value = rule.type || 'one_off';
              resourceSelect.value = rule.resource_id ? String(rule.resource_id) : '';
              if (rule.type === 'one_off') {
                oneOffStart.value = toInputDateTime(rule.start) || '';
                oneOffEnd.value = toInputDateTime(rule.end) || '';
              } else {
                weekday.value = String(rule.weekday || 0);
                startTime.value = rule.start_time || '09:00';
                endTime.value = rule.end_time || '10:00';
              }
              notes.value = rule.notes || '';
              updateVisibility();
            });
            actionsWrap.appendChild(edit);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'button button-secondary';
            remove.textContent = i18n('Verwijder', 'sbdp');
            remove.addEventListener('click', () => {
              if (!confirm(i18n('Weet je zeker dat je deze regel wilt verwijderen?', 'sbdp'))) {
                return;
              }
              apiFetch(`${v2.closures}/${encodeURIComponent(rule.id)}`, { method: 'DELETE' })
                .then(() => loadRules())
                .catch((error) => {
                  notice.textContent = error.message || i18n('Regel verwijderen mislukt.', 'sbdp');
                });
            });
            actionsWrap.appendChild(remove);

            item.appendChild(actionsWrap);
            list.appendChild(item);
          });
        })
        .catch((error) => {
          notice.textContent = error.message || i18n('Sluitregels laden mislukt.', 'sbdp');
        });
    };

    rulesContainer.appendChild(list);
    form.appendChild(document.createElement('hr'));

    body.appendChild(notice);
    body.appendChild(rulesContainer);
    body.appendChild(form);

    createModal({
      title: i18n('Sluitregels beheren', 'sbdp'),
      body,
    });

    loadRules();
  }

  function handleDrop(event, resource) {
    if (!event || !event.dataTransfer || !hasEndpoint('move')) {
      return;
    }

    let payload = null;
    try {
      payload = JSON.parse(event.dataTransfer.getData('application/json') || '{}');
    } catch (error) {
      payload = null;
    }

    if (!payload || !payload.booking_id) {
      return;
    }

    const target = resource && resource.id ? { id: resource.id, name: resource.name } : null;
    openMoveModal(payload, target);
  }

  function createModal(options) {
    closeModal();

    const overlay = document.createElement('div');
    overlay.className = 'sbdp-planboard-modal';

    const dialog = document.createElement('div');
    dialog.className = 'sbdp-planboard-modal__dialog';

    const header = document.createElement('div');
    header.className = 'sbdp-planboard-modal__header';

    const title = document.createElement('h3');
    title.textContent = options.title || '';
    header.appendChild(title);

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'button button-secondary';
    closeButton.textContent = i18n('Sluiten', 'sbdp');
    closeButton.addEventListener('click', () => closeModal());
    header.appendChild(closeButton);

    dialog.appendChild(header);

    const body = document.createElement('div');
    body.className = 'sbdp-planboard-modal__body';
    if (options.body) {
      body.appendChild(options.body);
    }
    dialog.appendChild(body);

    if (options.actions && options.actions.length) {
      const footer = document.createElement('div');
      footer.className = 'sbdp-planboard-modal__footer';
      options.actions.forEach((action) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = action.variant === 'primary' ? 'button button-primary' : 'button button-secondary';
        button.textContent = action.label;
        button.addEventListener('click', () => action.onClick && action.onClick());
        footer.appendChild(button);
      });
      dialog.appendChild(footer);
    }

    overlay.appendChild(dialog);
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) {
        closeModal();
      }
    });

    document.body.appendChild(overlay);
    state.modal = overlay;
  }

  function closeModal() {
    if (state.modal && state.modal.parentNode) {
      state.modal.parentNode.removeChild(state.modal);
    }
    state.modal = null;
  }

  function createNotice() {
    const notice = document.createElement('div');
    notice.className = 'sbdp-planboard-notice sbdp-planboard-notice--inline';
    return notice;
  }

  function buildFormRow(labelText, inputElement) {
    const row = document.createElement('div');
    row.className = 'sbdp-planboard-form__row';

    const label = document.createElement('label');
    label.textContent = labelText;
    label.appendChild(inputElement);

    row.appendChild(label);
    return row;
  }

  function createResourceSelect(selectedId) {
    const select = document.createElement('select');
    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = i18n('Niet toegewezen', 'sbdp');
    select.appendChild(empty);

    const resources = state.data && Array.isArray(state.data.resources) ? state.data.resources : [];
    resources.forEach((resource) => {
      const option = document.createElement('option');
      option.value = String(resource.id || 0);
      option.textContent = resource.name || i18n('Resource', 'sbdp');
      if (selectedId && Number(resource.id) === Number(selectedId)) {
        option.selected = true;
      }
      select.appendChild(option);
    });

    return select;
  }

  function resolveResourceName(resourceId) {
    const resources = state.data && Array.isArray(state.data.resources) ? state.data.resources : [];
    const match = resources.find((resource) => Number(resource.id) === Number(resourceId));
    return match ? match.name : '';
  }

  function addItemRow(container, products) {
    const row = document.createElement('div');
    row.className = 'sbdp-planboard-items__row';

    const hasProducts = Array.isArray(products) && products.length > 0;
    const product = hasProducts ? document.createElement('select') : document.createElement('input');
    product.className = 'sbdp-item-product';
    if (hasProducts) {
      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = i18n('Kies product', 'sbdp');
      product.appendChild(empty);
      products.forEach((entry) => {
        const option = document.createElement('option');
        option.value = String(entry.id);
        option.textContent = entry.name || i18n('Product', 'sbdp');
        product.appendChild(option);
      });
    } else {
      product.type = 'number';
      product.min = '1';
      product.placeholder = i18n('Product ID', 'sbdp');
    }

    const quantity = document.createElement('input');
    quantity.type = 'number';
    quantity.min = '1';
    quantity.value = '1';
    quantity.className = 'sbdp-item-quantity';
    quantity.dataset.sync = 'true';

    const price = document.createElement('input');
    price.type = 'number';
    price.step = '0.01';
    price.min = '0';
    price.value = '0.00';
    price.className = 'sbdp-item-price';

    const total = document.createElement('span');
    total.className = 'sbdp-item-total';
    total.textContent = '€ 0,00';

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'button button-secondary';
    remove.textContent = i18n('Verwijder', 'sbdp');
    remove.addEventListener('click', () => {
      if (container.children.length > 1) {
        container.removeChild(row);
      } else {
        product.value = '';
        quantity.value = '1';
        price.value = '0.00';
      }
    });

    row.appendChild(product);
    row.appendChild(quantity);
    row.appendChild(price);
    row.appendChild(total);
    row.appendChild(remove);
    container.appendChild(row);

    return row;
  }

  function collectItems(container) {
    const items = [];
    const rows = Array.from(container.querySelectorAll('.sbdp-planboard-items__row'));
    rows.forEach((row) => {
      const productInput = row.querySelector('.sbdp-item-product');
      const quantityInput = row.querySelector('.sbdp-item-quantity');
      const priceInput = row.querySelector('.sbdp-item-price');
      if (!productInput || !quantityInput || !priceInput) {
        return;
      }
      const productId = parseInt(productInput.value || '0', 10);
      const quantity = parsePositiveInteger(quantityInput.value);
      const unitPrice = parseFloat(priceInput.value || '0');
      if (productId > 0 && quantity > 0) {
        const product = findProductById(state.productCatalog.items, productId);
        const supportsPersons = productSupportsPersons(product);
        const item = {
          product_id: productId,
          quantity: quantity,
          unit_price: unitPrice || 0,
        };
        if (supportsPersons) {
          item.participants = quantity;
        }
        items.push(item);
      }
    });

    return items;
  }

  function toInputDateTime(value) {
    if (!value) {
      return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return '';
    }
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${date.getFullYear()}-${month}-${day}T${hours}:${minutes}`;
  }

  function fromInputDateTime(value) {
    if (!value) {
      return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return '';
    }
    return date.toISOString();
  }

  function addMinutes(value, minutes) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return '';
    }
    date.setMinutes(date.getMinutes() + minutes);
    return date.toISOString();
  }

function buildLegend(entries) {
  if (!Array.isArray(entries) || !entries.length) {
    return null;
  }

  const legend = document.createElement('div');
  legend.className = 'sbdp-planboard__legend';

  entries.forEach((entry) => {
    if (!entry || !entry.resource) {
      return;
    }
    const item = document.createElement('div');
    item.className = 'sbdp-planboard__legend-item';

    const dot = document.createElement('span');
    dot.className = 'sbdp-planboard__legend-dot';
    dot.style.backgroundColor = entry.resource.color || '#2563eb';

    const label = document.createElement('span');
    label.className = 'sbdp-planboard__legend-label';
    label.textContent = entry.resource.name || '';

    const capacity = document.createElement('span');
    capacity.className = 'sbdp-planboard__legend-capacity';
    if (entry.resource.capacity) {
      capacity.textContent = `(${entry.resource.capacity})`;
    }

    item.appendChild(dot);
    item.appendChild(label);
    if (capacity.textContent) {
      item.appendChild(capacity);
    }

    legend.appendChild(item);
  });

  return legend;
}
})();








