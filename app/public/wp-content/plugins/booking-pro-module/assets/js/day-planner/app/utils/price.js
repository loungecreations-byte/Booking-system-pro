import {
  calculateTotalCost as calculatePricingTotal,
  deriveSlotPricing,
  formatCurrency,
  roundCurrency,
  summarizePlan,
  toFloat,
} from "../../shared/pricing.js";

/**
 * Normalizes a numeric input (string or number) to a finite float.
 * Returns NaN when the input cannot be parsed.
 */
export function normalizeAmount(value) {
  if (typeof value === "number") {
    return Number.isFinite(value) ? value : NaN;
  }

  if (typeof value === "string") {
    let normalized = value.trim();

    if (normalized === "") {
      return NaN;
    }

    normalized = normalized.replace(/[^\d,.-]/g, "");

    if (normalized.includes(",") && normalized.includes(".")) {
      normalized = normalized.replace(/\./g, "");
    }

    normalized = normalized.replace(",", ".");

    const parsed = Number.parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : NaN;
  }

  return NaN;
}

/**
 * Extracts the price per person from a product object.
 *
 * Supports multiple field names and ignores non-numeric values.
 * Returns null when no valid price is found.
 */
export function getPricePerPerson(product) {
  if (!product || typeof product !== "object") {
    return null;
  }

  const pricing = product?.pricing || {};
  const candidates = [
    product?.price_pp,
    product?.price_per_person,
    product?.pricePerPerson,
    pricing?.per_person,
    pricing?.perPerson,
    pricing?.per_participant,
    pricing?.perParticipant,
    pricing?.price_per_person,
    pricing?.pricePerPerson,
    pricing?.unit_total,
    pricing?.unit?.total,
    pricing?.unit?.price,
    pricing?.unitPrice,
    pricing?.unit_price,
    pricing?.person,
    pricing?.participant,
  ];

  for (const candidate of candidates) {
    if (typeof candidate !== "number" && typeof candidate !== "string") {
      continue;
    }

    const parsed = normalizeAmount(candidate);
    if (Number.isFinite(parsed) && parsed > 0) {
      return roundCurrency(parsed);
    }
  }

  return null;
}

/**
 * Computes the base price for a product for a given participant count.
 *
 * Uses the shared slot pricing to respect dynamic totals, fixed fees and
 * per-person rules. Returns null when no valid price is available.
 */
function normalizeParticipantCount(participants) {
  const numeric = Number(participants);
  return Number.isFinite(numeric) && numeric > 0 ? numeric : 1;
}

export function getBasePrice(product, participants = 1, options = {}) {
  if (!product || typeof product !== "object") {
    return null;
  }

  const pricing = product?.pricing || product || {};
  const count = normalizeParticipantCount(participants);

  const breakdown = deriveSlotPricing(pricing, count, {
    ...options,
    pricePerPerson:
      options.pricePerPerson ??
      product?.price_pp ??
      product?.price_per_person ??
      product?.pricePerPerson,
    totalCost: options.totalCost ?? product?.totalCost,
  });

  if (!Number.isFinite(breakdown?.total) || breakdown.total <= 0) {
    return null;
  }

  return breakdown.total;
}

/**
 * Returns the price per person resolved via the slot pricing breakdown for the
 * given participant count. Falls back to a direct per-person value.
 */
export function getSlotPricePerPerson(product, participants = 1, options = {}) {
  if (!product || typeof product !== "object") {
    return null;
  }

  const pricing = product?.pricing || product || {};
  const breakdown = computeSlotPricing(pricing, participants, {
    ...options,
    pricePerPerson:
      options.pricePerPerson ??
      product?.price_pp ??
      product?.price_per_person ??
      product?.pricePerPerson,
    // Never treat stored totals as authoritative when resolving p.p.; only use explicit override.
    totalCost: options.totalCost ?? null,
    sourceProduct: product,
  });

  if (Number.isFinite(breakdown?.perPerson) && breakdown.perPerson > 0) {
    return breakdown.perPerson;
  }

  return getPricePerPerson(product);
}

/**
 * Calculates the total cost for a pricing object while keeping the planner
 * calculation logic in a single place.
 */
export function calculateTotalCost(pricing, participants, options = {}) {
  return calculatePricingTotal(pricing, participants, options);
}

/**
 * Returns a slot pricing breakdown (perPerson, fixedCost, total) for a given
 * pricing object and participant count.
 */
export function computeSlotPricing(pricing, participants, options = {}) {
  const count = normalizeParticipantCount(participants);
  const resolvedPerPerson =
    options.pricePerPerson ??
    options.price_pp ??
    pricing?.price_pp ??
    pricing?.per_person ??
    (options.sourceProduct ? getPricePerPerson(options.sourceProduct) : null);

  let resolvedFallbackTotal = options.total ?? options.fallbackTotal ?? null;
  if (!Number.isFinite(resolvedFallbackTotal) && Number.isFinite(pricing?.total)) {
    const cachedParticipants = Number(pricing?.participants ?? pricing?.people ?? pricing?.count ?? 0);
    if (cachedParticipants > 0) {
      resolvedFallbackTotal = (pricing.total / cachedParticipants) * count;
    }
  }

  return deriveSlotPricing(pricing || {}, count, {
    ...options,
    pricePerPerson: resolvedPerPerson,
    // Only honor explicit totalCost overrides; treat totals as fallbacks.
    totalCost: options.totalCost ?? null,
    total: resolvedFallbackTotal,
    fallbackTotal: resolvedFallbackTotal,
  });
}

/**
 * Formats a price using the shared currency formatter with sensible defaults.
 */
export function formatPrice(amount, currency = "EUR") {
  return formatCurrency(amount, currency);
}

export { deriveSlotPricing, roundCurrency, summarizePlan, toFloat };
