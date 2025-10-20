(function(){
  'use strict';

  var container = document.querySelector('[data-sbdp-product-form]');
  if (!container) {
    return;
  }

  var rawConfig = container.getAttribute('data-sbdp-config');
  var config = {};
  if (rawConfig) {
    try {
      config = JSON.parse(rawConfig);
    } catch (error) {
      config = {};
    }
  }

  var data = window.SBDP_ProductBooking || {};
  var composeUrl = data.compose || '';
  var nonce = data.nonce || '';
  var fallbackRedirect = data.fallback_redirect || '';
  var localizedPlanner = data.planner_url || '';
  var messages = data.messages || {};

  var dateInput = container.querySelector('[name="sbdp_date"]');
  var timeInput = container.querySelector('[name="sbdp_time"]');
  var participantsInput = container.querySelector('[name="sbdp_participants"]');
  var combiSelect = container.querySelector('select[name="sbdp_combi"]');
  var feedback = container.querySelector('[data-sbdp-feedback]');
  var bookButton = container.querySelector('[data-sbdp-action="book"]');
  var planButton = container.querySelector('[data-sbdp-action="plan"]');
  var buttons = container.querySelectorAll('[data-sbdp-action]');
  var calendarRoot = container.querySelector('[data-sbdp-calendar]');
  var calendarLabel = container.querySelector('[data-sbdp-calendar-label]');
  var calendarGrid = container.querySelector('[data-sbdp-calendar-grid]');
  var calendarPrev = container.querySelector('[data-sbdp-calendar-prev]');
  var calendarNext = container.querySelector('[data-sbdp-calendar-next]');
  var timePicker = container.querySelector('[data-sbdp-time-picker]');
  var timeslotList = container.querySelector('[data-sbdp-timeslot-list]');
  var timeslotEmpty = container.querySelector('[data-sbdp-timeslot-empty]');

  var summaryTotal = container.querySelector('[data-sbdp-total]');
  var summaryDuration = container.querySelector('[data-summary="duration"]');
  var summaryPeople = container.querySelector('[data-summary="people"]');
  var summaryCombi = container.querySelector('[data-summary="combi"]');
  var summaryHint = container.querySelector('[data-summary-hint]');

  var summaryDefaults = {
    duration: summaryDuration ? summaryDuration.textContent : '',
    people: summaryPeople ? summaryPeople.textContent : '',
    combi: summaryCombi ? summaryCombi.textContent : ''
  };
  var hintDefaultText = summaryHint ? summaryHint.textContent : '';

  var plannerUrl = (config && config.plannerUrl) || localizedPlanner || '';
  var limits = (config && config.limits) || {};
  var defaults = (config && config.defaults) || {};
  var today = (config && config.today) || '';
  var resources = Array.isArray(config && config.resources) ? config.resources : [];
  var basePrice = parseFloat(config && config.basePrice) || 0;
  var currency = config && config.currency ? config.currency : 'EUR';
  var currencySymbol = config && config.currencySym ? config.currencySym : currency;
  var locale = config && config.locale ? String(config.locale).replace('_', '-') : 'nl-NL';
  var durationMinutes = parseInt(config && config.duration, 10);
  var isLoading = false;
  var availabilityUrl = data.availability || '';
  var availabilityCache = {};
  var timeStepMinutes = parseInt(config && config.timeStep, 10);
  if (isNaN(timeStepMinutes) || timeStepMinutes <= 0) {
    timeStepMinutes = 15;
  }
  var availabilityState = {
    date: '',
    slots: [],
    capacity: null
  };
  var availableTimeOptions = [];
  var participantOptionList = [];
  var pricingPreviewUrl = data.pricing_preview || '';
  var pricingState = {
    total: null,
    formatted: null,
    unit: null,
    raw: null,
    loading: false
  };
  var pricingAbortController = null;
  var resourceCapacity = null;
  if (Array.isArray(resources) && resources.length > 0) {
    var firstResource = resources[0];
    if (firstResource && typeof firstResource.capacity !== 'undefined') {
      var parsedCapacity = parseInt(firstResource.capacity, 10);
      if (!isNaN(parsedCapacity) && parsedCapacity > 0) {
        resourceCapacity = parsedCapacity;
      }
    }
  }

  var messageLookup = function(key, fallback) {
    if (messages && Object.prototype.hasOwnProperty.call(messages, key)) {
      return messages[key];
    }
    return fallback || '';
  };

  var pad = function(number) {
    var n = parseInt(number, 10);
    if (isNaN(n) || n < 0) {
      n = 0;
    }
    return n < 10 ? '0' + n : String(n);
  };

  var formatTimeValue = function(dateObj) {
    return pad(dateObj.getHours()) + ':' + pad(dateObj.getMinutes());
  };

  var shiftDate = function(dateStr, offsetDays) {
    var base = new Date(dateStr + 'T00:00:00');
    if (isNaN(base.getTime())) {
      base = new Date();
    }
    base.setUTCDate(base.getUTCDate() + offsetDays);
    return base.toISOString().slice(0, 10);
  };

  var normalizeDate = function(value) {
    if (!value) {
      return '';
    }
    var trimmed = String(value).trim();
    return /^\d{4}-\d{2}-\d{2}$/.test(trimmed) ? trimmed : '';
  };

  var normalizeTime = function(value) {
    if (!value) {
      return '';
    }
    var trimmed = String(value).trim();
    return /^\d{2}:\d{2}$/.test(trimmed) ? trimmed : '';
  };

  var openHoursConfig = (config && config.openHours) || {};
  var TIME_PATTERN = /^\d{2}:\d{2}$/;
  var DEFAULT_DAY_START = '10:00';
  var DEFAULT_DAY_END = '23:00';

  var isTimeLike = function(value) {
    if (typeof value !== 'string') {
      return false;
    }
    return TIME_PATTERN.test(value.trim());
  };

  var timeToMinutes = function(value) {
    if (!isTimeLike(value)) {
      return null;
    }
    var parts = value.trim().split(':');
    var hours = parseInt(parts[0], 10);
    var minutes = parseInt(parts[1], 10);
    if (isNaN(hours) || isNaN(minutes)) {
      return null;
    }
    return hours * 60 + minutes;
  };

  var dayStartTimeString = isTimeLike(openHoursConfig.start) ? openHoursConfig.start.trim() : DEFAULT_DAY_START;
  var dayEndTimeString = isTimeLike(openHoursConfig.end) ? openHoursConfig.end.trim() : DEFAULT_DAY_END;
  var startMinutes = timeToMinutes(dayStartTimeString);
  var endMinutes = timeToMinutes(dayEndTimeString);
  if (startMinutes === null) {
    dayStartTimeString = DEFAULT_DAY_START;
    startMinutes = timeToMinutes(dayStartTimeString);
  }
  if (endMinutes === null || (startMinutes !== null && endMinutes !== null && endMinutes <= startMinutes)) {
    dayEndTimeString = DEFAULT_DAY_END;
    endMinutes = timeToMinutes(dayEndTimeString);
    if (endMinutes !== null && startMinutes !== null && endMinutes <= startMinutes) {
      dayStartTimeString = DEFAULT_DAY_START;
      startMinutes = timeToMinutes(dayStartTimeString);
    }
  }

  var createDateWithTime = function(dateStr, timeStr) {
    var normalizedDate = normalizeDate(dateStr);
    if (!normalizedDate || !isTimeLike(timeStr)) {
      return null;
    }
    var value = normalizedDate + 'T' + timeStr.trim() + ':00';
    var instance = new Date(value);
    if (isNaN(instance.getTime())) {
      return null;
    }
    return instance;
  };

  var resolveDayBounds = function(dateStr) {
    var normalizedDate = normalizeDate(dateStr);
    if (!normalizedDate) {
      return null;
    }
    var start = createDateWithTime(normalizedDate, dayStartTimeString);
    var end = createDateWithTime(normalizedDate, dayEndTimeString);
    if (!start || !end || start.getTime() >= end.getTime()) {
      return null;
    }
    return {
      start: start,
      end: end
    };
  };

  var parseISODate = function(value) {
    var normalized = normalizeDate(value);
    if (!normalized) {
      return null;
    }
    var parts = normalized.split('-');
    var year = parseInt(parts[0], 10);
    var month = parseInt(parts[1], 10) - 1;
    var day = parseInt(parts[2], 10);
    if (isNaN(year) || isNaN(month) || isNaN(day)) {
      return null;
    }
    return new Date(year, month, day, 12, 0, 0);
  };

  var startOfMonthFromISO = function(value) {
    var parsed = parseISODate(value);
    if (!parsed) {
      return null;
    }
    return new Date(parsed.getFullYear(), parsed.getMonth(), 1, 12, 0, 0);
  };

  var addMonths = function(dateObj, amount) {
    if (!(dateObj instanceof Date) || isNaN(dateObj.getTime())) {
      return null;
    }
    var clone = new Date(dateObj.getTime());
    clone.setMonth(clone.getMonth() + amount);
    return new Date(clone.getFullYear(), clone.getMonth(), 1, 12, 0, 0);
  };

  var getMonthEnd = function(dateObj) {
    if (!(dateObj instanceof Date) || isNaN(dateObj.getTime())) {
      return null;
    }
    return new Date(dateObj.getFullYear(), dateObj.getMonth() + 1, 0, 12, 0, 0);
  };

  var formatMonthLabel = function(dateObj) {
    if (!(dateObj instanceof Date) || isNaN(dateObj.getTime())) {
      return '';
    }
    if (typeof Intl !== 'undefined' && typeof Intl.DateTimeFormat === 'function') {
      try {
        return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(dateObj);
      } catch (error) {
        // fall back below
      }
    }
    return dateObj.getFullYear() + '-' + pad(dateObj.getMonth() + 1);
  };

  var formatFullDate = function(value) {
    var parsed = parseISODate(value);
    if (!parsed) {
      return value;
    }
    if (typeof Intl !== 'undefined' && typeof Intl.DateTimeFormat === 'function') {
      try {
        return new Intl.DateTimeFormat(locale, {
          weekday: 'long',
          day: 'numeric',
          month: 'long',
          year: 'numeric'
        }).format(parsed);
      } catch (error) {
        // fall through
      }
    }
    return value;
  };

  var compareISO = function(a, b) {
    var left = normalizeDate(a);
    var right = normalizeDate(b);
    if (!left || !right) {
      return 0;
    }
    if (left === right) {
      return 0;
    }
    return left < right ? -1 : 1;
  };

  var calendarAvailability = {};
  var minSelectableDate = normalizeDate(today);
  var initialCalendarDate = normalizeDate(dateInput && dateInput.value) || normalizeDate(defaults && defaults.date) || minSelectableDate;
  if (!initialCalendarDate) {
    initialCalendarDate = new Date().toISOString().slice(0, 10);
  }
  var calendarState = {
    selectedDate: initialCalendarDate,
    currentMonth: startOfMonthFromISO(initialCalendarDate)
  };
  if (!calendarState.currentMonth) {
    calendarState.currentMonth = startOfMonthFromISO(minSelectableDate) || new Date();
  }

  var isRangeBlocked = function(start, end, blocks) {
    if (!blocks || !blocks.length) {
      return false;
    }
    var startTime = start.getTime();
    var endTime = end.getTime();
    for (var i = 0; i < blocks.length; i += 1) {
      var block = blocks[i];
      if (!block) {
        continue;
      }
      var blockStart = block.start ? new Date(block.start).getTime() : 0;
      var blockEnd = block.end ? new Date(block.end).getTime() : 0;
      if (blockEnd > startTime && blockStart < endTime) {
        return true;
      }
    }
    return false;
  };

  var getAvailabilityCapacity = function(availability) {
    if (!availability || typeof availability.capacity === 'undefined') {
      return null;
    }
    var capacity = parseInt(availability.capacity, 10);
    if (isNaN(capacity) || capacity <= 0) {
      return null;
    }
    return capacity;
  };

  var generateTimeSlots = function(dateStr, availability) {
    var slots = [];
    if (!dateStr) {
      return slots;
    }

    var blocks = availability && availability.blocks ? availability.blocks : [];
    var duration = parseInt(durationMinutes, 10);
    if (isNaN(duration) || duration <= 0) {
      duration = 60;
    }
    var durationMs = Math.max(10, duration) * 60000;
    var stepMs = Math.max(5, timeStepMinutes) * 60000;

    var bounds = resolveDayBounds(dateStr);
    if (!bounds) {
      return slots;
    }

    var dayStart = bounds.start;
    var dayEnd = bounds.end;

    for (var cursor = new Date(dayStart.getTime()); cursor.getTime() + durationMs <= dayEnd.getTime(); cursor = new Date(cursor.getTime() + stepMs)) {
      var slotEnd = new Date(cursor.getTime() + durationMs);
      if (!isRangeBlocked(cursor, slotEnd, blocks)) {
        slots.push({
          value: formatTimeValue(cursor),
          label: formatTimeValue(cursor),
          start: cursor.toISOString(),
          end: slotEnd.toISOString()
        });
      }
    }

    return slots;
  };

  var renderCalendar = function() {
    if (!calendarGrid) {
      return;
    }

    if (!calendarState.currentMonth) {
      calendarState.currentMonth = startOfMonthFromISO(calendarState.selectedDate) || new Date();
    }

    var monthToRender = calendarState.currentMonth;
    if (!(monthToRender instanceof Date) || isNaN(monthToRender.getTime())) {
      monthToRender = new Date();
      calendarState.currentMonth = monthToRender;
    }

    if (calendarLabel) {
      calendarLabel.textContent = formatMonthLabel(monthToRender);
    }

    var minDateObj = minSelectableDate ? parseISODate(minSelectableDate) : null;

    if (calendarPrev) {
      var previousMonth = addMonths(monthToRender, -1);
      var prevDisabled = true;
      if (previousMonth) {
        if (!minDateObj) {
          prevDisabled = false;
        } else {
          var prevEnd = getMonthEnd(previousMonth);
          prevDisabled = !prevEnd || prevEnd.getTime() < minDateObj.getTime();
        }
      }
      calendarPrev.disabled = prevDisabled;
    }

    if (calendarNext) {
      calendarNext.disabled = false;
    }

    var fragment = document.createDocumentFragment();
    var firstDay = new Date(monthToRender.getFullYear(), monthToRender.getMonth(), 1);
    var daysInMonth = new Date(monthToRender.getFullYear(), monthToRender.getMonth() + 1, 0).getDate();
    var offset = (firstDay.getDay() + 6) % 7;
    var totalCells = Math.ceil((offset + daysInMonth) / 7) * 7;
    var selectedDate = normalizeDate(calendarState.selectedDate);

    for (var index = 0; index < totalCells; index += 1) {
      var dayNumber = index - offset + 1;
      if (dayNumber < 1 || dayNumber > daysInMonth) {
        var placeholder = document.createElement('span');
        placeholder.className = 'sbdp-date-picker__day sbdp-date-picker__cell--empty';
        fragment.appendChild(placeholder);
        continue;
      }

      var isoDate = monthToRender.getFullYear() + '-' + pad(monthToRender.getMonth() + 1) + '-' + pad(dayNumber);
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'sbdp-date-picker__day';
      button.setAttribute('role', 'gridcell');
      button.setAttribute('data-sbdp-calendar-date', isoDate);
      button.setAttribute('aria-label', formatFullDate(isoDate));

      var isSelected = selectedDate === isoDate;
      button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
      button.tabIndex = isSelected ? 0 : -1;

      if (isoDate === today) {
        button.classList.add('sbdp-date-picker__day--today');
      }

      var availabilityFlag = calendarAvailability[isoDate];
      if (availabilityFlag === 'empty') {
        button.classList.add('sbdp-date-picker__day--unavailable');
      }

      var isDisabled = false;
      if (minDateObj) {
        var candidateDate = parseISODate(isoDate);
        if (candidateDate && candidateDate.getTime() < minDateObj.getTime()) {
          isDisabled = true;
        }
      }

      if (isDisabled) {
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
      }

      fragment.appendChild(button);
    }

    while (calendarGrid.firstChild) {
      calendarGrid.removeChild(calendarGrid.firstChild);
    }
    calendarGrid.appendChild(fragment);

    if (!calendarGrid.querySelector('[data-sbdp-calendar-date][aria-selected="true"]')) {
      var firstAvailable = calendarGrid.querySelector('[data-sbdp-calendar-date]:not([aria-disabled="true"])');
      if (firstAvailable) {
        firstAvailable.tabIndex = 0;
      }
    }
  };

  var renderTimeSlots = function(slots, selectedValue) {
    if (!timeslotList) {
      return;
    }

    var doc = timeslotList.ownerDocument || document;
    while (timeslotList.firstChild) {
      timeslotList.removeChild(timeslotList.firstChild);
    }

    if (!Array.isArray(slots) || slots.length === 0) {
      if (timePicker) {
        timePicker.classList.add('sbdp-time-picker--empty');
      }
      if (timeslotEmpty) {
        timeslotEmpty.removeAttribute('hidden');
        timeslotEmpty.textContent = messageLookup('no_slots', 'Geen tijdsloten beschikbaar voor deze datum.');
      }
      timeslotList.removeAttribute('aria-activedescendant');
      return;
    }

    if (timePicker) {
      timePicker.classList.remove('sbdp-time-picker--empty');
    }
    if (timeslotEmpty) {
      timeslotEmpty.textContent = '';
      timeslotEmpty.setAttribute('hidden', 'hidden');
    }

    var fragment = doc.createDocumentFragment();
    var activeId = '';
    var normalizedSelected = normalizeTime(selectedValue);

    for (var i = 0; i < slots.length; i += 1) {
      var slot = slots[i];
      if (!slot || typeof slot.value === 'undefined') {
        continue;
      }
      var button = doc.createElement('button');
      button.type = 'button';
      button.className = 'sbdp-time-picker__slot';
      button.setAttribute('role', 'option');
      button.setAttribute('data-sbdp-time-slot', slot.value);
      button.textContent = slot.label || slot.value;
      var buttonId = 'sbdp-slot-' + (config && config.productId ? config.productId + '-' : '') + slot.value.replace(':', '');
      button.id = buttonId;

      var isSelected = normalizedSelected && slot.value === normalizedSelected;
      button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
      button.tabIndex = isSelected ? 0 : -1;

      if (isSelected) {
        activeId = buttonId;
      }

      fragment.appendChild(button);
    }

    timeslotList.appendChild(fragment);

    if (activeId) {
      timeslotList.setAttribute('aria-activedescendant', activeId);
    } else {
      timeslotList.removeAttribute('aria-activedescendant');
      var firstButton = timeslotList.querySelector('[data-sbdp-time-slot]');
      if (firstButton) {
        firstButton.tabIndex = 0;
      }
    }
  };

  var selectTimeSlot = function(value, triggerChange) {
    var normalized = normalizeTime(value);
    if (timeInput) {
      timeInput.value = normalized || '';
    }

    if (timeslotList) {
      var buttons = timeslotList.querySelectorAll('[data-sbdp-time-slot]');
      var activeId = '';
      for (var i = 0; i < buttons.length; i += 1) {
        var button = buttons[i];
        var isMatch = normalized && button.getAttribute('data-sbdp-time-slot') === normalized;
        button.setAttribute('aria-selected', isMatch ? 'true' : 'false');
        button.tabIndex = isMatch ? 0 : -1;
        if (isMatch) {
          activeId = button.id || '';
        }
      }
      if (activeId) {
        timeslotList.setAttribute('aria-activedescendant', activeId);
      } else {
        timeslotList.removeAttribute('aria-activedescendant');
        if (buttons.length > 0) {
          buttons[0].tabIndex = 0;
        }
      }
    }

    if (triggerChange !== false && timeInput) {
      var changeEvent = new Event('change', { bubbles: true });
      timeInput.dispatchEvent(changeEvent);
    }
  };

  var focusTimeSlotByIndex = function(index) {
    if (!timeslotList) {
      return;
    }
    var buttons = timeslotList.querySelectorAll('[data-sbdp-time-slot]');
    if (!buttons.length) {
      return;
    }
    var clamped = index;
    if (clamped < 0) {
      clamped = buttons.length - 1;
    } else if (clamped >= buttons.length) {
      clamped = 0;
    }
    buttons[clamped].focus();
  };

  var handleTimeKeydown = function(event) {
    if (!timeslotList) {
      return;
    }
    var buttons = timeslotList.querySelectorAll('[data-sbdp-time-slot]');
    if (!buttons.length) {
      return;
    }
    var key = event.key;
    if (key !== 'ArrowRight' && key !== 'ArrowDown' && key !== 'ArrowLeft' && key !== 'ArrowUp' && key !== 'Home' && key !== 'End') {
      return;
    }
    event.preventDefault();

    var activeElement = document.activeElement;
    var currentIndex = Array.prototype.indexOf.call(buttons, activeElement);
    if (currentIndex === -1) {
      var selectedValue = timeInput && timeInput.value;
      currentIndex = Array.prototype.findIndex.call(buttons, function(button) {
        return button.getAttribute('data-sbdp-time-slot') === selectedValue;
      });
    }
    if (currentIndex === -1) {
      currentIndex = 0;
    }

    if (key === 'ArrowRight' || key === 'ArrowDown') {
      focusTimeSlotByIndex(currentIndex + 1);
    } else if (key === 'ArrowLeft' || key === 'ArrowUp') {
      focusTimeSlotByIndex(currentIndex - 1);
    } else if (key === 'Home') {
      focusTimeSlotByIndex(0);
    } else if (key === 'End') {
      focusTimeSlotByIndex(buttons.length - 1);
    }
  };

  var setSelectOptions = function(select, options, placeholder, selectedValue) {
    if (!select) {
      return;
    }
    var previous = typeof selectedValue === 'undefined' ? select.value : selectedValue;
    var doc = select.ownerDocument || document;
    var fragment = doc.createDocumentFragment();

    if (placeholder) {
      var placeholderOption = doc.createElement('option');
      placeholderOption.value = '';
      placeholderOption.textContent = placeholder;
      fragment.appendChild(placeholderOption);
    }

    for (var i = 0; i < options.length; i += 1) {
      var optionData = options[i];
      if (!optionData || typeof optionData.value === 'undefined') {
        continue;
      }
      var optionEl = doc.createElement('option');
      optionEl.value = optionData.value;
      optionEl.textContent = optionData.label || optionData.value;
      if (optionData.disabled) {
        optionEl.disabled = true;
      }
      fragment.appendChild(optionEl);
    }

    while (select.firstChild) {
      select.removeChild(select.firstChild);
    }
    select.appendChild(fragment);

    if (previous && Array.prototype.some.call(select.options, function(opt) { return opt.value === previous; })) {
      select.value = previous;
    } else if (select.options.length > 0) {
      if (select.options[0].value === '') {
        if (select.options.length > 1) {
          select.selectedIndex = 1;
        } else {
          select.selectedIndex = 0;
        }
      } else {
        select.selectedIndex = 0;
      }
    }
  };

  var updateTimeOptions = function(slots, preserveSelection) {
    availableTimeOptions = Array.isArray(slots) ? slots.slice() : [];
    var normalizedPrevious = preserveSelection && timeInput ? normalizeTime(timeInput.value) : '';
    var selectedValue = '';

    if (normalizedPrevious) {
      var hasPrevious = availableTimeOptions.some(function(option) {
        return option && option.value === normalizedPrevious;
      });
      if (hasPrevious) {
        selectedValue = normalizedPrevious;
      }
    }

    if (!selectedValue) {
      var defaultTime = normalizeTime(defaults && defaults.time);
      if (defaultTime) {
        var hasDefault = availableTimeOptions.some(function(option) {
          return option && option.value === defaultTime;
        });
        if (hasDefault) {
          selectedValue = defaultTime;
        }
      }
    }

    if (!selectedValue && availableTimeOptions.length > 0) {
      selectedValue = availableTimeOptions[0].value;
    }

    renderTimeSlots(availableTimeOptions, selectedValue);

    if (availableTimeOptions.length === 0) {
      if (timeInput) {
        timeInput.setAttribute('data-empty', '1');
      }
      selectTimeSlot('', false);
    } else {
      if (timeInput) {
        timeInput.removeAttribute('data-empty');
      }
      selectTimeSlot(selectedValue, false);
    }
  };

  var updateParticipantOptions = function(capacity, preserveSelection) {
    var min = parseInt(limits.min, 10);
    if (isNaN(min) || min < 1) {
      min = 1;
    }
    var max = null;
    if (limits && typeof limits.max !== 'undefined' && limits.max !== null) {
      var parsedMax = parseInt(limits.max, 10);
      if (!isNaN(parsedMax) && parsedMax > 0) {
        max = parsedMax;
      }
    }

    if (typeof capacity === 'number' && capacity <= 0) {
      participantOptionList = [];
      setSelectOptions(participantsInput, [], messageLookup('select_participants', 'Selecteer aantal personen'), undefined);
      if (participantsInput) {
        participantsInput.disabled = true;
      }
      return;
    }

    if (resourceCapacity !== null) {
      max = max === null ? resourceCapacity : Math.min(max, resourceCapacity);
    }

    if (typeof capacity === 'number' && capacity > 0) {
      max = max === null ? capacity : Math.min(max, capacity);
    }

    if (max === null) {
      max = min + 9;
    }

    if (max < min) {
      participantOptionList = [];
      setSelectOptions(participantsInput, [], messageLookup('select_participants', 'Selecteer aantal personen'), undefined);
      if (participantsInput) {
        participantsInput.disabled = true;
      }
      return;
    }

    var options = [];
    for (var count = min; count <= max; count += 1) {
      options.push({
        value: String(count),
        label: String(count)
      });
    }

    participantOptionList = options.slice();
    var selected = preserveSelection ? participantsInput && participantsInput.value : undefined;
    setSelectOptions(participantsInput, options, messageLookup('select_participants', 'Selecteer aantal personen'), selected);
    if (participantsInput) {
      participantsInput.disabled = options.length === 0;
    }
  };

  var dispatchOptionsUpdated = function(detail) {
    var payload = detail || {};
    payload.date = payload.date || (dateInput ? dateInput.value : '');
    payload.times = availableTimeOptions.slice();
    payload.participants = participantOptionList.slice();
    payload.selectedTime = timeInput && timeInput.value ? timeInput.value : '';
    payload.selectedParticipants = participantsInput && participantsInput.value ? participantsInput.value : '';
    payload.pricing = {
      total: pricingState.total,
      formatted: pricingState.formatted,
      unit: pricingState.unit,
      loading: pricingState.loading
    };
    document.dispatchEvent(new CustomEvent('sbdp:product-options', { detail: payload }));
  };

  var resetPricingState = function() {
    pricingState.total = null;
    pricingState.formatted = null;
    pricingState.unit = null;
    pricingState.raw = null;
    pricingState.loading = false;
  };

  var refreshPricing = function() {
    if (!pricingPreviewUrl || !config || !config.productId) {
      resetPricingState();
      updateSummary();
      dispatchOptionsUpdated({});
      return Promise.resolve(null);
    }

    var range = computeTimeRange();
    var participants = getParticipants();

    if (!range || !range.start || !participants) {
      resetPricingState();
      updateSummary();
      dispatchOptionsUpdated({});
      return Promise.resolve(null);
    }

    if (pricingAbortController && typeof pricingAbortController.abort === 'function') {
      pricingAbortController.abort();
    }

    pricingAbortController = typeof AbortController !== 'undefined' ? new AbortController() : null;

    pricingState.loading = true;
    updateSummary();
    dispatchOptionsUpdated({});

    var headers = {
      'Content-Type': 'application/json'
    };

    if (nonce) {
      headers['X-WP-Nonce'] = nonce;
    }

    return fetch(pricingPreviewUrl, {
      method: 'POST',
      headers: headers,
      credentials: 'same-origin',
      body: JSON.stringify({
        product_id: config.productId,
        resource_id: getResourceId(),
        participants: participants,
        start: range.start
      }),
      signal: pricingAbortController ? pricingAbortController.signal : undefined
    }).then(function(response) {
      if (!response.ok) {
        throw new Error('pricing_failed');
      }
      return response.json();
    }).then(function(result) {
      pricingState.loading = false;
      pricingState.raw = result || null;
      var total = result && typeof result.total === 'number' ? result.total : null;
      var unit = result && typeof result.unit_price === 'number' ? result.unit_price : null;
      pricingState.total = total;
      pricingState.unit = unit;
      pricingState.formatted = total !== null ? formatCurrency(total) : null;
      updateSummary();
      dispatchOptionsUpdated({});
      return result;
    }).catch(function(error) {
      if (error && error.name === 'AbortError') {
        return null;
      }
      pricingState.loading = false;
      resetPricingState();
      updateSummary();
      dispatchOptionsUpdated({});
      return null;
    });
  };

  var fetchAvailability = function(date) {
    var normalizedDate = normalizeDate(date);
    if (!availabilityUrl || !config || !config.productId || !normalizedDate) {
      return Promise.resolve(null);
    }
    var resourceId = getResourceId();
    var cacheKey = [normalizedDate, resourceId || 0].join('|');
    if (availabilityCache[cacheKey]) {
      return Promise.resolve(availabilityCache[cacheKey]);
    }
    var url = availabilityUrl + '?product_id=' + encodeURIComponent(config.productId) + '&date=' + encodeURIComponent(normalizedDate);
    if (resourceId) {
      url += '&resource_id=' + encodeURIComponent(resourceId);
    }
    return fetch(url, {
      credentials: 'same-origin'
    }).then(function(response) {
      if (!response.ok) {
        throw new Error('availability_failed');
      }
      return response.json();
    }).then(function(json) {
      availabilityCache[cacheKey] = json || {};
      return availabilityCache[cacheKey];
    }).catch(function() {
      return null;
    });
  };

  var applyAvailability = function(date, availability, preserveSelection) {
    var normalizedDate = normalizeDate(date);
    if (!normalizedDate) {
      return;
    }
    availabilityState.date = normalizedDate;
    availabilityState.capacity = getAvailabilityCapacity(availability);
    availabilityState.slots = generateTimeSlots(normalizedDate, availability);

    calendarAvailability[normalizedDate] = availabilityState.slots.length > 0 ? 'available' : 'empty';
    renderCalendar();

    updateTimeOptions(availabilityState.slots, preserveSelection);
    updateParticipantOptions(availabilityState.capacity, preserveSelection);

    if (participantsInput && !participantsInput.value && participantOptionList.length > 0) {
      participantsInput.value = participantOptionList[0].value;
    }

    if (availabilityState.slots.length === 0) {
      showFeedback(messageLookup('no_slots', 'Geen tijdsloten beschikbaar voor deze datum.'), 'warning');
    } else if (participantsInput && participantsInput.disabled) {
      showFeedback(messageLookup('no_capacity', 'De geselecteerde capaciteit is niet beschikbaar.'), 'warning');
    } else {
      showFeedback('', null);
    }

    dispatchOptionsUpdated({ date: normalizedDate });
    refreshPricing();
    updateSummary();
  };

  var loadAvailability = function(date, preserveSelection) {
    var normalizedDate = normalizeDate(date);
    if (!normalizedDate) {
      return Promise.resolve(null);
    }
    return fetchAvailability(normalizedDate).then(function(availability) {
      applyAvailability(normalizedDate, availability, preserveSelection);
      return availability;
    }).then(function(availability) {
      refreshPricing();
      return availability;
    });
  };

  var findFirstAvailableDate = function(startDate, maxDays) {
    var normalizedStart = normalizeDate(startDate);
    if (!normalizedStart) {
      normalizedStart = today || new Date().toISOString().slice(0, 10);
    }
    var attempts = 0;

    var iterate = function(dateStr) {
      return fetchAvailability(dateStr).then(function(availability) {
        var slots = generateTimeSlots(dateStr, availability);
        calendarAvailability[dateStr] = slots.length > 0 ? 'available' : 'empty';
        renderCalendar();
        if (slots.length > 0) {
          return {
            date: dateStr,
            availability: availability
          };
        }
        attempts += 1;
        if (attempts >= maxDays) {
          return null;
        }
        return iterate(shiftDate(dateStr, 1));
      });
    };

    return iterate(normalizedStart);
  };

  var initialiseAvailability = function() {
    if (!dateInput) {
      return Promise.resolve();
    }

    var normalizedDefault = normalizeDate(defaults && defaults.date);
    var normalizedInput = normalizeDate(dateInput.value);
    var startingDate = normalizedInput || normalizedDefault || calendarState.selectedDate;

    if (minSelectableDate && (!startingDate || compareISO(startingDate, minSelectableDate) < 0)) {
      startingDate = minSelectableDate;
    }

    if (!startingDate) {
      startingDate = today || new Date().toISOString().slice(0, 10);
    }

    calendarState.selectedDate = startingDate;
    var monthStart = startOfMonthFromISO(startingDate);
    if (monthStart) {
      calendarState.currentMonth = monthStart;
    }

    if (dateInput) {
      dateInput.value = startingDate;
    }

    renderCalendar();

    return findFirstAvailableDate(startingDate, 60).then(function(result) {
      var targetDate = startingDate;
      if (result && result.date) {
        targetDate = result.date;
      }

      calendarState.selectedDate = targetDate;
      var nextMonthStart = startOfMonthFromISO(targetDate);
      if (nextMonthStart) {
        calendarState.currentMonth = nextMonthStart;
      }

      if (dateInput) {
        dateInput.value = targetDate;
      }

      renderCalendar();

      if (result && result.availability) {
        applyAvailability(targetDate, result.availability, false);
        return result.availability;
      }

      return loadAvailability(targetDate, false);
    }).then(function() {
      clampParticipants(false);
      return refreshPricing();
    });
  };

  var selectDate = function(dateStr, options) {
    var normalized = normalizeDate(dateStr);
    if (!normalized) {
      return Promise.resolve(null);
    }

    calendarState.selectedDate = normalized;
    var monthStart = startOfMonthFromISO(normalized);
    if (monthStart) {
      calendarState.currentMonth = monthStart;
    }

    if (dateInput) {
      dateInput.value = normalized;
    }

    renderCalendar();

    var shouldFetch = !options || options.fetch !== false;
    var preserveTime = !!(options && options.preserveTime);

    if (shouldFetch) {
      return loadAvailability(normalized, preserveTime);
    }

    return Promise.resolve(null);
  };

  var setLoading = function(state) {
    isLoading = !!state;
    if (isLoading) {
      container.classList.add('is-loading');
    } else {
      container.classList.remove('is-loading');
    }

    for (var i = 0; i < buttons.length; i += 1) {
      var button = buttons[i];
      button.disabled = isLoading;
      if (isLoading) {
        button.setAttribute('aria-busy', 'true');
      } else {
        button.removeAttribute('aria-busy');
      }
    }
  };

  var showFeedback = function(text, tone) {
    if (!feedback) {
      return;
    }

    var baseClass = 'sbdp-product-booking__feedback';
    var classes = [baseClass];
    if (tone) {
      classes.push(baseClass + '--' + tone);
    }
    feedback.className = classes.join(' ');
    feedback.textContent = text || '';
  };

  var formatCurrency = function(amount) {
    var value = parseFloat(amount);
    if (isNaN(value)) {
      value = 0;
    }
    if (value < 0) {
      value = 0;
    }

    if (typeof Intl !== 'undefined' && typeof Intl.NumberFormat === 'function') {
      try {
        return new Intl.NumberFormat(locale, { style: 'currency', currency: currency }).format(value);
      } catch (error) {
        // fall through to manual formatting
      }
    }

    var formatted = value.toFixed(2);
    if (locale.toLowerCase().indexOf('nl') === 0) {
      formatted = formatted.replace('.', ',');
    }

    if (currencySymbol === currency) {
      return currencySymbol + ' ' + formatted;
    }

    return currencySymbol + formatted;
  };

  var formatDuration = function(minutes) {
    var total = parseInt(minutes, 10);
    if (isNaN(total) || total <= 0) {
      return summaryDefaults.duration;
    }

    var hours = Math.floor(total / 60);
    var remainder = total % 60;
    var parts = [];

    if (hours > 0) {
      parts.push(hours + ' uur');
    }
    if (remainder > 0) {
      parts.push(remainder + (remainder === 1 ? ' minuut' : ' minuten'));
    }

    if (!parts.length) {
      parts.push(total + (total === 1 ? ' minuut' : ' minuten'));
    }

    return parts.join(' ');
  };

  var formatPeople = function(count) {
    var total = parseInt(count, 10);
    if (isNaN(total) || total <= 0) {
      return summaryDefaults.people;
    }

    return total + ' ' + (total === 1 ? 'persoon' : 'personen');
  };

  var getCombiSelection = function() {
    if (!combiSelect || combiSelect.options.length === 0) {
      return { label: summaryDefaults.combi, adjustment: 0 };
    }

    var option = combiSelect.options[combiSelect.selectedIndex >= 0 ? combiSelect.selectedIndex : 0];
    if (!option) {
      return { label: summaryDefaults.combi, adjustment: 0 };
    }

    var label = option.textContent ? option.textContent.trim() : '';
    var value = option.value || '';
    var adjustment = 0;
    var raw = option.getAttribute('data-adjustment');
    if (raw) {
      raw = raw.replace(',', '.');
      adjustment = parseFloat(raw);
      if (isNaN(adjustment)) {
        adjustment = 0;
      }
    }

    if (!value) {
      adjustment = 0;
      if (!label) {
        label = summaryDefaults.combi;
      }
    }

    return {
      label: label || summaryDefaults.combi,
      adjustment: adjustment
    };
  };

  var updateSummary = function() {
    if (summaryDuration) {
      summaryDuration.textContent = formatDuration(durationMinutes);
    }

    var participants = getParticipants();
    if (summaryPeople) {
      summaryPeople.textContent = formatPeople(participants);
    }

    var combi = getCombiSelection();
    if (summaryCombi) {
      summaryCombi.textContent = combi.label;
    }

    var fallbackTotal = (basePrice * participants) + combi.adjustment;

    if (summaryTotal) {
      if (pricingState.loading) {
        summaryTotal.textContent = messageLookup('pricing_loading', 'Prijs wordt berekend…');
      } else if (pricingState.formatted) {
        summaryTotal.textContent = pricingState.formatted;
      } else {
        summaryTotal.textContent = formatCurrency(fallbackTotal);
      }
    }

    if (summaryHint) {
      if (pricingState.loading) {
        summaryHint.textContent = messageLookup('pricing_loading', 'Prijs wordt berekend…');
        summaryHint.removeAttribute('hidden');
      } else if (pricingState.formatted || fallbackTotal > 0) {
        summaryHint.setAttribute('hidden', 'hidden');
      } else {
        summaryHint.textContent = hintDefaultText;
        summaryHint.removeAttribute('hidden');
      }
    }
  };

  var clampParticipants = function(shouldUpdate) {
    if (!participantsInput) {
      return;
    }

    if (!participantsInput.options || participantsInput.options.length === 0) {
      return;
    }

    var currentValue = participantsInput.value;
    var hasCurrent = currentValue && Array.prototype.some.call(participantsInput.options, function(option) {
      return !option.disabled && option.value === currentValue;
    });

    if (!hasCurrent) {
      for (var i = 0; i < participantsInput.options.length; i += 1) {
        var option = participantsInput.options[i];
        if (option.disabled || option.value === '') {
          continue;
        }
        participantsInput.value = option.value;
        break;
      }
    }

    if (shouldUpdate !== false) {
      updateSummary();
    }
  };

  var ensureDefaults = function() {
    if (dateInput) {
      if (today) {
        dateInput.setAttribute('data-min-date', today);
      }
    }

    var preferredDate = normalizeDate(dateInput && dateInput.value) || normalizeDate(defaults && defaults.date) || calendarState.selectedDate;
    if (minSelectableDate && (!preferredDate || compareISO(preferredDate, minSelectableDate) < 0)) {
      preferredDate = minSelectableDate;
    }
    if (!preferredDate) {
      preferredDate = today || new Date().toISOString().slice(0, 10);
    }

    calendarState.selectedDate = preferredDate;
    var monthStart = startOfMonthFromISO(preferredDate);
    if (monthStart) {
      calendarState.currentMonth = monthStart;
    }

    if (dateInput) {
      dateInput.value = preferredDate;
    }

    renderCalendar();

    if (participantsInput) {
      clampParticipants(false);
    }

    refreshPricing();
  };

  var computeTimeRange = function() {
    if (!dateInput || !dateInput.value) {
      return null;
    }

    var time = '';
    var hasSlots = availabilityState.slots && availabilityState.slots.length > 0;
    if (timeInput && /^\d{2}:\d{2}$/.test(timeInput.value || '')) {
      time = timeInput.value;
    } else if (hasSlots) {
      time = availabilityState.slots[0].value;
    } else if (typeof defaults.time === 'string' && /^\d{2}:\d{2}$/.test(defaults.time)) {
      time = defaults.time;
    } else {
      return null;
    }

    var start = new Date(dateInput.value + 'T' + time + ':00');
    if (isNaN(start.getTime())) {
      return null;
    }

    var duration = parseInt(durationMinutes, 10);
    if (isNaN(duration) || duration <= 0) {
      duration = 60;
    }

    var end = new Date(start.getTime() + duration * 60000);
    return {
      start: start.toISOString(),
      end: end.toISOString()
    };
  };

  var getParticipants = function() {
    if (!participantsInput) {
      return 1;
    }

    var value = parseInt(participantsInput.value || '0', 10);
    if (isNaN(value) || value < 1) {
      if (participantOptionList.length > 0) {
        value = parseInt(participantOptionList[0].value, 10);
      }
      if (isNaN(value) || value < 1) {
        value = 1;
      }
    }

    return value;
  };

  var getResourceId = function() {
    if (!Array.isArray(resources) || resources.length === 0) {
      return 0;
    }

    var first = resources[0];
    if (first && typeof first.id !== 'undefined') {
      return parseInt(first.id, 10) || 0;
    }

    return 0;
  };

  var handleBook = function(event) {
    if (event) {
      event.preventDefault();
    }

    if (isLoading) {
      return;
    }

    if (!composeUrl || !nonce) {
      showFeedback(messageLookup('generic_error', 'Er ging iets mis. Probeer het opnieuw.'), 'error');
      return;
    }

    if (!config || !config.productId) {
      showFeedback(messageLookup('generic_error', 'Er ging iets mis. Probeer het opnieuw.'), 'error');
      return;
    }

    if (!dateInput || !dateInput.value || !timeInput || !timeInput.value || availableTimeOptions.length === 0) {
      showFeedback(messageLookup('missing_fields', 'Vul datum, starttijd en aantal personen in.'), 'error');
      return;
    }

    var timeRange = computeTimeRange();
    if (!timeRange) {
      showFeedback(messageLookup('no_slots', 'Geen tijdsloten beschikbaar voor deze datum.'), 'warning');
      return;
    }

    var participants = getParticipants();
    if (participantsInput && participantsInput.disabled) {
      showFeedback(messageLookup('no_capacity', 'De geselecteerde capaciteit is niet beschikbaar.'), 'warning');
      return;
    }

    var payload = {
      mode: 'pay',
      participants: participants,
      items: [
        {
          product_id: config.productId,
          resource_id: getResourceId(),
          start: timeRange.start,
          end: timeRange.end
        }
      ]
    };

    showFeedback('', '');
    setLoading(true);

    fetch(composeUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce
      },
      body: JSON.stringify(payload)
    }).then(function(response){
      return response.json().then(function(json){
        return {
          ok: response.ok,
          status: response.status,
          data: json
        };
      }).catch(function(){
        return {
          ok: response.ok,
          status: response.status,
          data: null
        };
      });
    }).then(function(result){
      if (!result.ok || !result.data || result.data.ok !== true) {
        var errorMessage = messageLookup('generic_error', 'Er ging iets mis. Probeer het opnieuw.');
        if (result.data) {
          if (result.data.message) {
            errorMessage = result.data.message;
          } else if (result.data.data && result.data.data.message) {
            errorMessage = result.data.data.message;
          }
        }
        throw new Error(errorMessage);
      }

      var redirect = (result.data && result.data.redirect) || fallbackRedirect;
      if (redirect) {
        showFeedback(messageLookup('redirecting', 'Bezig met doorsturen.'), 'info');
        window.location.href = redirect;
      } else {
        showFeedback(messageLookup('generic_error', 'Er ging iets mis. Probeer het opnieuw.'), 'error');
      }
    }).catch(function(error){
      var text = error && error.message ? error.message : messageLookup('generic_error', 'Er ging iets mis. Probeer het opnieuw.');
      showFeedback(text, 'error');
    }).finally(function(){
      setLoading(false);
    });
  };

  var handlePlan = function(event) {
    if (event) {
      event.preventDefault();
    }

    var target = plannerUrl || '';
    if (!target) {
      var fallback = messageLookup('planner_missing', 'Plannerpagina niet gevonden.');
      showFeedback(fallback, 'warning');
      return;
    }

    if (!timeInput || !timeInput.value || availableTimeOptions.length === 0) {
      showFeedback(messageLookup('no_slots', 'Geen tijdsloten beschikbaar voor deze datum.'), 'warning');
      return;
    }

    if (participantsInput && participantsInput.disabled) {
      showFeedback(messageLookup('no_capacity', 'De geselecteerde capaciteit is niet beschikbaar.'), 'warning');
      return;
    }

    var params = [];
    if (dateInput && dateInput.value) {
      params.push('sbdp_date=' + encodeURIComponent(dateInput.value));
    }
    if (timeInput && timeInput.value) {
      params.push('sbdp_time=' + encodeURIComponent(timeInput.value));
    }
    if (participantsInput && participantsInput.value) {
      params.push('sbdp_participants=' + encodeURIComponent(participantsInput.value));
    }
    if (combiSelect && combiSelect.value) {
      params.push('sbdp_combi=' + encodeURIComponent(combiSelect.value));
      var combiOption = combiSelect.options[combiSelect.selectedIndex >= 0 ? combiSelect.selectedIndex : 0];
      if (combiOption && combiOption.textContent) {
        params.push('sbdp_combi_label=' + encodeURIComponent(combiOption.textContent.trim()));
      }
    }

    if (params.length) {
      target += (target.indexOf('?') === -1 ? '?' : '&') + params.join('&');
    }

    window.location.href = target;
  };

  if (participantsInput) {
    participantsInput.addEventListener('change', function(){
      clampParticipants();
      dispatchOptionsUpdated({ participants: participantsInput.value });
      refreshPricing();
    });
  }

  if (timeInput) {
    timeInput.addEventListener('change', function(){
      updateSummary();
      dispatchOptionsUpdated({ selectedTime: timeInput.value });
      refreshPricing();
    });
  }

  if (combiSelect) {
    combiSelect.addEventListener('change', function(){
      updateSummary();
      refreshPricing();
    });
  }

  if (dateInput) {
    dateInput.addEventListener('change', function(){
      selectDate(dateInput.value, { preserveTime: true, fetch: true });
    });
  }

  if (calendarPrev) {
    calendarPrev.addEventListener('click', function(){
      if (!calendarState.currentMonth) {
        calendarState.currentMonth = new Date();
      }
      var previousMonth = addMonths(calendarState.currentMonth, -1);
      if (!previousMonth) {
        return;
      }
      var minDateObj = minSelectableDate ? parseISODate(minSelectableDate) : null;
      var prevEnd = getMonthEnd(previousMonth);
      if (minDateObj && prevEnd && prevEnd.getTime() < minDateObj.getTime()) {
        return;
      }
      calendarState.currentMonth = previousMonth;
      renderCalendar();
    });
  }

  if (calendarNext) {
    calendarNext.addEventListener('click', function(){
      if (!calendarState.currentMonth) {
        calendarState.currentMonth = new Date();
      }
      var nextMonth = addMonths(calendarState.currentMonth, 1);
      if (!nextMonth) {
        return;
      }
      calendarState.currentMonth = nextMonth;
      renderCalendar();
    });
  }

  if (calendarGrid) {
    calendarGrid.addEventListener('click', function(event){
      var target = event.target && event.target.closest ? event.target.closest('[data-sbdp-calendar-date]') : null;
      if (!target || target.disabled || target.getAttribute('aria-disabled') === 'true') {
        return;
      }
      var value = target.getAttribute('data-sbdp-calendar-date');
      selectDate(value, { preserveTime: false, fetch: true });
    });
  }

  if (timeslotList) {
    timeslotList.addEventListener('click', function(event){
      var target = event.target && event.target.closest ? event.target.closest('[data-sbdp-time-slot]') : null;
      if (!target || target.disabled) {
        return;
      }
      var value = target.getAttribute('data-sbdp-time-slot');
      selectTimeSlot(value, true);
    });
    timeslotList.addEventListener('keydown', handleTimeKeydown);
  }

  if (bookButton) {
    bookButton.addEventListener('click', handleBook);
  }

  if (planButton) {
    planButton.addEventListener('click', handlePlan);
  }

  ensureDefaults();
  initialiseAvailability().then(function(){
    updateSummary();
  });
})();




