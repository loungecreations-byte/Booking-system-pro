import React from "react";
import PropTypes from "prop-types";

const HOURS = Array.from({ length: 15 }, (_, index) => index + 9);

const formatDate = (value) => {
  if (!value) {
    return "";
  }

  try {
    return new Date(value).toLocaleDateString("nl-NL", {
      weekday: "short",
      day: "numeric",
      month: "short",
    });
  } catch (error) {
    return value;
  }
};

const formatTime = (value) => {
  if (!value) {
    return "";
  }

  return value.length > 5 ? value.slice(0, 5) : value;
};

const formatHourLabel = (hour) => `${hour.toString().padStart(2, "0")}:00`;

const getSlotHour = (slot) => {
  if (!slot || !slot.start) {
    return null;
  }

  const start = slot.start;

  if (typeof start === "string" && start.indexOf("T") === -1 && start.length >= 2) {
    const parsed = Number.parseInt(start.slice(0, 2), 10);
    if (!Number.isNaN(parsed)) {
      return parsed;
    }
  }

  const date = new Date(start);
  if (!Number.isNaN(date.getTime())) {
    return date.getHours();
  }

  return null;
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

function TimelineCompact({ days }) {
  return (
    <section className="sbdp-day-planner__timeline">
      <h4>Tijdlijn</h4>
      {Array.isArray(days) && days.length > 0 ? (
        <div className="sbdp-day-planner__timeline-track">
          {days.map((day, dayIndex) => {
            const slots = Array.isArray(day.slots) ? day.slots : [];
            const totalParticipantsCount = sumParticipants(slots);

            return (
              <article key={day.date || dayIndex} className="sbdp-day-planner__timeline-day">
                <header className="sbdp-day-planner__timeline-day-header">
                  <span className="sbdp-day-planner__timeline-day-label">{formatDate(day.date)}</span>
                  <div className="sbdp-day-planner__timeline-day-meta">
                    <span>{slots.length === 1 ? "1 activiteit" : `${slots.length} activiteiten`}</span>
                    <span>{formatParticipantsCount(totalParticipantsCount)}</span>
                  </div>
                </header>
                <div className="sbdp-day-planner__timeline-grid">
                  {HOURS.map((hour) => {
                    const items = slots.filter((slot) => getSlotHour(slot) === hour);

                    return (
                      <div
                        key={`${dayIndex}-${hour}`}
                        className={`sbdp-day-planner__timeline-row${
                          items.length === 0 ? " sbdp-day-planner__timeline-row--empty" : ""
                        }`}
                      >
                        <span className="sbdp-day-planner__timeline-hour">{formatHourLabel(hour)}</span>
                        <div className="sbdp-day-planner__timeline-hour-content">
                          {items.length > 0 ? (
                            items.map((slot, slotIndex) => {
                              const label = slot.title || slot.activity?.title || slot.activity || "Activiteit";
                              const participants = resolveParticipants(slot);

                              return (
                                <div key={slot.id || slotIndex} className="sbdp-day-planner__timeline-slot">
                                  <div className="sbdp-day-planner__timeline-slot-main">
                                    <span className="sbdp-day-planner__timeline-time">
                                      {formatTime(slot.start)} - {formatTime(slot.end)}
                                    </span>
                                    <span className="sbdp-day-planner__timeline-activity">{label}</span>
                                  </div>
                                  <span className="sbdp-day-planner__timeline-participants">
                                    {formatParticipantsCount(participants)}
                                  </span>
                                </div>
                              );
                            })
                          ) : (
                            <span className="sbdp-day-planner__timeline-empty">-</span>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </article>
            );
          })}
        </div>
      ) : (
        <p>Je tijdlijn verschijnt zodra er activiteiten ingepland zijn.</p>
      )}
    </section>
  );
}

TimelineCompact.propTypes = {
  days: PropTypes.arrayOf(PropTypes.object).isRequired,
};

export default TimelineCompact;
