import { minutesToTime } from "./time.js";

const SEGMENT_TYPE_LABELS = {
  reception: "Ontvangst",
  activity: "Activiteit",
  food_drink: "Horeca",
  transport: "Transfer",
  free_time: "Vrije tijd",
  addon: "Add-on",
  optional_upgrade: "Optie",
};

const FIXED_STATUS_LABELS = {
  fixed: "Vast",
  "semi-flex": "Semi-flex",
  flexible: "Flexibel",
};

export function formatDurationLabel(minutes) {
  const value = Number.isFinite(minutes) ? Math.max(0, Math.round(minutes)) : 0;
  if (value <= 0) {
    return "0 min";
  }
  if (value >= 60) {
    const hours = Math.floor(value / 60);
    const remainder = value % 60;
    if (remainder === 0) {
      return `${hours} uur`;
    }
    return `${hours}u ${remainder}m`;
  }
  return `${value} min`;
}

export function formatTimeRange(startMinutes, endMinutes) {
  if (!Number.isFinite(startMinutes) || !Number.isFinite(endMinutes)) {
    return "";
  }

  return `${minutesToTime(startMinutes)}-${minutesToTime(endMinutes)}`;
}

export function getSegmentTypeLabel(segmentType) {
  const key = typeof segmentType === "string" ? segmentType.trim() : "";
  return SEGMENT_TYPE_LABELS[key] || "Onderdeel";
}

function getProgramSegmentRoleLabel(item, entry) {
  const role = typeof item?.role === "string" ? item.role.trim() : "";
  const isArrangementSegment =
    role === "pre" ||
    role === "post" ||
    role === "anchor" ||
    Boolean(item?.groupId) ||
    Boolean(item?.aggregateId) ||
    entry?.kind === "arrangement";

  if (!isArrangementSegment) {
    return getSegmentTypeLabel(item?.segment_type);
  }

  if (role === "pre") {
    return "Vooraf";
  }

  if (role === "post") {
    return "Achteraf";
  }

  return "Hoofdactiviteit";
}

export function getFixedStatusLabel(segment) {
  if (!segment || typeof segment !== "object") {
    return FIXED_STATUS_LABELS.flexible;
  }

  if (segment.fixed_status && FIXED_STATUS_LABELS[segment.fixed_status]) {
    return FIXED_STATUS_LABELS[segment.fixed_status];
  }

  if (segment.required) {
    return FIXED_STATUS_LABELS.fixed;
  }

  if (segment.is_optional || segment.is_replaceable) {
    return FIXED_STATUS_LABELS.flexible;
  }

  return FIXED_STATUS_LABELS["semi-flex"];
}

export function getSegmentLocation(segment) {
  if (!segment || typeof segment !== "object") {
    return "";
  }

  const values = [
    segment.location_name,
    segment.location,
    segment.resourceLabel,
    segment.resource_label,
    segment.vendor_name,
    segment.vendorName,
  ];

  for (const value of values) {
    if (typeof value === "string" && value.trim() !== "") {
      return value.trim();
    }
  }

  return "";
}

export function getProgramTitle(primaryItem, segments = []) {
  const explicitProgramTitle =
    primaryItem?.bookingResolution?.source_title ||
    primaryItem?.bookingResolution?.summary?.title ||
    primaryItem?.aggregate?.title ||
    primaryItem?.program_title ||
    "";
  const fallbackTitle = primaryItem?.title || primaryItem?.name || "";
  const sourceTitle = explicitProgramTitle || fallbackTitle;

  const segmentTitles = segments
    .map((entry) => entry?.item?.title || entry?.item?.name || "")
    .filter(Boolean);

  if (!segmentTitles.length) {
    return sourceTitle || "Jouw arrangement";
  }

  const normalizedSourceTitle = sourceTitle.trim().toLowerCase();
  const segmentTitleMatches = segmentTitles.some(
    (segmentTitle) => segmentTitle.trim().toLowerCase() === normalizedSourceTitle
  );

  if (segmentTitles.length === 1) {
    return sourceTitle || segmentTitles[0] || "Jouw arrangement";
  }

  if (explicitProgramTitle) {
    return explicitProgramTitle;
  }

  if (sourceTitle && !segmentTitleMatches && segmentTitles.length <= 1) {
    return sourceTitle;
  }

  return "Jouw arrangement";
}

export function buildProgramTimeline(primaryItem, segmentEntries = []) {
  const entries = Array.isArray(segmentEntries) ? segmentEntries : [];
  const dedupedSegments = new Map();
  entries.forEach((entry, index) => {
    const item = entry?.item || {};
    if (!Number.isFinite(item.startMinutes) || !Number.isFinite(item.endMinutes)) {
      return;
    }

    const dedupeKey = [
      item.id || "",
      item.role || "",
      item.title || item.name || "",
      item.startMinutes,
      item.endMinutes,
    ].join("|");

    if (!dedupedSegments.has(dedupeKey)) {
      dedupedSegments.set(dedupeKey, {
        entry,
        item,
        index,
      });
    }
  });

  const sortedSegments = Array.from(dedupedSegments.values())
    .map((entry) => ({
      entry: entry.entry,
      item: entry.item,
      index: entry.index,
    }))
    .sort((left, right) => {
      const leftStart = Number.isFinite(left.item.startMinutes) ? left.item.startMinutes : 0;
      const rightStart = Number.isFinite(right.item.startMinutes) ? right.item.startMinutes : 0;
      return leftStart - rightStart || (left.item.endMinutes - left.item.startMinutes) - (right.item.endMinutes - right.item.startMinutes);
    });

  const segments = sortedSegments.map(({ entry, item }, index) => {
    const durationMinutes = Math.max(0, (item.endMinutes || 0) - (item.startMinutes || 0));
    return {
      id: item.id || `${primaryItem?.id || "program"}-${index}`,
      item,
      title: item.title || item.name || "Onderdeel",
      type: item.segment_type || (item.role === "anchor" ? "activity" : "activity"),
      typeLabel: getProgramSegmentRoleLabel(item, entry),
      locationName: getSegmentLocation(item),
      startMinutes: item.startMinutes,
      endMinutes: item.endMinutes,
      startTime: item.startTime || minutesToTime(item.startMinutes),
      endTime: item.endTime || minutesToTime(item.endMinutes),
      durationMinutes,
      durationLabel: formatDurationLabel(durationMinutes),
      fixedStatus: item.fixed_status || (item.required ? "fixed" : item.is_optional || item.is_replaceable ? "flexible" : "semi-flex"),
      fixedStatusLabel: getFixedStatusLabel(item),
      bufferBeforeMinutes: Number.isFinite(item.buffer_before_minutes) ? item.buffer_before_minutes : 0,
      bufferAfterMinutes: Number.isFinite(item.buffer_after_minutes) ? item.buffer_after_minutes : 0,
      travelMinutesFromPrevious: Number.isFinite(item.travel_minutes_from_previous) ? item.travel_minutes_from_previous : 0,
      notes: typeof item.notes === "string" ? item.notes.trim() : "",
      supplierConstraints: typeof item.supplier_constraints === "string" ? item.supplier_constraints.trim() : "",
      isPrimary: Boolean(item.role === "anchor" || entry?.kind === "arrangement"),
      canMoveIndividually: !item.is_locked && !item.locked && item.role !== "anchor",
    };
  });

  const transitions = [];
  for (let index = 0; index < segments.length - 1; index += 1) {
    const current = segments[index];
    const next = segments[index + 1];
    const gapMinutes = Math.max(0, (next.startMinutes || 0) - (current.endMinutes || 0));
    if (gapMinutes <= 0) {
      continue;
    }

    const locationChanged =
      current.locationName && next.locationName && current.locationName !== next.locationName;
    const kind = gapMinutes >= 20 || locationChanged ? "travel" : "buffer";
    const detailLabel = kind === "travel" ? "Verplaatsing" : "Buffer";
    const context = locationChanged
      ? `Van ${current.locationName} naar ${next.locationName}`
      : kind === "travel"
      ? "Wissel van locatie"
      : "Speling tussen onderdelen";

    transitions.push({
      id: `${current.id}-to-${next.id}`,
      kind,
      label: detailLabel,
      detail: `${formatDurationLabel(gapMinutes)} · ${context}`,
      gapMinutes,
      startMinutes: current.endMinutes,
      endMinutes: next.startMinutes,
      startTime: minutesToTime(current.endMinutes),
      endTime: minutesToTime(next.startMinutes),
    });
  }

  const startMinutes = segments.length > 0 ? segments[0].startMinutes : null;
  const endMinutes = segments.length > 0 ? segments[segments.length - 1].endMinutes : null;
  const durationMinutes =
    Number.isFinite(startMinutes) && Number.isFinite(endMinutes) ? endMinutes - startMinutes : 0;
  const fixedCount = segments.filter((segment) => segment.fixedStatus === "fixed").length;
  const flexibleCount = segments.filter((segment) => segment.fixedStatus === "flexible").length;
  const semiFlexCount = segments.filter((segment) => segment.fixedStatus === "semi-flex").length;
  const title = getProgramTitle(primaryItem, entries);
  const segmentNames = segments.map((segment) => segment.title).filter(Boolean);
  const segmentSummary =
    segmentNames.length > 0
      ? segmentNames.slice(0, 3).join(" · ") + (segmentNames.length > 3 ? ` +${segmentNames.length - 3}` : "")
      : `${segments.length} onderdelen`;

  return {
    title,
    segmentSummary,
    startMinutes,
    endMinutes,
    startTime: Number.isFinite(startMinutes) ? minutesToTime(startMinutes) : "",
    endTime: Number.isFinite(endMinutes) ? minutesToTime(endMinutes) : "",
    durationMinutes,
    durationLabel: formatDurationLabel(durationMinutes),
    segmentCount: segments.length,
    fixedCount,
    flexibleCount,
    semiFlexCount,
    transitions,
    segments,
  };
}
