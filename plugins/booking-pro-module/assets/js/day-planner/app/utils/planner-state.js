export const PARTICIPANTS_SOURCE_INHERITED = "inherited";
export const PARTICIPANTS_SOURCE_MANUAL_OVERRIDE = "manual_override";
export const PARTICIPANTS_SOURCE_PRODUCT_DEFAULT = "product_default";
export const TIME_SOURCE_AUTO = "auto";
export const TIME_SOURCE_MANUAL = "manual";

export function toPlannerPositiveInt(value) {
  if (typeof value === "number" && Number.isFinite(value) && value > 0) {
    return Math.floor(value);
  }

  if (typeof value === "string" && value.trim() !== "") {
    const parsed = Number.parseInt(value, 10);
    if (Number.isFinite(parsed) && parsed > 0) {
      return parsed;
    }
  }

  return null;
}

export function hasManualParticipantsOverride(item) {
  return (
    item?.participants_override === true ||
    item?.participantsOverride === true ||
    item?.participants_source === PARTICIPANTS_SOURCE_MANUAL_OVERRIDE ||
    item?.participantsSource === PARTICIPANTS_SOURCE_MANUAL_OVERRIDE
  );
}

export function resolveParticipantsForItem(item, planParticipants, fallbackParticipants = null) {
  const fallback = toPlannerPositiveInt(fallbackParticipants);
  const canonical = toPlannerPositiveInt(planParticipants) ?? fallback;

  if (hasManualParticipantsOverride(item)) {
    return toPlannerPositiveInt(item?.participants) ?? canonical;
  }

  return canonical;
}

export function buildInheritedParticipants(planParticipants, fallbackParticipants = null) {
  return {
    participants: toPlannerPositiveInt(planParticipants) ?? toPlannerPositiveInt(fallbackParticipants),
    participants_override: false,
    participants_source: PARTICIPANTS_SOURCE_INHERITED,
  };
}

export function buildManualParticipants(participants, fallbackParticipants = null) {
  return {
    participants: toPlannerPositiveInt(participants) ?? toPlannerPositiveInt(fallbackParticipants),
    participants_override: true,
    participants_source: PARTICIPANTS_SOURCE_MANUAL_OVERRIDE,
  };
}

export function applyParticipantsTruthToItem(item, planParticipants, fallbackParticipants = null) {
  if (hasManualParticipantsOverride(item)) {
    return {
      ...item,
      ...buildManualParticipants(item?.participants, planParticipants ?? fallbackParticipants),
    };
  }

  return {
    ...item,
    ...buildInheritedParticipants(planParticipants, fallbackParticipants),
  };
}

export function isManualTimeLocked(item) {
  return (
    item?.manual_locked === true ||
    item?.manualLocked === true ||
    item?.time_source === TIME_SOURCE_MANUAL ||
    item?.timeSource === TIME_SOURCE_MANUAL
  );
}

export function buildManualTimeFields() {
  return {
    manual_locked: true,
    time_source: TIME_SOURCE_MANUAL,
  };
}

export function buildAutoTimeFields(source = TIME_SOURCE_AUTO) {
  return {
    manual_locked: false,
    time_source: source || TIME_SOURCE_AUTO,
  };
}

export function canRemovePlannerItem() {
  return true;
}

export function shouldApplyAvailabilitySuggestedStart(item, options = {}) {
  if (options?.explicitAutoReschedule === true) {
    return true;
  }

  if (isManualTimeLocked(item)) {
    return false;
  }

  return item?.allow_auto_reschedule === true;
}

export function isNonDefinitiveAvailabilityIssue(issue, reasonCode = null) {
  const rawReason =
    typeof reasonCode === "string" && reasonCode.trim() !== ""
      ? reasonCode
      : typeof issue?.reasonCode === "string"
      ? issue.reasonCode
      : "";
  const reason = rawReason.trim().toLowerCase();
  const message = typeof issue?.message === "string" ? issue.message.trim().toLowerCase() : "";

  if (
    [
      "availability_suggested_start",
      "availability_check_needed",
      "availability_manual_check",
      "manual_availability_check",
      "selected_time_unavailable_with_alternative",
      "suggested_alternative_available",
      "suggested_start_available",
      "requires_availability_check",
    ].includes(reason)
  ) {
    return true;
  }

  return (
    message.includes("beschikbaarheid controleren") ||
    message.includes("mogelijke optie") ||
    message.includes("misschien tijdslot mogelijk") ||
    message.includes("availability check") ||
    message.includes("check needed") ||
    message.includes("suggested start")
  );
}

export function isHardAvailabilityBlocker(reasonCode = null) {
  const reason = typeof reasonCode === "string" ? reasonCode.trim().toLowerCase() : "";

  return [
    "item_unavailable",
    "capacity_exceeded",
    "availability_unavailable",
    "definitively_unavailable",
    "no_availability",
    "booking_blocked",
    "server_blocked",
  ].includes(reason);
}

export function shouldAvailabilityIssueBlockDirectCheckout(routeIntent, issue, reasonCode = null) {
  if (routeIntent !== "checkout") {
    return Boolean(issue?.message) || reasonCode === "availability_lookup_failed";
  }

  if (isHardAvailabilityBlocker(reasonCode)) {
    return true;
  }

  if (isNonDefinitiveAvailabilityIssue(issue, reasonCode)) {
    return false;
  }

  return Boolean(issue?.message) || reasonCode === "availability_lookup_failed";
}

function plannerTimeToMinutes(value) {
  if (typeof value !== "string" || !/^\d{1,2}:\d{2}$/.test(value.trim())) {
    return NaN;
  }

  const [hours, minutes] = value.trim().split(":").map((part) => Number.parseInt(part, 10));
  if (!Number.isFinite(hours) || !Number.isFinite(minutes)) {
    return NaN;
  }

  return hours * 60 + minutes;
}

export function filterStartOptionsWithinPlannerHours(startOptions, durationMinutes, openHours) {
  if (!Array.isArray(startOptions) || startOptions.length === 0) {
    return [];
  }

  if (!openHours?.start || !openHours?.end) {
    return startOptions;
  }

  const openStart = plannerTimeToMinutes(openHours.start);
  const openEnd = plannerTimeToMinutes(openHours.end);
  if (!Number.isFinite(openStart) || !Number.isFinite(openEnd)) {
    return startOptions;
  }

  const duration = Number.parseInt(durationMinutes, 10);
  if (!Number.isFinite(duration) || duration <= 0) {
    return startOptions.filter((startMinutes) => Number.isFinite(startMinutes) && startMinutes >= openStart);
  }

  return startOptions.filter((startMinutes) => {
    if (!Number.isFinite(startMinutes)) {
      return false;
    }

    return startMinutes >= openStart && startMinutes + duration <= openEnd;
  });
}

export function resolveUserOrder(item, fallbackIndex = 0) {
  const explicit = toPlannerPositiveInt(item?.user_order ?? item?.userOrder ?? item?.sequence);
  return explicit ?? fallbackIndex + 1;
}

export function countCriticalPlannerItemOverlaps(items, timeToMinutes) {
  if (!Array.isArray(items) || items.length < 2 || typeof timeToMinutes !== "function") {
    return 0;
  }

  const byDay = new Map();
  items.forEach((item, index) => {
    const dayIndex =
      typeof item?.dayIndex === "number" && Number.isFinite(item.dayIndex)
        ? item.dayIndex
        : Number.parseInt(item?.dayIndex, 10) || 0;
    const start =
      typeof item?.startMinutes === "number" && Number.isFinite(item.startMinutes)
        ? item.startMinutes
        : typeof item?.startTime === "string"
        ? timeToMinutes(item.startTime)
        : NaN;
    const end =
      typeof item?.endMinutes === "number" && Number.isFinite(item.endMinutes)
        ? item.endMinutes
        : typeof item?.endTime === "string"
        ? timeToMinutes(item.endTime)
        : NaN;

    if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) {
      return;
    }

    const entries = byDay.get(dayIndex) || [];
    entries.push({
      id: item?.id || `idx-${index}`,
      groupId: typeof item?.groupId === "string" ? item.groupId : "",
      start,
      end,
    });
    byDay.set(dayIndex, entries);
  });

  let count = 0;
  byDay.forEach((entries) => {
    const sorted = entries.slice().sort((left, right) => left.start - right.start || left.end - right.end);
    for (let index = 0; index < sorted.length - 1; index += 1) {
      const current = sorted[index];
      const next = sorted[index + 1];
      if (current.groupId && current.groupId === next.groupId) {
        continue;
      }
      if (current.end > next.start) {
        count += 1;
      }
    }
  });

  return count;
}
