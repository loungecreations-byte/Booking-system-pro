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

  var dateInput = container.querySelector('input[name="sbdp_date"]');
  var timeInput = container.querySelector('input[name="sbdp_time"]');
  var participantsInput = container.querySelector('input[name="sbdp_participants"]');
  var combiSelect = container.querySelector('select[name="sbdp_combi"]');
  var feedback = container.querySelector('[data-sbdp-feedback]');
  var bookButton = container.querySelector('[data-sbdp-action="book"]');
  var planButton = container.querySelector('[data-sbdp-action="plan"]');
  var buttons = container.querySelectorAll('[data-sbdp-action]');

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

  var messageLookup = function(key, fallback) {
    if (messages && Object.prototype.hasOwnProperty.call(messages, key)) {
      return messages[key];
    }
    return fallback || '';
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

    if (summaryTotal) {
      var total = (basePrice * participants) + combi.adjustment;
      summaryTotal.textContent = formatCurrency(total);
    }

    if (summaryHint) {
      if ((basePrice * participants) + combi.adjustment > 0) {
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

    var min = parseInt(limits.min, 10);
    if (isNaN(min) || min < 1) {
      min = 1;
    }

    var max = parseInt(limits.max, 10);
    var value = parseInt(participantsInput.value || '0', 10);
    if (isNaN(value) || value < min) {
      value = min;
    }

    if (!isNaN(max) && max > 0 && value > max) {
      value = max;
    }

    participantsInput.value = String(value);

    if (shouldUpdate !== false) {
      updateSummary();
    }
  };

  var ensureDefaults = function() {
    if (dateInput) {
      if (!dateInput.value && defaults.date) {
        dateInput.value = defaults.date;
      }
      if (today) {
        dateInput.min = today;
      }
    }

    if (timeInput) {
      if (!timeInput.value && defaults.time) {
        timeInput.value = defaults.time;
      }
      if (!timeInput.value) {
        timeInput.value = '09:00';
      }
    }

    if (participantsInput) {
      if (limits.min) {
        participantsInput.min = String(limits.min);
      }
      if (limits.max) {
        participantsInput.max = String(limits.max);
      }
      if (!participantsInput.value && defaults.participants) {
        participantsInput.value = String(defaults.participants);
      }
      clampParticipants(false);
    }
  };

  var computeTimeRange = function() {
    if (!dateInput || !dateInput.value) {
      return null;
    }

    var time = '';
    if (timeInput && /^\d{2}:\d{2}$/.test(timeInput.value || '')) {
      time = timeInput.value;
    } else if (typeof defaults.time === 'string' && /^\d{2}:\d{2}$/.test(defaults.time)) {
      time = defaults.time;
    } else {
      time = '09:00';
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

    var value = parseInt(participantsInput.value || '1', 10);
    if (isNaN(value) || value < 1) {
      value = 1;
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

    if (!dateInput || !dateInput.value || (timeInput && !timeInput.value)) {
      showFeedback(messageLookup('missing_fields', 'Vul datum, starttijd en aantal personen in.'), 'error');
      return;
    }

    var timeRange = computeTimeRange();
    if (!timeRange) {
      showFeedback(messageLookup('generic_error', 'Er ging iets mis. Probeer het opnieuw.'), 'error');
      return;
    }

    var participants = getParticipants();

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
    });
    participantsInput.addEventListener('blur', function(){
      clampParticipants();
    });
  }

  if (timeInput) {
    timeInput.addEventListener('change', updateSummary);
    timeInput.addEventListener('blur', function(){
      if (timeInput && !/^\d{2}:\d{2}$/.test(timeInput.value || '')) {
        timeInput.value = defaults.time || '09:00';
      }
      updateSummary();
    });
  }

  if (combiSelect) {
    combiSelect.addEventListener('change', updateSummary);
  }

  if (dateInput) {
    dateInput.addEventListener('change', updateSummary);
  }

  if (bookButton) {
    bookButton.addEventListener('click', handleBook);
  }

  if (planButton) {
    planButton.addEventListener('click', handlePlan);
  }

  ensureDefaults();
  updateSummary();
})();




