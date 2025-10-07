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
  var participantsInput = container.querySelector('input[name="sbdp_participants"]');
  var feedback = container.querySelector('[data-sbdp-feedback]');
  var bookButton = container.querySelector('[data-sbdp-action="book"]');
  var planButton = container.querySelector('[data-sbdp-action="plan"]');
  var buttons = container.querySelectorAll('[data-sbdp-action]');

  var plannerUrl = (config && config.plannerUrl) || localizedPlanner || '';
  var limits = (config && config.limits) || {};
  var defaults = (config && config.defaults) || {};
  var today = (config && config.today) || '';
  var resources = Array.isArray(config && config.resources) ? config.resources : [];
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

  var clampParticipants = function() {
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
      clampParticipants();
    }
  };

  var computeTimeRange = function() {
    if (!dateInput || !dateInput.value) {
      return null;
    }

    var time = defaults.time;
    if (typeof time !== 'string' || !/^\d{2}:\d{2}$/.test(time)) {
      time = '09:00';
    }

    var start = new Date(dateInput.value + 'T' + time + ':00');
    if (isNaN(start.getTime())) {
      return null;
    }

    var duration = parseInt(config && config.duration, 10);
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

    if (!dateInput || !dateInput.value) {
      showFeedback(messageLookup('missing_fields', 'Vul datum en aantal personen in.'), 'error');
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
        showFeedback(messageLookup('redirecting', 'Bezig met doorsturen…'), 'info');
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
    if (participantsInput && participantsInput.value) {
      params.push('sbdp_participants=' + encodeURIComponent(participantsInput.value));
    }

    if (params.length) {
      target += (target.indexOf('?') === -1 ? '?' : '&') + params.join('&');
    }

    window.location.href = target;
  };

  if (participantsInput) {
    participantsInput.addEventListener('change', clampParticipants);
    participantsInput.addEventListener('blur', clampParticipants);
  }

  if (bookButton) {
    bookButton.addEventListener('click', handleBook);
  }

  if (planButton) {
    planButton.addEventListener('click', handlePlan);
  }

  ensureDefaults();
})();
