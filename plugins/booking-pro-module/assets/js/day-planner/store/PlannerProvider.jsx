import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useReducer,
  useRef,
  useState,
} from "react";
import PropTypes from "prop-types";

import {
  buildDays,
  itemConflicts,
  summarizePlan,
  deriveSlotPricing,
  computeSlotPricing,
} from "../shared/booking.js";
import {
  generateTimeOptions,
  getLocalDateIso,
  minutesToTime,
  timeToMinutes,
} from "../app/utils/time";
import { getProductCategoryTokens } from "../app/utils/search.js";
import { getDurationMinutes } from "../app/utils/products.js";
import { emitPlannerEvent } from "../app/utils/telemetry.js";
import {
  PARTICIPANTS_SOURCE_INHERITED,
  TIME_SOURCE_AUTO,
  TIME_SOURCE_MANUAL,
  applyParticipantsTruthToItem,
  buildAutoTimeFields,
  buildInheritedParticipants,
  buildManualParticipants,
  buildManualTimeFields,
  countCriticalPlannerItemOverlaps,
  filterStartOptionsWithinPlannerHours,
  hasManualParticipantsOverride,
  isHardAvailabilityBlocker,
  isNonDefinitiveAvailabilityIssue,
  resolveParticipantsForItem,
  resolveUserOrder,
  shouldAvailabilityIssueBlockDirectCheckout,
  shouldApplyAvailabilitySuggestedStart,
} from "../app/utils/planner-state.js";
import { createPlannerApi } from "../api/client.js";
import {
  isArrangement,
  buildResolvedBookingPayload,
  materializeResolvedBookingPayload,
  generateArrangementItemsPayload,
} from "../app/utils/arrangement-model.js";
import { buildNonceHeaders } from "../api/client.js";
import PreferenceManager from "../../shared/PreferenceManager.js";

const PlannerContext = createContext(null);

const ACTIONS = {
  CONFIG_REQUEST: "CONFIG_REQUEST",
  CONFIG_SUCCESS: "CONFIG_SUCCESS",
  CONFIG_FAILURE: "CONFIG_FAILURE",
  PRODUCTS_REQUEST: "PRODUCTS_REQUEST",
  PRODUCTS_SUCCESS: "PRODUCTS_SUCCESS",
  PRODUCTS_FAILURE: "PRODUCTS_FAILURE",
  PLAN_REQUEST: "PLAN_REQUEST",
  PLAN_SUCCESS: "PLAN_SUCCESS",
  PLAN_FAILURE: "PLAN_FAILURE",
  SET_FORM_FIELD: "SET_FORM_FIELD",
  START_PLANNING: "START_PLANNING",
  SET_STEP: "SET_STEP",
  SET_FILTERS: "SET_FILTERS",
  ADD_ITEM: "ADD_ITEM",
  UPDATE_ITEM: "UPDATE_ITEM",
  REMOVE_ITEM: "REMOVE_ITEM",
  SET_TOAST: "SET_TOAST",
  CLEAR_TOAST: "CLEAR_TOAST",
  SET_ERROR: "SET_ERROR",
  SET_SUMMARY: "SET_SUMMARY",
  SET_PLAN_METADATA: "SET_PLAN_METADATA",
  SET_PLAN_RANGE: "SET_PLAN_RANGE",
  HYDRATE_DRAFT: "HYDRATE_DRAFT",
  SET_ALTERNATIVES: "SET_ALTERNATIVES",
  CYCLE_ALTERNATIVE: "CYCLE_ALTERNATIVE",
  SET_WIDGET_PREFERENCES: "SET_WIDGET_PREFERENCES",
  CLEAR_PLAN: "CLEAR_PLAN",
  SET_AVAILABILITY_ISSUE: "SET_AVAILABILITY_ISSUE",
  CLEAR_AVAILABILITY_ISSUE: "CLEAR_AVAILABILITY_ISSUE",
};

const PREFILL_LOCK_DEFAULT = false;
const DEFAULT_START_TIME = "09:00";
const DEFAULT_PARTICIPANTS = null;
const BOOKING_CAPABILITY_DIRECT = "DIRECT_ELIGIBLE";
const BOOKING_CAPABILITY_REQUEST = "REQUEST_ONLY";
const PLAN_CHECKOUT_DIRECT = "DIRECT_ELIGIBLE";
const PLAN_CHECKOUT_REQUEST = "REQUEST_ONLY";
const PLAN_CHECKOUT_INCOMPLETE = "INCOMPLETE";
const ROUTE_INTENT_CHECKOUT = "checkout";
const ROUTE_INTENT_QUOTE = "quote";
const ROUTE_INTENT_BLOCKED = "blocked";
const NORMALIZED_CAPABILITY_DIRECT = "DIRECT";
const NORMALIZED_CAPABILITY_DIRECT_LIMITED = "DIRECT_LIMITED";
const NORMALIZED_CAPABILITY_REQUEST = "REQUEST";
const NORMALIZED_CAPABILITY_UNAVAILABLE = "UNAVAILABLE";
const SESSION_PREFILL_KEY = "sbdpPlannerPrefillQueue";
const FILTER_STORAGE_KEY = "sbdpPlannerFilters";
const PLAN_DRAFT_STORAGE_KEY = "sbdpPlannerDraftV1";
const FRESH_PREFILL_BOOTSTRAP_KEY = "sbdpPlannerFreshPrefillBootstrapV1";
const PLAN_RANGE_DAY_COUNTS = { day: 1, weekend: 2, week: 7 };
const VALID_PLAN_RANGES = new Set(Object.keys(PLAN_RANGE_DAY_COUNTS));

const VALID_DURATION_FILTERS = new Set(["all", "short", "medium", "long"]);
const VALID_PRICE_FILTERS = new Set(["all", "budget", "mid", "premium"]);
const VALID_ENVIRONMENT_FILTERS = new Set(["both", "indoor", "outdoor"]);

const DEFAULT_FILTERS = {
  search: "",
  duration: "all",
  category: "all",
  price: "all",
  environment: "both",
};
const DEFAULT_PLAN_RANGE = "day";

function normalizePlanRange(range, allowMulti = true) {
  if (!allowMulti) {
    return DEFAULT_PLAN_RANGE;
  }
  const key = typeof range === "string" ? range.trim().toLowerCase() : "";
  return VALID_PLAN_RANGES.has(key) ? key : DEFAULT_PLAN_RANGE;
}

function dayCountFromRange(range) {
  return PLAN_RANGE_DAY_COUNTS[range] || PLAN_RANGE_DAY_COUNTS[DEFAULT_PLAN_RANGE];
}

function guessPlanRangeFromDayCount(count) {
  if (count >= PLAN_RANGE_DAY_COUNTS.week) {
    return "week";
  }
  if (count >= PLAN_RANGE_DAY_COUNTS.weekend) {
    return "weekend";
  }
  return DEFAULT_PLAN_RANGE;
}

function getTodayIso() {
  try {
    return getLocalDateIso();
  } catch (error) {
    console.warn("[Planner] Failed to resolve current date", error);

    return "";
  }
}

function readSessionPrefillQueue() {
  if (typeof window === "undefined" || typeof window.sessionStorage === "undefined") {
    return [];
  }

  try {
    const raw = window.sessionStorage.getItem(SESSION_PREFILL_KEY);
    if (!raw) {
      return [];
    }

    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch (error) {
    console.warn("[Planner] Failed to read session prefill queue", error);
    return [];
  }
}

function writeSessionPrefillQueue(queue) {
  if (typeof window === "undefined" || typeof window.sessionStorage === "undefined") {
    return;
  }

  try {
    window.sessionStorage.setItem(SESSION_PREFILL_KEY, JSON.stringify(queue || []));
  } catch (error) {
    console.warn("[Planner] Failed to write session prefill queue", error);
  }
}

function clearSessionPrefillQueue() {
  if (typeof window === "undefined" || typeof window.sessionStorage === "undefined") {
    return;
  }

  try {
    window.sessionStorage.removeItem(SESSION_PREFILL_KEY);
  } catch (error) {
    console.warn("[Planner] Failed to clear session prefill queue", error);
  }
}

function sanitizeFilters(source) {
  if (!source || typeof source !== "object") {
    return { ...DEFAULT_FILTERS };
  }

  const search = typeof source.search === "string" ? source.search.trim() : "";
  const duration =
    typeof source.duration === "string" && VALID_DURATION_FILTERS.has(source.duration)
      ? source.duration
      : "all";
  const categoryValue = typeof source.category === "string" ? source.category.trim() : "";
  const category = categoryValue !== "" ? categoryValue : "all";
  const priceValue = typeof source.price === "string" ? source.price.trim() : "";
  const price = VALID_PRICE_FILTERS.has(priceValue) ? priceValue : "all";
  const environmentValue = typeof source.environment === "string" ? source.environment.trim().toLowerCase() : "";
  const environment = VALID_ENVIRONMENT_FILTERS.has(environmentValue) ? environmentValue : "both";

  return {
    search,
    duration,
    category,
    price,
    environment,
  };
}

function readStoredFilters() {
  if (typeof window === "undefined" || typeof window.localStorage === "undefined") {
    return null;
  }

  try {
    const raw = window.localStorage.getItem(FILTER_STORAGE_KEY);
    if (!raw) {
      return null;
    }
    const parsed = JSON.parse(raw);
    return sanitizeFilters(parsed);
  } catch (error) {
    console.warn("[Planner] Failed to read stored filters", error);
    return null;
  }
}

function writeStoredFilters(filters) {
  if (typeof window === "undefined" || typeof window.localStorage === "undefined") {
    return;
  }

  try {
    const payload = JSON.stringify(sanitizeFilters(filters));
    window.localStorage.setItem(FILTER_STORAGE_KEY, payload);
  } catch (error) {
    console.warn("[Planner] Failed to persist filters", error);
  }
}

function readStoredDraft() {
  if (typeof window === "undefined" || typeof window.localStorage === "undefined") {
    return null;
  }

  try {
    const raw = window.localStorage.getItem(PLAN_DRAFT_STORAGE_KEY);
    if (!raw) {
      return null;
    }
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== "object") {
      return null;
    }
    return parsed;
  } catch (error) {
    console.warn("[Planner] Failed to read planner concept", error);
    return null;
  }
}

function writeStoredDraft(draft) {
  if (typeof window === "undefined" || typeof window.localStorage === "undefined") {
    return;
  }
  try {
    window.localStorage.setItem(PLAN_DRAFT_STORAGE_KEY, JSON.stringify(draft));
  } catch (error) {
    console.warn("[Planner] Failed to persist planner concept", error);
  }
}

function clearStoredDraft() {
  if (typeof window === "undefined" || typeof window.localStorage === "undefined") {
    return;
  }
  try {
    window.localStorage.removeItem(PLAN_DRAFT_STORAGE_KEY);
  } catch (error) {
    console.warn("[Planner] Failed to clear planner concept", error);
  }
}

function buildPrefillQueueSignature(rawEntry) {
  const entry = rawEntry && typeof rawEntry === "object" ? rawEntry : {};
  const traceId =
    typeof entry.traceId === "string" && entry.traceId.trim() !== ""
      ? entry.traceId.trim()
      : typeof entry.trace_id === "string" && entry.trace_id.trim() !== ""
      ? entry.trace_id.trim()
      : "";
  if (traceId) {
    return `trace:${traceId}`;
  }

  const productId = toPositiveInt(entry.product_id ?? entry.productId ?? entry.id) ?? 0;
  const date = typeof entry.date === "string" ? entry.date.trim() : "";
  const time = sanitiseTimeString(entry.time ?? entry.start_time ?? entry.start ?? "");
  const participants = toPositiveInt(entry.participants ?? entry.people) ?? 0;
  const resourceId = toPositiveInt(entry.resource_id ?? entry.resourceId) ?? 0;
  const combiItems = normalisePrefillCombiItems(
    entry.combi_items ??
      entry.combiItems ??
      entry.planItem?.options?.combiItems ??
      entry.options?.combiItems ??
      []
  );
  const combiKey = combiItems
    .map((item) => `${item.id}:${item.timing || "before"}:${item.order ?? 0}`)
    .join("|");

  return [productId, date, time, participants, resourceId, combiKey].join("::");
}

function appendUniquePrefillEntry(queue, rawEntry) {
  const entry = rawEntry && typeof rawEntry === "object" ? rawEntry : null;
  if (!entry) {
    return Array.isArray(queue) ? queue : [];
  }

  const nextQueue = Array.isArray(queue) ? [...queue] : [];
  const nextSignature = buildPrefillQueueSignature(entry);
  if (!nextSignature) {
    nextQueue.push(entry);
    return nextQueue;
  }

  const existingIndex = nextQueue.findIndex(
    (queueEntry) => buildPrefillQueueSignature(queueEntry) === nextSignature
  );

  if (existingIndex >= 0) {
    nextQueue[existingIndex] = {
      ...nextQueue[existingIndex],
      ...entry,
    };
    return nextQueue;
  }

  nextQueue.push(entry);
  return nextQueue;
}

function isImmutablePlannerItem(item) {
  if (!item || typeof item !== "object") {
    return false;
  }

  const role = typeof item.role === "string" ? item.role.trim().toLowerCase() : "";
  const source = typeof item.source === "string" ? item.source.trim().toLowerCase() : "";
  const meta = item.meta && typeof item.meta === "object" ? item.meta : {};
  const lockReason =
    typeof item.lockReason === "string"
      ? item.lockReason.trim().toLowerCase()
      : typeof item.lock_reason === "string"
      ? item.lock_reason.trim().toLowerCase()
      : "";

  if (Boolean(meta.is_fixed || meta.locked_slot)) {
    return true;
  }

  if (lockReason === "fixed" || lockReason === "anchor") {
    return true;
  }

  if (role === "anchor" && (item.groupId || item.aggregateId)) {
    return true;
  }

  if (source === "tour-anchor" || source === "fixed-slot" || source === "system-anchor") {
    return true;
  }

  return false;
}

function resolvePlannerItemLocked(item, explicitLocked = null) {
  if (typeof explicitLocked === "boolean") {
    return explicitLocked;
  }

  if (!item || typeof item !== "object") {
    return false;
  }

  return isImmutablePlannerItem(item);
}

function mergeProducts(existing, incoming) {
  const map = new Map();

  (existing || []).forEach((item) => {
    if (!item || typeof item !== "object") {
      return;
    }
    const id = parseInt(item.id ?? item.product_id, 10);
    if (!Number.isFinite(id) || id <= 0) {
      return;
    }
    map.set(id, { ...item, id });
  });

  (incoming || []).forEach((item) => {
    if (!item || typeof item !== "object") {
      return;
    }
    const id = parseInt(item.id ?? item.product_id, 10);
    if (!Number.isFinite(id) || id <= 0) {
      return;
    }
    const previous = map.get(id) || {};
    map.set(id, { ...previous, ...item, id });
  });

  return Array.from(map.values());
}

function createEmptySummary(currency = "EUR") {
  return {
    currency,
    items: [],
    adjustments: [],
    discounts: [],
    taxes: [],
    itemsSubtotal: 0,
    adjustmentsTotal: 0,
    discountTotal: 0,
    taxTotal: 0,
    grandTotal: 0,
    subtotal: 0,
    participants: null,
    breakdown: {
      currency,
      items_count: 0,
      items_subtotal: 0,
      adjustments_total: 0,
      discount_total: 0,
      tax_total: 0,
      grand_total: 0,
    },
  };
}

function sanitiseDateString(value) {
  if (typeof value !== "string") {
    return null;
  }
  const trimmed = value.trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
    return null;
  }
  const date = new Date(`${trimmed}T00:00:00Z`);
  if (Number.isNaN(date.getTime())) {
    return null;
  }
  return trimmed;
}

function sanitiseTimeString(value) {
  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  if (/^(2[0-3]|[01]\d):[0-5]\d$/.test(trimmed)) {
    return trimmed;
  }

  // Be lenient: pick the first HH:MM-like occurrence, even in ranges or ISO strings.
  const match = trimmed.match(/(2[0-3]|[01]?\d)[:.]([0-5]\d)/);
  if (match) {
    const hours = parseInt(match[1], 10);
    const minutes = parseInt(match[2], 10);
    if (Number.isFinite(hours) && Number.isFinite(minutes)) {
      const normalisedHours = String(Math.min(Math.max(hours, 0), 23)).padStart(2, "0");
      const normalisedMinutes = String(Math.min(Math.max(minutes, 0), 59)).padStart(2, "0");
      return `${normalisedHours}:${normalisedMinutes}`;
    }
  }

  return null;
}

function normalisePrefillCombiItems(rawItems) {
  if (!Array.isArray(rawItems) || rawItems.length === 0) {
    return [];
  }

  return rawItems
    .map((rawItem, index) => {
      if (!rawItem || typeof rawItem !== "object") {
        return null;
      }

      const rawId = rawItem.id ?? rawItem.product_id ?? rawItem.productId;
      const id = parseInt(rawId, 10);
      if (!Number.isFinite(id) || id <= 0) {
        return null;
      }

      const timing =
        rawItem.timing === "after" || rawItem.role === "post" ? "after" : "before";
      const durationRaw = rawItem.durationMinutes ?? rawItem.duration ?? null;
      const duration = parseInt(durationRaw, 10);

      return {
        id,
        label: typeof rawItem.label === "string" ? rawItem.label.trim() : "",
        timing,
        role: timing === "after" ? "post" : "pre",
        order:
          Number.isFinite(rawItem.order) && rawItem.order >= 0 ? rawItem.order : index,
        duration: Number.isFinite(duration) && duration > 0 ? duration : null,
        durationMinutes: Number.isFinite(duration) && duration > 0 ? duration : null,
      };
    })
    .filter(Boolean)
    .sort((left, right) => (left.order || 0) - (right.order || 0));
}

function normalisePrefill(raw) {
  if (!raw || typeof raw !== "object") {
    return null;
  }

  const result = {};

  const productId = parseInt(raw.product_id ?? raw.productId, 10);
  if (Number.isFinite(productId) && productId > 0) {
    result.productId = productId;
  }

  const date = sanitiseDateString(
    raw.date ?? raw.start_date ?? raw.slot?.start_date ?? raw.slot?.date
  );
  if (date) {
    result.date = date;
  }

  const time = sanitiseTimeString(
    raw.time ?? raw.start_time ?? raw.slot?.start_time ?? raw.slot?.time
  );
  if (time) {
    result.time = time;
  }

  const peopleValue = raw.people ?? raw.participants ?? raw.count ?? raw.slot?.people ?? raw.slot?.count;
  const people = parseInt(peopleValue, 10);
  if (Number.isFinite(people) && people > 0) {
    result.people = people;
  }

  const resourceValue = raw.resource_id ?? raw.resourceId ?? raw.slot?.resource_id;
  const resourceId = parseInt(resourceValue, 10);
  if (Number.isFinite(resourceId) && resourceId > 0) {
    result.resourceId = resourceId;
  }

  if (typeof raw.combi === "string" && raw.combi.trim() !== "") {
    result.combi = raw.combi.trim();
  }

  if (typeof raw.combi_label === "string" && raw.combi_label.trim() !== "") {
    result.combiLabel = raw.combi_label.trim();
  }

  const combiItems = normalisePrefillCombiItems(
    raw.combi_items ??
      raw.combiItems ??
      (raw.options && typeof raw.options === "object" ? raw.options.combiItems : [])
  );
  if (combiItems.length > 0) {
    result.combiItems = combiItems;
  }

  if (typeof raw.duration === "string" && raw.duration.trim() !== "") {
    result.duration = raw.duration.trim();
  }

  if (typeof raw.audience === "string" && raw.audience.trim() !== "") {
    result.audience = raw.audience.trim();
  }

  if (typeof raw.vibe === "string" && raw.vibe.trim() !== "") {
    result.vibe = raw.vibe.trim();
  }

  const meaningfulKeys = Object.keys(result);
  if (meaningfulKeys.length === 0) {
    return null;
  }

  result.lockFirstSlot =
    raw.lock_first_slot === undefined ? PREFILL_LOCK_DEFAULT : Boolean(raw.lock_first_slot);

  if (raw.source && typeof raw.source === "string" && raw.source.trim() !== "") {
    result.source = raw.source.trim();
  }

  const traceId =
    typeof raw.traceId === "string" && raw.traceId.trim() !== ""
      ? raw.traceId.trim()
      : typeof raw.trace_id === "string" && raw.trace_id.trim() !== ""
      ? raw.trace_id.trim()
      : null;
  if (traceId) {
    result.traceId = traceId;
    result.trace_id = traceId;
  }

  return result;
}

function resolveExplicitPrefillParticipants(prefill) {
  if (!prefill || typeof prefill !== "object") {
    return null;
  }

  return toPositiveInt(prefill.people ?? prefill.participants ?? prefill.count);
}

function buildFreshPrefillIdentity(prefill) {
  if (!prefill || typeof prefill !== "object") {
    return "";
  }

  const traceId =
    typeof prefill.traceId === "string" && prefill.traceId.trim() !== ""
      ? prefill.traceId.trim()
      : typeof prefill.trace_id === "string" && prefill.trace_id.trim() !== ""
      ? prefill.trace_id.trim()
      : "";

  if (traceId) {
    return `trace:${traceId}`;
  }

  const productId = toPositiveInt(prefill.productId) ?? 0;
  const date = typeof prefill.date === "string" ? prefill.date.trim() : "";
  const time = sanitiseTimeString(prefill.time);
  const people = resolveExplicitPrefillParticipants(prefill) ?? 0;
  const combiKey = Array.isArray(prefill.combiItems)
    ? prefill.combiItems
        .map((entry) => `${toPositiveInt(entry?.id) ?? 0}:${entry?.timing || ""}:${entry?.order ?? ""}`)
        .join("|")
    : "";

  return [productId, date, time, people, combiKey].join("::");
}

function draftMatchesFreshPrefill(draft, prefill) {
  if (!draft || typeof draft !== "object" || !prefill || typeof prefill !== "object") {
    return false;
  }

  const planItems = Array.isArray(draft?.plan?.items) ? draft.plan.items : [];
  if (planItems.length === 0) {
    return false;
  }

  const prefillProductId = toPositiveInt(prefill.productId);
  if (prefillProductId === null) {
    return false;
  }

  const prefillDate = typeof prefill.date === "string" ? prefill.date.trim() : "";
  const prefillTime = sanitiseTimeString(prefill.time);
  const prefillParticipants = resolveExplicitPrefillParticipants(prefill);
  const canonicalDraftParticipants = toPositiveInt(draft?.plan?.participants);
  const draftFormParticipants = toPositiveInt(draft?.form?.participants);

  if (prefillParticipants !== null) {
    if (canonicalDraftParticipants !== null && canonicalDraftParticipants !== prefillParticipants) {
      return false;
    }

    if (draftFormParticipants !== null && draftFormParticipants !== prefillParticipants) {
      return false;
    }
  }

  return planItems.some((item) => {
    const itemProductId = toPositiveInt(item?.productId ?? item?.product_id);
    if (itemProductId !== prefillProductId) {
      return false;
    }

    const itemDate = typeof item?.date === "string" ? item.date.trim() : "";
    if (prefillDate && itemDate && itemDate !== prefillDate) {
      return false;
    }

    const itemStartTime = sanitiseTimeString(item?.startTime ?? item?.start ?? "");
    if (prefillTime && itemStartTime && itemStartTime !== prefillTime) {
      return false;
    }

    if (prefillParticipants !== null) {
      const itemParticipants = toPositiveInt(item?.participants);
      if (itemParticipants !== null && itemParticipants !== prefillParticipants) {
        return false;
      }
    }

    return true;
  });
}

function resolvePlannerTraceId(raw) {
  if (!raw || typeof raw !== "object") {
    return "";
  }

  const directTraceId =
    typeof raw.traceId === "string" && raw.traceId.trim() !== ""
      ? raw.traceId.trim()
      : typeof raw.trace_id === "string" && raw.trace_id.trim() !== ""
      ? raw.trace_id.trim()
      : "";
  if (directTraceId) {
    return directTraceId;
  }

  const plannerInput = raw.plannerInput && typeof raw.plannerInput === "object" ? raw.plannerInput : null;
  if (!plannerInput) {
    return "";
  }

  return typeof plannerInput.traceId === "string" && plannerInput.traceId.trim() !== ""
    ? plannerInput.traceId.trim()
    : typeof plannerInput.trace_id === "string" && plannerInput.trace_id.trim() !== ""
    ? plannerInput.trace_id.trim()
    : "";
}

function buildArrangementCombiKey(rawCombiItems, resolutionSegments = []) {
  const explicitItems = normalisePrefillCombiItems(rawCombiItems);
  if (explicitItems.length > 0) {
    return explicitItems
      .map((item) => `${toPositiveInt(item?.id) ?? 0}:${item?.timing || ""}:${item?.order ?? ""}`)
      .join("|");
  }

  const segments = Array.isArray(resolutionSegments) ? resolutionSegments : [];
  return segments
    .filter((segment) => segment && segment.role && segment.role !== "anchor")
    .map((segment) => {
      const productId = toPositiveInt(segment.product_id ?? segment.productId ?? segment.id) ?? 0;
      const timing = typeof segment.timing === "string" ? segment.timing : segment.role === "post" ? "after" : "before";
      const order = Number.isFinite(segment.order) ? segment.order : "";
      return `${productId}:${timing}:${order}`;
    })
    .join("|");
}

function buildArrangementIntentSignature(sourceItem, resolution, rawCombiItems = []) {
  const safeSource = sourceItem && typeof sourceItem === "object" ? sourceItem : {};
  const safeResolution = resolution && typeof resolution === "object" ? resolution : {};
  const traceId = resolvePlannerTraceId(safeSource);
  if (traceId) {
    return `trace:${traceId}`;
  }

  const productId =
    toPositiveInt(
      safeResolution.source_product_id ??
        safeSource.productId ??
        safeSource.product_id ??
        safeSource.bookingResolution?.source_product_id
    ) ?? 0;
  const bookingDate =
    typeof safeResolution.booking_date === "string" && safeResolution.booking_date.trim() !== ""
      ? safeResolution.booking_date.trim()
      : typeof safeSource.date === "string"
      ? safeSource.date.trim()
      : "";
  const anchorSegment = Array.isArray(safeResolution.segments)
    ? safeResolution.segments.find((segment) => segment && segment.role === "anchor")
    : null;
  const startTime = sanitiseTimeString(
    safeSource.startTime ??
      safeSource.time ??
      safeSource.start ??
      anchorSegment?.startTime ??
      ""
  );
  const participants =
    toPositiveInt(safeResolution.participants ?? safeSource.participants ?? safeSource.people) ?? 0;
  const resourceId =
    toPositiveInt(safeSource.resourceId ?? safeSource.resource_id ?? safeSource.plannerInput?.resourceId) ?? 0;
  const combiKey = buildArrangementCombiKey(rawCombiItems, safeResolution.segments);

  if (!productId || !bookingDate) {
    return "";
  }

  return ["arrangement", productId, bookingDate, startTime, participants, resourceId, combiKey].join("::");
}

function hasMatchingArrangementIntent(existingItems, nextSourceItem, nextResolution, nextCombiItems = []) {
  const nextSignature = buildArrangementIntentSignature(nextSourceItem, nextResolution, nextCombiItems);
  if (!nextSignature) {
    return false;
  }

  const items = Array.isArray(existingItems) ? existingItems : [];
  const seenGroups = new Set();

  return items.some((item) => {
    if (!item || typeof item !== "object") {
      return false;
    }

    const groupId =
      typeof item.groupId === "string" && item.groupId.trim() !== ""
        ? item.groupId.trim()
        : typeof item.bookingResolution?.groupId === "string" && item.bookingResolution.groupId.trim() !== ""
        ? item.bookingResolution.groupId.trim()
        : "";
    if (groupId) {
      if (seenGroups.has(groupId)) {
        return false;
      }
      seenGroups.add(groupId);
    }

    const existingSignature = buildArrangementIntentSignature(
      item,
      item.bookingResolution,
      item.combiItems ?? item.options?.combiItems ?? []
    );
    return existingSignature !== "" && existingSignature === nextSignature;
  });
}

/**
 * Resolve theme from prefill hints using scoring system
 * Combines duration, audience, and vibe for smart suggestions
 */
function resolveThemeFromHints(prefill) {
  if (!prefill || typeof prefill !== "object") {
    return null;
  }

  const vibe = typeof prefill.vibe === "string" ? prefill.vibe.trim().toLowerCase() : "";
  const audience = typeof prefill.audience === "string" ? prefill.audience.trim().toLowerCase() : "";
  const duration = typeof prefill.duration === "string" ? prefill.duration.trim().toLowerCase() : "";

  // Initialize theme scores
  const scores = {
    bourgondisch: 0,
    actief: 0,
    teambuilding: 0,
    mystiek: 0,
    mix: 0
  };

  // 🎯 Vibe scoring (primary influence - weight 3)
  if (vibe) {
    if (vibe.includes("bourgondisch") || vibe.includes("eten") || vibe.includes("food")) {
      scores.bourgondisch += 5;
    }
    if (vibe.includes("cultuur") || vibe.includes("museum") || vibe.includes("histor")) {
      scores.mystiek += 5;
    }
    if (vibe.includes("klassiek")) {
      scores.mystiek += 4;
    }
    if (vibe.includes("actief")) {
      scores.actief += 5;
      if (audience.includes("collega")) {
        scores.teambuilding += 2;
      }
    }
    if (vibe.includes("relaxed")) {
      scores.mix += 3;
      scores.bourgondisch += 2;
    }
    if (vibe.includes("shop")) {
      scores.bourgondisch += 3;
      scores.mix += 1;
    }
    if (vibe.includes("kidsproof") || vibe.includes("kids")) {
      scores.mix += 4;
      scores.actief += 2;
    }
    if (vibe.includes("verrassend") || vibe.includes("outdoor")) {
      scores.actief += 5;
      if (audience.includes("collega") || audience.includes("team")) {
        scores.teambuilding += 3; // tilt surprise for colleagues toward teambuilding
      }
    }
  }

  // 👥 Audience scoring (secondary influence - weight 2)
  if (audience) {
    if (audience.includes("collega") || audience.includes("team")) {
      scores.teambuilding += 8; // steer clearly to teambuilding
      scores.actief += 0;
    }
    if (audience.includes("gezin") || audience.includes("kids") || audience.includes("familie")) {
      scores.mix += 4;
      scores.actief += 2;
    }
    if (audience.includes("vriend")) {
      scores.actief += 4;
      scores.bourgondisch += 1;
    }
    if (audience.includes("partner")) {
      scores.bourgondisch += 4;
      scores.mystiek += 2;
    }
    if (audience.includes("solo")) {
      scores.mystiek += 4;
      scores.mix += 1;
    }
    if (audience.includes("gemengd")) {
      scores.mix += 3;
      scores.bourgondisch += 1;
    }
  }

  // ⏱️ Duration influence (tertiary - weight 1)
  if (duration) {
    if (duration.includes("ochtend") || duration.includes("middag")) {
      scores.mystiek += 1;  // Short cultuur visits
      scores.actief += 1;
    }
    if (duration.includes("hele-dag")) {
      scores.actief += 2;   // Full day outdoor activities
      scores.mix += 1;
    }
    if (duration.includes("avond")) {
      scores.bourgondisch += 3;  // Evening dining/drinks
    }
    if (duration.includes("weekend")) {
      scores.mix += 2;      // Variety for weekend
      scores.actief += 1;
    }
  }

  // 🎲 Combination bonuses
  if (vibe.includes("verrassend") && (audience.includes("collega") || audience.includes("team"))) {
    scores.teambuilding += 8; // push colleagues+surprise towards teambuilding
    scores.actief -= 4;       // soften actief tie
  }
  if (vibe.includes("cultuur") && audience.includes("gezin")) {
    scores.mystiek += 2;
    scores.mix += 2;  // Family-friendly culture
  }
  if (vibe.includes("verrassend") && audience.includes("partner")) {
    scores.actief += 2;
    scores.bourgondisch += 1;  // Romantic surprise
  }

  // Find highest score
  const entries = Object.entries(scores).sort((a, b) => b[1] - a[1]);
  const topScore = entries[0][1];
  
  // Return null if no meaningful score (all zeros)
  if (topScore === 0) {
    return null;
  }

  // Return top theme
  return entries[0][0];
}

function resolvePrefillStartTime(prefillTime, product, config) {
  return (
    sanitiseTimeString(prefillTime) ||
    sanitiseTimeString(product?.default_start_time ?? product?.defaultStartTime ?? "") ||
    null
  );
}

function buildAvailabilitySlotsUrl(restBase) {
  const base = sanitiseBase(restBase);
  if (!base) {
    return "";
  }

  return base.replace(/\/planner\/v1$/i, "/sbdp/v1/availability/slots");
}

function buildBookableStartOptions(slots, durationMinutes) {
  if (!Array.isArray(slots) || slots.length === 0) {
    return [];
  }

  const firstSlot = slots[0] || {};
  let slotLength = 0;

  if (firstSlot.start && firstSlot.end) {
    slotLength = timeToMinutes(String(firstSlot.end)) - timeToMinutes(String(firstSlot.start));
  }

  if (!Number.isFinite(slotLength) || slotLength <= 0) {
    slotLength = Math.max(15, Math.min(60, durationMinutes || 60));
  }

  const required = Math.max(1, Math.ceil(durationMinutes / slotLength));
  const starts = [];
  const startSet = new Set();

  slots.forEach((slot) => {
    const slotStart = sanitiseTimeString(slot?.start);
    if (!slotStart) {
      return;
    }

    const startMinutes = timeToMinutes(slotStart);
    if (Number.isFinite(startMinutes)) {
      startSet.add(startMinutes);
      starts.push(startMinutes);
    }
  });

  starts.sort((a, b) => a - b);

  return starts.filter((startMinutes) => {
    for (let index = 1; index < required; index += 1) {
      const nextStart = startMinutes + index * slotLength;
      if (!startSet.has(nextStart)) {
        return false;
      }
    }
    return true;
  });
}

function buildProductQuery(prefill, queue) {
  const ids = new Set();

  if (prefill && prefill.productId) {
    ids.add(prefill.productId);
  }

  if (Array.isArray(queue)) {
    queue.forEach((entry) => {
      if (!entry) {
        return;
      }
      const rawId = entry.product_id ?? entry.productId ?? entry.id;
      const parsed = Number.parseInt(rawId, 10);
      if (Number.isFinite(parsed) && parsed > 0) {
        ids.add(parsed);
      }
      
      const combis = entry.planItem?.options?.combiItems ?? entry.options?.combiItems ?? entry.combiItems ?? [];
      if (Array.isArray(combis)) {
        combis.forEach((combi) => {
          const combiId = Number.parseInt(combi?.id ?? combi?.product_id, 10);
          if (Number.isFinite(combiId) && combiId > 0) {
            ids.add(combiId);
          }
        });
      }
    });
  }

  if (ids.size === 0) {
    return "";
  }

  // Only pass include[] so the server guarantees these products appear in the
  // catalogue. Do NOT pass match_by[]/primary_product — that caused the server
  // to filter the *entire* catalogue down to only products matching the primary
  // product's location+category, hiding all other available activities when the
  // user had a saved plan with 1-2 items.
  const params = [];
  ids.forEach((id) => {
    params.push(`include[]=${encodeURIComponent(id)}`);
  });

  return params.join("&");
}

function resolvePlannerItemDate(state, dayIndex = 0) {
  const dayDate =
    typeof state.plan?.days?.[dayIndex]?.date === "string"
      ? state.plan.days[dayIndex].date.trim()
      : "";
  const formDate = typeof state.form?.date === "string" ? state.form.date.trim() : "";
  return dayDate || formDate;
}

function hasBookingDateMissing(item) {
  const errors = [
    ...(Array.isArray(item?.errors) ? item.errors : []),
    ...(Array.isArray(item?.bookingResolution?.errors) ? item.bookingResolution.errors : []),
  ];
  return errors.includes("booking_date_missing");
}

function applyPlannerItemDateTruth(state, item) {
  if (!item || typeof item !== "object") {
    return item;
  }

  const rawDayIndex =
    typeof item.dayIndex === "number" ? item.dayIndex : Number.parseInt(item.dayIndex, 10);
  const dayIndex = Number.isFinite(rawDayIndex) ? rawDayIndex : 0;
  const resolvedDate = resolvePlannerItemDate(state, dayIndex);
  if (!resolvedDate) {
    return item;
  }

  const plannerInput =
    item.plannerInput && typeof item.plannerInput === "object" ? item.plannerInput : {};
  let nextItem =
    item.date === resolvedDate && plannerInput.date === resolvedDate
      ? item
      : {
          ...item,
          date: resolvedDate,
          plannerInput: {
            ...plannerInput,
            date: resolvedDate,
          },
        };

  if (!hasBookingDateMissing(nextItem)) {
    return nextItem;
  }

  nextItem = {
    ...nextItem,
    errors: Array.isArray(nextItem.errors)
      ? nextItem.errors.filter((error) => error !== "booking_date_missing")
      : [],
  };

  const combiItems = normalisePrefillCombiItems(
    nextItem.options?.combiItems ?? nextItem.combiItems ?? []
  );
  const resolution = buildResolvedBookingPayload(nextItem, combiItems);
  const anchorSegment = Array.isArray(resolution.segments)
    ? resolution.segments.find((segment) => segment?.role === "anchor") || resolution.segments[0]
    : null;
  const nextErrors = Array.from(
    new Set([...(resolution.errors || []), ...(anchorSegment?.errors || [])])
  );
  const nextWarnings = Array.from(
    new Set([...(resolution.warnings || []), ...(anchorSegment?.warnings || [])])
  );

  return {
    ...nextItem,
    bookingResolution: resolution,
    errors: nextErrors,
    warnings: nextWarnings,
    status:
      nextItem.status === "error" && resolution.status === "valid"
        ? "confirmed"
        : nextItem.status,
  };
}

const initialState = {
  step: "info",
  config: null,
  configSource: null,
  configDegraded: false,
  configError: null,
  loading: {
    config: true,
    products: false,
    plan: false,
    save: false,
  },
  error: null,
  toast: null,
  availabilityIssue: null,
  timeOptions: [],
  form: {
    date: "",
    participants: "",
  },
  filters: { ...DEFAULT_FILTERS },
  planRange: DEFAULT_PLAN_RANGE,
  products: [],
  plan: {
    id: null,
    editToken: null,
    participants: null,
    days: [],
    items: [],
    planCheckoutCapability: null,
  },
  summary: createEmptySummary(),
  draftStatus: {
    restored: false,
    timestamp: null,
  },
  alternatives: {
    bySlot: {},
    currentIndex: {},
  },
  widgetPreferences: {
    visitDate: null,
    duration: null,      // 'ochtend', 'middag', 'avond', 'hele-dag', 'weekend'
    count: 2,
    audience: null,      // 'partner', 'gezin', 'vrienden', 'collegas', 'solo'
    vibe: null,          // 'cultuur', 'gezellig', 'actief', etc.
  },
};

function normaliseBootProducts(source) {
  return Array.isArray(source) ? source.filter((item) => item && typeof item === "object") : [];
}

function isPlainObject(value) {
  return Boolean(value) && typeof value === "object" && !Array.isArray(value);
}

function isValidPlannerConfig(config) {
  if (!isPlainObject(config)) {
    return false;
  }

  const openHours = config.open_hours;
  const start = typeof openHours?.start === "string" ? openHours.start.trim() : "";
  const end = typeof openHours?.end === "string" ? openHours.end.trim() : "";
  const stepMinutes = Number.parseInt(config.time_step_minutes, 10);

  if (!start || !end || !/^(2[0-3]|[01]\d):[0-5]\d$/.test(start) || !/^(2[0-3]|[01]\d):[0-5]\d$/.test(end)) {
    return false;
  }

  return Number.isFinite(stepMinutes) && stepMinutes > 0;
}

function plannerReducer(state, action) {
  switch (action.type) {
    case ACTIONS.CONFIG_REQUEST:
      return {
        ...state,
        loading: { ...state.loading, config: true },
        error: null,
      };
    case ACTIONS.CONFIG_SUCCESS: {
      const {
        config,
        timeOptions,
        configSource = "rest",
        configDegraded = false,
        configError = null,
      } = action.payload;
      const currency = (config && config.currency) || "EUR";
      const allowMulti = Boolean(config?.allow_multi_day);
      const defaultDayCount = Number.parseInt(config?.default_day_count, 10);
      const inferredRange = Number.isFinite(defaultDayCount)
        ? guessPlanRangeFromDayCount(defaultDayCount)
        : state.planRange;
      const planRange = allowMulti ? inferredRange : DEFAULT_PLAN_RANGE;
      return {
        ...state,
        config,
        configSource,
        configDegraded,
        configError,
        timeOptions,
        planRange,
        loading: { ...state.loading, config: false },
        summary: {
          ...state.summary,
          currency,
          breakdown: {
            ...state.summary.breakdown,
            currency,
          },
        },
      };
    }
    case ACTIONS.CONFIG_FAILURE:
      return {
        ...state,
        configSource: null,
        configDegraded: false,
        configError: action.payload?.message || "Kon planner instellingen niet laden.",
        loading: { ...state.loading, config: false },
        error: action.payload?.message || "Kon planner instellingen niet laden.",
      };
    case ACTIONS.PRODUCTS_REQUEST:
      return {
        ...state,
        loading: { ...state.loading, products: true },
        error: null,
      };
    case ACTIONS.PRODUCTS_SUCCESS: {
      const incoming = Array.isArray(action.payload?.products) ? action.payload.products : [];
      const shouldAppend = Boolean(action.payload?.append);
      const products = shouldAppend ? mergeProducts(state.products, incoming) : incoming;
      return {
        ...state,
        loading: { ...state.loading, products: false },
        products,
      };
    }
    case ACTIONS.PRODUCTS_FAILURE:
      return {
        ...state,
        loading: { ...state.loading, products: false },
        error: action.payload?.message || "Kon activiteiten niet laden.",
      };
    case ACTIONS.PLAN_REQUEST:
      return {
        ...state,
        loading: { ...state.loading, plan: true },
        error: null,
      };
    case ACTIONS.PLAN_FAILURE:
      return {
        ...state,
        loading: { ...state.loading, plan: false },
        error: action.payload?.message || "Kon bestaand plan niet laden.",
      };
    case ACTIONS.PLAN_SUCCESS: {
      const { plan, form, summary } = action.payload;
      return {
        ...state,
        step: plan.items.length > 0 ? "layout" : state.step,
        loading: { ...state.loading, plan: false },
        plan: {
          ...state.plan,
          id: plan.id ?? state.plan.id,
          editToken: plan.editToken ?? state.plan.editToken,
          participants: plan.participants ?? state.plan.participants,
          days: plan.days.length > 0 ? plan.days : state.plan.days,
          items: plan.items,
          planCheckoutCapability: plan.planCheckoutCapability ?? state.plan.planCheckoutCapability,
        },
        form: {
          ...state.form,
          date: form.date ?? state.form.date,
          participants: form.participants ?? state.form.participants,
        },
        summary: summary || state.summary,
      };
    }
    case ACTIONS.SET_FORM_FIELD:
      return {
        ...state,
        form: {
          ...state.form,
          [action.payload.field]: action.payload.value,
        },
      };
    case ACTIONS.START_PLANNING: {
      const { date, participants, config } = action.payload;
      const nextParticipants = toPositiveInt(participants);
      const allowMulti = Boolean(config?.allow_multi_day);
      const requestedRange = normalizePlanRange(state.planRange, allowMulti);
      const dayCount = allowMulti ? dayCountFromRange(requestedRange) : dayCountFromRange(DEFAULT_PLAN_RANGE);
      const days = buildDays(date, dayCount);
      const existingItems = Array.isArray(state.plan.items) ? state.plan.items : [];
      let nextItems = existingItems;

      if (existingItems.length > 0 && days.length > 0) {
        const maxDayIndex = days.length - 1;
        nextItems = existingItems.map((item, index) => {
          const rawIndex =
            typeof item?.dayIndex === "number"
              ? item.dayIndex
              : Number.parseInt(item?.dayIndex, 10);
          const normalized = Number.isFinite(rawIndex) ? rawIndex : 0;
          const safeIndex = Math.min(Math.max(0, normalized), maxDayIndex);
          const itemWithParticipants =
            nextParticipants !== null
              ? applyParticipantsTruthToItem(item, nextParticipants, DEFAULT_PARTICIPANTS)
              : item;

          if (
            safeIndex === item?.dayIndex &&
            itemWithParticipants === item &&
            resolveUserOrder(item, index) === item?.user_order
          ) {
            return item;
          }

          return {
            ...itemWithParticipants,
            dayIndex: safeIndex,
            user_order: resolveUserOrder(item, index),
          };
        });
      }

      const baseState = {
        ...state,
        step: "layout",
        planRange: requestedRange,
        plan: {
          ...state.plan,
          participants: nextParticipants ?? participants,
          days,
        },
      };

      if (nextItems.length === 0) {
        return applyItemsUpdate(baseState, []);
      }

      return applyItemsUpdate(
        baseState,
        nextItems.map((item) => applyPlannerItemDateTruth(baseState, item))
      );
    }
    case ACTIONS.SET_STEP:
      return {
        ...state,
        step: action.payload.step,
      };
    case ACTIONS.SET_FILTERS:
      return {
        ...state,
        filters: {
          ...state.filters,
          ...action.payload,
        },
      };
    case ACTIONS.SET_PLAN_RANGE: {
      const allowMulti = Boolean(state.config?.allow_multi_day);
      const requested = action.payload?.range;
      const nextRange = normalizePlanRange(requested, allowMulti);
      if (state.planRange === nextRange) {
        return state;
      }
      const dayCount = allowMulti ? dayCountFromRange(nextRange) : dayCountFromRange(DEFAULT_PLAN_RANGE);
      const anchorDate = state.plan?.days?.[0]?.date || state.form?.date || getTodayIso();
      const days = buildDays(anchorDate, dayCount);
      const maxDayIndex = Math.max(0, days.length - 1);
      const dateState = {
        ...state,
        plan: {
          ...state.plan,
          days,
        },
      };
      const nextItems = (state.plan.items || []).map((item) => {
        const parsedIndex =
          typeof item?.dayIndex === "number" ? item.dayIndex : Number.parseInt(item?.dayIndex, 10);
        const normalizedIndex = Number.isFinite(parsedIndex) ? parsedIndex : 0;
        const clampedIndex = Math.min(Math.max(0, normalizedIndex), maxDayIndex);
        const nextItem = clampedIndex === item?.dayIndex ? item : {
          ...item,
          dayIndex: clampedIndex,
        };
        return applyPlannerItemDateTruth(dateState, nextItem);
      });
      return applyItemsUpdate(
        {
          ...dateState,
          planRange: nextRange,
        },
        nextItems
      );
    }
    case ACTIONS.ADD_ITEM: {
      const itemsToAdd = Array.isArray(action.payload.items) ? action.payload.items : [action.payload.item];
      const existingIds = new Set(state.plan.items.map((item) => item.id));
      // Idempotency guard for queue + critical-add double dispatch.
      const deduped = itemsToAdd.filter((item) => item && item.id && !existingIds.has(item.id));
      if (deduped.length === 0) {
        return state;
      }
      const maxOrder = state.plan.items.reduce(
        (max, item, index) => Math.max(max, resolveUserOrder(item, index)),
        0
      );
      const canonicalParticipants = selectCanonicalParticipants(state, { allowFormFallback: true });
      const preparedItems = deduped.map((item, index) =>
        applyPlannerItemDateTruth(state, {
          ...applyParticipantsTruthToItem(item, canonicalParticipants, DEFAULT_PARTICIPANTS),
          user_order: resolveUserOrder(item, maxOrder + index),
          ...(
            item?.manual_locked === true || item?.time_source === TIME_SOURCE_MANUAL
              ? buildManualTimeFields()
              : {
                  manual_locked: item?.manual_locked === true,
                  time_source: item?.time_source || TIME_SOURCE_AUTO,
                }
          ),
        })
      );
      const nextItems = [...state.plan.items, ...preparedItems];
      return applyItemsUpdate(state, nextItems);
    }
    case ACTIONS.UPDATE_ITEM: {
      const sourceItem = state.plan.items.find(i => i.id === action.payload.id);
      if (!sourceItem) return state;

      const shift = action.payload.changes.startMinutes !== undefined 
        ? action.payload.changes.startMinutes - sourceItem.startMinutes 
        : 0;
      const nextParticipants = toPositiveInt(action.payload.changes.participants);

      const nextItems = state.plan.items.map((item) => {
        if (item.id === action.payload.id) {
          return { ...item, ...action.payload.changes };
        }
        // If part of the same arrangement group, and time shifted, shift it too
        if (shift !== 0 && sourceItem.groupId && item.groupId === sourceItem.groupId) {
          const newStartMinutes = item.startMinutes + shift;
          return {
            ...item,
            startMinutes: newStartMinutes,
            endMinutes: newStartMinutes + (item.durationMinutes || (item.endMinutes - item.startMinutes)),
            startTime: minutesToTime(newStartMinutes),
            endTime: minutesToTime(newStartMinutes + (item.durationMinutes || (item.endMinutes - item.startMinutes))),
            ...(action.payload.changes?.time_source === TIME_SOURCE_MANUAL ? buildManualTimeFields() : {}),
          };
        }
        if (
          nextParticipants !== null &&
          sourceItem.groupId &&
          item.groupId === sourceItem.groupId
        ) {
          const itemTotalCost = resolveSlotPricing(item, nextParticipants);
          return {
            ...item,
            participants: nextParticipants,
            ...(action.payload.changes?.participants_source === "manual_override"
              ? buildManualParticipants(nextParticipants, DEFAULT_PARTICIPANTS)
              : {}),
            totalCost: itemTotalCost.total,
            price_pp: itemTotalCost.perPerson,
            fixedCost: itemTotalCost.fixedCost,
          };
        }
        return item;
      });
      return applyItemsUpdate(state, nextItems);
    }
    case ACTIONS.REMOVE_ITEM: {
      const sourceItem = state.plan.items.find(i => i.id === action.payload.id);
      let nextItems;
      if (sourceItem?.groupId) {
        nextItems = state.plan.items.filter((item) => item.groupId !== sourceItem.groupId);
      } else {
        nextItems = state.plan.items.filter((item) => item.id !== action.payload.id);
      }
      return applyItemsUpdate(state, nextItems);
    }
    case ACTIONS.SET_TOAST:
      return {
        ...state,
        toast: action.payload?.message || null,
      };
    case ACTIONS.CLEAR_TOAST:
      return {
        ...state,
        toast: null,
      };
    case ACTIONS.SET_ERROR:
      return {
        ...state,
        error: action.payload?.message || null,
      };
    case ACTIONS.SET_AVAILABILITY_ISSUE:
      return {
        ...state,
        availabilityIssue: {
          message: action.payload?.message || "Tijdslot is niet meer beschikbaar.",
          source: action.payload?.source || "availability",
          reasonCode: action.payload?.reasonCode || null,
          timestamp: action.payload?.timestamp || Date.now(),
        },
      };
    case ACTIONS.CLEAR_AVAILABILITY_ISSUE:
      return {
        ...state,
        availabilityIssue: null,
      };
    case ACTIONS.SET_SUMMARY:
      return {
        ...state,
        summary: action.payload.summary,
      };
    case ACTIONS.SET_PLAN_METADATA: {
      const planId = toPositiveInt(action.payload?.id);
      const rawToken = typeof action.payload?.editToken === "string" ? action.payload.editToken : null;
      const editToken =
        rawToken && rawToken.trim() !== "" ? rawToken.trim() : state.plan.editToken;

      return {
        ...state,
        plan: {
          ...state.plan,
          id: planId !== null ? planId : state.plan.id,
          editToken,
        },
      };
    }
    case ACTIONS.HYDRATE_DRAFT: {
      const incomingPlan = action.payload?.plan && typeof action.payload.plan === "object" ? action.payload.plan : null;
      const incomingForm = action.payload?.form && typeof action.payload.form === "object" ? action.payload.form : null;
      const incomingSummary =
        action.payload?.summary && typeof action.payload.summary === "object" ? action.payload.summary : null;
      const planItems = Array.isArray(incomingPlan?.items) && incomingPlan.items.length > 0 ? incomingPlan.items : state.plan.items;
      const planDays = Array.isArray(incomingPlan?.days) && incomingPlan.days.length > 0 ? incomingPlan.days : state.plan.days;
      const participants =
        toPositiveInt(incomingPlan?.participants) ?? state.plan.participants;
      const planId = toPositiveInt(incomingPlan?.id) ?? state.plan.id;
      const editToken =
        typeof incomingPlan?.editToken === "string" && incomingPlan.editToken.trim() !== ""
          ? incomingPlan.editToken.trim()
          : state.plan.editToken;

      return {
        ...state,
        step: planItems.length > 0 ? "layout" : state.step,
        form: incomingForm
          ? {
              ...state.form,
              ...incomingForm,
            }
          : state.form,
        plan: {
          ...state.plan,
          id: planId,
          editToken,
          participants,
          days: planDays,
          items: planItems,
        },
        summary: incomingSummary ? { ...state.summary, ...incomingSummary } : state.summary,
        availabilityIssue:
          action.payload?.availabilityIssue && typeof action.payload.availabilityIssue === "object"
            ? {
                message:
                  action.payload.availabilityIssue.message || "Tijdslot is niet meer beschikbaar.",
                source: action.payload.availabilityIssue.source || "availability",
                reasonCode: action.payload.availabilityIssue.reasonCode || null,
                timestamp: action.payload.availabilityIssue.timestamp || Date.now(),
              }
            : state.availabilityIssue,
        draftStatus: {
          restored: true,
          timestamp: action.payload?.timestamp || Date.now(),
        },
      };
    }
    
    case ACTIONS.SET_ALTERNATIVES:
      console.log('[Reducer] SET_ALTERNATIVES:', action.payload.slotKey, action.payload.alternatives.length);
      return {
        ...state,
        alternatives: {
          ...state.alternatives,
          bySlot: {
            ...state.alternatives.bySlot,
            [action.payload.slotKey]: action.payload.alternatives,
          },
          currentIndex: {
            ...state.alternatives.currentIndex,
            [action.payload.slotKey]: 0,
          },
        },
      };
    
    case ACTIONS.CYCLE_ALTERNATIVE: {
      const { slotKey } = action.payload;
      const currentIdx = state.alternatives.currentIndex[slotKey] || 0;
      const alternatives = state.alternatives.bySlot[slotKey] || [];
      const nextIdx = (currentIdx + 1) % alternatives.length;
      
      return {
        ...state,
        alternatives: {
          ...state.alternatives,
          currentIndex: {
            ...state.alternatives.currentIndex,
            [slotKey]: nextIdx,
          },
        },
      };
    }
    
    case ACTIONS.SET_WIDGET_PREFERENCES: {
      const nextPreferences = {
        ...state.widgetPreferences,
        ...action.payload,
      };
      const nextForm = { ...state.form };
      const nextCount = toPositiveInt(action.payload?.count);
      if (nextCount !== null) {
        nextForm.participants = String(nextCount);
      }
      if (typeof action.payload?.visitDate === "string" && action.payload.visitDate.trim() !== "") {
        nextForm.date = action.payload.visitDate;
      }
      return {
        ...state,
        widgetPreferences: nextPreferences,
        form: nextForm,
      };
    }
    
    case ACTIONS.CLEAR_PLAN: {
      const currency = state.summary?.currency || "EUR";
      return {
        ...state,
        plan: {
          ...state.plan,
          items: [],
        },
        summary: createEmptySummary(currency),
        availabilityIssue: null,
        alternatives: {
          bySlot: {},
          currentIndex: {},
        },
      };
    }
    
    default:
      return state;
  }
}

function applyItemsUpdate(state, nextItems) {
  const currency = state.summary?.currency || "EUR";
  const participantCount = selectCanonicalParticipants(state, { allowFormFallback: true });
  const pricedItems = recalculateItems(nextItems, state.products, participantCount);
  if (nextItems.length === 0) {
    return {
      ...state,
      plan: {
        ...state.plan,
        items: pricedItems,
        planCheckoutCapability: null,
      },
      summary: createEmptySummary(currency),
    };
  }

  const recomputedSummary = summarizePlan(pricedItems, currency, participantCount);
  const summary = state.summary
    ? mergeSummaryWithAdjustments(state.summary, recomputedSummary)
    : recomputedSummary;

  return {
    ...state,
    plan: {
      ...state.plan,
      items: pricedItems,
      planCheckoutCapability: null,
    },
    summary,
  };
}

function sanitiseBase(restBase) {
  if (!restBase) {
    return "";
  }
  return restBase.replace(/\/+$/, "");
}

async function fetchJson(
  url,
  { method = "GET", body, nonce, nonceAction, referrerPolicy = "origin", credentials = "same-origin" } = {}
) {
  const response = await fetch(url, {
    method,
    headers: {
      "Content-Type": "application/json",
      ...buildNonceHeaders(nonce, nonceAction),
    },
    body: body ? JSON.stringify(body) : undefined,
    credentials,
    ...(referrerPolicy ? { referrerPolicy } : {}),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const message = payload?.message || response.statusText || "Request failed";
    throw new Error(message);
  }

  return payload;
}

function navigateFromPlanner(url) {
  if (!url || typeof window === "undefined" || !window.location) {
    return;
  }

  if (typeof document !== "undefined") {
    const host = window.location.hostname || "";
    const cookieNames = document.cookie
      .split(";")
      .map((entry) => entry.split("=")[0]?.trim())
      .filter((name) => /^sbjs_/i.test(name));
    const domains = [host, host ? `.${host}` : ""].filter(Boolean);

    cookieNames.forEach((name) => {
      document.cookie = `${name}=; Max-Age=0; path=/; SameSite=Lax`;
      domains.forEach((domain) => {
        document.cookie = `${name}=; Max-Age=0; path=/; domain=${domain}; SameSite=Lax`;
      });
    });
  }

  if (window.history && typeof window.history.replaceState === "function") {
    const cleanPath = `${window.location.pathname}${window.location.hash || ""}`;
    window.history.replaceState(window.history.state, "", cleanPath);
  }

  window.location.assign(url);
}

function toPositiveInt(value) {
  const parsed = Number.parseInt(value, 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

function normalizeBookingCapability(rawValue) {
  if (typeof rawValue !== "string") {
    return null;
  }

  const value = rawValue.trim().toLowerCase();
  if (!value) {
    return null;
  }

  if (
    value === "direct" ||
    value === "direct_eligible" ||
    value === "direct-eligible" ||
    value === "book" ||
    value === "checkout"
  ) {
    return BOOKING_CAPABILITY_DIRECT;
  }

  if (
    value === "request" ||
    value === "request_only" ||
    value === "request-only" ||
    value === "quote" ||
    value === "quote_only" ||
    value === "quote-only"
  ) {
    return BOOKING_CAPABILITY_REQUEST;
  }

  return null;
}

function resolveExplicitBookingCapability(source) {
  if (!source || typeof source !== "object") {
    return null;
  }

  const explicit =
    normalizeBookingCapability(source.bookingCapability) ||
    normalizeBookingCapability(source.booking_capability) ||
    normalizeBookingCapability(source.bookingMode) ||
    normalizeBookingCapability(source.booking_mode) ||
    normalizeBookingCapability(source.checkoutMode) ||
    normalizeBookingCapability(source.checkout_mode);
  if (explicit) {
    return explicit;
  }

  if (
    source.requestOnly === true ||
    source.quoteOnly === true ||
    source.requiresConfirmation === true ||
    source.requires_confirmation === true
  ) {
    return BOOKING_CAPABILITY_REQUEST;
  }

  if (source.directBookable === true) {
    return BOOKING_CAPABILITY_DIRECT;
  }

  return null;
}

function classifyItemBookingCapability(item, product = null) {
  const explicitFromItem = resolveExplicitBookingCapability(item);
  if (explicitFromItem) {
    return explicitFromItem;
  }

  const explicitFromProduct = resolveExplicitBookingCapability(product);
  if (explicitFromProduct) {
    return explicitFromProduct;
  }

  const resolutionStatus =
    typeof item?.bookingResolution?.status === "string"
      ? item.bookingResolution.status.trim().toLowerCase()
      : "";
  if (resolutionStatus && resolutionStatus !== "valid" && resolutionStatus !== "confirmed") {
    return BOOKING_CAPABILITY_REQUEST;
  }

  const hasValidStart = Boolean(sanitiseTimeString(item?.startTime ?? item?.start ?? ""));
  const hasValidEnd = Boolean(sanitiseTimeString(item?.endTime ?? item?.end ?? ""));
  if (!hasValidStart || !hasValidEnd) {
    return BOOKING_CAPABILITY_REQUEST;
  }

  return BOOKING_CAPABILITY_DIRECT;
}

function classifyPlanCheckoutCapability(items, products) {
  if (!Array.isArray(items) || items.length === 0) {
    return {
      status: PLAN_CHECKOUT_INCOMPLETE,
      isDirectEligible: false,
      blockingItemIds: [],
    };
  }

  const blockingItemIds = [];
  items.forEach((item) => {
    const productId = toPositiveInt(item?.productId ?? item?.product_id);
    const product =
      productId !== null
        ? (Array.isArray(products) ? products.find((entry) => Number(entry?.id) === productId) : null)
        : null;
    const capability = classifyItemBookingCapability(item, product);
    if (capability !== BOOKING_CAPABILITY_DIRECT) {
      blockingItemIds.push(item?.id || `${productId || "unknown"}`);
    }
  });

  if (blockingItemIds.length > 0) {
    return {
      status: PLAN_CHECKOUT_REQUEST,
      isDirectEligible: false,
      blockingItemIds,
    };
  }

  return {
    status: PLAN_CHECKOUT_DIRECT,
    isDirectEligible: true,
    blockingItemIds: [],
  };
}

function hasExplicitPlannerIngressPrefill(source) {
  if (!source || typeof source !== "object") {
    return false;
  }

  const visitDate =
    typeof source.visitDate === "string" && source.visitDate.trim() !== ""
      ? source.visitDate.trim()
      : typeof source.date === "string" && source.date.trim() !== ""
      ? source.date.trim()
      : "";
  const participants = toPositiveInt(
    source.count ?? source.participants ?? source.people
  );
  const startActivity =
    typeof source.startActivity === "string" && source.startActivity.trim() !== ""
      ? source.startActivity.trim()
      : typeof source.start === "string" && source.start.trim() !== ""
      ? source.start.trim()
      : "";

  return Boolean(visitDate || participants !== null || startActivity);
}

function normalizeRouteIntent(rawValue) {
  if (typeof rawValue !== "string") {
    return null;
  }

  const value = rawValue.trim().toLowerCase();
  if (value === ROUTE_INTENT_CHECKOUT || value === ROUTE_INTENT_QUOTE || value === ROUTE_INTENT_BLOCKED) {
    return value;
  }

  return null;
}

function normalizeCapabilityStatus(rawValue) {
  if (typeof rawValue !== "string") {
    return null;
  }

  const value = rawValue.trim().toLowerCase();
  if (!value) {
    return null;
  }

  if (value === "direct" || value === "direct_eligible" || value === "direct-eligible") {
    return NORMALIZED_CAPABILITY_DIRECT;
  }

  if (value === "direct_limited" || value === "direct-limited") {
    return NORMALIZED_CAPABILITY_DIRECT_LIMITED;
  }

  if (value === "request" || value === "request_only" || value === "request-only" || value === "quote") {
    return NORMALIZED_CAPABILITY_REQUEST;
  }

  if (value === "unavailable" || value === "blocked" || value === "none" || value === "incomplete") {
    return NORMALIZED_CAPABILITY_UNAVAILABLE;
  }

  return null;
}

function normalizeLegacyCheckoutStatus(rawValue, routeIntent, normalizedStatus) {
  const explicit = normalizeBookingCapability(rawValue);
  if (explicit) {
    return explicit;
  }

  if (normalizedStatus === NORMALIZED_CAPABILITY_DIRECT || normalizedStatus === NORMALIZED_CAPABILITY_DIRECT_LIMITED) {
    return PLAN_CHECKOUT_DIRECT;
  }

  if (routeIntent === ROUTE_INTENT_QUOTE) {
    return PLAN_CHECKOUT_REQUEST;
  }

  return PLAN_CHECKOUT_INCOMPLETE;
}

function buildCapabilityProfile({
  status,
  normalizedStatus,
  routeIntent,
  reasonCode = null,
  source = "derived",
}) {
  return {
    status: normalizeLegacyCheckoutStatus(status, routeIntent, normalizedStatus),
    normalized_status: normalizedStatus,
    route_intent: routeIntent,
    reason_code: typeof reasonCode === "string" && reasonCode.trim() !== "" ? reasonCode.trim() : null,
    source,
  };
}

function normalizeServerPlanCheckoutCapability(source) {
  if (!source || typeof source !== "object") {
    return null;
  }

  const normalizedStatus =
    normalizeCapabilityStatus(source.normalized_status) ||
    normalizeCapabilityStatus(source.normalizedStatus) ||
    normalizeCapabilityStatus(source.status);
  const routeIntent =
    normalizeRouteIntent(source.route_intent) ||
    normalizeRouteIntent(source.routeIntent) ||
    (normalizedStatus === NORMALIZED_CAPABILITY_DIRECT || normalizedStatus === NORMALIZED_CAPABILITY_DIRECT_LIMITED
      ? ROUTE_INTENT_CHECKOUT
      : normalizedStatus === NORMALIZED_CAPABILITY_REQUEST
      ? ROUTE_INTENT_QUOTE
      : normalizedStatus === NORMALIZED_CAPABILITY_UNAVAILABLE
      ? ROUTE_INTENT_BLOCKED
      : null);

  if (!routeIntent) {
    return null;
  }

  return buildCapabilityProfile({
    status: source.status,
    normalizedStatus:
      normalizedStatus ||
      (routeIntent === ROUTE_INTENT_CHECKOUT
        ? NORMALIZED_CAPABILITY_DIRECT
        : routeIntent === ROUTE_INTENT_QUOTE
        ? NORMALIZED_CAPABILITY_REQUEST
        : NORMALIZED_CAPABILITY_UNAVAILABLE),
    routeIntent,
    reasonCode: source.reason_code ?? source.reasonCode ?? null,
    source: "server",
  });
}

function deriveFallbackPlanCheckoutCapability(items, products) {
  const fallback = classifyPlanCheckoutCapability(items, products);
  if (fallback.status === PLAN_CHECKOUT_DIRECT) {
    return buildCapabilityProfile({
      status: fallback.status,
      normalizedStatus: NORMALIZED_CAPABILITY_DIRECT,
      routeIntent: ROUTE_INTENT_CHECKOUT,
      source: "fallback",
    });
  }

  if (fallback.status === PLAN_CHECKOUT_REQUEST) {
    return buildCapabilityProfile({
      status: fallback.status,
      normalizedStatus: NORMALIZED_CAPABILITY_REQUEST,
      routeIntent: ROUTE_INTENT_QUOTE,
      reasonCode: fallback.blockingItemIds.length > 0 ? "request_only_item_present" : "request_only_plan",
      source: "fallback",
    });
  }

  return buildCapabilityProfile({
    status: fallback.status,
    normalizedStatus: NORMALIZED_CAPABILITY_UNAVAILABLE,
    routeIntent: ROUTE_INTENT_BLOCKED,
    reasonCode: "incomplete_plan",
    source: "fallback",
  });
}

function resolvePlanCheckoutCapabilityProfile(plan, items, products) {
  const serverProfile =
    normalizeServerPlanCheckoutCapability(plan?.planCheckoutCapability) ||
    normalizeServerPlanCheckoutCapability(plan?.booking_capability) ||
    normalizeServerPlanCheckoutCapability(plan?.bookingCapability);
  const fallbackProfile = deriveFallbackPlanCheckoutCapability(items, products);

  if (!serverProfile) {
    return fallbackProfile;
  }

  const rankRouteIntent = (routeIntent) => {
    switch (routeIntent) {
      case ROUTE_INTENT_BLOCKED:
        return 3;
      case ROUTE_INTENT_QUOTE:
        return 2;
      case ROUTE_INTENT_CHECKOUT:
      default:
        return 1;
    }
  };

  if (rankRouteIntent(fallbackProfile.route_intent) > rankRouteIntent(serverProfile.route_intent)) {
    return fallbackProfile;
  }

  if (serverProfile) {
    return serverProfile;
  }

  return fallbackProfile;
}

function buildBlockingReasonMessage(reasonCode, routeIntent, messageOverride = null) {
  if (typeof messageOverride === "string" && messageOverride.trim() !== "") {
    return messageOverride.trim();
  }

  switch (reasonCode) {
    case "planner_conflict_critical":
      return "Deze planning bevat een kritieke overlap of te krappe aansluiting. Pas de tijden aan voordat je verdergaat.";
    case "availability_lookup_failed":
      return "Beschikbaarheid kon niet worden bevestigd. Controleer het tijdslot of vraag een offerte aan.";
    case "request_only_item_present":
    case "requires_confirmation":
    case "provider_requires_request":
      return "Deze planning bevat activiteiten die alleen via offerte beschikbaar zijn.";
    case "item_unavailable":
    case "capacity_exceeded":
      return "Een of meer activiteiten zijn niet beschikbaar op het gekozen moment.";
    case "empty_plan":
    case "incomplete_plan":
      return "Maak je planning compleet voordat je verdergaat.";
    default:
      if (routeIntent === ROUTE_INTENT_QUOTE) {
        return "Deze planning vereist een offerte-aanvraag voordat je verder kunt.";
      }
      if (routeIntent === ROUTE_INTENT_BLOCKED) {
        return "Deze planning kan momenteel niet worden afgerond.";
      }
      return "";
  }
}

function buildPlannerActionState({
  plan,
  items,
  form,
  products,
  availabilityIssue,
  canonicalParticipants,
  plannerApiAvailable,
}) {
  const capability = resolvePlanCheckoutCapabilityProfile(plan, items, products);
  const declaredCriticalPlannerConflictCount = Array.isArray(plan?.days)
    ? plan.days.reduce((count, day) => {
        const conflicts = Array.isArray(day?.conflicts) ? day.conflicts : [];
        return count + conflicts.filter((conflict) => conflict?.tone === "critical").length;
      }, 0)
    : 0;
  const computedCriticalPlannerConflictCount = countCriticalPlannerItemOverlaps(items, timeToMinutes);
  const criticalPlannerConflictCount =
    declaredCriticalPlannerConflictCount + computedCriticalPlannerConflictCount;
  const hasCriticalPlannerConflicts = criticalPlannerConflictCount > 0;
  const hasDate = typeof form?.date === "string" && form.date.trim() !== "";
  const hasItems = Array.isArray(items) && items.length > 0;
  const hasStartTimes = hasItems && items.some((item) => Boolean(item?.startTime));
  const requirementsMet = hasDate && canonicalParticipants > 0 && hasItems && hasStartTimes;
  const availabilityReasonCode =
    typeof availabilityIssue?.reasonCode === "string" && availabilityIssue.reasonCode.trim() !== ""
      ? availabilityIssue.reasonCode.trim()
      : capability.reason_code;
  const availabilityIssueVisible =
    Boolean(availabilityIssue?.message) ||
    availabilityReasonCode === "availability_lookup_failed";
  const hasNonDefinitiveAvailabilityIssue = isNonDefinitiveAvailabilityIssue(
    availabilityIssue,
    availabilityReasonCode
  );
  const hasHardAvailabilityBlocker = isHardAvailabilityBlocker(availabilityReasonCode);
  const availabilityBlocksDirectCheckout = shouldAvailabilityIssueBlockDirectCheckout(
    capability.route_intent,
    availabilityIssue,
    availabilityReasonCode
  );

  let actionMode = "blocked";
  if (!hasItems) {
    actionMode = "empty";
  } else if (requirementsMet && !hasCriticalPlannerConflicts && !hasHardAvailabilityBlocker) {
    if (capability.route_intent === ROUTE_INTENT_CHECKOUT && !availabilityBlocksDirectCheckout) {
      actionMode = "direct";
    } else if (hasNonDefinitiveAvailabilityIssue) {
      actionMode = "request";
    } else if (
      capability.route_intent === ROUTE_INTENT_QUOTE ||
      (availabilityIssueVisible && capability.route_intent !== ROUTE_INTENT_BLOCKED)
    ) {
      actionMode = "request";
    }
  }

  const blockingReasonCode =
    !requirementsMet
      ? "incomplete_plan"
      : hasCriticalPlannerConflicts
      ? "planner_conflict_critical"
      : availabilityReasonCode ||
        (actionMode === "request"
          ? capability.reason_code || "request_only_item_present"
          : actionMode === "blocked"
          ? capability.reason_code || "booking_blocked"
          : null);
  const blockingReasonMessage = buildBlockingReasonMessage(
    blockingReasonCode,
    capability.route_intent,
    availabilityIssue?.message ?? null
  );
  const primaryCtaEnabled =
    actionMode === "direct" &&
    requirementsMet &&
    plannerApiAvailable &&
    !availabilityBlocksDirectCheckout &&
    !hasCriticalPlannerConflicts;
  const secondaryQuoteEnabled =
    requirementsMet &&
    plannerApiAvailable &&
    !hasCriticalPlannerConflicts &&
    !hasHardAvailabilityBlocker &&
    (actionMode === "request" || capability.route_intent !== ROUTE_INTENT_BLOCKED);

  return {
    action_mode: actionMode,
    primary_cta_enabled: primaryCtaEnabled,
    primary_cta_visible: true,
    secondary_quote_enabled: secondaryQuoteEnabled,
    blocking_reason_code: blockingReasonCode,
    blocking_reason_message: blockingReasonMessage,
    availability_issue_visible: availabilityIssueVisible,
    handoff_allowed: primaryCtaEnabled,
    requirements_met: requirementsMet,
    critical_planner_conflict_count: criticalPlannerConflictCount,
    legacy_status: capability.status,
    normalized_status: capability.normalized_status,
    route_intent: capability.route_intent,
    status_label:
      actionMode === "direct"
        ? "Klaar om af te ronden"
        : actionMode === "empty"
        ? "Nog geen activiteiten"
        : actionMode === "request"
        ? "Offerte nodig"
        : "Niet direct boekbaar",
    status_message:
      actionMode === "direct"
        ? "Je planning klopt en is klaar om te boeken of als offerte te versturen."
        : blockingReasonMessage,
  };
}

function selectCanonicalParticipants(state, { allowFormFallback = true } = {}) {
  const fromForm = toPositiveInt(state?.form?.participants);
  if (fromForm !== null) {
    return fromForm;
  }

  if (allowFormFallback) {
    const fromPlan = toPositiveInt(state?.plan?.participants);
    if (fromPlan !== null) {
      return fromPlan;
    }
  }

  return DEFAULT_PARTICIPANTS;
}

function toFloat(value) {
  if (typeof value === "number") {
    return value;
  }
  if (typeof value === "string" && value.trim() !== "") {
    const parsed = Number.parseFloat(value);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }
  return 0;
}

function roundCurrency(value) {
  if (!Number.isFinite(value)) {
    return 0;
  }
  return Math.round((value + Number.EPSILON) * 100) / 100;
}

function hasServerQuotedPricing(item) {
  return (
    item?.pricingSource === "server" ||
    item?.pricing_source === "server" ||
    item?.serverQuoted === true
  );
}

function resolveQuotedPricingTotal(pricing, fallbackTotal = 0) {
  if (!pricing || typeof pricing !== "object") {
    return roundCurrency(fallbackTotal);
  }

  const quotedTotal =
    typeof pricing.display_total === "number"
      ? pricing.display_total
      : typeof pricing.total === "number"
      ? pricing.total
      : fallbackTotal;

  return roundCurrency(quotedTotal);
}

function resolveQuotedUnitPrice(pricing, totalCost, participants) {
  if (!pricing || typeof pricing !== "object") {
    return participants > 0 ? roundCurrency(totalCost / participants) : roundCurrency(totalCost);
  }

  const quotedUnitPrice =
    typeof pricing.display_unit_price === "number"
      ? pricing.display_unit_price
      : typeof pricing.display_per_person === "number"
      ? pricing.display_per_person
      : typeof pricing.unit_price === "number"
      ? pricing.unit_price
      : typeof pricing.per_person === "number"
      ? pricing.per_person
      : participants > 0
      ? totalCost / participants
      : totalCost;

  return roundCurrency(quotedUnitPrice);
}

function resolveQuotedAdjustment(pricing, fallbackAdjustment = 0) {
  if (!pricing || typeof pricing !== "object") {
    return roundCurrency(fallbackAdjustment);
  }

  const adjustment =
    typeof pricing.display_booking_adjustment === "number"
      ? pricing.display_booking_adjustment
      : typeof pricing.booking_adjustment === "number"
      ? pricing.booking_adjustment
      : fallbackAdjustment;

  return roundCurrency(adjustment);
}

function stripAggregatePricing(aggregate) {
  if (!aggregate || typeof aggregate !== "object") {
    return undefined;
  }

  const nextAggregate = { ...aggregate };
  if (Object.prototype.hasOwnProperty.call(nextAggregate, "pricing")) {
    delete nextAggregate.pricing;
  }

  return nextAggregate;
}

function sanitisePlannerInputForPersistence(plannerInput, combiItems) {
  if (!plannerInput || typeof plannerInput !== "object") {
    return undefined;
  }

  const nextInput = { ...plannerInput };
  if (nextInput.options && typeof nextInput.options === "object") {
    nextInput.options = {
      ...nextInput.options,
      combiItems,
    };
  } else if (combiItems.length > 0) {
    nextInput.options = { combiItems };
  }

  return nextInput;
}

function sanitizePlannerItemForPersistence(item) {
  if (!item || typeof item !== "object") {
    return null;
  }

  const combiItems = normalisePrefillCombiItems(
    item?.options?.combiItems ?? item?.combiItems ?? []
  );
  const aggregate = stripAggregatePricing(item?.aggregate);
  const nextItem = {
    ...item,
    participants: toPositiveInt(item?.participants) ?? DEFAULT_PARTICIPANTS,
    options: {
      ...(item?.options && typeof item.options === "object" ? item.options : {}),
      combiItems,
    },
  };

  delete nextItem.pricing;
  delete nextItem.totalCost;
  delete nextItem.fixedCost;
  delete nextItem.price_pp;
  delete nextItem.pricingSource;
  delete nextItem.pricing_source;
  delete nextItem.serverQuoted;
  delete nextItem.combiItems;

  if (aggregate) {
    nextItem.aggregate = aggregate;
  } else {
    delete nextItem.aggregate;
  }

  const plannerInput = sanitisePlannerInputForPersistence(nextItem.plannerInput, combiItems);
  if (plannerInput) {
    nextItem.plannerInput = plannerInput;
  } else {
    delete nextItem.plannerInput;
  }

  return nextItem;
}

function mergeSummaryWithAdjustments(existingSummary, recomputed) {
  if (!existingSummary) {
    return recomputed;
  }

  const adjustments = Array.isArray(existingSummary.adjustments) ? existingSummary.adjustments : [];
  const discounts = Array.isArray(existingSummary.discounts) ? existingSummary.discounts : [];
  const taxes = Array.isArray(existingSummary.taxes) ? existingSummary.taxes : [];

  const adjustmentsTotal = roundCurrency(sumAmounts(adjustments));
  const discountTotal = roundCurrency(sumAmounts(discounts));
  const taxTotal = roundCurrency(sumAmounts(taxes));
  const grandTotal = roundCurrency(
    recomputed.itemsSubtotal + adjustmentsTotal + taxTotal - discountTotal
  );

  const participants =
    Number.isFinite(recomputed.participants) && recomputed.participants > 0
      ? recomputed.participants
      : Number.isFinite(existingSummary.participants) && existingSummary.participants > 0
      ? existingSummary.participants
      : null;

  const participantShare =
    participants && participants > 0 ? roundCurrency(grandTotal / participants) : null;

  return {
    ...existingSummary,
    ...recomputed,
    adjustments,
    discounts,
    taxes,
    participants,
    participantShare,
    adjustmentsTotal,
    discountTotal,
    taxTotal,
    grandTotal,
    subtotal: grandTotal,
    breakdown: {
      ...existingSummary.breakdown,
      ...recomputed.breakdown,
      adjustments_total: adjustmentsTotal,
      discount_total: discountTotal,
      tax_total: taxTotal,
      grand_total: grandTotal,
      items_subtotal: recomputed.itemsSubtotal,
    },
  };
}

function recalculateItems(items, products, fallbackParticipants) {
  if (!Array.isArray(items) || items.length === 0) {
    return [];
  }

  return items.map((item) => {
    const participantTruth = applyParticipantsTruthToItem(
      item,
      fallbackParticipants,
      DEFAULT_PARTICIPANTS
    );
    const participants = participantTruth.participants;

    if (hasServerQuotedPricing(item) && item?.pricing && typeof item.pricing === "object") {
      const totalCost = resolveQuotedPricingTotal(item.pricing, item?.totalCost ?? 0);
      const unitPrice = resolveQuotedUnitPrice(item.pricing, totalCost, participants);
      const fixedCost = resolveQuotedAdjustment(item.pricing, item?.fixedCost ?? 0);

      return {
        ...item,
        ...participantTruth,
        pricing: {
          ...(item?.pricing || {}),
          currency: item?.pricing?.currency || "EUR",
          subtotal: item?.pricing?.subtotal ?? totalCost,
          tax: item?.pricing?.tax ?? item?.pricing?.tax_total ?? 0,
          tax_total: item?.pricing?.tax_total ?? item?.pricing?.tax ?? 0,
          total: totalCost,
          display_total: totalCost,
          unit_price: unitPrice,
          display_unit_price: unitPrice,
          unitPrice: unitPrice,
          per_person: unitPrice,
          display_per_person: unitPrice,
          adjustments: Array.isArray(item?.pricing?.adjustments) ? item.pricing.adjustments : [],
          discounts: Array.isArray(item?.pricing?.discounts) ? item.pricing.discounts : [],
          taxes: Array.isArray(item?.pricing?.taxes) ? item.pricing.taxes : [],
          segments: Array.isArray(item?.pricing?.segments) ? item.pricing.segments : [],
          dynamic: { total: totalCost },
        },
        totalCost,
        price_pp: unitPrice,
        fixedCost,
      };
    }

    const productId = toPositiveInt(item?.productId ?? item?.product_id);
    const product = products?.find((entry) => entry.id === productId) || null;
    const pricingSource = product?.pricing || item?.pricing || {};

    const slotPricing = computeSlotPricing(pricingSource, participants, {
      // Prefer item-specific price_pp over product-level to avoid overwriting API-provided values.
      pricePerPerson: item?.price_pp ?? product?.price_pp ?? product?.price_per_person,
      sourceProduct: product || item,
    });

    return {
      ...item,
      ...participantTruth,
      pricing: pricingSource,
      totalCost: slotPricing.total,
      price_pp: slotPricing.perPerson,
      fixedCost: slotPricing.fixedCost,
    };
  });
}

function minutesBetween(start, end) {
  const startMinutes = timeToMinutes(start || "");
  const endMinutes = timeToMinutes(end || "");
  const diff = endMinutes - startMinutes;
  return diff > 0 ? diff : 0;
}

function getParticipantCount(...candidates) {
  for (const candidate of candidates) {
    const parsed = toPositiveInt(candidate);
    if (parsed !== null) {
      return parsed;
    }
  }

  return DEFAULT_PARTICIPANTS;
}

function formatPlannerClockLabel(minutes) {
  if (!Number.isFinite(minutes)) {
    return null;
  }

  return minutesToTime(minutes);
}

function buildSuggestedSlotMessage(baseMessage, minutes) {
  const label = formatPlannerClockLabel(minutes);
  if (!label) {
    return baseMessage;
  }

  return `${baseMessage} Probeer ${label}.`;
}

function findSuggestedPlannerStart({
  items,
  dayIndex,
  durationMinutes,
  startFromMinutes,
  openHours,
  ignoreId = null,
  ignoreGroupId = null,
}) {
  const duration = Math.max(15, Number.isFinite(durationMinutes) ? durationMinutes : 15);
  const fallbackStart = Number.isFinite(startFromMinutes) ? startFromMinutes : 9 * 60;
  const rawOpenStart =
    typeof openHours?.start === "string" ? timeToMinutes(openHours.start) : null;
  const rawOpenEnd =
    typeof openHours?.end === "string" ? timeToMinutes(openHours.end) : null;
  const scanStart = Number.isFinite(rawOpenStart)
    ? Math.max(rawOpenStart, fallbackStart)
    : fallbackStart;
  const scanEnd = Number.isFinite(rawOpenEnd)
    ? rawOpenEnd - duration
    : fallbackStart + 12 * 60;

  if (scanEnd < scanStart) {
    return null;
  }

  const step = 15;
  for (let candidate = scanStart; candidate <= scanEnd; candidate += step) {
    const candidateEnd = candidate + duration;
    if (!isWithinPlannerHours(candidate, candidateEnd, openHours)) {
      continue;
    }

    if (
      itemConflicts(items, dayIndex, candidate, candidateEnd, ignoreId, {
        ignoreGroupId,
      })
    ) {
      continue;
    }

    return candidate;
  }

  return null;
}

function resolveSlotPricing(item, participants) {
  if (!item) {
    return { perPerson: 0, fixedCost: 0, total: 0 };
  }

  if (hasServerQuotedPricing(item) && item?.pricing && typeof item.pricing === "object") {
    const total = resolveQuotedPricingTotal(item.pricing, item?.totalCost ?? 0);
    const perPerson = resolveQuotedUnitPrice(item.pricing, total, participants);

    return {
      perPerson,
      fixedCost: resolveQuotedAdjustment(item.pricing, item?.fixedCost ?? 0),
      total,
    };
  }

  return deriveSlotPricing(item?.pricing || {}, participants, {
    totalCost: item?.totalCost,
    pricePerPerson: item?.price_pp,
  });
}

function buildPlanTitle(plan, form) {
  const firstDate = plan?.days?.[0]?.date || form?.date;
  if (firstDate) {
    return `Dagplanning ${firstDate}`;
  }
  return "Dagplanning";
}

function buildPlanPayload(plan, form, summary, config) {
  const planParticipants = Array.isArray(plan?.participants)
    ? plan.participants.length
    : plan?.participants;
  const participantCount = getParticipantCount(
    planParticipants,
    plan?.meta?.participant_count,
    form?.participants
  );
  const currency = summary?.currency || "EUR";

  const days = (plan?.days || []).map((day) => ({
    date: day?.date || "",
    slots: [],
  }));

  const participantsList = Array.from({ length: participantCount }, () => ({
    name: "",
    email: "",
    role: "guest",
  }));

  (plan?.items || []).forEach((item) => {
    const dayIndex = Number.isFinite(item?.dayIndex) ? item.dayIndex : Number.parseInt(item?.dayIndex, 10) || 0;
    if (!days[dayIndex]) {
      const fallbackDate = plan?.days?.[dayIndex]?.date || "";
      days[dayIndex] = { date: fallbackDate, slots: [] };
    }

    const slotParticipants = resolveParticipantsForItem(item, participantCount, participantCount);
    const bookingResolution =
      item?.bookingResolution && typeof item.bookingResolution === "object"
        ? item.bookingResolution
        : null;
    const itemStart =
      sanitiseTimeString(item?.startTime ?? item?.start ?? "");
    const itemEnd =
      sanitiseTimeString(item?.endTime ?? item?.end ?? "");
    const duration = toPositiveInt(item?.durationMinutes) ?? minutesBetween(itemStart, itemEnd);

    const absStart = itemStart || "";
    const absEnd = itemEnd || "";
    const absDuration = duration > 0 ? duration : 0;

    days[dayIndex].slots.push({
      start: absStart || "",
      end: absEnd || "",
      people: slotParticipants,
      product_id: item?.productId || item?.product_id || 0,
      resource_id: item?.resourceId != null ? item.resourceId : item?.resource_id != null ? item.resource_id : 0,
      planner_key: item?.plannerKey || "",
      status: item?.status || "planned",
      currency,
      duration_minutes: absDuration > 0 ? absDuration : null,
      buffer_before: 0,
      buffer_after: 0,
    });
  });

  const meta = {
    participant_count: participantCount,
    planner_items: Array.isArray(plan?.items)
      ? plan.items.map((item) => sanitizePlannerItemForPersistence(item)).filter(Boolean)
      : [],
  };

  const rawToken =
    typeof plan?.editToken === "string" && plan.editToken.trim() !== ""
      ? plan.editToken.trim()
      : typeof plan?.meta?.edit_token === "string" && plan.meta.edit_token.trim() !== ""
      ? plan.meta.edit_token.trim()
      : null;

  if (rawToken) {
    meta.edit_token = rawToken;
  }

  return {
    title: buildPlanTitle(plan, form),
    days,
    participants: participantsList,
    meta,
  };
}

function readPlannerTitle(value) {
  return typeof value === "string" && value.trim() !== "" ? value.trim() : "";
}

function resolvePlannerItemTitle(item, productId, productLookup, fallbackLabel) {
  const normalizedProductId = toPositiveInt(productId);
  const product =
    normalizedProductId !== null && productLookup instanceof Map
      ? productLookup.get(normalizedProductId) || null
      : null;

  const candidates = [
    item?.title,
    item?.product_name,
    item?.name,
    item?.product?.name,
    item?.bookingResolution?.source_title,
    item?.bookingResolution?.summary?.title,
    item?.aggregate?.title,
    product?.name,
    product?.title,
  ];

  for (const candidate of candidates) {
    const title = readPlannerTitle(candidate);
    if (title) {
      return title;
    }
  }

  return readPlannerTitle(fallbackLabel);
}

function normalisePlanResponse(planPayload, existingState = initialState) {
  if (!planPayload || typeof planPayload !== "object") {
    return null;
  }

  const safeState = existingState || initialState;
  const baseDate = safeState.form?.date || getTodayIso();

  let rawParticipants =
    planPayload.participants ??
    planPayload.meta?.participant_count ??
    planPayload.meta?.participants;
  if (Array.isArray(rawParticipants)) {
    rawParticipants = rawParticipants.length;
  }
  const participants = getParticipantCount(
    rawParticipants,
    planPayload.meta?.participant_count,
    safeState.form?.participants
  );

  const rawDays = Array.isArray(planPayload.days) ? planPayload.days : [];
  const persistedPlannerItems = Array.isArray(planPayload?.meta?.planner_items)
    ? planPayload.meta.planner_items
    : [];
  const productLookup = new Map(
    (Array.isArray(safeState.products) ? safeState.products : [])
      .filter((product) => product && typeof product === "object")
      .map((product) => {
        const productId = toPositiveInt(product?.id ?? product?.product_id);
        return productId !== null ? [productId, product] : null;
      })
      .filter(Boolean)
  );
  const persistedPlannerMap = new Map(
    persistedPlannerItems
      .filter((item) => item && typeof item === "object")
      .map((item) => {
        const key =
          typeof item?.plannerKey === "string" && item.plannerKey.trim() !== ""
            ? item.plannerKey.trim()
            : [
                toPositiveInt(item?.productId ?? item?.product_id) ?? 0,
                typeof item?.date === "string" ? item.date : "",
                typeof item?.startTime === "string" ? item.startTime : "",
                toPositiveInt(item?.resourceId ?? item?.resource_id) ?? 0,
                toPositiveInt(item?.participants) ?? 1,
                Array.isArray(item?.options?.combiItems)
                  ? item.options.combiItems
                      .map((entry) => toPositiveInt(entry?.id))
                      .filter(Boolean)
                      .join(",")
                  : "",
              ].join("|");
        return [key, item];
      })
  );
  const normalisedDays =
    rawDays.length > 0
      ? rawDays.map((day) => ({
          date:
            typeof day?.date === "string" && day.date.trim() !== "" ? day.date.trim() : baseDate,
        }))
      : safeState.plan.days.length > 0
      ? safeState.plan.days
      : buildDays(baseDate, 1);

  const items = [];

  rawDays.forEach((day, dayIndex) => {
    const slots = Array.isArray(day?.slots) ? day.slots : [];
    const currentDate =
      typeof day?.date === "string" && day.date.trim() !== ""
        ? day.date.trim()
        : normalisedDays[dayIndex]?.date ?? normalisedDays[0]?.date ?? baseDate;

    if (normalisedDays[dayIndex]) {
      normalisedDays[dayIndex] = { ...normalisedDays[dayIndex], date: currentDate };
    }

    slots.forEach((slot, slotIndex) => {
      const productId = toPositiveInt(
        slot?.product_id ?? slot?.productId ?? slot?.product?.id ?? slot?.id
      );

      const titleSource = resolvePlannerItemTitle(
        slot,
        productId,
        productLookup,
        productId !== null ? `Activiteit ${productId}` : `Activiteit ${slotIndex + 1}`
      );

      const start =
        typeof slot?.start === "string" && sanitiseTimeString(slot.start)
          ? sanitiseTimeString(slot.start)
          : null;
      const durationCandidate = toPositiveInt(slot?.duration_minutes) ?? 0;
      const end =
        typeof slot?.end === "string" && sanitiseTimeString(slot.end)
          ? sanitiseTimeString(slot.end)
          : start && durationCandidate > 0
          ? minutesToTime(timeToMinutes(start) + durationCandidate)
          : null;

      const startMinutes = start ? timeToMinutes(start) : NaN;
      const endMinutes = end ? timeToMinutes(end) : NaN;
      const durationMinutes =
        Number.isFinite(startMinutes) && Number.isFinite(endMinutes) && endMinutes > startMinutes
          ? endMinutes - startMinutes
          : durationCandidate > 0
          ? durationCandidate
          : 0;

      let slotParticipants =
        toPositiveInt(slot?.people ?? slot?.participants) ?? participants ?? DEFAULT_PARTICIPANTS;

      const perPerson = toFloat(
        slot?.price_pp ?? slot?.price_per_person ?? slot?.pricing?.per_person
      );
      const fixedFee = toFloat(slot?.fixed_cost ?? slot?.fixed_fee ?? slot?.pricing?.fixed_fee);
      const dynamicTotal = toFloat(slot?.total_cost ?? slot?.total ?? slot?.pricing?.total);
      const totalCost =
        dynamicTotal > 0
          ? roundCurrency(dynamicTotal)
          : roundCurrency(perPerson * slotParticipants + fixedFee);

      const currency =
        slot?.currency ||
        slot?.pricing?.currency ||
        planPayload?.currency ||
        planPayload?.totals?.currency ||
        safeState.summary?.currency ||
        "EUR";

      const locked = resolvePlannerItemLocked(slot);

      const resourceId = toPositiveInt(slot?.resource_id ?? slot?.resourceId);
      const persistedKey =
        typeof slot?.planner_key === "string" && slot.planner_key.trim() !== ""
          ? slot.planner_key.trim()
          : typeof slot?.plannerKey === "string" && slot.plannerKey.trim() !== ""
          ? slot.plannerKey.trim()
          : [
              productId ?? 0,
              currentDate,
              start,
              resourceId ?? 0,
              slotParticipants,
            ].join("|");
      const persistedPlannerItem = persistedPlannerMap.get(persistedKey) || null;
      slotParticipants = resolveParticipantsForItem(
        persistedPlannerItem || slot,
        participants,
        participants ?? DEFAULT_PARTICIPANTS
      );

      const bookingResolution =
        persistedPlannerItem?.bookingResolution && typeof persistedPlannerItem.bookingResolution === "object"
          ? persistedPlannerItem.bookingResolution
          : undefined;

      const item = {
        id:
          typeof slot?.id === "string" && slot.id.trim() !== ""
            ? `plan-slot-${slot.id.trim()}`
            : `plan-${productId ?? "slot"}-${dayIndex}-${slotIndex}-${Date.now()}-${Math.floor(
                Math.random() * 1000
              )}`,
        dayIndex,
        productId,
        product_id: productId,
        title: titleSource,
        participants: slotParticipants,
        participants_override: hasManualParticipantsOverride(persistedPlannerItem),
        participants_source: hasManualParticipantsOverride(persistedPlannerItem)
          ? persistedPlannerItem?.participants_source || "manual_override"
          : PARTICIPANTS_SOURCE_INHERITED,
        durationMinutes,
        startMinutes,
        endMinutes,
        startTime: start,
        endTime: end,
        manual_locked: persistedPlannerItem?.manual_locked === true || persistedPlannerItem?.manualLocked === true,
        time_source:
          typeof persistedPlannerItem?.time_source === "string" && persistedPlannerItem.time_source.trim() !== ""
            ? persistedPlannerItem.time_source.trim()
            : persistedPlannerItem?.manual_locked === true || persistedPlannerItem?.manualLocked === true
            ? TIME_SOURCE_MANUAL
            : TIME_SOURCE_AUTO,
        user_order: resolveUserOrder(persistedPlannerItem || slot, slotIndex),
        pricing:
          hasServerQuotedPricing(persistedPlannerItem) &&
          persistedPlannerItem?.pricing &&
          typeof persistedPlannerItem.pricing === "object"
            ? persistedPlannerItem.pricing
            : {
                per_person: perPerson,
                fixed_fee: fixedFee,
                currency,
              },
        totalCost:
          hasServerQuotedPricing(persistedPlannerItem) &&
          typeof persistedPlannerItem?.totalCost === "number"
            ? persistedPlannerItem.totalCost
            : totalCost,
        locked,
        resourceId,
        resource_id: resourceId,
        date: currentDate,
        status:
          typeof persistedPlannerItem?.status === "string" && persistedPlannerItem.status.trim() !== ""
            ? persistedPlannerItem.status.trim()
            : "planned",
        plannerKey:
          typeof persistedPlannerItem?.plannerKey === "string" && persistedPlannerItem.plannerKey.trim() !== ""
            ? persistedPlannerItem.plannerKey.trim()
            : persistedKey,
        source:
          typeof persistedPlannerItem?.source === "string" && persistedPlannerItem.source.trim() !== ""
            ? persistedPlannerItem.source.trim()
            : "day-planner",
        pricingSource:
          hasServerQuotedPricing(persistedPlannerItem) ? "server" : undefined,
        serverQuoted: hasServerQuotedPricing(persistedPlannerItem),
        options:
          persistedPlannerItem?.options && typeof persistedPlannerItem.options === "object"
            ? {
                ...persistedPlannerItem.options,
                combiItems: normalisePrefillCombiItems(persistedPlannerItem.options.combiItems),
              }
            : { combiItems: [] },
        combiItems: Array.isArray(persistedPlannerItem?.combiItems)
          ? normalisePrefillCombiItems(persistedPlannerItem.combiItems)
          : normalisePrefillCombiItems(persistedPlannerItem?.options?.combiItems),
        type: typeof persistedPlannerItem?.type === "string" ? persistedPlannerItem.type : undefined,
        role: typeof persistedPlannerItem?.role === "string" ? persistedPlannerItem.role : undefined,
        isArrangement: Boolean(
          persistedPlannerItem?.isArrangement ||
          persistedPlannerItem?.type === "arrangement" ||
          persistedPlannerItem?.type === "arrangement-part" ||
          (Array.isArray(persistedPlannerItem?.options?.combiItems) && persistedPlannerItem.options.combiItems.length > 0)
        ),
        aggregateId:
          typeof persistedPlannerItem?.aggregateId === "string" ? persistedPlannerItem.aggregateId : undefined,
        groupId:
          typeof persistedPlannerItem?.groupId === "string" ? persistedPlannerItem.groupId : undefined,
        segments:
          Array.isArray(persistedPlannerItem?.segments) ? persistedPlannerItem.segments : undefined,
        aggregate:
          persistedPlannerItem?.aggregate && typeof persistedPlannerItem.aggregate === "object"
            ? stripAggregatePricing(persistedPlannerItem.aggregate)
            : undefined,
        bookingResolution,
        plannerInput:
          persistedPlannerItem?.plannerInput && typeof persistedPlannerItem.plannerInput === "object"
            ? persistedPlannerItem.plannerInput
            : undefined,
        cartMapping:
          persistedPlannerItem?.cartMapping && typeof persistedPlannerItem.cartMapping === "object"
            ? persistedPlannerItem.cartMapping
            : {
                product_id: productId,
                quantity: slotParticipants,
                line_hash: persistedKey,
              },
      };

      const shouldMaterializeArrangement =
        !item.groupId &&
        (item.isArrangement ||
          (Array.isArray(item.combiItems) && item.combiItems.length > 0) ||
          (Array.isArray(item.options?.combiItems) && item.options.combiItems.length > 0) ||
          (Array.isArray(item.bookingResolution?.segments) &&
            item.bookingResolution.segments.some((segment) => segment && segment.role && segment.role !== "anchor")));

      if (shouldMaterializeArrangement) {
        items.push(
          ...generateArrangementItemsPayload(
            item,
            item.combiItems || item.options?.combiItems || []
          )
        );
      } else {
        items.push(item);
      }
    });
  });

  if (items.length === 0 && Array.isArray(planPayload.items)) {
    planPayload.items.forEach((lineItem, index) => {
      const productId = toPositiveInt(lineItem?.product_id ?? lineItem?.id);
      const start = sanitiseTimeString(lineItem?.schedule?.start ?? lineItem?.start ?? "");
      const end = sanitiseTimeString(lineItem?.schedule?.end ?? lineItem?.end ?? "");
      const date =
        typeof lineItem?.schedule?.date === "string" && lineItem.schedule.date.trim() !== ""
          ? lineItem.schedule.date.trim()
          : normalisedDays[0]?.date ?? baseDate;
      const startMinutes = start ? timeToMinutes(start) : NaN;
      const endMinutes = end ? timeToMinutes(end) : NaN;
      const durationMinutes =
        Number.isFinite(startMinutes) && Number.isFinite(endMinutes) && endMinutes > startMinutes
          ? endMinutes - startMinutes
          : 0;
      const slotParticipants = resolveParticipantsForItem(
        lineItem,
        participants,
        participants ?? DEFAULT_PARTICIPANTS
      );
      const totalCost = roundCurrency(toFloat(lineItem?.line_subtotal ?? lineItem?.total));
      const currency = lineItem?.currency || safeState.summary?.currency || "EUR";
      const resourceId = toPositiveInt(lineItem?.resource_id ?? lineItem?.resourceId);
      const plannerKey =
        typeof lineItem?.plannerKey === "string" && lineItem.plannerKey.trim() !== ""
          ? lineItem.plannerKey.trim()
          : typeof lineItem?.line_uid === "string" && lineItem.line_uid.trim() !== ""
          ? lineItem.line_uid.trim()
          : [
              productId ?? 0,
              date || "",
              start,
              resourceId ?? 0,
              slotParticipants,
            ].join("|");

      const item = {
        id: `plan-item-${productId ?? "slot"}-${index}-${Date.now()}-${Math.floor(
          Math.random() * 1000
        )}`,
        dayIndex: 0,
        productId,
        product_id: productId,
        title: resolvePlannerItemTitle(
          lineItem,
          productId,
          productLookup,
          `Activiteit ${productId ?? index + 1}`
        ),
        participants: slotParticipants,
        participants_override: hasManualParticipantsOverride(lineItem),
        participants_source: hasManualParticipantsOverride(lineItem)
          ? lineItem?.participants_source || "manual_override"
          : PARTICIPANTS_SOURCE_INHERITED,
        durationMinutes,
        startMinutes,
        endMinutes,
        startTime: start,
        endTime: end,
        manual_locked: lineItem?.manual_locked === true || lineItem?.manualLocked === true,
        time_source:
          typeof lineItem?.time_source === "string" && lineItem.time_source.trim() !== ""
            ? lineItem.time_source.trim()
            : lineItem?.manual_locked === true || lineItem?.manualLocked === true
            ? TIME_SOURCE_MANUAL
            : TIME_SOURCE_AUTO,
        user_order: resolveUserOrder(lineItem, index),
        date,
        pricing: {
          per_person: 0,
          fixed_fee: 0,
          currency,
        },
        totalCost,
        locked: resolvePlannerItemLocked(lineItem),
        resourceId,
        resource_id: resourceId,
        status:
          typeof lineItem?.status === "string" && lineItem.status.trim() !== ""
            ? lineItem.status.trim()
            : "planned",
        plannerKey,
        aggregateId: typeof lineItem?.aggregateId === "string" ? lineItem.aggregateId : undefined,
        groupId: typeof lineItem?.groupId === "string" ? lineItem.groupId : undefined,
        segments: Array.isArray(lineItem?.segments) ? lineItem.segments : undefined,
        aggregate:
          lineItem?.aggregate && typeof lineItem.aggregate === "object"
            ? stripAggregatePricing(lineItem.aggregate)
            : undefined,
        bookingResolution:
          lineItem?.bookingResolution && typeof lineItem.bookingResolution === "object"
            ? lineItem.bookingResolution
            : undefined,
        source:
          typeof lineItem?.source === "string" && lineItem.source.trim() !== ""
            ? lineItem.source.trim()
            : "day-planner",
        options:
          lineItem?.options && typeof lineItem.options === "object"
            ? {
                ...lineItem.options,
                combiItems: normalisePrefillCombiItems(lineItem.options.combiItems),
              }
            : { combiItems: [] },
        combiItems: Array.isArray(lineItem?.combiItems)
          ? normalisePrefillCombiItems(lineItem.combiItems)
          : normalisePrefillCombiItems(lineItem?.options?.combiItems),
        type: typeof lineItem?.type === "string" ? lineItem.type : undefined,
        role: typeof lineItem?.role === "string" ? lineItem.role : undefined,
        isArrangement: Boolean(
          lineItem?.isArrangement ||
          lineItem?.type === "arrangement" ||
          lineItem?.type === "arrangement-part" ||
          (Array.isArray(lineItem?.options?.combiItems) && lineItem.options.combiItems.length > 0)
        ),
        plannerInput:
          lineItem?.plannerInput && typeof lineItem.plannerInput === "object"
            ? lineItem.plannerInput
            : undefined,
        cartMapping:
          lineItem?.cartMapping && typeof lineItem.cartMapping === "object"
            ? lineItem.cartMapping
            : {
                product_id: productId,
                quantity: slotParticipants,
                line_hash: plannerKey,
              },
      };

      const shouldMaterializeArrangement =
        !item.groupId &&
        (item.isArrangement ||
          (Array.isArray(item.combiItems) && item.combiItems.length > 0) ||
          (Array.isArray(item.options?.combiItems) && item.options?.combiItems.length > 0) ||
          (Array.isArray(item.bookingResolution?.segments) &&
            item.bookingResolution.segments.some((segment) => segment && segment.role && segment.role !== "anchor")));

      if (shouldMaterializeArrangement) {
        items.push(
          ...generateArrangementItemsPayload(
            item,
            item.combiItems || item.options?.combiItems || []
          )
        );
      } else {
        items.push(item);
      }
    });
  }

  const summary = planPayload.totals
    ? normalizeTotals(planPayload.totals, safeState.summary)
    : safeState.summary;

  const formDate =
    normalisedDays.length > 0 && typeof normalisedDays[0]?.date === "string"
      ? normalisedDays[0].date
      : baseDate;

  const editToken =
    typeof planPayload?.meta?.edit_token === "string" && planPayload.meta.edit_token.trim() !== ""
      ? planPayload.meta.edit_token.trim()
      : typeof planPayload?.edit_token === "string" && planPayload.edit_token.trim() !== ""
      ? planPayload.edit_token.trim()
      : safeState.plan.editToken;
  const planCheckoutCapability = normalizeServerPlanCheckoutCapability(
    planPayload?.planCheckoutCapability ||
      planPayload?.plan_checkout_capability ||
      planPayload?.booking_capability ||
      planPayload?.bookingCapability
  );

  return {
    plan: {
      id: toPositiveInt(planPayload.id) ?? safeState.plan.id,
      editToken,
      participants,
      days: normalisedDays,
      items,
      planCheckoutCapability,
    },
    form: {
      date: formDate,
      participants: participants ? String(participants) : "",
    },
    summary,
  };
}

function buildPlanPrefillQueue(plan) {
  if (!plan || !Array.isArray(plan.items)) {
    return [];
  }

  return plan.items
    .filter((item) => toPositiveInt(item?.productId ?? item?.product_id))
    .map((item) => {
      const productId = toPositiveInt(item?.productId ?? item?.product_id);
      const day = Array.isArray(plan.days) ? plan.days[item.dayIndex] : null;
      return {
        source: "plan",
        product_id: productId ?? undefined,
        productId: productId ?? undefined,
        date: day?.date ?? null,
        time: item.startTime,
        participants: item.participants,
        resource_id: item.resourceId ?? item.resource_id ?? undefined,
        lock_first_slot: resolvePlannerItemLocked(item),
        planItem: item,
      };
    });
}

function normalizeTotals(totals, previousSummary = createEmptySummary()) {
  const base = previousSummary && typeof previousSummary === "object"
    ? { ...previousSummary }
    : createEmptySummary();

  if (!totals || typeof totals !== "object") {
    return base;
  }

  const currency =
    totals?.summary?.currency ||
    totals?.currency ||
    base.currency ||
    "EUR";

  const items = Array.isArray(totals?.items)
    ? normalizeLineItems(totals.items)
    : normalizeLineItems(base.items);
  const adjustments = Array.isArray(totals?.adjustments)
    ? normalizeMoneyRows(totals.adjustments)
    : normalizeMoneyRows(base.adjustments);
  const discounts = Array.isArray(totals?.discounts)
    ? normalizeMoneyRows(totals.discounts)
    : normalizeMoneyRows(base.discounts);
  const taxes = Array.isArray(totals?.taxes)
    ? normalizeMoneyRows(totals.taxes)
    : normalizeMoneyRows(base.taxes);

  const summaryData =
    (totals?.summary && typeof totals.summary === "object" ? totals.summary : base.breakdown) || {};

  const itemsSubtotalValue =
    summaryData.items_subtotal ?? totals?.items_subtotal ?? totals?.subtotal ?? sumLineSubtotals(items);
  const adjustmentsTotalValue =
    summaryData.adjustments_total ?? totals?.adjustments_total ?? sumAmounts(adjustments);
  const discountTotalValue =
    summaryData.discount_total ?? totals?.discount_total ?? sumAmounts(discounts);
  const taxTotalValue =
    summaryData.tax_total ?? totals?.tax_total ?? sumAmounts(taxes);
  const grandTotalValue =
    summaryData.grand_total ?? totals?.total ??
    (itemsSubtotalValue + adjustmentsTotalValue + taxTotalValue - discountTotalValue);

  const participantsCount =
    summaryData.participants ?? totals?.participants ?? base.participants ?? null;

  const itemsSubtotal = roundCurrency(toFloat(itemsSubtotalValue));
  const adjustmentsTotal = roundCurrency(toFloat(adjustmentsTotalValue));
  const discountTotal = roundCurrency(toFloat(discountTotalValue));
  const taxTotal = roundCurrency(toFloat(taxTotalValue));
  const grandTotal = roundCurrency(toFloat(grandTotalValue));
  const participantShare =
    participantsCount && participantsCount > 0
      ? roundCurrency(grandTotal / participantsCount)
      : null;

  return {
    ...base,
    currency,
    items,
    adjustments,
    discounts,
    taxes,
    itemsSubtotal,
    adjustmentsTotal,
    discountTotal,
    taxTotal,
    grandTotal,
    subtotal: grandTotal,
    participants: participantsCount,
    participantShare,
    breakdown: {
      ...base.breakdown,
      ...summaryData,
      currency,
      participants: participantsCount,
      items_count: summaryData.items_count ?? items.length,
      items_subtotal: itemsSubtotal,
      adjustments_total: adjustmentsTotal,
      discount_total: discountTotal,
      tax_total: taxTotal,
      grand_total: grandTotal,
    },
  };
}

export function PlannerProvider({ bootConfig, children }) {
  const restBase = sanitiseBase(bootConfig?.restBase);
  const nonce = bootConfig?.nonce || "";
  const nonceAction = bootConfig?.nonceAction || "";
  const [state, dispatch] = useReducer(plannerReducer, initialState, (baseState) => {
    const storedFilters = readStoredFilters();
    const bootProducts = normaliseBootProducts(bootConfig?.products);
    const nextState = {
      ...baseState,
      products: bootProducts,
      loading: {
        ...baseState.loading,
        products: false,
      },
    };

    if (!storedFilters) {
      return nextState;
    }
    return {
      ...nextState,
      filters: {
        ...nextState.filters,
        ...storedFilters,
      },
    };
  });
  const stateRef = useRef(state);
  useEffect(() => {
    stateRef.current = state;
  }, [state]);

  const plannerApi = useMemo(() => {
    if (!restBase) {
      return null;
    }

    return createPlannerApi({ restBase, nonce, nonceAction });
  }, [restBase, nonce, nonceAction]);

  const hasConfig = Boolean(state.config);
  const canonicalParticipants = useMemo(
    () => selectCanonicalParticipants(state, { allowFormFallback: true }),
    [state.plan?.participants, state.form?.participants]
  );
  const planCheckoutCapability = useMemo(
    () => resolvePlanCheckoutCapabilityProfile(state.plan, state.plan?.items, state.products),
    [state.plan, state.products]
  );
  const plannerActionState = useMemo(
    () =>
      buildPlannerActionState({
        plan: state.plan,
        items: state.plan?.items,
        form: state.form,
        products: state.products,
        availabilityIssue: state.availabilityIssue,
        canonicalParticipants,
        plannerApiAvailable: Boolean(plannerApi),
      }),
    [state.plan, state.form, state.products, state.availabilityIssue, canonicalParticipants, plannerApi]
  );

  // Define showToast early to avoid circular reference issues in bundled output
  const showToast = useCallback((message) => {
    dispatch({ type: ACTIONS.SET_TOAST, payload: { message } });
  }, []);

  const clearToast = useCallback(() => {
    dispatch({ type: ACTIONS.CLEAR_TOAST });
  }, []);

  const resolveStartTimeAgainstAvailability = useCallback(
    async ({
      product,
      desiredStartTime,
      date,
      participants,
      resourceId,
      openHours,
      clearIssueOnSuccess = true,
    }) => {
      const setAvailabilityIssue = (message) => {
        dispatch({
          type: ACTIONS.SET_AVAILABILITY_ISSUE,
          payload: { message, source: "availability" },
        });
      };

      const normalizedDesiredTime =
        sanitiseTimeString(desiredStartTime) ||
        sanitiseTimeString(product?.default_start_time ?? product?.defaultStartTime ?? "") ||
        null;

      const productId = toPositiveInt(product?.id);
      const normalizedDate = typeof date === "string" ? date.trim() : "";
      const durationMinutes =
        getDurationMinutes(product) ?? product?.duration?.minutes ?? product?.duration_minutes ?? null;

      if (!productId || !normalizedDate || !Number.isFinite(durationMinutes) || durationMinutes <= 0) {
        setAvailabilityIssue("Beschikbaarheid kon niet betrouwbaar worden bepaald voor deze activiteit.");
        return null;
      }

      const availabilityUrl = buildAvailabilitySlotsUrl(restBase);
      if (!availabilityUrl) {
        setAvailabilityIssue("Beschikbaarheid service is niet beschikbaar.");
        return null;
      }

      const normalizedParticipants = toPositiveInt(participants);
      if (normalizedParticipants === null) {
        setAvailabilityIssue("Aantal deelnemers ontbreekt voor de beschikbaarheidscontrole.");
        return null;
      }
      const normalizedResourceId = toPositiveInt(resourceId ?? product?.resource_id ?? product?.resourceId) ?? 0;
      const cacheKey = [
        productId,
        normalizedDate,
        normalizedParticipants,
        normalizedResourceId,
        durationMinutes,
      ].join("::");

      let payload = availabilityCacheRef.current.get(cacheKey);
      if (!payload) {
        const url = new URL(availabilityUrl, window.location.origin);
        url.searchParams.set("product_id", String(productId));
        url.searchParams.set("date", normalizedDate);
        if (normalizedResourceId > 0) {
          url.searchParams.set("resource_id", String(normalizedResourceId));
        }

        try {
          payload = await fetchJson(url.toString(), {
            nonce,
            nonceAction,
            referrerPolicy: "origin",
            credentials: "omit",
          });
          availabilityCacheRef.current.set(cacheKey, payload);
        } catch (error) {
          const message = error?.message || "Beschikbaarheid kon niet worden opgehaald.";
          console.warn("[Planner] Availability lookup failed.", error);
          setAvailabilityIssue(message);
          return null;
        }
      }

      const slots = Array.isArray(payload?.slots) ? payload.slots : [];
      const rawStartOptions = buildBookableStartOptions(slots, durationMinutes);
      const startOptions = filterStartOptionsWithinPlannerHours(
        rawStartOptions,
        durationMinutes,
        openHours
      );
      if (startOptions.length === 0) {
        setAvailabilityIssue("Geen beschikbare tijdsloten gevonden voor de gekozen activiteit.");
        return null;
      }

      if (clearIssueOnSuccess) {
        dispatch({ type: ACTIONS.CLEAR_AVAILABILITY_ISSUE });
      }

      const desiredMinutes = normalizedDesiredTime ? timeToMinutes(normalizedDesiredTime) : null;
      const defaultStartTime = sanitiseTimeString(
        product?.default_start_time ?? product?.defaultStartTime ?? ""
      );
      const defaultMinutes = defaultStartTime ? timeToMinutes(defaultStartTime) : null;

      if (Number.isFinite(desiredMinutes) && startOptions.includes(desiredMinutes)) {
        return minutesToTime(desiredMinutes);
      }

      if (Number.isFinite(defaultMinutes) && startOptions.includes(defaultMinutes)) {
        return minutesToTime(defaultMinutes);
      }

      if (Number.isFinite(desiredMinutes)) {
        const nextStart = startOptions.find((startMinutes) => startMinutes >= desiredMinutes);
        if (Number.isFinite(nextStart)) {
          return minutesToTime(nextStart);
        }
      }

      return minutesToTime(startOptions[0]);
    },
    [dispatch, nonce, restBase]
  );

  /**
   * Voeg de huidige planning toe aan de WooCommerce cart via AJAX/REST.
   * Kan vanuit elke widget worden aangeroepen via context.
   */
  const addPlanToCart = useCallback(async () => {
    // Zet de huidige planning om naar cart-items
    const planPayload = buildPlanPayload(state.plan, state.form, state.summary, state.config);
    try {
      // Stuur de items naar een custom endpoint die de WooCommerce cart vult
      const response = await fetch('/wp-json/booking-pro/v1/add-to-cart', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ plan: planPayload }),
        credentials: 'same-origin',
      });
      if (!response.ok) {
        throw new Error('Fout bij toevoegen aan winkelwagen');
      }
      const data = await response.json();
      showToast('Planning toegevoegd aan winkelwagen!');
      return data;
    } catch (error) {
      showToast(error.message || 'Kon niet toevoegen aan winkelwagen');
      return null;
    }
  }, [state.plan, state.form, state.summary, state.config, showToast]);

  const prefill = useMemo(() => normalisePrefill(bootConfig?.prefill), [bootConfig?.prefill]);
  const prefillFormAppliedRef = useRef(false);
  const prefillPlanAppliedRef = useRef(false);
  const prefillProductFetchedRef = useRef(false);
  const availabilityReconciledRef = useRef(new Set());
  const addActivityRef = useRef(() => false);
  const availabilityCacheRef = useRef(new Map());
  const sessionPrefillRef = useRef(readSessionPrefillQueue());
  const seededSessionPrefillRef = useRef(
    Array.isArray(sessionPrefillRef.current) ? [...sessionPrefillRef.current] : []
  );
  const [prefillQueueVersion, setPrefillQueueVersion] = useState(0);
  const productsFetchCancelledRef = useRef(false);
  const productsLoadedRef = useRef(Array.isArray(bootConfig?.products) && bootConfig.products.length > 0);
  const planHydratedRef = useRef(false);
  const draftHydratedRef = useRef(false);
  const sharedDraftTimestampRef = useRef(null);
  const canonicalPrefillResetRef = useRef(false);
  const isFreshProductPrefill =
    Boolean(prefill && prefill.productId) &&
    !Boolean(bootConfig?.planId || bootConfig?.plan_id || bootConfig?.plan?.id);

  useEffect(() => {
    if (!isFreshProductPrefill || canonicalPrefillResetRef.current) {
      return;
    }

    const prefillIdentity = buildFreshPrefillIdentity(prefill);
    if (typeof window !== "undefined" && window.sessionStorage && prefillIdentity) {
      try {
        const consumedPrefill = window.sessionStorage.getItem(FRESH_PREFILL_BOOTSTRAP_KEY);
        if (consumedPrefill === prefillIdentity) {
          canonicalPrefillResetRef.current = true;
          return;
        }
      } catch (error) {
        // ignore storage errors
      }
    }

    if (typeof window !== "undefined" && window.localStorage) {
      try {
        window.localStorage.removeItem(PLAN_DRAFT_STORAGE_KEY);
      } catch (error) {
        // ignore storage errors
      }
    }

    if (typeof window !== "undefined" && window.SBDPPlannerDomain?.store) {
      try {
        const plannerStore = window.SBDPPlannerDomain.store;
        if (typeof plannerStore.clearDraft === "function") {
          plannerStore.clearDraft();
        }
      } catch (error) {
        // ignore shared draft errors
      }
    }

    if (typeof window !== "undefined" && window.sessionStorage && prefillIdentity) {
      try {
        window.sessionStorage.setItem(FRESH_PREFILL_BOOTSTRAP_KEY, prefillIdentity);
      } catch (error) {
        // ignore storage errors
      }
    }

    canonicalPrefillResetRef.current = true;
  }, [isFreshProductPrefill, prefill]);

  useEffect(() => {
    if (isFreshProductPrefill) {
      return;
    }

    const queue = sessionPrefillRef.current;
    if (!Array.isArray(queue) || queue.length === 0) {
      return;
    }
    const hasAppendEntry = queue.some((entry) => entry?.append);
    if (hasAppendEntry && state.plan.items.length > 0) {
      return;
    }

    const withDate = queue.find(
      (entry) => typeof entry?.date === "string" && entry.date.trim() !== ""
    );
    if (withDate) {
      dispatch({
        type: ACTIONS.SET_FORM_FIELD,
        payload: { field: "date", value: withDate.date },
      });
    }

    const withParticipants = queue.find((entry) => {
      const raw = entry?.participants ?? entry?.people;
      const parsed = Number.parseInt(raw, 10);
      return Number.isFinite(parsed) && parsed > 0;
    });
    if (withParticipants) {
      const raw = withParticipants?.participants ?? withParticipants?.people;
      const parsed = Number.parseInt(raw, 10);
      dispatch({
        type: ACTIONS.SET_FORM_FIELD,
        payload: {
          field: "participants",
          value: String(parsed),
        },
      });
    }
  }, [state.form.date, state.form.participants, state.plan.items.length, dispatch]);

  useEffect(() => {
    if (planHydratedRef.current) {
      return;
    }

    const rawPlanId =
      bootConfig?.planId ?? bootConfig?.plan_id ?? bootConfig?.plan?.id ?? null;
    const planId = toPositiveInt(rawPlanId);
    const planSnapshot =
      bootConfig?.plan && typeof bootConfig.plan === "object" && Object.keys(bootConfig.plan).length > 0
        ? bootConfig.plan
        : null;

    if (!planId && !planSnapshot) {
      planHydratedRef.current = true;
      return;
    }

    if (!plannerApi) {
      return;
    }

    let cancelled = false;
    planHydratedRef.current = true;

    const token =
      typeof bootConfig?.planToken === "string" && bootConfig.planToken.trim() !== ""
        ? bootConfig.planToken.trim()
        : undefined;

    const applyPlan = (planPayload) => {
      if (cancelled || !planPayload) {
        return;
      }

      const hydrated = normalisePlanResponse(planPayload, stateRef.current);
      if (!hydrated) {
        dispatch({
          type: ACTIONS.PLAN_FAILURE,
          payload: { message: "Plan kon niet worden gelezen." },
        });
        return;
      }

      const queue = buildPlanPrefillQueue(hydrated.plan);
      sessionPrefillRef.current = queue;
      writeSessionPrefillQueue(queue);
      setPrefillQueueVersion((version) => version + 1);

      dispatch({
        type: ACTIONS.PLAN_SUCCESS,
        payload: hydrated,
      });

      prefillFormAppliedRef.current = true;
      prefillPlanAppliedRef.current = true;
    };

    dispatch({ type: ACTIONS.PLAN_REQUEST });

    if (planId) {
      plannerApi
        .getPlan(planId, { token })
        .then((response) => {
          if (cancelled) {
            return;
          }
          const planPayload = response?.plan || response;
          if (!planPayload) {
            dispatch({
              type: ACTIONS.PLAN_FAILURE,
              payload: { message: "Plan response bevat geen data." },
            });
            return;
          }
          applyPlan(planPayload);
        })
        .catch((error) => {
          if (cancelled) {
            return;
          }
          dispatch({
            type: ACTIONS.PLAN_FAILURE,
            payload: { message: error.message || "Kon bestaand plan niet laden." },
          });
        });
    } else if (planSnapshot) {
      try {
        applyPlan(planSnapshot);
      } catch (error) {
        dispatch({
          type: ACTIONS.PLAN_FAILURE,
          payload: { message: error.message || "Kon bestaand plan niet laden." },
        });
      }
    }

    return () => {
      cancelled = true;
    };
  }, [bootConfig, plannerApi, dispatch]);

  useEffect(() => {
    if (draftHydratedRef.current || !planHydratedRef.current) {
      return;
    }

    if (isFreshProductPrefill) {
      draftHydratedRef.current = true;
      return;
    }

    if (bootConfig?.planId || bootConfig?.plan_id || bootConfig?.plan?.id) {
      draftHydratedRef.current = true;
      return;
    }

    const plannerDomain =
      typeof window !== "undefined" && window.SBDPPlannerDomain ? window.SBDPPlannerDomain : null;
    const storedDraft =
      plannerDomain?.store && typeof plannerDomain.store.readDraft === "function"
        ? plannerDomain.store.readDraft()
        : readStoredDraft();

    draftHydratedRef.current = true;

    if (!storedDraft || typeof storedDraft !== "object" || !storedDraft.plan) {
      return;
    }

    sharedDraftTimestampRef.current =
      typeof storedDraft.timestamp === "number" ? storedDraft.timestamp : sharedDraftTimestampRef.current;
    dispatch({
      type: ACTIONS.HYDRATE_DRAFT,
      payload: storedDraft,
    });
  }, [bootConfig, dispatch, isFreshProductPrefill]);

  useEffect(() => {
    if (typeof window === "undefined") {
      return undefined;
    }

    const handler = (event) => {
      const detail = event?.detail || {};
      const rawId = detail.product_id ?? detail.productId ?? detail.id;
      const productId = Number.parseInt(rawId, 10);
      if (!Number.isFinite(productId) || productId <= 0) {
        return;
      }

      const rawParticipants = detail.participants ?? detail.people ?? detail.count ?? detail.slot?.count ?? null;
      const parsedParticipants =
        rawParticipants === null ? null : Number.parseInt(rawParticipants, 10);
      const participants =
        parsedParticipants !== null &&
        Number.isFinite(parsedParticipants) &&
        parsedParticipants > 0
          ? parsedParticipants
          : null;

      const entry = {
        ...(detail && typeof detail === "object" ? detail : {}),
        product_id: productId,
        date: typeof detail.date === "string" ? detail.date : null,
        time: typeof detail.time === "string" ? detail.time : null,
        participants,
        people: participants,
        resource_id: detail.resource_id ?? detail.resourceId ?? null,
        append: detail.append === true,
        combiItems: normalisePrefillCombiItems(
          detail.combi_items ??
            detail.combiItems ??
            detail.planItem?.options?.combiItems ??
            detail.options?.combiItems ??
            []
        ),
      };

      const queue = appendUniquePrefillEntry(sessionPrefillRef.current, entry);
      sessionPrefillRef.current = queue.map((item) => {
        if (item && typeof item === "object") {
          const raw = item.participants ?? item.people;
          const parsed = Number.parseInt(raw, 10);
          if (Number.isFinite(parsed) && parsed > 0) {
            return { ...item, participants: String(parsed), people: String(parsed) };
          }
        }
        return item;
      });
      writeSessionPrefillQueue(sessionPrefillRef.current);
      setPrefillQueueVersion((version) => version + 1);

      if (entry.date) {
        dispatch({
          type: ACTIONS.SET_FORM_FIELD,
          payload: { field: "date", value: entry.date },
        });
      }

      if (Number.isFinite(participants) && participants > 0) {
        dispatch({
          type: ACTIONS.SET_FORM_FIELD,
          payload: { field: "participants", value: String(participants) },
        });
      }
    };

    window.addEventListener("sbdp:planner/prefill", handler);

    return () => {
      window.removeEventListener("sbdp:planner/prefill", handler);
    };
  }, [dispatch, state.form.date, state.form.participants]);

  useEffect(() => {
    if (isFreshProductPrefill) {
      return;
    }

    const plannerDomain =
      typeof window !== "undefined" && window.SBDPPlannerDomain ? window.SBDPPlannerDomain : null;
    if (!plannerDomain?.api || typeof plannerDomain.api.syncCartState !== "function") {
      return;
    }

    plannerDomain.api.syncCartState().catch(() => {});
  }, []);

  useEffect(() => {
    if (typeof window === "undefined") {
      return undefined;
    }

    const applySharedDraft = () => {
      if (isFreshProductPrefill) {
        return;
      }

      const plannerDomain = window.SBDPPlannerDomain || null;
      const draft =
        plannerDomain?.store && typeof plannerDomain.store.readDraft === "function"
          ? plannerDomain.store.readDraft()
          : readStoredDraft();

      if (!draft || !draft.plan || !Array.isArray(draft.plan.items)) {
        return;
      }

      const incomingTimestamp =
        typeof draft.timestamp === "number" ? draft.timestamp : null;
      if (
        incomingTimestamp !== null &&
        sharedDraftTimestampRef.current !== null &&
        incomingTimestamp === sharedDraftTimestampRef.current
      ) {
        return;
      }

      if (incomingTimestamp !== null) {
        sharedDraftTimestampRef.current = incomingTimestamp;
      }

      dispatch({
        type: ACTIONS.HYDRATE_DRAFT,
        payload: draft,
      });
    };

    const onStorage = (event) => {
      if (isFreshProductPrefill) {
        return;
      }

      if (!event || event.key !== "sbdpPlannerDraftV1") {
        return;
      }
      applySharedDraft();
    };

    window.addEventListener("sbdp:planner/domain-updated", applySharedDraft);
    window.addEventListener("storage", onStorage);

    return () => {
      window.removeEventListener("sbdp:planner/domain-updated", applySharedDraft);
      window.removeEventListener("storage", onStorage);
    };
  }, [dispatch]);

  useEffect(() => {
    let cancelled = false;

    async function loadConfig() {
      console.log('🔧 Config Load Debug:', {
        restBase,
        nonce: nonce ? `${nonce.substring(0, 5)}...` : 'MISSING',
        url: `${restBase}/config`,
        bootConfig: bootConfig ? 'present' : 'MISSING'
      });
      dispatch({ type: ACTIONS.CONFIG_REQUEST });
      try {
        console.log('📡 Fetching config...');
        const response = await fetchJson(`${restBase}/config`, {
          referrerPolicy: "origin",
          credentials: "omit",
        });
        console.log('✅ Config loaded:', response);
        const restConfig = isValidPlannerConfig(response?.config) ? response.config : null;
        const fallbackConfig = isValidPlannerConfig(bootConfig?.config) ? bootConfig.config : null;
        const config = restConfig || fallbackConfig;
        if (!config) {
          throw new Error("Plannerconfig ontbreekt of is ongeldig.");
        }
        const timeOptions = generateTimeOptions(config.open_hours || {}, config.time_step_minutes);
        if (!cancelled) {
          console.log('📤 Dispatching CONFIG_SUCCESS');
          dispatch({
            type: ACTIONS.CONFIG_SUCCESS,
            payload: {
              config,
              timeOptions,
              configSource: restConfig ? "rest" : "boot-fallback",
              configDegraded: !restConfig,
              configError: restConfig ? null : "REST config invalid; using bootConfig.config fallback.",
            },
          });
          if (!restConfig) {
            console.warn("[Planner] /config response invalid; using bootConfig.config fallback.");
          }
        }
      } catch (error) {
        console.error('❌ Config load error:', error.message, error);
        if (!cancelled) {
          const fallbackConfig = isValidPlannerConfig(bootConfig?.config) ? bootConfig.config : null;
          if (!fallbackConfig) {
            dispatch({
              type: ACTIONS.CONFIG_FAILURE,
              payload: { message: error.message || "Plannerconfig kon niet geladen worden." },
            });
            return;
          }
          const timeOptions = generateTimeOptions(
            fallbackConfig.open_hours || {},
            fallbackConfig.time_step_minutes
          );
          dispatch({
            type: ACTIONS.CONFIG_SUCCESS,
            payload: {
              config: fallbackConfig,
              timeOptions,
              configSource: "boot-fallback",
              configDegraded: true,
              configError: error.message || "Plannerconfig kon niet via REST worden geladen.",
            },
          });
          console.warn("[Planner] /config failed; falling back to bootConfig.config.", error);
        }
      }
    }

    loadConfig();

    return () => {
      cancelled = true;
    };
  }, [restBase, nonce, bootConfig?.config]);

  useEffect(() => {
    // Only load products once when entering layout step with config ready
    if (state.step !== "layout" || state.loading.products || !hasConfig) {
      return;
    }

    // Skip if we already have products OR already started loading
    if (state.products.length > 0 || productsLoadedRef.current) {
      return;
    }

    // Mark as loading to prevent duplicate fetches
    productsLoadedRef.current = true;
    // Reset cancelled flag for this fetch
    productsFetchCancelledRef.current = false;

    async function loadProducts() {
      dispatch({ type: ACTIONS.PRODUCTS_REQUEST });
      try {
        let endpoint = `${restBase}/activities`;
        const query = buildProductQuery(prefill, sessionPrefillRef.current);
        if (query) {
          endpoint += `?${query}`;
        }

        const response = await fetchJson(endpoint, {
          referrerPolicy: "origin",
          credentials: "omit",
        });
        if (!productsFetchCancelledRef.current) {
          dispatch({
            type: ACTIONS.PRODUCTS_SUCCESS,
            payload: { products: response?.items || response?.products || [] },
          });
        } else {
          productsLoadedRef.current = false; // Reset so it can retry
        }
      } catch (error) {
        console.error('[Products] Load failed:', error);
        productsLoadedRef.current = false; // Reset on error so it can retry
        if (!productsFetchCancelledRef.current) {
          dispatch({
            type: ACTIONS.PRODUCTS_FAILURE,
            payload: { message: error.message },
          });
        }
      }
    }

    loadProducts();

    // NO cleanup function - we don't want to cancel on dependency changes
    // The productsLoadedRef guard prevents duplicate fetches
  }, [
    state.step,
    hasConfig,
  ]); // Removed: state.loading.products - this was causing cleanup during fetch!

  useEffect(() => {
    if (!prefill || prefillFormAppliedRef.current) {
      return;
    }

    if (prefill.date) {
      dispatch({
        type: ACTIONS.SET_FORM_FIELD,
        payload: { field: "date", value: prefill.date },
      });
    }

    const prefillParticipants = resolveExplicitPrefillParticipants(prefill);
    if (prefillParticipants !== null) {
      dispatch({
        type: ACTIONS.SET_FORM_FIELD,
        payload: { field: "participants", value: String(prefillParticipants) },
      });
    }

    prefillFormAppliedRef.current = true;
  }, [prefill, dispatch]);

  useEffect(() => {
    if (!isFreshProductPrefill || !prefill) {
      return;
    }

    const explicitDate =
      typeof prefill.date === "string" && prefill.date.trim() !== "" ? prefill.date.trim() : null;
    const explicitParticipants = resolveExplicitPrefillParticipants(prefill);

    if (explicitDate && state.form.date !== explicitDate) {
      dispatch({
        type: ACTIONS.SET_FORM_FIELD,
        payload: { field: "date", value: explicitDate },
      });
    }

    if (
      explicitParticipants !== null &&
      String(state.form.participants ?? "") !== String(explicitParticipants)
    ) {
      dispatch({
        type: ACTIONS.SET_FORM_FIELD,
        payload: { field: "participants", value: String(explicitParticipants) },
      });
    }

    if (!state.config || !explicitDate || explicitParticipants === null) {
      return;
    }

    const planDate = state.plan.days.length > 0 ? state.plan.days[0]?.date || null : null;
    const planParticipants = toPositiveInt(state.plan.participants);
    const needsCanonicalPlanSync =
      state.plan.days.length === 0 ||
      planDate !== explicitDate ||
      planParticipants !== explicitParticipants;

    if (!needsCanonicalPlanSync) {
      return;
    }

    dispatch({
      type: ACTIONS.START_PLANNING,
      payload: {
        date: explicitDate,
        participants: explicitParticipants,
        config: state.config,
      },
    });
  }, [
    isFreshProductPrefill,
    prefill,
    state.form.date,
    state.form.participants,
    state.plan.days,
    state.plan.participants,
    state.config,
    dispatch,
  ]);

  const setFormField = useCallback((field, value) => {
    dispatch({ type: ACTIONS.SET_FORM_FIELD, payload: { field, value } });
  }, []);

  const setParticipantsIngress = useCallback((value, options = {}) => {
    const mode = typeof options?.mode === "string" ? options.mode : "commit";
    const currentParticipants = selectCanonicalParticipants(stateRef.current, { allowFormFallback: true });
    const currentValue = Number.isFinite(currentParticipants) && currentParticipants > 0
      ? currentParticipants
      : 1;

    let rawValue = typeof value === "string" ? value.trim() : "";

    if (mode === "delta") {
      const delta = Number.parseInt(String(value), 10);
      if (!Number.isFinite(delta) || delta === 0) {
        return false;
      }
      rawValue = String(Math.max(1, currentValue + delta));
    }

    if (!/^\d*$/.test(rawValue)) {
      return false;
    }

    if (rawValue === "") {
      if (mode === "typing") {
        dispatch({
          type: ACTIONS.SET_FORM_FIELD,
          payload: { field: "participants", value: "" },
        });
        return true;
      }
      rawValue = String(currentValue);
    }

    const parsed = Number.parseInt(rawValue, 10);
    if (!Number.isFinite(parsed)) {
      return false;
    }

    dispatch({
      type: ACTIONS.SET_FORM_FIELD,
      payload: {
        field: "participants",
        value: String(Math.max(1, parsed)),
      },
    });
    return true;
  }, []);

  useEffect(() => {
    if (isFreshProductPrefill) {
      const explicitDate =
        typeof prefill?.date === "string" && prefill.date.trim() !== "" ? prefill.date.trim() : null;
      const explicitParticipants = resolveExplicitPrefillParticipants(prefill);
      const currentParticipants = toPositiveInt(state.form.participants);

      if (
        (explicitDate && state.form.date !== explicitDate) ||
        (explicitParticipants !== null && currentParticipants !== explicitParticipants)
      ) {
        return;
      }
    }

    const nextCount = toPositiveInt(state.form.participants);
    const nextDate =
      typeof state.form.date === "string" && state.form.date.trim() !== ""
        ? state.form.date
        : null;
    const updates = {};

    if (nextCount !== null && nextCount !== state.widgetPreferences?.count) {
      updates.count = nextCount;
    }
    if (nextDate && nextDate !== state.widgetPreferences?.visitDate) {
      updates.visitDate = nextDate;
    }

    if (Object.keys(updates).length > 0) {
      dispatch({ type: ACTIONS.SET_WIDGET_PREFERENCES, payload: updates });
    }
  }, [
    state.form.participants,
    state.form.date,
    state.widgetPreferences?.count,
    state.widgetPreferences?.visitDate,
    dispatch,
  ]);

  const setFilters = useCallback((filters) => {
    dispatch({ type: ACTIONS.SET_FILTERS, payload: filters });
  }, []);

  const setPlanRange = useCallback((range) => {
    dispatch({ type: ACTIONS.SET_PLAN_RANGE, payload: { range } });
  }, []);

  const startPlanning = useCallback(() => {
    const hasAppendEntry =
      Array.isArray(sessionPrefillRef.current) &&
      sessionPrefillRef.current.some((entry) => entry?.append);
    if (hasAppendEntry && state.plan.items.length > 0) {
      console.log('[Planner] ?? Append prefill detected, keeping existing plan');
      return;
    }

    const date = state.form.date;
    const participants = parseInt(state.form.participants, 10);

    if (!date) {
      dispatch({
        type: ACTIONS.SET_TOAST,
        payload: { message: "Kies eerst een datum om te plannen." },
      });
      return;
    }

    if (!Number.isFinite(participants) || participants <= 0) {
      dispatch({
        type: ACTIONS.SET_TOAST,
        payload: { message: "Vul het aantal deelnemers in (minimum 1)." },
      });
      return;
    }

    dispatch({
      type: ACTIONS.START_PLANNING,
      payload: {
        date,
        participants,
        config: state.config || {},
      },
    });
  }, [state.form, state.config]);

  useEffect(() => {
    if (isFreshProductPrefill) {
      return;
    }

    console.log('[Planner] 🚀 Auto-start check:', {
      loadingConfig: state.loading.config,
      hasConfig: !!state.config,
      formDate: state.form.date,
      formParticipants: state.form.participants,
      planDaysLength: state.plan.days.length,
      planDate: state.plan.days[0]?.date,
      planParticipants: state.plan.participants
    });
    
    if (state.loading.config || !state.config) {
      console.log('[Planner] ⏸️ Waiting for config...');
      return;
    }

    const date = state.form.date;
    const participantsValue = parseInt(state.form.participants, 10);

    if (!date || !Number.isFinite(participantsValue) || participantsValue <= 0) {
      console.log('[Planner] ⚠️ Invalid form data:', { date, participantsValue });
      return;
    }

    const planDate = state.plan.days.length > 0 ? state.plan.days[0]?.date || null : null;
    const planParticipants = Number.isFinite(state.plan.participants)
      ? state.plan.participants
      : null;

    const needsInit =
      state.plan.days.length === 0 ||
      planDate !== date ||
      planParticipants !== participantsValue;

    console.log('[Planner] 🎯 Needs init?', needsInit, {
      planDaysLength: state.plan.days.length,
      datesMatch: planDate === date,
      participantsMatch: planParticipants === participantsValue
    });

    if (needsInit) {
      console.log('[Planner] 🚀 DISPATCHING START_PLANNING!');
      dispatch({
        type: ACTIONS.START_PLANNING,
        payload: {
          date,
          participants: participantsValue,
          config: state.config || {},
        },
      });
    }
  }, [
    state.loading.config,
    state.config,
    state.form.date,
    state.form.participants,
    state.plan.days.length,
    state.plan.days[0]?.date,
    state.plan.participants,
    state.plan.items.length,
    prefillQueueVersion,
    isFreshProductPrefill,
  ]);

  // showToast and clearToast are defined earlier to avoid bundler hoisting issues

  const addActivity = useCallback(
    ({ productId, dayIndex, startTime }, options = {}) => {
      console.log('[addActivity] Called with:', { productId, dayIndex, startTime, options });
      console.log('[addActivity] State:', { 
        productsCount: state.products.length,
        planDays: state.plan.days.length,
        planItems: state.plan.items.length,
        participants: state.plan.participants,
        config: !!state.config,
        openHours: state.config?.open_hours
      });
      
      const emitAddEvent = (status, detail = {}) =>
        emitPlannerEvent("sbdp:planner/activity-add", {
          status,
          productId,
          dayIndex,
          startTime,
          ...detail,
        });

      const product = state.products.find((entry) => entry.id === productId);
      if (!product) {
        console.log('[addActivity] ❌ Product not found:', productId);
        showToast("Kon activiteit niet vinden.");
        emitAddEvent("error", { reason: "product_missing" });
        return false;
      }

      const participants = Math.max(
        1,
        selectCanonicalParticipants(state, { allowFormFallback: true })
      );

      const isCombi = options.source === "product-combi";
      const queuedPlanItem =
        options.planItem && typeof options.planItem === "object" ? { ...options.planItem } : null;

      // Capacity guard: only validate when max > 1 (real group capacity).
      // Products with max=1 are per-person items (food/drink etc.) — their quantity
      // scales with participant count, they must never block a multi-person plan.
      if (!isCombi && product.people?.enabled && (product.people?.max ?? 1) > 1) {
        const min = Math.max(1, product.people.min || 1);
        const max = Math.max(min, product.people.max || min);
        if (participants < min || participants > max) {
          showToast(
            `Aantal deelnemers voor "${product.name}" moet tussen ${min} en ${max} liggen.`
          );
          emitAddEvent("blocked", { reason: "participants_out_of_range" });
          return false;
        }
      }

      if (queuedPlanItem) {
        const ignoreId = options.ignoreId || null;
        const queuedCombiItems = normalisePrefillCombiItems(
          queuedPlanItem.options?.combiItems ?? queuedPlanItem.combiItems ?? []
        );
        const resolvedDate = resolvePlannerItemDate(state, dayIndex);
        const queuedPlannerInput =
          queuedPlanItem.plannerInput && typeof queuedPlanItem.plannerInput === "object"
            ? queuedPlanItem.plannerInput
            : {};
        const normalizedItem = {
          ...queuedPlanItem,
          dayIndex,
          date: resolvedDate,
          productId: queuedPlanItem.productId ?? queuedPlanItem.product_id ?? productId,
          product_id: queuedPlanItem.productId ?? queuedPlanItem.product_id ?? productId,
          ...(hasManualParticipantsOverride(queuedPlanItem)
            ? buildManualParticipants(queuedPlanItem.participants, participants)
            : buildInheritedParticipants(participants, DEFAULT_PARTICIPANTS)),
          startTime: sanitiseTimeString(queuedPlanItem.startTime ?? startTime),
          endTime: sanitiseTimeString(queuedPlanItem.endTime ?? ""),
          startMinutes: sanitiseTimeString(queuedPlanItem.startTime ?? startTime)
            ? timeToMinutes(sanitiseTimeString(queuedPlanItem.startTime ?? startTime))
            : undefined,
          endMinutes: sanitiseTimeString(queuedPlanItem.endTime ?? "")
            ? timeToMinutes(sanitiseTimeString(queuedPlanItem.endTime ?? ""))
            : undefined,
          durationMinutes: queuedPlanItem.durationMinutes ?? null,
          resourceId:
            queuedPlanItem.resourceId ?? queuedPlanItem.resource_id ?? options.resourceId ?? product.resource_id ?? null,
          resource_id:
            queuedPlanItem.resourceId ?? queuedPlanItem.resource_id ?? options.resourceId ?? product.resource_id ?? null,
          type:
            typeof queuedPlanItem.type === "string" && queuedPlanItem.type.trim() !== ""
              ? queuedPlanItem.type.trim()
              : queuedCombiItems.length > 0
              ? "arrangement"
              : queuedPlanItem.type,
          role:
            typeof queuedPlanItem.role === "string" && queuedPlanItem.role.trim() !== ""
              ? queuedPlanItem.role.trim()
              : undefined,
          groupId:
            typeof queuedPlanItem.groupId === "string" && queuedPlanItem.groupId.trim() !== ""
              ? queuedPlanItem.groupId.trim()
              : undefined,
          locked: resolvePlannerItemLocked(
            queuedPlanItem,
            typeof options.locked === "boolean" ? options.locked : null
          ),
          ...(queuedPlanItem?.manual_locked === true || queuedPlanItem?.time_source === TIME_SOURCE_MANUAL
            ? buildManualTimeFields()
            : buildAutoTimeFields(queuedPlanItem?.time_source || TIME_SOURCE_AUTO)),
          user_order: resolveUserOrder(queuedPlanItem, state.plan.items.length),
          source: queuedPlanItem.source || options.source || "product-prefill",
          traceId: resolvePlannerTraceId(queuedPlanItem) || resolvePlannerTraceId(options),
          trace_id: resolvePlannerTraceId(queuedPlanItem) || resolvePlannerTraceId(options),
          plannerInput: {
            ...queuedPlannerInput,
            date: resolvedDate,
          },
          options: {
            ...(queuedPlanItem.options && typeof queuedPlanItem.options === "object"
              ? queuedPlanItem.options
              : {}),
            combiItems: queuedCombiItems,
          },
          combiItems: queuedCombiItems,
          segments: Array.isArray(queuedPlanItem.segments) ? queuedPlanItem.segments : undefined,
          aggregate:
            queuedPlanItem.aggregate && typeof queuedPlanItem.aggregate === "object"
              ? queuedPlanItem.aggregate
              : undefined,
          cartMapping:
            queuedPlanItem.cartMapping && typeof queuedPlanItem.cartMapping === "object"
              ? queuedPlanItem.cartMapping
              : {
                  product_id: queuedPlanItem.productId ?? queuedPlanItem.product_id ?? productId,
                  quantity: queuedPlanItem.participants ?? participants,
                  line_hash: queuedPlanItem.plannerKey || "",
                },
          bookingCapability: classifyItemBookingCapability(queuedPlanItem, product),
        };
        const resolution = buildResolvedBookingPayload(normalizedItem, queuedCombiItems);
        const itemsToAdd = materializeResolvedBookingPayload(resolution, normalizedItem).map((entry) => ({
          ...entry,
          bookingCapability: classifyItemBookingCapability(entry, product),
        }));
        const renderableItems = itemsToAdd.filter(
          (entry) =>
            Number.isFinite(entry?.startMinutes) &&
            Number.isFinite(entry?.endMinutes) &&
            entry.endMinutes > entry.startMinutes
        );

        if (
          renderableItems.some((entry) =>
            itemConflicts(
              state.plan.items,
              dayIndex,
              entry.startMinutes,
              entry.endMinutes,
              options.ignoreId || null,
              { ignoreGroupId: entry.groupId || null }
            )
          )
        ) {
          const earliestStart = Math.min(
            ...renderableItems.map((entry) => entry.startMinutes).filter(Number.isFinite)
          );
          const latestEnd = Math.max(
            ...renderableItems.map((entry) => entry.endMinutes).filter(Number.isFinite)
          );
          const totalDuration = Number.isFinite(earliestStart) && Number.isFinite(latestEnd)
            ? Math.max(15, latestEnd - earliestStart)
            : null;
          const suggestedStart =
            totalDuration !== null
              ? findSuggestedPlannerStart({
                  items: state.plan.items,
                  dayIndex,
                  durationMinutes: totalDuration,
                  startFromMinutes: earliestStart,
                  openHours: state.config?.open_hours,
                  ignoreId,
                })
              : null;
          showToast(buildSuggestedSlotMessage("Deze tijd overlapt met een bestaande activiteit.", suggestedStart));
          emitAddEvent("blocked", { reason: "time_conflict" });
          return false;
        }

        if (hasMatchingArrangementIntent(state.plan.items, normalizedItem, resolution, queuedCombiItems)) {
          showToast("Dit arrangement staat al in je planning.");
          emitAddEvent("blocked", { reason: "duplicate_arrangement" });
          return false;
        }

        dispatch({ type: ACTIONS.ADD_ITEM, payload: { items: itemsToAdd } });
        emitAddEvent(resolution.status === "valid" ? "success" : "blocked", {
          itemId: itemsToAdd.find((entry) => !entry.role || entry.role === "anchor")?.id || itemsToAdd[0].id,
          resolutionStatus: resolution.status,
        });
        return true;
      }

      const startTimeValue = sanitiseTimeString(startTime);
      const durationMinutes = options.durationOverride ?? getDurationMinutes(product) ?? product.duration?.minutes ?? product.duration_minutes ?? 60;
      const baseStartMinutes = startTimeValue ? timeToMinutes(startTimeValue) : null;
      const date = resolvePlannerItemDate(state, dayIndex);
      const baseItem = {
        id: `plan-${product.id}-${Date.now()}-${Math.floor(Math.random() * 1000)}`,
        plannerKey: "",
        status: options.status || "planned",
        source: options.source || "day-planner",
        dayIndex,
        productId: product.id,
        product_id: product.id,
        title: product.name,
        date,
        ...buildInheritedParticipants(participants, DEFAULT_PARTICIPANTS),
        durationMinutes: Number.isFinite(durationMinutes) && durationMinutes > 0 ? durationMinutes : null,
        startMinutes: Number.isFinite(baseStartMinutes) ? baseStartMinutes : undefined,
        endMinutes:
          Number.isFinite(baseStartMinutes) && Number.isFinite(durationMinutes) && durationMinutes > 0
            ? baseStartMinutes + durationMinutes
            : undefined,
        startTime: startTimeValue || null,
        endTime:
          Number.isFinite(baseStartMinutes) && Number.isFinite(durationMinutes) && durationMinutes > 0
            ? minutesToTime(baseStartMinutes + durationMinutes)
            : null,
        pricing: product.pricing || {},
        totalCost: 0,
        price_pp: 0,
        fixedCost: 0,
        locked: typeof options.locked === "boolean" ? options.locked : false,
        ...buildAutoTimeFields(TIME_SOURCE_AUTO),
        user_order: state.plan.items.length + 1,
        resourceId:
          options.resourceId != null ? options.resourceId : product.resource_id ?? null,
        resource_id:
          options.resourceId != null ? options.resourceId : product.resource_id ?? null,
        suggested: Boolean(options.suggested),
        availabilitySource: options.availabilitySource || null,
        traceId: resolvePlannerTraceId(options),
        trace_id: resolvePlannerTraceId(options),
        options: {
          combiItems: Array.isArray(options.combiItems) ? options.combiItems : [],
        },
        combiItems: Array.isArray(options.combiItems) ? options.combiItems : [],
        plannerInput: {
          source: options.source || "day-planner",
          date,
          traceId: resolvePlannerTraceId(options) || undefined,
          trace_id: resolvePlannerTraceId(options) || undefined,
          options: {
            combiItems: Array.isArray(options.combiItems) ? options.combiItems : [],
          },
        },
        cartMapping: {
          product_id: product.id,
          quantity: participants,
          line_hash: "",
        },
        bookingCapability: resolveExplicitBookingCapability(product),
      };

      const resolution = buildResolvedBookingPayload(baseItem, options.combiItems || []);
      const itemsToAdd = materializeResolvedBookingPayload(resolution, baseItem).map((entry) => ({
        ...entry,
        bookingCapability: classifyItemBookingCapability(entry, product),
      }));
      const renderableItems = itemsToAdd.filter(
        (entry) =>
          Number.isFinite(entry?.startMinutes) &&
          Number.isFinite(entry?.endMinutes) &&
          entry.endMinutes > entry.startMinutes
      );

      const ignoreId = options.ignoreId || null;
      const isNewMainCombi = Array.isArray(options.combiItems) && options.combiItems.length > 0;

      for (const it of renderableItems) {
        if (
          !isCombi &&
          !isWithinPlannerHours(it.startMinutes, it.endMinutes, state.config?.open_hours)
        ) {
          console.log('[addActivity] ❌ Outside open hours');
          showToast("De activiteit past niet binnen de openingstijden.");
          emitAddEvent("blocked", { reason: "outside_open_hours" });
          return false;
        }

        if (
          !isCombi &&
          itemConflicts(state.plan.items, dayIndex, it.startMinutes, it.endMinutes, ignoreId, {
            isNewCombi: isNewMainCombi,
            ignoreGroupId: it.groupId || null,
          })
        ) {
          console.log('[addActivity] ❌ Time conflict against arranged time');
          const suggestedStart = findSuggestedPlannerStart({
            items: state.plan.items,
            dayIndex,
            durationMinutes: Math.max(15, it.endMinutes - it.startMinutes),
            startFromMinutes: it.startMinutes,
            openHours: state.config?.open_hours,
            ignoreId,
            ignoreGroupId: it.groupId || null,
          });
          showToast(buildSuggestedSlotMessage("Deze tijd overlapt met een bestaande activiteit.", suggestedStart));
          emitAddEvent("blocked", { reason: "time_conflict" });
          return false;
        }
      }

      console.log('[addActivity] ✅ Creating resolved items:', itemsToAdd, resolution);

      if (hasMatchingArrangementIntent(state.plan.items, baseItem, resolution, options.combiItems || [])) {
        showToast("Dit arrangement staat al in je planning.");
        emitAddEvent("blocked", { reason: "duplicate_arrangement" });
        return false;
      }

      dispatch({ type: ACTIONS.ADD_ITEM, payload: { items: itemsToAdd } });
      emitAddEvent(resolution.status === "valid" ? "success" : "blocked", {
        itemId: itemsToAdd.find((entry) => !entry.role || entry.role === "anchor")?.id || itemsToAdd[0].id,
        resolutionStatus: resolution.status,
      });
      return true;
    },
    [state.products, state.plan.items, state.plan.participants, state.form.participants, state.config, showToast]
  );

  const updateActivity = useCallback(
    (id, { startTime, participants, scope = "program" } = {}) => {
      const item = state.plan.items.find((entry) => entry.id === id);
      const emitUpdateEvent = (status, detail = {}) =>
        emitPlannerEvent("sbdp:planner/activity-update", {
          status,
          id,
          dayIndex: item?.dayIndex ?? null,
          scope,
          ...detail,
        });

      if (!item) {
        showToast("Activiteit niet gevonden.");
        emitUpdateEvent("error", { reason: "item_missing" });
        return false;
      }

      if (isImmutablePlannerItem(item)) {
        showToast("Dit vaste tijdslot kan niet worden aangepast.");
        emitUpdateEvent("blocked", { reason: "locked" });
        return false;
      }

      const product = state.products.find((entry) => entry.id === item.productId);
      if (!product) {
        showToast("Activiteit niet gevonden.");
        emitUpdateEvent("error", { reason: "product_missing" });
        return false;
      }

      let nextStart = item.startMinutes;
      const hasStartTimeChange = typeof startTime === "string" && startTime.trim() !== "";
      const hasParticipantChange = participants != null;
      let nextParticipants =
        hasParticipantChange
          ? toPositiveInt(participants)
          : toPositiveInt(item.participants);

      if (nextParticipants === null) {
        showToast("Aantal deelnemers ontbreekt.");
        emitUpdateEvent("error", { reason: "participants_missing" });
        return false;
      }

      if (hasStartTimeChange) {
        nextStart = timeToMinutes(startTime);
      }

      const isCombi = item.source === "product-combi";

      // Same per-person guard as addActivity: skip for max=1 products.
      if (!isCombi && product.people?.enabled && (product.people?.max ?? 1) > 1) {
        const min = Math.max(1, product.people.min || 1);
        const max = Math.max(min, product.people.max || min);
        if (nextParticipants < min || nextParticipants > max) {
          showToast(
            `Aantal deelnemers voor "${product.name}" moet tussen ${min} en ${max} liggen.`
          );
          emitUpdateEvent("blocked", { reason: "participants_out_of_range" });
          return false;
        }
      }

      const duration = item.durationMinutes ?? product.duration?.minutes ?? 0;
      const nextEnd = Math.max(nextStart + duration, nextStart + 1);

      const shift = nextStart - item.startMinutes;
      const moveGroup = Boolean(item.groupId) && scope !== "segment";
      const groupItems = moveGroup ? state.plan.items.filter((candidate) => candidate.groupId === item.groupId) : [item];
      const groupStart = Math.min(...groupItems.map((entry) => entry.startMinutes).filter(Number.isFinite));
      const groupEnd = Math.max(...groupItems.map((entry) => entry.endMinutes).filter(Number.isFinite));
      const groupDuration =
        Number.isFinite(groupStart) && Number.isFinite(groupEnd)
          ? Math.max(15, groupEnd - groupStart)
          : Math.max(15, nextEnd - nextStart);

      if (scope === "segment" && item.groupId && item.role === "anchor") {
        showToast("Het hoofdonderdeel van een arrangement verschuift het hele programma.");
        emitUpdateEvent("blocked", { reason: "anchor_locked" });
        return false;
      }

      for (const gItem of groupItems) {
        const gNextStart = gItem.startMinutes + shift;
        const gNextEnd = gItem.endMinutes + shift;

        if (!isCombi && !isWithinPlannerHours(gNextStart, gNextEnd, state.config?.open_hours)) {
          const suggestedStart = findSuggestedPlannerStart({
            items: state.plan.items,
            dayIndex: item.dayIndex,
            durationMinutes: moveGroup || scope !== "segment"
              ? groupDuration
              : Math.max(15, gNextEnd - gNextStart),
            startFromMinutes:
              typeof state.config?.open_hours?.start === "string"
                ? timeToMinutes(state.config.open_hours.start)
                : gItem.startMinutes,
            openHours: state.config?.open_hours,
            ignoreId: gItem.id,
            ignoreGroupId: moveGroup ? item.groupId || null : null,
          });
          showToast(
            buildSuggestedSlotMessage(
              scope === "segment"
                ? "Dit onderdeel valt buiten de openingstijden."
                : "Dit arrangement valt buiten de openingstijden.",
              suggestedStart
            )
          );
          emitUpdateEvent("blocked", { reason: "outside_open_hours" });
          return false;
        }

        if (
          !isCombi &&
          itemConflicts(state.plan.items, item.dayIndex, gNextStart, gNextEnd, gItem.id, {
            isNewCombi: Array.isArray(gItem.combiItems) && gItem.combiItems.length > 0,
            ignoreGroupId: moveGroup ? item.groupId || null : null,
          })
        ) {
          const suggestionDuration =
            moveGroup || scope !== "segment"
              ? groupDuration
              : Math.max(15, gNextEnd - gNextStart);
          const suggestedStart = findSuggestedPlannerStart({
            items: state.plan.items,
            dayIndex: item.dayIndex,
            durationMinutes: suggestionDuration,
            startFromMinutes: moveGroup ? groupStart : gNextStart,
            openHours: state.config?.open_hours,
            ignoreId: gItem.id,
            ignoreGroupId: moveGroup ? item.groupId || null : null,
          });
          showToast(
            buildSuggestedSlotMessage(
              scope === "segment"
                ? "Dit onderdeel overlapt met een andere activiteit."
                : "Een deel van dit arrangement overlapt met een bestaande activiteit.",
              suggestedStart
            )
          );
          emitUpdateEvent("blocked", { reason: "time_conflict" });
          return false;
        }
      }

      const nextStartTime = minutesToTime(nextStart);
      const nextEndTime = minutesToTime(nextEnd);

      const slotPricing = computeSlotPricing(product.pricing || {}, nextParticipants, {
        pricePerPerson: item.price_pp ?? product.price_pp,
        sourceProduct: product || item,
      });

      dispatch({
        type: ACTIONS.UPDATE_ITEM,
        payload: {
          id,
          changes: {
            startMinutes: nextStart,
            endMinutes: nextEnd,
            startTime: nextStartTime,
            endTime: nextEndTime,
            participants: nextParticipants,
            ...(hasParticipantChange
              ? buildManualParticipants(nextParticipants, DEFAULT_PARTICIPANTS)
              : {
                  participants_override: hasManualParticipantsOverride(item),
                  participants_source: item.participants_source || PARTICIPANTS_SOURCE_INHERITED,
                }),
            ...(hasStartTimeChange ? buildManualTimeFields() : {}),
            totalCost: slotPricing.total,
            price_pp: slotPricing.perPerson,
            fixedCost: slotPricing.fixedCost,
            plannerKey: [
              item.productId,
              item.date || (state.plan.days[item.dayIndex] ? state.plan.days[item.dayIndex].date : ""),
              nextStartTime,
              item.resourceId || item.resource_id || 0,
              nextParticipants,
              Array.isArray(item?.options?.combiItems)
                ? item.options.combiItems.map((entry) => entry?.id).filter(Boolean).join(",")
                : "",
            ].join("|"),
            cartMapping: {
              product_id: item.productId,
              quantity: nextParticipants,
              line_hash: [
                item.productId,
                item.date || (state.plan.days[item.dayIndex] ? state.plan.days[item.dayIndex].date : ""),
                nextStartTime,
                item.resourceId || item.resource_id || 0,
                nextParticipants,
                Array.isArray(item?.options?.combiItems)
                  ? item.options.combiItems.map((entry) => entry?.id).filter(Boolean).join(",")
                  : "",
              ].join("|"),
            },
          },
        },
      });

      emitUpdateEvent("success", {
        startTime: nextStartTime,
        endTime: nextEndTime,
        participants: nextParticipants,
        scope,
      });

      return true;
    },
    [state.plan.items, state.products, state.config, showToast]
  );

  useEffect(() => {
    if (state.loading.products || !state.plan.items.length || !state.products.length) {
      return;
    }

    const planSignature = state.plan.items
      .map((item) => `${item.id}:${item.startTime || ""}:${item.participants ?? ""}`)
      .join("|");

    if (!planSignature || availabilityReconciledRef.current.has(planSignature)) {
      return;
    }

    availabilityReconciledRef.current.add(planSignature);

    let cancelled = false;

    void (async () => {
      let suggestionCount = 0;
      let hadAvailabilityFailure = false;
      let firstSuggestedStart = null;

      for (const item of state.plan.items) {
        const product = state.products.find((entry) => entry.id === item.productId);
        if (!product || !item.startTime) {
          continue;
        }

        const resolvedStartTime = await resolveStartTimeAgainstAvailability({
          product,
          desiredStartTime: item.startTime,
          date: item.date || state.plan.days[item.dayIndex]?.date || state.form.date,
          participants: toPositiveInt(item.participants) ?? canonicalParticipants,
          resourceId: item.resourceId ?? item.resource_id ?? product.resource_id ?? null,
          openHours: state.config?.open_hours,
          clearIssueOnSuccess: false,
        });

        if (cancelled) {
          return;
        }

        if (!resolvedStartTime) {
          hadAvailabilityFailure = true;
          continue;
        }

        if (resolvedStartTime === item.startTime) {
          continue;
        }

        if (!shouldApplyAvailabilitySuggestedStart(item)) {
          suggestionCount += 1;
          firstSuggestedStart = firstSuggestedStart || resolvedStartTime;
          continue;
        }
      }

      if (cancelled) {
        return;
      }

      if (!hadAvailabilityFailure && state.availabilityIssue?.source === "availability") {
        dispatch({ type: ACTIONS.CLEAR_AVAILABILITY_ISSUE });
      }

      if (suggestionCount > 0) {
        const message =
          suggestionCount === 1
            ? `Beschikbaarheid controleren: een gekozen tijd lijkt niet beschikbaar. Mogelijke optie: ${firstSuggestedStart}.`
            : `${suggestionCount} gekozen tijden vragen beschikbaarheidscontrole.`;
        dispatch({
          type: ACTIONS.SET_AVAILABILITY_ISSUE,
          payload: {
            message,
            source: "availability",
            reasonCode: "availability_suggested_start",
          },
        });
        showToast(
          suggestionCount === 1
            ? "Beschikbaarheid controleren: de gekozen tijd is behouden."
            : "Beschikbaarheid controleren: gekozen tijden zijn behouden."
        );
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [
    canonicalParticipants,
    resolveStartTimeAgainstAvailability,
    showToast,
    state.form.date,
    state.availabilityIssue?.source,
    state.config?.open_hours,
    state.loading.products,
    state.plan.days,
    state.plan.items,
    state.products,
  ]);

  const removeActivity = useCallback(
    (id) => {
      const item = state.plan.items.find((entry) => entry.id === id);
      if (isImmutablePlannerItem(item)) {
        showToast("Dit vaste tijdslot kan niet worden verwijderd.");
        emitPlannerEvent("sbdp:planner/activity-remove", {
          status: "blocked",
          id,
          reason: "locked",
        });
        return;
      }

      dispatch({ type: ACTIONS.REMOVE_ITEM, payload: { id } });
      emitPlannerEvent("sbdp:planner/activity-remove", {
        status: "success",
        id,
      });
    },
    [state.plan.items, showToast]
  );

  useEffect(() => {
    addActivityRef.current = addActivity;
  }, [addActivity]);

  useEffect(() => {
    const queue = sessionPrefillRef.current;
    if (!Array.isArray(queue) || queue.length === 0) {
      return;
    }

    if (state.loading.config || !state.config) {
      return;
    }

    if (!state.plan.days.length) {
      return;
    }

    if (!state.products.length) {
      return;
    }

    const remaining = [];

    queue.forEach((entry) => {
      if (!entry) {
        return;
      }

      const rawId = entry.product_id ?? entry.productId ?? entry.id;
      const productId = Number.parseInt(rawId, 10);
      if (!Number.isFinite(productId) || productId <= 0) {
        return;
      }

      const product = state.products.find((item) => item.id === productId);
      if (!product) {
        remaining.push(entry);
        return;
      }

      const addFn = addActivityRef.current;
      if (typeof addFn !== "function") {
        remaining.push(entry);
        return;
      }

      const targetDate = typeof entry.date === "string" ? entry.date : null;
      const dayIndex = targetDate
        ? state.plan.days.findIndex((day) => day?.date === targetDate)
        : 0;
      const safeDayIndex = dayIndex >= 0 ? dayIndex : 0;
      const startTime = sanitiseTimeString(entry.time);

      const combiItemsForAdd =
        normalisePrefillCombiItems(
          entry.combi_items ??
            entry.combiItems ??
            entry.planItem?.options?.combiItems ??
            entry.options?.combiItems ??
            []
        );

      const added = addFn(
        { productId, dayIndex: safeDayIndex, startTime },
        {
          resourceId: entry.resource_id ?? product.resource_id ?? null,
          combiItems: combiItemsForAdd,
          traceId: entry.traceId ?? entry.trace_id ?? null,
          source:
            typeof entry.source === "string" && entry.source.trim() !== ""
              ? entry.source.trim() === "product_planner_legacy"
                ? "product-prefill"
                : entry.source.trim()
              : isFreshProductPrefill
                ? "product-prefill"
                : undefined,
          planItem: entry.planItem ?? null,
        }
      );

      if (
        added &&
        isFreshProductPrefill &&
        prefill?.productId === productId
      ) {
        prefillPlanAppliedRef.current = true;
      }

      /* Removed legacy combi-items individual adding logic since combi deals are now grouped cleanly under a single arrangement item via buildArrangement */

      if (!added) { console.log('[Prefill] Failed to add item. Removing from queue to prevent ghost-adds.'); }
    });

    if (remaining.length === 0) {
      sessionPrefillRef.current = [];
      clearSessionPrefillQueue();
    } else {
      const hadChange = remaining.length !== queue.length;
      sessionPrefillRef.current = remaining;
      writeSessionPrefillQueue(remaining);
      if (hadChange) {
        setPrefillQueueVersion((version) => version + 1);
      }
    }
  }, [
    isFreshProductPrefill,
    state.loading.config,
    state.config,
    state.plan.days,
    state.products,
    prefillQueueVersion,
    setPrefillQueueVersion,
  ]);

  // Home-widget and URL ingress may hydrate context only. Activity generation requires explicit user intent.
  const homeWidgetAppliedRef = React.useRef(false);
  useEffect(() => {
    if (isFreshProductPrefill) {
      return;
    }

    // Only run once
    if (homeWidgetAppliedRef.current) {
      return;
    }
    
    // Check for home widget data from multiple sources:
    // Load widget preferences using PreferenceManager with robust fallback chain:
    // 1. PreferenceManager.load() (handles window global, sessionStorage, URL params)
    // 2. Boot config prefill (from PHP-parsed URL parameters)
    // 3. Direct URL parameter parsing as final fallback
    const widgetData = (() => {
      // Try PreferenceManager first (most robust)
      const prefs = PreferenceManager.load();
      if (prefs && hasExplicitPlannerIngressPrefill(prefs)) {
        console.log('📦 Loaded preferences via PreferenceManager:', prefs);
        return prefs;
      }

      // Fallback: check bootConfig.prefill for URL parameters
      if (bootConfig?.prefill) {
        const p = bootConfig.prefill;
        // Map prefill fields to widget data format - trigger on ANY prefill field
        if (p.date || p.audience || p.vibe || p.duration || p.people || p.participants) {
          const fallbackCount = toPositiveInt(p.people ?? p.participants ?? p.count);
          const fallbackPrefs = {
            visitDate: p.date || getLocalDateIso(),
            count: fallbackCount ?? 2,
            audience: p.audience || 'vrienden',
            vibe: p.vibe || 'verrassend',
            duration: p.duration || 'hele-dag',
          };
          console.log('📦 Loaded preferences from bootConfig.prefill:', fallbackPrefs);
          return fallbackPrefs;
        }
      }

      // Last fallback: check URL parameters directly
      if (typeof window !== 'undefined') {
        const params = new URLSearchParams(window.location.search);
        const duration = params.get('duration');
        if (duration) {
          const urlPrefs = {
            visitDate: params.get('visitDate') || params.get('date') || getLocalDateIso(),
            count: parseInt(params.get('count') || params.get('participants') || params.get('people') || '2', 10),
            audience: params.get('audience') || 'vrienden',
            vibe: params.get('vibe') || 'verrassend',
            duration: duration,
            startActivity: params.get('start') || params.get('startActivity') || null,
          };
          console.log('📦 Loaded preferences from URL params:', urlPrefs);
          return urlPrefs;
        }
      }
      
      console.log('📦 No widget preferences found');
      return null;
    })();
    
    if (!widgetData || !hasExplicitPlannerIngressPrefill(widgetData)) {
      return;
    }
    
    // Validate preferences before using
    const validation = PreferenceManager.validate(widgetData);
    if (!validation.valid) {
      console.warn('⚠️ Invalid widget preferences:', validation.errors);
      // Continue anyway with defaults, but log warning
    }
    
    // Store widget preferences in state for display/editing
    const explicitIngressParticipants = toPositiveInt(
      widgetData.count ?? widgetData.participants ?? widgetData.people
    );

    dispatch({
      type: ACTIONS.SET_WIDGET_PREFERENCES,
      payload: {
        visitDate: widgetData.visitDate || null,
        duration: widgetData.duration || null,
        count: explicitIngressParticipants ?? 2,
        audience: widgetData.audience || null,
        vibe: widgetData.vibe || null,
        startActivity: widgetData.startActivity || null,
      },
    });

    if (widgetData.visitDate) {
      dispatch({
        type: ACTIONS.SET_FORM_FIELD,
        payload: { field: "date", value: widgetData.visitDate },
      });
    }

    if (explicitIngressParticipants !== null) {
      dispatch({
        type: ACTIONS.SET_FORM_FIELD,
        payload: { field: "participants", value: String(explicitIngressParticipants) },
      });
    }
    
    homeWidgetAppliedRef.current = true;
  }, [isFreshProductPrefill, bootConfig?.prefill, dispatch]);

  useEffect(() => {
    if (!prefill || !prefill.productId) {
      return;
    }

    if (!restBase || prefillProductFetchedRef.current) {
      return;
    }

    if (state.products.some((product) => product.id === prefill.productId)) {
      return;
    }

    prefillProductFetchedRef.current = true;

    (async () => {
      try {
        const response = await fetchJson(`${restBase}/activities?include[]=${prefill.productId}`, {
          referrerPolicy: "origin",
          credentials: "omit",
        });
        const extras = Array.isArray(response?.items)
          ? response.items
          : Array.isArray(response?.products)
            ? response.products
            : [];
        if (extras.length) {
          dispatch({
            type: ACTIONS.PRODUCTS_SUCCESS,
            payload: { products: extras, append: true },
          });
        }
      } catch (error) {
        // eslint-disable-next-line no-console
        console.warn("Kon product niet vooraf laden voor planner.", error);
      }
    })();
  }, [prefill, restBase, nonce, state.products, state.loading.products, dispatch]);

  useEffect(() => {
    if (!prefill || !state.config) {
      return;
    }

    if (prefillPlanAppliedRef.current) {
      return;
    }

    if (!state.plan.days.length && prefill.date) {
      const participants =
        resolveExplicitPrefillParticipants(prefill) ??
        selectCanonicalParticipants(stateRef.current, { allowFormFallback: true });
      dispatch({
        type: ACTIONS.START_PLANNING,
        payload: { date: prefill.date, participants, config: state.config },
      });
      return;
    }

    if (!state.plan.days.length) {
      return;
    }

    if (prefill.productId) {
      console.info("[Planner] Product prefill detected on load; not committing an activity without explicit user action.");
      prefillPlanAppliedRef.current = true;
      return;
    }
    prefillPlanAppliedRef.current = true;
  }, [
    prefill,
    state.config,
    state.loading.products,
    state.products,
    state.plan.days.length,
    state.plan.items,
    state.form.participants,
    dispatch,
  ]);


  const savePlan = useCallback(
    async (options = {}) => {
      const { silent = false } = options;

      if (!plannerApi) {
        const error = new Error("Planner service is niet beschikbaar.");
        if (!silent) {
          dispatch({
            type: ACTIONS.SET_ERROR,
            payload: { message: error.message },
          });
        }
        throw error;
      }

      try {
        if (!silent) {
          dispatch({
            type: ACTIONS.SET_TOAST,
            payload: { message: "Plan wordt opgeslagen." },
          });
        }

        const payload = buildPlanPayload(state.plan, state.form, state.summary, state.config);
        const response = state.plan.id
          ? await plannerApi.updatePlan(state.plan.id, payload)
          : await plannerApi.createPlan(payload);

        const savedPlan = response?.plan || {};
        const hydratedPlan = normalisePlanResponse(savedPlan, stateRef.current);
        const savedPlanId = toPositiveInt(savedPlan?.id);

        const savedToken =
          typeof savedPlan?.meta?.edit_token === "string" && savedPlan.meta.edit_token.trim() !== ""
            ? savedPlan.meta.edit_token.trim()
            : typeof savedPlan?.edit_token === "string" && savedPlan.edit_token.trim() !== ""
            ? savedPlan.edit_token.trim()
            : null;

        if (hydratedPlan) {
          dispatch({
            type: ACTIONS.PLAN_SUCCESS,
            payload: hydratedPlan,
          });
        } else if (savedPlanId !== null || savedToken) {
          dispatch({
            type: ACTIONS.SET_PLAN_METADATA,
            payload: {
              id: savedPlanId ?? undefined,
              editToken: savedToken ?? undefined,
            },
          });
        }

        if (!hydratedPlan && savedPlan.totals) {
          dispatch({
            type: ACTIONS.SET_SUMMARY,
            payload: { summary: normalizeTotals(savedPlan.totals, state.summary) },
          });
        }

        if (!silent) {
          dispatch({
            type: ACTIONS.SET_TOAST,
            payload: { message: "Plan opgeslagen." },
          });
        }

        return savedPlan;
      } catch (error) {
        if (!silent) {
          dispatch({
            type: ACTIONS.SET_ERROR,
            payload: { message: error.message || "Opslaan mislukt." },
          });
        } else {
          // eslint-disable-next-line no-console
          console.warn("[SBDP] Automatisch opslaan mislukt", error);
        }

        throw error;
      }
    },
    [plannerApi, state.plan, state.form, state.summary, state.config, dispatch]
  );

  const submitPlan = useCallback(
    async (options = {}) => {
      const successMessage = options.successMessage || "Boekingsaanvraag verzonden.";
      const errorMessage = options.errorMessage || "Verzenden mislukt.";
      const currentState = stateRef.current;
      const currentActionState = buildPlannerActionState({
        plan: currentState.plan,
        items: currentState.plan?.items,
        form: currentState.form,
        products: currentState.products,
        availabilityIssue: currentState.availabilityIssue,
        canonicalParticipants: selectCanonicalParticipants(currentState, { allowFormFallback: true }),
        plannerApiAvailable: Boolean(plannerApi),
      });

      if (!currentActionState.secondary_quote_enabled) {
        const message =
          currentActionState.blocking_reason_message || "Deze planning kan momenteel niet als offerte worden verstuurd.";
        dispatch({
          type: ACTIONS.SET_AVAILABILITY_ISSUE,
          payload: {
            message,
            source: "quote",
            reasonCode: currentActionState.blocking_reason_code,
          },
        });
        dispatch({
          type: ACTIONS.SET_TOAST,
          payload: { message },
        });
        emitPlannerEvent("sbdp:planner/action", {
          action: "request-quote",
          status: "blocked",
          reason: currentActionState.blocking_reason_code || "quote_unavailable",
        });
        return {
          planId: null,
          redirect: "",
          message,
          error: true,
        };
      }

      if (!plannerApi) {
        dispatch({
          type: ACTIONS.SET_ERROR,
          payload: { message: "Planner service is niet beschikbaar." },
        });
        emitPlannerEvent("sbdp:planner/action", {
          action: "request-quote",
          status: "error",
          reason: "service_unavailable",
        });
        return;
      }

      emitPlannerEvent("sbdp:planner/action", {
        action: "request-quote",
        status: "initiated",
      });

      try {
        const saved = await savePlan({ silent: true });
        const planId = toPositiveInt(saved?.id ?? state.plan.id);
        const savedCapability = resolvePlanCheckoutCapabilityProfile(
          saved,
          currentState.plan?.items,
          currentState.products
        );

        if (!planId) {
          throw new Error("Plan kon niet worden opgeslagen.");
        }

        if (savedCapability.route_intent === ROUTE_INTENT_BLOCKED) {
          const message = buildBlockingReasonMessage(
            savedCapability.reason_code,
            savedCapability.route_intent
          );
          dispatch({
            type: ACTIONS.SET_AVAILABILITY_ISSUE,
            payload: {
              message,
              source: "quote",
              reasonCode: savedCapability.reason_code,
            },
          });
          dispatch({
            type: ACTIONS.SET_TOAST,
            payload: { message },
          });
          emitPlannerEvent("sbdp:planner/action", {
            action: "request-quote",
            status: "blocked",
            reason: savedCapability.reason_code || "quote_blocked",
          });
          return {
            planId,
            redirect: "",
            message,
            error: true,
          };
        }

        const planToken =
          typeof saved?.meta?.edit_token === "string" && saved.meta.edit_token.trim() !== ""
            ? saved.meta.edit_token.trim()
            : state.plan.editToken;

        const quoteResult = await plannerApi.requestQuote(planId, { token: planToken });
        const quoteUrl =
          typeof quoteResult?.quote_url === "string" && quoteResult.quote_url.trim() !== ""
            ? quoteResult.quote_url.trim()
            : "";

        dispatch({ type: ACTIONS.CLEAR_AVAILABILITY_ISSUE });
        dispatch({
          type: ACTIONS.SET_TOAST,
          payload: { message: successMessage },
        });
        emitPlannerEvent("sbdp:planner/action", {
          action: "request-quote",
          status: "success",
          planId,
          redirect: quoteUrl || "",
        });

        if (quoteUrl && typeof window !== "undefined" && window.location) {
          navigateFromPlanner(quoteUrl);
          return { planId, redirect: quoteUrl };
        }
      } catch (error) {
        const message = error.message || errorMessage;
        if (isAvailabilityError(message)) {
          dispatch({
            type: ACTIONS.SET_AVAILABILITY_ISSUE,
            payload: { message, source: "quote", reasonCode: "availability_lookup_failed" },
          });
        }
        dispatch({
          type: ACTIONS.SET_ERROR,
          payload: { message },
        });
        emitPlannerEvent("sbdp:planner/action", {
          action: "request-quote",
          status: "error",
          message,
        });
      }
    },
    [plannerApi, savePlan, state.plan.id, state.plan.editToken, dispatch]
  );

  const addToCart = useCallback(async () => {
    const currentState = stateRef.current;
    const currentActionState = buildPlannerActionState({
      plan: currentState.plan,
      items: currentState.plan?.items,
      form: currentState.form,
      products: currentState.products,
      availabilityIssue: currentState.availabilityIssue,
      canonicalParticipants: selectCanonicalParticipants(currentState, { allowFormFallback: true }),
      plannerApiAvailable: Boolean(plannerApi),
    });

    if (!currentActionState.handoff_allowed) {
      const message =
        currentActionState.blocking_reason_message ||
        "Direct boeken is momenteel niet beschikbaar voor deze planning.";
      dispatch({
        type: ACTIONS.SET_AVAILABILITY_ISSUE,
        payload: {
          message,
          source: "checkout",
          reasonCode: currentActionState.blocking_reason_code,
        },
      });
      dispatch({
        type: ACTIONS.SET_TOAST,
        payload: { message },
      });
      emitPlannerEvent("sbdp:planner/action", {
        action: "queue",
        status: "blocked",
        reason: currentActionState.blocking_reason_code || "plan_not_direct_eligible",
      });
      return {
        planId: null,
        redirect: "",
        message,
        error: true,
      };
    }

    if (!plannerApi) {
      dispatch({
        type: ACTIONS.SET_ERROR,
        payload: { message: "Planner service is niet beschikbaar." },
      });
      emitPlannerEvent("sbdp:planner/action", {
        action: "queue",
        status: "error",
        reason: "service_unavailable",
      });
      return;
    }

    emitPlannerEvent("sbdp:planner/action", {
      action: "queue",
      status: "initiated",
    });

    try {
      const saved = await savePlan({ silent: true });
      const planId = toPositiveInt(saved?.id ?? state.plan.id);
      const savedCapability = resolvePlanCheckoutCapabilityProfile(
        saved,
        currentState.plan?.items,
        currentState.products
      );

      if (!planId) {
        throw new Error("Plan kon niet worden opgeslagen.");
      }

      if (savedCapability.route_intent !== ROUTE_INTENT_CHECKOUT) {
        const message = buildBlockingReasonMessage(
          savedCapability.reason_code,
          savedCapability.route_intent
        );
        dispatch({
          type: ACTIONS.SET_AVAILABILITY_ISSUE,
          payload: {
            message,
            source: savedCapability.route_intent === ROUTE_INTENT_QUOTE ? "checkout-quote" : "checkout",
            reasonCode: savedCapability.reason_code,
          },
        });
        dispatch({
          type: ACTIONS.SET_TOAST,
          payload: { message },
        });
        emitPlannerEvent("sbdp:planner/action", {
          action: "queue",
          status: "blocked",
          reason: savedCapability.reason_code || "plan_not_direct_eligible",
        });
        return {
          planId,
          redirect: "",
          message,
          error: true,
        };
      }

      const planToken =
          typeof saved?.meta?.edit_token === "string" && saved.meta.edit_token.trim() !== ""
            ? saved.meta.edit_token.trim()
            : state.plan.editToken;

      const queueResult = await plannerApi.queueBooking(planId, { token: planToken });
      const redirectUrl =
        typeof queueResult?.redirect_url === "string" && queueResult.redirect_url.trim() !== ""
          ? queueResult.redirect_url.trim()
          : "";
      const checkoutUrl =
        typeof queueResult?.checkout_url === "string" && queueResult.checkout_url.trim() !== ""
          ? queueResult.checkout_url.trim()
          : "";
      const cartUrl =
        typeof queueResult?.cart_url === "string" && queueResult.cart_url.trim() !== ""
          ? queueResult.cart_url.trim()
          : "";
      const queueMessage =
        typeof queueResult?.message === "string" && queueResult.message.trim() !== ""
          ? queueResult.message.trim()
          : "Plan toegevoegd aan winkelwagen.";

      emitPlannerEvent("sbdp:planner/action", {
        action: "queue",
        status: "success",
        planId,
        redirect: redirectUrl || cartUrl || checkoutUrl || "",
      });

      const plannerDomain =
        typeof window !== "undefined" && window.SBDPPlannerDomain ? window.SBDPPlannerDomain : null;
      if (plannerDomain?.api && typeof plannerDomain.api.syncCartState === "function") {
        // Keep cart state sync best-effort; redirect to Woo cart must not wait on this.
        plannerDomain.api.syncCartState().catch(() => {});
      }

      dispatch({ type: ACTIONS.CLEAR_AVAILABILITY_ISSUE });
      if (redirectUrl && typeof window !== "undefined" && window.location) {
        navigateFromPlanner(redirectUrl);
        return { planId, redirect: redirectUrl, message: queueMessage };
      }

      if (cartUrl && typeof window !== "undefined" && window.location) {
        navigateFromPlanner(cartUrl);
        return { planId, redirect: cartUrl, message: queueMessage };
      }

      if (checkoutUrl && typeof window !== "undefined" && window.location) {
        navigateFromPlanner(checkoutUrl);
        return { planId, redirect: checkoutUrl, message: queueMessage };
      }

      dispatch({
        type: ACTIONS.SET_TOAST,
        payload: { message: queueMessage },
      });
      return { planId, redirect: "", message: queueMessage };
    } catch (error) {
      const message = error.message || "Toevoegen aan winkelwagen mislukt.";
      if (isAvailabilityError(message)) {
        dispatch({
          type: ACTIONS.SET_AVAILABILITY_ISSUE,
          payload: { message, source: "cart", reasonCode: "availability_lookup_failed" },
        });
        dispatch({
          type: ACTIONS.SET_TOAST,
          payload: {
            message:
              "Een gekozen tijdslot is intussen bezet. Kies een nieuw tijdstip en probeer opnieuw.",
          },
        });
      }
      dispatch({
        type: ACTIONS.SET_ERROR,
        payload: { message },
      });
      emitPlannerEvent("sbdp:planner/action", {
        action: "queue",
        status: "error",
        message,
      });
      return {
        planId: null,
        redirect: "",
        message,
        error: true,
      };
    }
  }, [plannerApi, savePlan, state.plan.id, state.plan.editToken, dispatch]);

  const generatePresetPlan = useCallback(
    (theme) => {
      const addFn = addActivityRef.current;
      if (typeof addFn !== "function") {
        showToast("Planner is nog niet klaar om activiteiten toe te voegen.");
        return;
      }

      const normalizedTheme =
        typeof theme === "string" && theme.trim() !== "" ? theme.trim().toLowerCase() : "mix";

      const themeTokensMap = {
        bourgondisch: ["eten", "horeca", "proeverij", "culinair", "food"],
        actief: ["outdoor", "sport", "avontuur", "rondleiding", "boot"],
        teambuilding: ["team", "workshop", "escape", "challenge", "samen"],
        mystiek: ["museum", "kunst", "cultuur", "historie", "mystiek"],
      };

      const availableProducts = Array.isArray(state.products) ? state.products : [];
      if (availableProducts.length === 0) {
        showToast("Nog geen activiteiten beschikbaar om een planning te vullen.");
        return;
      }

      const preferredTokens = themeTokensMap[normalizedTheme] || [];
      const themedProducts =
        preferredTokens.length > 0
          ? availableProducts.filter((product) => {
              const tokens = getProductCategoryTokens(product);
              return tokens.some((token) => preferredTokens.includes(token));
            })
          : availableProducts;

      const sourceProducts = themedProducts.length > 0 ? themedProducts : availableProducts;

      const uniqueProducts = [];
      const seen = new Set();
      for (const product of sourceProducts) {
        if (!product || seen.has(product.id)) {
          continue;
        }
        uniqueProducts.push(product);
        seen.add(product.id);
        if (uniqueProducts.length >= 4) {
          break;
        }
      }

      if (uniqueProducts.length === 0) {
        showToast("Geen passende activiteiten gevonden voor dit thema.");
        return;
      }

      const baseStart =
        (Array.isArray(state.timeOptions) && state.timeOptions[0]?.value
          ? timeToMinutes(state.timeOptions[0].value)
          : null) ??
        (state.config?.open_hours?.start ? timeToMinutes(state.config.open_hours.start) : null);

      if (!Number.isFinite(baseStart)) {
        showToast("Geen geldige starttijd beschikbaar voor deze planning.");
        return;
      }

      void (async () => {
        let startMinutes = baseStart;
        let addedCount = 0;
        const plannerDate = state.plan.days[0]?.date || state.form.date;
        const plannerParticipants = canonicalParticipants;

        for (const product of uniqueProducts) {
          const duration =
            getDurationMinutes(product) ?? product?.duration?.minutes ?? product?.duration_minutes ?? null;
          if (!Number.isFinite(duration) || duration <= 0) {
            showToast(`"${product.name}" mist een geldige duur en kon niet netjes worden ingepland.`);
            continue;
          }

          const startTime = minutesToTime(startMinutes);
          const resolvedStartTime = await resolveStartTimeAgainstAvailability({
            product,
            desiredStartTime: startTime,
            date: plannerDate,
            participants: plannerParticipants,
            resourceId: product.resource_id ?? null,
            openHours: state.config?.open_hours,
          });
          if (!resolvedStartTime) {
            continue;
          }
          const added = addFn(
            { productId: product.id, dayIndex: 0, startTime: resolvedStartTime },
            {}
          );

          if (added) {
            addedCount += 1;
            const nextStartMinutes = resolvedStartTime ? timeToMinutes(resolvedStartTime) : startMinutes;
            startMinutes = nextStartMinutes + duration;
          }
        }

        if (addedCount === 0) {
          showToast("Het is niet gelukt om activiteiten toe te voegen.");
          return;
        }

        showToast(`Jeroen Bosch stelde ${addedCount} activiteiten voor.`);
      })();
    },
    [
      canonicalParticipants,
      resolveStartTimeAgainstAvailability,
      showToast,
      state.config?.open_hours,
      state.form.date,
      state.plan.days,
      state.products,
      state.timeOptions,
    ]
  );

  const handleAlternativeSwitch = useCallback(
    (slotKey, currentActivityId, nextAlternative) => {
      const currentItem = state.plan.items.find((item) => item.id === currentActivityId);
      if (!currentItem) {
        showToast("Activiteit niet gevonden om te wisselen.");
        return;
      }

      console.log(`🔄 Switching activity in ${slotKey}:`, {
        from: currentActivityId,
        to: nextAlternative.name,
        score: nextAlternative.score
      });
      
      // 1. Extract time from slotKey (format: 'day-0-slot-840')
      const timeMinutes = parseInt(slotKey.split('-').pop(), 10);
      const dayIndex = parseInt(slotKey.split('-')[1], 10) || 0;
      const startTime = minutesToTime(timeMinutes);
      
      // 2. Add new alternative (ignore conflict with current activity)
      const added = addActivity(
        { productId: nextAlternative.id, dayIndex, startTime },
        { resourceId: nextAlternative.resource_id ?? null, ignoreId: currentActivityId }
      );
      
      if (added) {
        // 3. Remove old activity only after new one is placed
        removeActivity(currentActivityId);

        // 4. Cycle index
        dispatch({
          type: ACTIONS.CYCLE_ALTERNATIVE,
          payload: { slotKey }
        });
        
        showToast(`Gewisseld naar: ${nextAlternative.name}`);
        console.log(`✅ Successfully switched to ${nextAlternative.name}`);
      } else {
        showToast(`Kon niet wisselen naar: ${nextAlternative.name}`);
        console.error(`❌ Failed to switch to ${nextAlternative.name}`);
      }
    },
    [addActivity, removeActivity, dispatch, showToast, state.plan.items]
  );

  // Set widget preferences (from home widget or user edit)
  const setWidgetPreferences = useCallback(
    (preferences) => {
      // Normalize and validate preferences
      const normalized = PreferenceManager.normalize(preferences);
      if (normalized) {
        // Save to storage for persistence
        PreferenceManager.save(normalized);
        
        dispatch({
          type: ACTIONS.SET_WIDGET_PREFERENCES,
          payload: normalized,
        });
      } else {
        console.warn('⚠️ Failed to set invalid preferences:', preferences);
      }
    },
    [dispatch]
  );

  // Clear the current plan (all items)
  const clearPlan = useCallback(() => {
    dispatch({ type: ACTIONS.CLEAR_PLAN });
    clearSessionPrefillQueue();
    clearStoredDraft();
    if (typeof window !== "undefined") {
      window.SBDP_HOME_WIDGET_PREFILL = null;
      try {
        window.sessionStorage?.removeItem("sbdp_home_widget_prefill");
      } catch (error) {
        console.warn("[Planner] Failed to clear home-widget prefill", error);
      }
    }
    homeWidgetAppliedRef.current = true;
  }, [dispatch]);

  // Regenerate plan with new preferences
  const regeneratePlan = useCallback(
    (newPreferences) => {
      console.log('🔄 Regenerating plan with preferences:', newPreferences);
      dispatch({ type: ACTIONS.CLEAR_AVAILABILITY_ISSUE });
      
      // 1. Normalize and validate preferences
      const normalized = PreferenceManager.normalize(newPreferences);
      if (!normalized) {
        console.error('❌ Invalid preferences for regeneration');
        showToast('Ongeldige voorkeuren. Probeer het opnieuw.');
        return;
      }
      
      // 2. Save to storage
      PreferenceManager.save(normalized);
      
      // 3. Update preferences in state
      dispatch({
        type: ACTIONS.SET_WIDGET_PREFERENCES,
        payload: normalized,
      });
      
      // 4. Clear current plan
      dispatch({ type: ACTIONS.CLEAR_PLAN });
      
      // 5. Reset the auto-fill flag so useEffect triggers again
      homeWidgetAppliedRef.current = false;
      
      // 6. Update window global for auto-fill to pick up
      const prefs = normalized || state.widgetPreferences;
      window.SBDP_HOME_WIDGET_PREFILL = {
        visitDate: prefs.visitDate || state.form?.date || getLocalDateIso(),
        count: prefs.count || 2,
        audience: prefs.audience || 'vrienden',
        vibe: prefs.vibe || 'verrassend',
        duration: prefs.duration || 'hele-dag',
      };
      
      showToast('Plan wordt opnieuw opgebouwd...');
      
      // 5. Force re-render to trigger auto-fill effect
      // The effect will run because homeWidgetAppliedRef is now false
    },
    [dispatch, showToast, state.widgetPreferences, state.form?.date]
  );

  /**
   * 🤖 Generate Smart Plan via Backend AI Suggestions API
   * 
   * Calls the backend AiSuggestionService to generate personalized activities
   * based on user preferences from the Home Widget (duration, audience, vibes).
   * 
   * @param {Object} preferences - User preferences from home widget
   * @param {string} preferences.date - Date in Y-m-d format
   * @param {string} preferences.duration - ochtend|middag|avond|hele-dag
   * @param {number} preferences.people - Number of participants
   * @param {string} preferences.audience - familie|vrienden|bedrijf|romantisch|solo
   * @param {string} preferences.vibe - Space-separated vibes: cultuur bourgondisch food actief
   */
  const generateSmartPlan = useCallback(
    async (preferences = {}) => {
      if (!plannerApi) {
        showToast("Planner API is nog niet beschikbaar.");
        return null;
      }

      const addFn = addActivityRef.current;
      if (typeof addFn !== "function") {
        showToast("Planner is nog niet klaar om activiteiten toe te voegen.");
        return null;
      }

      // Build preferences from current state + provided overrides
      const requestPayload = {
        date: preferences.date || state.form?.date || getTodayIso(),
        duration: preferences.duration || "middag",
        people: preferences.people || parseInt(state.form?.participants, 10) || 2,
        audience: preferences.audience || "",
        vibe: preferences.vibe || "",
      };

      console.log("🤖 Generating smart plan via backend API:", requestPayload);

      try {
        // Call backend AI suggestion API
        const response = await plannerApi.suggestActivities(requestPayload);
        
        if (!response || !response.activities || response.activities.length === 0) {
          const message = response?.summary || "Geen passende activiteiten gevonden.";
          showToast(message);
          console.warn("🤖 Backend returned no activities:", response);
          return response;
        }

        console.log("🤖 Backend suggested activities:", response.activities);
        console.log("🤖 Summary:", response.summary);

        // Ensure we have a plan started
        if (!state.plan.days.length) {
          dispatch({
            type: ACTIONS.START_PLANNING,
            payload: {
              date: requestPayload.date,
              participants: requestPayload.people,
              config: state.config,
            },
          });
        }

        // Add each suggested activity to the plan
        let addedCount = 0;
        for (const activity of response.activities) {
          const productId = activity.product_id;
          if (!productId) continue;

          // Extract start time from ISO string (e.g., "2025-01-15T09:30:00" -> "09:30")
          const startTime = activity.start
            ? activity.start.split("T")[1]?.substring(0, 5)
            : null;

          if (!startTime) continue;

          const added = addFn(
            { productId, dayIndex: 0, startTime },
            {
              quantity: activity.quantity || requestPayload.people,
              resourceId: activity.resource_id ?? null,
              suggested: true,
              availabilitySource: "availability",
            }
          );

          if (added) {
            addedCount += 1;
          }
        }

        if (addedCount > 0) {
          const fallbackNote =
            Array.isArray(response?.meta?.fallbacks) && response.meta.fallbacks.length > 0
              ? " (aangepast op beschikbaarheid)"
              : "";
          showToast((response.summary || `${addedCount} activiteiten toegevoegd aan je plan.`) + fallbackNote);
        } else {
          showToast("Activiteiten konden niet worden toegevoegd aan het plan.");
        }

        return response;
      } catch (error) {
        console.error("🤖 Smart plan generation failed:", error);
        showToast(error.message || "Fout bij het genereren van slimme suggesties.");
        return null;
      }
    },
    [plannerApi, state.form, state.config, state.plan.days.length, dispatch, showToast]
  );

  useEffect(() => {
    writeStoredFilters(state.filters);
  }, [state.filters]);

  useEffect(() => {
    const hasItems = Array.isArray(state.plan.items) && state.plan.items.length > 0;
    const hasForm = Boolean(state.form?.date);
    if (!hasItems && !hasForm) {
      clearStoredDraft();
      const plannerDomain =
        typeof window !== "undefined" && window.SBDPPlannerDomain ? window.SBDPPlannerDomain : null;
      if (plannerDomain?.store && typeof plannerDomain.store.clearDraft === "function") {
        plannerDomain.store.clearDraft();
      }
      return;
    }
    const payload = {
      plan: {
        id: state.plan.id,
        editToken: state.plan.editToken,
        participants: state.plan.participants,
        days: state.plan.days,
        items: state.plan.items,
      },
      form: state.form,
      summary: state.summary,
      availabilityIssue: state.availabilityIssue,
      timestamp: Date.now(),
    };
    sharedDraftTimestampRef.current = payload.timestamp;
    writeStoredDraft(payload);
    const plannerDomain =
      typeof window !== "undefined" && window.SBDPPlannerDomain ? window.SBDPPlannerDomain : null;
    if (plannerDomain?.store && typeof plannerDomain.store.syncPlan === "function") {
      plannerDomain.store.syncPlan(payload);
    }
  }, [state.plan, state.form, state.summary]);

  const contextValue = useMemo(
    () => ({
      state,
      selectors: {
        canonicalParticipants,
        planCheckoutCapability,
        plannerActionState,
      },
      actions: {
        setFormField,
        setParticipantsIngress,
        startPlanning,
        setFilters,
        setPlanRange,
        addActivity,
        updateActivity,
        removeActivity,
        showToast,
        clearToast,
        savePlan,
        submitPlan,
        addToCart,
        generatePresetPlan,
        generateSmartPlan,
        handleAlternativeSwitch,
        setWidgetPreferences,
        clearPlan,
        regeneratePlan,
      },
    }),
    [
      state,
      canonicalParticipants,
      planCheckoutCapability,
      plannerActionState,
      setFormField,
      setParticipantsIngress,
      startPlanning,
      setFilters,
      setPlanRange,
      addActivity,
      updateActivity,
      removeActivity,
      showToast,
      clearToast,
      savePlan,
      submitPlan,
      addToCart,
      generatePresetPlan,
      generateSmartPlan,
      handleAlternativeSwitch,
      setWidgetPreferences,
      clearPlan,
      regeneratePlan,
    ]
  );

  return <PlannerContext.Provider value={contextValue}>{children}</PlannerContext.Provider>;
}

PlannerProvider.propTypes = {
  bootConfig: PropTypes.object,
  children: PropTypes.node.isRequired,
};

PlannerProvider.defaultProps = {
  bootConfig: {},
};

export function usePlanner() {
  const context = useContext(PlannerContext);
  if (!context) {
    throw new Error("usePlanner moet binnen PlannerProvider gebruikt worden.");
  }
  return context;
}

function isAvailabilityError(message) {
  if (!message || typeof message !== "string") {
    return false;
  }
  return /tijdslot|beschikbaar|availability|slot/i.test(message);
}

function normalizeLineItems(items) {
  if (!Array.isArray(items)) {
    return [];
  }

  return items
    .map((item) => {
      if (!item || typeof item !== "object") {
        return null;
      }

      const normalized = { ...item };
      const productId = toPositiveInt(normalized.product_id ?? normalized.id);
      normalized.product_id = productId !== null ? productId : null;
      normalized.participants = toPositiveInt(normalized.participants) ?? 0;
      normalized.line_subtotal = roundCurrency(
        toFloat(
          normalized.line_subtotal ?? normalized.total ?? normalized.subtotal ?? 0
        )
      );

      const scheduleSource =
        normalized.schedule && typeof normalized.schedule === "object"
          ? normalized.schedule
          : {};

      normalized.schedule = normalizeSchedule(
        scheduleSource,
        normalized.start,
        normalized.end
      );

      return normalized;
    })
    .filter(Boolean);
}

function normalizeSchedule(schedule, fallbackStart, fallbackEnd) {
  const start =
    typeof schedule?.start === "string" && schedule.start.trim() !== ""
      ? schedule.start
      : typeof fallbackStart === "string"
      ? fallbackStart
      : null;

  const end =
    typeof schedule?.end === "string" && schedule.end.trim() !== ""
      ? schedule.end
      : typeof fallbackEnd === "string"
      ? fallbackEnd
      : null;

  return { start, end };
}

function normalizeMoneyRows(rows) {
  if (!Array.isArray(rows)) {
    return [];
  }

  return rows
    .map((row) => {
      if (!row || typeof row !== "object") {
        return null;
      }

      const amount = roundCurrency(toFloat(row.amount));
      const category =
        typeof row.category === "string"
          ? row.category
          : "";
      const meta =
        row.meta && typeof row.meta === "object" ? row.meta : undefined;

      return {
        ...row,
        code: typeof row.code === "string" ? row.code : "",
        label: typeof row.label === "string" ? row.label : "",
        amount,
        category,
        meta,
      };
    })
    .filter(Boolean);
}

function sumAmounts(rows) {
  if (!Array.isArray(rows)) {
    return 0;
  }

  return rows.reduce((total, row) => total + toFloat(row?.amount), 0);
}

function sumLineSubtotals(items) {
  if (!Array.isArray(items)) {
    return 0;
  }

  return items.reduce(
    (total, item) => total + toFloat(item?.line_subtotal ?? item?.total ?? 0),
    0
  );
}

function isWithinPlannerHours(startMinutes, endMinutes, openHours) {
  if (!openHours || !openHours.start || !openHours.end) {
    return true;
  }
  const openStart = timeToMinutes(openHours.start);
  const openEnd = timeToMinutes(openHours.end);
  return startMinutes >= openStart && endMinutes <= openEnd;
}

export default PlannerProvider;





