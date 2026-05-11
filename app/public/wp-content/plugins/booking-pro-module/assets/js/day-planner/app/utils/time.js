/**
 * Convert HH:MM string to minutes.
 *
 * @param {string} value
 * @return {number}
 */
export function timeToMinutes(value) {
  if (!value || typeof value !== "string") {
    return 0;
  }

  const [hours, minutes] = value.split(":").map((part) => parseInt(part, 10));
  const h = Number.isFinite(hours) ? hours : 0;
  const m = Number.isFinite(minutes) ? minutes : 0;

  return h * 60 + m;
}

/**
 * Convert minutes to HH:MM string.
 *
 * @param {number} minutes
 * @return {string}
 */
export function minutesToTime(minutes) {
  const safe = Number.isFinite(minutes) ? minutes : 0;
  const h = Math.floor(safe / 60);
  const m = safe % 60;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
}

export function clampMinutes(value) {
  if (!Number.isFinite(value)) {
    return 0;
  }
  return Math.max(0, value);
}

export function addMinutes(startMinutes, duration) {
  return clampMinutes(startMinutes) + clampMinutes(duration);
}

export function snapToStep(minutes, stepMinutes = 15) {
  const step = Math.max(1, stepMinutes || 15);
  return Math.round(minutes / step) * step;
}

export function clampBetween(value, min, max) {
  const safeMin = Number.isFinite(min) ? min : 0;
  const safeMax = Number.isFinite(max) ? max : safeMin;
  const safeValue = Number.isFinite(value) ? value : safeMin;

  if (safeValue < safeMin) {
    return safeMin;
  }
  if (safeValue > safeMax) {
    return safeMax;
  }
  return safeValue;
}

export function getLocalDateIso(date = new Date()) {
  if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
    return "";
  }

  const localDate = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
  return localDate.toISOString().slice(0, 10);
}

/**
 * Generate selectable time options between open hours.
 *
 * @param {{start:string,end:string}} openHours
 * @param {number} stepMinutes
 * @return {Array<{value:string,label:string,minutes:number}>}
 */
export function generateTimeOptions(openHours, stepMinutes) {
  if (!openHours || !openHours.start || !openHours.end) {
    return [];
  }

  const step = Math.max(5, stepMinutes || 15);
  const startMinutes = timeToMinutes(openHours.start);
  const endMinutes = timeToMinutes(openHours.end);

  const options = [];
  for (let current = startMinutes; current < endMinutes; current += step) {
    const value = minutesToTime(current);
    options.push({
      value,
      label: value,
      minutes: current,
      status: "free",
    });
  }

  return options;
}

export function normalizeLocale(locale) {
  const fallback = "nl-NL";

  if (typeof locale !== "string" || locale.trim() === "") {
    return fallback;
  }

  const candidate = locale.replace(/_/g, "-");

  if (typeof Intl !== "undefined" && typeof Intl.DateTimeFormat === "function") {
    try {
      const [supported] = Intl.DateTimeFormat.supportedLocalesOf([candidate]);
      if (supported) {
        return supported;
      }
    } catch (error) {
      // Ignore and fall back.
    }
  }

  return fallback;
}

export function formatDateLabel(dateString, locale = "nl-NL") {
  if (!dateString) {
    return "";
  }

  const formatter = new Intl.DateTimeFormat(normalizeLocale(locale), {
    weekday: "long",
    day: "numeric",
    month: "long",
  });
  const date = new Date(`${dateString}T00:00:00`);
  return formatter.format(date);
}
