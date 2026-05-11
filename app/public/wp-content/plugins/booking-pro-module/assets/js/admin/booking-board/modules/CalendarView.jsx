import React, { useMemo } from "react";
import PropTypes from "prop-types";

import DragDropScheduler from "./DragDropScheduler";

const dayFormatter = new Intl.DateTimeFormat(undefined, {
  weekday: "long",
  year: "numeric",
  month: "long",
  day: "numeric",
});

const shortFormatter = new Intl.DateTimeFormat(undefined, {
  weekday: "short",
  month: "short",
  day: "numeric",
});

function getWeekBounds(anchor) {
  const start = new Date(anchor);
  const day = start.getDay();
  const diff = (day + 6) % 7;
  start.setDate(start.getDate() - diff);
  start.setHours(0, 0, 0, 0);

  const end = new Date(start);
  end.setDate(start.getDate() + 6);
  return { start, end };
}

function formatWeekTitle(range) {
  const startLabel = shortFormatter.format(range.start);
  const endLabel = shortFormatter.format(range.end);

  if (range.start.getMonth() === range.end.getMonth()) {
    return `${startLabel} – ${endLabel}`;
  }

  return `${shortFormatter.format(range.start)} – ${shortFormatter.format(range.end)}`;
}

function CalendarView({ bookings, onReschedule, view, activeDate, onNavigate, loading }) {
  const range = useMemo(() => {
    if (view === "week") {
      return getWeekBounds(activeDate);
    }

    const date = new Date(activeDate);
    date.setHours(0, 0, 0, 0);
    return { start: date, end: date };
  }, [activeDate, view]);

  const title = useMemo(
    () => (view === "week" ? formatWeekTitle(range) : dayFormatter.format(range.start)),
    [range, view]
  );

  return (
    <section className="sbdp-calendar-view">
      <header className="sbdp-calendar-view__header">
        <div>
          <h3 className="sbdp-calendar-view__title">{title}</h3>
          <div className="sbdp-calendar-view__secondary">
            <span>{view === "week" ? "Grouped by day" : "Grouped by resource"}</span>
            {loading ? <span>Loading…</span> : null}
          </div>
        </div>
        <div className="sbdp-calendar-nav">
          <button type="button" className="sbdp-calendar-nav__button" onClick={() => onNavigate("prev")}>
            ‹ Prev
          </button>
          <button type="button" className="sbdp-calendar-nav__button" onClick={() => onNavigate("today")}>
            Today
          </button>
          <button type="button" className="sbdp-calendar-nav__button" onClick={() => onNavigate("next")}>
            Next ›
          </button>
        </div>
      </header>
      <DragDropScheduler
        bookings={bookings}
        onReschedule={onReschedule}
        view={view}
        rangeStart={range.start}
        rangeEnd={range.end}
      />
    </section>
  );
}

CalendarView.propTypes = {
  bookings: PropTypes.arrayOf(PropTypes.object).isRequired,
  onReschedule: PropTypes.func.isRequired,
  view: PropTypes.oneOf(["day", "week"]).isRequired,
  activeDate: PropTypes.instanceOf(Date).isRequired,
  onNavigate: PropTypes.func.isRequired,
  loading: PropTypes.bool,
};

CalendarView.defaultProps = {
  loading: false,
};

export default CalendarView;
