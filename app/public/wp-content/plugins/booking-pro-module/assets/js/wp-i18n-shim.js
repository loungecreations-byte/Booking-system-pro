const getI18n = () => {
  if (typeof window === "undefined") {
    return undefined;
  }

  return window.wp?.i18n;
};

const makeFallback = (method, fallback) => {
  return (...args) => {
    const i18n = getI18n();
    if (i18n && typeof i18n[method] === "function") {
      return i18n[method](...args);
    }

    return typeof fallback === "function" ? fallback(...args) : fallback;
  };
};

export const __ = makeFallback("__", (text) => (text !== undefined ? String(text) : ""));
export const _x = makeFallback("_x", (text) => (text !== undefined ? String(text) : ""));
export const _n = makeFallback("_n", (single, plural, number) => (number === 1 ? single : plural));
export const _nx = makeFallback(
  "_nx",
  (single, plural, number) => (number === 1 ? single : plural)
);

const coerceNumber = (value, fallback = 0) => {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
};

const sprintfFallback = (format, ...values) => {
  if (typeof format !== "string" || format.length === 0) {
    return "";
  }

  let index = 0;
  const pattern = /%(\d+\$)?([%sdifouxX])/g;

  return format.replace(pattern, (match, position, type) => {
    if (type === "%") {
      return "%";
    }

    const resolvedIndex = position ? parseInt(position, 10) - 1 : index;
    if (!position) {
      index += 1;
    }

    const value = values[resolvedIndex];
    if (value === undefined) {
      return "";
    }

    switch (type) {
      case "d":
      case "i":
      case "u":
        return String(Math.trunc(coerceNumber(value, 0)));
      case "f":
        return String(coerceNumber(value, 0));
      case "o": {
        const numeric = Math.trunc(coerceNumber(value, 0));
        return (numeric >>> 0).toString(8);
      }
      case "x": {
        const numeric = Math.trunc(coerceNumber(value, 0));
        return (numeric >>> 0).toString(16);
      }
      case "X": {
        const numeric = Math.trunc(coerceNumber(value, 0));
        return (numeric >>> 0).toString(16).toUpperCase();
      }
      case "s":
      default:
        return value != null ? String(value) : "";
    }
  });
};

export const sprintf = makeFallback("sprintf", sprintfFallback);

export default {
  __,
  _x,
  _n,
  _nx,
  sprintf,
};
