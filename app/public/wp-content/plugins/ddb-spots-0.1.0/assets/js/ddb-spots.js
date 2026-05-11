(function () {
  function getSettings() {
    return window.ddbSpotsFrontend || {};
  }

  function toInt(value) {
    var parsed = parseInt(value || '0', 10);
    return isNaN(parsed) ? 0 : parsed;
  }

  function normalizeUrl(url) {
    return String(url || '').replace(/\/+$/, '');
  }

  function uniqueUrls(urls) {
    var out = [];
    var seen = {};
    urls.forEach(function (url) {
      var key = normalizeUrl(url);
      if (!key || seen[key]) return;
      seen[key] = true;
      out.push(key);
    });
    return out;
  }

  function getSystemTheme() {
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      return 'dark';
    }
    return 'light';
  }

  function getStoredTheme() {
    try {
      return window.localStorage.getItem('ddbTheme') || '';
    } catch (e) {
      return '';
    }
  }

  function setStoredTheme(theme) {
    try {
      window.localStorage.setItem('ddbTheme', theme);
    } catch (e) {
      // ignore storage failures
    }
  }

  function applyTheme(theme) {
    var mode = theme === 'dark' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-ddb-theme', mode);
    return mode;
  }

  function updateThemeButtons(mode) {
    document.querySelectorAll('[data-ddb-theme-toggle]').forEach(function (button) {
      var lightLabel = button.getAttribute('data-light-label') || 'Lichte modus';
      var darkLabel = button.getAttribute('data-dark-label') || 'Donkere modus';
      button.textContent = mode === 'dark' ? lightLabel : darkLabel;
      button.setAttribute('aria-label', mode === 'dark' ? lightLabel : darkLabel);
    });
  }

  function mapLegacyEventType(raw) {
    if (raw === 'cta_click') return 'cta_click';
    if (raw === 'card_click') return 'spot_view';
    if (raw === 'add_to_plan') return 'module_event';
    if (raw === 'module_event') return 'module_event';
    return '';
  }

  function buildEndpoints() {
    var settings = getSettings();
    if (!settings.canTrackEvents) {
      return [];
    }
    var endpoints = [];

    if (settings.eventsEndpoint) {
      endpoints.push(settings.eventsEndpoint);
    }

    if (settings.legacyEventsEndpoint) {
      endpoints.push(settings.legacyEventsEndpoint);
    }

    return uniqueUrls(endpoints);
  }

  function enrichMeta(payload) {
    var base = {};
    var sourceMeta = payload.meta || {};
    Object.keys(sourceMeta).forEach(function (key) {
      base[key] = sourceMeta[key];
    });

    base.context = payload.context || '';
    base.spot_type = payload.spot_type || '';
    base.cta_type = payload.cta_type || '';
    base.path = window.location.pathname || '';
    return base;
  }

  function buildBody(endpoint, payload) {
    var eventType = payload.event_type || mapLegacyEventType(payload.event || '');
    if (!eventType) return null;

    var spotId = toInt(payload.spot_id);
    var meta = enrichMeta(payload);

    if (endpoint.indexOf('/dbspots/v1/events') !== -1) {
      if (eventType === 'module_event') {
        return null;
      }
      return {
        event_type: eventType,
        spot_id: spotId,
        source: 'frontend',
        context: meta
      };
    }

    return {
      event_type: eventType,
      spot_id: spotId,
      meta: meta
    };
  }

  function sendEvent(payload) {
    if (typeof window.fetch !== 'function') return;
    var endpoints = buildEndpoints();
    if (!endpoints.length) return;

    var settings = getSettings();
    var headers = {
      'Content-Type': 'application/json'
    };
    if (settings.restNonce) {
      headers['X-WP-Nonce'] = String(settings.restNonce);
    }

    var index = 0;
    var blockedEndpoints = window.ddbSpotsBlockedEndpoints || {};
    window.ddbSpotsBlockedEndpoints = blockedEndpoints;

    function sendNext() {
      if (index >= endpoints.length) return;
      var endpoint = endpoints[index++];
      if (blockedEndpoints[normalizeUrl(endpoint)]) {
        sendNext();
        return;
      }
      var body = buildBody(endpoint, payload);
      if (!body) {
        sendNext();
        return;
      }

      window.fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: JSON.stringify(body)
      }).then(function (response) {
        if (!response || !response.ok) {
          if (response && (response.status === 401 || response.status === 403 || response.status === 404 || response.status === 405)) {
            blockedEndpoints[normalizeUrl(endpoint)] = true;
          }
          sendNext();
        }
      }).catch(function () {
        blockedEndpoints[normalizeUrl(endpoint)] = true;
        sendNext();
      });
    }

    sendNext();
  }

  function emitTrack(payload) {
    document.dispatchEvent(new CustomEvent('ddb:track', { detail: payload }));
    sendEvent(payload);
    if (window.dataLayer && Array.isArray(window.dataLayer)) {
      window.dataLayer.push(payload);
    }
  }

  function trackClick(el) {
    var eventName = el.getAttribute('data-ddb-track') || '';
    if (!eventName) return;

    var payload = {
      event: eventName,
      spot_id: el.getAttribute('data-ddb-spot-id') || '',
      spot_type: el.getAttribute('data-ddb-spot-type') || '',
      context: el.getAttribute('data-ddb-context') || '',
      cta_type: el.getAttribute('data-ddb-cta-type') || '',
      meta: {}
    };

    if (eventName === 'add_to_plan') {
      payload.event_type = 'module_event';
      payload.meta.module = 'day_planner';
      payload.meta.action = 'add_to_plan_click';
    }
    if (eventName === 'module_event') {
      payload.event_type = 'module_event';
      payload.meta.module = el.getAttribute('data-ddb-module') || 'unknown';
      payload.meta.action = el.getAttribute('data-ddb-cta-type') || 'click';
      var spotIds = el.getAttribute('data-ddb-spot-ids') || '';
      if (spotIds) payload.meta.spot_ids = spotIds;
    }

    emitTrack(payload);
  }

  var seenViews = {};

  function trackSpotView(spotId, spotType, context, meta) {
    spotId = toInt(spotId);
    if (!spotId) return;
    var key = String(spotId) + ':' + String(context || '');
    if (seenViews[key]) return;
    seenViews[key] = true;

    emitTrack({
      event_type: 'spot_view',
      spot_id: spotId,
      spot_type: spotType || '',
      context: context || '',
      meta: meta || {}
    });
  }

  function initSingleViewTracking() {
    var root = document.querySelector('.ddb-node[data-ddb-spot-id], .ddb-spot-layout[data-ddb-spot-id], .ddb-spot-detail[data-ddb-spot-id]');
    if (!root) return;
    trackSpotView(
      root.getAttribute('data-ddb-spot-id') || '',
      root.getAttribute('data-ddb-spot-type') || '',
      'single_load',
      { source: 'single' }
    );
  }

  function initListingImpressions() {
    var links = Array.prototype.slice.call(document.querySelectorAll('.ddb-spot-card__link[data-ddb-spot-id]'));
    if (!links.length) return;

    if (!('IntersectionObserver' in window)) {
      links.slice(0, 6).forEach(function (link) {
        trackSpotView(
          link.getAttribute('data-ddb-spot-id') || '',
          link.getAttribute('data-ddb-spot-type') || '',
          'listing_impression',
          { source: 'listing_fallback' }
        );
      });
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var link = entry.target;
        trackSpotView(
          link.getAttribute('data-ddb-spot-id') || '',
          link.getAttribute('data-ddb-spot-type') || '',
          'listing_impression',
          { source: 'listing_observer' }
        );
        observer.unobserve(link);
      });
    }, { threshold: 0.4 });

    links.forEach(function (link) {
      observer.observe(link);
    });
  }

  var seenModules = {};

  function trackModuleEvent(el, module, action) {
    var root = el.closest('[data-ddb-spot-id]');
    if (!root) return;
    var spotId = toInt(root.getAttribute('data-ddb-spot-id') || '0');
    if (!spotId) return;

    var key = String(spotId) + ':' + module + ':' + action;
    if (action === 'interact' && seenModules[key]) {
      return;
    }
    seenModules[key] = true;

    emitTrack({
      event_type: 'module_event',
      spot_id: spotId,
      spot_type: root.getAttribute('data-ddb-spot-type') || '',
      context: 'module',
      meta: {
        module: module,
        action: action
      }
    });
  }

  function initModuleTracking() {
    var detailsNodes = document.querySelectorAll('.ddb-spot-layout__hours, .ddb-node-hours');
    detailsNodes.forEach(function (details) {
      details.addEventListener('toggle', function () {
        trackModuleEvent(details, 'opening_hours', details.open ? 'open' : 'close');
      });
    });

    var sliders = document.querySelectorAll('.ddb-spot-layout__slider');
    sliders.forEach(function (slider) {
      slider.addEventListener('pointerdown', function () {
        trackModuleEvent(slider, 'gallery', 'interact');
      }, { passive: true });
      slider.addEventListener('touchstart', function () {
        trackModuleEvent(slider, 'gallery', 'interact');
      }, { passive: true });
      slider.addEventListener('wheel', function () {
        trackModuleEvent(slider, 'gallery', 'interact');
      }, { passive: true });
    });
  }

  function initNodeMapToggle() {
    var buttons = document.querySelectorAll('[data-ddb-map-expand]');
    if (!buttons.length) return;
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        var panel = button.parentElement ? button.parentElement.querySelector('[data-ddb-map-canvas]') : null;
        if (!panel) return;
        var hidden = panel.hasAttribute('hidden');
        if (hidden) {
          panel.removeAttribute('hidden');
          button.textContent = 'Verberg kaart';
          trackModuleEvent(button, 'location_map', 'open');
        } else {
          panel.setAttribute('hidden', 'hidden');
          button.textContent = 'Toon kaart';
          trackModuleEvent(button, 'location_map', 'close');
        }
      });
    });
  }

  function initPlannerHook() {
    var links = document.querySelectorAll('[data-ddb-add-to-day]');
    if (!links.length) return;

    links.forEach(function (link) {
      link.addEventListener('click', function (event) {
        var detail = {
          spot_id: toInt(link.getAttribute('data-ddb-spot-id') || '0'),
          spot_type: link.getAttribute('data-ddb-spot-type') || '',
          href: link.getAttribute('href') || ''
        };
        var plannerEvent = new CustomEvent('ddb:add-to-day', { detail: detail, cancelable: true });
        var shouldContinue = document.dispatchEvent(plannerEvent);
        if (!shouldContinue) {
          event.preventDefault();
          return;
        }
        if (window.ddbPlanner && typeof window.ddbPlanner.open === 'function') {
          event.preventDefault();
          window.ddbPlanner.open(detail);
        }
      });
    });
  }

  function initSingleMapToggle() {
    var blocks = document.querySelectorAll('[data-ddb-single-map]');
    if (!blocks.length) return;

    blocks.forEach(function (block) {
      var toggle = block.querySelector('[data-ddb-single-map-toggle]');
      if (!toggle) return;

      function updateLabel(isOpen) {
        var openLabel = toggle.getAttribute('data-label-open') || 'Toon kaart';
        var closeLabel = toggle.getAttribute('data-label-close') || 'Verberg kaart';
        toggle.textContent = isOpen ? closeLabel : openLabel;
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      }

      updateLabel(block.classList.contains('is-map-open'));

      toggle.addEventListener('click', function () {
        var isOpen = block.classList.toggle('is-map-open');
        updateLabel(isOpen);
        trackModuleEvent(block, 'location_map', isOpen ? 'open' : 'close');
      });
    });
  }

  function initSingleSectionNav() {
    var navs = document.querySelectorAll('[data-ddb-section-nav]');
    if (!navs.length) return;

    navs.forEach(function (nav) {
      var links = Array.prototype.slice.call(nav.querySelectorAll('a[href^="#"]'));
      if (!links.length) return;

      var map = {};
      var sections = [];

      links.forEach(function (link) {
        var hash = link.getAttribute('href') || '';
        if (!hash || hash === '#') return;
        var id = hash.slice(1);
        if (!id) return;
        var section = document.getElementById(id);
        if (!section) return;
        map[id] = link;
        sections.push(section);

        link.addEventListener('click', function (event) {
          event.preventDefault();
          var navHeight = nav.getBoundingClientRect().height || 0;
          var top = section.getBoundingClientRect().top + window.scrollY - navHeight - 18;
          window.scrollTo({
            top: Math.max(0, top),
            behavior: 'smooth'
          });
        });
      });

      function setActive(id) {
        links.forEach(function (link) {
          link.classList.toggle('is-active', link === map[id]);
        });
      }

      if (!sections.length) return;
      setActive(sections[0].id);

      if (!('IntersectionObserver' in window)) return;

      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          if (!entry.target || !entry.target.id) return;
          if (!map[entry.target.id]) return;
          setActive(entry.target.id);
        });
      }, {
        threshold: 0.15,
        rootMargin: '-32% 0px -52% 0px'
      });

      sections.forEach(function (section) {
        observer.observe(section);
      });
    });
  }

  function setListingMapFocus(shell, item) {
    if (!shell || !item) return;
    var frame = shell.querySelector('[data-ddb-map-frame]');
    var title = shell.querySelector('[data-ddb-map-title]');
    var address = shell.querySelector('[data-ddb-map-address]');
    var link = shell.querySelector('[data-ddb-map-link]');
    var embedUrl = item.getAttribute('data-embed-url') || '';
    var mapUrl = item.getAttribute('data-map-url') || '';
    var itemTitle = item.getAttribute('data-title') || '';
    var itemAddress = item.getAttribute('data-address') || '';

    shell.querySelectorAll('[data-ddb-map-item]').forEach(function (node) {
      node.classList.remove('is-active');
    });
    item.classList.add('is-active');

    if (frame && embedUrl) {
      frame.setAttribute('src', embedUrl);
    }
    if (title) {
      title.textContent = itemTitle;
    }
    if (address) {
      address.textContent = itemAddress;
    }
    if (link && mapUrl) {
      link.setAttribute('href', mapUrl);
    }
  }

  function initListingMap() {
    var shells = document.querySelectorAll('.ddb-listing-shell[data-ddb-component="listing-shell"]');
    if (!shells.length) return;

    shells.forEach(function (shell) {
      var toggle = shell.querySelector('[data-ddb-map-toggle]');
      if (toggle) {
        toggle.addEventListener('click', function () {
          shell.classList.toggle('is-map-open');
          toggle.textContent = shell.classList.contains('is-map-open') ? 'Resultaten tonen' : 'Kaart tonen';
        });
      }

      var mapItems = shell.querySelectorAll('[data-ddb-map-item]');
      mapItems.forEach(function (item) {
        item.addEventListener('click', function () {
          setListingMapFocus(shell, item);
        });
      });

      var focusLinks = shell.querySelectorAll('[data-ddb-map-focus]');
      focusLinks.forEach(function (link) {
        var spotId = link.getAttribute('data-ddb-map-focus');
        if (!spotId) return;
        var mapItem = shell.querySelector('[data-ddb-map-item][data-spot-id="' + spotId + '"]');
        if (!mapItem) return;
        link.addEventListener('mouseenter', function () {
          setListingMapFocus(shell, mapItem);
        });
        link.addEventListener('focus', function () {
          setListingMapFocus(shell, mapItem);
        });
      });
    });
  }

  function initThemeToggle() {
    if (window.DDB_SPOTS_ENABLE_LEGACY_THEME_TOGGLE !== true) {
      var globalTheme = document.documentElement.getAttribute('data-theme');
      if (globalTheme === 'dark' || globalTheme === 'light') {
        document.documentElement.setAttribute('data-ddb-theme', globalTheme);
      }
      return;
    }

    var preferred = getStoredTheme();
    if (preferred !== 'dark' && preferred !== 'light') {
      preferred = getSystemTheme();
    }
    var mode = applyTheme(preferred);
    updateThemeButtons(mode);

    document.querySelectorAll('[data-ddb-theme-toggle]').forEach(function (button) {
      button.addEventListener('click', function () {
        var current = document.documentElement.getAttribute('data-ddb-theme') || 'light';
        var next = current === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        setStoredTheme(next);
        updateThemeButtons(next);
      });
    });
  }

  document.addEventListener('click', function (event) {
    var target = event.target.closest('[data-ddb-track]');
    if (!target) return;
    trackClick(target);
  });

  initSingleViewTracking();
  initListingImpressions();
  initModuleTracking();
  initNodeMapToggle();
  initPlannerHook();
  initSingleMapToggle();
  initSingleSectionNav();
  initListingMap();
  initThemeToggle();
})();
