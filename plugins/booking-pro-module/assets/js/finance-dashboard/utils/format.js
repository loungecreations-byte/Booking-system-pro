const DEFAULT_LOCALE = "nl-NL";
const DEFAULT_CURRENCY = "EUR";

export function formatCurrency(value, currency = DEFAULT_CURRENCY, locale = DEFAULT_LOCALE) {
  const amount = Number.isFinite(value) ? value : 0;

  return new Intl.NumberFormat(locale, {
    style: "currency",
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount);
}

export function formatNumber(value, locale = DEFAULT_LOCALE) {
  return new Intl.NumberFormat(locale, {
    maximumFractionDigits: 0,
  }).format(Number.isFinite(value) ? value : 0);
}

export function formatPercent(value, locale = DEFAULT_LOCALE, fractionDigits = 1) {
  const amount = Number.isFinite(value) ? value : 0;

  return new Intl.NumberFormat(locale, {
    style: "percent",
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(amount / 100);
}

export function formatTrend(value, fractionDigits = 2) {
  if (!Number.isFinite(value)) {
    return "0";
  }

  return value.toFixed(fractionDigits);
}

export function chooseTrendColor(value) {
  if (!Number.isFinite(value)) {
    return "neutral";
  }

  if (value > 0) {
    return "positive";
  }

  if (value < 0) {
    return "negative";
  }

  return "neutral";
}
