const CONTEXT_KEY = "__SBDP_TELEMETRY_CONTEXT_PROVIDER__";

function isClient() {
  return typeof window !== "undefined";
}

function getContextProvider() {
  if (!isClient()) {
    return null;
  }

  const candidate = window[CONTEXT_KEY];
  return typeof candidate === "function" ? candidate : null;
}

function toPrimitive(value) {
  if (value === null || value === undefined) {
    return null;
  }

  if (typeof value === "string") {
    return value.length > 240 ? value.slice(0, 240) : value;
  }

  if (typeof value === "number" || typeof value === "boolean") {
    return value;
  }

  try {
    const serialized = JSON.stringify(value);
    return serialized.length > 240 ? serialized.slice(0, 240) : serialized;
  } catch (error) {
    return String(value);
  }
}

function normalizeEventName(eventName) {
  const source = typeof eventName === "string" ? eventName : "planner_unknown";

  return source
    .replace(/^sbdp:/i, "")
    .replace(/[:/.\-]+/g, "_")
    .replace(/_+/g, "_")
    .replace(/^_+|_+$/g, "")
    .replace(/^planner_/i, "")
    .toLowerCase();
}

function buildAnalyticsPayload(eventName, detail) {
  const payload = {
    event: `planner_${normalizeEventName(eventName)}`,
    planner_event_name: eventName,
    planner_event_source: "day_planner",
  };

  Object.entries(detail || {}).forEach(([key, value]) => {
    const normalizedKey = String(key)
      .replace(/[^a-zA-Z0-9_]/g, "_")
      .replace(/_+/g, "_")
      .replace(/^_+|_+$/g, "")
      .toLowerCase();

    if (!normalizedKey) {
      return;
    }

    payload[normalizedKey] = toPrimitive(value);
  });

  return payload;
}

function pushAnalytics(payload) {
  if (!isClient()) {
    return;
  }

  if (!Array.isArray(window.dataLayer)) {
    window.dataLayer = [];
  }

  window.dataLayer.push(payload);

  if (typeof window.gtag === "function") {
    const { event, ...params } = payload;
    window.gtag("event", event, params);
  }
}

export function setPlannerTelemetryContextProvider(provider) {
  if (!isClient()) {
    return;
  }

  if (typeof provider === "function") {
    window[CONTEXT_KEY] = provider;
    return;
  }

  delete window[CONTEXT_KEY];
}

export function emitPlannerEvent(eventName, detail = {}) {
  if (!isClient() || typeof window.dispatchEvent !== "function") {
    return;
  }

  const contextProvider = getContextProvider();
  const context = contextProvider ? contextProvider() || {} : {};
  const eventDetail = {
    timestamp: Date.now(),
    ...context,
    ...detail,
  };

  window.dispatchEvent(new CustomEvent(eventName, { detail: eventDetail }));
  pushAnalytics(buildAnalyticsPayload(eventName, eventDetail));
}
