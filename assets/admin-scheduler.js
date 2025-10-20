(function () {
  'use strict';

  const cfg = window.SBDP_ADMIN_SCHEDULER || null;
  const root = document.getElementById('sbdp-scheduler-app');
  if (!cfg || !root) {
    return;
  }

  const VIEWS = ['day', 'week', 'month'];
  const i18n = window.wp && window.wp.i18n && window.wp.i18n.__
    ? window.wp.i18n.__
    : function (text) {
        return text;
      };

  const state = {
    view: 'day',
    anchorDate: todayISO(),
    date: '',
    rangeStart: '',
    rangeEnd: '',
    loading: false,
    error: '',
    data: null,
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
      const segments = Array.isArray(entry.segments) ? entry.segments : [];

      segments.forEach((segment) => {
        timelineRow.appendChild(createSegmentElement(segment, dayStart, dayEnd, totalMs, resourceColor));
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

  function createSegmentElement(segment, dayStart, dayEnd, totalMs, fallbackColor = '#2563eb') {
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
      element.innerHTML = `<strong>${segment.event.product_name || i18n('Activiteit', 'sbdp')}</strong><span>${label}</span>`;
      const titleParts = [
        segment.event.product_name,
        segment.event.customer,
        segment.event.participants ? `${segment.event.participants} ${i18n('deelnemers', 'sbdp')}` : '',
      ].filter(Boolean);
      if (label) {
        titleParts.push(label);
      }
      element.title = titleParts.join(' | ');
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

          eventList.appendChild(item);
        });
      }

      column.appendChild(eventList);
      calendar.appendChild(column);
    });

  return calendar;
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








