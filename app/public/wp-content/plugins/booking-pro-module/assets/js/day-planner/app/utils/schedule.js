import { addMinutes, clampMinutes, minutesToTime, timeToMinutes } from "./time";
import { calculateTotalCost, computeSlotPricing, summarizePlan, toFloat, roundCurrency } from "./price.js";
import { getDurationMinutes } from "./products.js";

export function buildDays(baseDate, dayCount) {
  const count = Math.max(1, dayCount || 1);
  const start = sanitiseDate(baseDate) || today();

  return Array.from({ length: count }, (_, index) => ({
    date: addDays(start, index),
  }));
}

export function isWithinOpenHours(startMinutes, endMinutes, openHours) {
  if (!openHours || !openHours.start || !openHours.end) {
    return true;
  }

  const openStart = timeToMinutes(openHours.start);
  const openEnd = timeToMinutes(openHours.end);

  return startMinutes >= openStart && endMinutes <= openEnd;
}

export function itemConflicts(items, dayIndex, startMinutes, endMinutes, ignoreId, options = {}) {
  const isNewCombi = options.isNewCombi || false;
  const ignoreGroupId = options.ignoreGroupId || null;

  const conflicts = items.filter((item) => {
    if (item.dayIndex !== dayIndex) {
      return false;
    }

    if (ignoreId && item.id === ignoreId) {
      return false;
    }

    if (ignoreGroupId && item.groupId === ignoreGroupId) {
      return false;
    }

    // Combi buffertijden zijn nu hard gecodeerd via segmenten (pre, post, vrije tijd),
    // dus we voegen geen onzichtbare extra buffertijd meer toe per los blokje.
    const buffer = 0;
    const hasConflict = startMinutes < (item.endMinutes + buffer) && endMinutes > (item.startMinutes - buffer);

    if (hasConflict) {
      console.log(`⚠️ CONFLICT DETECTED (buffer: ${buffer}m):`, {
        newActivity: { 
          start: startMinutes, 
          end: endMinutes, 
          time: `${Math.floor(startMinutes/60)}:${String(startMinutes%60).padStart(2,'0')}-${Math.floor(endMinutes/60)}:${String(endMinutes%60).padStart(2,'0')}` 
        },
        existingActivity: { 
          id: item.id,
          title: item.title,
          start: item.startMinutes, 
          end: item.endMinutes,
          time: `${Math.floor(item.startMinutes/60)}:${String(item.startMinutes%60).padStart(2,'0')}-${Math.floor(item.endMinutes/60)}:${String(item.endMinutes%60).padStart(2,'0')}`
        }
      });
    }
    
    return hasConflict;
  });

  return conflicts.length > 0;
}

export function findNextAvailableTime(items, dayIndex, durationMinutes, startFromMinutes = 480) {
  const MIN_GAP_MINUTES = 30;
  
  // Get all items for this day, sorted by start time
  const dayItems = items
    .filter(item => item.dayIndex === dayIndex)
    .sort((a, b) => a.startMinutes - b.startMinutes);
  
  if (dayItems.length === 0) {
    return startFromMinutes;
  }
  
  // Try to fit before first item
  const firstItem = dayItems[0];
  if (startFromMinutes + durationMinutes + MIN_GAP_MINUTES <= firstItem.startMinutes) {
    return startFromMinutes;
  }
  
  // Try to fit between items
  for (let i = 0; i < dayItems.length - 1; i++) {
    const current = dayItems[i];
    const next = dayItems[i + 1];
    const gapStart = current.endMinutes + MIN_GAP_MINUTES;
    const gapEnd = next.startMinutes - MIN_GAP_MINUTES;
    
    if (gapStart + durationMinutes <= gapEnd) {
      return gapStart;
    }
  }
  
  // Place after last item
  const lastItem = dayItems[dayItems.length - 1];
  return lastItem.endMinutes + MIN_GAP_MINUTES;
}

export function createPlannedItem(product, options) {
  const {
    dayIndex,
    startMinutes,
    participants,
    locked = false,
    resourceId = null,
    suggested = false,
    availabilitySource = null,
  } = options;
  const date =
    typeof options?.date === "string" && options.date.trim() !== ""
      ? options.date.trim()
      : "";
  const combiItems = Array.isArray(options?.combiItems) ? options.combiItems : [];

  // Combi deals are processed as their own separate activities in PlannerProvider.
  // We do NOT add their duration or price to the main core activity to avoid double charging or overlap issues.
  const duration = clampMinutes(options.durationOverride ?? getDurationMinutes(product) ?? product?.duration?.minutes ?? 60);
  const endMinutes = addMinutes(startMinutes, duration);

  const pricingBreakdown = computeSlotPricing(product?.pricing || {}, participants, {
    pricePerPerson: options.priceOverride ?? product?.price_pp,
    sourceProduct: product,
  });

  const pricePp = (pricingBreakdown.perPerson || 0);
  const itemTotalCost = (pricingBreakdown.fixedCost || 0) + (pricePp * participants);

  const plannerInput = {
    schemaVersion: "1.0.0",
    productId: product.id,
    productType: product?.type || product?.kind || "",
    date,
    participants,
    timeslot: {
      start: minutesToTime(startMinutes),
      end: minutesToTime(endMinutes),
      slotId: null,
    },
    resourceId:
      resourceId != null ? resourceId : product?.resource_id != null ? product.resource_id : 0,
    options: {
      combiItems,
      extras: [],
    },
    source: options?.source || "day-planner",
    locationContext: {
      resourceId:
        resourceId != null ? resourceId : product?.resource_id != null ? product.resource_id : 0,
      resourceLabel: product?.resource_title || "",
    },
  };

  const item = {
    id: `plan-${product.id}-${Date.now()}-${Math.floor(Math.random() * 1000)}`,
    plannerKey: "",
    status: options?.status || "planned",
    source: plannerInput.source,
    dayIndex,
    productId: product.id,
    product_id: product.id,
    productType: plannerInput.productType,
    title: product.name,
    date,
    participants,
    durationMinutes: duration,
    startMinutes,
    endMinutes,
    startTime: minutesToTime(startMinutes),
    endTime: minutesToTime(endMinutes),
    pricing: product.pricing || {},
    totalCost: itemTotalCost,
    price_pp: pricePp,
    fixedCost: pricingBreakdown.fixedCost,
    locked: Boolean(locked),
    resourceId:
      resourceId != null ? resourceId : product?.resource_id != null ? product.resource_id : null,
    resource_id:
      resourceId != null ? resourceId : product?.resource_id != null ? product.resource_id : null,
    suggested: Boolean(suggested),
    availabilitySource: availabilitySource || null,
    options: {
      combiItems,
      extras: [],
    },
    plannerInput,
    cartMapping: {
      product_id: product.id,
      quantity: participants,
      line_hash: "",
    },
  };

  item.plannerKey = [
    item.productId,
    item.date,
    item.startTime,
    item.resourceId || 0,
    item.participants,
    combiItems.map((entry) => entry?.id).filter(Boolean).join(","),
  ].join("|");
  item.cartMapping.line_hash = item.plannerKey;

  return item;
}

export function updatePlannedItem(item, updates, product, participants) {
  const next = {
    ...item,
    ...updates,
  };

  if (typeof updates.startMinutes === "number") {
    next.startTime = minutesToTime(updates.startMinutes);
  }

  if (typeof updates.endMinutes === "number") {
    next.endTime = minutesToTime(updates.endMinutes);
  }

  if (typeof participants === "number") {
    next.participants = participants;
  }

  if (product?.pricing) {
    const updatedPricing = computeSlotPricing(product.pricing, next.participants, {
      pricePerPerson: item?.price_pp ?? product?.price_pp,
      sourceProduct: product || item,
    });
    next.totalCost = updatedPricing.total;
    next.price_pp = updatedPricing.perPerson;
    next.fixedCost = updatedPricing.fixedCost;
  }

  return next;
}

export { calculateTotalCost, summarizePlan, toFloat, roundCurrency };

function sanitiseDate(value) {
  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
    return null;
  }

  return trimmed;
}

function today() {
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function addDays(dateString, offset) {
  if (offset === 0) {
    return dateString;
  }

  const [year, month, day] = dateString.split("-").map((part) => parseInt(part, 10));
  const date = new Date(Date.UTC(year, month - 1, day));
  date.setUTCDate(date.getUTCDate() + offset);

  const nextYear = date.getUTCFullYear();
  const nextMonth = String(date.getUTCMonth() + 1).padStart(2, "0");
  const nextDay = String(date.getUTCDate()).padStart(2, "0");
  return `${nextYear}-${nextMonth}-${nextDay}`;
}
