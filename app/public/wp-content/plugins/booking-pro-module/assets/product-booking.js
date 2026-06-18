(function(){
  'use strict';

  if (typeof window !== 'undefined') {
    window.SBDPProductBookingLoaded = true;
  }
  var container = document.querySelector('#sbdp-booking-form') || document.querySelector('[data-sbdp-product-form]');
  if (!container) {
    return;
  }
  function isSelectElement(field) {
    return !!(field && field.tagName && field.tagName.toLowerCase() === 'select');
  }
  if (
    (container.hasAttribute && container.hasAttribute('data-sbdp-legacy-form')) ||
    (container.closest && container.closest('[data-sbdp-legacy-form="true"]'))
  ) {
    return;
  }
  if (container.hasAttribute && container.hasAttribute('data-sbdp-booking-bound')) {
    return;
  }
  if (container.setAttribute) {
    container.setAttribute('data-sbdp-booking-bound', 'true');
  }

  var rawConfig = container.getAttribute ? container.getAttribute('data-sbdp-config') : null;
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
  var quoteUrl = (config && config.quoteUrl) || data.quote_url || '';
  var localizedPlanner = data.planner_url || '';
  var plannerRoute = data.planner_route || '';
  var messages = data.messages || {};
  var bookingCapability = (config && config.bookingCapability) || data.bookingCapability || {};
  var routeIntent = String(bookingCapability.route_intent || bookingCapability.routeIntent || '').toLowerCase();
  var isDirectBookingRoute = !routeIntent || routeIntent === 'checkout';
  var dataLabels = data.labels || {};
  var configLabels = (config && config.labels) || {};
  var fallbackDuration = 90;
  var durationMinutes = parseInt((config && config.duration) || data.duration, 10);
  if (isNaN(durationMinutes) || durationMinutes <= 0) {
    durationMinutes = fallbackDuration;
  }

  var bookingForm = container.querySelector ? container.querySelector('#sbdp-booking-form') : null;
  if (!bookingForm && container && container.matches && container.matches('#sbdp-booking-form')) {
    bookingForm = container;
  }
  var ensureSummaryLabel = function() {
    var summaryMeta = container.querySelector('.sbdp-summary-meta');
    if (!summaryMeta) {
      return;
    }
    var labels = summaryMeta.querySelectorAll('div, p, span, strong');
    if (!labels.length) {
      return;
    }
    Array.prototype.forEach.call(labels, function(node) {
      var text = (node.textContent || '').trim();
      if (text.toLowerCase() === 'prijs overzicht') {
        node.textContent = 'Overzicht';
      }
      if (node.textContent && node.textContent.trim().toLowerCase() === 'overzicht:') {
        node.setAttribute('data-sbdp-summary-label', 'true');
      }
    });
  };
  ensureSummaryLabel();
  if (!rawConfig && bookingForm) {
    var productField = bookingForm.querySelector('input[name="add-to-cart"]');
    if (productField && productField.value) {
      var productId = parseInt(productField.value, 10);
      if (!isNaN(productId) && productId > 0) {
        config.productId = productId;
      }
    }
  }

  var parsePriceValue = function(text) {
    if (!text) {
      return null;
    }
    var cleaned = String(text).replace(/[^\d,.-]/g, '');
    if (!cleaned) {
      return null;
    }
    if (cleaned.indexOf(',') !== -1 && cleaned.indexOf('.') !== -1) {
      cleaned = cleaned.replace(/\./g, '');
    }
    cleaned = cleaned.replace(',', '.');
    var parsed = parseFloat(cleaned);
    return isNaN(parsed) ? null : parsed;
  };

  var inferPriceFromDom = function() {
    var perPersonNode = document.getElementById('sbdp_price_per_person');
    if (perPersonNode && perPersonNode.textContent) {
      var parsedPerPerson = parsePriceValue(perPersonNode.textContent);
      if (parsedPerPerson !== null && parsedPerPerson > 0) {
        return {
          amount: parsedPerPerson,
          symbol: (perPersonNode.textContent.match(/[€$£]/) || [])[0] || ''
        };
      }
    }

    var selectors = [
      '.product .summary .price .woocommerce-Price-amount',
      '.woocommerce .price .woocommerce-Price-amount',
      '.product .price .amount',
      '.price .amount',
      '.woocommerce-Price-amount'
    ];
    for (var i = 0; i < selectors.length; i += 1) {
      var node = document.querySelector(selectors[i]);
      if (node && node.textContent) {
        var parsed = parsePriceValue(node.textContent);
        if (parsed !== null && parsed > 0) {
          return {
            amount: parsed,
            symbol: (node.textContent.match(/[€$£]/) || [])[0] || ''
          };
        }
      }
    }
    return null;
  };

  var inferred = inferPriceFromDom();
  if (inferred && inferred.amount) {
    if (!config.basePrice || config.basePrice <= 0) {
      config.basePrice = inferred.amount;
    }
    if (!config.perPersonPrice || config.perPersonPrice <= 0) {
      config.perPersonPrice = inferred.amount;
    }
    if (!config.currencySym && inferred.symbol) {
      config.currencySym = inferred.symbol;
    }
  }

  if (bookingForm && bookingForm.querySelector('[name="sbdp_participants"]')) {
    config.supportsPersons = true;
  }
  if (!config.combiOptions && Array.isArray(data.combiOptions)) {
    config.combiOptions = data.combiOptions;
  }
  if (!config.basePrice && typeof data.basePrice === 'number' && data.basePrice > 0) {
    config.basePrice = data.basePrice;
  }
  if (!config.perPersonPrice && typeof data.perPersonPrice === 'number' && data.perPersonPrice > 0) {
    config.perPersonPrice = data.perPersonPrice;
  }
  if (!config.currencySym && data.currencySym) {
    config.currencySym = data.currencySym;
  }
  if (bookingForm && !bookingForm.querySelector('.sbdp-form-title')) {
    var titleText = '';
    var titleNode = container.querySelector('.sbdp-product-hero__title')
      || container.querySelector('.sbdp-product-shell__title')
      || document.querySelector('.product_title')
      || document.querySelector('.entry-title');

    if (titleNode && titleNode.textContent) {
      titleText = titleNode.textContent.trim();
    }

    if (!titleText && config && config.productName) {
      titleText = String(config.productName).trim();
    }

    if (titleText) {
      var titleEl = document.createElement('h2');
      titleEl.className = 'sbdp-form-title';
      titleEl.textContent = titleText;
      bookingForm.insertBefore(titleEl, bookingForm.firstChild);
    }
  }


  var firstFilled = function(list) {
    if (!Array.isArray(list)) {
      return '';
    }

    for (var i = 0; i < list.length; i += 1) {
      var value = list[i];
      if (typeof value !== 'string') {
        continue;
      }

      var trimmed = value.trim();
      if (trimmed) {
        return trimmed;
      }
    }

    return '';
  };

  var toLowerCaseSafe = function(value) {
    if (typeof value !== 'string') {
      return '';
    }

    var trimmed = value.trim();
    if (!trimmed) {
      return '';
    }

    return trimmed.toLocaleLowerCase();
  };

  var deriveSingular = function(plural, fallback) {
    var base = (typeof plural === 'string' ? plural : '').trim();
    if (!base) {
      return fallback;
    }

    var lower = base.toLocaleLowerCase();
    if (lower === 'personen') {
      return 'Persoon';
    }
    if (lower === 'people') {
      return 'Person';
    }
    if (lower === 'guests') {
      return 'Guest';
    }

    if (lower.slice(-2) === 'en') {
      var candidate = base.slice(0, -2).trim();
      if (candidate) {
        return candidate;
      }
    }

    if (lower.slice(-1) === 's') {
      var fallbackCandidate = base.slice(0, -1).trim();
      if (fallbackCandidate) {
        return fallbackCandidate;
      }
    }

    return base;
  };

  var participantsPlural = firstFilled([
    configLabels.participants_plural,
    configLabels.participants,
    dataLabels.participants_plural,
    dataLabels.participants
  ]) || 'Deelnemers';
  var participantsSingular = firstFilled([
    configLabels.participants_singular,
    dataLabels.participants_singular
  ]) || deriveSingular(participantsPlural, 'Deelnemer');
  var participantsPluralLower = firstFilled([
    configLabels.participants_plural_lower,
    dataLabels.participants_plural_lower
  ]) || toLowerCaseSafe(participantsPlural);
  var participantsSingularLower = firstFilled([
    configLabels.participants_singular_lower,
    dataLabels.participants_singular_lower
  ]) || toLowerCaseSafe(participantsSingular);

  var selectParticipantsFallback = 'Selecteer aantal ' + (participantsPluralLower || 'personen');
  var missingFieldsFallback = 'Vul datum, starttijd en ' + (participantsPluralLower || 'personen') + ' in.';

  var plannerCard = container.querySelector('[data-sbdp-planner-card]');
  var plannerStatusNode = container.querySelector('[data-sbdp-planner-status]');
  var plannerBadge = container.querySelector('[data-sbdp-planner-indicator]');
  var scrollTriggers = container.querySelectorAll('[data-sbdp-scroll-target]');
  var PLANNER_QUEUE_KEY = 'sbdpPlannerPrefillQueue';
  var plannerQueueCount = 0;
  var plannerIndicatorState = null;

  var dateInput = container.querySelector('[name="sbdp_date"]');
  var timeInput = container.querySelector('[name="sbdp_time"]');
  var participantsInput = container.querySelector('[name="sbdp_participants"]');
  var participantsSuffix = container.querySelector('#sbdp_participants_suffix');
  var resourceSelect = container.querySelector('[data-sbdp-resource-select]');
  var resourceInput = container.querySelector('[data-sbdp-resource-input]');
  var combiSelect = container.querySelector('select[name="sbdp_combi"]');

  function normalizeCombiLabelSafe(label) {
    return normalizeCombiLabel(label);
  }

  function getCombiOptions() {
    var options = Array.isArray(config && config.combiOptions) ? config.combiOptions : [];
    if (!options.length && Array.isArray(data.combiOptions)) {
      options = data.combiOptions;
    }
    if (!options.length && window.SBDP_ProductBooking && Array.isArray(window.SBDP_ProductBooking.combiOptions)) {
      options = window.SBDP_ProductBooking.combiOptions;
      config.combiOptions = options;
    }
    if (!Array.isArray(options)) {
      return [];
    }
    var seen = {};
    return options.filter(function(option) {
      if (!option || typeof option.value === 'undefined' || option.value === null) {
        return false;
      }
      var key = String(option.value);
      if (seen[key]) {
        return false;
      }
      seen[key] = true;
      return true;
    });
  }

  function populateCombiSelect(selectEl, options) {
    if (!selectEl || !options || options.length === 0) {
      return;
    }
    while (selectEl.firstChild) {
      selectEl.removeChild(selectEl.firstChild);
    }
    var placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Geen combi geselecteerd';
    selectEl.appendChild(placeholder);

    options.forEach(function(option) {
      if (!option || typeof option.value === 'undefined') {
        return;
      }
      var opt = document.createElement('option');
      opt.value = String(option.value);
      var optLabel = normalizeCombiLabelSafe(option.name || option.label || String(option.value));
      opt.textContent = optLabel;
      opt.setAttribute('data-label', optLabel);
      if (option.image) {
        opt.setAttribute('data-image', String(option.image));
      }
      if (typeof option.duration !== 'undefined' && option.duration !== null) {
        opt.setAttribute('data-duration', String(option.duration));
      }
      if (typeof option.adjustment !== 'undefined' && option.adjustment !== null) {
        opt.setAttribute('data-adjustment', String(option.adjustment));
      }
      if (typeof option.supportsPersons !== 'undefined' && option.supportsPersons !== null) {
        opt.setAttribute('data-supports-persons', option.supportsPersons ? '1' : '0');
      }
      selectEl.appendChild(opt);
    });
  }

  var formatCombiCurrency = function(value) {
    if (typeof formatCurrency === 'function') {
      return formatCurrency(value);
    }
    var amount = parseFloat(value);
    if (!Number.isFinite(amount)) {
      amount = 0;
    }
    return (currencySymbol || '€') + amount.toFixed(2).replace('.', ',');
  };

  var ensureCombiField = function() {
    var combiOptions = getCombiOptions();
    if (!combiOptions.length) {
      return;
    }

    var form = bookingForm || container;
    if (!form) {
      return;
    }

    combiSelect = form.querySelector('select[name="sbdp_combi"]');
    var formGrid = form.querySelector('.sbdp-grid-shell');
    var summaryCard = formGrid ? formGrid.querySelector('.sbdp-summary-card') : null;

    if (!combiSelect && formGrid) {
      var combiField = document.createElement('div');
      combiField.className = 'sbdp-field sbdp-field--combi';

      var combiLabel = document.createElement('p');
      combiLabel.className = 'sbdp-label-heading';
      combiLabel.textContent = 'Combi-deal';

      var combiSelectEl = document.createElement('select');
      combiSelectEl.name = 'sbdp_combi';
      combiSelectEl.id = 'sbdp_combi';
      combiSelectEl.className = 'sbdp-field-select sbdp-combi-select';

      combiField.appendChild(combiLabel);
      combiField.appendChild(combiSelectEl);

      if (summaryCard && summaryCard.parentNode === formGrid) {
        formGrid.insertBefore(combiField, summaryCard);
      } else {
        formGrid.appendChild(combiField);
      }

      combiSelect = combiSelectEl;
    }


    if (combiSelect && (!combiSelect.options || combiSelect.options.length <= 1 || !combiSelect.hasAttribute('data-sbdp-combi-populated'))) {
      var previousValue = combiSelect.getAttribute('data-sbdp-last-value') || combiSelect.value || '';
      populateCombiSelect(combiSelect, combiOptions);
      if (previousValue) {
        combiSelect.value = previousValue;
        dispatchCombiChange(combiSelect);
      }
      combiSelect.setAttribute('data-sbdp-combi-populated', 'true');
    }

    if (combiSelect) {
      var existingList = form.querySelector('.sbdp-combi-list');
      var needsRebuild = false;
      if (existingList) {
        var valueMap = {};
        var duplicateFound = false;
        var optionButtons = existingList.querySelectorAll('.sbdp-combi-option[data-combi-value]:not([data-combi-value=""])');
        optionButtons.forEach(function(button) {
          var value = String(button.getAttribute('data-combi-value') || '');
          if (!value) {
            return;
          }
          if (valueMap[value]) {
            duplicateFound = true;
            return;
          }
          valueMap[value] = true;
        });
        needsRebuild = optionButtons.length !== combiOptions.length || duplicateFound;
        if (!needsRebuild && !existingList.hasAttribute('data-sbdp-combi-bound')) {
          needsRebuild = true;
        }
      }
      if (!existingList || needsRebuild) {
        if (existingList) {
          existingList.remove();
        }
        var injectedList = buildCombiList(combiSelect, combiOptions);
        if (injectedList && combiSelect.parentNode) {
          combiSelect.parentNode.appendChild(injectedList);
        }
      } else if (combiSelect.value) {
        syncCombiActive(existingList, combiSelect.value);
      }
    }

    if (combiSelect && !combiSelect.__sbdpCombiListenerAttached) {
      combiSelect.addEventListener('change', function(){
        updateSummary();
        refreshPricing();
      });
      combiSelect.__sbdpCombiListenerAttached = true;
      combiSelect.setAttribute('data-sbdp-combi-listener', 'true');
    }

    if (typeof updateSummary === 'function') {
      updateSummary();
    }
  };

  ensureCombiField();
  setTimeout(ensureCombiField, 300);
  setTimeout(ensureCombiField, 1200);

  function ensureCombiOption(selectEl, value, meta) {
    if (!selectEl || !value) {
      return;
    }
    var escaped = String(value).replace(/"/g, '\\"');
    var existing = selectEl.querySelector('option[value="' + escaped + '"]');
    if (existing) {
      return;
    }
    var option = document.createElement('option');
    var labelText = meta && meta.label ? normalizeCombiLabel(meta.label) : String(value);
    option.value = String(value);
    option.textContent = labelText;
    option.setAttribute('data-label', labelText);
    if (meta && meta.image) {
      option.setAttribute('data-image', meta.image);
    }
    if (meta && typeof meta.adjustment !== 'undefined' && meta.adjustment !== null) {
      option.setAttribute('data-adjustment', String(meta.adjustment));
    }
    if (meta && typeof meta.supportsPersons !== 'undefined' && meta.supportsPersons !== null) {
      option.setAttribute('data-supports-persons', meta.supportsPersons ? '1' : '0');
    }
    selectEl.appendChild(option);
  }

  function applyCombiSelection(list, selectEl, value, meta) {
    if (!selectEl) {
      return;
    }
    var nextValue = value || '';
    if (nextValue) {
      ensureCombiOption(selectEl, nextValue, meta || null);
    }
    combiSelect = selectEl;
    selectEl.value = nextValue;
    if (nextValue) {
      for (var i = 0; i < selectEl.options.length; i += 1) {
        if (selectEl.options[i].value === nextValue) {
          selectEl.selectedIndex = i;
          break;
        }
      }
    } else {
      selectEl.selectedIndex = 0;
    }
    selectEl.setAttribute('data-sbdp-last-value', nextValue);
    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
    if (list) {
      syncCombiActive(list, nextValue);
    }
    if (typeof updateSummary === 'function') {
      updateSummary();
    }
    refreshPricing();
  }

  function findCombiButtonFromPoint(list, x, y) {
    if (!list || typeof x !== 'number' || typeof y !== 'number') {
      return null;
    }
    var rect = list.getBoundingClientRect();
    if (x < rect.left || x > rect.right || y < rect.top || y > rect.bottom) {
      return null;
    }
    var hit = document.elementFromPoint(x, y);
    if (!hit) {
      return null;
    }
    if (hit.closest) {
      return hit.closest('.sbdp-combi-option');
    }
    var node = hit;
    while (node && node !== list) {
      if (node.classList && node.classList.contains('sbdp-combi-option')) {
        return node;
      }
      node = node.parentNode;
    }
    return null;
  }

  function buildCombiList(selectEl, options) {
    if (!selectEl || !Array.isArray(options) || options.length === 0) {
      return null;
    }

    var form = selectEl.closest ? selectEl.closest('form') : null;
    if (!form) {
      form = bookingForm || container;
    }
    var selectedIds = [];
    if (form) {
      var hiddenIds = form.querySelectorAll('input[name="sbdp_combi_ids[]"]');
      hiddenIds.forEach(function(input) {
        if (input && input.value) {
          selectedIds.push(String(input.value));
        }
      });
    }
    if (!selectedIds.length && selectEl.value) {
      selectedIds = [String(selectEl.value)];
    }

    var list = document.createElement('div');
    list.className = 'sbdp-combi-list';
    list.style.pointerEvents = 'auto';

    var resolveCombiButton = function(target) {
      if (!target) {
        return null;
      }
      if (target.closest) {
        return target.closest('.sbdp-combi-option');
      }
      var node = target;
      while (node && node !== list) {
        if (node.classList && node.classList.contains('sbdp-combi-option')) {
          return node;
        }
        node = node.parentNode;
      }
      return null;
    };

    var clearItem = document.createElement('button');
    clearItem.type = 'button';
    clearItem.className = 'sbdp-combi-option';
    clearItem.setAttribute('data-combi-value', '');
    clearItem.setAttribute('aria-pressed', selectedIds.length ? 'false' : 'true');
    if (!selectedIds.length) {
      clearItem.classList.add('is-active');
    }
    var clearThumb = document.createElement('span');
    clearThumb.className = 'sbdp-combi-thumb sbdp-combi-thumb--empty';
    clearItem.appendChild(clearThumb);
      var clearText = document.createElement('span');
      clearText.className = 'sbdp-combi-text';
      clearText.textContent = 'Geen combi';
      clearItem.appendChild(clearText);
    clearItem.addEventListener('click', function(event) {
      if (event) {
        event.preventDefault();
        event.stopPropagation();
      }
      toggleCombiSelection(list, selectEl, clearItem);
    });
    list.appendChild(clearItem);

    options.forEach(function(option) {
      if (!option || typeof option.value === 'undefined') {
        return;
      }
      var item = document.createElement('button');
      item.type = 'button';
      item.className = 'sbdp-combi-option';
      item.setAttribute('data-combi-value', String(option.value));
      item.setAttribute('aria-pressed', 'false');
      item.setAttribute('data-combi-timing', 'before');

      var rawLabel = option.name || option.label || String(option.value);
      item.setAttribute('data-label', rawLabel);
      if (typeof option.adjustment !== 'undefined' && option.adjustment !== null) {
        item.setAttribute('data-adjustment', String(option.adjustment));
      }
      if (typeof option.supportsPersons !== 'undefined' && option.supportsPersons !== null) {
        item.setAttribute('data-supports-persons', option.supportsPersons ? '1' : '0');
      }
      if (typeof option.duration !== 'undefined' && option.duration !== null) {
        item.setAttribute('data-duration', String(option.duration));
      }

      if (option.image) {
        item.setAttribute('data-image', String(option.image));
        var thumb = document.createElement('span');
        thumb.className = 'sbdp-combi-thumb';
        var imageUrl = String(option.image).replace(/\"/g, '');
        imageUrl = imageUrl.replace(/&amp;/g, '&');
        thumb.style.backgroundImage = 'url("' + encodeURI(imageUrl) + '")';
        var thumbImg = document.createElement('img');
        thumbImg.src = imageUrl;
        thumbImg.alt = normalizeCombiLabel(rawLabel);
        thumbImg.loading = 'lazy';
        thumbImg.decoding = 'async';
        thumb.appendChild(thumbImg);
        item.appendChild(thumb);
      } else {
        var fallbackThumb = document.createElement('span');
        fallbackThumb.className = 'sbdp-combi-thumb sbdp-combi-thumb--empty';
        item.appendChild(fallbackThumb);
      }

      var text = document.createElement('span');
      text.className = 'sbdp-combi-text';
      text.textContent = normalizeCombiLabel(rawLabel);
      item.appendChild(text);

      if (option.adjustment) {
        var priceText = document.createElement('span');
        priceText.className = 'sbdp-combi-price';
        priceText.textContent = formatCombiCurrency(option.adjustment) + (option.supportsPersons ? ' p.p.' : '');
        item.appendChild(priceText);
      }

      var timingRow = document.createElement('span');
      timingRow.className = 'sbdp-combi-timing';

      var timingValue = 'before';
      if (form) {
        var timingInput = form.querySelector('input[name="sbdp_combi_timing[' + String(option.value) + ']"]');
        if (timingInput && timingInput.value === 'after') {
          timingValue = 'after';
        }
      }
      var preferredTiming = getPreferredCombiTiming(rawLabel);
      if (preferredTiming) {
        timingValue = preferredTiming;
      }
      item.setAttribute('data-combi-timing', timingValue);

      var beforeLabel = document.createElement('label');
      beforeLabel.className = 'sbdp-combi-timing-option';
      var beforeInput = document.createElement('input');
      beforeInput.type = 'radio';
      beforeInput.name = 'sbdp_combi_timing_' + String(option.value);
      beforeInput.value = 'before';
      beforeInput.checked = timingValue === 'before';
      beforeInput.disabled = preferredTiming === 'after';
      beforeLabel.appendChild(beforeInput);
      beforeLabel.appendChild(document.createTextNode(' Vooraf'));

      var afterLabel = document.createElement('label');
      afterLabel.className = 'sbdp-combi-timing-option';
      var afterInput = document.createElement('input');
      afterInput.type = 'radio';
      afterInput.name = 'sbdp_combi_timing_' + String(option.value);
      afterInput.value = 'after';
      afterInput.checked = timingValue === 'after';
      afterInput.disabled = preferredTiming === 'before';
      afterLabel.appendChild(afterInput);
      afterLabel.appendChild(document.createTextNode(' Achteraf'));

      timingRow.appendChild(beforeLabel);
      timingRow.appendChild(afterLabel);
      item.appendChild(timingRow);

      if (selectedIds.indexOf(String(option.value)) !== -1) {
        item.classList.add('is-active');
        item.setAttribute('aria-pressed', 'true');
      }

      item.addEventListener('click', function(event) {
        if (event && event.target && event.target.tagName === 'INPUT') {
          return;
        }
        if (event) {
          event.preventDefault();
          event.stopPropagation();
        }
        toggleCombiSelection(list, selectEl, item);
      });

      beforeInput.addEventListener('change', function(event) {
        if (event) {
          event.stopPropagation();
        }
        syncCombiTiming(item, 'before');
        if (typeof updateSummary === 'function') {
          updateSummary();
        }
      });

      afterInput.addEventListener('change', function(event) {
        if (event) {
          event.stopPropagation();
        }
        syncCombiTiming(item, 'after');
        if (typeof updateSummary === 'function') {
          updateSummary();
        }
      });
      list.appendChild(item);
    });

    if (!list.hasAttribute('data-sbdp-combi-bound')) {
      list.addEventListener('change', function(event) {
        var target = event.target;
        if (!target || target.tagName !== 'INPUT' || target.type !== 'radio') {
          return;
        }
        var parentButton = resolveCombiButton(target);
        if (!parentButton) {
          return;
        }
        var value = target.value === 'after' ? 'after' : 'before';
        syncCombiTiming(parentButton, value);
        updateSummary();
      });
      list.setAttribute('data-sbdp-combi-bound', 'true');
    }

    syncCombiActive(list, selectEl.value);
    selectEl.addEventListener('change', function() {
      syncCombiActive(list, selectEl.value);
    });

    return list;
  }

  function syncCombiActive(list, value) {
    if (!list) {
      return;
    }
    var hasSelection = list.querySelector('.sbdp-combi-option.is-active');
    if (hasSelection) {
      var clearItem = list.querySelector('.sbdp-combi-option[data-combi-value=""]');
      if (clearItem && clearItem.classList.contains('is-active')) {
        clearItem.classList.remove('is-active');
        clearItem.setAttribute('aria-pressed', 'false');
      }
      return;
    }
    var items = list.querySelectorAll('.sbdp-combi-option');
    items.forEach(function(item){
      var itemValue = item.getAttribute('data-combi-value');
      var isActive = itemValue === value;
      item.classList.toggle('is-active', isActive);
      item.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
  }

  function syncCombiTiming(button, value) {
    if (!button) {
      return;
    }
    var timing = value === 'after' ? 'after' : 'before';
    button.setAttribute('data-combi-timing', timing);
    var inputs = button.querySelectorAll('input[type="radio"]');
    inputs.forEach(function(input) {
      input.checked = input.value === timing;
    });
  }

  function getCombiSelections() {
    var list = container.querySelector('.sbdp-combi-list');
    var form = bookingForm || container;
    if (!form) {
      return [];
    }
    var selections = [];
    var selectionMap = {};

    var items = list ? list.querySelectorAll('.sbdp-combi-option.is-active') : [];
    items.forEach(function(item) {
      var value = item.getAttribute('data-combi-value') || '';
      if (!value || selectionMap[value]) {
        return;
      }
      var adjustment = 0;
      var raw = item.getAttribute('data-adjustment');
      if (raw) {
        raw = raw.replace(',', '.');
        adjustment = parseFloat(raw);
        if (isNaN(adjustment)) {
          adjustment = 0;
        }
      }
      var duration = 0;
      var rawDuration = item.getAttribute('data-duration');
      if (rawDuration) {
        duration = parseInt(rawDuration, 10);
        if (isNaN(duration) || duration <= 0) {
          duration = 0;
        }
      }
      var timing = 'before';
      var attrTiming = item.getAttribute('data-combi-timing');
      if (attrTiming === 'after') {
        timing = 'after';
      } else {
        var checked = item.querySelector('input[type="radio"]:checked');
        if (checked && checked.value === 'after') {
          timing = 'after';
        }
      }
      selectionMap[value] = true;
      selections.push({
        value: value,
        label: normalizeCombiLabel(item.getAttribute('data-label') || ''),
        adjustment: adjustment,
        duration: duration,
        timing: timing,
        supportsPersons: resolveCombiSupportsPersons(item.getAttribute('data-supports-persons'))
      });
    });

    var hiddenIds = form.querySelectorAll('input[name="sbdp_combi_ids[]"]');
    hiddenIds.forEach(function(input) {
      if (!input || !input.value) {
        return;
      }
      var value = String(input.value);
      if (selectionMap[value]) {
        return;
      }
      var option = combiSelect ? combiSelect.querySelector('option[value="' + value.replace(/"/g, '\\"') + '"]') : null;
      var explicitLabelInput = form.querySelector('input[name="sbdp_combi_label[' + value + ']"]');
      var label = explicitLabelInput && explicitLabelInput.value 
          ? normalizeCombiLabel(explicitLabelInput.value) 
          : (option ? normalizeCombiLabel(option.getAttribute('data-label') || option.textContent || '') : value);
      var adjustment = 0;
      var raw = option ? option.getAttribute('data-adjustment') : '';
      if (raw) {
        raw = raw.replace(',', '.');
        adjustment = parseFloat(raw);
        if (isNaN(adjustment)) {
          adjustment = 0;
        }
      }
      var duration = 0;
      var rawDuration = option ? option.getAttribute('data-duration') : '';
      if (rawDuration) {
        duration = parseInt(rawDuration, 10);
        if (isNaN(duration) || duration <= 0) {
          duration = 0;
        }
      }
      var timingInput = form.querySelector('input[name="sbdp_combi_timing[' + value + ']"]');
      var timing = timingInput && timingInput.value === 'after' ? 'after' : 'before';
      selections.push({
        value: value,
        label: label,
        adjustment: adjustment,
        duration: duration,
        timing: timing,
        supportsPersons: resolveCombiSupportsPersons(option ? option.getAttribute('data-supports-persons') : '')
      });
    });

    if (!selections.length && combiSelect && combiSelect.value) {
      var fallbackValue = String(combiSelect.value);
      if (!selectionMap[fallbackValue]) {
        var fallbackOption = combiSelect.options[combiSelect.selectedIndex];
        if (fallbackOption) {
          var fallbackLabel = normalizeCombiLabel(fallbackOption.getAttribute('data-label')
            || fallbackOption.textContent || fallbackValue);
          var fallbackAdjustment = 0;
          var fallbackRaw = fallbackOption.getAttribute('data-adjustment');
          if (fallbackRaw) {
            fallbackRaw = fallbackRaw.replace(',', '.');
            fallbackAdjustment = parseFloat(fallbackRaw);
            if (isNaN(fallbackAdjustment)) {
              fallbackAdjustment = 0;
            }
          }
          var fallbackDuration = 0;
          var fallbackDurationRaw = fallbackOption.getAttribute('data-duration');
          if (fallbackDurationRaw) {
            fallbackDuration = parseInt(fallbackDurationRaw, 10);
            if (isNaN(fallbackDuration) || fallbackDuration <= 0) {
              fallbackDuration = 0;
            }
          }
          selections.push({
            value: fallbackValue,
            label: fallbackLabel,
            adjustment: fallbackAdjustment,
            duration: fallbackDuration,
            timing: 'before',
            supportsPersons: resolveCombiSupportsPersons(fallbackOption.getAttribute('data-supports-persons'))
          });
        }
      }
    }
    return selections;
  }

  function syncCombiSelectFromList(list, selectEl) {
    if (!list || !selectEl) {
      return;
    }
    var first = list.querySelector('.sbdp-combi-option.is-active[data-combi-value]');
    selectEl.value = first ? (first.getAttribute('data-combi-value') || '') : '';
    selectEl.setAttribute('data-sbdp-last-value', selectEl.value || '');
    dispatchCombiChange(selectEl);
  }

  function toggleCombiSelection(list, selectEl, button) {
    if (!list || !button) {
      return;
    }
    var value = button.getAttribute('data-combi-value') || '';
    if (!value) {
      var active = list.querySelectorAll('.sbdp-combi-option.is-active');
      active.forEach(function(item) {
        item.classList.remove('is-active');
        item.setAttribute('aria-pressed', 'false');
      });
    } else {
      var clearItem = list.querySelector('.sbdp-combi-option[data-combi-value=""]');
      if (clearItem) {
        clearItem.classList.remove('is-active');
        clearItem.setAttribute('aria-pressed', 'false');
      }
      var nextActive = !button.classList.contains('is-active');
      button.classList.toggle('is-active', nextActive);
      button.setAttribute('aria-pressed', nextActive ? 'true' : 'false');
    }
    syncCombiSelectFromList(list, selectEl);
    if (typeof updateSummary === 'function') {
      updateSummary();
    }
    refreshPricing();
  }

  function updateCombiHiddenFields(selections) {
    var form = bookingForm || container;
    if (!form) {
      return;
    }
    var existing = form.querySelectorAll('[data-sbdp-combi-hidden]');
    existing.forEach(function(node) {
      node.remove();
    });
    if (!selections || !selections.length) {
      return;
    }
    selections.forEach(function(selection) {
      var id = selection.value;
      if (!id) {
        return;
      }
      var idInput = document.createElement('input');
      idInput.type = 'hidden';
      idInput.name = 'sbdp_combi_ids[]';
      idInput.value = id;
      idInput.setAttribute('data-sbdp-combi-hidden', 'true');
      form.appendChild(idInput);

      var timingInput = document.createElement('input');
      timingInput.type = 'hidden';
      timingInput.name = 'sbdp_combi_timing[' + id + ']';
      timingInput.value = selection.timing === 'after' ? 'after' : 'before';
      timingInput.setAttribute('data-sbdp-combi-hidden', 'true');
      form.appendChild(timingInput);

      var labelInput = document.createElement('input');
      labelInput.type = 'hidden';
      labelInput.name = 'sbdp_combi_label[' + id + ']';
      labelInput.value = selection.label || '';
      labelInput.setAttribute('data-sbdp-combi-hidden', 'true');
      form.appendChild(labelInput);
    });
  }

  if (!document.documentElement.hasAttribute('data-sbdp-combi-capture')) {
    document.documentElement.setAttribute('data-sbdp-combi-capture', 'true');
  }
  var feedback = container.querySelector('[data-sbdp-feedback]');
  var bookButton = container.querySelector('[data-sbdp-action="book"]');
  var quoteButton = container.querySelector('[data-sbdp-action="quote"]');
  var planButton = container.querySelector('[data-sbdp-action="plan"]') || container.querySelector('#sbdp_plan_btn');
  var queueButton = container.querySelector('[data-sbdp-action="queue"]');
  var buttons = container.querySelectorAll('[data-sbdp-action]');
  var calendarRoot = container.querySelector('[data-sbdp-calendar]');
  var calendarLabel = container.querySelector('[data-sbdp-calendar-label]');
  var calendarGrid = container.querySelector('[data-sbdp-calendar-grid]');
  var calendarPrev = container.querySelector('[data-sbdp-calendar-prev]');
  var calendarNext = container.querySelector('[data-sbdp-calendar-next]');

  if (!calendarRoot && dateInput && rawConfig) {
    var dateField = dateInput.closest ? dateInput.closest('.sbdp-field') : null;
    if (!dateField && dateInput.parentElement) {
      dateField = dateInput.parentElement;
    }

    if (dateField) {
      var calendarWrapper = document.createElement('div');
      calendarWrapper.className = 'sbdp-date-picker';
      calendarWrapper.setAttribute('data-sbdp-calendar', '');
      calendarWrapper.setAttribute('aria-label', 'Beschikbare dagen');

      var header = document.createElement('div');
      header.className = 'sbdp-date-picker__header';

      var prev = document.createElement('button');
      prev.type = 'button';
      prev.className = 'sbdp-date-picker__nav sbdp-date-picker__nav--prev';
      prev.setAttribute('data-sbdp-calendar-prev', '');
      prev.setAttribute('aria-label', 'Vorige maand');
      prev.innerHTML = '<span aria-hidden="true"><</span>';

      var label = document.createElement('p');
      label.className = 'sbdp-date-picker__label';
      label.setAttribute('data-sbdp-calendar-label', '');
      label.setAttribute('aria-live', 'polite');

      var next = document.createElement('button');
      next.type = 'button';
      next.className = 'sbdp-date-picker__nav sbdp-date-picker__nav--next';
      next.setAttribute('data-sbdp-calendar-next', '');
      next.setAttribute('aria-label', 'Volgende maand');
      next.innerHTML = '<span aria-hidden="true">></span>';

      header.appendChild(prev);
      header.appendChild(label);
      header.appendChild(next);
      calendarWrapper.appendChild(header);

      var weekdays = document.createElement('div');
      weekdays.className = 'sbdp-date-picker__weekdays';
      weekdays.setAttribute('aria-hidden', 'true');
      var weekdayLabels = ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'];
      for (var i = 0; i < weekdayLabels.length; i += 1) {
        var weekday = document.createElement('span');
        weekday.className = 'sbdp-date-picker__weekday';
        weekday.textContent = weekdayLabels[i];
        weekdays.appendChild(weekday);
      }
      calendarWrapper.appendChild(weekdays);

      var grid = document.createElement('div');
      grid.className = 'sbdp-date-picker__grid';
      grid.setAttribute('role', 'grid');
      grid.setAttribute('data-sbdp-calendar-grid', '');
      calendarWrapper.appendChild(grid);

      dateField.appendChild(calendarWrapper);
      dateInput.type = 'hidden';
    }

    calendarRoot = container.querySelector('[data-sbdp-calendar]');
    calendarLabel = container.querySelector('[data-sbdp-calendar-label]');
    calendarGrid = container.querySelector('[data-sbdp-calendar-grid]');
    calendarPrev = container.querySelector('[data-sbdp-calendar-prev]');
    calendarNext = container.querySelector('[data-sbdp-calendar-next]');
  }
  var timePicker = container.querySelector('[data-sbdp-time-picker]');
  var timeslotList = container.querySelector('[data-sbdp-timeslot-list]');
  var timeslotEmpty = container.querySelector('[data-sbdp-timeslot-empty]');
  var timeChipGroup = container.querySelector('[data-ddb-chip-group="time"]');

  if (!isDirectBookingRoute && bookButton && !quoteButton) {
    bookButton.setAttribute('data-sbdp-action', 'quote');
    bookButton.textContent = 'Vraag offerte aan';
    quoteButton = bookButton;
    bookButton = null;
  }

  var getResolvedTimeValue = function() {
    var currentTime = normalizeTime(timeInput && timeInput.value);
    if (currentTime) {
      return currentTime;
    }

    var persistedTime = normalizeTime(persistedSelection.time);
    if (persistedTime) {
      if (timeInput) {
        timeInput.value = persistedTime;
      }
      return persistedTime;
    }

    var fallbackTime = normalizeTime(defaultTime);
    if (fallbackTime) {
      if (timeInput) {
        timeInput.value = fallbackTime;
      }
      return fallbackTime;
    }

    return '';
  };

  var syncTimeChips = function(selectedValue) {
    if (!timeChipGroup) {
      return;
    }

    var buttons = timeChipGroup.querySelectorAll('[data-ddb-time]');
    buttons.forEach(function(button){
      var chipValue = button.getAttribute('data-ddb-time') || '';
      var isActive = chipValue === selectedValue;
      button.classList.toggle('is-active', isActive);
      button.classList.toggle('ddb-slot--selected', isActive);
      button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
  };

  var applyTimeChipSelection = function(value) {
    var normalized = normalizeTime(value);
    if (!normalized || !timeInput) {
      return;
    }

    timeInput.value = normalized;
    syncTimeChips(normalized);
    timeInput.dispatchEvent(new Event('change', { bubbles: true }));
  };

  if (timeChipGroup && timeInput) {
    var chipButtons = timeChipGroup.querySelectorAll('[data-ddb-time]');
    chipButtons.forEach(function(button){
      button.setAttribute('type', 'button');
      button.addEventListener('click', function(event){
        event.preventDefault();
        applyTimeChipSelection(button.getAttribute('data-ddb-time') || '');
      });
    });

    timeChipGroup.addEventListener('click', function(event){
      var target = event.target && event.target.closest ? event.target.closest('[data-ddb-time]') : null;
      if (!target || target.disabled || target.classList.contains('is-disabled') || target.getAttribute('aria-disabled') === 'true') {
        return;
      }
      event.preventDefault();
      applyTimeChipSelection(target.getAttribute('data-ddb-time') || '');
    });

    if (timeInput.value) {
      syncTimeChips(normalizeTime(timeInput.value));
    } else {
      var activeChip = timeChipGroup.querySelector('[data-ddb-time].is-active');
      if (activeChip) {
        applyTimeChipSelection(activeChip.getAttribute('data-ddb-time') || '');
      }
    }
  }


  var summaryTotal = container.querySelector('[data-sbdp-total]');
  var summaryDate = container.querySelector('[data-summary-date]');
  var summaryTime = container.querySelector('[data-summary-time]');
  var summaryBreakdown = container.querySelector('[data-summary-breakdown]');
  var summaryPeople = container.querySelector('[data-summary-people]');
  var summaryCombiRow = container.querySelector('[data-summary-combi]');
  var summaryCombiLabel = container.querySelector('[data-summary-combi-label]');
  var summaryCombiValue = container.querySelector('[data-summary-combi-value]');
  var summaryHint = container.querySelector('[data-summary-hint]');

  var summaryDefaults = {
    date: summaryDate ? summaryDate.textContent : '',
    time: summaryTime ? summaryTime.textContent : '',
    people: summaryPeople ? summaryPeople.textContent : '',
    combiLabel: summaryCombiLabel ? summaryCombiLabel.textContent : '',
    combiValue: summaryCombiValue ? summaryCombiValue.textContent : ''
  };
  var hintDefaultText = summaryHint ? summaryHint.textContent : '';

  var plannerUrl = (config && config.plannerUrl) || localizedPlanner || '';
  if (plannerRoute) {
    plannerUrl = appendRoute(plannerUrl, plannerRoute);
  }
  var limits = (config && config.limits) || {};
  var defaults = (config && config.defaults) || {};
  var today = (config && config.today) || '';
  var resources = Array.isArray(config && config.resources) ? config.resources : [];
  var basePrice = parseFloat(config && config.basePrice) || 0;
  var perPersonPrice = parseFloat(config && config.perPersonPrice) || 0;
  var fixedFee = parseFloat(config && config.fixedFee) || 0;
  var supportsPersons = !!(config && config.supportsPersons);

  function resolveCombiSupportsPersons(raw) {
    if (typeof raw === 'boolean') {
      return raw;
    }
    if (typeof raw === 'string') {
      var normalized = raw.trim().toLowerCase();
      if (normalized === '1' || normalized === 'true' || normalized === 'yes') {
        return true;
      }
      if (normalized === '0' || normalized === 'false' || normalized === 'no') {
        return false;
      }
    }
    return supportsPersons;
  }

  var currency = config && config.currency ? config.currency : 'EUR';
  var currencySymbol = config && config.currencySym ? config.currencySym : currency;
  var locale = config && config.locale ? String(config.locale).replace('_', '-') : 'nl-NL';
  var isLoading = false;
  var availabilityUrl = data.availability || '';
  var availabilitySlotsUrl = data.availability_slots || '';
  var availabilityCache = {};
  var availabilityPending = {};
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
  var allowPreviewPricing = !(!rawConfig && bookingForm && bookingForm.matches && bookingForm.matches('#sbdp-booking-form'));
  if (!allowPreviewPricing) {
    pricingPreviewUrl = '';
  }

  function syncCombiTiming(list, value) {
    if (!list) {
      return;
    }
    var timing = value === 'after' ? 'after' : 'before';
    list.setAttribute('data-sbdp-combi-timing', timing);
    var inputs = list.querySelectorAll('input[name="sbdp_combi_timing"]');
    inputs.forEach(function(input) {
      input.checked = input.value === timing;
    });
  }
  var pricingState = {
    total: null,
    formatted: null,
    unit: null,
    displayTotal: null,
    displayUnit: null,
    displayScale: 1,
    raw: null,
    loading: false
  };
  var pricingAbortController = null;
  var isIgnorableFetchError = function(error, controller) {
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
  };
  var resourceCapacity = null;
  var updateResourceCapacity = function(resourceId) {
    if (!Array.isArray(resources) || resources.length === 0) {
      resourceCapacity = null;
      return;
    }
    var match = null;
    if (resourceId) {
      for (var i = 0; i < resources.length; i += 1) {
        if (String(resources[i].id) === String(resourceId)) {
          match = resources[i];
          break;
        }
      }
    }
    if (!match) {
      match = resources[0];
    }
    var parsedCapacity = match && typeof match.capacity !== 'undefined' ? parseInt(match.capacity, 10) : NaN;
    if (!isNaN(parsedCapacity) && parsedCapacity > 0) {
      resourceCapacity = parsedCapacity;
    } else {
      resourceCapacity = null;
    }
  };
  updateResourceCapacity(resourceSelect ? resourceSelect.value : null);
  if (resourceSelect && resourceInput) {
    resourceInput.value = resourceSelect.value || '';
  }

  ensureHelperBar();
  ensureQueueBadge();
  if (feedback) {
    feedback.setAttribute('role', 'status');
    feedback.setAttribute('aria-live', 'polite');
  }
  if (summaryTotal) {
    summaryTotal.setAttribute('role', 'status');
    summaryTotal.setAttribute('aria-live', 'polite');
  }
  if (summaryHint) {
    summaryHint.setAttribute('role', 'status');
    summaryHint.setAttribute('aria-live', 'polite');
  }
  if (bookButton && !bookButton.getAttribute('aria-label')) {
    bookButton.setAttribute('aria-label', 'Direct boeken met de gekozen datum, tijd en deelnemers');
  }
  if (quoteButton && !quoteButton.getAttribute('aria-label')) {
    quoteButton.setAttribute('aria-label', 'Vraag een offerte aan voor deze activiteit');
  }
  if (planButton && !planButton.getAttribute('aria-label')) {
    planButton.setAttribute('aria-label', 'Plan je dag met de huidige selectie');
  }
  if (dateInput && !dateInput.getAttribute('aria-label')) {
    dateInput.setAttribute('aria-label', 'Selecteer de gewenste datum');
  }
  if (timeInput && !timeInput.getAttribute('aria-label')) {
    timeInput.setAttribute('aria-label', 'Selecteer het gewenste starttijdstip');
  }
  if (participantsInput && !participantsInput.getAttribute('aria-label')) {
    participantsInput.setAttribute('aria-label', 'Kies het aantal deelnemers');
  }
  if (participantsInput && !isSelectElement(participantsInput)) {
    participantsInput.setAttribute('step', '1');
    participantsInput.setAttribute('inputmode', 'numeric');
    if (!participantsInput.getAttribute('min')) {
      participantsInput.setAttribute('min', '1');
    }
  }
  if (queueButton && !queueButton.getAttribute('aria-label')) {
    queueButton.setAttribute('aria-label', 'Zet activiteit klaar voor planner');
  }

  var storageKey = config && config.productId ? 'sbdp:lastSelection:' + config.productId : 'sbdp:lastSelection';
  var persistedSelection = {};
  var helperBar = null;
  var helperLiveRegion = null;
  var queueBadgeNode = null;

  function readPersistedSelection() {
    if (typeof window === 'undefined' || typeof window.localStorage === 'undefined') {
      return {};
    }
    try {
      var raw = window.localStorage.getItem(storageKey);
      if (!raw) {
        return {};
      }
      var parsed = JSON.parse(raw);
      return {
        date: normalizeDate(parsed && parsed.date),
        time: normalizeTime(parsed && parsed.time),
        participants: ''
      };
    } catch (error) {
      return {};
    }
  }

  function persistSelection(partial) {
    if (typeof window === 'undefined' || typeof window.localStorage === 'undefined') {
      return;
    }
    var next = Object.assign({}, persistedSelection, partial);
    if (next && Object.prototype.hasOwnProperty.call(next, 'participants')) {
      delete next.participants;
    }
    persistedSelection = next;
    try {
      window.localStorage.setItem(storageKey, JSON.stringify({
        date: persistedSelection.date || '',
        time: persistedSelection.time || '',
        participants: ''
      }));
    } catch (error) {
      // ignore
    }
  }

  persistedSelection = readPersistedSelection();

  function ensureHelperStyles() {
    if (document.getElementById('sbdp-helper-styles')) {
      return;
    }
    var styleEl = document.createElement('style');
    styleEl.id = 'sbdp-helper-styles';
    styleEl.textContent = '' +
      '.sbdp-progress-helper{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0;padding:8px 10px;background:#f7f9fb;border:1px solid #d9e3ec;border-radius:8px;font-size:12px;}' +
      '.sbdp-progress-helper__item{display:flex;align-items:center;gap:6px;padding:6px 8px;border-radius:6px;background:#fff;border:1px dashed #d0d7de;transition:all .25s ease;}' +
      '.sbdp-progress-helper__icon{width:22px;height:22px;border-radius:50%;border:2px solid #b0c0ce;background:#fff;color:#1f2d3d;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;box-sizing:border-box;box-shadow:0 1px 2px rgba(0,0,0,0.05);}' +
      '.sbdp-progress-helper__icon::after{content:attr(data-step);}' +
      '.sbdp-progress-helper__item.is-complete{border-color:#3aa76d;background:rgba(58,167,109,0.08);box-shadow:0 2px 6px rgba(58,167,109,0.12);}'+
      '.sbdp-progress-helper__item.is-complete .sbdp-progress-helper__icon{border-color:#3aa76d;background:#fff;color:#3aa76d;transform:scale(1.05);}' +
      '.sbdp-progress-helper__item.is-complete .sbdp-progress-helper__icon::after{content:"\u2713";font-size:12px;}' +
      '.sbdp-progress-helper__item:not(.is-complete) .sbdp-progress-helper__icon::after{content:attr(data-step);color:#4b5968;}' +
      '.sbdp-queue-badge{position:fixed;right:16px;top:16px;z-index:9999;background:#123b75;color:#fff;padding:10px 12px;border-radius:10px;box-shadow:0 6px 20px rgba(0,0,0,0.18);font-size:13px;line-height:1.2;}'+
      '.sbdp-queue-badge__count{font-weight:700;display:block;}' +
      '.sbdp-feedback__link{margin-left:8px;font-weight:600;text-decoration:underline;}';
    document.head.appendChild(styleEl);
  }

  function ensureHelperBar() {
    return;
  }

  function updateHelperProgress() {
    return;
  }

  function ensureQueueBadge() {
    if (queueBadgeNode || typeof document === 'undefined') {
      return;
    }
    ensureHelperStyles();
    var badge = document.createElement('div');
    badge.className = 'sbdp-queue-badge';
    badge.setAttribute('data-sbdp-queue-badge', 'true');
    badge.setAttribute('role', 'status');
    badge.setAttribute('aria-live', 'polite');
    badge.style.display = 'none';
    badge.innerHTML = '<span class="sbdp-queue-badge__count"></span><span class="sbdp-queue-badge__meta"></span>';
    document.body.appendChild(badge);
    queueBadgeNode = badge;
  }

  function renderQueueBadge(queue) {
    ensureQueueBadge();
    if (!queueBadgeNode) {
      return;
    }
    var countNode = queueBadgeNode.querySelector('.sbdp-queue-badge__count');
    var metaNode = queueBadgeNode.querySelector('.sbdp-queue-badge__meta');
    var hasItems = Array.isArray(queue) && queue.length > 0;
    if (!hasItems) {
      queueBadgeNode.style.display = 'none';
      if (countNode) { countNode.textContent = ''; }
      if (metaNode) { metaNode.textContent = ''; }
      return;
    }
    var latest = queue[queue.length - 1] || {};
    var label = queue.length + (queue.length === 1 ? ' activiteit' : ' activiteiten');
    if (countNode) {
      countNode.textContent = label;
    }
    if (metaNode) {
      var parts = [];
      if (latest.date) {
        if (typeof formatDateDisplay === 'function') {
          parts.push(formatDateDisplay(latest.date));
        } else {
          parts.push(latest.date);
        }
      }
      if (latest.time) {
        parts.push(latest.time);
      }
      metaNode.textContent = parts.length ? parts.join(' • ') : 'Klaar in planner';
    }
    queueBadgeNode.style.display = 'block';
  }

  function appendRoute(base, route) {
    if (!base) {
      return route || '';
    }
    if (!route) {
      return base;
    }

    var workingBase = base;
    var fragment = '';
    var hashIndex = workingBase.indexOf('#');
    if (hashIndex >= 0) {
      fragment = workingBase.slice(hashIndex);
      workingBase = workingBase.slice(0, hashIndex);
    }

    var query = '';
    var queryIndex = workingBase.indexOf('?');
    if (queryIndex >= 0) {
      query = workingBase.slice(queryIndex);
      workingBase = workingBase.slice(0, queryIndex);
    }

    var cleanedBase = workingBase.replace(/\/+$/, '');
    var cleanedRoute = String(route).replace(/^\/+/, '').replace(/\/+$/, '');
    if (cleanedRoute === '') {
      return cleanedBase + query + fragment;
    }

    var lowerBase = cleanedBase.toLowerCase();
    var lowerRoute = cleanedRoute.toLowerCase();
    var targetBase = lowerBase.endsWith('/' + lowerRoute)
      ? cleanedBase
      : cleanedBase + '/' + cleanedRoute;

    return targetBase + query + fragment;
  }

  var readPlannerQueue = function() {
    if (typeof window === 'undefined' || typeof window.sessionStorage === 'undefined') {
      plannerQueueCount = 0;
      updatePlannerBadge();
      renderQueueBadge([]);
      updateHelperProgress();
      return [];
    }

    try {
      var raw = window.sessionStorage.getItem(PLANNER_QUEUE_KEY);
      if (!raw) {
        plannerQueueCount = 0;
        updatePlannerBadge();
        renderQueueBadge([]);
        updateHelperProgress();
        return [];
      }

      var parsed = JSON.parse(raw);
      plannerQueueCount = Array.isArray(parsed) ? parsed.length : 0;
      updatePlannerBadge();
      renderQueueBadge(parsed);
      updateHelperProgress();
      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      plannerQueueCount = 0;
      updatePlannerBadge();
      renderQueueBadge([]);
      updateHelperProgress();
      return [];
    }
  };

  var writePlannerQueue = function(queue) {
    if (typeof window === 'undefined' || typeof window.sessionStorage === 'undefined') {
      return;
    }

    try {
      var normalized = Array.isArray(queue) ? queue : [];
      window.sessionStorage.setItem(PLANNER_QUEUE_KEY, JSON.stringify(normalized));
      plannerQueueCount = normalized.length;
      updatePlannerBadge();
      renderQueueBadge(normalized);
      updateHelperProgress();
      if (plannerQueueCount > 0) {
        setPlannerIndicatorsVisible(true);
      } else if (!plannerIndicatorState) {
        setPlannerIndicatorsVisible(false);
      }
      try {
        window.dispatchEvent(new CustomEvent('sbdp:plannerQueueChanged', { detail: plannerQueueCount }));
      } catch (customError) {
        if (typeof document !== 'undefined' && document.createEvent) {
          var legacyEvent = document.createEvent('CustomEvent');
          legacyEvent.initCustomEvent('sbdp:plannerQueueChanged', true, true, plannerQueueCount);
          window.dispatchEvent(legacyEvent);
        }
      }
    } catch (error) {
      // ignore storage errors
    }
  };

  var clearPlannerQueue = function() {
    if (typeof window === 'undefined' || typeof window.sessionStorage === 'undefined') {
      return;
    }

    try {
      window.sessionStorage.removeItem(PLANNER_QUEUE_KEY);
      plannerQueueCount = 0;
      updatePlannerBadge();
      renderQueueBadge([]);
      updateHelperProgress();
      setPlannerIndicatorsVisible(false);
    } catch (error) {
      // ignore storage errors
    }
  };

  var clearPlannerDraft = function() {
    if (typeof window === 'undefined') {
      return;
    }

    try {
      if (window.sessionStorage && typeof window.sessionStorage.removeItem === 'function') {
        window.sessionStorage.removeItem(PLANNER_QUEUE_KEY);
      }
    } catch (error) {
      // ignore storage errors
    }

    try {
      if (window.localStorage && typeof window.localStorage.removeItem === 'function') {
        window.localStorage.removeItem('sbdpPlannerDraftV1');
      }
    } catch (error) {
      // ignore storage errors
    }

    try {
      var plannerDomain = getPlannerDomain();
      if (plannerDomain && plannerDomain.store && typeof plannerDomain.store.clearDraft === 'function') {
        plannerDomain.store.clearDraft();
      }
    } catch (error) {
      // ignore domain bridge errors
    }

    plannerQueueCount = 0;
    updatePlannerBadge();
    renderQueueBadge([]);
    updateHelperProgress();
    setPlannerIndicatorsVisible(false);
  };

  var enqueuePlannerPrefill = function(entry) {
    if (!entry || typeof entry !== 'object') {
      return;
    }

    var queue = readPlannerQueue();
    queue.push(entry);
    writePlannerQueue(queue);
    if (typeof window !== 'undefined' && typeof window.dispatchEvent === 'function') {
      try {
        window.dispatchEvent(new CustomEvent('sbdp:planner/prefill', { detail: entry }));
      } catch (error) {
        if (typeof document !== 'undefined' && document.createEvent) {
          var legacyEvent = document.createEvent('CustomEvent');
          legacyEvent.initCustomEvent('sbdp:planner/prefill', true, true, entry);
          window.dispatchEvent(legacyEvent);
        }
      }
    }
  };

  function roundCurrency(value) {
    var number = typeof value === "number" ? value : parseFloat(value);
    if (!Number.isFinite(number)) {
      return 0;
    }
    return Math.round((number + Number.EPSILON) * 100) / 100;
  }

  function dispatchCombiChange(select) {
    if (!select || typeof select.dispatchEvent !== 'function') {
      return;
    }
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function buildTraceId(prefix) {
    var safePrefix = typeof prefix === 'string' && prefix.trim() ? prefix.trim() : 'sbdp';
    var timestamp = Date.now().toString(36);
    var entropy = Math.random().toString(36).slice(2, 10);
    return safePrefix + '-' + timestamp + '-' + entropy;
  }

  function buildCombiPayload(selections) {
    if (!Array.isArray(selections) || selections.length === 0) {
      return [];
    }
    return selections
      .map(function (selection, index) {
        var timing = selection.timing === "after" ? "after" : "before";
        var duration =
          typeof selection.duration === "number" && selection.duration > 0
            ? selection.duration
            : 0;
        return {
          id: selection.value,
          label: selection.label || selection.value,
          timing: timing,
          role: timing === "after" ? "post" : "pre",
          order: index,
          duration: duration,
          durationMinutes: duration,
          adjustment:
            typeof selection.adjustment === "number"
              ? selection.adjustment
              : parseFloat(selection.adjustment) || 0,
        };
      })
      .filter(function (entry) {
        return entry && entry.id;
      });
  }

  function sanitizePlannerCombiItems(items) {
    if (!Array.isArray(items) || items.length === 0) {
      return [];
    }

    return items
      .map(function (item, index) {
        if (!item || typeof item !== "object") {
          return null;
        }

        var id = item.id || item.product_id || item.productId || null;
        if (!id) {
          return null;
        }

        var timing = item.timing === "after" || item.role === "post" ? "after" : "before";
        var duration =
          typeof item.durationMinutes === "number" && item.durationMinutes > 0
            ? item.durationMinutes
            : typeof item.duration === "number" && item.duration > 0
            ? item.duration
            : 0;

        return {
          id: String(id),
          label: typeof item.label === "string" ? item.label : "",
          timing: timing,
          role: timing === "after" ? "post" : "pre",
          order:
            Number.isFinite(item.order) && item.order >= 0 ? item.order : index,
          duration: duration,
          durationMinutes: duration,
        };
      })
      .filter(function (item) {
        return !!item;
      })
      .sort(function (left, right) {
        return (left.order || 0) - (right.order || 0);
      });
  }

  function buildPlannerPrefillPayload(entry) {
    if (!entry || typeof entry !== "object") {
      return null;
    }

    var traceId =
      (typeof entry.traceId === "string" && entry.traceId.trim() !== "" ? entry.traceId.trim() : "") ||
      (typeof entry.trace_id === "string" && entry.trace_id.trim() !== "" ? entry.trace_id.trim() : "") ||
      buildTraceId("product");

    var prefill = {
      source: typeof entry.source === "string" && entry.source ? entry.source : "product",
      lock_first_slot: entry.lockFirstSlot !== false,
      trace_id: traceId,
      traceId: traceId,
    };

    var productId = entry.product_id || entry.productId || entry.id || null;
    if (productId) {
      prefill.product_id = parseInt(productId, 10) || productId;
    }

    if (typeof entry.date === "string" && entry.date) {
      prefill.date = entry.date;
    }

    if (typeof entry.time === "string" && entry.time) {
      prefill.time = entry.time;
    }

    var participants = parseInt(entry.people ?? entry.participants ?? 0, 10);
    if (Number.isFinite(participants) && participants > 0) {
      prefill.people = participants;
      prefill.participants = participants;
    }

    var resourceId = entry.resource_id || entry.resourceId || null;
    if (resourceId) {
      prefill.resource_id = parseInt(resourceId, 10) || resourceId;
    }

    var combiItems = sanitizePlannerCombiItems(
      entry.combiItems ||
        (entry.options && Array.isArray(entry.options.combiItems)
          ? entry.options.combiItems
          : [])
    );
    if (combiItems.length) {
      prefill.combi_items = combiItems;
      prefill.combi_ids = combiItems.map(function (item) {
        return item.id;
      });
      prefill.combi_timing_map = combiItems.reduce(function (acc, item) {
        acc[item.id] = item.timing;
        return acc;
      }, {});
      if (combiItems.length === 1) {
        prefill.combi = combiItems[0].id;
        if (combiItems[0].label) {
          prefill.combi_label = combiItems[0].label;
        }
      }
    }

    return prefill;
  }

  function buildPricingPayload(participants, combiItems) {
    var safeParticipants =
      Number.isFinite(participants) && participants > 0 ? participants : 1;
    var fallbackTotal =
      typeof pricingState.total === "number" && pricingState.total >= 0
        ? pricingState.total
        : typeof pricingState.displayTotal === "number"
        ? pricingState.displayTotal
        : null;
    if (fallbackTotal === null) {
      fallbackTotal = computeFallbackTotal(safeParticipants, 0);
    }
    var unitPrice =
      typeof pricingState.unit === "number" && pricingState.unit > 0
        ? pricingState.unit
        : typeof pricingState.displayUnit === "number"
        ? pricingState.displayUnit
        : null;
    if (!unitPrice || unitPrice <= 0) {
      unitPrice = safeParticipants > 0 ? fallbackTotal / safeParticipants : 0;
    }
    unitPrice = roundCurrency(unitPrice);
    var payload = {
      currency: currency || "EUR",
      total: roundCurrency(fallbackTotal),
      unitPrice: unitPrice,
      per_person: unitPrice,
      dynamic: { total: roundCurrency(fallbackTotal) },
      adjustments:
        pricingState.raw && Array.isArray(pricingState.raw.adjustments)
          ? pricingState.raw.adjustments
          : [],
      discounts:
        pricingState.raw && Array.isArray(pricingState.raw.discounts)
          ? pricingState.raw.discounts
          : [],
      taxes:
        pricingState.raw && Array.isArray(pricingState.raw.taxes)
          ? pricingState.raw.taxes
          : [],
      combi_multi: Array.isArray(combiItems) ? combiItems : [],
    };
    return payload;
  }

  function getPlannerDomain() {
    if (typeof window === "undefined") {
      return null;
    }
    return window.SBDPPlannerDomain || null;
  }

  function normalizePlannerInput(entry) {
    if (!entry || typeof entry !== "object") {
      return null;
    }

    var raw = {
      productId: entry.product_id ?? entry.productId ?? entry.id ?? null,
      participants: entry.participants ?? entry.people ?? null,
      people: entry.people ?? entry.participants ?? null,
      date: entry.date ?? entry.start_date ?? null,
      time:
        entry.time ??
        entry.start_time ??
        (entry.timeslot && entry.timeslot.start) ??
        null,
      resourceId: entry.resource_id ?? entry.resourceId ?? null,
      options: entry.options ?? (entry.combiItems ? { combiItems: entry.combiItems } : {}),
      source: entry.source ?? "planner",
    };

    var plannerDomain = getPlannerDomain();
    if (plannerDomain && typeof plannerDomain.normalizeInput === "function") {
      return plannerDomain.normalizeInput(raw);
    }

    return raw;
  }

  function handleEvaluateError(error) {
    if (!error) {
      return;
    }
    var message = typeof error === "string" ? error : error.message || "";
    if (!message) {
      return;
    }
    if (message.toLowerCase().includes("te veel planner")) {
      showFeedback(message, "warning");
    }
  }

  async function evaluatePlannerEntry(entry) {
    var normalizedInput = normalizePlannerInput(entry);
    entry.plannerInput = normalizedInput;
    return entry;
  }

  function enqueuePlannerEntry(entry, options) {
    if (!entry || typeof entry !== "object") {
      return entry;
    }

    var prefillEntry = buildPlannerPrefillPayload(entry);
    if (!prefillEntry) {
      return entry;
    }

    entry.plannerInput = normalizePlannerInput(entry);

    var persistQueue = true;
    if (options && typeof options === 'object' && options.persistQueue === false) {
      persistQueue = false;
    }

    if (persistQueue) {
      enqueuePlannerPrefill(prefillEntry);
    }

    return prefillEntry;
  };

  var setPlannerIndicatorsVisible = function(visible) {
    if (plannerStatusNode) {
      if (visible) {
        plannerStatusNode.removeAttribute('hidden');
        plannerStatusNode.removeAttribute('aria-hidden');
      } else {
        plannerStatusNode.textContent = '';
        plannerStatusNode.setAttribute('hidden', 'hidden');
        plannerStatusNode.setAttribute('aria-hidden', 'true');
      }
    }

    if (plannerBadge) {
      if (visible) {
        plannerBadge.removeAttribute('hidden');
        plannerBadge.removeAttribute('aria-hidden');
      } else {
        plannerBadge.textContent = '';
        plannerBadge.removeAttribute('data-has-items');
        plannerBadge.setAttribute('hidden', 'hidden');
        plannerBadge.setAttribute('aria-hidden', 'true');
      }
    }

    if (!visible) {
      plannerIndicatorState = null;
    }
  };

  var updatePlannerBadge = function(state) {
    if (!plannerBadge) {
      return;
    }

    var effectiveState = typeof state === 'string' ? state : plannerIndicatorState;

    plannerBadge.classList.remove('is-success', 'is-error', 'is-info', 'is-pending');
    if (effectiveState) {
      plannerBadge.classList.add('is-' + effectiveState);
    }

    var badgeLabel = '';
    if (plannerQueueCount > 0) {
      var template = messageLookup('planner_queue_count', '%s activiteiten klaar voor Plan je dag');
      badgeLabel = template.replace('%s', plannerQueueCount);
      plannerBadge.setAttribute('data-has-items', 'true');
    } else {
      plannerBadge.removeAttribute('data-has-items');

      if (effectiveState === 'pending') {
        badgeLabel = messageLookup('planner_pending_short', 'Bezig...');
      } else if (effectiveState === 'error') {
        badgeLabel = messageLookup('planner_error_short', 'Let op');
      } else if (effectiveState === 'success') {
        badgeLabel = messageLookup('planner_success_short', 'Gereed');
      } else if (effectiveState === 'info') {
        badgeLabel = messageLookup('planner_info_short', 'Info');
      }
    }

    if (badgeLabel) {
      plannerBadge.textContent = badgeLabel;
      plannerBadge.removeAttribute('hidden');
      plannerBadge.removeAttribute('aria-hidden');
    } else {
      plannerBadge.textContent = '';
      plannerBadge.setAttribute('hidden', 'hidden');
      plannerBadge.setAttribute('aria-hidden', 'true');
    }
  };

  var updatePlannerStatus = function(state, message) {
    if (!plannerCard) {
      if (plannerStatusNode && typeof message === 'string' && message !== '') {
        plannerStatusNode.textContent = message;
      }
      return;
    }

    plannerCard.classList.remove('is-success', 'is-error', 'is-info', 'is-pending');
    if (state) {
      plannerCard.classList.add('is-' + state);
    }

    var hasMessage = typeof message === 'string' && message !== '';
    if (plannerStatusNode) {
      if (hasMessage) {
        plannerStatusNode.textContent = message;
      } else if (!state) {
        plannerStatusNode.textContent = '';
      }
    }

    if (!state && !hasMessage && plannerQueueCount === 0) {
      setPlannerIndicatorsVisible(false);
      updatePlannerBadge(null);
      return;
    }

    plannerIndicatorState = state || plannerIndicatorState;
    setPlannerIndicatorsVisible(true);
    updatePlannerBadge(state);
    updateHelperProgress();
  };

  var formatPlannerReadyMessage = function(count) {
    if (count === 1) {
      return messageLookup('planner_ready_single', '1 activiteit staat klaar voor Plan je dag.');
    }

    var template = messageLookup('planner_ready_multi', '%s activiteiten staan klaar voor Plan je dag.');
    return template.replace('%s', count);
  };

  var hydratePlannerStatus = function() {
    if (!plannerStatusNode) {
      return;
    }

    if (plannerCard && plannerCard.classList.contains('is-pending')) {
      return;
    }

    var queue = readPlannerQueue();
    if (Array.isArray(queue) && queue.length > 0) {
      plannerQueueCount = queue.length;
      setPlannerIndicatorsVisible(true);
      updatePlannerStatus('info', formatPlannerReadyMessage(queue.length));
      return;
    }

    plannerQueueCount = 0;
    setPlannerIndicatorsVisible(false);
    updatePlannerBadge(null);
  };

  var handleScrollTarget = function(event) {
    if (event && typeof event.preventDefault === 'function') {
      event.preventDefault();
    }

    if (!event || !event.currentTarget) {
      return;
    }

    var selector = event.currentTarget.getAttribute('data-sbdp-scroll-target');
    if (!selector) {
      return;
    }

    var target = document.querySelector(selector);
    if (!target) {
      return;
    }

    var offset = target.getBoundingClientRect().top + window.pageYOffset - 80;
    if (typeof window.scrollTo === 'function') {
      window.scrollTo({
        top: offset < 0 ? 0 : offset,
        behavior: 'smooth'
      });
    } else {
      window.scrollTop = offset < 0 ? 0 : offset;
    }
  };

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

  var formatLocalDateISO = function(dateObj) {
    if (!(dateObj instanceof Date) || isNaN(dateObj.getTime())) {
      return '';
    }
    return dateObj.getFullYear() + '-' + pad(dateObj.getMonth() + 1) + '-' + pad(dateObj.getDate());
  };

  var shiftDate = function(dateStr, offsetDays) {
    var base = new Date(dateStr + 'T00:00:00');
    if (isNaN(base.getTime())) {
      base = new Date();
    }
    base.setUTCDate(base.getUTCDate() + offsetDays);
    return formatLocalDateISO(base);
  };

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

  var findClosestSlot = function(targetValue, slots) {
    if (!targetValue || !Array.isArray(slots) || slots.length === 0) {
      return '';
    }
    var targetMinutes = timeToMinutes(targetValue);
    if (targetMinutes === null) {
      return '';
    }
    var closest = '';
    var smallestDelta = Number.POSITIVE_INFINITY;
    for (var i = 0; i < slots.length; i += 1) {
      var slot = slots[i];
      if (!slot || !slot.value || getSlotPresentation(slot).disabled) {
        continue;
      }
      var slotMinutes = timeToMinutes(slot.value);
      if (slotMinutes === null) {
        continue;
      }
      var delta = Math.abs(slotMinutes - targetMinutes);
      if (delta < smallestDelta) {
        smallestDelta = delta;
        closest = slot.value;
      }
    }
    return closest;
  };

  var chooseNextAvailableSlot = function(dateStr, slots) {
    if (!Array.isArray(slots) || slots.length === 0) {
      return '';
    }
    var enabledSlots = slots.filter(function(slot) {
      return slot && slot.value && !getSlotPresentation(slot).disabled;
    });
    if (enabledSlots.length === 0) {
      return '';
    }
    var normalizedDate = normalizeDate(dateStr);
    var todayCandidate = new Date();
    var todayStr = formatLocalDateISO(todayCandidate);
    if (!normalizedDate || normalizedDate !== todayStr) {
      return enabledSlots[0].value;
    }
    var nowMinutes = todayCandidate.getHours() * 60 + todayCandidate.getMinutes();
    for (var i = 0; i < enabledSlots.length; i += 1) {
      var slot = enabledSlots[i];
      var slotMinutes = timeToMinutes(slot && slot.value);
      if (slotMinutes !== null && slotMinutes > nowMinutes) {
        return slot.value;
      }
    }
    return enabledSlots[enabledSlots.length - 1].value;
  };

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
    initialCalendarDate = formatLocalDateISO(new Date());
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
    if (availabilitySlotsUrl) {
      return null;
    }
    if (!availability || typeof availability.capacity === 'undefined') {
      return null;
    }
    var capacity = parseInt(availability.capacity, 10);
    if (isNaN(capacity) || capacity <= 0) {
      return null;
    }
    return capacity;
  };

  var parseTimeToMinutes = function(value) {
    if (!value || typeof value !== 'string') {
      return null;
    }
    var parts = value.split(':');
    if (parts.length < 2) {
      return null;
    }
    var hours = parseInt(parts[0], 10);
    var minutes = parseInt(parts[1], 10);
    if (isNaN(hours) || isNaN(minutes)) {
      return null;
    }
    return hours * 60 + minutes;
  };

  var minutesToTime = function(minutes) {
    if (isNaN(minutes)) {
      return '';
    }
    var safe = Math.max(0, Math.min(23 * 60 + 59, Math.round(minutes)));
    var hours = String(Math.floor(safe / 60)).padStart(2, '0');
    var mins = String(safe % 60).padStart(2, '0');
    return hours + ':' + mins;
  };

  var resolveSlotMinutes = function(slots) {
    if (!Array.isArray(slots) || slots.length === 0) {
      return 30;
    }
    var start = parseTimeToMinutes(slots[0].start);
    var end = parseTimeToMinutes(slots[0].end);
    if (isNaN(start) || isNaN(end) || end <= start) {
      return 30;
    }
    return end - start;
  };

  var filterSlotsByDuration = function(slots, duration) {
    if (!Array.isArray(slots) || slots.length === 0) {
      return [];
    }
    var slotMinutes = resolveSlotMinutes(slots);
    var required = Math.max(1, Math.ceil(duration / slotMinutes));
    var sorted = slots.map(function(slot) {
      return {
        start: slot.start,
        end: slot.end,
        startMinutes: parseTimeToMinutes(slot.start)
      };
    }).filter(function(slot) {
      return !isNaN(slot.startMinutes);
    }).sort(function(a, b) {
      return a.startMinutes - b.startMinutes;
    });

    var startSet = {};
    sorted.forEach(function(slot) {
      startSet[slot.startMinutes] = true;
    });

    var results = [];
    sorted.forEach(function(slot) {
      var ok = true;
      for (var step = 0; step < required; step += 1) {
        if (!startSet[slot.startMinutes + step * slotMinutes]) {
          ok = false;
          break;
        }
      }
      if (ok) {
        results.push({
          start: slot.start,
          end: minutesToTime(slot.startMinutes + duration)
        });
      }
    });

    return results;
  };

  var generateTimeSlots = function(dateStr, availability) {
    var slots = [];
    if (!dateStr) {
      return slots;
    }
    if (availability && Array.isArray(availability.slots) && availability.slots.length > 0) {
      var duration = parseInt(durationMinutes, 10);
      if (isNaN(duration) || duration <= 0) {
        duration = 90;
      }
      var filteredSlots = filterSlotsByDuration(availability.slots, duration);
      filteredSlots.forEach(function(slot) {
        slots.push({
          value: slot.start,
          label: slot.end ? slot.start + ' - ' + slot.end : slot.start,
          start: slot.start,
          end: slot.end,
          source: availability,
          remaining_capacity: slot.remaining_capacity,
          available_capacity: slot.available_capacity,
          capacity_status: slot.capacity_status
        });
      });
      return slots;
    }

    if (availabilitySlotsUrl) {
      return slots;
    }

    var blocks = availability && availability.blocks ? availability.blocks : [];
    var duration = parseInt(durationMinutes, 10);
    if (isNaN(duration) || duration <= 0) {
      duration = 90;
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
          end: slotEnd.toISOString(),
          source: availability
        });
      }
    }

    return slots;
  };

  var parseSlotCapacityValue = function(slot) {
    if (!slot || typeof slot !== 'object') {
      return null;
    }

    var keys = ['remaining_capacity', 'available_capacity', 'remainingCapacity', 'availableCapacity'];
    for (var i = 0; i < keys.length; i += 1) {
      if (typeof slot[keys[i]] === 'undefined' || slot[keys[i]] === null || slot[keys[i]] === '') {
        continue;
      }
      var parsed = parseInt(slot[keys[i]], 10);
      if (!isNaN(parsed)) {
        return parsed;
      }
    }

    return null;
  };

  var parseSlotCapacityStatus = function(slot) {
    if (!slot || typeof slot !== 'object') {
      return '';
    }

    var keys = ['capacity_status', 'capacityStatus', 'availability_status', 'availabilityStatus'];
    for (var i = 0; i < keys.length; i += 1) {
      if (typeof slot[keys[i]] === 'string' && slot[keys[i]].trim() !== '') {
        return slot[keys[i]].trim().toLowerCase();
      }
    }

    return '';
  };

  var getSlotPresentation = function(slot) {
    var participants = getParticipants();
    if (!Number.isFinite(participants) || participants <= 0) {
      participants = 1;
    }

    var remaining = parseSlotCapacityValue(slot);
    var status = parseSlotCapacityStatus(slot);
    var reliable = remaining !== null || status !== '';
    var presentation = {
      reliable: reliable,
      disabled: false,
      label: '',
      tone: 'available'
    };

    if (!reliable) {
      return presentation;
    }

    if (status === 'full' || status === 'unavailable' || status === 'sold_out' || remaining === 0) {
      presentation.disabled = true;
      presentation.label = 'Vol';
      presentation.tone = 'unavailable';
      return presentation;
    }

    if (remaining !== null && remaining < participants) {
      presentation.disabled = true;
      presentation.label = 'Niet genoeg plek';
      presentation.tone = 'unavailable';
      return presentation;
    }

    if (status === 'limited' || (remaining !== null && remaining > 0 && remaining <= 5)) {
      presentation.label = remaining !== null ? 'Nog ' + remaining + ' plekken' : '';
      presentation.tone = 'limited';
      return presentation;
    }

    return presentation;
  };

  var renderTimeChips = function(slots, selectedValue) {
    if (!timeChipGroup) {
      return;
    }

    while (timeChipGroup.firstChild) {
      timeChipGroup.removeChild(timeChipGroup.firstChild);
    }

    if (!Array.isArray(slots) || slots.length === 0) {
      return;
    }

    slots.forEach(function(slot) {
      if (!slot || typeof slot.value === 'undefined') {
        return;
      }

      var presentation = getSlotPresentation(slot);
      var button = document.createElement('button');
      var isSelected = !!selectedValue && slot.value === selectedValue && !presentation.disabled;
      button.type = 'button';
      button.className = 'ddb-slot ui-chip ui-chip--muted ddb-slot--' + presentation.tone + (isSelected ? ' is-active ddb-slot--selected' : '');
      button.setAttribute('data-ddb-time', slot.value);
      button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');

      if (presentation.disabled) {
        button.disabled = true;
        button.classList.add('is-disabled');
        button.setAttribute('aria-disabled', 'true');
      }

      var timeNode = document.createElement('span');
      timeNode.className = 'ddb-slot__time sbdp-chip-time';
      timeNode.textContent = slot.value;
      button.appendChild(timeNode);

      if (presentation.reliable && presentation.label) {
        var capacityNode = document.createElement('span');
        capacityNode.className = 'ddb-slot__capacity sbdp-chip-availability';
        capacityNode.textContent = presentation.label;
        button.appendChild(capacityNode);
      }

      timeChipGroup.appendChild(button);
    });
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
      button.textContent = String(dayNumber);

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
    renderTimeChips(slots, selectedValue);
    if (!timeslotList) {
      return;
    }

    var doc = timeslotList.ownerDocument || document;
    while (timeslotList.firstChild) {
      timeslotList.removeChild(timeslotList.firstChild);
    }

    var placeholder = doc.createElement('option');
    placeholder.value = '';
    placeholder.textContent = messageLookup('select_time', 'Selecteer een starttijd');
    timeslotList.appendChild(placeholder);

    if (!Array.isArray(slots) || slots.length === 0) {
      if (timePicker) {
        timePicker.classList.add('sbdp-time-picker--empty');
      }
      if (timeslotEmpty) {
        timeslotEmpty.removeAttribute('hidden');
        timeslotEmpty.textContent = messageLookup('no_slots', 'Geen tijdsloten beschikbaar voor deze datum.');
      }
      timeslotList.value = '';
      timeslotList.disabled = true;
      return;
    }

    if (timePicker) {
      timePicker.classList.remove('sbdp-time-picker--empty');
    }
    if (timeslotEmpty) {
      timeslotEmpty.textContent = '';
      timeslotEmpty.setAttribute('hidden', 'hidden');
    }

    var normalizedSelected = normalizeTime(selectedValue);
    var hasSelected = false;
    for (var i = 0; i < slots.length; i += 1) {
      var slot = slots[i];
      if (!slot || typeof slot.value === 'undefined') {
        continue;
      }
      var presentation = getSlotPresentation(slot);
      var option = doc.createElement('option');
      option.value = slot.value;
      option.textContent = (slot.label || slot.value) + (presentation.reliable && presentation.label ? ' · ' + presentation.label : '');
      if (presentation.disabled) {
        option.disabled = true;
      }
      if (normalizedSelected && slot.value === normalizedSelected && !presentation.disabled) {
        option.selected = true;
        hasSelected = true;
      }
      timeslotList.appendChild(option);
    }

    timeslotList.disabled = false;

    if (normalizedSelected && !hasSelected) {
      timeslotList.value = normalizedSelected;
    } else if (!hasSelected && timeslotList.options.length > 1) {
      for (var optionIndex = 0; optionIndex < timeslotList.options.length; optionIndex += 1) {
        var candidateOption = timeslotList.options[optionIndex];
        if (!candidateOption.disabled && candidateOption.value !== '') {
          timeslotList.selectedIndex = optionIndex;
          break;
        }
      }
    }
  };

  var selectTimeSlot = function(value, triggerChange) {
    var normalized = normalizeTime(value);
    if (timeInput) {
      timeInput.value = normalized || '';
    }

    if (timeslotList) {
      timeslotList.value = normalized || '';
    }

    if (triggerChange !== false && timeInput) {
      var changeEvent = new Event('change', { bubbles: true });
      timeInput.dispatchEvent(changeEvent);
    }

    if (normalized) {
      persistSelection({ time: normalized });
    }
    updateHelperProgress();
  };

  var setSelectOptions = function(select, options, placeholder, selectedValue) {
    if (!select || !isSelectElement(select) || !select.options) {
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

  var setNumberConstraints = function(input, minValue, maxValue, selectedValue) {
    if (!input || isSelectElement(input)) {
      return;
    }
    var min = Math.max(1, parseInt(minValue, 10) || 1);
    input.min = String(min);
    input.step = '1';
    if (typeof maxValue === 'number' && maxValue > 0) {
      input.max = String(maxValue);
    } else {
      input.removeAttribute('max');
    }
    var current = selectedValue != null ? parseInt(selectedValue, 10) : parseInt(input.value, 10);
    if (isNaN(current) || current < min) {
      current = min;
    }
    if (typeof maxValue === 'number' && maxValue > 0 && current > maxValue) {
      current = maxValue;
    }
    input.value = String(current);
  };

  var updateTimeOptions = function(slots, preserveSelection) {
    availableTimeOptions = Array.isArray(slots) ? slots.slice() : [];
    var normalizedPrevious = preserveSelection && timeInput ? normalizeTime(timeInput.value) : '';
    var selectedValue = '';
    var storedTime = normalizeTime(persistedSelection.time);
    var fallbackFromPrevious = '';

    if (normalizedPrevious) {
      var hasPrevious = availableTimeOptions.some(function(option) {
        return option && option.value === normalizedPrevious;
      });
      if (hasPrevious) {
        selectedValue = normalizedPrevious;
      }
    }

    if (!selectedValue && storedTime) {
      var hasStored = availableTimeOptions.some(function(option) {
        return option && option.value === storedTime;
      });
      if (hasStored) {
        selectedValue = storedTime;
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

    if (!selectedValue && normalizedPrevious) {
      fallbackFromPrevious = findClosestSlot(normalizedPrevious, availableTimeOptions);
      selectedValue = fallbackFromPrevious || '';
    }

    if (!selectedValue && availableTimeOptions.length > 0) {
      selectedValue = chooseNextAvailableSlot(calendarState.selectedDate || (dateInput && dateInput.value), availableTimeOptions);
    }

    renderTimeSlots(availableTimeOptions, selectedValue);

    if (availableTimeOptions.length === 0) {
      if (timeInput) {
        timeInput.setAttribute('data-empty', '1');
      }
      var preservedTime = normalizeTime(timeInput && timeInput.value) || normalizeTime(persistedSelection.time) || normalizeTime(defaultTime) || '';
      if (preservedTime) {
        selectTimeSlot(preservedTime, false);
      }
    } else {
      if (timeInput) {
        timeInput.removeAttribute('data-empty');
      }
      selectTimeSlot(selectedValue, false);

      if (selectedValue) {
        persistSelection({ time: selectedValue });
      }

      if (fallbackFromPrevious) {
        var fallbackLabel = availableTimeOptions.reduce(function(acc, option) {
          if (option && option.value === fallbackFromPrevious) {
            acc = option.label || option.value;
          }
          return acc;
        }, fallbackFromPrevious);
        showFeedback(messageLookup('slot_changed', 'Je gekozen tijdstip is niet meer beschikbaar. We hebben ') + (fallbackLabel || fallbackFromPrevious) + ' voorgesteld.', 'info');
      }
    }

    updateSummary();
    updateHelperProgress();
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
      if (participantsInput) {
        if (isSelectElement(participantsInput)) {
          setSelectOptions(participantsInput, [], messageLookup('select_participants', selectParticipantsFallback), undefined);
        } else {
          participantsInput.value = '';
        }
        participantsInput.disabled = true;
      }
      return;
    }

    var ignoreCapacity = !rawConfig && bookingForm && bookingForm.matches && bookingForm.matches('#sbdp-booking-form');
    if (!ignoreCapacity && resourceCapacity !== null) {
      max = max === null ? resourceCapacity : Math.min(max, resourceCapacity);
    }

    if (!ignoreCapacity && typeof capacity === 'number' && capacity > 0) {
      max = max === null ? capacity : Math.min(max, capacity);
    }

    if (max === null) {
      max = min + 9;
    }

    if (max < min) {
      participantOptionList = [];
      if (participantsInput) {
        if (isSelectElement(participantsInput)) {
          setSelectOptions(participantsInput, [], messageLookup('select_participants', selectParticipantsFallback), undefined);
        } else {
          participantsInput.value = '';
        }
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
    var defaultParticipantsValue = (typeof defaults.participants !== 'undefined' && defaults.participants !== null)
      ? String(defaults.participants)
      : '';
    var persistedParticipants = persistedSelection && persistedSelection.participants ? String(persistedSelection.participants) : '';
    var inputValue = participantsInput && participantsInput.value ? String(participantsInput.value) : '';
    var selected = preserveSelection && participantsInput
      ? participantsInput.value
      : (inputValue || persistedParticipants || defaultParticipantsValue);
    if (selected && !options.some(function(option) {
      return option && String(option.value) === selected;
    })) {
      selected = undefined;
    }

    if (participantsInput) {
      if (isSelectElement(participantsInput)) {
        setSelectOptions(participantsInput, options, messageLookup('select_participants', selectParticipantsFallback), selected);
        if (selected && participantsInput.value !== selected) {
          participantsInput.value = selected;
        }
        participantsInput.disabled = options.length === 0;
      } else {
        setNumberConstraints(participantsInput, min, max, selected);
        participantsInput.disabled = false;
      }

      if (!participantsInput.disabled && participantsInput.value) {
        persistedSelection.participants = participantsInput.value;
        persistSelection({ participants: participantsInput.value });
      }
    }
  };

  var dispatchOptionsUpdated = function(detail) {
    var payload = detail || {};
    payload.date = payload.date || (dateInput ? dateInput.value : '');
    payload.times = availableTimeOptions.slice();
    payload.participants = participantOptionList.slice();
    payload.selectedTime = getResolvedTimeValue();
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
    pricingState.displayTotal = null;
    pricingState.displayUnit = null;
    pricingState.displayScale = 1;
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
    if (participantsInput && participantsInput.value) {
      var parsedParticipants = parseInt(participantsInput.value, 10);
      if (!isNaN(parsedParticipants) && parsedParticipants > 0) {
        participants = parsedParticipants;
      }
    }

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
    var requestController = pricingAbortController;

    var combi = getCombiSelection();
    var combiSelections = getCombiSelections();
    var combiTimingMap = {};
    combiSelections.forEach(function(selection) {
      combiTimingMap[selection.value] = selection.timing || 'before';
    });
    var combiTiming = getCombiTiming();
    var baseFallbackTotal = computeFallbackTotal(participants, 0);
    pricingState.total = baseFallbackTotal;
    pricingState.unit = participants > 0 ? (baseFallbackTotal / participants) : baseFallbackTotal;
    pricingState.formatted = formatCurrency(baseFallbackTotal);
    pricingState.displayTotal = baseFallbackTotal;
    pricingState.displayUnit = pricingState.unit;
    pricingState.displayScale = 1;
    pricingState.loading = true;
    if (typeof updateSummary === 'function') {
      updateSummary();
    }
    dispatchOptionsUpdated({});

    var headers = {
      'Content-Type': 'application/json'
    };

    if (nonce) {
      headers['X-WP-Nonce'] = nonce;
      headers['x-sbdp-nonce'] = nonce;
    }

    return fetch(pricingPreviewUrl, {
      method: 'POST',
      headers: headers,
      credentials: 'same-origin',
      body: JSON.stringify({
        product_id: config.productId,
        resource_id: getResourceId(),
        participants: participants,
        start: range.start,
        combi: combiSelect && combiSelect.value ? combiSelect.value : ''
      }),
      signal: requestController ? requestController.signal : undefined
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
      var displayTotal = result && typeof result.display_total === 'number' ? result.display_total : null;
      var displayUnit = result && typeof result.display_unit_price === 'number' ? result.display_unit_price : null;
      if ((!unit || unit <= 0) && total && participants > 0) {
        unit = total / participants;
      }
      var fallbackDisplayUnit = resolveDisplayUnitPrice();
      var displayScale = 1;
      if (displayTotal === null && unit) {
        displayScale = deriveDisplayScale(unit, fallbackDisplayUnit);
      }
      if (total !== null && total > 0) {
        pricingState.total = total;
        pricingState.unit = unit;
        pricingState.formatted = formatCurrency(displayTotal !== null ? displayTotal : total);
        pricingState.displayScale = displayTotal !== null ? 1 : displayScale;
        pricingState.displayTotal = displayTotal !== null ? displayTotal : (displayScale !== 1 ? total * displayScale : total);
        if (displayUnit !== null && displayUnit > 0) {
          pricingState.displayUnit = displayUnit;
        } else if (unit && unit > 0) {
          pricingState.displayUnit = displayScale !== 1 ? unit * displayScale : unit;
        } else {
          pricingState.displayUnit = fallbackDisplayUnit || null;
        }
        if (pricingState.displayTotal !== null) {
          pricingState.formatted = formatCurrency(pricingState.displayTotal);
        }
      } else {
        pricingState.total = baseFallbackTotal;
        pricingState.unit = participants > 0 ? (baseFallbackTotal / participants) : baseFallbackTotal;
        pricingState.formatted = formatCurrency(baseFallbackTotal);
        pricingState.displayTotal = baseFallbackTotal;
        pricingState.displayUnit = pricingState.unit;
        pricingState.displayScale = 1;
      }
      updateSummary();
      dispatchOptionsUpdated({});
      return result;
    }).catch(function(error) {
      if (isIgnorableFetchError(error, requestController)) {
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
    var endpoint = availabilitySlotsUrl || availabilityUrl;
    if (!endpoint || !config || !config.productId || !normalizedDate) {
      return Promise.resolve(null);
    }
    var resourceId = getResourceId();
    var cacheKey = [normalizedDate, resourceId || 0].join('|');
    if (availabilityCache[cacheKey]) {
      return Promise.resolve(availabilityCache[cacheKey]);
    }
    if (availabilityPending[cacheKey]) {
      return availabilityPending[cacheKey];
    }
    var url = endpoint + '?product_id=' + encodeURIComponent(config.productId) + '&date=' + encodeURIComponent(normalizedDate);
    if (resourceId) {
      url += '&resource_id=' + encodeURIComponent(resourceId);
    }
    var headers = {
      'Accept': 'application/json'
    };
    if (nonce) {
      headers['X-WP-Nonce'] = nonce;
      headers['x-sbdp-nonce'] = nonce;
    }
    var pendingRequest = fetch(url, {
      credentials: 'same-origin',
      headers: headers
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
    }).then(function(result) {
      delete availabilityPending[cacheKey];
      return result;
    });
    availabilityPending[cacheKey] = pendingRequest;
    return pendingRequest;
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
      startingDate = today || formatLocalDateISO(new Date());
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

    return loadAvailability(startingDate, false).then(function() {
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

    persistSelection({ date: normalized });

    renderCalendar();
    if (typeof updateSummary === 'function') {
      updateSummary();
    }
    updateHelperProgress();

    var shouldFetch = !options || options.fetch !== false;
    var preserveTime = !!(options && options.preserveTime);

    if (shouldFetch) {
      return loadAvailability(normalized, preserveTime);
    }

    updateHelperProgress();
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

  var showFeedback = function(text, tone, options) {
    if (!feedback) {
      return;
    }

    var baseClass = 'sbdp-product-booking__feedback';
    var classes = [baseClass];
    if (tone) {
      classes.push(baseClass + '--' + tone);
    }
    feedback.className = classes.join(' ');
    feedback.textContent = '';

    var message = typeof text === 'string' ? text : '';
    if (message) {
      var span = document.createElement('span');
      span.textContent = message;
      feedback.appendChild(span);
    }

    var opts = options || {};
    if (opts.linkHref) {
      var link = document.createElement('a');
      link.href = opts.linkHref;
      link.textContent = opts.linkLabel || messageLookup('view_planner', 'Bekijk planner');
      link.className = 'sbdp-feedback__link';
      if (opts.linkTarget) {
        link.target = opts.linkTarget;
      }
      feedback.appendChild(link);
    }

    var liveMessage = message || (opts && opts.linkLabel) || '';
    if (liveMessage) {
      feedback.setAttribute('aria-label', liveMessage);
    } else {
      feedback.removeAttribute('aria-label');
    }
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

  var formatDateDisplay = function(value) {
    var placeholder = summaryDefaults.date || messageLookup('date_placeholder', 'Nog geen datum gekozen.');
    var normalized = normalizeDate(value);
    if (!normalized) {
      return placeholder;
    }

    try {
      if (typeof Intl !== 'undefined' && typeof Intl.DateTimeFormat === 'function') {
        var date = new Date(normalized + 'T12:00:00');
        if (!isNaN(date.getTime())) {
          return new Intl.DateTimeFormat(locale, {
            weekday: 'short',
            day: 'numeric',
            month: 'long'
          }).format(date);
        }
      }
    } catch (error) {
      // ignore and fall through to normalized value
    }

    return normalized;
  };

  var formatPeople = function(count) {
    var total = parseInt(count, 10);
    if (isNaN(total) || total <= 0) {
      return summaryDefaults.people;
    }

    var label = total === 1 ? (participantsSingular || participantsPlural) : (participantsPlural || participantsSingular);
    if (!label) {
      label = total === 1 ? 'persoon' : 'personen';
    }

    return total + ' ' + label;
  };

  var getSelectedTimeLabel = function() {
    var placeholder = summaryDefaults.time || messageLookup('time_placeholder', 'Nog geen tijd gekozen.');
    if (!timeInput || !timeInput.value) {
      return placeholder;
    }

    var normalized = normalizeTime(timeInput.value);
    if (!normalized) {
      return placeholder;
    }

    for (var i = 0; i < availableTimeOptions.length; i += 1) {
      var option = availableTimeOptions[i];
      if (option && option.value === normalized) {
        return option.label || normalized;
      }
    }

    return normalized;
  };

  var getCombiSelection = function() {
    var selections = getCombiSelections();
    if (selections.length) {
      return selections[0];
    }
    var defaultLabel = summaryDefaults.combiLabel || messageLookup('combi_default', 'Geen combi geselecteerd.');
    return { label: defaultLabel, adjustment: 0, value: '', duration: 0, timing: 'before', supportsPersons: supportsPersons };
  };

  var getCombiTiming = function() {
    var selections = getCombiSelections();
    if (selections.length && selections[0].timing === 'after') {
      return 'after';
    }
    return 'before';
  };

  function normalizeCombiLabel(label) {
    if (!label) {
      return '';
    }
    var text = String(label).replace(/\s+/g, ' ').trim();
    if (!text) {
      return '';
    }
    var dashIndex = text.indexOf(' - ');
    var plusIndex = text.indexOf(' + ');
    var parenIndex = text.indexOf(' (');
    var cutIndex = -1;
    [dashIndex, plusIndex, parenIndex].forEach(function(index) {
      if (index >= 0 && (cutIndex === -1 || index < cutIndex)) {
        cutIndex = index;
      }
    });
    if (cutIndex >= 0) {
      text = text.slice(0, cutIndex);
    }
    return text.trim();
  }

  function getCombiLabelFingerprint(label) {
    return normalizeCombiLabel(label).toLocaleLowerCase();
  }

  function getPreferredCombiTiming(label) {
    var fingerprint = getCombiLabelFingerprint(label);
    if (!fingerprint) {
      return '';
    }
    if (fingerprint.indexOf('bossche bol') !== -1) {
      return 'before';
    }
    if (fingerprint.indexOf('3 gangen diner') !== -1 || fingerprint.indexOf('drie gangen diner') !== -1) {
      return 'after';
    }
    return '';
  }

  function getCombiSummarySortWeight(label, timing) {
    var fingerprint = getCombiLabelFingerprint(label);
    if (timing === 'before') {
      return fingerprint.indexOf('bossche bol') !== -1 ? -100 : 0;
    }
    if (timing === 'after') {
      return (fingerprint.indexOf('3 gangen diner') !== -1 || fingerprint.indexOf('drie gangen diner') !== -1) ? 100 : 0;
    }
    return 0;
  }

  function normalizeSummaryCombiSelections(selections) {
    return (Array.isArray(selections) ? selections : []).map(function(selection) {
      if (!selection) {
        return selection;
      }
      var preferredTiming = getPreferredCombiTiming(selection.label || '');
      if (!preferredTiming) {
        return selection;
      }
      return Object.assign({}, selection, { timing: preferredTiming });
    }).sort(function(left, right) {
      var leftTiming = left && left.timing === 'after' ? 'after' : 'before';
      var rightTiming = right && right.timing === 'after' ? 'after' : 'before';
      if (leftTiming !== rightTiming) {
        return leftTiming === 'before' ? -1 : 1;
      }
      return getCombiSummarySortWeight(left && left.label, leftTiming) - getCombiSummarySortWeight(right && right.label, rightTiming);
    });
  }

  function formatSummaryQuantity(quantity) {
    var parsed = parseInt(quantity, 10);
    if (!Number.isFinite(parsed) || parsed <= 0) {
      parsed = 1;
    }
    return parsed + ' x';
  }

  function buildSummaryPriceLine(quantity, label, unitAmount, totalAmount, timing) {
    var line = document.createElement('div');
    line.className = 'sbdp-summary-breakdown-line';
    line.setAttribute('data-summary-phase', timing || 'main');

    var labelWrap = document.createElement('span');
    labelWrap.className = 'sbdp-summary-breakdown-label';

    if (timing === 'before' || timing === 'after') {
      var phase = document.createElement('span');
      phase.className = 'sbdp-summary-breakdown-phase';
      phase.textContent = timing === 'before' ? 'Vooraf' : 'Achteraf';
      labelWrap.appendChild(phase);
    }

    var text = document.createElement('span');
    text.className = 'sbdp-summary-breakdown-text';
    text.textContent = formatSummaryQuantity(quantity) + ' ' + label;
    labelWrap.appendChild(text);

    var priceWrap = document.createElement('span');
    priceWrap.className = 'sbdp-summary-breakdown-price';
    priceWrap.textContent = formatCurrency(unitAmount) + ' = ' + formatCurrency(totalAmount);

    line.appendChild(labelWrap);
    line.appendChild(priceWrap);
    return line;
  }

  var resolveFallbackUnitPrice = function() {
    if (perPersonPrice > 0) {
      return perPersonPrice;
    }
    if (basePrice > 0) {
      return basePrice;
    }
    return 0;
  };

  var resolveDisplayUnitPrice = function() {
    var fallback = resolveFallbackUnitPrice();
    if (fallback > 0) {
      return fallback;
    }
    return basePrice > 0 ? basePrice : 0;
  };

  var deriveDisplayScale = function(unit, displayUnit) {
    if (!unit || !displayUnit) {
      return 1;
    }
    var scale = displayUnit / unit;
    if (!isFinite(scale) || scale <= 0) {
      return 1;
    }
    if (Math.abs(scale - 1) < 0.01) {
      return 1;
    }
    if (scale < 0.5 || scale > 2.5) {
      return 1;
    }
    return scale;
  };

  var computeFallbackTotal = function(participants, adjustment) {
    var total = fixedFee > 0 ? fixedFee : 0;
    var unit = resolveFallbackUnitPrice();
    var parsedParticipants = parseInt(participants, 10);
    if (!Number.isFinite(parsedParticipants) || parsedParticipants <= 0) {
      parsedParticipants = 1;
    }

    if (perPersonPrice > 0 || supportsPersons) {
      total += unit * parsedParticipants;
    } else {
      total += unit;
    }

    var extra = parseFloat(adjustment);
    if (!isNaN(extra) && extra !== 0) {
      total += (perPersonPrice > 0 || supportsPersons) ? (extra * parsedParticipants) : extra;
    }

    if (total < 0) {
      total = 0;
    }

    return total;
  };

  var computeCombiTotal = function(participants, adjustment, combiSupportsPersons) {
    var parsedParticipants = parseInt(participants, 10);
    if (!Number.isFinite(parsedParticipants) || parsedParticipants <= 0) {
      parsedParticipants = 1;
    }
    var extra = parseFloat(adjustment);
    if (isNaN(extra) || extra === 0) {
      return 0;
    }
    return resolveCombiSupportsPersons(combiSupportsPersons) ? (extra * parsedParticipants) : extra;
  };

  var formatTime = function(date) {
    if (!date || isNaN(date.getTime())) {
      return '';
    }
    var hours = String(date.getHours()).padStart(2, '0');
    var minutes = String(date.getMinutes()).padStart(2, '0');
    return hours + ':' + minutes;
  };

  var computeEndTime = function(dateValue, timeValue) {
    if (!dateValue || !timeValue) {
      return '';
    }
    var start = new Date(dateValue + 'T' + timeValue + ':00');
    if (isNaN(start.getTime())) {
      return '';
    }
    var end = new Date(start.getTime() + durationMinutes * 60000);
    return formatTime(end);
  };

  var computeEndTimeWithDuration = function(dateValue, timeValue, duration) {
    if (!dateValue || !timeValue) {
      return '';
    }
    var parsedDuration = parseInt(duration, 10);
    if (isNaN(parsedDuration) || parsedDuration <= 0) {
      return '';
    }
    var start = new Date(dateValue + 'T' + timeValue + ':00');
    if (isNaN(start.getTime())) {
      return '';
    }
    var end = new Date(start.getTime() + parsedDuration * 60000);
    return formatTime(end);
  };

  var computeCombiTimeline = function(dateValue, timeValue, mainDuration, selections) {
    var parsedMain = parseInt(mainDuration, 10);
    if (!dateValue || !timeValue || isNaN(parsedMain) || parsedMain <= 0) {
      return { before: [], after: [], main: { start: timeValue || '', end: '' } };
    }
    var baseStart = new Date(dateValue + 'T' + timeValue + ':00');
    if (isNaN(baseStart.getTime())) {
      return { before: [], after: [], main: { start: timeValue || '', end: '' } };
    }
    var mainEnd = new Date(baseStart.getTime() + parsedMain * 60000);
    var before = [];
    var after = [];
    (selections || []).forEach(function(selection) {
      var duration = parseInt(selection.duration, 10);
      if (isNaN(duration) || duration <= 0) {
        duration = parsedMain;
      }
      if (selection.timing === 'after') {
        after.push({ selection: selection, duration: duration });
      } else {
        before.push({ selection: selection, duration: duration });
      }
    });

    var beforeWindows = [];
    var cursor = baseStart;
    for (var i = before.length - 1; i >= 0; i -= 1) {
      var entry = before[i];
      var start = new Date(cursor.getTime() - entry.duration * 60000);
      beforeWindows.unshift({
        selection: entry.selection,
        start: formatTime(start),
        end: formatTime(cursor)
      });
      cursor = start;
    }

    var afterWindows = [];
    var afterCursor = mainEnd;
    for (var j = 0; j < after.length; j += 1) {
      var afterEntry = after[j];
      var afterStart = new Date(afterCursor.getTime());
      var afterEnd = new Date(afterCursor.getTime() + afterEntry.duration * 60000);
      afterWindows.push({
        selection: afterEntry.selection,
        start: formatTime(afterStart),
        end: formatTime(afterEnd)
      });
      afterCursor = afterEnd;
    }

    return {
      before: beforeWindows,
      after: afterWindows,
      main: { start: formatTime(baseStart), end: formatTime(mainEnd) }
    };
  };

  var updateSummary = function() {
    if (summaryDate) {
      var currentDateValue = dateInput ? dateInput.value : '';
      summaryDate.textContent = formatDateDisplay(currentDateValue);
    }

    if (summaryTime) {
      summaryTime.textContent = getSelectedTimeLabel();
    }

    var participants = getParticipants();
    if (summaryPeople) {
      summaryPeople.textContent = formatPeople(participants);
    }

    var combiSelections = normalizeSummaryCombiSelections(getCombiSelections());
    var combi = combiSelections.length ? combiSelections[0] : getCombiSelection();
    var combiLabelInput = container.querySelector('#sbdp_combi_label') || document.getElementById('sbdp_combi_label');
    var combiTotal = combiSelections.reduce(function(total, selection) {
      return total + computeCombiTotal(participants, selection.adjustment, selection.supportsPersons);
    }, 0);
    var baseFallbackTotal = computeFallbackTotal(participants, 0);
    var fallbackTotal = baseFallbackTotal + combiTotal;
    var pricingHasCombi = combiSelections.length <= 1 && !!(pricingState.raw && pricingState.raw.combi && combi && combi.value
      && String(pricingState.raw.combi.id || '') === String(combi.value));

    var pricingTotal = pricingState.displayTotal !== null ? pricingState.displayTotal : pricingState.total;
    var previewCombiTotal = pricingHasCombi && pricingState.raw && typeof pricingState.raw.combi.display_total === 'number'
      ? pricingState.raw.combi.display_total
      : pricingHasCombi && pricingState.raw && typeof pricingState.raw.combi.total === 'number'
      ? pricingState.raw.combi.total
      : 0;
    var previewCombiDisplay = previewCombiTotal;
    if (pricingState.displayScale && pricingState.displayScale !== 1) {
      previewCombiDisplay = previewCombiTotal * pricingState.displayScale;
    }
    var baseTotalForDisplay = pricingTotal !== null
      ? (pricingHasCombi ? pricingTotal - previewCombiDisplay : pricingTotal)
      : baseFallbackTotal;
    if (baseTotalForDisplay <= 0 && baseFallbackTotal > 0) {
      baseTotalForDisplay = baseFallbackTotal;
    }

    if (summaryCombiRow && summaryCombiLabel && summaryCombiValue) {
      if (summaryBreakdown) {
        summaryCombiRow.setAttribute('hidden', 'hidden');
      } else if (combi && combi.value && combiSelections.length <= 1) {
        summaryCombiRow.removeAttribute('hidden');
        summaryCombiLabel.textContent = combi.label || summaryDefaults.combiLabel || messageLookup('combi_default', 'Combi-deal');

        if (combi.adjustment > 0) {
          summaryCombiValue.textContent = '+' + formatCurrency(combi.adjustment) + (resolveCombiSupportsPersons(combi.supportsPersons) ? ' p.p.' : '');
          summaryCombiValue.classList.add('is-positive');
          summaryCombiValue.classList.remove('is-negative');
        } else if (combi.adjustment < 0) {
          summaryCombiValue.textContent = '-' + formatCurrency(Math.abs(combi.adjustment)) + (resolveCombiSupportsPersons(combi.supportsPersons) ? ' p.p.' : '');
          summaryCombiValue.classList.add('is-negative');
          summaryCombiValue.classList.remove('is-positive');
        } else {
          summaryCombiValue.textContent = summaryDefaults.combiValue || messageLookup('combi_included', 'Geen meerprijs');
          summaryCombiValue.classList.remove('is-positive', 'is-negative');
        }
      } else {
        summaryCombiRow.setAttribute('hidden', 'hidden');
        summaryCombiLabel.textContent = summaryDefaults.combiLabel || messageLookup('combi_default', 'Combi-deal');
        summaryCombiValue.textContent = summaryDefaults.combiValue || '';
        summaryCombiValue.classList.remove('is-positive', 'is-negative');
      }
    }

    if (combiLabelInput) {
      combiLabelInput.value = combi && combi.value && combi.label ? combi.label : '';
    }

    if (summaryBreakdown) {
      summaryBreakdown.innerHTML = '';
      var displayParticipants = participants > 0 ? participants : 1;
      var baseLineQuantity = (perPersonPrice > 0 || supportsPersons) ? displayParticipants : 1;
      var baseLineUnit = baseLineQuantity > 0 ? (baseTotalForDisplay / baseLineQuantity) : baseTotalForDisplay;
      var baseTitleNode = container.querySelector('[data-sbdp-summary-product]');
      var baseTitle = baseTitleNode && baseTitleNode.textContent ? baseTitleNode.textContent.trim() : (config.productName || 'Activiteit');

      combiSelections.filter(function(selection) {
        return selection && selection.timing !== 'after';
      }).forEach(function(selection) {
        var qty = resolveCombiSupportsPersons(selection.supportsPersons) ? displayParticipants : 1;
        var total = computeCombiTotal(displayParticipants, selection.adjustment, selection.supportsPersons);
        summaryBreakdown.appendChild(buildSummaryPriceLine(qty, selection.label || 'Combi-deal', selection.adjustment, total, 'before'));
      });

      summaryBreakdown.appendChild(buildSummaryPriceLine(baseLineQuantity, baseTitle, baseLineUnit, baseTotalForDisplay, 'main'));

      combiSelections.filter(function(selection) {
        return selection && selection.timing === 'after';
      }).forEach(function(selection) {
        var qty = resolveCombiSupportsPersons(selection.supportsPersons) ? displayParticipants : 1;
        var total = computeCombiTotal(displayParticipants, selection.adjustment, selection.supportsPersons);
        summaryBreakdown.appendChild(buildSummaryPriceLine(qty, selection.label || 'Combi-deal', selection.adjustment, total, 'after'));
      });
    }

    if (summaryTotal) {
      var combinedTotal = baseTotalForDisplay + combiTotal;
      if (pricingState.loading) {
        if (pricingState.formatted) {
          summaryTotal.textContent = combinedTotal !== null ? formatCurrency(combinedTotal) : pricingState.formatted;
        } else {
          summaryTotal.textContent = messageLookup('pricing_loading', 'Prijs wordt berekend.');
        }
      } else if (pricingState.formatted) {
        summaryTotal.textContent = combinedTotal !== null ? formatCurrency(combinedTotal) : pricingState.formatted;
      } else {
        summaryTotal.textContent = formatCurrency(fallbackTotal);
      }
    }

    updateCombiHiddenFields(combiSelections);
    updateLegacySummary(participants, combiSelections, combiTotal, fallbackTotal);
    ensureSummaryLabel();

    if (summaryHint) {
      if (pricingState.loading) {
        if (pricingState.formatted || fallbackTotal > 0) {
          summaryHint.setAttribute('hidden', 'hidden');
        } else {
          summaryHint.textContent = messageLookup('pricing_loading', 'Prijs wordt berekend.');
          summaryHint.removeAttribute('hidden');
        }
      } else if (pricingState.formatted || fallbackTotal > 0) {
        summaryHint.setAttribute('hidden', 'hidden');
      } else {
        summaryHint.textContent = hintDefaultText;
        summaryHint.removeAttribute('hidden');
      }
    }

    updateHelperProgress();
  };

  var updateLegacySummary = function(participants, combiSelections, combiTotal, fallbackTotal) {
    if (summaryTotal || summaryDate || summaryTime || summaryPeople || summaryCombiRow) {
      return;
    }

    var perPersonNode = document.getElementById('sbdp_price_per_person');
    var countNode = document.getElementById('sbdp_summary_aantal');
    var totalNode = document.getElementById('sbdp_summary_total');
    var summaryMeta = document.querySelector('.sbdp-summary-meta');
    if (!summaryMeta && !perPersonNode && !countNode && !totalNode) {
      return;
    }

    var baseFallbackTotal = computeFallbackTotal(participants, 0);
    var primaryCombi = combiSelections && combiSelections.length ? combiSelections[0] : null;
    var pricingHasCombi = !!(pricingState.raw && pricingState.raw.combi && primaryCombi && primaryCombi.value
      && String(pricingState.raw.combi.id || '') === String(primaryCombi.value));
    var previewCombiTotal = pricingHasCombi && pricingState.raw && typeof pricingState.raw.combi.display_total === 'number'
      ? pricingState.raw.combi.display_total
      : pricingHasCombi && pricingState.raw && typeof pricingState.raw.combi.total === 'number'
      ? pricingState.raw.combi.total
      : 0;
    var previewCombiDisplay = previewCombiTotal;
    if (pricingState.displayScale && pricingState.displayScale !== 1) {
      previewCombiDisplay = previewCombiTotal * pricingState.displayScale;
    }
    var displayTotal = pricingState.displayTotal !== null ? pricingState.displayTotal : pricingState.total;
    var baseTotalForDisplay = displayTotal !== null
      ? (pricingHasCombi ? displayTotal - previewCombiDisplay : displayTotal)
      : baseFallbackTotal;
    if (baseTotalForDisplay <= 0 && baseFallbackTotal > 0) {
      baseTotalForDisplay = baseFallbackTotal;
    }
    var perPersonValue = participants > 0 ? (baseTotalForDisplay / participants) : baseTotalForDisplay;
    if (perPersonNode) {
      perPersonNode.textContent = formatCurrency(perPersonValue).replace(/[^\d,\.\s]/g, '').trim();
    }

    if (countNode) {
      countNode.textContent = String(participants);
    }

    if (totalNode) {
      totalNode.textContent = formatCurrency(baseTotalForDisplay);
    }

    if (summaryMeta) {
      var displayParticipants = participants;
      if (participantsInput && participantsInput.value) {
        var parsedParticipants = parseInt(participantsInput.value, 10);
        if (!isNaN(parsedParticipants) && parsedParticipants > 0) {
          displayParticipants = parsedParticipants;
        }
      }

      summaryMeta.innerHTML = '';

      var labelNode = document.createElement('div');
      labelNode.setAttribute('data-sbdp-summary-label', 'true');
      labelNode.textContent = 'Overzicht';
      summaryMeta.appendChild(labelNode);

      var selectedDate = dateInput && dateInput.value ? dateInput.value : '';
      var selectedTime = getResolvedTimeValue();
      var timeline = computeCombiTimeline(selectedDate, selectedTime, durationMinutes, combiSelections);
      var titleText = '';
      if (config && config.productName) {
        titleText = config.productName;
      } else {
        var titleNode = container.querySelector('.sbdp-form-title')
          || container.querySelector('.sbdp-product-shell__title')
          || container.querySelector('.sbdp-product-hero__title')
          || document.querySelector('.product_title')
          || document.querySelector('.entry-title');
        if (titleNode && titleNode.textContent) {
          titleText = titleNode.textContent.trim();
        }
      }

      var baseLine = document.createElement('div');
      baseLine.setAttribute('data-sbdp-summary-base', 'true');
      var baseTitle = titleText || 'Activiteit';
      baseLine.textContent = displayParticipants + ' x ' + baseTitle;
      summaryMeta.appendChild(baseLine);

      var scheduleLine = document.createElement('div');
      scheduleLine.setAttribute('data-sbdp-summary-schedule', 'true');
      var timelineStart = timeline && timeline.main && timeline.main.start ? timeline.main.start : '--:--';
      var timelineEnd = timeline && timeline.main && timeline.main.end ? timeline.main.end : '--:--';
      scheduleLine.textContent = 'Datum ' + (selectedDate || '-') + ' • Tijd ' + timelineStart + ' – ' + timelineEnd;
      summaryMeta.appendChild(scheduleLine);

      if (Array.isArray(combiSelections) && combiSelections.length) {
        var combiSelection = combiSelections[0];
        var combiLine = document.createElement('div');
        combiLine.setAttribute('data-sbdp-summary-combi', 'true');
        var combiLabel = combiSelection.label || 'Combi-deal';
        var combiUnitAmount = parseFloat(combiSelection.adjustment) || 0;
        var combiDisplayTotal = computeCombiTotal(displayParticipants, combiUnitAmount, combiSelection.supportsPersons);
        combiLine.textContent = 'Combi ' + combiLabel + ' • ' + formatCurrency(combiDisplayTotal);
        summaryMeta.appendChild(combiLine);
      }

      var totalLine = document.createElement('div');
      totalLine.setAttribute('data-sbdp-summary-total', 'true');
      var combinedDisplayTotal = baseTotalForDisplay + combiSelections.reduce(function(total, selection) {
        return total + computeCombiTotal(displayParticipants, selection.adjustment, selection.supportsPersons);
      }, 0);
      totalLine.textContent = 'Totaal ' + formatCurrency(combinedDisplayTotal);
      summaryMeta.appendChild(totalLine);
    }
  };

  var clampParticipants = function(shouldUpdate) {
    if (!participantsInput) {
      return;
    }

    var syncParticipantsSuffix = function(rawValue) {
      if (!participantsSuffix) {
        return;
      }
      var count = parseInt(rawValue, 10);
      participantsSuffix.textContent = count === 1 ? 'persoon' : 'personen';
    };

    if (isSelectElement(participantsInput)) {
      if (!participantsInput.options || participantsInput.options.length === 0) {
        syncParticipantsSuffix(participantsInput.value);
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
    } else {
      var minValue = parseInt(participantsInput.getAttribute('min'), 10);
      var maxValue = parseInt(participantsInput.getAttribute('max'), 10);
      var parsedValue = parseInt(participantsInput.value, 10);
      if (isNaN(minValue) || minValue < 1) {
        minValue = 1;
      }
      if (isNaN(parsedValue) || parsedValue < minValue) {
        parsedValue = minValue;
      }
      if (!isNaN(maxValue) && maxValue > 0 && parsedValue > maxValue) {
        parsedValue = maxValue;
      }
      participantsInput.value = String(parsedValue);
    }

    if (shouldUpdate !== false) {
      updateSummary();
    }

    if (participantsInput && participantsInput.value) {
      persistSelection({ participants: participantsInput.value });
    }

    syncParticipantsSuffix(participantsInput.value);
  };

  var ensureDefaults = function() {
    if (dateInput) {
      if (today) {
        dateInput.setAttribute('data-min-date', today);
      }
    }

    var preferredDate = normalizeDate(persistedSelection.date) || normalizeDate(dateInput && dateInput.value) || normalizeDate(defaults && defaults.date) || calendarState.selectedDate;
    if (minSelectableDate && (!preferredDate || compareISO(preferredDate, minSelectableDate) < 0)) {
      preferredDate = minSelectableDate;
    }
    if (!preferredDate) {
      preferredDate = today || formatLocalDateISO(new Date());
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
    persistSelection({ date: preferredDate });

    if (participantsInput) {
      clampParticipants(false);
      if (persistedSelection.participants) {
        participantsInput.value = persistedSelection.participants;
      }
    }

    refreshPricing();
    updateHelperProgress();
  };

  var computeTimeRange = function() {
    if (!dateInput || !dateInput.value) {
      return null;
    }

    var time = getResolvedTimeValue();
    if (!time) {
      return null;
    }

    var start = new Date(dateInput.value + 'T' + time + ':00');
    if (isNaN(start.getTime())) {
      return null;
    }

    var duration = parseInt(durationMinutes, 10);
    if (isNaN(duration) || duration <= 0) {
      duration = 90;
    }

    var end = new Date(start.getTime() + duration * 60000);

    // Format as local YYYY-MM-DDTHH:mm:ss to prevent UTC timezone shifts.
    var formatLocalISO = function(date) {
      if (!(date instanceof Date) || isNaN(date.getTime())) return null;
      var pad = function(n) { return n < 10 ? '0' + n : n; };
      return date.getFullYear() + '-' +
        pad(date.getMonth() + 1) + '-' +
        pad(date.getDate()) + 'T' +
        pad(date.getHours()) + ':' +
        pad(date.getMinutes()) + ':' +
        pad(date.getSeconds());
    };

    return {
      start: formatLocalISO(start),
      end: formatLocalISO(end)
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
    if (resourceSelect && resourceSelect.value) {
      return parseInt(resourceSelect.value, 10) || 0;
    }
    if (!Array.isArray(resources) || resources.length === 0) {
      return 0;
    }

    var first = resources[0];
    if (first && typeof first.id !== 'undefined') {
      return parseInt(first.id, 10) || 0;
    }

    return 0;
  };

  var handleQuoteRequest = function(event) {
    if (event) {
      event.preventDefault();
    }

    if (!quoteUrl) {
      showFeedback(messageLookup('generic_error', 'Er ging iets mis. Probeer het opnieuw.'), 'error');
      return;
    }

    var params = [];
    if (config && config.productId) {
      params.push('product_id=' + encodeURIComponent(String(config.productId)));
    }
    if (dateInput && dateInput.value) {
      params.push('date=' + encodeURIComponent(dateInput.value));
    }
    var resolvedTime = getResolvedTimeValue();
    if (resolvedTime) {
      params.push('time=' + encodeURIComponent(resolvedTime));
    }
    if (participantsInput && participantsInput.value) {
      params.push('participants=' + encodeURIComponent(participantsInput.value));
    }

    var separator = quoteUrl.indexOf('?') === -1 ? '?' : '&';
    var target = params.length ? quoteUrl + separator + params.join('&') : quoteUrl;
    showFeedback(messageLookup('request_redirecting', 'We openen de offerte-aanvraag. Prijs en beschikbaarheid worden eerst bevestigd.'), 'info');
    window.location.href = target;
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

    if (!dateInput || !dateInput.value || !getResolvedTimeValue() || availableTimeOptions.length === 0) {
      showFeedback(messageLookup('missing_fields', missingFieldsFallback), 'error');
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
      combi: combiSelect && combiSelect.value ? combiSelect.value : '',
      combi_timing: combiTiming,
      combi_ids: combiSelections.map(function(selection) { return selection.value; }),
      combi_timing_map: combiTimingMap,
      items: [
        {
          product_id: config.productId,
          resource_id: getResourceId(),
          start: timeRange.start,
          end: timeRange.end,
          combi: combiSelect && combiSelect.value ? combiSelect.value : '',
          combi_timing: combiTiming,
          combi_ids: combiSelections.map(function(selection) { return selection.value; }),
          combi_timing_map: combiTimingMap,
          combi_label: combiSelect && combiSelect.options && combiSelect.selectedIndex >= 0
            ? (combiSelect.options[combiSelect.selectedIndex].textContent || '').trim()
            : ''
        }
      ]
    };

    showFeedback('', '');
    setLoading(true);

    fetch(composeUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
        'x-sbdp-nonce': nonce
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

  var submitPlannerAction = async function(event, options) {
    var settings = options || {};
    var redirect = !!settings.redirect;
    var requireSlot = settings.requireSlot !== false;
    var resolvedTime = getResolvedTimeValue();

    if (event) {
      event.preventDefault();
    }

    var target = plannerUrl || '';
    if (!target) {
      var missingMessage = messageLookup('planner_missing', 'Plannerpagina niet gevonden.');
      showFeedback(missingMessage, 'warning');
      updatePlannerStatus('error', missingMessage);
      return false;
    }

    var hasTimeSelection = !!resolvedTime;
    var hasSlots = availableTimeOptions.length > 0;

    if (requireSlot && (!hasTimeSelection || !hasSlots)) {
      var noSlotMessage = messageLookup('no_slots', 'Geen tijdsloten beschikbaar voor deze datum.');
      showFeedback(noSlotMessage, 'warning');
      updatePlannerStatus('error', noSlotMessage);
      return false;
    }

    if (requireSlot && participantsInput && participantsInput.disabled) {
      var capacityMessage = messageLookup('no_capacity', 'De geselecteerde capaciteit is niet beschikbaar.');
      showFeedback(capacityMessage, 'warning');
      updatePlannerStatus('error', capacityMessage);
      return false;
    }

    var params = [];
    if (dateInput && dateInput.value) {
      params.push('sbdp_date=' + encodeURIComponent(dateInput.value));
    }
    if (hasTimeSelection) {
      params.push('sbdp_time=' + encodeURIComponent(resolvedTime));
    }
    if (participantsInput && participantsInput.value) {
      params.push('sbdp_participants=' + encodeURIComponent(participantsInput.value));
    }
    if (config && config.productId) {
      params.push('sbdp_product=' + encodeURIComponent(String(config.productId)));
    }
    var resourceId = getResourceId();
    if (resourceId) {
      params.push('sbdp_resource=' + encodeURIComponent(String(resourceId)));
    }
    var combiSelections = getCombiSelections();
    var combiItems = buildCombiPayload(combiSelections);
    if (combiSelections.length) {
      combiSelections.forEach(function(selection) {
        params.push('sbdp_combi_ids[]=' + encodeURIComponent(selection.value));
        params.push('sbdp_combi_timing[' + encodeURIComponent(selection.value) + ']=' + encodeURIComponent(selection.timing || 'before'));
        if (selection.label) {
          params.push('sbdp_combi_label[' + encodeURIComponent(selection.value) + ']=' + encodeURIComponent(selection.label));
        }
      });
    } else if (combiSelect && combiSelect.value) {
      params.push('sbdp_combi=' + encodeURIComponent(combiSelect.value));
      var combiOption = combiSelect.options[combiSelect.selectedIndex >= 0 ? combiSelect.selectedIndex : 0];
      if (combiOption && combiOption.textContent) {
        var combiLabel = combiOption.textContent.trim();
        params.push('sbdp_combi_label=' + encodeURIComponent(combiLabel));
      }
    }

    updatePlannerStatus('pending', messageLookup('planner_pending', 'Activiteit wordt toegevoegd aan je planning...'));

    var plannerEntry = {
      source: 'product',
      lockFirstSlot: hasTimeSelection,
      append: true,
      traceId: buildTraceId('product')
    };
    plannerEntry.options = { combiItems: [] };
    if (durationMinutes > 0) {
      plannerEntry.durationMinutes = durationMinutes;
      plannerEntry.duration = durationMinutes;
    }
    if (config && config.productId) {
      plannerEntry.product_id = config.productId;
      plannerEntry.productId = config.productId;
    }
    if (dateInput && dateInput.value) {
      plannerEntry.date = dateInput.value;
    }
    if (hasTimeSelection) {
      plannerEntry.time = resolvedTime;
    }
    var participantCount = 1;
    if (participantsInput && participantsInput.value) {
      var parsedParticipantCount = parseInt(participantsInput.value, 10);
      if (isNaN(parsedParticipantCount) || parsedParticipantCount <= 0) {
        parsedParticipantCount = 1;
      }
      participantCount = parsedParticipantCount;
      plannerEntry.people = participantCount;
      plannerEntry.participants = participantCount;
    }
    plannerEntry.participants = participantCount;
    if (resourceId) {
      var numericResourceId = parseInt(resourceId, 10);
      plannerEntry.resource_id = !isNaN(numericResourceId) ? numericResourceId : resourceId;
    }
    if (combiSelections.length) {
      plannerEntry.combi_ids = combiSelections.map(function(selection) { return selection.value; });
      plannerEntry.combi_timing_map = combiSelections.reduce(function(acc, selection) {
        acc[selection.value] = selection.timing || 'before';
        return acc;
      }, {});
      plannerEntry.options.combiItems = combiItems;
      plannerEntry.combiItems = combiItems;
    } else if (combiSelect && combiSelect.value) {
      plannerEntry.combi = combiSelect.value;
      plannerEntry.combi_timing = getCombiTiming();
      var plannerCombiOption = combiSelect.options[combiSelect.selectedIndex >= 0 ? combiSelect.selectedIndex : 0];
      if (plannerCombiOption && plannerCombiOption.textContent) {
        plannerEntry.combi_label = plannerCombiOption.textContent.trim();
      }
      var singleCombiItems = [];
      if (combiSelect.value) {
        var rawDuration = plannerCombiOption && typeof plannerCombiOption.getAttribute === 'function'
          ? plannerCombiOption.getAttribute('data-duration')
          : '';
        var parsedDuration = parseInt(rawDuration, 10);
        if (isNaN(parsedDuration) || parsedDuration <= 0) {
          parsedDuration = 0;
        }

        var rawAdjustment = plannerCombiOption && typeof plannerCombiOption.getAttribute === 'function'
          ? plannerCombiOption.getAttribute('data-adjustment')
          : '';
        if (typeof rawAdjustment === 'string') {
          rawAdjustment = rawAdjustment.replace(',', '.');
        }
        var parsedAdjustment = parseFloat(rawAdjustment);
        if (isNaN(parsedAdjustment)) {
          parsedAdjustment = 0;
        }

        singleCombiItems.push({
          id: combiSelect.value,
          label: plannerEntry.combi_label || '',
          timing: plannerEntry.combi_timing || 'before',
          duration: parsedDuration,
          adjustment: parsedAdjustment,
        });
      }
      plannerEntry.options.combiItems = singleCombiItems;
      plannerEntry.combiItems = singleCombiItems;
    }

    await evaluatePlannerEntry(plannerEntry);
    if (redirect && typeof window !== 'undefined' && window.localStorage) {
      try {
        window.localStorage.removeItem('sbdpPlannerDraftV1');
      } catch (error) {
        // ignore storage errors
      }
    }
    var prefillEntry = enqueuePlannerEntry(plannerEntry, { persistQueue: true });
    if (prefillEntry) {
      params.push('sbdp_prefill=' + encodeURIComponent(JSON.stringify(prefillEntry)));
    }

    var plannerTarget = target;
    if (params.length) {
      plannerTarget += (plannerTarget.indexOf('?') === -1 ? '?' : '&') + params.join('&');
    }

    var queueMessage = formatPlannerReadyMessage(plannerQueueCount);
    var toastOptions = {
      linkHref: plannerTarget,
      linkLabel: messageLookup('view_planner', 'Bekijk planner'),
      linkTarget: '_self'
    };

    if (!redirect) {
      showFeedback(queueMessage, 'success', toastOptions);
      updatePlannerStatus('success', queueMessage);
      return true;
    }

    showFeedback(messageLookup('planner_toast', 'Activiteit klaargezet voor je planner.'), 'success', toastOptions);
    updatePlannerStatus('success', queueMessage);

    setTimeout(function() {
      if (plannerTarget) {
        window.location.href = plannerTarget;
      } else {
        showFeedback(messageLookup('planner_missing', 'Plannerpagina niet gevonden.'), 'error');
      }
    }, 650);
    return true;
  };

  var handlePlan = function(event) {
    submitPlannerAction(event, { redirect: true, requireSlot: false });
  };

  var handleQueue = function(event) {
    submitPlannerAction(event, { redirect: false, requireSlot: false });
  };

  var participantsDebounce = null;
  var minusButton = container.querySelector('#sbdp_minus');
  var plusButton = container.querySelector('#sbdp_plus');
  var adjustParticipants = function(delta) {
    if (!participantsInput) {
      return;
    }

    if (isSelectElement(participantsInput)) {
      var enabledOptions = Array.prototype.filter.call(participantsInput.options, function(option) {
        return option && !option.disabled && option.value !== '';
      });
      if (!enabledOptions.length) {
        return;
      }
      var currentValue = participantsInput.value;
      var currentIndex = enabledOptions.findIndex(function(option) {
        return option.value === currentValue;
      });
      if (currentIndex < 0) {
        currentIndex = 0;
      }
      var nextIndex = currentIndex + delta;
      if (nextIndex < 0) {
        nextIndex = 0;
      }
      if (nextIndex >= enabledOptions.length) {
        nextIndex = enabledOptions.length - 1;
      }
      participantsInput.value = enabledOptions[nextIndex].value;
    } else {
      var currentNumber = parseInt(participantsInput.value, 10);
      var minValue = parseInt(participantsInput.getAttribute('min'), 10);
      var maxValue = parseInt(participantsInput.getAttribute('max'), 10);
      if (isNaN(minValue)) {
        minValue = 1;
      }
      if (isNaN(currentNumber)) {
        currentNumber = minValue;
      }
      var nextValue = currentNumber + delta;
      if (nextValue < minValue) {
        nextValue = minValue;
      }
      if (!isNaN(maxValue) && maxValue > 0 && nextValue > maxValue) {
        nextValue = maxValue;
      }
      participantsInput.value = String(nextValue);
    }

    participantsInput.dispatchEvent(new Event('input', { bubbles: true }));
    participantsInput.dispatchEvent(new Event('change', { bubbles: true }));
  };
  if (participantsInput) {
    participantsInput.addEventListener('change', function(){
      clampParticipants();
      if (Array.isArray(availableTimeOptions) && availableTimeOptions.length > 0) {
        updateTimeOptions(availableTimeOptions, true);
      }
      if (typeof updateSummary === 'function') {
        updateSummary();
      }
      dispatchOptionsUpdated({ participants: participantsInput.value });
      refreshPricing();
      updateHelperProgress();
    });
    participantsInput.addEventListener('input', function(){
      if (participantsDebounce) {
        clearTimeout(participantsDebounce);
      }
      participantsDebounce = setTimeout(function() {
        clampParticipants();
        if (Array.isArray(availableTimeOptions) && availableTimeOptions.length > 0) {
          updateTimeOptions(availableTimeOptions, true);
        }
        if (typeof updateSummary === 'function') {
          updateSummary();
        }
        dispatchOptionsUpdated({ participants: participantsInput.value });
        refreshPricing();
        updateHelperProgress();
      }, 200);
    });
  }

  if (minusButton) {
    minusButton.addEventListener('click', function(event) {
      if (event) {
        event.preventDefault();
        event.stopPropagation();
      }
      adjustParticipants(-1);
    });
  }

  if (plusButton) {
    plusButton.addEventListener('click', function(event) {
      if (event) {
        event.preventDefault();
        event.stopPropagation();
      }
      adjustParticipants(1);
    });
  }

  if (timeInput) {
    timeInput.addEventListener('change', function(){
      updateSummary();
      dispatchOptionsUpdated({ selectedTime: getResolvedTimeValue() });
      refreshPricing();
      var resolvedTime = getResolvedTimeValue();
      if (resolvedTime) {
        persistSelection({ time: normalizeTime(resolvedTime) });
      }
      updateHelperProgress();
    });
  }

  if (combiSelect && !combiSelect.hasAttribute('data-sbdp-combi-listener')) {
    combiSelect.addEventListener('change', function(){
      updateSummary();
      refreshPricing();
    });
    combiSelect.setAttribute('data-sbdp-combi-listener', 'true');
  }

  if (dateInput) {
    dateInput.addEventListener('change', function(){
      selectDate(dateInput.value, { preserveTime: true, fetch: true });
    });
  }

  if (resourceSelect) {
    resourceSelect.addEventListener('change', function() {
      if (resourceInput) {
        resourceInput.value = resourceSelect.value || '';
      }
      updateResourceCapacity(resourceSelect.value);
      availabilityCache = {};
      loadAvailability(dateInput ? dateInput.value : '', false);
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
    timeslotList.addEventListener('change', function(event){
      if (!event || !event.target) {
        return;
      }
      selectTimeSlot(event.target.value, true);
    });
  }

  if (bookButton) {
    bookButton.addEventListener('click', handleBook);
  }

  if (quoteButton) {
    quoteButton.addEventListener('click', handleQuoteRequest);
  }

  if (planButton) {
    planButton.addEventListener('click', handlePlan);
  }

  if (queueButton) {
    queueButton.addEventListener('click', handleQueue);
  }

  if (scrollTriggers && scrollTriggers.length) {
    Array.prototype.forEach.call(scrollTriggers, function(trigger) {
      trigger.addEventListener('click', handleScrollTarget);
      trigger.addEventListener('keydown', function(evt) {
        if (!evt) {
          return;
        }
        var key = evt.key || evt.keyCode;
        if (key === 'Enter' || key === ' ' || key === 'Spacebar' || key === 13 || key === 32) {
          handleScrollTarget(evt);
        }
      });
    });
  }

  window.addEventListener('sbdp:plannerQueueChanged', function(event) {
    if (event && typeof event.detail === 'number') {
      plannerQueueCount = event.detail;
    }
    var latestQueue = readPlannerQueue();
    renderQueueBadge(latestQueue);
    if (plannerCard && plannerCard.classList.contains('is-pending')) {
      return;
    }
    hydratePlannerStatus();
  });

  window.addEventListener('focus', function() {
    if (plannerCard) {
      plannerCard.classList.remove('is-pending');
    }
    hydratePlannerStatus();
  });

  hydratePlannerStatus();
  ensureDefaults();
  initialiseAvailability().then(function(){
    if (typeof updateSummary === 'function') {
      updateSummary();
    }
  }).catch(function(){
    if (typeof updateSummary === 'function') {
      updateSummary();
    }
  });
  setTimeout(function() {
        if (typeof updateSummary === 'function') {
          updateSummary();
        }
  }, 300);
})();













