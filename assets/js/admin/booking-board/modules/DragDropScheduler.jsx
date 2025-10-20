import React from "react";
import PropTypes from "prop-types";

function DragDropScheduler({ bookings, onReschedule }) {
  return (
    <div className="sbdp-dragdrop-scheduler">
      <p>Drag & drop scheduling will be available in a future release.</p>
      <ul>
        {bookings.map((booking) => (
          <li key={booking.booking_id}>
            {booking.product} — {booking.from}
            <button type="button" onClick={() => onReschedule(booking)}>
              Reschedule
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}

DragDropScheduler.propTypes = {
  bookings: PropTypes.arrayOf(PropTypes.object).isRequired,
  onReschedule: PropTypes.func.isRequired,
};

export default DragDropScheduler;
