/**
 * ddb-context-state.js
 *
 * Discover / Fit / Match state logic for DagjeDenBosch browse pages.
 *
 * STATE MODEL
 * -----------
 * DISCOVER — no date, no group size: browse, orient, compare
 * FIT      — date OR group size known: soft suitability signals
 * MATCH    — both date AND group size known: ranked relevant options
 *
 * This script:
 * 1. Reads context from PreferenceManager (sessionStorage / URL params)
 * 2. Computes the current state
 * 3. Sets data-ddb-state="discover|fit|match" on the overview root
 * 4. Injects or updates the context bar inside the browser bar (compact date+group chip)
 * 5. Does NOT touch OMDB semantics, Woo pricing, or planner logic
 *
 * AVAILABILITY RULE
 * -----------------
 * Availability labels are only injected when state === MATCH and only
 * using the canonical four-word vocabulary:
 *   Beschikbaar | Beperkt beschikbaar | Op aanvraag | Niet beschikbaar
 *
 * @package DagjeDenBosch
 */
(function () {
  'use strict';

  // ─── State constants ────────────────────────────────────────────────────
  var STATE_DISCOVER = 'discover';
  var STATE_FIT      = 'fit';
  var STATE_MATCH    = 'match';

  // ─── Storage keys (must match PreferenceManager.js) ─────────────────────
  var STORAGE_KEY        = 'sbdp_user_preferences';
  var LEGACY_STORAGE_KEY = 'sbdp_home_widget_prefill';
  var STORAGE_EXPIRY_MS  = 2 * 60 * 60 * 1000;

  // ─── Dutch format helpers ────────────────────────────────────────────────
  var MONTH_NL = [
    'jan', 'feb', 'mrt', 'apr', 'mei', 'jun',
    'jul', 'aug', 'sep', 'okt', 'nov', 'dec',
  ];

  function formatDateNL(isoDate) {
    // isoDate: YYYY-MM-DD
    var parts = isoDate.split('-');
    if (parts.length !== 3) return isoDate;
    var d = parseInt(parts[2], 10);
    var m = parseInt(parts[1], 10) - 1;
    return d + ' ' + MONTH_NL[m];
  }

  function formatGroupNL(count) {
    if (!count) return null;
    var n = parseInt(count, 10);
    if (isNaN(n) || n < 1) return null;
    return n + '\u00a0pers'; // non-breaking space before pers
  }

  // ─── Preference loading ──────────────────────────────────────────────────

  function loadPreferences() {
    // 1. URL params have priority (explicit intent)
    var params = new URLSearchParams(window.location.search);
    var fromUrl = {
      visitDate: params.get('visitDate') || params.get('date'),
      count: params.get('count') || params.get('participants'),
    };
    if (fromUrl.visitDate || fromUrl.count) {
      return fromUrl;
    }

    // 2. Window global (set by planner / home widget)
    if (window.SBDP_HOME_WIDGET_PREFILL) {
      var g = window.SBDP_HOME_WIDGET_PREFILL;
      if (g.visitDate || g.count) return g;
    }

    // 3. Session storage
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      if (raw) {
        var payload = JSON.parse(raw);
        if (payload.expiresAt && Date.now() < payload.expiresAt && payload.data) {
          return payload.data;
        }
      }
      var legacy = sessionStorage.getItem(LEGACY_STORAGE_KEY);
      if (legacy) {
        return JSON.parse(legacy);
      }
    } catch (e) {
      // ignore storage errors
    }

    return null;
  }

  function computeState(prefs) {
    if (!prefs) return STATE_DISCOVER;
    var hasDate  = !!(prefs.visitDate && /^\d{4}-\d{2}-\d{2}$/.test(prefs.visitDate));
    var hasGroup = !!(prefs.count && parseInt(prefs.count, 10) >= 1);
    if (hasDate && hasGroup) return STATE_MATCH;
    if (hasDate || hasGroup) return STATE_FIT;
    return STATE_DISCOVER;
  }

  // ─── Browser bar context chip injection ──────────────────────────────────

  function buildContextLabel(prefs, state) {
    if (state === STATE_DISCOVER || !prefs) return null;

    var parts = [];
    if (prefs.visitDate && /^\d{4}-\d{2}-\d{2}$/.test(prefs.visitDate)) {
      parts.push(formatDateNL(prefs.visitDate));
    }
    var groupStr = formatGroupNL(prefs.count);
    if (groupStr) parts.push(groupStr);

    return parts.length ? parts.join('\u00a0·\u00a0') : null;
  }

  function buildStateHint(state, prefs) {
    if (state === STATE_DISCOVER) return null;
    if (state === STATE_FIT) {
      var hasDate  = prefs && prefs.visitDate;
      var hasGroup = prefs && prefs.count;
      if (hasDate && !hasGroup) return 'Voeg groepsgrootte toe voor exacte opties';
      if (hasGroup && !hasDate) return 'Kies een datum voor exacte beschikbaarheid';
      return null;
    }
    if (state === STATE_MATCH) {
      return 'Toont opties die hierbij passen';
    }
    return null;
  }

  function injectOrUpdateContextBar(browserBar, label, hint) {
    // Find or create a context bar inside the browser bar
    var existing = browserBar.querySelector('.ddb-context-bar');

    if (!label && !hint) {
      if (existing) existing.remove();
      return;
    }

    if (!existing) {
      existing = document.createElement('div');
      existing.className = 'ddb-context-bar';
      var inner = browserBar.querySelector('.ddb-browser-bar__inner');
      if (inner) {
        inner.appendChild(existing);
      } else {
        browserBar.appendChild(existing);
      }
    }

    var html = '';
    if (label) {
      html += '<span class="ddb-context-bar__chip">' +
        escHtml(label) +
        '</span>';
    }
    if (hint) {
      html += '<span class="ddb-context-bar__hint">' +
        escHtml(hint) +
        '</span>';
    }
    existing.innerHTML = html;
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ─── Root element state attribute ────────────────────────────────────────

  function applyStateToRoot(state) {
    // Apply to activity overview containers
    var roots = document.querySelectorAll(
      '.sbdp-activity-overview, .ddb-activiteiten-archive, .ddb-spots-archive, [data-component="sbdp-activity-overview"]'
    );
    roots.forEach(function (el) {
      el.setAttribute('data-ddb-state', state);
    });

    // Apply to the document body so global CSS rules can respond
    document.body.setAttribute('data-ddb-state', state);
  }

  // ─── Browser bar discover/fit/match chip area ────────────────────────────

  function updateBrowserBar(state, prefs) {
    var browserBars = document.querySelectorAll('.ddb-browser-bar');
    if (!browserBars.length) return;

    var label = buildContextLabel(prefs, state);
    var hint  = buildStateHint(state, prefs);

    browserBars.forEach(function (bar) {
      injectOrUpdateContextBar(bar, label, hint);
    });
  }

  // ─── Clear context (edit icon / reset link) ──────────────────────────────

  function renderClearControl(prefs, state) {
    if (state === STATE_DISCOVER) return;

    var bars = document.querySelectorAll('.ddb-browser-bar__chips');
    if (!bars.length) return;

    bars.forEach(function (chipBar) {
      if (chipBar.querySelector('.ddb-context-bar__clear')) return;

      var clearBtn       = document.createElement('button');
      clearBtn.type      = 'button';
      clearBtn.className = 'ddb-context-bar__clear';
      clearBtn.setAttribute('aria-label', 'Wis datum en groepsgrootte');
      clearBtn.textContent = 'Wis context';
      clearBtn.addEventListener('click', function () {
        clearAllContext();
      });
      chipBar.appendChild(clearBtn);
    });
  }

  function clearAllContext() {
    try {
      sessionStorage.removeItem(STORAGE_KEY);
      sessionStorage.removeItem(LEGACY_STORAGE_KEY);
    } catch (e) {
      // ignore
    }
    delete window.SBDP_HOME_WIDGET_PREFILL;

    // Remove state params from URL without reload
    var url = new URL(window.location.href);
    url.searchParams.delete('visitDate');
    url.searchParams.delete('date');
    url.searchParams.delete('count');
    url.searchParams.delete('participants');
    window.history.replaceState(null, '', url.toString());

    // Re-run with cleared state
    init();
  }

  // ─── Init ────────────────────────────────────────────────────────────────

  function init() {
    var prefs = loadPreferences();
    var state = computeState(prefs);

    applyStateToRoot(state);
    updateBrowserBar(state, prefs);
    renderClearControl(prefs, state);

    // Expose state for other scripts (read-only)
    window.DDB_CONTEXT_STATE = {
      state: state,
      visitDate: (prefs && prefs.visitDate) || null,
      count: (prefs && prefs.count) ? parseInt(prefs.count, 10) : null,
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Re-run if the planner or home widget pushes new state
  window.addEventListener('ddb:preferences:updated', function () {
    init();
  });
}());
