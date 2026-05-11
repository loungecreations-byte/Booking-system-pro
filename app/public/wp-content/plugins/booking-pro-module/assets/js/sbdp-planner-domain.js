(function () {
  "use strict";

  if (window.SBDPPlannerDomain) {
    return;
  }

  var config = window.SBDP_PLANNER_DOMAIN_CONFIG || {};
  var storage = config.storage || {};
  var DRAFT_KEY = storage.draftKey || "sbdpPlannerDraftV1";
  var QUEUE_KEY = storage.queueKey || "sbdpPlannerPrefillQueue";
  var SETTINGS_KEY = storage.settingsKey || "sbdpPlannerSettings";

  function safeParse(raw, fallback) {
    if (!raw) {
      return fallback;
    }
    try {
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : fallback;
    } catch (error) {
      return fallback;
    }
  }

  function clone(value, fallback) {
    return safeParse(JSON.stringify(value), fallback);
  }

  function toPositiveInt(value) {
    var parsed = parseInt(value, 10);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
  }

  function sanitizeDate(value) {
    if (typeof value !== "string") {
      return "";
    }
    var trimmed = value.trim();
    return /^\d{4}-\d{2}-\d{2}$/.test(trimmed) ? trimmed : "";
  }

  function sanitizeTime(value) {
    if (typeof value !== "string") {
      return "";
    }
    var trimmed = value.trim();
    if (/^\d{2}:\d{2}$/.test(trimmed)) {
      return trimmed;
    }
    var match = trimmed.match(/(\d{2}:\d{2})/);
    return match ? match[1] : "";
  }

  function timeToMinutes(value) {
    var time = sanitizeTime(value);
    if (!time) {
      return 0;
    }
    var parts = time.split(":");
    return (parseInt(parts[0], 10) * 60) + parseInt(parts[1], 10);
  }

  function roundCurrency(value) {
    var number = typeof value === "number" ? value : parseFloat(value || 0);
    return Number.isFinite(number) ? Math.round((number + Number.EPSILON) * 100) / 100 : 0;
  }

  function emitChange(detail) {
    if (typeof window === "undefined" || typeof window.dispatchEvent !== "function") {
      return;
    }
    try {
      window.dispatchEvent(new CustomEvent("sbdp:planner/domain-updated", { detail: detail || {} }));
    } catch (error) {
      // ignore dispatch errors
    }
  }

  function buildPlannerKey(item) {
    var productId = toPositiveInt(item && (item.productId || item.product_id)) || 0;
    var date = sanitizeDate(item && item.date) || "";
    var startTime = sanitizeTime(item && item.startTime) || "";
    var resourceId = toPositiveInt(item && (item.resourceId || item.resource_id)) || 0;
    var participants = toPositiveInt(item && item.participants) || 1;
    var combiItems = item && item.options && Array.isArray(item.options.combiItems) ? item.options.combiItems : [];
    var combiIds = combiItems
      .map(function (entry) {
        return toPositiveInt(entry && entry.id);
      })
      .filter(Boolean)
      .join(",");

    return [productId, date, startTime, resourceId, participants, combiIds].join("|");
  }

  function normalizeInput(raw) {
    raw = raw && typeof raw === "object" ? raw : {};

    var productId = toPositiveInt(raw.productId || raw.product_id) || 0;
    var participants = toPositiveInt(raw.participants || raw.people) || 1;
    var date = sanitizeDate(raw.date || raw.start_date);
    var time = sanitizeTime(raw.time || raw.start_time || (raw.timeslot && raw.timeslot.start));
    var resourceId = toPositiveInt(raw.resourceId || raw.resource_id) || 0;
    var options = raw.options && typeof raw.options === "object" ? clone(raw.options, {}) : {};
    options.combiItems = Array.isArray(options.combiItems)
      ? options.combiItems
      : Array.isArray(raw.combi_multi)
      ? clone(raw.combi_multi, [])
      : [];

    return {
      schemaVersion: config.schemaVersion || "1.0.0",
      productId: productId,
      productType: typeof raw.productType === "string" ? raw.productType : (typeof raw.product_type === "string" ? raw.product_type : ""),
      date: date,
      participants: participants,
      timeslot: {
        start: time,
        end: sanitizeTime(raw.endTime || raw.end_time || (raw.timeslot && raw.timeslot.end)),
        slotId: null,
      },
      resourceId: resourceId,
      options: options,
      source: typeof raw.source === "string" && raw.source.trim() ? raw.source.trim() : "planner",
      locationContext: {
        resourceId: resourceId,
        resourceLabel: raw.locationContext && typeof raw.locationContext.resourceLabel === "string" ? raw.locationContext.resourceLabel : "",
      },
    };
  }

  function normalizePlanItem(raw) {
    raw = raw && typeof raw === "object" ? raw : {};

    var normalized = clone(raw, {});
    normalized.productId = toPositiveInt(normalized.productId || normalized.product_id) || 0;
    normalized.product_id = normalized.productId;
    normalized.date = sanitizeDate(normalized.date || (normalized.plannerInput && normalized.plannerInput.date));
    normalized.startTime = sanitizeTime(normalized.startTime || normalized.start || (normalized.plannerInput && normalized.plannerInput.timeslot && normalized.plannerInput.timeslot.start));
    normalized.endTime = sanitizeTime(normalized.endTime || normalized.end || (normalized.plannerInput && normalized.plannerInput.timeslot && normalized.plannerInput.timeslot.end));
    normalized.participants = toPositiveInt(normalized.participants) || 1;
    normalized.resourceId = toPositiveInt(normalized.resourceId || normalized.resource_id) || 0;
    normalized.resource_id = normalized.resourceId;
    normalized.durationMinutes = toPositiveInt(normalized.durationMinutes) || Math.max(0, timeToMinutes(normalized.endTime) - timeToMinutes(normalized.startTime));
    normalized.startMinutes = timeToMinutes(normalized.startTime);
    normalized.endMinutes = timeToMinutes(normalized.endTime);
    normalized.status = typeof normalized.status === "string" && normalized.status.trim() ? normalized.status : "planned";
    normalized.options = normalized.options && typeof normalized.options === "object" ? normalized.options : {};
    normalized.options.combiItems = Array.isArray(normalized.options.combiItems)
      ? normalized.options.combiItems
      : Array.isArray(normalized.pricing && normalized.pricing.combi_multi)
      ? clone(normalized.pricing.combi_multi, [])
      : [];
    normalized.pricing = normalized.pricing && typeof normalized.pricing === "object" ? normalized.pricing : {};
    normalized.pricing.currency = typeof normalized.pricing.currency === "string" ? normalized.pricing.currency : "EUR";
    normalized.totalCost = roundCurrency(
      normalized.totalCost != null
        ? normalized.totalCost
        : normalized.pricing && normalized.pricing.dynamic
        ? normalized.pricing.dynamic.total
        : normalized.pricing.total
    );
    normalized.title = typeof normalized.title === "string" && normalized.title.trim() ? normalized.title.trim() : "Activiteit";
    normalized.plannerInput = normalizeInput(normalized.plannerInput || {
      productId: normalized.productId,
      date: normalized.date,
      time: normalized.startTime,
      participants: normalized.participants,
      resourceId: normalized.resourceId,
      options: normalized.options,
      source: normalized.source,
    });
    normalized.plannerKey = typeof normalized.plannerKey === "string" && normalized.plannerKey.trim()
      ? normalized.plannerKey.trim()
      : buildPlannerKey(normalized);
    normalized.cartMapping = normalized.cartMapping && typeof normalized.cartMapping === "object" ? normalized.cartMapping : {};
    normalized.cartMapping.line_hash = normalized.plannerKey;
    normalized.cartMapping.product_id = normalized.productId;
    normalized.cartMapping.quantity = normalized.participants;

    return normalized;
  }

  function readDraft() {
    if (typeof window === "undefined" || typeof window.localStorage === "undefined") {
      return null;
    }
    return safeParse(window.localStorage.getItem(DRAFT_KEY), null);
  }

  function writeDraft(draft) {
    if (typeof window === "undefined" || typeof window.localStorage === "undefined") {
      return;
    }
    window.localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
  }

  function clearDraft() {
    if (typeof window === "undefined" || typeof window.localStorage === "undefined") {
      return;
    }
    window.localStorage.removeItem(DRAFT_KEY);
  }

  function readQueue() {
    if (typeof window === "undefined" || typeof window.sessionStorage === "undefined") {
      return [];
    }
    var parsed = safeParse(window.sessionStorage.getItem(QUEUE_KEY), []);
    return Array.isArray(parsed) ? parsed : [];
  }

  function writeQueue(queue) {
    if (typeof window === "undefined" || typeof window.sessionStorage === "undefined") {
      return;
    }
    window.sessionStorage.setItem(QUEUE_KEY, JSON.stringify(Array.isArray(queue) ? queue : []));
  }

  function createLineItems(items) {
    return items.map(function (item) {
      return {
        id: item.plannerKey,
        product_id: item.productId,
        participants: item.participants,
        line_subtotal: roundCurrency(item.totalCost),
        line_uid: item.plannerKey,
        title: item.title,
        status: item.status,
        schedule: {
          date: item.date,
          start: item.startTime,
          end: item.endTime,
        },
      };
    });
  }

  function buildDraftPayload(items, context) {
    var safeItems = items.map(normalizePlanItem);
    var sortedDates = Array.from(new Set(safeItems.map(function (item) { return item.date; }).filter(Boolean))).sort();
    var days = sortedDates.map(function (date) { return { date: date }; });
    var participants = toPositiveInt(context && context.participants) ||
      toPositiveInt(safeItems[0] && safeItems[0].participants) || 1;
    var date = sanitizeDate(context && context.date) || sortedDates[0] || "";
    var summaryTotal = safeItems.reduce(function (total, item) {
      return total + roundCurrency(item.totalCost);
    }, 0);
    var currency = ((safeItems[0] || {}).pricing || {}).currency || "EUR";

    safeItems.forEach(function (item) {
      item.dayIndex = Math.max(0, days.findIndex(function (day) {
        return day.date === item.date;
      }));
    });

    return {
      plan: {
        id: null,
        editToken: null,
        participants: participants,
        days: days,
        items: safeItems,
      },
      form: {
        date: date,
        participants: String(participants),
      },
      summary: {
        currency: currency,
        grandTotal: roundCurrency(summaryTotal),
        subtotal: roundCurrency(summaryTotal),
        participants: participants,
        items: createLineItems(safeItems),
      },
      timestamp: Date.now(),
    };
  }

  function pushPrefill(entry) {
    var queue = readQueue();
    queue.push(entry);
    writeQueue(queue);
    if (typeof window !== "undefined" && typeof window.dispatchEvent === "function") {
      try {
        window.dispatchEvent(new CustomEvent("sbdp:planner/prefill", { detail: entry }));
      } catch (error) {
        // ignore dispatch errors
      }
    }
  }

  function requestJson(url, options) {
    var settings = options || {};
    var headers = { "Content-Type": "application/json" };
    if (config.nonce) {
      headers["X-WP-Nonce"] = config.nonce;
    }

    return fetch(url, {
      method: settings.method || "GET",
      headers: headers,
      credentials: "same-origin",
      body: settings.body ? JSON.stringify(settings.body) : undefined,
    }).then(function (response) {
      return response.json().catch(function () {
        return {};
      }).then(function (payload) {
        if (!response.ok) {
          throw new Error((payload && payload.message) || "Request failed");
        }
        return payload;
      });
    });
  }

  var store = {
    readDraft: readDraft,
    clearDraft: clearDraft,
    buildDraftPayload: buildDraftPayload,
    syncPlan: function (snapshot) {
      if (!snapshot || !snapshot.plan || !Array.isArray(snapshot.plan.items)) {
        return null;
      }
      var context = {
        date: snapshot.form && snapshot.form.date,
        participants: snapshot.form && snapshot.form.participants,
      };
      var payload = buildDraftPayload(snapshot.plan.items, context);
      payload.timestamp = typeof snapshot.timestamp === "number" ? snapshot.timestamp : payload.timestamp;
      payload.plan.id = snapshot.plan.id || null;
      payload.plan.editToken = snapshot.plan.editToken || null;
      payload.form = clone(snapshot.form || payload.form, payload.form);
      payload.summary = clone(snapshot.summary || payload.summary, payload.summary);
      payload.plan.items = snapshot.plan.items.map(normalizePlanItem);
      writeDraft(payload);
      emitChange({ type: "draft-sync", draft: payload });
      return payload;
    },
    upsertPlanItem: function (item, context) {
      var normalized = normalizePlanItem(item);
      var current = readDraft();
      var items = current && current.plan && Array.isArray(current.plan.items) ? current.plan.items.slice() : [];
      var nextItems = items.map(normalizePlanItem);
      var existingIndex = nextItems.findIndex(function (entry) {
        return entry.plannerKey === normalized.plannerKey;
      });

      if (existingIndex >= 0) {
        nextItems[existingIndex] = Object.assign({}, nextItems[existingIndex], normalized);
      } else {
        nextItems.push(normalized);
      }

      var payload = buildDraftPayload(nextItems, context || {});
      payload.plan.id = current && current.plan ? current.plan.id || null : null;
      payload.plan.editToken = current && current.plan ? current.plan.editToken || null : null;
      writeDraft(payload);
      emitChange({ type: "plan-item-upsert", planItem: normalized, draft: payload });
      return payload;
    },
    updateStatuses: function (statusMap) {
      if (!statusMap || typeof statusMap !== "object") {
        return null;
      }
      var current = readDraft();
      if (!current || !current.plan || !Array.isArray(current.plan.items)) {
        return null;
      }
      var nextItems = current.plan.items.map(function (item) {
        var normalized = normalizePlanItem(item);
        var nextStatus = statusMap[normalized.plannerKey];
        if (typeof nextStatus === "string" && nextStatus.trim()) {
          normalized.status = nextStatus.trim();
        } else if (normalized.status === "in-cart") {
          normalized.status = "planned";
        }
        return normalized;
      });
      var payload = buildDraftPayload(nextItems, current.form || {});
      payload.plan.id = current.plan ? current.plan.id || null : null;
      payload.plan.editToken = current.plan ? current.plan.editToken || null : null;
      writeDraft(payload);
      emitChange({ type: "status-sync", statuses: statusMap, draft: payload });
      return payload;
    },
  };

  var api = {
    evaluate: function (input) {
      return requestJson(config.evaluateUrl, {
        method: "POST",
        body: normalizeInput(input),
      });
    },
    getCartState: function () {
      return requestJson(config.cartStateUrl, { method: "GET" });
    },
    syncCartState: function () {
      return api.getCartState().then(function (payload) {
        var statusMap = {};
        var items = Array.isArray(payload && payload.items) ? payload.items : [];
        items.forEach(function (entry) {
          if (entry && typeof entry.plannerKey === "string" && entry.plannerKey) {
            statusMap[entry.plannerKey] = typeof entry.status === "string" ? entry.status : "in-cart";
          }
        });
        store.updateStatuses(statusMap);
        return payload;
      });
    },
  };

  window.SBDPPlannerDomain = {
    config: config,
    keys: {
      draft: DRAFT_KEY,
      queue: QUEUE_KEY,
      settings: SETTINGS_KEY,
    },
    normalizeInput: normalizeInput,
    normalizePlanItem: normalizePlanItem,
    buildPlannerKey: buildPlannerKey,
    queue: {
      read: readQueue,
      write: writeQueue,
      push: pushPrefill,
      fromPlanItem: function (item, detail) {
        var normalized = normalizePlanItem(item);
        var entry = {
          product_id: normalized.productId,
          productId: normalized.productId,
          date: normalized.date,
          time: normalized.startTime,
          participants: normalized.participants,
          people: normalized.participants,
          resource_id: normalized.resourceId,
          append: !detail || detail.append !== false,
          planItem: normalized,
        };
        pushPrefill(entry);
        return entry;
      },
    },
    store: store,
    api: api,
  };
})();
