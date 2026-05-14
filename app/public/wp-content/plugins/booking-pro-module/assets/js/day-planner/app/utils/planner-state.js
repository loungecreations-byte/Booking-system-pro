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

export function resolveParticipantsForItem(item, planParticipants, fallbackParticipants = 10) {
  const fallback = toPlannerPositiveInt(fallbackParticipants) ?? 10;
  const canonical = toPlannerPositiveInt(planParticipants) ?? fallback;

  if (hasManualParticipantsOverride(item)) {
    return toPlannerPositiveInt(item?.participants) ?? canonical;
  }

  return canonical;
}

export function buildInheritedParticipants(planParticipants, fallbackParticipants = 10) {
  return {
    participants: toPlannerPositiveInt(planParticipants) ?? toPlannerPositiveInt(fallbackParticipants) ?? 10,
    participants_override: false,
    participants_source: PARTICIPANTS_SOURCE_INHERITED,
  };
}

export function buildManualParticipants(participants, fallbackParticipants = 10) {
  return {
    participants: toPlannerPositiveInt(participants) ?? toPlannerPositiveInt(fallbackParticipants) ?? 10,
    participants_override: true,
    participants_source: PARTICIPANTS_SOURCE_MANUAL_OVERRIDE,
  };
}

export function applyParticipantsTruthToItem(item, planParticipants, fallbackParticipants = 10) {
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

export function shouldApplyAvailabilitySuggestedStart(item, options = {}) {
  if (options?.explicitAutoReschedule === true) {
    return true;
  }

  if (isManualTimeLocked(item)) {
    return false;
  }

  return item?.allow_auto_reschedule === true;
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
