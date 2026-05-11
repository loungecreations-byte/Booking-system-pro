import { minutesToTime, timeToMinutes } from "./time.js";

const SEGMENT_STATUSES = new Set(["confirmed", "needs_choice", "error"]);
const BOOKING_STATUSES = new Set(["valid", "partial", "invalid"]);
const PREFERRED_COMBI_BUFFER_MINUTES = 30;
const MIN_COMBI_BUFFER_MINUTES = 15;

function cleanText(value) {
  return typeof value === "string" ? value.trim() : "";
}

function positiveIntOrNull(value) {
  const parsed = Number.parseInt(value, 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

function finiteMinutesOrNull(value) {
  const parsed = Number.parseInt(value, 10);
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : null;
}

function buildIso(date, time) {
  const cleanDate = cleanText(date);
  const cleanTime = cleanText(time);
  if (!cleanDate || !cleanTime) {
    return null;
  }

  return `${cleanDate}T${cleanTime}:00`;
}

function normaliseRole(rawRole, rawTiming) {
  const role = cleanText(rawRole).toLowerCase();
  if (role === "pre" || role === "post" || role === "anchor") {
    return role;
  }

  const timing = cleanText(rawTiming).toLowerCase();
  if (timing === "after") {
    return "post";
  }
  if (timing === "before") {
    return "pre";
  }

  return null;
}

function normaliseTiming(rawTiming, rawRole) {
  const timing = cleanText(rawTiming).toLowerCase();
  if (timing === "before" || timing === "after") {
    return timing;
  }

  const role = cleanText(rawRole).toLowerCase();
  if (role === "pre") {
    return "before";
  }
  if (role === "post") {
    return "after";
  }

  return null;
}

function normaliseWarningsErrors(value) {
  if (!Array.isArray(value)) {
    return [];
  }

  return value
    .map((entry) => cleanText(entry))
    .filter(Boolean);
}

function deriveMainDurationMinutes(source) {
  const candidates = [
    source?.durationMinutes,
    source?.duration_minutes,
    source?.duration?.minutes,
    source?.duration,
    source?.aggregate?.timeline?.durationMinutes,
    source?.bookingResolution?.segments?.find((segment) => segment?.role === "anchor")?.duration_minutes,
  ];

  for (const candidate of candidates) {
    const duration = finiteMinutesOrNull(candidate);
    if (duration !== null && duration > 0) {
      return duration;
    }
  }

  return null;
}

function normaliseCombiItem(rawItem, index) {
  if (!rawItem || typeof rawItem !== "object") {
    return null;
  }

  const productId = positiveIntOrNull(rawItem.productId ?? rawItem.product_id ?? rawItem.id);
  if (!productId) {
    return null;
  }

  const label = cleanText(rawItem.label ?? rawItem.title ?? rawItem.name);
  const duration = finiteMinutesOrNull(rawItem.durationMinutes ?? rawItem.duration);
  const timing = normaliseTiming(rawItem.timing, rawItem.role);
  const role = normaliseRole(rawItem.role, rawItem.timing);
  const order = Number.isFinite(rawItem.order) && rawItem.order >= 0 ? rawItem.order : index;
  const warnings = normaliseWarningsErrors(rawItem.warnings);
  const errors = normaliseWarningsErrors(rawItem.errors);

  if (!timing) {
    warnings.push("segment_timing_missing");
  }
  if (!role) {
    warnings.push("segment_role_missing");
  }
  if (!label) {
    errors.push("segment_title_missing");
  }
  if (!duration || duration <= 0) {
    errors.push("segment_duration_missing");
  }

  const status =
    errors.length > 0 ? "error" : warnings.length > 0 ? "needs_choice" : "confirmed";

  return {
    id: productId,
    productId,
    label,
    name: label,
    title: label,
    timing,
    role,
    order,
    durationMinutes: duration,
    status,
    warnings,
    errors,
    vendorId: positiveIntOrNull(rawItem.vendorId ?? rawItem.vendor_id),
    location: cleanText(rawItem.location),
    isLocked: Boolean(rawItem.isLocked || rawItem.locked),
  };
}

function normaliseSourceItems(mainItem, combiItems = []) {
  const source = mainItem && typeof mainItem === "object" ? mainItem : {};
  const explicitCombis = Array.isArray(combiItems) ? combiItems : [];
  const resolvedCombis = explicitCombis
    .map((item, index) => normaliseCombiItem(item, index))
    .filter(Boolean)
    .sort((left, right) => (left.order || 0) - (right.order || 0));

  const bookingResolution = source.bookingResolution && typeof source.bookingResolution === "object"
    ? source.bookingResolution
    : null;
  const existingSegments = Array.isArray(bookingResolution?.segments)
    ? bookingResolution.segments
    : [];

  if (resolvedCombis.length === 0 && existingSegments.length > 0) {
    existingSegments
      .filter((segment) => segment && typeof segment === "object" && segment.role !== "anchor")
      .forEach((segment, index) => {
        const productId = positiveIntOrNull(segment.product_id ?? segment.productId);
        if (!productId) {
          return;
        }
        resolvedCombis.push(
          normaliseCombiItem(
            {
              id: productId,
              productId,
              label: cleanText(segment.title),
              timing: segment.timing || (segment.role === "post" ? "after" : "before"),
              role: segment.role || (segment.timing === "after" ? "post" : "pre"),
              order: index,
              durationMinutes: segment.duration_minutes ?? segment.durationMinutes,
              vendorId: segment.vendor_id ?? segment.vendorId,
              location: segment.location,
              warnings: segment.warnings,
              errors: segment.errors,
              isLocked: segment.is_locked ?? segment.isLocked,
            },
            index
          )
        );
      });
  }

  return resolvedCombis;
}

function buildResolvedSegment({
  payload,
  sourceItem,
  segmentId,
  productId,
  title,
  role,
  status,
  startMinutes,
  durationMinutes,
  warnings = [],
  errors = [],
  timing = null,
  vendorId = null,
  location = "",
  isLocked = false,
}) {
  const cleanWarnings = normaliseWarningsErrors(warnings);
  const cleanErrors = normaliseWarningsErrors(errors);
  const segmentStatus =
    status && SEGMENT_STATUSES.has(status)
      ? status
      : cleanErrors.length > 0
      ? "error"
      : cleanWarnings.length > 0
      ? "needs_choice"
      : "confirmed";

  const hasTiming = Number.isFinite(startMinutes) && Number.isFinite(durationMinutes) && durationMinutes > 0;
  const endMinutes = hasTiming ? startMinutes + durationMinutes : null;
  const startTime = hasTiming ? minutesToTime(startMinutes) : null;
  const endTime = hasTiming ? minutesToTime(endMinutes) : null;
  const start = hasTiming && sourceItem.date ? buildIso(sourceItem.date, startTime) : null;
  const end = hasTiming && sourceItem.date ? buildIso(sourceItem.date, endTime) : null;

  return {
    segment_id: segmentId,
    product_id: productId,
    title,
    start,
    end,
    startMinutes: hasTiming ? startMinutes : null,
    endMinutes: hasTiming ? endMinutes : null,
    startTime,
    endTime,
    duration_minutes: hasTiming ? durationMinutes : null,
    vendor_id: vendorId,
    location,
    timing,
    role,
    status: segmentStatus,
    availability_status: segmentStatus === "confirmed" ? "unknown" : "unresolved",
    is_locked: Boolean(isLocked),
    warnings: cleanWarnings,
    errors: cleanErrors,
    groupId: payload.groupId,
    aggregateId: payload.aggregateId,
  };
}

function buildAggregateSnapshot(resolution, confirmedSegments) {
  if (!Array.isArray(confirmedSegments) || confirmedSegments.length === 0) {
    return undefined;
  }

  const first = confirmedSegments[0];
  const last = confirmedSegments[confirmedSegments.length - 1];
  if (!Number.isFinite(first?.startMinutes) || !Number.isFinite(last?.endMinutes)) {
    return undefined;
  }

  return {
    title: resolution?.source_title || first?.title || "",
    timeline: {
      start: first.start,
      end: last.end,
      startTime: first.startTime,
      endTime: last.endTime,
      durationMinutes: last.endMinutes - first.startMinutes,
    },
    groupId: resolution.groupId,
    aggregateId: resolution.aggregateId,
    status: resolution.status,
    segments: resolution.segments,
  };
}

export function isArrangement(item) {
  const hasStructuredComboSegments = Array.isArray(item?.bookingResolution?.segments)
    ? item.bookingResolution.segments.some((segment) => segment && segment.role && segment.role !== "anchor")
    : false;
  return (
    item?.type === "arrangement" ||
    item?.source === "product-combi" ||
    (Array.isArray(item?.combiItems) && item.combiItems.length > 0) ||
    (Array.isArray(item?.options?.combiItems) && item.options.combiItems.length > 0) ||
    Boolean(item?.groupId) ||
    Boolean(item?.bookingResolution?.groupId && hasStructuredComboSegments)
  );
}

export function buildResolvedBookingPayload(mainItem, combiItems = []) {
  const sourceItem = mainItem && typeof mainItem === "object" ? { ...mainItem } : {};
  const sourceProductId = positiveIntOrNull(sourceItem.productId ?? sourceItem.product_id ?? sourceItem.id);
  const sourceTitle = cleanText(sourceItem.title ?? sourceItem.name);
  const bookingDate = cleanText(
    sourceItem.date ?? sourceItem.booking_date ?? sourceItem.plannerInput?.date ?? ""
  );
  const sourceTime = cleanText(
    sourceItem.startTime ??
      sourceItem.time ??
      sourceItem.start ??
      sourceItem.plannerInput?.timeslot?.start ??
      sourceItem.bookingResolution?.segments?.find((segment) => segment?.role === "anchor")?.startTime ??
      ""
  );
  const sourceStartMinutes = Number.isFinite(sourceItem.startMinutes)
    ? sourceItem.startMinutes
    : sourceTime
    ? timeToMinutes(sourceTime)
    : null;
  const sourceDurationMinutes = deriveMainDurationMinutes(sourceItem);
  const currency = cleanText(
    sourceItem.currency ?? sourceItem.pricing?.currency ?? sourceItem.bookingResolution?.currency ?? ""
  ) || "EUR";
  const participants = positiveIntOrNull(
    sourceItem.participants ?? sourceItem.people ?? sourceItem.quantity
  );

  const groupId =
    cleanText(sourceItem.groupId) ||
    cleanText(sourceItem.bookingResolution?.groupId) ||
    cleanText(sourceItem.aggregateId) ||
    `grp-${sourceProductId || sourceItem.id || Date.now()}`;
  const aggregateId =
    cleanText(sourceItem.aggregateId) ||
    cleanText(sourceItem.bookingResolution?.aggregateId) ||
    groupId;

  const warnings = normaliseWarningsErrors(sourceItem.warnings);
  const errors = normaliseWarningsErrors(sourceItem.errors);
  const sourceType = cleanText(sourceItem.source) || (Array.isArray(combiItems) && combiItems.length > 0 ? "product-combi" : "product");

  if (!sourceProductId) {
    errors.push("main_product_missing");
  }
  if (!sourceTitle) {
    errors.push("main_title_missing");
  }
  if (!bookingDate) {
    errors.push("booking_date_missing");
  }
  if (!Number.isFinite(sourceStartMinutes)) {
    warnings.push("start_time_missing");
  }
  if (!Number.isFinite(sourceDurationMinutes) || sourceDurationMinutes <= 0) {
    warnings.push("duration_missing");
  }

  const normalizedCombis = normaliseSourceItems(sourceItem, combiItems);
  const preparedSegments = [];

  const confirmedPre = normalizedCombis.filter((item) => item.timing === "before" && item.status === "confirmed");
  const unresolvedPre = normalizedCombis.filter((item) => item.timing === "before" && item.status !== "confirmed");
  const confirmedPost = normalizedCombis.filter((item) => item.timing === "after" && item.status === "confirmed");
  const unresolvedPost = normalizedCombis.filter((item) => item.timing === "after" && item.status !== "confirmed");
  const unresolvedTiming = normalizedCombis.filter((item) => !item.timing || !item.role);

  const mainConfirmed =
    Boolean(sourceProductId) &&
    Boolean(sourceTitle) &&
    Boolean(bookingDate) &&
    Number.isFinite(sourceDurationMinutes) &&
    sourceDurationMinutes > 0;

  const mainSegment = buildResolvedSegment({
    payload: { groupId, aggregateId },
    sourceItem: { ...sourceItem, date: bookingDate },
    segmentId: `${groupId}:anchor`,
    productId: sourceProductId || 0,
    title: sourceTitle,
    role: "anchor",
    status: mainConfirmed ? "confirmed" : errors.length > 0 ? "error" : "needs_choice",
    startMinutes: mainConfirmed ? sourceStartMinutes : null,
    durationMinutes: mainConfirmed ? sourceDurationMinutes : null,
    warnings,
    errors,
    timing: null,
    vendorId: positiveIntOrNull(sourceItem.vendorId ?? sourceItem.vendor_id),
    location: cleanText(sourceItem.location ?? sourceItem.resourceLabel ?? sourceItem.resource_label),
    isLocked: Boolean(sourceItem.locked),
  });

  preparedSegments.push(mainSegment);

  const hasAnchorTime = mainConfirmed && Number.isFinite(sourceStartMinutes);
  let cursor = hasAnchorTime ? sourceStartMinutes - PREFERRED_COMBI_BUFFER_MINUTES : null;
  const preConfirmedStart = hasAnchorTime ? sourceStartMinutes : null;
  const preDurationTotal = confirmedPre.reduce((total, item) => total + (item.durationMinutes || 0), 0);
  if (hasAnchorTime && preDurationTotal > 0) {
    cursor = sourceStartMinutes - preDurationTotal - PREFERRED_COMBI_BUFFER_MINUTES;
  }

  confirmedPre.forEach((item) => {
    const startMinutes = Number.isFinite(cursor) ? cursor : null;
    const segment = buildResolvedSegment({
      payload: { groupId, aggregateId },
      sourceItem: { ...sourceItem, date: bookingDate },
      segmentId: `${groupId}:pre:${item.productId}`,
      productId: item.productId,
      title: item.title,
      role: "pre",
      status: "confirmed",
      startMinutes,
      durationMinutes: item.durationMinutes,
      warnings: item.warnings,
      errors: item.errors,
      timing: item.timing,
      vendorId: item.vendorId,
      location: item.location,
      isLocked: item.isLocked,
    });
    if (segment && Number.isFinite(segment.endMinutes)) {
      segment.buffer_after_minutes = PREFERRED_COMBI_BUFFER_MINUTES;
      segment.preferred_buffer_after_minutes = PREFERRED_COMBI_BUFFER_MINUTES;
      segment.minimum_buffer_after_minutes = MIN_COMBI_BUFFER_MINUTES;
    }
    preparedSegments.push(segment);
    if (Number.isFinite(segment.endMinutes)) {
      cursor = segment.endMinutes;
    }
  });

  if (hasAnchorTime) {
    const postStartBase = sourceStartMinutes + sourceDurationMinutes + PREFERRED_COMBI_BUFFER_MINUTES;
    let postCursor = postStartBase;

    if (mainSegment && Number.isFinite(mainSegment.startMinutes)) {
      mainSegment.buffer_before_minutes = confirmedPre.length > 0 ? PREFERRED_COMBI_BUFFER_MINUTES : 0;
      mainSegment.buffer_after_minutes = confirmedPost.length > 0 ? PREFERRED_COMBI_BUFFER_MINUTES : 0;
      mainSegment.preferred_buffer_before_minutes = confirmedPre.length > 0 ? PREFERRED_COMBI_BUFFER_MINUTES : 0;
      mainSegment.preferred_buffer_after_minutes = confirmedPost.length > 0 ? PREFERRED_COMBI_BUFFER_MINUTES : 0;
      mainSegment.minimum_buffer_before_minutes = confirmedPre.length > 0 ? MIN_COMBI_BUFFER_MINUTES : 0;
      mainSegment.minimum_buffer_after_minutes = confirmedPost.length > 0 ? MIN_COMBI_BUFFER_MINUTES : 0;
    }

    confirmedPost.forEach((item) => {
      const segment = buildResolvedSegment({
        payload: { groupId, aggregateId },
        sourceItem: { ...sourceItem, date: bookingDate },
        segmentId: `${groupId}:post:${item.productId}`,
        productId: item.productId,
        title: item.title,
        role: "post",
        status: "confirmed",
        startMinutes: postCursor,
        durationMinutes: item.durationMinutes,
        warnings: item.warnings,
        errors: item.errors,
        timing: item.timing,
        vendorId: item.vendorId,
        location: item.location,
        isLocked: item.isLocked,
      });
      if (segment && Number.isFinite(segment.startMinutes)) {
        segment.buffer_before_minutes = PREFERRED_COMBI_BUFFER_MINUTES;
        segment.preferred_buffer_before_minutes = PREFERRED_COMBI_BUFFER_MINUTES;
        segment.minimum_buffer_before_minutes = MIN_COMBI_BUFFER_MINUTES;
      }
      preparedSegments.push(segment);
      if (Number.isFinite(segment.endMinutes)) {
        postCursor = segment.endMinutes;
      }
    });
  }

  unresolvedPre.forEach((item) => {
    preparedSegments.push(
      buildResolvedSegment({
        payload: { groupId, aggregateId },
        sourceItem: { ...sourceItem, date: bookingDate },
        segmentId: `${groupId}:pre:${item.productId}:pending`,
        productId: item.productId,
        title: item.title,
        role: "pre",
        status: item.status,
        startMinutes: null,
        durationMinutes: item.durationMinutes,
        warnings: [...item.warnings, ...(mainConfirmed ? [] : ["main_activity_needs_choice"])],
        errors: item.errors,
        timing: item.timing,
        vendorId: item.vendorId,
        location: item.location,
        isLocked: item.isLocked,
      })
    );
  });

  unresolvedTiming.forEach((item) => {
    preparedSegments.push(
      buildResolvedSegment({
        payload: { groupId, aggregateId },
        sourceItem: { ...sourceItem, date: bookingDate },
        segmentId: `${groupId}:segment:${item.productId}:pending`,
        productId: item.productId,
        title: item.title,
        role: item.role || null,
        status: item.status,
        startMinutes: null,
        durationMinutes: item.durationMinutes,
        warnings: item.warnings,
        errors: [...item.errors, "segment_timing_missing"],
        timing: item.timing,
        vendorId: item.vendorId,
        location: item.location,
        isLocked: item.isLocked,
      })
    );
  });

  unresolvedPost.forEach((item) => {
    preparedSegments.push(
      buildResolvedSegment({
        payload: { groupId, aggregateId },
        sourceItem: { ...sourceItem, date: bookingDate },
        segmentId: `${groupId}:post:${item.productId}:pending`,
        productId: item.productId,
        title: item.title,
        role: "post",
        status: item.status,
        startMinutes: null,
        durationMinutes: item.durationMinutes,
        warnings: item.warnings,
        errors: item.errors,
        timing: item.timing,
        vendorId: item.vendorId,
        location: item.location,
        isLocked: item.isLocked,
      })
    );
  });

  const confirmedSegments = preparedSegments.filter(
    (segment) => segment.status === "confirmed" && Number.isFinite(segment.startMinutes) && Number.isFinite(segment.endMinutes)
  );
  const requiresConfirmation =
    errors.length > 0 ||
    !mainConfirmed ||
    unresolvedPre.length > 0 ||
    unresolvedPost.length > 0 ||
    unresolvedTiming.length > 0;

  let status = "valid";
  if (errors.length > 0 || !mainConfirmed) {
    status = "invalid";
  } else if (requiresConfirmation) {
    status = "partial";
  }

  const bookingResolution = {
    session_id:
      cleanText(sourceItem.sessionId ?? sourceItem.session_id ?? sourceItem.plannerKey) || groupId,
    source_product_id: sourceProductId,
    source_title: sourceTitle,
    source_type: sourceType,
    participants,
    booking_date: bookingDate || null,
    currency,
    pricing: sourceItem.pricing && typeof sourceItem.pricing === "object" ? { ...sourceItem.pricing } : undefined,
    warnings: Array.from(new Set(warnings)),
    errors: Array.from(new Set(errors)),
    status: BOOKING_STATUSES.has(status) ? status : "invalid",
    requires_confirmation: requiresConfirmation,
    groupId,
    aggregateId,
    segments: preparedSegments,
    confirmedSegments,
  };

  bookingResolution.summary = buildAggregateSnapshot(bookingResolution, confirmedSegments);

  return bookingResolution;
}

export function materializeResolvedBookingPayload(resolution, mainItem = {}) {
  const payload = resolution && typeof resolution === "object"
    ? resolution
    : buildResolvedBookingPayload(mainItem, mainItem?.combiItems ?? mainItem?.options?.combiItems ?? []);

  const sourceItem = mainItem && typeof mainItem === "object" ? mainItem : {};
  const derivedCombiItems = Array.isArray(payload.segments)
    ? payload.segments
        .filter((segment) => segment && segment.role !== "anchor" && Number.isFinite(segment.product_id))
        .map((segment, index) => ({
          id: segment.product_id,
          productId: segment.product_id,
          label: segment.title || "",
          title: segment.title || "",
          name: segment.title || "",
          timing: segment.timing || (segment.role === "post" ? "after" : "before"),
          role: segment.role || (segment.timing === "after" ? "post" : "pre"),
          order: Number.isFinite(segment.order) ? segment.order : index,
          durationMinutes: Number.isFinite(segment.duration_minutes) ? segment.duration_minutes : null,
          vendorId: segment.vendor_id ?? null,
          location: segment.location ?? "",
          warnings: Array.isArray(segment.warnings) ? segment.warnings : [],
          errors: Array.isArray(segment.errors) ? segment.errors : [],
          isLocked: Boolean(segment.is_locked),
        }))
    : [];
  const sharedCombiItems = Array.isArray(sourceItem?.options?.combiItems) && sourceItem.options.combiItems.length > 0
    ? sourceItem.options.combiItems
    : Array.isArray(sourceItem?.combiItems) && sourceItem.combiItems.length > 0
    ? sourceItem.combiItems
    : derivedCombiItems;
  const arrangementMode =
    cleanText(payload.source_type) === "product-combi" ||
    sharedCombiItems.length > 0 ||
    (Array.isArray(payload.segments) &&
      payload.segments.some((segment) => segment && segment.role && segment.role !== "anchor"));
  const baseItem = {
    ...sourceItem,
    groupId: payload.groupId,
    aggregateId: payload.aggregateId,
    bookingResolution: payload,
    options: {
      ...(sourceItem.options && typeof sourceItem.options === "object" ? sourceItem.options : {}),
      combiItems: sharedCombiItems,
    },
    combiItems: sharedCombiItems,
    isArrangement: arrangementMode,
  };

  const renderableSegments = Array.isArray(payload.segments)
    ? payload.segments.filter(
        (segment) =>
          segment &&
          segment.status === "confirmed" &&
          Number.isFinite(segment.startMinutes) &&
          Number.isFinite(segment.endMinutes)
      )
    : [];

  if (renderableSegments.length === 0) {
    const firstSegment = Array.isArray(payload.segments) ? payload.segments[0] : null;
    return [
      {
        ...baseItem,
        id: `${payload.groupId || baseItem.id || "booking"}-pending`,
        type: arrangementMode ? "arrangement" : "single",
        role: arrangementMode ? "anchor" : undefined,
        groupId: arrangementMode ? payload.groupId : undefined,
        aggregateId: arrangementMode ? payload.aggregateId : undefined,
        status: payload.status === "invalid" ? "error" : "needs_choice",
        warnings: Array.from(new Set([...(payload.warnings || []), ...(firstSegment?.warnings || [])])),
        errors: Array.from(new Set([...(payload.errors || []), ...(firstSegment?.errors || [])])),
        segments: payload.segments,
        combiItems: arrangementMode ? sharedCombiItems : [],
        aggregate: {
          ...(sourceItem.aggregate && typeof sourceItem.aggregate === "object" ? sourceItem.aggregate : {}),
          status: payload.status,
          segments: payload.segments,
        },
      },
    ];
  }

  const confirmedSegments = renderableSegments;
  const first = confirmedSegments[0];
  const last = confirmedSegments[confirmedSegments.length - 1];
  const aggregate = {
    ...(sourceItem.aggregate && typeof sourceItem.aggregate === "object" ? sourceItem.aggregate : {}),
    timeline: {
      start: first.start,
      end: last.end,
      startTime: first.startTime,
      endTime: last.endTime,
      durationMinutes: last.endMinutes - first.startMinutes,
    },
    status: payload.status,
    segments: payload.segments,
  };

  return payload.segments.map((segment) => {
    const isConfirmed = segment.status === "confirmed" && Number.isFinite(segment.startMinutes) && Number.isFinite(segment.endMinutes);
    const item = {
      ...baseItem,
      id: segment.segment_id || `${payload.groupId}-${segment.role || "segment"}-${segment.product_id}`,
      productId: segment.product_id,
      product_id: segment.product_id,
      title: segment.title || sourceItem.title || sourceItem.name || "",
      type: arrangementMode ? (segment.role === "anchor" ? "arrangement" : "arrangement-part") : "single",
      role: arrangementMode ? (segment.role || "anchor") : undefined,
      groupId: arrangementMode ? payload.groupId : undefined,
      aggregateId: arrangementMode ? payload.aggregateId : undefined,
      status: isConfirmed ? "confirmed" : segment.status,
      warnings: Array.from(new Set([...(payload.warnings || []), ...(segment.warnings || [])])),
      errors: Array.from(new Set([...(payload.errors || []), ...(segment.errors || [])])),
      bookingResolution: payload,
      segments: payload.segments,
      aggregate,
      isArrangement: arrangementMode,
      source: cleanText(sourceItem.source) || payload.source_type || "day-planner",
      options: {
        ...(sourceItem.options && typeof sourceItem.options === "object" ? sourceItem.options : {}),
        combiItems: sharedCombiItems,
      },
      combiItems: arrangementMode && segment.role === "anchor" ? sharedCombiItems : [],
    };

    if (isConfirmed) {
      item.startMinutes = segment.startMinutes;
      item.endMinutes = segment.endMinutes;
      item.startTime = segment.startTime;
      item.endTime = segment.endTime;
      item.durationMinutes = segment.duration_minutes;
      item.start = segment.start;
      item.end = segment.end;
    } else {
      delete item.startMinutes;
      delete item.endMinutes;
      delete item.startTime;
      delete item.endTime;
      delete item.durationMinutes;
      delete item.start;
      delete item.end;
    }

    return item;
  });
}

export function generateArrangementItemsPayload(mainItem, combiItems = []) {
  return materializeResolvedBookingPayload(
    buildResolvedBookingPayload(mainItem, combiItems),
    mainItem
  );
}
