import React from "react";
import PropTypes from "prop-types";

import DragDropScheduler from "./DragDropScheduler";

function CalendarView({ bookings, onReschedule }) {
  return (
    <section className="sbdp-calendar-view">
      <header className="sbdp-calendar-view__header">
        <h3>Calendar View</h3>
      </header>
      <DragDropScheduler bookings={bookings} onReschedule={onReschedule} />
    </section>
  );
}

CalendarView.propTypes = {
  bookings: PropTypes.arrayOf(PropTypes.object).isRequired,
  onReschedule: PropTypes.func.isRequired,
};

export default CalendarView;
