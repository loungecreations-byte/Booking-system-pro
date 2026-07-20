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
  return Number.isFinite(numeric) && numeric > 0 ? numeric : null;
}

export function deriveSlotPricing(pricing, participants, options = {}) {
  const price = pricing || {};
  normalizeParticipantCount(participants); // Validation only; never used to calculate a price.
  const dynamic = options.dynamic ?? price.dynamic ?? null;
  const perPerson = toFloat(
    options.displayUnitPrice ??
      price.display_unit_price ??
      price.unit_price ??
      options.pricePerPerson ??
      options.price_pp ??
      price.price_pp ??
      price.per_person ??
      dynamic?.unit_total ??
      dynamic?.unit?.total
  );
  const fixedCost = toFloat(
    options.fixedCost ?? price.adjustments_total ?? price.fixed_cost ?? price.fixed_fee
  );
  const total = toFloat(
    options.totalCost ??
      options.displayTotal ??
      price.display_total ??
      options.total ??
      price.total ??
      dynamic?.total
  );

  return finaliseSlotPricing({
    perPerson,
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

  return {
    currency: currency || "EUR",
    participants: participantCount,
    items: lineItems,
    adjustments: [],
    discounts: [],
    taxes: [],
    itemsSubtotal: null,
    adjustmentsTotal: 0,
    discountTotal: 0,
    taxTotal: 0,
    grandTotal: null,
    subtotal: null,
    breakdown: {
      currency: currency || "EUR",
      items_count: lineItems.length,
      items_subtotal: null,
      participants: participantCount,
      adjustments_total: 0,
      discount_total: 0,
      tax_total: 0,
      grand_total: null,
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
