const DEFAULT_PIXELS_PER_MINUTE = 1.5;

function getItemLayoutIdentity(item, fallbackIndex) {
  if (!item || typeof item !== "object") {
    return `idx-${fallbackIndex}`;
  }

  const id = typeof item.id === "string" && item.id.trim() !== "" ? item.id.trim() : "";
  if (id) {
    return `id:${id}`;
  }

  const plannerKey =
    typeof item.plannerKey === "string" && item.plannerKey.trim() !== ""
      ? item.plannerKey.trim()
      : typeof item.cartMapping?.line_hash === "string" && item.cartMapping.line_hash.trim() !== ""
      ? item.cartMapping.line_hash.trim()
      : "";

  if (plannerKey) {
    return `key:${plannerKey}`;
  }

  return [
    "slot",
    item.productId ?? item.product_id ?? "",
    item.dayIndex ?? "",
    item.startTime ?? item.startMinutes ?? "",
    item.endTime ?? item.endMinutes ?? "",
    fallbackIndex,
  ].join(":");
}

function getItemGroupId(item) {
  const groupId =
    typeof item?.groupId === "string" && item.groupId.trim() !== ""
      ? item.groupId.trim()
      : typeof item?.bookingResolution?.groupId === "string" &&
        item.bookingResolution.groupId.trim() !== ""
      ? item.bookingResolution.groupId.trim()
      : "";

  return groupId;
}

function hasValidTiming(item) {
  return (
    Number.isFinite(item?.startMinutes) &&
    Number.isFinite(item?.endMinutes) &&
    item.endMinutes > item.startMinutes
  );
}

function eventsOverlap(left, right) {
  return left.startMinutes < right.endMinutes && right.startMinutes < left.endMinutes;
}

function shouldIgnoreLayoutCollision(left, right) {
  if (!left || !right) {
    return true;
  }

  if (left === right || left.identity === right.identity) {
    return true;
  }

  return Boolean(left.groupId && left.groupId === right.groupId);
}

function logTimelineLayoutDebug(event, overlapping, source) {
  if (typeof window === "undefined" || window.SBDP_TIMELINE_DEBUG !== true) {
    return;
  }

  const comparisons = overlapping.map((other) => ({
    compared_item_id: other.item?.id || "",
    compared_title: other.item?.title || "",
    compared_start: other.item?.startTime || other.startMinutes,
    compared_end: other.item?.endTime || other.endMinutes,
    overlap_true_false: true,
  }));

  console.debug("[SBDP timeline layout]", {
    item_id: event.item?.id || "",
    title: event.item?.title || "",
    start_time: event.item?.startTime || event.startMinutes,
    end_time: event.item?.endTime || event.endMinutes,
    compared: comparisons,
    lane_index: event.column,
    lane_count: event.columnCount,
    computed_width: 100 / event.columnCount,
    computed_left: (100 / event.columnCount) * event.column,
    source,
    render_cycle: Date.now(),
  });
}

export function buildTimelineDayLayout(
  items,
  timelineWindow,
  {
    pixelsPerMinute = DEFAULT_PIXELS_PER_MINUTE,
    source = "buildTimelineDayLayout",
  } = {}
) {
  const seenIdentities = new Set();
  const events = (Array.isArray(items) ? items : [])
    .map((item, index) => ({
      item,
      index,
      identity: getItemLayoutIdentity(item, index),
      groupId: getItemGroupId(item),
    }))
    .filter((entry) => {
      if (!hasValidTiming(entry.item) || seenIdentities.has(entry.identity)) {
        return false;
      }
      seenIdentities.add(entry.identity);
      return true;
    })
    .map((entry) => ({
      item: entry.item,
      identity: entry.identity,
      groupId: entry.groupId,
      startMinutes: entry.item.startMinutes,
      endMinutes: entry.item.endMinutes,
      durationMinutes: Math.max(15, entry.item.endMinutes - entry.item.startMinutes),
    }))
    .sort(
      (a, b) =>
        a.startMinutes - b.startMinutes ||
        a.durationMinutes - b.durationMinutes ||
        a.identity.localeCompare(b.identity)
    );

  const active = [];

  events.forEach((event) => {
    for (let index = active.length - 1; index >= 0; index -= 1) {
      const activeEvent = active[index];
      if (
        activeEvent.endMinutes <= event.startMinutes ||
        shouldIgnoreLayoutCollision(activeEvent, event)
      ) {
        active.splice(index, 1);
      }
    }

    const usedColumns = new Set(active.map((entry) => entry.column));
    let column = 0;
    while (usedColumns.has(column)) {
      column += 1;
    }
    event.column = column;
    active.push(event);
  });

  events.forEach((event) => {
    const overlapping = events.filter(
      (other) => !shouldIgnoreLayoutCollision(event, other) && eventsOverlap(event, other)
    );
    const maxColumn = Math.max(event.column, ...overlapping.map((entry) => entry.column));
    event.columnCount = maxColumn + 1;
    logTimelineLayoutDebug(event, overlapping, source);
  });

  const windowStart = Number.isFinite(timelineWindow?.startMinutes)
    ? timelineWindow.startMinutes
    : 0;

  return events.map((event) => {
    const durationMinutes = event.endMinutes - event.startMinutes;
    const top = Math.max(0, (event.startMinutes - windowStart) * pixelsPerMinute);
    const height = Math.max(20, durationMinutes * pixelsPerMinute);
    const width = 100 / event.columnCount;
    const left = width * event.column;

    return {
      item: event.item,
      top,
      height,
      left,
      width,
    };
  });
}

export const __timelineLayoutInternals = {
  eventsOverlap,
  getItemGroupId,
  getItemLayoutIdentity,
  shouldIgnoreLayoutCollision,
};
