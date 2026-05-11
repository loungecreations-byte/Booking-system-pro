import { getPricePerPerson } from "./products.js";
import { minutesToTime, timeToMinutes } from "./time.js";

const DEFAULT_OPEN_HOURS = { start: "09:00", end: "22:00" };
const DEFAULT_STEP_MINUTES = 15;
const MIN_BREAK_MINUTES = 15;
const PREFERRED_BREAK_MINUTES = 30;
const LARGE_GAP_MINUTES = 45;
const IDEAL_MEAL_WINDOW = {
  lunch: { start: 12 * 60, end: 14 * 60 + 30, label: "lunch" },
  dinner: { start: 17 * 60 + 30, end: 21 * 60, label: "diner" },
};

const AREA_PATTERNS = [
  { label: "Parade", tokens: ["parade"] },
  { label: "Uilenburg", tokens: ["uilenburg", "uylenburg"] },
  { label: "Centrum", tokens: ["centrum", "binnenstad", "markt", "stadhuis"] },
  { label: "Bossche Broek", tokens: ["bossche broek"] },
  { label: "Muntel", tokens: ["muntel"] },
  { label: "Stationskwartier", tokens: ["station", "stationskwartier"] },
  { label: "Paleiskwartier", tokens: ["paleiskwartier"] },
];

const SLOT_TYPE_RULES = [
  { slotType: "breakfast", tokens: ["ontbijt", "brunch", "koffie", "coffee", "bossche bol"] },
  { slotType: "lunch", tokens: ["lunch", "broodje", "high tea", "proeverij"] },
  { slotType: "dinner", tokens: ["diner", "restaurant", "avondeten", "walking dinner"] },
  { slotType: "drinks", tokens: ["borrel", "cocktail", "bier", "wijn", "pubquiz"] },
  { slotType: "boat", tokens: ["boot", "vaart", "rondvaart", "boat"] },
  { slotType: "tour", tokens: ["tour", "wandeling", "rondleiding", "guide", "stadswandeling", "fietstocht"] },
  { slotType: "culture", tokens: ["museum", "kunst", "cultuur", "audio tour", "audiotour", "jeroen bosch"] },
  { slotType: "family", tokens: ["kids", "kinderen", "familie", "gezin", "speurtocht"] },
  { slotType: "romantic", tokens: ["romantisch", "romantic", "date", "sunset"] },
  { slotType: "indoor", tokens: ["indoor", "escape", "atelier", "workshop", "binnen"] },
];

const PREFERRED_WINDOWS = {
  breakfast: { label: "ochtend", start: 9 * 60, end: 11 * 60, center: 10 * 60 },
  lunch: { label: "middag", start: 11 * 60 + 30, end: 14 * 60 + 30, center: 13 * 60 },
  dinner: { label: "avond", start: 17 * 60 + 30, end: 21 * 60 + 30, center: 18 * 60 + 30 },
  drinks: { label: "middag/avond", start: 16 * 60, end: 21 * 60 + 30, center: 17 * 60 + 30 },
  boat: { label: "middag", start: 11 * 60, end: 17 * 60, center: 14 * 60 },
  tour: { label: "ochtend/middag", start: 10 * 60, end: 16 * 60 + 30, center: 11 * 60 + 30 },
  culture: { label: "ochtend/middag", start: 10 * 60, end: 17 * 60, center: 13 * 60 },
  indoor: { label: "middag", start: 11 * 60, end: 18 * 60, center: 14 * 60 },
  family: { label: "ochtend/middag", start: 10 * 60, end: 17 * 60, center: 13 * 60 },
  romantic: { label: "avond", start: 16 * 60 + 30, end: 22 * 60, center: 18 * 60 + 30 },
  activity: { label: "middag", start: 11 * 60, end: 18 * 60, center: 14 * 60 },
};

const COMPLEMENTARY_SLOT_TYPES = {
  breakfast: ["tour", "culture", "boat"],
  tour: ["lunch", "boat", "culture", "drinks"],
  culture: ["lunch", "drinks", "boat"],
  boat: ["lunch", "drinks", "dinner"],
  lunch: ["culture", "boat", "drinks", "tour"],
  indoor: ["drinks", "dinner", "culture"],
  family: ["lunch", "culture", "boat"],
  romantic: ["drinks", "dinner", "boat"],
  drinks: ["dinner", "boat", "culture"],
  dinner: ["drinks", "culture"],
  activity: ["lunch", "drinks", "dinner"],
};

const STARTER_THEMES = [
  {
    key: "first-visit",
    title: "Begin met een tour",
    description: "Sterke eerste keuze voor een eerste bezoek aan Den Bosch.",
    preferredSlotTypes: ["tour", "culture", "boat"],
    startMinutes: 10 * 60,
  },
  {
    key: "culinary",
    title: "Plan een culinaire middag",
    description: "Start met iets lekkers en bouw door richting borrel of diner.",
    preferredSlotTypes: ["lunch", "drinks", "dinner"],
    startMinutes: 12 * 60,
  },
  {
    key: "romantic",
    title: "Maak een romantische dag",
    description: "Kies een rustige experience die goed doorloopt naar de avond.",
    preferredSlotTypes: ["romantic", "boat", "dinner"],
    startMinutes: 16 * 60 + 30,
  },
  {
    key: "family",
    title: "Perfect met kinderen",
    description: "Laagdrempelig, logisch in te plannen en geschikt voor gezinnen.",
    preferredSlotTypes: ["family", "tour", "indoor"],
    startMinutes: 11 * 60,
  },
  {
    key: "evening",
    title: "Avond in Den Bosch",
    description: "Bouw richting diner, borrel of een latere activiteit.",
    preferredSlotTypes: ["dinner", "drinks", "romantic"],
    startMinutes: 17 * 60 + 30,
  },
];

export function buildPlannerInsights({ plan, products, config }) {
  const safePlan = plan || { days: [], items: [] };
  const safeProducts = Array.isArray(products) ? products : [];
  const openHours = config?.open_hours || DEFAULT_OPEN_HOURS;
  const productsById = new Map(
    safeProducts
      .filter((product) => product && typeof product === "object")
      .map((product) => [Number(product.id), buildProductMeta(product)])
  );

  const plannedItems = Array.isArray(safePlan.items) ? safePlan.items : [];
  const dayEntries = Array.isArray(safePlan.days) ? safePlan.days : [];
  const scheduledProductIds = new Set(
    plannedItems
      .map((item) => Number.parseInt(item?.productId, 10))
      .filter((id) => Number.isFinite(id) && id > 0)
  );

  const days = dayEntries.map((day, dayIndex) =>
    analyzeDay({
      day,
      dayIndex,
      items: plannedItems.filter((item) => item.dayIndex === dayIndex),
      productsById,
      allProducts: safeProducts,
      scheduledProductIds,
      openHours,
      config,
    })
  );

  const starterSuggestions =
    plannedItems.length === 0
      ? buildStarterSuggestions({ products: safeProducts, openHours, config })
      : [];

  const conflictCount = days.reduce(
    (count, day) => count + day.conflicts.filter((conflict) => conflict.tone === "critical").length,
    0
  );
  const advisoryConflictCount = days.reduce(
    (count, day) => count + day.conflicts.filter((conflict) => conflict.tone !== "critical").length,
    0
  );
  const gapCount = days.reduce((count, day) => count + day.gapSuggestions.length, 0);
  const routeIssueCount = days.reduce((count, day) => count + day.routeWarnings.length, 0);
  const suggestionCount =
    days.reduce((count, day) => count + day.quickSuggestions.length, 0) + starterSuggestions.length;

  const topMessages = [];
  if (conflictCount > 0) {
    topMessages.push(`${conflictCount} conflict${conflictCount === 1 ? "" : "en"} vraagt aandacht.`);
  } else if (advisoryConflictCount > 0) {
    topMessages.push(`${advisoryConflictCount} aandachtspunt${advisoryConflictCount === 1 ? "" : "en"} vraagt aandacht.`);
  }
  if (gapCount > 0) {
    topMessages.push(`${gapCount} open moment${gapCount === 1 ? "" : "en"} kan slim worden ingevuld.`);
  }
  if (routeIssueCount > 0) {
    topMessages.push("De route springt onnodig tussen gebieden.");
  }
  if (topMessages.length === 0 && plannedItems.length > 0) {
    topMessages.push("De dagopbouw oogt logisch. Je kunt nu verfijnen of direct boeken.");
  }

  const statusTone =
    conflictCount > 0 ? "warning" : advisoryConflictCount > 0 || routeIssueCount > 0 || gapCount > 0 ? "notice" : "ready";

  return {
    statusTone,
    summary: {
      criticalConflictCount: conflictCount,
      conflictCount,
      advisoryConflictCount,
      gapCount,
      routeIssueCount,
      suggestionCount,
      topMessages,
      topSuggestion:
        starterSuggestions[0] ||
        days.flatMap((day) => day.quickSuggestions).sort((left, right) => right.score - left.score)[0] ||
        null,
      mobileLabel: buildMobileSummaryLabel({
        itemCount: plannedItems.length,
        conflictCount,
        advisoryConflictCount,
        gapCount,
      }),
    },
    days,
    starterSuggestions,
  };
}

export function suggestPlannerInsertion({ product, plan, config }) {
  if (!product || typeof product !== "object") {
    return null;
  }

  const safePlan = plan || { days: [], items: [] };
  const days = Array.isArray(safePlan.days) ? safePlan.days : [];
  const allItems = Array.isArray(safePlan.items) ? safePlan.items : [];
  const stepMinutes = Math.max(
    5,
    Number.parseInt(config?.time_step_minutes, 10) || DEFAULT_STEP_MINUTES
  );
  const openHours = config?.open_hours || DEFAULT_OPEN_HOURS;

  const candidates = (days.length > 0 ? days : [{ date: null }]).map((_, dayIndex) => {
    const items = allItems
      .filter((item) => item.dayIndex === dayIndex)
      .slice()
      .sort((left, right) => left.startMinutes - right.startMinutes);

    const slot = findBestTimeSlot({
      product,
      dayIndex,
      dayItems: items,
      stepMinutes,
      openHours,
    });

    return slot
      ? {
          ...slot,
          dayIndex,
          densityPenalty: items.length * 12,
          finalScore: slot.score - items.length * 12,
        }
      : null;
  }).filter(Boolean);

  if (candidates.length === 0) {
    return null;
  }

  const best = candidates.sort((left, right) => right.finalScore - left.finalScore)[0];

  return {
    dayIndex: best.dayIndex,
    startMinutes: best.startMinutes,
    startTime: minutesToTime(best.startMinutes),
    endTime: minutesToTime(best.endMinutes),
    reason: best.reason,
    score: best.finalScore,
  };
}

function analyzeDay({
  day,
  dayIndex,
  items,
  productsById,
  allProducts,
  scheduledProductIds,
  openHours,
  config,
}) {
  const sortedItems = items
    .map((item) => enrichPlannedItem(item, productsById.get(Number(item.productId))))
    .sort((left, right) => left.startMinutes - right.startMinutes);

  const conflicts = [];
  const routeWarnings = [];
  const quickSuggestions = [];
  const itemNotes = [];
  const conflictItemIds = new Set();

  sortedItems.forEach((item) => {
    const timingNote = buildTimingNote(item);
    if (timingNote) {
      itemNotes.push(timingNote);
    }
  });

  for (let index = 0; index < sortedItems.length - 1; index += 1) {
    const current = sortedItems[index];
    const next = sortedItems[index + 1];

    if (current.groupId && current.groupId === next.groupId) {
      continue;
    }

    const gapMinutes = next.startMinutes - current.endMinutes;
    const travelMinutes = estimateTravelMinutes(current.area, next.area);

    if (gapMinutes < 0) {
      const overlapMinutes = Math.abs(gapMinutes);
      const suggestionTime = next.startMinutes + overlapMinutes + PREFERRED_BREAK_MINUTES;
      const message = `"${current.title}" overlapt met "${next.title}" (${formatDuration(overlapMinutes)}).`;

      conflicts.push({
        id: `overlap-${current.id}-${next.id}`,
        tone: "critical",
        type: "overlap",
        title: "Overlap in je planning",
        message,
        suggestion: `Verplaats "${next.title}" naar ${minutesToTime(suggestionTime)} of later voor minstens ${PREFERRED_BREAK_MINUTES} min rust.`,
        relatedItemIds: [current.id, next.id],
      });
      conflictItemIds.add(current.id);
      conflictItemIds.add(next.id);
      continue;
    }

    if (gapMinutes < travelMinutes) {
      const shortage = travelMinutes - gapMinutes;
      conflicts.push({
        id: `travel-${current.id}-${next.id}`,
        tone: "warning",
        type: "travel",
        title: "Aansluiting is krap",
        message: `Van ${current.area} naar ${next.area} is ${gapMinutes} min waarschijnlijk te kort.`,
        suggestion: `Plan minimaal ${Math.max(travelMinutes, PREFERRED_BREAK_MINUTES)} min marge of schuif "${next.title}" ${formatDuration(shortage)} op.`,
        relatedItemIds: [current.id, next.id],
      });
      conflictItemIds.add(current.id);
      conflictItemIds.add(next.id);
    } else if (gapMinutes < MIN_BREAK_MINUTES) {
      conflicts.push({
        id: `break-${current.id}-${next.id}`,
        tone: "warning",
        type: "break",
        title: "Korte aansluiting",
        message: `Er zit maar ${formatDuration(gapMinutes)} tussen "${current.title}" en "${next.title}".`,
        suggestion: `Plan minstens ${MIN_BREAK_MINUTES} min ertussen; ${PREFERRED_BREAK_MINUTES} min heeft de voorkeur.`,
        relatedItemIds: [current.id, next.id],
      });
      conflictItemIds.add(current.id);
      conflictItemIds.add(next.id);
    } else if (gapMinutes < PREFERRED_BREAK_MINUTES) {
      conflicts.push({
        id: `preferred-break-${current.id}-${next.id}`,
        tone: "warning",
        type: "preferred-break",
        title: "Pauze is aan de krappe kant",
        message: `Er zit ${formatDuration(gapMinutes)} tussen "${current.title}" en "${next.title}". Dat kan, maar ${PREFERRED_BREAK_MINUTES} min is prettiger.`,
        suggestion: `Laat idealiter ${PREFERRED_BREAK_MINUTES} min ruimte tussen deze onderdelen.`,
        relatedItemIds: [current.id, next.id],
      });
    } else if (gapMinutes >= LARGE_GAP_MINUTES) {
      const gapSuggestion = buildGapSuggestion({
        gapStart: current.endMinutes,
        gapEnd: next.startMinutes,
        beforeItem: current,
        afterItem: next,
        dayIndex,
        allProducts,
        scheduledProductIds,
        openHours,
        config,
      });

      if (gapSuggestion) {
        quickSuggestions.push(gapSuggestion);
      }
    }

    if (areSimilarExperiences(current, next)) {
      conflicts.push({
        id: `similar-${current.id}-${next.id}`,
        tone: "notice",
        type: "similar",
        title: "Twee vergelijkbare activiteiten direct na elkaar",
        message: `"${current.title}" en "${next.title}" lijken erg op elkaar in opzet.`,
        suggestion: "Wissel af met een pauze, lunch of iets in een andere sfeer.",
        relatedItemIds: [current.id, next.id],
      });
    }

    if (index >= 1) {
      const previous = sortedItems[index - 1];
      if (
        previous.area !== "Den Bosch" &&
        previous.area === next.area &&
        current.area !== previous.area
      ) {
        routeWarnings.push({
          id: `route-${previous.id}-${current.id}-${next.id}`,
          title: "Onlogische route",
          message: `Je springt van ${previous.area} naar ${current.area} en weer terug naar ${next.area}.`,
          suggestion: `Probeer "${current.title}" dichter bij ${current.area} te combineren.`,
        });
      }
    }
  }

  const mealtimeSuggestion = buildMissingMealSuggestion({
    items: sortedItems,
    dayIndex,
    allProducts,
    scheduledProductIds,
    openHours,
    config,
  });

  if (mealtimeSuggestion) {
    quickSuggestions.push(mealtimeSuggestion);
  }

  const routeSummary =
    routeWarnings.length > 0
      ? routeWarnings[0].message
      : buildAreaGroupingHint(sortedItems);

  return {
    day,
    dayIndex,
    items: sortedItems,
    conflicts,
    conflictItemIds: Array.from(conflictItemIds),
    routeWarnings,
    routeSummary,
    itemNotes,
    quickSuggestions: quickSuggestions
      .sort((left, right) => right.score - left.score)
      .slice(0, 3),
    gapSuggestions: quickSuggestions.filter((suggestion) => suggestion.kind === "gap"),
  };
}

function buildGapSuggestion({
  gapStart,
  gapEnd,
  beforeItem,
  afterItem,
  dayIndex,
  allProducts,
  scheduledProductIds,
  openHours,
  config,
}) {
  const gapMinutes = gapEnd - gapStart;
  if (gapMinutes < LARGE_GAP_MINUTES) {
    return null;
  }

  const midpoint = gapStart + Math.floor(gapMinutes / 2);
  const targetSlotType = resolvePreferredGapType({
    beforeItem,
    afterItem,
    midpoint,
  });

  const candidates = allProducts
    .filter((product) => {
      const productId = Number.parseInt(product?.id, 10);
      return Number.isFinite(productId) && !scheduledProductIds.has(productId);
    })
    .map((product) => {
      const meta = buildProductMeta(product);
      const durationMinutes = meta.durationMinutes;
      const travelBefore = estimateTravelMinutes(beforeItem.area, meta.area);
      const travelAfter = estimateTravelMinutes(meta.area, afterItem.area);
      const availableDuration = gapMinutes - travelBefore - travelAfter;

      if (availableDuration < Math.max(30, durationMinutes)) {
        return null;
      }

      const insertion = suggestPlannerInsertion({
        product,
        plan: {
          days: [{ date: null }],
          items: [
            { ...beforeItem, dayIndex: 0 },
            { ...afterItem, dayIndex: 0 },
          ],
        },
        config: {
          ...config,
          open_hours: openHours,
        },
      });

      if (!insertion) {
        return null;
      }

      if (insertion.startMinutes < gapStart || insertion.startMinutes + durationMinutes > gapEnd) {
        return null;
      }

      let score = 50;
      if (meta.slotType === targetSlotType) {
        score += 28;
      }
      if (meta.area === beforeItem.area || meta.area === afterItem.area) {
        score += 24;
      }
      if (meta.preferredWindow && midpoint >= meta.preferredWindow.start && midpoint <= meta.preferredWindow.end) {
        score += 18;
      }

      const reason = buildSuggestionReason({
        meta,
        targetSlotType,
        beforeItem,
        afterItem,
        gapMinutes,
      });

      return {
        kind: "gap",
        id: `gap-${dayIndex}-${product.id}-${gapStart}`,
        score,
        title: meta.title,
        area: meta.area,
        priceLabel: meta.priceLabel,
        startTime: insertion.startTime,
        endTime: insertion.endTime,
        dayIndex,
        productId: product.id,
        badge: meta.slotTypeLabel,
        reason,
        ctaLabel:
          targetSlotType === "breakfast"
            ? "Plan in de ochtend"
            : targetSlotType === "lunch"
              ? "Plan rond lunch"
              : targetSlotType === "dinner"
                ? "Plan in de avond"
                : "Plan dit moment",
      };
    })
    .filter(Boolean)
    .sort((left, right) => right.score - left.score);

  if (candidates.length > 0) {
    return candidates[0];
  }

  const gapLabel =
    targetSlotType === "lunch"
      ? "Je hebt hier ruimte voor lunch."
      : targetSlotType === "dinner"
      ? "Hier past een diner of borrel goed."
      : "Dit blok kan slim worden ingevuld met iets korts in de buurt.";

  return {
    kind: "gap",
    id: `gap-note-${dayIndex}-${gapStart}`,
    score: 20,
    title: "Vrij moment",
    area: beforeItem.area,
    priceLabel: null,
    startTime: minutesToTime(gapStart),
    endTime: minutesToTime(gapEnd),
    dayIndex,
    productId: null,
    badge: "Open moment",
    reason: `${gapLabel} (${formatDuration(gapMinutes)} tussen ${beforeItem.title} en ${afterItem.title}).`,
    ctaLabel: null,
  };
}

function buildMissingMealSuggestion({
  items,
  dayIndex,
  allProducts,
  scheduledProductIds,
  openHours,
  config,
}) {
  if (items.length === 0) {
    return null;
  }

  const firstStart = items[0].startMinutes;
  const lastEnd = items[items.length - 1].endMinutes;
  const hasLunch = items.some((item) => item.slotType === "lunch" || item.slotType === "breakfast");
  const hasDinner = items.some((item) => item.slotType === "dinner" || item.slotType === "drinks");

  const mealType =
    !hasLunch && firstStart <= IDEAL_MEAL_WINDOW.lunch.start && lastEnd >= IDEAL_MEAL_WINDOW.lunch.end
      ? "lunch"
      : !hasDinner && lastEnd >= IDEAL_MEAL_WINDOW.dinner.start
      ? "dinner"
      : null;

  if (!mealType) {
    return null;
  }

  const mealWindow = IDEAL_MEAL_WINDOW[mealType];
  const anchorBefore =
    [...items].reverse().find((item) => item.endMinutes <= mealWindow.start) ||
    items[0];
  const anchorAfter =
    items.find((item) => item.startMinutes >= mealWindow.end) || items[items.length - 1];

  return buildGapSuggestion({
    gapStart: mealWindow.start,
    gapEnd: mealWindow.end,
    beforeItem: anchorBefore,
    afterItem: anchorAfter,
    dayIndex,
    allProducts,
    scheduledProductIds,
    openHours,
    config,
  });
}

function buildStarterSuggestions({ products, openHours, config }) {
  const usedProductIds = new Set();

  return STARTER_THEMES.map((theme) => {
    const product = products
      .map((entry) => buildProductMeta(entry))
      .filter((meta) => !usedProductIds.has(meta.id))
      .sort((left, right) => {
        const leftScore = scoreStarterThemeMatch(left, theme);
        const rightScore = scoreStarterThemeMatch(right, theme);
        return rightScore - leftScore;
      })[0];

    if (!product || scoreStarterThemeMatch(product, theme) <= 0) {
      return null;
    }

    usedProductIds.add(product.id);

    const insertion = suggestPlannerInsertion({
      product,
      plan: { days: [{ date: null }], items: [] },
      config: {
        ...config,
        open_hours: openHours,
      },
    });

    return {
      id: `starter-${theme.key}-${product.id}`,
      themeKey: theme.key,
      title: theme.title,
      description: theme.description,
      productId: product.id,
      productTitle: product.title,
      area: product.area,
      badge: product.slotTypeLabel,
      startTime: insertion?.startTime || minutesToTime(theme.startMinutes),
      priceLabel: product.priceLabel,
      ctaLabel: "Start hiermee",
    };
  })
    .filter(Boolean)
    .slice(0, 5);
}

function findBestTimeSlot({ product, dayIndex, dayItems, stepMinutes, openHours }) {
  const meta = buildProductMeta(product);
  if (!Number.isFinite(meta.durationMinutes) || meta.durationMinutes <= 0) {
    return null;
  }
  const startBoundary = timeToMinutes(openHours?.start || DEFAULT_OPEN_HOURS.start);
  const endBoundaryRaw = openHours?.end === "24:00" ? 24 * 60 : timeToMinutes(openHours?.end || DEFAULT_OPEN_HOURS.end);
  const durationMinutes = meta.durationMinutes;
  const endBoundary = endBoundaryRaw - durationMinutes;

  if (endBoundary < startBoundary) {
    return null;
  }

  let best = null;

  for (let startMinutes = startBoundary; startMinutes <= endBoundary; startMinutes += stepMinutes) {
    const endMinutes = startMinutes + durationMinutes;
    const isNewItemCombi = product?.type === "combi" || Array.isArray(product?.combi_items);
    if (hasConflict(dayItems, startMinutes, endMinutes, isNewItemCombi)) {
      continue;
    }

    const score = scoreTimeSlot({
      startMinutes,
      endMinutes,
      meta,
      dayItems,
    });

    if (!best || score > best.score) {
      best = {
        dayIndex,
        startMinutes,
        endMinutes,
        score,
        reason: describeSlotReason(meta, startMinutes),
      };
    }
  }

  return best;
}

function scoreTimeSlot({ startMinutes, endMinutes, meta, dayItems }) {
  let score = 100;
  const buffer = 30;
  const previous = [...dayItems].reverse().find((item) => item.endMinutes <= startMinutes) || null;
  const next = dayItems.find((item) => item.startMinutes >= endMinutes) || null;

  if (previous) {
    const gap = startMinutes - previous.endMinutes;
    if (gap < buffer) {
      score -= (buffer - gap) * 10;
    }
  }

  if (next) {
    const gap = next.startMinutes - endMinutes;
    if (gap < buffer) {
      score -= (buffer - gap) * 10;
    }
  }

  if (meta.preferredWindow) {
    const center = meta.preferredWindow.center;
    score -= Math.round(Math.abs(startMinutes - center) / 8);

    if (startMinutes >= meta.preferredWindow.start && endMinutes <= meta.preferredWindow.end) {
      score += 28;
    } else {
      score -= 18;
    }
  }

  if (previous) {
    const gap = startMinutes - previous.endMinutes;
    const travelNeed = estimateTravelMinutes(previous.area, meta.area);
    if (gap >= travelNeed) {
      score += 14;
    } else {
      score -= (travelNeed - gap) * 3;
    }

    if (previous.area === meta.area) {
      score += 18;
    }
  } else {
    score += 10;
  }

  if (next) {
    const gap = next.startMinutes - endMinutes;
    const travelNeed = estimateTravelMinutes(meta.area, next.area);
    if (gap >= travelNeed) {
      score += 10;
    } else {
      score -= (travelNeed - gap) * 2;
    }

    if (next.area === meta.area) {
      score += 12;
    }
  } else {
    score += 8;
  }

  return score;
}

function buildProductMeta(product) {
  const id = Number.parseInt(product?.id, 10);
  const tokens = collectProductTokens(product);
  const slotType = resolveSlotType(tokens);
  const preferredWindow = PREFERRED_WINDOWS[slotType] || PREFERRED_WINDOWS.activity;
  const area = normalizeArea(product?.location);
  const durationCandidate = Number.parseInt(product?.duration?.minutes ?? product?.duration_minutes ?? product?.durationMinutes, 10);
  const durationMinutes = Number.isFinite(durationCandidate) && durationCandidate > 0 ? durationCandidate : null;
  const price = getPricePerPerson(product);

  return {
    ...product,
    id,
    title: product?.name || product?.title || "Activiteit",
    tokens,
    slotType,
    slotTypeLabel: formatSlotTypeLabel(slotType),
    preferredWindow,
    area,
    durationMinutes,
    price,
    priceLabel: Number.isFinite(price) && price > 0 ? formatCurrency(price, product?.currency || "EUR") : null,
  };
}

function enrichPlannedItem(item, productMeta) {
  const meta = productMeta || buildProductMeta({ id: item.productId, name: item.title });

  return {
    ...item,
    title: item?.title || meta.title,
    area: meta.area,
    slotType: meta.slotType,
    slotTypeLabel: meta.slotTypeLabel,
    preferredWindow: meta.preferredWindow,
    durationMinutes: Number.isFinite(item?.durationMinutes) && item.durationMinutes > 0 ? item.durationMinutes : meta.durationMinutes,
  };
}

function buildTimingNote(item) {
  if (!item.preferredWindow) {
    return null;
  }

  if (
    item.startMinutes >= item.preferredWindow.start &&
    item.endMinutes <= item.preferredWindow.end
  ) {
    return null;
  }

  return {
    id: `timing-${item.id}`,
    title: "Slimmer tijdslot mogelijk",
    message: `"${item.title}" past meestal beter in de ${item.preferredWindow.label}.`,
  };
}

function buildAreaGroupingHint(items) {
  const distinctAreas = new Set(items.map((item) => item.area).filter(Boolean));
  if (distinctAreas.size <= 1) {
    return "Je activiteiten blijven netjes in hetzelfde gebied.";
  }
  if (distinctAreas.size === 2) {
    return "De route blijft redelijk compact, met een beperkte verplaatsing tussen gebieden.";
  }
  if (items.length > 0) {
    return "Groepeer activiteiten per gebied om heen-en-weer lopen te beperken.";
  }
  return null;
}

function resolvePreferredGapType({ beforeItem, afterItem, midpoint }) {
  if (midpoint >= IDEAL_MEAL_WINDOW.lunch.start && midpoint <= IDEAL_MEAL_WINDOW.lunch.end) {
    return "lunch";
  }
  if (midpoint >= IDEAL_MEAL_WINDOW.dinner.start) {
    return "dinner";
  }

  const nextPreference = afterItem?.slotType;
  const complementary = COMPLEMENTARY_SLOT_TYPES[beforeItem?.slotType] || [];
  if (nextPreference && complementary.includes(nextPreference)) {
    return nextPreference;
  }
  return complementary[0] || "activity";
}

function buildSuggestionReason({ meta, targetSlotType, beforeItem, afterItem, gapMinutes }) {
  if (meta.slotType === "lunch" || targetSlotType === "lunch") {
    return `Je hebt rond ${minutesToTime(beforeItem.endMinutes)} ruimte voor lunch, dicht bij ${beforeItem.area}.`;
  }
  if (meta.slotType === "dinner" || targetSlotType === "dinner") {
    return `Deze optie bouwt logisch door naar je avond tussen ${beforeItem.title} en ${afterItem.title}.`;
  }
  if (meta.area === beforeItem.area || meta.area === afterItem.area) {
    return `Past binnen ${formatDuration(gapMinutes)} en blijft in de buurt van ${meta.area}.`;
  }
  return `Maakt dit open blok van ${formatDuration(gapMinutes)} logisch completer.`;
}

function describeSlotReason(meta, startMinutes) {
  if (!meta.preferredWindow) {
    return "Beschikbaar tijdslot";
  }
  if (
    startMinutes >= meta.preferredWindow.start &&
    startMinutes <= meta.preferredWindow.end
  ) {
    return `Past goed in de ${meta.preferredWindow.label}`;
  }
  return "Beschikbaar, maar minder ideaal dan het voorkeursmoment";
}

function scoreStarterThemeMatch(meta, theme) {
  let score = 0;
  if (theme.preferredSlotTypes.includes(meta.slotType)) {
    score += 60;
  }
  if (meta.preferredWindow && Math.abs(meta.preferredWindow.center - theme.startMinutes) <= 120) {
    score += 20;
  }
  if (meta.price > 0) {
    score += 5;
  }
  if (meta.area && meta.area !== "Den Bosch") {
    score += 5;
  }
  return score;
}

function collectProductTokens(product) {
  const values = [
    product?.name,
    product?.title,
    product?.slug,
    product?.location,
    ...(Array.isArray(product?.categories) ? product.categories : []),
    ...(Array.isArray(product?.category_slugs) ? product.category_slugs : []),
    ...(Array.isArray(product?.tags) ? product.tags : []),
  ];

  return values
    .flatMap((value) => (typeof value === "string" ? value.split(/[\s,/_-]+/u) : []))
    .map((value) => value.trim().toLowerCase())
    .filter(Boolean);
}

function resolveSlotType(tokens) {
  for (const rule of SLOT_TYPE_RULES) {
    if (rule.tokens.some((token) => tokens.some((entry) => entry.includes(token)))) {
      return rule.slotType;
    }
  }
  return "activity";
}

function normalizeArea(location) {
  const value = typeof location === "string" ? location.trim().toLowerCase() : "";
  if (!value) {
    return "Den Bosch";
  }

  const match = AREA_PATTERNS.find((entry) =>
    entry.tokens.some((token) => value.includes(token))
  );
  if (match) {
    return match.label;
  }

  const [firstPart] = value.split(/[,-]/u);
  const cleaned = (firstPart || value).trim();
  if (!cleaned) {
    return "Den Bosch";
  }

  return cleaned.charAt(0).toUpperCase() + cleaned.slice(1);
}

function estimateTravelMinutes(fromArea, toArea) {
  if (!fromArea || !toArea || fromArea === toArea) {
    return 10;
  }

  const centralAreas = new Set(["Centrum", "Parade", "Uilenburg"]);
  if (centralAreas.has(fromArea) && centralAreas.has(toArea)) {
    return 15;
  }

  return 25;
}

function areSimilarExperiences(left, right) {
  if (!left || !right) {
    return false;
  }

  if (left.slotType === right.slotType && ["tour", "culture", "boat", "activity"].includes(left.slotType)) {
    return true;
  }

  const leftTokens = new Set(left.title.toLowerCase().split(/\s+/u));
  const rightTokens = new Set(right.title.toLowerCase().split(/\s+/u));
  let shared = 0;
  leftTokens.forEach((token) => {
    if (token.length > 3 && rightTokens.has(token)) {
      shared += 1;
    }
  });

  return shared >= 2;
}

function hasConflict(items, startMinutes, endMinutes, isNewItemCombi = false) {
  return items.some((item) => {
    const isExistingCombi = (Array.isArray(item.combiItems) && item.combiItems.length > 0) || item.source === 'product-combi';
    const buffer = (isNewItemCombi || isExistingCombi) ? 30 : 0;
    return startMinutes < (item.endMinutes + buffer) && endMinutes > (item.startMinutes - buffer);
  });
}

function formatSlotTypeLabel(slotType) {
  switch (slotType) {
    case "breakfast":
      return "Ochtend";
    case "lunch":
      return "Lunch";
    case "dinner":
      return "Diner";
    case "drinks":
      return "Borrel";
    case "boat":
      return "Boottocht";
    case "tour":
      return "Tour";
    case "culture":
      return "Cultuur";
    case "family":
      return "Kidsproof";
    case "romantic":
      return "Romantisch";
    case "indoor":
      return "Indoor";
    default:
      return "Activiteit";
  }
}

function formatDuration(minutes) {
  const value = Math.max(0, Math.round(minutes));
  if (value >= 60) {
    const hours = Math.floor(value / 60);
    const remainder = value % 60;
    return remainder > 0 ? `${hours}u ${remainder}m` : `${hours} uur`;
  }
  return `${value} min`;
}

function formatCurrency(value, currency = "EUR") {
  try {
    return new Intl.NumberFormat("nl-NL", {
      style: "currency",
      currency,
      maximumFractionDigits: 0,
    }).format(value || 0);
  } catch (error) {
    return `${Math.round(value || 0)} ${currency}`;
  }
}

function buildMobileSummaryLabel({ itemCount, conflictCount, advisoryConflictCount = 0, gapCount }) {
  const parts = [`${itemCount} item${itemCount === 1 ? "" : "s"}`];
  if (conflictCount > 0) {
    parts.push(`${conflictCount} conflict${conflictCount === 1 ? "" : "en"}`);
  } else if (advisoryConflictCount > 0) {
    parts.push(`${advisoryConflictCount} aandachtspunt${advisoryConflictCount === 1 ? "" : "en"}`);
  } else if (gapCount > 0) {
    parts.push(`${gapCount} open moment${gapCount === 1 ? "" : "en"}`);
  }
  return parts.join("  ");
}
