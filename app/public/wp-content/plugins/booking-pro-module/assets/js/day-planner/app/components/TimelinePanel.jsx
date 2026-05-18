import React, { useCallback, useMemo, useRef } from "react";
import PropTypes from "prop-types";

import { minutesToTime, timeToMinutes } from "../utils/time.js";
import { buildPlannerInsights } from "../utils/planner-engine.js";
import { buildProgramTimeline, formatDurationLabel, formatTimeRange } from "../utils/program.js";
import { buildTimelineDayLayout } from "../utils/timeline-layout.js";
import ActivitySwitcher from "./ActivitySwitcher.jsx";

const PIXELS_PER_MINUTE = 1.5;
const DEFAULT_DAY_START = 9 * 60; // 09:00
const DEFAULT_DAY_END = 23 * 60; // 23:00

export default function TimelinePanel({
  plan,
  products,
  slotOptions,
  openHours,
  updateActivity,
  removeActivity,
  showToast,
  onDropProduct,
  alternatives,
  onSwitchAlternative,
}) {
  const slots = useMemo(
    () =>
      (slotOptions || []).map((option) => ({
        ...option,
        minutes: timeToMinutes(option.value),
      })),
    [slotOptions]
  );

  const window = useMemo(
    () => resolveWindow(slots, plan.items, openHours),
    [slots, plan.items, openHours]
  );

  const hourMarkers = useMemo(
    () => buildHourMarkers(window.startMinutes, window.endMinutes),
    [window.startMinutes, window.endMinutes]
  );

  const dayLayouts = useMemo(
    () =>
      plan.days.map((day, dayIndex) => {
        const itemsForDay = plan.items.filter((item) => item.dayIndex === dayIndex);
        const layout = buildTimelineDayLayout(itemsForDay, window, {
          pixelsPerMinute: PIXELS_PER_MINUTE,
          source: "TimelinePanel.buildDayLayout",
        });
        return { day, dayIndex, layout, items: itemsForDay };
      }),
    [plan.days, plan.items, window]
  );

  const insights = useMemo(
    () =>
      buildPlannerInsights({
        plan,
        products,
        config: { open_hours: openHours },
      }),
    [plan, products, openHours]
  );
  const gapMarkersMap = useMemo(
    () => buildGapMarkers(dayLayouts, window),
    [dayLayouts, window.startMinutes, window.endMinutes, window.totalMinutes]
  );
  const insightDayMap = useMemo(
    () => new Map((insights.days || []).map((entry) => [entry.dayIndex, entry])),
    [insights.days]
  );

  const handleAddSuggestedProduct = useCallback(
    (suggestion) => {
      if (!suggestion?.productId || !suggestion?.startTime) {
        return;
      }

      const added = onDropProduct({
        productId: suggestion.productId,
        dayIndex: suggestion.dayIndex,
        startTime: suggestion.startTime,
      });

      if (added) {
        showToast(`${suggestion.title} toegevoegd om ${suggestion.startTime}.`);
      }
    },
    [onDropProduct, showToast]
  );

  if (!plan.days.length) {
    return null;
  }

  return (
    <section className="sbdp-calendar-board">
      <div className="sbdp-calendar-grid">
        <TimeAxis markers={hourMarkers} totalMinutes={window.totalMinutes} />
        <div className="sbdp-calendar-days">{dayLayouts.map(({ day, dayIndex, layout, items }) => (
            <CalendarDay
              key={day.date}
              day={day}
              dayIndex={dayIndex}
              layout={layout}
              items={items}
              products={products}
              markers={hourMarkers}
              window={window}
              slots={slots}
              updateActivity={updateActivity}
              removeActivity={removeActivity}
              showToast={showToast}
              onDropProduct={onDropProduct}
              gapMarkers={gapMarkersMap.get(dayIndex) ?? []}
              insightDay={insightDayMap.get(dayIndex) || null}
              onAddSuggestedProduct={handleAddSuggestedProduct}
              alternatives={alternatives}
              onSwitchAlternative={onSwitchAlternative}
            />
          ))}
        </div>
      </div>
    </section>
  );
}

TimelinePanel.propTypes = {
  plan: PropTypes.object.isRequired,
  products: PropTypes.array.isRequired,
  slotOptions: PropTypes.array.isRequired,
  openHours: PropTypes.object,
  updateActivity: PropTypes.func.isRequired,
  removeActivity: PropTypes.func.isRequired,
  showToast: PropTypes.func.isRequired,
  onDropProduct: PropTypes.func.isRequired,
  gapMarkers: PropTypes.array,
  alternatives: PropTypes.object,
  onSwitchAlternative: PropTypes.func,
};

CalendarDay.defaultProps = {
  gapMarkers: [],
};

TimelinePanel.defaultProps = {
  openHours: null,
};

function CalendarDay({
  day,
  dayIndex,
  layout,
  items,
  products,
  markers,
  window,
  slots,
  updateActivity,
  removeActivity,
  showToast,
  onDropProduct,
  gapMarkers,
  insightDay,
  onAddSuggestedProduct,
  alternatives,
  onSwitchAlternative,
}) {
  const canvasRef = useRef(null);
  const totalHeight = window.totalMinutes * PIXELS_PER_MINUTE;
  const visualEntries = useMemo(() => buildVisualEntries(layout), [layout]);
  const visualGapMarkers = useMemo(
    () => buildGapMarkers([{ dayIndex, layout: visualEntries }], window).get(dayIndex) ?? [],
    [dayIndex, visualEntries, window]
  );
  const conflictIds = useMemo(
    () => new Set(insightDay?.conflictItemIds || []),
    [insightDay?.conflictItemIds]
  );
  const resolutionIssues = useMemo(() => buildResolutionNotices(items), [items]);

  const handleDragOver = useCallback((event) => {
    event.preventDefault();
  }, []);

  const handleDrop = useCallback(
    (event) => {
      if (!canvasRef.current) {
        return;
      }

      event.preventDefault();

      const canvasRect = canvasRef.current.getBoundingClientRect();
      const offsetY = event.clientY - canvasRect.top;
      
      // Calculate exact minutes from mouse position
      const minutesFromStart = Math.max(
        0,
        Math.min(window.totalMinutes, offsetY / PIXELS_PER_MINUTE)
      );
      const absoluteMinutes = Math.round(window.startMinutes + minutesFromStart);
      
      // Round to nearest 15-minute interval for smooth snapping
      const step = 15;
      const snappedMinutes = Math.round(absoluteMinutes / step) * step;
      const startTime = minutesToTime(snappedMinutes);

      const itemPayload = event.dataTransfer?.getData("application/x-sbdp-item");
      if (itemPayload) {
        try {
          const parsed = JSON.parse(itemPayload);
          if (parsed?.itemId) {
            updateActivity(parsed.itemId, { startTime });
            return;
          }
        } catch (error) {
          showToast("Kon activiteit niet verplaatsen.");
          return;
        }
      }

      const productPayload =
        event.dataTransfer?.getData("application/x-sbdp-product") ||
        event.dataTransfer?.getData("text/plain") ||
        "";

      let productId = parseInt(productPayload, 10);
      if (!Number.isFinite(productId)) {
        try {
          const parsed = JSON.parse(productPayload);
          productId = parseInt(parsed?.productId, 10);
        } catch (error) {
          productId = NaN;
        }
      }

      if (!Number.isFinite(productId) || productId <= 0) {
        showToast("Kon activiteit niet herkennen.");
        return;
      }

      onDropProduct({
        productId,
        dayIndex,
        startTime,
      });
    },
    [canvasRef, dayIndex, onDropProduct, showToast, updateActivity, window]
  );

  const handleTimeNudge = useCallback(
    (item, deltaMinutes, scope = "program") => {
      if (!deltaMinutes || item.locked) {
        return;
      }

      const duration = item.endMinutes - item.startMinutes;
      const proposedStart = item.startMinutes + deltaMinutes;
      const earliest = window.startMinutes;
      const latest = window.endMinutes - duration;

      if (proposedStart < earliest || proposedStart > latest) {
        showToast("Tijd valt buiten de planning.");
        return;
      }

      updateActivity(item.id, { startTime: minutesToTime(proposedStart), scope });
    },
    [showToast, updateActivity, window.endMinutes, window.startMinutes]
  );

  const handleParticipantStep = useCallback(
    (item, product, delta) => {
      if (!delta || item.locked) {
        return;
      }

      const range = resolveParticipantRange(product);
      const nextValue = Math.min(
        range.max,
        Math.max(range.min, item.participants + delta)
      );

      if (nextValue === item.participants) {
        showToast(delta > 0 ? "Maximaal aantal deelnemers bereikt." : "Minimaal aantal deelnemers bereikt.");
        return;
      }

      updateActivity(item.id, { participants: nextValue });
    },
    [showToast, updateActivity]
  );

  return (
    <div className="sbdp-calendar-day">
      <div
        ref={canvasRef}
        className="sbdp-calendar-day__canvas"
        style={{ height: `${totalHeight}px` }}
        onDragOver={handleDragOver}
        onDrop={handleDrop}
      >
        <div className="sbdp-calendar-day__grid">
          {markers.map((marker) => {
            const top = (marker.minutes - window.startMinutes) * PIXELS_PER_MINUTE;
            return (
              <div
                key={`marker-${marker.minutes}`}
                className="sbdp-calendar-day__gridline"
                style={{ top: `${top}px` }}
              >
                <span>{marker.label}</span>
              </div>
            );
          })}
        </div>

        <div className="sbdp-calendar-day__events">
          {resolutionIssues.length > 0 ? (
            <div className="sbdp-calendar-day__issues" aria-label="Onvolledige arrangementen">
              {resolutionIssues.map((issue) => (
              <article key={issue.id} className={`sbdp-calendar-event sbdp-calendar-event--issue sbdp-calendar-event--${issue.status}`}>
                  <header className="sbdp-calendar-event__header">
                    <div className="sbdp-calendar-event__headline">
                      <strong>{issue.status === "error" ? "Arrangement fout" : "Arrangement wacht op keuze"}</strong>
                      <div className="sbdp-calendar-event__subline">
                        <span className="sbdp-calendar-event__availability">
                          {issue.title}
                        </span>
                      </div>
                    </div>
                  </header>
                  {issue.errors.length > 0 || issue.warnings.length > 0 ? (
                    <div className="sbdp-calendar-event__segments">
                      {issue.errors.map((entry) => (
                        <div key={entry} className="sbdp-calendar-event__segment sbdp-calendar-event__segment--error">
                          <span className="sbdp-calendar-event__segment-role">Fout</span>
                          <strong>{entry}</strong>
                        </div>
                      ))}
                      {issue.warnings.map((entry) => (
                        <div key={entry} className="sbdp-calendar-event__segment sbdp-calendar-event__segment--warning">
                          <span className="sbdp-calendar-event__segment-role">Opmerking</span>
                          <strong>{entry}</strong>
                        </div>
                      ))}
                    </div>
                  ) : null}
                </article>
              ))}
            </div>
          ) : null}
          {visualEntries.map((entry) => {
            const primaryItem = entry.item;
            const segmentEntries = Array.isArray(entry.entries) ? entry.entries : [];
            const product = products.find((item) => item.id === primaryItem.productId);
            const isLocked = Boolean(primaryItem.locked);
            const participantRange = resolveParticipantRange(product);
            const isArrangementSegment = Boolean(entry.groupId || primaryItem.groupId || primaryItem.bookingResolution?.groupId);
            const isAnchorSegment = primaryItem.role === "anchor" || !primaryItem.role;
            if (isArrangementSegment && !isAnchorSegment) {
              return null;
            }
            const programEntries =
              segmentEntries.length > 0 ? segmentEntries : buildProgramEntriesFromResolvedSegments(primaryItem);
            const program =
              isArrangementSegment && programEntries.length > 1
                ? buildProgramTimeline(primaryItem, programEntries)
                : null;
            const displayStartTime = program?.startTime || entry.displayStartTime || primaryItem.startTime;
            const displayEndTime = program?.endTime || entry.displayEndTime || primaryItem.endTime;
            const slotStartMinutes = timeToMinutes(primaryItem.startTime);
            const slotKey = `day-${dayIndex}-slot-${slotStartMinutes}`;
            const slotAlternatives = alternatives?.bySlot?.[slotKey] || [];
            const currentAltIndex = alternatives?.currentIndex?.[slotKey] || 0;
            const hasAlternatives = Array.isArray(slotAlternatives) && slotAlternatives.length > 1;
            const hasSegmentRows = Boolean(program && Array.isArray(program.segments) && program.segments.length > 1);
            const fallbackSegmentRows =
              !hasSegmentRows && isArrangementSegment
                ? buildProgramEntriesFromResolvedSegments(primaryItem).map((entry) => entry.item)
                : [];
            const hasConflict = [primaryItem, ...segmentEntries].some((segment) => conflictIds.has(segment.id));
            const itemTypeLabel = isArrangementSegment
              ? `Combi · ${getArrangementRoleLabel(primaryItem)}`
              : "Los item";
            const titleLabel = program?.title || primaryItem.title;
            const segmentRows = program?.segments || [];
            const transitionRows = program?.transitions || [];
            const showCompactArrangementHeader = isArrangementSegment && hasSegmentRows;
            
            const style = {
              top: `${entry.top}px`,
              height: `${entry.height}px`,
              left: `${entry.left}%`,
              width: `${entry.width}%`,
              opacity: 1,
              borderLeft: isArrangementSegment ? '4px solid #E4B97F' : undefined,
              zIndex: isArrangementSegment ? (isAnchorSegment ? 3 : 2) : 1,
            };

            return (
              <article
                key={primaryItem.id}
                className={`sbdp-calendar-event ${isLocked ? "is-locked" : ""} ${hasConflict ? "is-conflict" : ""} ${isArrangementSegment ? "sbdp-calendar-event--arrangement" : ""}`.trim()}
                data-planner-entry-kind={isArrangementSegment ? "arrangement" : "single"}
                style={style}
                draggable={!isLocked}
                onDragStart={(event) => handleEventDragStart(event, primaryItem, isLocked)}
              >
                <header className="sbdp-calendar-event__header">
                  <div className="sbdp-calendar-event__headline">
                    <div className="sbdp-calendar-event__eyebrows">
                      <span className="sbdp-calendar-event__eyebrow">{itemTypeLabel}</span>
                      <span className="sbdp-calendar-event__eyebrow sbdp-calendar-event__eyebrow--soft">Programma</span>
                    </div>
                    {!showCompactArrangementHeader ? (
                      <>
                        <strong>{titleLabel}</strong>
                        <div className="sbdp-calendar-event__subline">
                          <span className="sbdp-calendar-event__time">
                            {formatTimeRange(timeToMinutes(displayStartTime), timeToMinutes(displayEndTime)) || `${displayStartTime} - ${displayEndTime}`}
                          </span>
                          <span className="sbdp-calendar-event__availability">
                            {program?.durationLabel || formatDurationLabel(primaryItem.endMinutes - primaryItem.startMinutes)}
                          </span>
                        </div>
                      </>
                    ) : null}
                  </div>
                </header>

                {hasSegmentRows ? (
                  <div className="sbdp-calendar-event__segments sbdp-calendar-event__segments--program">
                    {segmentRows.map((segment, index) => {
                      const segmentItem = segment.item || {};
                      const segmentId = segmentItem.id || segment.id || `${primaryItem.id}-${index}`;
                      const canMoveSegment = Boolean(segment.canMoveIndividually && !isLocked);
                      return (
                        <React.Fragment key={segmentId}>
                          <div className={`sbdp-calendar-event__segment sbdp-calendar-event__segment--${segment.fixedStatus}`}>
                            <div className="sbdp-calendar-event__segment-time">
                              <strong>{segment.startTime} - {segment.endTime}</strong>
                              <span>{segment.durationLabel}</span>
                            </div>
                            <div className="sbdp-calendar-event__segment-body">
                              <span className="sbdp-calendar-event__segment-role">{segment.typeLabel}</span>
                              <strong>{segment.title || segmentItem.title || primaryItem.title}</strong>
                              <span className="sbdp-calendar-event__segment-meta">
                                {segment.locationName ? `${segment.locationName} · ` : ""}
                                {segment.fixedStatusLabel}
                              </span>
                              {segment.notes ? (
                                <span className="sbdp-calendar-event__segment-note">{segment.notes}</span>
                              ) : null}
                            </div>
                            {canMoveSegment ? (
                              <div className="sbdp-calendar-event__segment-actions">
                                <button
                                  type="button"
                                  className="sbdp-calendar-event__ghost-btn"
                                  onClick={() => handleTimeNudge(segmentItem, -15, "segment")}
                                  disabled={isLocked}
                                  aria-label={`Schuif ${segment.title} eerder`}
                                >
                                  Eerder
                                </button>
                                <button
                                  type="button"
                                  className="sbdp-calendar-event__ghost-btn"
                                  onClick={() => handleTimeNudge(segmentItem, 15, "segment")}
                                  disabled={isLocked}
                                  aria-label={`Schuif ${segment.title} later`}
                                >
                                  Later
                                </button>
                              </div>
                            ) : null}
                          </div>
                          {transitionRows[index] ? (
                            <div className={`sbdp-calendar-event__transition sbdp-calendar-event__transition--${transitionRows[index].kind}`}>
                              <span>{transitionRows[index].label}</span>
                              <strong>{transitionRows[index].detail}</strong>
                            </div>
                          ) : null}
                        </React.Fragment>
                      );
                    })}
                  </div>
                ) : null}

                {!hasSegmentRows && fallbackSegmentRows.length > 1 ? (
                  <div className="sbdp-calendar-event__segments sbdp-calendar-event__segments--program">
                    {fallbackSegmentRows.map((segment, index) => {
                      const durationMinutes = Math.max(
                        0,
                        (segment?.endMinutes || 0) - (segment?.startMinutes || 0)
                      );
                      return (
                        <div
                          key={segment.id || `${primaryItem.id}-fallback-segment-${index}`}
                          className="sbdp-calendar-event__segment sbdp-calendar-event__segment--semi-flex"
                        >
                          <div className="sbdp-calendar-event__segment-time">
                            <strong>
                              {segment.startTime} - {segment.endTime}
                            </strong>
                            <span>{formatDurationLabel(durationMinutes)}</span>
                          </div>
                          <div className="sbdp-calendar-event__segment-body">
                            <span className="sbdp-calendar-event__segment-role">
                              {getArrangementRoleLabel(segment)}
                            </span>
                            <strong>{segment.title || primaryItem.title}</strong>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                ) : null}

                {(!isArrangementSegment || isAnchorSegment || !isLocked) && (
                  <div className="sbdp-calendar-event__controls" aria-label="Activiteit instellingen">
                    <div className="sbdp-calendar-event__inline-controls">
                      <button
                        type="button"
                        className="sbdp-calendar-event__ghost-btn"
                        onClick={() => handleTimeNudge(primaryItem, -15, "program")}
                        disabled={isLocked}
                        aria-label="15 minuten eerder"
                        title="15 minuten eerder"
                      >
                        Eerder
                      </button>
                      <button
                        type="button"
                        className="sbdp-calendar-event__ghost-btn"
                        onClick={() => handleTimeNudge(primaryItem, 15, "program")}
                        disabled={isLocked}
                        aria-label="15 minuten later"
                        title="15 minuten later"
                      >
                        Later
                      </button>
                    </div>

                  <div className="sbdp-calendar-event__participants-inline">
                    <button
                      type="button"
                      onClick={() => handleParticipantStep(primaryItem, product, -1)}
                      disabled={(isArrangementSegment && !isAnchorSegment) || isLocked || primaryItem.participants <= participantRange.min}
                      aria-label="Minder deelnemers"
                    >
                      −
                    </button>
                    <span aria-label="Aantal deelnemers">
                      {primaryItem.participants}
                    </span>
                    <button
                      type="button"
                      onClick={() => handleParticipantStep(primaryItem, product, 1)}
                      disabled={(isArrangementSegment && !isAnchorSegment) || isLocked || primaryItem.participants >= participantRange.max}
                      aria-label="Meer deelnemers"
                    >
                      +
                    </button>
                  </div>
                  <button
                    type="button"
                    className="sbdp-calendar-event__remove"
                    onClick={() => removeActivity(primaryItem.id)}
                    disabled={isLocked}
                    aria-label={`Verwijder ${primaryItem.title}`}
                  >
                    <span aria-hidden="true">&times;</span>
                  </button>
                </div>
                )}
                {((!primaryItem.role || primaryItem.role === 'anchor') && (product?.location || hasAlternatives)) ? (
                  <div className="sbdp-calendar-event__meta-row">
                    {product?.location ? (
                      <p className="sbdp-calendar-event__meta">{product.location}</p>
                    ) : <span />}
                    {hasAlternatives ? (
                      <div className="sbdp-calendar-event__switcher">
                        <ActivitySwitcher
                          activityId={primaryItem.id}
                          slotKey={slotKey}
                          alternatives={slotAlternatives}
                          currentIndex={currentAltIndex}
                          onSwitch={onSwitchAlternative}
                        />
                      </div>
                    ) : null}
                  </div>
                ) : null}
              </article>
            );
          })}
          {visualGapMarkers.map((marker) => (
            <div
              key={marker.id}
              className={`sbdp-calendar-gap sbdp-calendar-gap--${marker.type}`}
              style={{ top: `${marker.top}px`, height: `${marker.height}px` }}
            >
              <span>{marker.label}</span>
            </div>
          ))}
        </div>
      </div>

      {insightDay?.conflicts?.length > 0 || insightDay?.routeWarnings?.length > 0 || insightDay?.itemNotes?.length > 0 ? (
        <div className="sbdp-planner-day-notices">
          {insightDay.conflicts.slice(0, 2).map((entry) => (
            <article key={entry.id} className={`sbdp-planner-notice sbdp-planner-notice--${entry.tone}`} title={entry.title}>
              <strong>{entry.title}</strong>
              <div className="sbdp-planner-notice__detail">
                <p>{entry.message}</p>
                {entry.suggestion ? <span>{entry.suggestion}</span> : null}
              </div>
            </article>
          ))}
          {insightDay.routeWarnings.slice(0, 1).map((entry) => (
            <article key={entry.id} className="sbdp-planner-notice sbdp-planner-notice--route" title={entry.title}>
              <strong>{entry.title}</strong>
              <div className="sbdp-planner-notice__detail">
                <p>{entry.message}</p>
                <span>{entry.suggestion}</span>
              </div>
            </article>
          ))}
          {insightDay.itemNotes.slice(0, 1).map((entry) => (
            <article key={entry.id} className="sbdp-planner-notice sbdp-planner-notice--note" title={entry.title}>
              <strong>{entry.title}</strong>
              <div className="sbdp-planner-notice__detail">
                <p>{entry.message}</p>
              </div>
            </article>
          ))}
        </div>
      ) : null}

      {insightDay?.quickSuggestions?.length > 0 ? (
        <div className="sbdp-planner-suggestions" aria-label="Slimme suggesties">
          {insightDay.quickSuggestions.map((suggestion) => (
            <article key={suggestion.id} className="sbdp-planner-suggestion-card" title={suggestion.title}>
              <div className="sbdp-planner-suggestion-card__top">
                <span className="sbdp-planner-suggestion-card__badge">{suggestion.badge}</span>
                <span className="sbdp-planner-suggestion-card__time">
                  {suggestion.startTime}
                  {suggestion.endTime ? ` - ${suggestion.endTime}` : ""}
                </span>
              </div>
              <div className="sbdp-planner-suggestion-card__detail">
                <h5>{suggestion.title}</h5>
                <p>{suggestion.reason}</p>
                <footer className="sbdp-planner-suggestion-card__footer">
                  <span>
                    {suggestion.area}
                    {suggestion.priceLabel ? ` • ${suggestion.priceLabel}` : ""}
                  </span>
                  {suggestion.productId ? (
                    <button
                      type="button"
                      className="ui-btn ui-btn--secondary ui-btn--sm"
                      onClick={() => onAddSuggestedProduct(suggestion)}
                    >
                      {suggestion.ctaLabel || "Voeg toe"}
                    </button>
                  ) : null}
                </footer>
              </div>
            </article>
          ))}
        </div>
      ) : null}
    </div>
  );
}

CalendarDay.propTypes = {
  day: PropTypes.object.isRequired,
  dayIndex: PropTypes.number.isRequired,
  layout: PropTypes.array.isRequired,
  items: PropTypes.array.isRequired,
  products: PropTypes.array.isRequired,
  markers: PropTypes.array.isRequired,
  window: PropTypes.object.isRequired,
  slots: PropTypes.array.isRequired,
  updateActivity: PropTypes.func.isRequired,
  removeActivity: PropTypes.func.isRequired,
  showToast: PropTypes.func.isRequired,
  onDropProduct: PropTypes.func.isRequired,
  insightDay: PropTypes.object,
  onAddSuggestedProduct: PropTypes.func.isRequired,
};

function TimeAxis({ markers, totalMinutes }) {
  const height = totalMinutes * PIXELS_PER_MINUTE;
  return (
    <div className="sbdp-calendar-hours" style={{ height: `${height}px` }}>
      {markers.map((marker) => (
        <div
          key={`axis-${marker.minutes}`}
          className="sbdp-calendar-hours__marker"
          style={{ top: `${marker.offset}px` }}
        >
          {marker.label}
        </div>
      ))}
    </div>
  );
}

TimeAxis.propTypes = {
  markers: PropTypes.array.isRequired,
  totalMinutes: PropTypes.number.isRequired,
};

function handleEventDragStart(event, item, isLocked) {
  if (!event.dataTransfer || isLocked) {
    return;
  }
  const payload = JSON.stringify({ itemId: item.id });
  event.dataTransfer.effectAllowed = "move";
  event.dataTransfer.setData("application/x-sbdp-item", payload);
}

function resolveWindow(slots, items, openHours) {
  let startMinutes =
    typeof openHours?.start === "string" ? timeToMinutes(openHours.start) : DEFAULT_DAY_START;
  let endMinutes =
    typeof openHours?.end === "string" ? timeToMinutes(openHours.end) : DEFAULT_DAY_END;

  if (slots.length) {
    const slotStart = Math.min(...slots.map((slot) => slot.minutes));
    const slotEnd = Math.max(...slots.map((slot) => slot.minutes));
    if (Number.isFinite(slotStart)) {
      startMinutes = Math.min(startMinutes, slotStart);
    }
    if (Number.isFinite(slotEnd)) {
      endMinutes = Math.max(endMinutes, slotEnd + 60);
    }
  }

  if (items.length) {
    const minItem = Math.min(...items.map((item) => item.startMinutes));
    const maxItem = Math.max(...items.map((item) => item.endMinutes));
    if (Number.isFinite(minItem)) {
      startMinutes = Math.min(startMinutes, Math.floor(minItem / 30) * 30);
    }
    if (Number.isFinite(maxItem)) {
      endMinutes = Math.max(endMinutes, Math.ceil(maxItem / 30) * 30);
    }
  }

  if (!Number.isFinite(startMinutes)) {
    startMinutes = DEFAULT_DAY_START;
  }
  if (!Number.isFinite(endMinutes) || endMinutes <= startMinutes) {
    endMinutes = startMinutes + 10 * 60;
  }

  const totalMinutes = endMinutes - startMinutes;
  const label = `${minutesToTime(startMinutes)} tot ${minutesToTime(endMinutes)}`;

  return { startMinutes, endMinutes, totalMinutes, label };
}

function buildHourMarkers(startMinutes, endMinutes) {
  const markers = [];
  const startHour = Math.floor(startMinutes / 60);
  const endHour = Math.floor(endMinutes / 60);

  for (let hour = startHour; hour <= endHour; hour += 1) {
    const minutes = hour * 60;
    
    // Skip markers that would be positioned outside the canvas
    if (minutes > endMinutes) {
      continue;
    }
    
    const offset = (minutes - startMinutes) * PIXELS_PER_MINUTE;
    
    markers.push({
      minutes,
      label: `${String(hour).padStart(2, "0")}:00`,
      offset,
    });
  }

  return markers;
}

function buildGapMarkers(dayLayouts, window) {
  const map = new Map();

  dayLayouts.forEach(({ dayIndex, layout }) => {
    if (!Array.isArray(layout) || layout.length === 0) {
      map.set(dayIndex, []);
      return;
    }

    const sorted = [...layout].sort(
      (a, b) => a.item.startMinutes - b.item.startMinutes || a.item.endMinutes - b.item.endMinutes
    );

    const markers = [];

    for (let i = 0; i < sorted.length - 1; i += 1) {
      const current = sorted[i];
      const next = sorted[i + 1];
      const currentGroupId = current?.item?.groupId || current?.item?.bookingResolution?.groupId || null;
      const nextGroupId = next?.item?.groupId || next?.item?.bookingResolution?.groupId || null;

      if (currentGroupId && currentGroupId === nextGroupId) {
        continue;
      }

      const gapMinutes = next.item.startMinutes - current.item.endMinutes;
      const afterCurrentTop = current.top + current.height;
      const idBase = `${current.item.id}-gap-${next.item.id}`;

      if (gapMinutes > 5) {
        const rawHeight = next.top - afterCurrentTop;
        const height = Math.max(24, rawHeight > 0 ? rawHeight : 24);
        
        // Determine label based on gap duration
        let label;
        let type;
        if (gapMinutes >= 30) {
          label = `Vrije tijd ${formatGapDuration(gapMinutes)}`;
          type = "free";
        } else {
          label = `⚠️ Let op: ${formatGapDuration(gapMinutes)} reistijd - overweeg minimaal 30 min`;
          type = "warning";
        }
        
        markers.push({
          id: idBase,
          top: afterCurrentTop,
          height,
          label,
          type,
        });
      } else if (gapMinutes < 0) {
        const overlapMinutes = Math.abs(gapMinutes);
        const overlapTop = Math.max(next.top, afterCurrentTop - 24);
        const overlapBottom = Math.min(
          current.top + current.height,
          next.top + next.height,
          window.totalMinutes * PIXELS_PER_MINUTE
        );
        const height = Math.max(28, overlapBottom - overlapTop);
        markers.push({
          id: `${idBase}-conflict`,
          top: overlapTop,
          height,
          label: `Overlap ${formatGapDuration(overlapMinutes)}`,
          type: "conflict",
        });
      }
    }

    map.set(dayIndex, markers);
  });

  return map;
}

function buildVisualEntries(layout) {
  if (!Array.isArray(layout) || layout.length === 0) {
    return [];
  }

  const grouped = [];
  const arrangementMap = new Map();

  layout.forEach((entry) => {
    const groupId =
      typeof entry?.item?.groupId === "string" && entry.item.groupId.trim() !== ""
        ? entry.item.groupId.trim()
        : typeof entry?.item?.bookingResolution?.groupId === "string" &&
          entry.item.bookingResolution.groupId.trim() !== ""
        ? entry.item.bookingResolution.groupId.trim()
        : "";

    if (!groupId) {
      grouped.push({
        ...entry,
        entries: [entry],
        kind: "single",
        displayStartTime: entry?.item?.startTime || minutesToTime(entry?.item?.startMinutes || 0),
        displayEndTime: entry?.item?.endTime || minutesToTime(entry?.item?.endMinutes || 0),
      });
      return;
    }

    const existing = arrangementMap.get(groupId);
    if (!existing) {
      arrangementMap.set(groupId, {
        groupId,
        entries: [entry],
      });
      return;
    }

    existing.entries.push(entry);
  });

  arrangementMap.forEach((group) => {
    const entries = Array.isArray(group.entries) ? [...group.entries] : [];
    if (entries.length === 0) {
      return;
    }

    entries.sort((left, right) => {
      const leftStart = Number.isFinite(left?.item?.startMinutes) ? left.item.startMinutes : 0;
      const rightStart = Number.isFinite(right?.item?.startMinutes) ? right.item.startMinutes : 0;
      return leftStart - rightStart || left.top - right.top || left.left - right.left;
    });

    const primaryEntry =
      entries.find((candidate) => candidate?.item?.role === "anchor") ||
      entries[0];
    const top = Math.min(...entries.map((candidate) => candidate.top).filter(Number.isFinite));
    const bottom = Math.max(
      ...entries
        .map((candidate) =>
          Number.isFinite(candidate.top) && Number.isFinite(candidate.height)
            ? candidate.top + candidate.height
            : candidate.top
        )
        .filter(Number.isFinite)
    );
    const left = Number.isFinite(primaryEntry?.left)
      ? primaryEntry.left
      : Math.min(...entries.map((candidate) => candidate.left).filter(Number.isFinite));
    const width = Number.isFinite(primaryEntry?.width)
      ? primaryEntry.width
      : Math.max(...entries.map((candidate) => candidate.width).filter(Number.isFinite));
    const firstEntry = entries[0];
    const lastEntry = entries[entries.length - 1];

    grouped.push({
      ...primaryEntry,
      top: Number.isFinite(top) ? top : primaryEntry.top,
      height:
        Number.isFinite(top) && Number.isFinite(bottom) && bottom > top
          ? bottom - top
          : primaryEntry.height,
      left,
      width,
      entries,
      kind: "arrangement",
      displayStartTime:
        firstEntry?.item?.startTime || minutesToTime(firstEntry?.item?.startMinutes || 0),
      displayEndTime:
        lastEntry?.item?.endTime || minutesToTime(lastEntry?.item?.endMinutes || 0),
    });
  });

  return grouped
    .sort((left, right) => {
      const leftStart = Number.isFinite(left?.item?.startMinutes) ? left.item.startMinutes : 0;
      const rightStart = Number.isFinite(right?.item?.startMinutes) ? right.item.startMinutes : 0;
      return left.top - right.top || leftStart - rightStart || left.left - right.left;
    });
}

function buildResolutionNotices(items) {
  if (!Array.isArray(items) || items.length === 0) {
    return [];
  }

  return items
    .filter((item) => item?.bookingResolution && item.bookingResolution.status && item.bookingResolution.status !== "valid")
    .map((item) => ({
      id: `${item.id || item.productId || "item"}-resolution`,
      title: item.title || "Arrangement",
      status: item.bookingResolution?.status || item.status || "partial",
      warnings: Array.isArray(item.bookingResolution?.warnings) ? item.bookingResolution.warnings : [],
      errors: Array.isArray(item.bookingResolution?.errors) ? item.bookingResolution.errors : [],
    }));
}

function getArrangementRoleLabel(item) {
  const role = item?.role || item?.bookingResolution?.role || "";
  if (role === "pre") {
    return "Vooraf";
  }
  if (role === "post") {
    return "Achteraf";
  }
  if (role === "anchor" || role === "") {
    return "Hoofdactiviteit";
  }
  return "Programma";
}

function buildProgramEntriesFromResolvedSegments(primaryItem) {
  const resolvedSegments = Array.isArray(primaryItem?.segments)
    ? primaryItem.segments
    : Array.isArray(primaryItem?.bookingResolution?.segments)
    ? primaryItem.bookingResolution.segments
    : [];

  return resolvedSegments
    .filter((segment) => segment && typeof segment === "object")
    .map((segment, index) => ({
      item: {
        id:
          segment.segment_id ||
          segment.id ||
          `${primaryItem?.id || primaryItem?.productId || "arrangement"}-segment-${index}`,
        title: segment.title || primaryItem?.title || "Onderdeel",
        name: segment.title || primaryItem?.title || "Onderdeel",
        role: segment.role || (index === 0 ? "anchor" : ""),
        startMinutes: segment.startMinutes,
        endMinutes: segment.endMinutes,
        startTime: segment.startTime,
        endTime: segment.endTime,
        groupId: segment.groupId || primaryItem?.groupId,
        aggregateId: segment.aggregateId || primaryItem?.aggregateId,
        location_name: segment.location,
        location: segment.location,
        is_locked: Boolean(segment.is_locked),
        locked: Boolean(segment.is_locked),
      },
      kind: "arrangement",
    }))
    .filter((entry) => Number.isFinite(entry?.item?.startMinutes) && Number.isFinite(entry?.item?.endMinutes));
}

function resolveParticipantRange(product) {
  if (product?.people?.enabled) {
    const min = Math.max(1, product.people.min || 1);
    const max = Math.max(min, product.people.max || min);
    return { min, max };
  }

  return { min: 1, max: 999 };
}

function formatGapDuration(minutes) {
  const value = Math.max(0, Math.round(minutes));
  if (value >= 60) {
    const hours = Math.floor(value / 60);
    const remainder = value % 60;
    if (remainder === 0) {
      return `${hours} uur`;
    }
    return `${hours}u ${remainder}m`;
  }

  if (value === 0) {
    return "0m";
  }

  return `${value} min`;
}

function findClosestSlot(slots, minutes) {
  if (!slots.length) {
    return null;
  }

  let best = null;
  let bestDiff = Infinity;

  slots.forEach((slot) => {
    const diff = Math.abs(slot.minutes - minutes);
    if (diff < bestDiff) {
      best = slot;
      bestDiff = diff;
    }
  });

  return best;
}
