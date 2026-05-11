export function toFloat(value) {
  if (typeof value === "number") {
    return value;
  }

  if (typeof value === "string" && value !== "") {
    let normalized = value.trim();
    // Strip currency symbols and whitespace, keep digits/.,,/- for locale formats
    normalized = normalized.replace(/[^\d,.-]/g, "");
    // If both comma and dot occur, treat dot as thousand separator
    if (normalized.includes(",") && normalized.includes(".")) {
      normalized = normalized.replace(/\./g, "");
    }
    // Convert comma decimal to dot
    normalized = normalized.replace(",", ".");

    const parsed = Number.parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  return 0;
}

export function roundCurrency(value) {
  return Math.round((value + Number.EPSILON) * 100) / 100;
}

function normalizeParticipantCount(participants) {
  const numeric = Number(participants);
  return Number.isFinite(numeric) && numeric > 0 ? numeric : 1;
}

export function deriveSlotPricing(pricing, participants, options = {}) {
  const price = pricing || {};
  const count = normalizeParticipantCount(participants);

  const supportsPersons =
    typeof price.supports_persons === "boolean"
      ? price.supports_persons
      : options.supportsPersons ?? true;

  let base = toFloat(options.base ?? price.base);
  let perPerson = toFloat(
    options.pricePerPerson ??
      options.price_pp ??
      price.price_pp ??
      options.perPerson ??
      price.per_person
  );
  let fixed = toFloat(options.fixedFee ?? price.fixed_fee);
  const dynamic = options.dynamic ?? price.dynamic ?? null;
  const totalOverride = toFloat(options.totalCost);
  const fallbackPerPerson = toFloat(dynamic ? dynamic.unit_total ?? dynamic.unit?.total : undefined);
  // Fallback total: do NOT pull in raw price.total to avoid overriding per-person pricing.
  const fallbackTotal = toFloat(
    options.total ?? options.fallbackTotal ?? (dynamic ? dynamic.total : undefined) ?? 0
  );

  // If we have a price_pp anywhere, treat this as authoritative per-person pricing
  // and ignore base/fixed to avoid double counting legacy fields.
  const hasPricePP =
    Number.isFinite(perPerson) && perPerson > 0 && (options.pricePerPerson || options.price_pp || price.price_pp);
  if (supportsPersons && hasPricePP) {
    base = 0;
    fixed = 0;
  }

  // If perPerson is still missing, use dynamic unit; otherwise leave as is.
  if (perPerson <= 0 && fallbackPerPerson > 0) {
    perPerson = fallbackPerPerson;
  }

  const dynamicBreakdown = computeDynamicBreakdown(dynamic, count, totalOverride);
  // Only use dynamic pricing when we have no authoritative per-person pricing.
  const hasPerPerson = supportsPersons && (perPerson > 0 || fallbackPerPerson > 0);
  if (dynamicBreakdown && !hasPerPerson) {
    const fixedCost = Math.max(
      dynamicBreakdown.total - dynamicBreakdown.perPerson * count,
      0
    );

    return finaliseSlotPricing({
      perPerson: dynamicBreakdown.perPerson,
      fixedCost,
      total: dynamicBreakdown.total,
    });
  }

  let appliedPerPerson = perPerson > 0 ? perPerson : 0;
  let total = base + fixed;

  if (supportsPersons) {
    if (appliedPerPerson > 0) {
      total += appliedPerPerson * count;
    }
  } else {
    // Not per-person pricing; treat perPerson as a flat addition if present.
    if (appliedPerPerson > 0) {
      total += appliedPerPerson;
    }
  }

  total = roundCurrency(total);

  if (total <= 0 && fallbackTotal > 0) {
    total = roundCurrency(fallbackTotal);
    if (supportsPersons && appliedPerPerson <= 0 && count > 0) {
      appliedPerPerson = roundCurrency(total / count);
    }
  }

  const fixedRemainder = supportsPersons
    ? total - (appliedPerPerson > 0 ? appliedPerPerson * count : 0)
    : total - (appliedPerPerson > 0 ? appliedPerPerson : 0);
  const fixedCost = roundCurrency(fixedRemainder > 0 ? fixedRemainder : base + fixed);

  return finaliseSlotPricing({
    perPerson: appliedPerPerson,
    fixedCost,
    total,
  });
}

export function calculateTotalCost(pricing, participants, options = {}) {
  const breakdown = deriveSlotPricing(pricing, participants, options);
  return breakdown.total;
}

export function summarizePlan(items, currency, participantCount = null) {
  const lineItems = Array.isArray(items)
    ? items.map((item) => {
        const rawProductId = item?.productId ?? item?.product_id ?? null;
        const parsedProduct = Number.parseInt(rawProductId, 10);
        const productId = Number.isFinite(parsedProduct) ? parsedProduct : rawProductId;
        const scheduleDate =
          typeof item?.date === "string" && item.date.trim() !== "" ? item.date.trim() : null;
        const dayIndex = Number.isFinite(item?.dayIndex) ? item.dayIndex : null;
        const lineHash = typeof item?.id === "string" ? item.id : null;

        return {
          product_id: productId,
          title: item?.title ?? "",
          participants: item?.participants ?? 0,
          line_subtotal: roundCurrency(item?.totalCost ?? 0),
          schedule: {
            start: item?.startTime ?? null,
            end: item?.endTime ?? null,
            date: scheduleDate,
          },
          day_index: dayIndex,
          line_uid: lineHash,
        };
      })
    : [];

  const itemsSubtotal = lineItems.reduce((total, line) => total + (line.line_subtotal || 0), 0);
  const roundedSubtotal = roundCurrency(itemsSubtotal);

  return {
    currency: currency || "EUR",
    participants: participantCount,
    items: lineItems,
    adjustments: [],
    discounts: [],
    taxes: [],
    itemsSubtotal: roundedSubtotal,
    adjustmentsTotal: 0,
    discountTotal: 0,
    taxTotal: 0,
    grandTotal: roundedSubtotal,
    subtotal: roundedSubtotal,
    breakdown: {
      currency: currency || "EUR",
      items_count: lineItems.length,
      items_subtotal: roundedSubtotal,
      participants: participantCount,
      adjustments_total: 0,
      discount_total: 0,
      tax_total: 0,
      grand_total: roundedSubtotal,
    },
  };
}

export function formatCurrency(amount, currency) {
  try {
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency: currency || "EUR",
    }).format(amount);
  } catch (error) {
    const symbol = currency === "USD" ? "$" : "€";
    const safe = typeof amount === "number" ? amount : parseFloat(amount || "0");
    if (Number.isNaN(safe)) {
      return symbol + "0.00";
    }
    return symbol + safe.toFixed(2);
  }
}

function computeDynamicBreakdown(dynamic, count, totalOverride) {
  if (totalOverride > 0) {
    const total = roundCurrency(totalOverride);
    return {
      perPerson: roundCurrency(total / count),
      total,
    };
  }

  if (!dynamic) {
    return null;
  }

  const unitTotal = toFloat(dynamic?.unit_total ?? dynamic?.unit?.total);
  if (unitTotal > 0) {
    const perPerson = roundCurrency(unitTotal);
    return {
      perPerson,
      total: roundCurrency(perPerson * count),
    };
  }

  const dynamicTotal = toFloat(dynamic?.total);
  if (dynamicTotal <= 0) {
    return null;
  }

  const dynamicParticipants = Number.isFinite(dynamic?.participants)
    ? Number(dynamic.participants)
    : null;

  let scaledTotal = dynamicTotal;
  if (dynamicParticipants && dynamicParticipants > 0) {
    const multiplier = count / dynamicParticipants;
    if (Number.isFinite(multiplier) && multiplier > 0) {
      scaledTotal = dynamicTotal * multiplier;
    }
  }

  const total = roundCurrency(scaledTotal);
  return {
    perPerson: roundCurrency(total / count),
    total,
  };
}

function finaliseSlotPricing({ perPerson, fixedCost, total }) {
  const safePerPerson = roundCurrency(perPerson > 0 ? perPerson : 0);
  const safeFixed = roundCurrency(fixedCost > 0 ? fixedCost : 0);
  const safeTotal = roundCurrency(total > 0 ? total : 0);

  return {
    perPerson: safePerPerson,
    fixedCost: safeFixed,
    total: safeTotal,
  };
}
