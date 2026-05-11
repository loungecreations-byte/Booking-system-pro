import { getPricePerPerson as resolvePricePerPerson, normalizeAmount } from "./price.js";

export function normalizeNumeric(value) {
  const parsed = normalizeAmount(value);
  return Number.isFinite(parsed) ? parsed : NaN;
}

export function getDurationMinutes(product) {
  if (!product || typeof product !== "object") {
    return null;
  }

  const candidates = [
    product?.duration?.minutes,
    product?.duration_minutes,
    product?.durationMinutes,
    product?.duration,
  ];

  for (const candidate of candidates) {
    if (typeof candidate !== "number" && typeof candidate !== "string") {
      continue;
    }

    const parsed = normalizeNumeric(candidate);
    if (Number.isFinite(parsed) && parsed > 0) {
      return parsed;
    }
  }

  return null;
}

export function getPricePerPerson(product) {
  return resolvePricePerPerson(product);
}

const INDOOR_KEYWORDS = ["indoor", "inside", "binnen", "binnenactiviteit", "museum", "atelier"];
const OUTDOOR_KEYWORDS = ["outdoor", "outside", "buiten", "buitenactiviteit", "park", "vaart"];

function normaliseToken(value) {
  if (typeof value === "string") {
    return value.trim().toLowerCase();
  }
  return "";
}

function evaluateEnvironmentCandidate(value, buckets) {
  if (!value) {
    return;
  }
  const raw = Array.isArray(value) ? value : String(value).split(/[,/]/);
  raw.forEach((entry) => {
    const token = normaliseToken(entry);
    if (!token) {
      return;
    }
    if (INDOOR_KEYWORDS.some((keyword) => token.includes(keyword))) {
      buckets.indoor = true;
    }
    if (OUTDOOR_KEYWORDS.some((keyword) => token.includes(keyword))) {
      buckets.outdoor = true;
    }
  });
}

export function getEnvironmentTag(product) {
  if (!product || typeof product !== "object") {
    return "both";
  }

  const buckets = { indoor: false, outdoor: false };
  const attributes = product.attributes || {};

  evaluateEnvironmentCandidate(product.environment, buckets);
  evaluateEnvironmentCandidate(product.environment_type, buckets);
  evaluateEnvironmentCandidate(product.environmentType, buckets);
  evaluateEnvironmentCandidate(attributes.environment, buckets);
  evaluateEnvironmentCandidate(attributes.omgeving, buckets);
  evaluateEnvironmentCandidate(product.tags, buckets);
  evaluateEnvironmentCandidate(product.categories?.map((category) => category?.slug || category?.name), buckets);

  if (buckets.indoor && !buckets.outdoor) {
    return "indoor";
  }
  if (buckets.outdoor && !buckets.indoor) {
    return "outdoor";
  }
  return "both";
}
