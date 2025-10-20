import React, { useMemo, useState } from "react";
import PropTypes from "prop-types";

const ACTIVITY_MIME = "application/x-sbdp-activity";
const SLOT_MIME = "application/x-sbdp-slot";
const DROP_HIGHLIGHT_CLASS = "sbdp-dropzone--active";

const DAY_START_HOUR = 9;
const DAY_END_HOUR = 23;
const TIME_STEP_MINUTES = 60;

const UNASSIGNED_TIME_KEY = "unassigned";

const DROP_KEY_PREFIX = {
  day: "day-",
  time: "time-",
  slot: "slot-",
};

const formatDate = (value) => {
  if (!value) {
    return "";
  }

  try {
    return new Date(value).toLocaleDateString("nl-NL", {
      weekday: "long",
      year: "numeric",
      month: "long",
      day: "numeric",
    });
  } catch (error) {
    return value;
  }
};

const pad = (value) => String(value).padStart(2, "0");

const normaliseTimeValue = (value) => {
  if (!value) {
    return "";
  }

  if (typeof value === "string") {
    const match = value.match(/(\d{2}):(\d{2})/);
    if (match) {
      return `${match[1]}:${match[2]}`;
    }
  }

  const date = new Date(value);
  if (!Number.isNaN(date.getTime())) {
    return `${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  return "";
};

const timeStringToMinutes = (value) => {
  const normalised = normaliseTimeValue(value);
  if (!normalised) {
    return 0;
  }

  const parts = normalised.split(":").map((part) => Number.parseInt(part, 10));
  if (parts.length !== 2 || parts.some((part) => Number.isNaN(part))) {
    return 0;
  }

  const [hours, minutes] = parts;
  return hours * 60 + minutes;
};

const minutesToTimeString = (minutes) => {
  const safeMinutes = Math.max(0, Math.min(24 * 60, minutes));
  const hours = Math.floor(safeMinutes / 60) % 24;
  const remainder = safeMinutes % 60;
  return `${pad(hours)}:${pad(remainder)}`;
};

const calculateSlotDurationMinutes = (slot) => {
  if (!slot) {
    return TIME_STEP_MINUTES;
  }

  const start = timeStringToMinutes(slot.start);
  const end = timeStringToMinutes(slot.end);
  if (end <= start) {
    return TIME_STEP_MINUTES;
  }

  return end - start;
};

const buildTimeGrid = (
  startHour = DAY_START_HOUR,
  endHour = DAY_END_HOUR,
  stepMinutes = TIME_STEP_MINUTES
) => {
  const grid = [];
  for (let minutes = startHour * 60; minutes < endHour * 60; minutes += stepMinutes) {
    const value = minutesToTimeString(minutes);
    grid.push({ value, label: value });
  }

  return grid;
};

const formatTime = (value) => {
  const normalised = normaliseTimeValue(value);
  return normalised || "";
};

const resolveParticipants = (slot) => {
  if (!slot) {
    return 1;
  }

  const candidates = [
    slot.people,
    slot.participants,
    slot.activity && slot.activity.people,
    slot.activity && slot.activity.default_people,
  ];

  for (let index = 0; index < candidates.length; index += 1) {
    const candidate = candidates[index];
    const parsed = Number.parseInt(candidate, 10);
    if (!Number.isNaN(parsed) && parsed > 0) {
      return parsed;
    }
  }

  return 1;
};

const sumParticipants = (slots) => {
  if (!Array.isArray(slots) || slots.length === 0) {
    return 0;
  }

  return slots.reduce((total, slot) => total + resolveParticipants(slot), 0);
};

const formatParticipantsCount = (count) => {
  if (!count || Number.isNaN(count)) {
    return "0 personen";
  }

  return count === 1 ? "1 persoon" : `${count} personen`;
};

const readTransferData = (event, type) => {
  try {
    const payload = event.dataTransfer.getData(type);
    if (!payload) {
      return null;
    }

    return JSON.parse(payload);
  } catch (error) {
    return null;
  }
};

const resolveDropStart = (timeValue) => {
  if (!timeValue || timeValue === UNASSIGNED_TIME_KEY) {
    return null;
  }

  const normalised = normaliseTimeValue(timeValue);
  return normalised || null;
};

const ensureActivityDuration = (activity) => {
  const rawDuration =
    (activity && (activity.duration_minutes || activity.duration)) || TIME_STEP_MINUTES;
  const parsed = Number.parseInt(rawDuration, 10);
  return Number.isNaN(parsed) ? TIME_STEP_MINUTES : parsed;
};

const createEnrichedSlots = (slots) =>
  slots.map((slot, slotIndex) => {
    const startNormalised = normaliseTimeValue(slot.start);
    return {
      slot,
      slotIndex,
      startNormalised,
      participantsValue: resolveParticipants(slot),
      durationMinutes: calculateSlotDurationMinutes(slot),
    };
  });

const groupSlotsByStartTime = (enrichedSlots) =>
  enrichedSlots.reduce((map, item) => {
    const timeKey = item.startNormalised || UNASSIGNED_TIME_KEY;
    if (!map.has(timeKey)) {
      map.set(timeKey, []);
    }

    map.get(timeKey).push(item);
    return map;
  }, new Map());

const getDayDropKey = (dayIndex) => `${DROP_KEY_PREFIX.day}${dayIndex}`;

const getTimeDropKey = (dayIndex, timeValue) =>
  `${DROP_KEY_PREFIX.time}${dayIndex}-${timeValue || UNASSIGNED_TIME_KEY}`;

const getSlotDropKey = (dayIndex, slotIndex) =>
  `${DROP_KEY_PREFIX.slot}${dayIndex}-${slotIndex}`;

function CalendarBoard({ days, onAddActivity, onMoveSlot, onUpdateSlot, onRemoveSlot }) {
  const timeGrid = useMemo(() => buildTimeGrid(), []);
  const timeRows = useMemo(
    () => [...timeGrid, { value: UNASSIGNED_TIME_KEY, label: "Zonder tijd" }],
    [timeGrid]
  );
  const [activeDropKey, setActiveDropKey] = useState(null);

  if (!Array.isArray(days) || days.length === 0) {
    return (
      <section className="sbdp-day-planner__calendar">
        <h3>Dagindeling</h3>
        <p>Voeg activiteiten toe om een planning op te bouwen.</p>
      </section>
    );
  }

  const getDropZoneClassName = (baseClass, key, activeClass) => {
    if (activeDropKey !== key) {
      return baseClass;
    }

    if (activeClass) {
      return `${baseClass} ${activeClass} ${DROP_HIGHLIGHT_CLASS}`;
    }

    return `${baseClass} ${DROP_HIGHLIGHT_CLASS}`;
  };

  const handleDragOver = (event, key) => {
    event.preventDefault();
    event.dataTransfer.dropEffect = "move";
    setActiveDropKey(key);
  };

  const clearDropHighlight = () => {
    setActiveDropKey(null);
  };

  const handleDropOnDay = (event, dayIndex) => {
    event.preventDefault();
    clearDropHighlight();

    const slotPayload = readTransferData(event, SLOT_MIME);
    if (slotPayload && onMoveSlot) {
      const { dayIndex: fromDayIndex, slotIndex } = slotPayload;
      onMoveSlot({
        fromDayIndex,
        slotIndex,
        toDayIndex: dayIndex,
      });

      return;
    }

    const activityPayload = readTransferData(event, ACTIVITY_MIME);
    if (activityPayload && onAddActivity) {
      onAddActivity(dayIndex, activityPayload);
    }
  };

  const handleDropOnSlot = (event, dayIndex, slotIndex, timeValue) => {
    event.preventDefault();
    clearDropHighlight();

    const newStart = resolveDropStart(timeValue);

    const slotPayload = readTransferData(event, SLOT_MIME);
    if (slotPayload && onMoveSlot) {
      const { dayIndex: fromDayIndex, slotIndex: sourceIndex } = slotPayload;
      onMoveSlot({
        fromDayIndex,
        slotIndex: sourceIndex,
        toDayIndex: dayIndex,
        insertIndex: slotIndex,
        newStart,
      });

      return;
    }

    const activityPayload = readTransferData(event, ACTIVITY_MIME);
    if (activityPayload && onAddActivity) {
      const durationMinutes = ensureActivityDuration(activityPayload);

      onAddActivity(dayIndex, activityPayload, slotIndex, {
        start: newStart,
        durationMinutes,
      });
    }
  };

  const handleDropOnTimeSlot = (event, dayIndex, timeValue, insertIndex) => {
    event.preventDefault();
    clearDropHighlight();

    const newStart = resolveDropStart(timeValue);

    const slotPayload = readTransferData(event, SLOT_MIME);
    if (slotPayload && onMoveSlot) {
      const { dayIndex: fromDayIndex, slotIndex } = slotPayload;
      onMoveSlot({
        fromDayIndex,
        slotIndex,
        toDayIndex: dayIndex,
        insertIndex,
        newStart,
      });

      return;
    }

    const activityPayload = readTransferData(event, ACTIVITY_MIME);
    if (activityPayload && onAddActivity) {
      const durationMinutes = ensureActivityDuration(activityPayload);

      onAddActivity(dayIndex, activityPayload, insertIndex, {
        start: newStart,
        durationMinutes,
      });
    }
  };

  const handleSlotDragStart = (event, dayIndex, slotIndex) => {
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData(SLOT_MIME, JSON.stringify({ dayIndex, slotIndex }));
    event.dataTransfer.setData("text/plain", `${dayIndex}:${slotIndex}`);
  };

  const handleTimeChange = (dayIndex, slotIndex, field) => (event) => {
    const value = event.target.value;
    if (onUpdateSlot) {
      onUpdateSlot(dayIndex, slotIndex, { [field]: value });
    }
  };

  const handleParticipantsChange = (dayIndex, slotIndex) => (event) => {
    const nextValue = Number.parseInt(event.target.value, 10);
    const normalised = Number.isNaN(nextValue) || nextValue < 1 ? 1 : nextValue;
    if (onUpdateSlot) {
      onUpdateSlot(dayIndex, slotIndex, { people: normalised });
    }
  };

  const handleRemoveSlot = (dayIndex, slotIndex) => {
    if (onRemoveSlot) {
      onRemoveSlot(dayIndex, slotIndex);
    }
  };

  return (
    <section className="sbdp-day-planner__calendar">
      <h3>Dagindeling</h3>
      <ul className="sbdp-day-planner__calendar-days">
        {days.map((day, dayIndex) => {
          const dayDropKey = getDayDropKey(dayIndex);
          const slots = Array.isArray(day.slots) ? day.slots : [];
          const totalParticipantsCount = sumParticipants(slots);
          const activitiesLabel = slots.length === 1 ? "1 activiteit" : `${slots.length} activiteiten`;

          const enrichedSlots = createEnrichedSlots(slots);
          const slotsByTime = groupSlotsByStartTime(enrichedSlots);

          return (
            <li
              key={day.date || dayIndex}
              className={getDropZoneClassName(
                "sbdp-day-planner__calendar-day",
                dayDropKey,
                "sbdp-day-planner__calendar-day--active"
              )}
              onDragOver={(event) => handleDragOver(event, dayDropKey)}
              onDragLeave={clearDropHighlight}
              onDrop={(event) => handleDropOnDay(event, dayIndex)}
            >
              <header className="sbdp-day-planner__calendar-day-header">
                <strong>{formatDate(day.date)}</strong>
                <div className="sbdp-day-planner__calendar-day-meta">
                  <span>{activitiesLabel}</span>
                  <span>{formatParticipantsCount(totalParticipantsCount)}</span>
                </div>
              </header>

              <div className="sbdp-day-planner__calendar-grid">
                {timeRows.map((timeSlot) => {
                  const rowItems = (slotsByTime.get(timeSlot.value) || []).slice();
                  rowItems.sort((a, b) => a.slotIndex - b.slotIndex);

                  const rowKey = getTimeDropKey(dayIndex, timeSlot.value);
                  const rowClassName = getDropZoneClassName(
                    "sbdp-day-planner__calendar-row",
                    rowKey,
                    "sbdp-day-planner__calendar-row--active"
                  );

                  const insertIndex =
                    rowItems.length > 0
                      ? Math.min(slots.length, rowItems[rowItems.length - 1].slotIndex + 1)
                      : slots.length;

                  return (
                    <div
                      key={rowKey}
                      className={rowClassName}
                      onDragOver={(event) => handleDragOver(event, rowKey)}
                      onDragLeave={clearDropHighlight}
                      onDrop={(event) => handleDropOnTimeSlot(event, dayIndex, timeSlot.value, insertIndex)}
                    >
                      <div className="sbdp-day-planner__calendar-row-time">{timeSlot.label}</div>
                      <div className="sbdp-day-planner__calendar-row-content">
                        {rowItems.length > 0 ? (
                          rowItems.map((item) => {
                            const slotDropKey = getSlotDropKey(dayIndex, item.slotIndex);
                            const slotClassName = getDropZoneClassName(
                              "sbdp-day-planner__calendar-slot",
                              slotDropKey,
                              "sbdp-day-planner__calendar-slot--active"
                            );

                            return (
                              <div
                                key={item.slot.id || slotDropKey}
                                className={slotClassName}
                                draggable
                                onDragStart={(event) => handleSlotDragStart(event, dayIndex, item.slotIndex)}
                                onDragOver={(event) => handleDragOver(event, slotDropKey)}
                                onDragLeave={clearDropHighlight}
                                onDrop={(event) =>
                                  handleDropOnSlot(event, dayIndex, item.slotIndex, timeSlot.value)
                                }
                              >
                                <div className="sbdp-day-planner__calendar-slot-times">
                                  <input
                                    type="time"
                                    value={formatTime(item.slot.start)}
                                    onChange={handleTimeChange(dayIndex, item.slotIndex, "start")}
                                    aria-label="Starttijd"
                                  />
                                  <span>-</span>
                                  <input
                                    type="time"
                                    value={formatTime(item.slot.end)}
                                    onChange={handleTimeChange(dayIndex, item.slotIndex, "end")}
                                    aria-label="Eindtijd"
                                  />
                                </div>
                                <div className="sbdp-day-planner__calendar-slot-content">
                                  <span className="sbdp-day-planner__calendar-slot-label">
                                    {item.slot.title ||
                                      item.slot.activity?.title ||
                                      item.slot.activity ||
                                      "Activiteit"}
                                  </span>
                                  <div className="sbdp-day-planner__calendar-slot-meta">
                                    <label className="sbdp-day-planner__calendar-slot-participants">
                                      <span>Deelnemers</span>
                                      <input
                                        type="number"
                                        min="1"
                                        value={item.participantsValue}
                                        onChange={handleParticipantsChange(dayIndex, item.slotIndex)}
                                      />
                                    </label>
                                  </div>
                                </div>
                                <button
                                  type="button"
                                  className="button-link sbdp-day-planner__calendar-slot-remove"
                                  onClick={() => handleRemoveSlot(dayIndex, item.slotIndex)}
                                  aria-label="Verwijder activiteit"
                                >
                                  x
                                </button>
                              </div>
                            );
                          })
                        ) : (
                          <span className="sbdp-day-planner__calendar-row-placeholder">Vrij</span>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            </li>
          );
        })}
      </ul>
    </section>
  );
}

CalendarBoard.propTypes = {
  days: PropTypes.arrayOf(PropTypes.object).isRequired,
  onAddActivity: PropTypes.func.isRequired,
  onMoveSlot: PropTypes.func.isRequired,
  onUpdateSlot: PropTypes.func.isRequired,
  onRemoveSlot: PropTypes.func.isRequired,
};

export default CalendarBoard;
